// Core compute ops on real model weights, executed through the natively linked SYCL path.
// Each op is checked against an independent host reference in the same binary.
#include <sycl/sycl.hpp>

#include "gguf.h"
#include "sycl_backend.h"

#include <algorithm>
#include <chrono>
#include <cmath>
#include <cstdint>
#include <cstring>
#include <string>
#include <vector>

namespace ops {
namespace {
    std::string g_err;
    double g_ms = 0.0;

    inline float h2f(uint16_t bits) { return (float) sycl::bit_cast<sycl::half>(bits); }
    inline uint16_t ld16(const uint8_t* p) { return (uint16_t)(p[0] | (p[1] << 8)); }

    // Layouts verified against ggml-common.h static_asserts:
    //   q8_0 { half d; int8 qs[32]; }                = 34B / 32 elems
    //   q5_0 { half d; uint8 qh[4]; uint8 qs[16]; }  = 22B / 32 elems
    // f32 is a plain copy. Other dtypes are refused, not guessed.
    uint32_t block_elems(uint32_t dtype) {
        if (dtype == 0) return 1u;
        if (dtype == 12 || dtype == 14) return 256u;
        return 32u;
    }

    bool supported(uint32_t dtype) {
        return dtype == 0 || dtype == 6 || dtype == 8 || dtype == 12 || dtype == 14;
    }

    // ----- verbatim upstream reference (ggml-quants.c) -----
    // Copied structurally as written there (pointer walking, 64-element groups) on purpose:
    // the SYCL kernel below is an independently derived per-element formulation, so the two
    // agreeing is a real check on the scale/nibble indexing rather than a self-confirmation.
    inline void get_scale_min_k4_ref(int j, const uint8_t* q, uint8_t* d, uint8_t* m) {
        if (j < 4) {
            *d = q[j] & 63; *m = q[j + 4] & 63;
        } else {
            *d = (q[j + 4] & 0xF) | ((q[j - 4] >> 6) << 4);
            *m = (q[j + 4] >> 4) | ((q[j - 0] >> 6) << 4);
        }
    }

    void ref_dequant_q4_K(const uint8_t* base, float* y, int64_t k) {
        const int64_t nb = k / 256;
        for (int64_t i = 0; i < nb; i++) {
            const uint8_t* q = base + (size_t) i * 144 + 16;  // block_q4_K::qs
            const float d = h2f(ld16(base + (size_t) i * 144));
            const float min = h2f(ld16(base + (size_t) i * 144 + 2));
            const uint8_t* sp = base + (size_t) i * 144 + 4;  // block_q4_K::scales
            int is = 0;
            uint8_t sc, m;
            for (int64_t j = 0; j < 256; j += 64) {
                get_scale_min_k4_ref(is + 0, sp, &sc, &m);
                const float d1 = d * sc; const float m1 = min * m;
                get_scale_min_k4_ref(is + 1, sp, &sc, &m);
                const float d2 = d * sc; const float m2 = min * m;
                for (int l = 0; l < 32; ++l) *y++ = d1 * (q[l] & 0xF) - m1;
                for (int l = 0; l < 32; ++l) *y++ = d2 * (q[l] >> 4) - m2;
                q += 32; is += 2;
            }
        }
    }

    void ref_dequant_q6_K(const uint8_t* base, float* y, int64_t k) {
        const int64_t nb = k / 256;
        for (int64_t i = 0; i < nb; i++) {
            const float d = h2f(ld16(base + (size_t) i * 210 + 208));
            const uint8_t* ql = base + (size_t) i * 210;         // ql[128]
            const uint8_t* qh = ql + 128;                        // qh[64]
            const int8_t* sc = (const int8_t*)(ql + 192);        // scales[16]
            for (int64_t n = 0; n < 256; n += 128) {
                for (int l = 0; l < 32; ++l) {
                    int is = l / 16;
                    const int8_t q1 = (int8_t)((ql[l + 0] & 0xF) | (((qh[l] >> 0) & 3) << 4)) - 32;
                    const int8_t q2 = (int8_t)((ql[l + 32] & 0xF) | (((qh[l] >> 2) & 3) << 4)) - 32;
                    const int8_t q3 = (int8_t)((ql[l + 0] >> 4) | (((qh[l] >> 4) & 3) << 4)) - 32;
                    const int8_t q4 = (int8_t)((ql[l + 32] >> 4) | (((qh[l] >> 6) & 3) << 4)) - 32;
                    y[l + 0] = d * sc[is + 0] * q1;
                    y[l + 32] = d * sc[is + 2] * q2;
                    y[l + 64] = d * sc[is + 4] * q3;
                    y[l + 96] = d * sc[is + 6] * q4;
                }
                y += 128; ql += 64; qh += 32; sc += 8;
            }
        }
    }
    // -------------------------------------------------------

