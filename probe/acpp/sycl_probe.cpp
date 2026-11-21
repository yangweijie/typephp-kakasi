// F694 A-section probe (2026-11-21, mac side): can the engine's representative SYCL kernels
// compile and run CORRECTLY on a non-Intel GPU via AdaptiveCpp? This machine is an Apple M4
// (10-core GPU) - itself a non-Intel target, which is why it can host this probe without any
// vendor SDK. Kernel shapes mirror the engine's hot paths (model_sycl.cc:649 gemvM, ops_sycl.cc:246
// tiled SGEMM, ops_sycl.cc:150 device_dequant): the shared-weight matvec (TG=64, butterfly
// reduction, local_accessor), the tiled GEMM (TM/TN/TK = 16/16/32), block dequant with
// bit_cast<sycl::half>, an rmsnorm reduction, and an attention-score pass. Every op is checked
// against an independent CPU reference and timed. Numbers are REPORTED; PASS/FAIL is judged on
// correctness only (tolerances below), never on speed - the F688 rule.
//
// Build (after `brew install adaptivecpp`):
//   acpp --acpp-targets="omp;opencl" -O2 -std=c++17 sycl_probe.cpp -o sycl_probe
// (target string adjusted to what this acpp build actually supports - see acpp --help).
// Cross-distribution include: DPC++ ships <sycl/sycl.hpp>; AdaptiveCpp installs
// <AdaptiveCpp/sycl.hpp> (brew) — keep the probe buildable on both.
#if __has_include(<AdaptiveCpp/sycl.hpp>)
#include <AdaptiveCpp/sycl.hpp>
namespace sycl = acpp::sycl;   // AdaptiveCpp 25.x wraps SYCL in acpp::sycl
#elif __has_include(<sycl/sycl.hpp>)
#include <sycl/sycl.hpp>
#else
#include <SYCL/sycl.hpp>
#endif
#include <cstdio>
#include <cstdint>
#include <cmath>
#include <cstdlib>
#include <vector>
#include <chrono>

namespace {

// Resident-model shapes (Qwen2.5-0.5B: D=896, FFN=4864, 14 attention heads x HD 64, 24 layers).
constexpr int D = 896;
constexpr int FFN = 4864;
constexpr int NH = 14;
constexpr int HD = 64;
constexpr int TG = 64;      // gemvM work-group (model_sycl.cc:653)
constexpr int TM = 16, TN = 16, TK = 32;  // tiled GEMM tile (ops_sycl.cc:235)

double now_ms() {
    using namespace std::chrono;
    return duration<double, std::milli>(steady_clock::now().time_since_epoch()).count();
}

// ---- op 1: gemvM<2> - the batched decode matvec, engine structure verbatim (MM=2) ----
// Y[m*N + row] = sum_k X[m*K + k] * W[row*K + k] + bias[row]
void gemvM2(sycl::queue& q, const float* X, const float* W, const float* bias,
            int K, int N, float* Y) {
    q.submit([&](sycl::handler& h) {
        sycl::local_accessor<float, 2> red(sycl::range<2>(TG, 2), h);
        h.parallel_for(sycl::nd_range<1>((size_t) N * TG, TG), [=](sycl::nd_item<1> item) {
            const int row = (int) item.get_group(0);
            const int lane = (int) item.get_local_id(0);
            const size_t base = (size_t) row * (size_t) K;
            float a[2];
            a[0] = 0.0f; a[1] = 0.0f;
            for (int k = lane; k < K; k += TG) {
                const float wv = W[base + (size_t) k];
                a[0] += X[(size_t) k] * wv;
                a[1] += X[(size_t) K + (size_t) k] * wv;
            }
            for (int m = 0; m < 2; ++m) red[lane][m] = a[m];
            item.barrier(sycl::access::fence_space::local_space);
            for (int s = TG / 2; s > 0; s >>= 1) {
                if (lane < s) {
                    for (int m = 0; m < 2; ++m) red[lane][m] += red[lane + s][m];
                }
                item.barrier(sycl::access::fence_space::local_space);
            }
            if (lane == 0) {
                Y[row] = red[0][0] + bias[row];
                Y[(size_t) N + (size_t) row] = red[0][1] + bias[row];
            }
        });
    });
    q.wait_and_throw();
}

// ---- op 2: tiled SGEMM, engine tile shape verbatim ----
void tiled_gemm(sycl::queue& q, const float* X, const float* W, float* Y, int M, int N, int K) {
    const int mt = (M + TM - 1) / TM;
    const int nt = (N + TN - 1) / TN;
    sycl::range<2> local(TM, TN);
    sycl::range<2> global((size_t) mt * TM, (size_t) nt * TN);
    q.submit([&](sycl::handler& h) {
        sycl::local_accessor<float, 2> Xs(sycl::range<2>(TM, TK), h);
        sycl::local_accessor<float, 2> Ws(sycl::range<2>(TN, TK), h);
        h.parallel_for(sycl::nd_range<2>(global, local), [=](sycl::nd_item<2> item) {
            const int tm = (int) item.get_local_id(0);
            const int tn = (int) item.get_local_id(1);
            const int row = (int) item.get_group(0) * TM + tm;
            const int col = (int) item.get_group(1) * TN + tn;
            float acc = 0.0f;
            for (int kt = 0; kt < K; kt += TK) {
                if (row < M && tm < TM) {
                    for (int t = 0; t < TK; ++t) {
                        Xs[tm][t] = (kt + t < K && row < M) ? X[(size_t) row * (size_t) K + (size_t)(kt + t)] : 0.0f;
                    }
                }
                if (col < N && tn < TN) {
                    for (int t = 0; t < TK; ++t) {
                        Ws[tn][t] = (kt + t < K && col < N) ? W[(size_t) col * (size_t) K + (size_t)(kt + t)] : 0.0f;
                    }
                }
                item.barrier(sycl::access::fence_space::local_space);
                for (int t = 0; t < TK; ++t) acc += Xs[tm][t] * Ws[tn][t];
                item.barrier(sycl::access::fence_space::local_space);
            }
            if (row < M && col < N) Y[(size_t) row * (size_t) N + (size_t) col] = acc;
        });
    });
    q.wait_and_throw();
}

// ---- op 3: block dequant (engine dtype 8 = Q8_0 form: 34B block, f16 scale + 32 int8) ----
// One work item per 32-element block; the arithmetic is integer/scale, so the CPU reference
// must match BIT-EXACTLY - this op has tolerance 0.
void dequant_q8(sycl::queue& q, const uint8_t* raw, float* out, uint64_t nblocks) {
    q.parallel_for(sycl::range<1>((size_t) nblocks), [=](sycl::item<1> it) {
        const uint64_t b = it.get_id(0);
        const uint8_t* p = raw + b * 34;
        const uint16_t hb = (uint16_t)(p[0] | (p[1] << 8));
        const float d = (float) sycl::bit_cast<sycl::half>(hb);
        for (int i = 0; i < 32; ++i) out[b * 32 + i] = d * (float) ((int8_t) p[2 + i]);
    });
    q.wait_and_throw();
}

// ---- op 4: rmsnorm (reduction over D, rsqrt, per-channel weight) ----
void rmsnorm(sycl::queue& q, const float* x, const float* w, float* out, int n, float eps) {
    q.submit([&](sycl::handler& h) {
        sycl::local_accessor<float, 1> red(sycl::range<1>(TG), h);
        h.parallel_for(sycl::nd_range<1>(TG, TG), [=](sycl::nd_item<1> item) {
            const int lane = (int) item.get_local_id(0);
            float a = 0.0f;
            for (int i = lane; i < n; i += TG) a += x[i] * x[i];
            red[lane] = a;
            item.barrier(sycl::access::fence_space::local_space);
            for (int s = TG / 2; s > 0; s >>= 1) {
                if (lane < s) red[lane] += red[lane + s];
                item.barrier(sycl::access::fence_space::local_space);
            }
            if (lane == 0) red[0] = sycl::rsqrt(red[0] / (float) n + eps);
            item.barrier(sycl::access::fence_space::local_space);
            const float inv = red[0];
            for (int i = lane; i < n; i += TG) out[i] = x[i] * inv * w[i];
        });
    });
    q.wait_and_throw();
}

// ---- op 5: attention scores - per (token, head): dot over HD, softmax over scoreRows ----
// Mirror of the per-(token,head) row shape (T=1 row attending `rows` cached rows).
void attn_scores(sycl::queue& q, const float* Q, const float* Kc, float* S,
                 int rows, float scale) {
    // Q: HD floats (one head of one token). Kc: rows*HD. S: rows.
    q.submit([&](sycl::handler& h) {
        sycl::local_accessor<float, 1> red(sycl::range<1>(TG), h);
        h.parallel_for(sycl::nd_range<1>((size_t) rows * TG, TG), [=](sycl::nd_item<1> item) {
            const int row = (int) item.get_group(0);
            const int lane = (int) item.get_local_id(0);
            const size_t base = (size_t) row * (size_t) HD;
            float a = 0.0f;
            for (int i = lane; i < HD; i += TG) a += Q[i] * Kc[base + (size_t) i];
            red[lane] = a;
            item.barrier(sycl::access::fence_space::local_space);
            for (int s = TG / 2; s > 0; s >>= 1) {
                if (lane < s) red[lane] += red[lane + s];
                item.barrier(sycl::access::fence_space::local_space);
            }
            if (lane == 0) S[row] = red[0] * scale;
        });
    });
    q.wait_and_throw();
}

struct Check { double maxdiff; double rel; };

Check cmp(const std::vector<float>& got, const std::vector<float>& ref) {
    Check c{0.0, 0.0};
    double refmax = 1e-30;
    for (size_t i = 0; i < ref.size(); ++i) {
        const double d = std::fabs((double) got[i] - (double) ref[i]);
        if (d > c.maxdiff) c.maxdiff = d;
        const double a = std::fabs((double) ref[i]);
        if (a > refmax) refmax = a;
    }
    c.rel = c.maxdiff / refmax;
    return c;
}

}  // namespace