    bool ref_dequant(const std::vector<uint8_t>& raw, uint32_t dtype, uint64_t numel, std::vector<float>& out) {
        out.assign((size_t) numel, 0.0f);
        if (dtype == 0) {
            if (raw.size() != (size_t) numel * 4) return false;
            memcpy(out.data(), raw.data(), raw.size());
            return true;
        }
        if (dtype == 8) {
            const uint64_t nb = numel / 32;
            if (raw.size() != (size_t) nb * 34) return false;
            for (uint64_t b = 0; b < nb; ++b) {
                const uint8_t* p = raw.data() + (size_t) b * 34;
                const float d = h2f((uint16_t)(p[0] | (p[1] << 8)));
                for (int i = 0; i < 32; ++i) out[(size_t) b * 32 + i] = d * (float) ((int8_t) p[2 + i]);
            }
            return true;
        }
        if (dtype == 6) {
            const uint64_t nb = numel / 32;
            if (raw.size() != (size_t) nb * 22) return false;
            for (uint64_t b = 0; b < nb; ++b) {
                const uint8_t* p = raw.data() + (size_t) b * 22;
                const float d = h2f((uint16_t)(p[0] | (p[1] << 8)));
                const uint8_t* qh = p + 2;
                const uint8_t* qs = p + 6;
                const uint32_t qhw = (uint32_t) qh[0] | ((uint32_t) qh[1] << 8) |
                                     ((uint32_t) qh[2] << 16) | ((uint32_t) qh[3] << 24);
                // upstream dequantize_row_q5_0: qs[j] carries element j (low nibble) and
                // element j+16 (high nibble); qh bit i is the 5th bit of element i.
                for (int j = 0; j < 16; ++j) {
                    const int xh0 = ((qhw >> j) << 4) & 0x10;
                    const int xh1 = (qhw >> (j + 12)) & 0x10;
                    out[(size_t) b * 32 + j] = d * (float) (((qs[j] & 0x0F) | xh0) - 16);
                    out[(size_t) b * 32 + j + 16] = d * (float) (((qs[j] >> 4) | xh1) - 16);
                }
            }
            return true;
        }
        if (dtype == 12) {
            const uint64_t nb = numel / 256;
            if (raw.size() != (size_t) nb * 144) return false;
            ref_dequant_q4_K(raw.data(), out.data(), (int64_t) numel);
            return true;
        }
        if (dtype == 14) {
            const uint64_t nb = numel / 256;
            if (raw.size() != (size_t) nb * 210) return false;
            ref_dequant_q6_K(raw.data(), out.data(), (int64_t) numel);
            return true;
        }
        return false;
    }

    void device_dequant(sycl::queue& q, const uint8_t* raw, float* out, uint64_t nblocks, uint32_t dtype) {
        // One work item rebuilds one quant block (32 elements), same arithmetic as the host.
        // Pointers are captured individually, and the call waits: no reliance on implicit ordering.
        q.parallel_for(sycl::range<1>(nblocks), [=](sycl::item<1> it) {
            const uint64_t b = it.get_id(0);
            if (dtype == 0) {
                const float* src = (const float*) raw;
                out[b] = src[b];
                return;
            }
            if (dtype == 8) {
                const uint8_t* p = raw + b * 34;
                const uint16_t hb = (uint16_t)(p[0] | (p[1] << 8));
                const float d = (float) sycl::bit_cast<sycl::half>(hb);
                for (int i = 0; i < 32; ++i) out[b * 32 + i] = d * (float) ((int8_t) p[2 + i]);
                return;
            }
            if (dtype == 6) {
                const uint8_t* p = raw + b * 22;
                const float d = (float) sycl::bit_cast<sycl::half>(ld16(p));
                const uint8_t* qh = p + 2;
                const uint8_t* qs = p + 6;
                const uint32_t qhw = (uint32_t) qh[0] | ((uint32_t) qh[1] << 8) |
                                     ((uint32_t) qh[2] << 16) | ((uint32_t) qh[3] << 24);
                for (int j = 0; j < 16; ++j) {
                    const int xh0 = ((qhw >> j) << 4) & 0x10;
                    const int xh1 = (qhw >> (j + 12)) & 0x10;
                    out[b * 32 + j] = d * (float) (((qs[j] & 0x0F) | xh0) - 16);
                    out[b * 32 + j + 16] = d * (float) (((qs[j] >> 4) | xh1) - 16);
                }
                return;
            }
            // Independently derived indexing (sub-block = super-block/32, nibble half chosen
            // by parity) vs the reference's pointer-walking 64-element groups.
            if (dtype == 12) {
                const uint8_t* blk = raw + b * 144;
                const float d = (float) sycl::bit_cast<sycl::half>(ld16(blk));
                const float mn = (float) sycl::bit_cast<sycl::half>(ld16(blk + 2));
                const uint8_t* sc = blk + 4;
                const uint8_t* qs = blk + 16;
                for (int s = 0; s < 8; ++s) {
                    uint8_t a, m;
                    if (s < 4) {
                        a = sc[s] & 63; m = sc[s + 4] & 63;
                    } else {
                        a = (sc[s + 4] & 0xF) | ((sc[s - 4] >> 6) << 4);
                        m = (sc[s + 4] >> 4) | ((sc[s] >> 6) << 4);
                    }
                    const float ds = d * (float) a;
                    const float ms = mn * (float) m;
                    const int qbase = (s / 2) * 32;
                    const bool hi_nib = (s & 1) != 0;
                    for (int l = 0; l < 32; ++l) {
                        const int nib = hi_nib ? (qs[qbase + l] >> 4) : (qs[qbase + l] & 0xF);
                        out[b * 256 + s * 32 + l] = ds * (float) nib - ms;
                    }
                }
                return;
            }
            // Per-element form: chunk/quarter/lane decomposition, not upstream's 4-array walk.
            const uint8_t* blk = raw + b * 210;
            const float d = (float) sycl::bit_cast<sycl::half>(ld16(blk + 208));
            const uint8_t* ql = blk;
            const uint8_t* qh = blk + 128;
            const int8_t* sc = (const int8_t*)(blk + 192);
            for (int e = 0; e < 256; ++e) {
                const int c = e >> 7;          // which 128-element chunk
                const int r = e & 127;
                const int j = r >> 5;          // which of the 4 writes
                const int l = r & 31;
                const int si = c * 8 + j * 2 + (l >> 4);
                const int qh_i = qh[c * 32 + l];
                int nib, two;
                if (j == 0) { nib = ql[c * 64 + l] & 0xF;        two = (qh_i >> 0) & 3; }
                else if (j == 1) { nib = ql[c * 64 + 32 + l] & 0xF; two = (qh_i >> 2) & 3; }
                else if (j == 2) { nib = ql[c * 64 + l] >> 4;       two = (qh_i >> 4) & 3; }
                else { nib = ql[c * 64 + 32 + l] >> 4;          two = (qh_i >> 6) & 3; }
                const int q = nib | (two << 4);
                out[b * 256 + e] = d * (float) sc[si] * (float) (q - 32);
            }
        });
        q.wait_and_throw();
    }

    // ---- tiled SGEMM: Y[M][N] = X[M][K] * W[N][K]^T  (linear layer layout) ----
    constexpr int TM = 16;
    constexpr int TN = 16;
    constexpr int TK = 32;

    struct GemmArgs {
        const float* X;
        const float* W;
        float* Y;
        int M, N, K;
    };