int main() {
    auto qlist = sycl::device::get_devices(sycl::info::device_type::all);
    std::printf("ACPP-PROBE devices=%zu\n", qlist.size());
    for (const auto& dv : qlist) {
        std::printf("ACPP-PROBE device: %s | gpu=%d\n",
                    dv.get_info<sycl::info::device::name>().c_str(),
                    (int) dv.is_gpu());
    }
    // Probe on the first GPU if one exists, else the first device (honest about which ran).
    sycl::device dev;
    bool have_gpu = false;
    for (const auto& dv : qlist) {
        if (dv.is_gpu()) { dev = dv; have_gpu = true; break; }
    }
    if (!have_gpu && !qlist.empty()) dev = qlist[0];
    sycl::queue q(dev);
    std::printf("ACPP-PROBE using: %s (gpu=%d)\n",
                dev.get_info<sycl::info::device::name>().c_str(), (int) dev.is_gpu());

    int failures = 0;

    // ---------- op 1: gemvM<2> at both layer shapes ----------
    for (int caseIdx = 0; caseIdx < 2; ++caseIdx) {
        const int K = (caseIdx == 0) ? D : FFN;
        const int N = (caseIdx == 0) ? FFN : D;
        std::vector<float> X((size_t) 2 * K), W((size_t) N * K), bias((size_t) N);
        std::srand(42);
        for (auto& v : X) v = (float) std::rand() / RAND_MAX - 0.5f;
        for (auto& v : W) v = ((float) std::rand() / RAND_MAX - 0.5f) * 0.1f;
        for (auto& v : bias) v = (float) std::rand() / RAND_MAX * 0.01f;
        float *dX = sycl::malloc_device<float>(X.size(), q);
        float *dW = sycl::malloc_device<float>(W.size(), q);
        float *dB = sycl::malloc_device<float>(bias.size(), q);
        float *dY = sycl::malloc_device<float>(2 * (size_t) N, q);
        q.memcpy(dX, X.data(), X.size() * sizeof(float)).wait();
        q.memcpy(dW, W.data(), W.size() * sizeof(float)).wait();
        q.memcpy(dB, bias.data(), bias.size() * sizeof(float)).wait();
        std::vector<float> Yref((size_t) 2 * N, 0.0f);
        for (int m = 0; m < 2; ++m) {
            for (int row = 0; row < N; ++row) {
                double acc = 0.0;
                for (int k = 0; k < K; ++k) {
                    acc += (double) X[(size_t) m * K + (size_t) k] * (double) W[(size_t) row * K + (size_t) k];
                }
                Yref[(size_t) m * N + (size_t) row] = (float) acc + bias[(size_t) row];
            }
        }
        std::vector<float> Ygot((size_t) 2 * N, 0.0f);
        gemvM2(q, dX, dW, dB, K, N, dY);
        q.memcpy(Ygot.data(), dY, Ygot.size() * sizeof(float)).wait();
        Check c = cmp(Ygot, Yref);
        // timing: 20 iterations, one wait at the end (submit cost included)
        const double t0 = now_ms();
        for (int r = 0; r < 20; ++r) gemvM2(q, dX, dW, dB, K, N, dY);
        const double ms = (now_ms() - t0) / 20.0;
        const bool ok = c.rel < 5e-4;
        if (!ok) ++failures;
        std::printf("ACPP-PROBE gemvM2 K=%d N=%d: maxdiff=%.3e rel=%.3e ms=%.3f %s\n",
                    K, N, c.maxdiff, c.rel, ms, ok ? "ok" : "TOLERANCE_FAIL");
        sycl::free(dX, q); sycl::free(dW, q); sycl::free(dB, q); sycl::free(dY, q);
    }

    // ---------- op 2: tiled GEMM, prefill shape M=512 K=896 N=4864 ----------
    {
        const int M = 512, K = D, N = FFN;
        std::vector<float> X((size_t) M * K), W((size_t) N * K);
        std::srand(7);
        for (auto& v : X) v = (float) std::rand() / RAND_MAX - 0.5f;
        for (auto& v : W) v = ((float) std::rand() / RAND_MAX - 0.5f) * 0.1f;
        float *dX = sycl::malloc_device<float>(X.size(), q);
        float *dW = sycl::malloc_device<float>(W.size(), q);
        float *dY = sycl::malloc_device<float>((size_t) M * N, q);
        q.memcpy(dX, X.data(), X.size() * sizeof(float)).wait();
        q.memcpy(dW, W.data(), W.size() * sizeof(float)).wait();
        // CPU reference (double accumulate; the kernel is f32 so rel tolerance, not bit-exact)
        std::vector<float> Yref((size_t) M * N, 0.0f);
        for (int r = 0; r < M; ++r) {
            for (int col = 0; col < N; ++col) {
                double acc = 0.0;
                for (int k = 0; k < K; ++k) {
                    acc += (double) X[(size_t) r * K + (size_t) k] * (double) W[(size_t) col * K + (size_t) k];
                }
                Yref[(size_t) r * N + (size_t) col] = (float) acc;
            }
        }
        std::vector<float> Ygot((size_t) M * N, 0.0f);
        tiled_gemm(q, dX, dW, dY, M, N, K);
        q.memcpy(Ygot.data(), dY, Ygot.size() * sizeof(float)).wait();
        Check c = cmp(Ygot, Yref);
        const double t0 = now_ms();
        for (int r2 = 0; r2 < 5; ++r2) tiled_gemm(q, dX, dW, dY, M, N, K);
        const double ms = (now_ms() - t0) / 5.0;
        const bool ok = c.rel < 5e-3;
        if (!ok) ++failures;
        std::printf("ACPP-PROBE tiled_gemm M=%d K=%d N=%d: maxdiff=%.3e rel=%.3e ms=%.3f %s\n",
                    M, K, N, c.maxdiff, c.rel, ms, ok ? "ok" : "TOLERANCE_FAIL");
        sycl::free(dX, q); sycl::free(dW, q); sycl::free(dY, q);
    }

    // ---------- op 3: block dequant (Q8_0 form) - tolerance 0 ----------
    {
        const uint64_t nb = 10000;
        std::vector<uint8_t> raw((size_t) nb * 34);
        std::srand(11);
        for (auto& v : raw) v = (uint8_t) (std::rand() & 0xFF);
        uint8_t *dR = sycl::malloc_device<uint8_t>(raw.size(), q);
        float *dO = sycl::malloc_device<float>((size_t) nb * 32, q);
        q.memcpy(dR, raw.data(), raw.size()).wait();
        std::vector<float> Oref((size_t) nb * 32, 0.0f);
        for (uint64_t b = 0; b < nb; ++b) {
            const uint8_t* p = raw.data() + b * 34;
            const uint16_t hb = (uint16_t)(p[0] | ((uint16_t) p[1] << 8));
            const float d = (float) sycl::bit_cast<sycl::half>(hb);
            for (int i = 0; i < 32; ++i) Oref[b * 32 + i] = d * (float) ((int8_t) p[2 + i]);
        }
        std::vector<float> Ogot((size_t) nb * 32, 0.0f);
        dequant_q8(q, dR, dO, nb);
        q.memcpy(Ogot.data(), dO, Ogot.size() * sizeof(float)).wait();
        Check c = cmp(Ogot, Oref);
        const bool ok = (c.maxdiff == 0.0);
        if (!ok) ++failures;
        std::printf("ACPP-PROBE dequant_q8 blocks=%llu: maxdiff=%.3e %s\n",
                    (unsigned long long) nb, c.maxdiff, ok ? "ok (bit-exact)" : "EXACTNESS_FAIL");
        sycl::free(dR, q); sycl::free(dO, q);
    }

    // ---------- op 4: rmsnorm over D ----------
    {
        std::vector<float> x(D), w(D);
        std::srand(3);
        for (auto& v : x) v = (float) std::rand() / RAND_MAX - 0.5f;
        for (auto& v : w) v = 0.5f + (float) std::rand() / RAND_MAX;
        float *dX = sycl::malloc_device<float>(D, q);
        float *dW = sycl::malloc_device<float>(D, q);
        float *dO = sycl::malloc_device<float>(D, q);
        q.memcpy(dX, x.data(), x.size() * sizeof(float)).wait();
        q.memcpy(dW, w.data(), w.size() * sizeof(float)).wait();
        const float eps = 1e-5f;
        double ss = 0.0;
        for (int i = 0; i < D; ++i) ss += (double) x[i] * (double) x[i];
        const float inv = (float) (1.0 / std::sqrt(ss / (double) D + (double) eps));
        std::vector<float> Oref(D);
        for (int i = 0; i < D; ++i) Oref[(size_t) i] = x[(size_t) i] * inv * w[(size_t) i];
        std::vector<float> Ogot(D, 0.0f);
        rmsnorm(q, dX, dW, dO, D, eps);
        q.memcpy(Ogot.data(), dO, Ogot.size() * sizeof(float)).wait();
        Check c = cmp(Ogot, Oref);
        const double t0 = now_ms();
        for (int r2 = 0; r2 < 100; ++r2) rmsnorm(q, dX, dW, dO, D, eps);
        const double ms = (now_ms() - t0) / 100.0;
        const bool ok = c.rel < 1e-4;
        if (!ok) ++failures;
        std::printf("ACPP-PROBE rmsnorm D=%d: maxdiff=%.3e rel=%.3e ms=%.4f %s\n",
                    D, c.maxdiff, c.rel, ms, ok ? "ok" : "TOLERANCE_FAIL");
        sycl::free(dX, q); sycl::free(dW, q); sycl::free(dO, q);
    }

    // ---------- op 5: attention scores, one row attending 46 cached rows ----------
    {
        const int rows = 46;
        std::vector<float> Qh(HD), Kc((size_t) rows * HD);
        std::srand(5);
        for (auto& v : Qh) v = (float) std::rand() / RAND_MAX - 0.5f;
        for (auto& v : Kc) v = (float) std::rand() / RAND_MAX - 0.5f;
        const float scale = 1.0f / std::sqrt((float) HD);
        float *dQ = sycl::malloc_device<float>(HD, q);
        float *dK = sycl::malloc_device<float>(Kc.size(), q);
        float *dS = sycl::malloc_device<float>((size_t) rows, q);
        q.memcpy(dQ, Qh.data(), Qh.size() * sizeof(float)).wait();
        q.memcpy(dK, Kc.data(), Kc.size() * sizeof(float)).wait();
        std::vector<float> Sref((size_t) rows, 0.0f);
        for (int r = 0; r < rows; ++r) {
            double acc = 0.0;
            for (int i = 0; i < HD; ++i) {
                acc += (double) Qh[(size_t) i] * (double) Kc[(size_t) r * HD + (size_t) i];
            }
            Sref[(size_t) r] = (float) acc * scale;
        }
        std::vector<float> Sgot((size_t) rows, 0.0f);
        attn_scores(q, dQ, dK, dS, rows, scale);
        q.memcpy(Sgot.data(), dS, Sgot.size() * sizeof(float)).wait();
        Check c = cmp(Sgot, Sref);
        const double t0 = now_ms();
        for (int r2 = 0; r2 < 100; ++r2) attn_scores(q, dQ, dK, dS, rows, scale);
        const double ms = (now_ms() - t0) / 100.0;
        const bool ok = c.rel < 1e-4;
        if (!ok) ++failures;
        std::printf("ACPP-PROBE attn_scores rows=%d: maxdiff=%.3e rel=%.3e ms=%.4f %s\n",
                    rows, c.maxdiff, c.rel, ms, ok ? "ok" : "TOLERANCE_FAIL");
        sycl::free(dQ, q); sycl::free(dK, q); sycl::free(dS, q);
    }

    if (failures == 0) {
        std::printf("ACPP-PROBE: PASS (all ops within tolerance on the device above)\n");
    } else {
        std::printf("ACPP-PROBE: FAIL failures=%d\n", failures);
    }
    return failures == 0 ? 0 : 1;
}