    void device_gemm(sycl::queue& q, const GemmArgs& a) {
        const int mt = (a.M + TM - 1) / TM;
        const int nt = (a.N + TN - 1) / TN;
        sycl::range<2> local(TM, TN);
        sycl::range<2> global((size_t) mt * TM, (size_t) nt * TN);
        q.submit([&](sycl::handler& h) {
            sycl::local_accessor<float, 2> Xs(sycl::range<2>(TM, TK), h);
            sycl::local_accessor<float, 2> Ws(sycl::range<2>(TN, TK), h);
            h.parallel_for(sycl::nd_range<2>(global, local), [=](sycl::nd_item<2> item) {
                const int tm = (int) item.get_local_id(0);
                const int tn = (int) item.get_local_id(1);
                const int row = (int) item.get_group(0) * TM + tm;  // M index
                const int col = (int) item.get_group(1) * TN + tn;  // N index
                float acc = 0.0f;
                for (int kt = 0; kt < a.K; kt += TK) {
                    // 256 items load 2x(16*32) elements: each item does 2 X and 2 W loads.
                    for (int i = 0; i < 2; ++i) {
                        int flat = (tn * TM + tm) * 2 + i;
                        int xr = flat / TK;
                        int xc = flat % TK;
                        int gr = row - tm + xr;
                        int gc = kt + xc;
                        Xs[xr][xc] = (gr < a.M && gc < a.K) ? a.X[(size_t) gr * a.K + gc] : 0.0f;
                        int wr = col - tn + xr;
                        Ws[xr][xc] = (wr < a.N && gc < a.K) ? a.W[(size_t) wr * a.K + gc] : 0.0f;
                    }
                    item.barrier(sycl::access::fence_space::local_space);
                    #pragma unroll
                    for (int kk = 0; kk < TK; ++kk) acc += Xs[tm][kk] * Ws[tn][kk];
                    item.barrier(sycl::access::fence_space::local_space);
                }
                if (row < a.M && col < a.N) a.Y[(size_t) row * a.N + col] = acc;
            });
        });
        q.wait_and_throw();
    }

    double max_rel_abs(const std::vector<float>& a, const std::vector<float>& b) {
        double m = 0.0;
        for (size_t i = 0; i < a.size(); ++i) m = std::max(m, (double) std::fabs(a[i] - b[i]));
        return m;
    }
}  // namespace

std::string last_error() { return g_err; }
double elapsed_ms() { return g_ms; }

// Returns max abs error between device dequant and the host reference (0 expected),
// or -2 for an unsupported dtype, -1 for any failure.
double dequant_maxerr(int backend, int tensor) {
    g_err.clear();
    g_ms = 0.0;
    if (!gguf::loaded()) { g_err = "model not loaded"; return -1.0; }
    sycl::queue* q = kakasi::queue_for(backend);
    if (!q) { g_err = "backend unavailable"; return -1.0; }
    const int dtype = gguf::tensor_dtype(tensor);
    if (!supported((uint32_t) dtype)) { g_err = "dtype not implemented yet"; return -2.0; }

    uint64_t numel = 1;
    for (int d = 0; d < 4; ++d) {
        int64_t ne = gguf::tensor_ne(tensor, d);
        if (ne > 1) numel *= (uint64_t) ne;
    }
    std::vector<uint8_t> raw;
    if (!gguf::raw_bytes(tensor, raw)) { g_err = "raw_bytes failed: " + gguf::tensor_name(tensor); return -1.0; }

    std::vector<float> ref;
    if (!ref_dequant(raw, (uint32_t) dtype, numel, ref)) { g_err = "reference dequant mismatch: " + gguf::tensor_name(tensor); return -1.0; }

    const uint64_t nblocks = numel / block_elems((uint32_t) dtype);
    auto t0 = std::chrono::steady_clock::now();
    uint8_t* dRaw = sycl::malloc_device<uint8_t>(raw.size(), *q);
    float* dOut = sycl::malloc_device<float>(numel, *q);
    q->memcpy(dRaw, raw.data(), raw.size());
    q->wait_and_throw();
    device_dequant(*q, dRaw, dOut, nblocks, (uint32_t) dtype);
    std::vector<float> got((size_t) numel, 0.0f);
    q->memcpy(got.data(), dOut, sizeof(float) * numel);
    q->wait_and_throw();
    sycl::free(dRaw, *q);
    sycl::free(dOut, *q);
    g_ms = std::chrono::duration<double, std::milli>(std::chrono::steady_clock::now() - t0).count();
    return max_rel_abs(ref, got);
}

// Relative error of the tiled SGEMM against a naive host triple loop on dequantized weights.
double linear_relerr(int backend, int tensor, int M) {
    g_err.clear();
    g_ms = 0.0;
    if (!gguf::loaded()) { g_err = "model not loaded"; return -1.0; }
    sycl::queue* q = kakasi::queue_for(backend);
    if (!q) { g_err = "backend unavailable"; return -1.0; }
    const int dtype = gguf::tensor_dtype(tensor);
    if (!supported((uint32_t) dtype)) { g_err = "dtype not implemented yet"; return -2.0; }

    const int K = (int) gguf::tensor_ne(tensor, 0);
    const int N = (int) gguf::tensor_ne(tensor, 1);
    if (K <= 0 || N <= 0 || M <= 0 || M > 64) { g_err = "bad shape"; return -1.0; }
    const uint64_t numel = (uint64_t) K * (uint64_t) N;

    std::vector<uint8_t> raw;
    if (!gguf::raw_bytes(tensor, raw)) { g_err = "raw_bytes failed"; return -1.0; }
    std::vector<float> W;
    if (!ref_dequant(raw, (uint32_t) dtype, numel, W)) { g_err = "dequant failed"; return -1.0; }

    std::vector<float> X((size_t) M * K);
    for (size_t i = 0; i < X.size(); ++i) X[i] = (float) ((i * 37) % 101) / 101.0f - 0.5f;

    std::vector<float> ref((size_t) M * N, 0.0f);
    for (int m = 0; m < M; ++m)
        for (int n = 0; n < N; ++n) {
            double s = 0.0;
            for (int k = 0; k < K; ++k) s += (double) X[(size_t) m * K + k] * (double) W[(size_t) n * K + k];
            ref[(size_t) m * N + n] = (float) s;
        }

    std::vector<float> got((size_t) M * N, 0.0f);
    auto t0 = std::chrono::steady_clock::now();
    float* dX = sycl::malloc_device<float>(X.size(), *q);
    float* dW = sycl::malloc_device<float>(W.size(), *q);
    float* dY = sycl::malloc_device<float>(got.size(), *q);
    q->memcpy(dX, X.data(), sizeof(float) * X.size());
    q->memcpy(dW, W.data(), sizeof(float) * W.size());
    q->wait_and_throw();
    GemmArgs ga{dX, dW, dY, M, N, K};
    device_gemm(*q, ga);
    q->memcpy(got.data(), dY, sizeof(float) * got.size());
    q->wait_and_throw();
    sycl::free(dX, *q);
    sycl::free(dW, *q);
    sycl::free(dY, *q);
    g_ms = std::chrono::duration<double, std::milli>(std::chrono::steady_clock::now() - t0).count();

    double scale = 0.0;
    for (float v : ref) scale = std::max(scale, (double) std::fabs(v));
    if (scale == 0.0) return 0.0;
    return max_rel_abs(ref, got) / scale;
}

double rmsnorm_maxerr(int backend, int len) {
    g_err.clear();
    g_ms = 0.0;
    sycl::queue* q = kakasi::queue_for(backend);
    if (!q) { g_err = "backend unavailable"; return -1.0; }
    if (len < 1 || len > 65536) { g_err = "bad length"; return -1.0; }
    std::vector<float> x((size_t) len);
    for (int i = 0; i < len; ++i) x[i] = (float) std::sin((double) i * 0.017) * 1.7f;

    const float eps = 1e-6f;
    double acc = 0.0;
    for (int i = 0; i < len; ++i) acc += (double) x[i] * x[i];
    const float inv = (float) (1.0 / std::sqrt(acc / (double) len + (double) eps));
    std::vector<float> ref((size_t) len);
    for (int i = 0; i < len; ++i) ref[i] = x[i] * inv;

    std::vector<float> got((size_t) len, 0.0f);
    float* dX = sycl::malloc_device<float>(x.size(), *q);
    float* dY = sycl::malloc_device<float>(got.size(), *q);
    float* dRed = sycl::malloc_device<float>(1, *q);
    q->memcpy(dX, x.data(), sizeof(float) * x.size());
    q->wait_and_throw();
    auto t0 = std::chrono::steady_clock::now();
    const int n = len;
    // Deterministic two-kernel form: one work item sums the squares, then the scale is applied.
    // float atomic_ref with relaxed ordering + a plain cross-kernel read was nondeterministic on
    // one backend; a tree reduction belongs to the real attention op, not to this correctness check.
    q->single_task([=] {
        float s = 0.0f;
        for (int i = 0; i < n; ++i) {
            float v = dX[i];
            s += v * v;
        }
        *dRed = s;
    });
    q->wait_and_throw();
    q->parallel_for(sycl::range<1>(len), [=](sycl::item<1> it) {
        const float s = *dRed;
        dY[it.get_id(0)] = dX[it.get_id(0)] * (1.0f / sycl::sqrt(s / (float) n + eps));
    });
    q->wait_and_throw();
    q->memcpy(got.data(), dY, sizeof(float) * got.size());
    q->wait_and_throw();
    sycl::free(dX, *q);
    sycl::free(dY, *q);
    sycl::free(dRed, *q);
    g_ms = std::chrono::duration<double, std::milli>(std::chrono::steady_clock::now() - t0).count();
    return max_rel_abs(ref, got);
}

}  // namespace ops
