// plugin_closed_loop_harness.cc - F694 v1 acceptance ② and ③ (host side).
//
// ② closed loop over the REAL OpenCL reference plugin, loaded exactly the way the engine's
//    v2 loader will: dlopen -> dlsym(entry) -> ABI handshake -> caps/role check -> device ->
//    stream -> mem_alloc -> copy_h2d -> ops -> stream_sync -> copy_d2h -> compare against an
//    independent double-accumulated CPU reference. Includes the self-determinism check
//    (same inputs twice -> byte-identical outputs) from the numeric contract.
// ③ three refusal paths, each with a NAMED output line (never silent):
//      1. abi-major mismatch  -> entry(want=2.0) must return NULL
//      2. caps missing        -> a stub claiming only DEVICE|MEM must fail the compute-role check
//      3. entry symbol absent -> dlsym on a dylib without the entry must return NULL
//
// Build & run: see probe/acpp/README or the closure notes in progress.md.
#include "../../engine/src/plugins/plugin_abi.h"
#include <dlfcn.h>
#include <cstdio>
#include <cstdint>
#include <cstring>
#include <cstdlib>
#include <cmath>
#include <vector>

namespace {
double now_ms_dummy() { return 0.0; }  /* timing is not asserted in v1 */

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

int failures = 0;
void fail(const char* what) { std::printf("PLUGIN-LOOP: FAIL %s\n", what); ++failures; }
}  // namespace

int main(int argc, char** argv) {
    const char* pluginPath = (argc > 1) ? argv[1] : "/tmp/f693/libkakasi_plugin_opencl.dylib";
    const char* stubNocaps = (argc > 2) ? argv[2] : "/tmp/f693/libplugin_stub_nocaps.dylib";
    const char* stubNoentry = (argc > 3) ? argv[3] : "/tmp/f693/libplugin_stub_noentry.dylib";
    int problems = 0;

    // ---------- loader (the v2 engine loader's core logic, exercised standalone) ----------
    typedef const kakasi_plugin_vtable* (*entry_t)(uint32_t);
    void* so = dlopen(pluginPath, RTLD_NOW | RTLD_LOCAL);
    if (!so) { std::printf("PLUGIN-CLOSED-LOOP: FAIL dlopen %s: %s\n", pluginPath, dlerror()); return 1; }
    entry_t entry = (entry_t) dlsym(so, "kakasi_plugin_get_vtable");
    if (!entry) {
        std::printf("PLUGIN-REFUSE entry: symbol kakasi_plugin_get_vtable not found in %s (%s)\n",
                    pluginPath, dlerror());
        ++failures;
    }
    // ③-1: ABI major mismatch must be refused BY the plugin (NULL), by name here.
    {
        const kakasi_plugin_vtable* v = entry ? entry(KAKASI_PLUGIN_ABI(2, 0)) : nullptr;
        if (v != nullptr) {
            std::printf("PLUGIN-REFUSE abi: FAIL - want_abi=0x20000 was accepted\n");
            ++failures;
        } else {
            std::printf("PLUGIN-REFUSE abi: refused (NULL) want_abi=0x%08x -> named refusal ok\n",
                        KAKASI_PLUGIN_ABI(2, 0));
        }
    }
    const kakasi_plugin_vtable* v = entry ? entry(KAKASI_PLUGIN_ABI(1, 9)) : nullptr;
    if (!v || v->abi_version != KAKASI_PLUGIN_ABI_CURRENT ||
        v->vtable_size < (uint32_t) sizeof(kakasi_plugin_vtable)) {
        std::printf("PLUGIN-CLOSED-LOOP: FAIL handshake\n");
        return 1;
    }
    // ③-2: role check - a plugin without the compute bits must be refused at load, by name.
    {
        const uint64_t caps = v->query_caps();
        const uint64_t missing = KAKASI_PLUGIN_ROLE_COMPUTE_CAPS & ~caps;
        if (missing) {
            std::printf("PLUGIN-REFUSE caps: role=compute missing=0x%llx\n", (unsigned long long) missing);
            ++failures;
        } else {
            std::printf("PLUGIN-REFUSE caps: role=compute satisfied (caps=0x%llx)\n", (unsigned long long) caps);
        }
        // claimed-caps-imply-non-NULL invariant
        struct Slot { uint64_t bit; void* fn; const char* name; };
        Slot slots[] = {
            {KAKASI_PLUGIN_CAP_DEVICE, (void*) v->device_count, "device_count"},
            {KAKASI_PLUGIN_CAP_MEM, (void*) v->mem_alloc, "mem_alloc"},
            {KAKASI_PLUGIN_CAP_GEMV_MM, (void*) v->gemv_mm, "gemv_mm"},
            {KAKASI_PLUGIN_CAP_GEMM, (void*) v->gemm_tiled, "gemm_tiled"},
            {KAKASI_PLUGIN_CAP_DEQUANT_Q8_0, (void*) v->dequant_q8_0, "dequant_q8_0"},
            {KAKASI_PLUGIN_CAP_RMSNORM, (void*) v->rmsnorm, "rmsnorm"},
        };
        for (auto& s : slots) {
            if ((caps & s.bit) && !s.fn) {
                std::printf("PLUGIN-REFUSE caps: claimed bit=0x%llx but %s is NULL\n",
                            (unsigned long long) s.bit, s.name);
                ++failures;
            }
        }
    }

    // ---------- closed loop: devices ----------
    const int nDev = v->device_count();
    if (nDev <= 0) { std::printf("PLUGIN-CLOSED-LOOP: FAIL device_count=%d (%s)\n", nDev, v->last_error(nullptr)); return 1; }
    struct kakasi_plugin_device_info di;
    di.struct_size = (uint32_t) sizeof(di);
    if (v->device_info(0, &di) != KAKASI_PLUGIN_OK || !di.name) {
        std::printf("PLUGIN-CLOSED-LOOP: FAIL device_info rc=%d\n", 0);
        return 1;
    }
    std::printf("PLUGIN-LOOP device: %s caps=0x%llx mem=%lluMB\n", di.name,
                (unsigned long long) di.caps, (unsigned long long) (di.mem_bytes >> 20));
    void* dev = v->device_open(0);
    if (!dev) { std::printf("PLUGIN-CLOSED-LOOP: FAIL device_open (%s)\n", v->last_error(nullptr)); return 1; }
    void* stream = v->stream_create(dev);
    if (!stream) { std::printf("PLUGIN-CLOSED-LOOP: FAIL stream_create\n"); return 1; }

    // ---------- op 1+2: gemv_mm, both layer shapes + determinism ----------
    for (int caseIdx = 0; caseIdx < 2; ++caseIdx) {
        const int K = (caseIdx == 0) ? 896 : 4864;
        const int N = (caseIdx == 0) ? 4864 : 896;
        const int M = (caseIdx == 0) ? 2 : 4;
        const int ldy = N;
        std::srand(100 + caseIdx);
        std::vector<float> X((size_t) M * K), W((size_t) N * K), bias(N);
        for (auto& f : X) f = (float) std::rand() / RAND_MAX - 0.5f;
        for (auto& f : W) f = ((float) std::rand() / RAND_MAX - 0.5f) * 0.1f;
        for (auto& f : bias) f = (float) std::rand() / RAND_MAX * 0.01f;
        std::vector<float> Yref((size_t) M * N, 0.0f);
        for (int m = 0; m < M; ++m) {
            for (int row = 0; row < N; ++row) {
                double acc = 0.0;
                for (int k = 0; k < K; ++k) acc += (double) X[(size_t) m * K + k] * (double) W[(size_t) row * K + k];
                Yref[(size_t) m * N + row] = (float) acc + bias[(size_t) row];
            }
        }
        void* dX = v->mem_alloc(dev, (uint64_t) X.size() * 4, KAKASI_PLUGIN_MEM_DEVICE);
        void* dW = v->mem_alloc(dev, (uint64_t) W.size() * 4, KAKASI_PLUGIN_MEM_DEVICE);
        void* dB = v->mem_alloc(dev, (uint64_t) bias.size() * 4, KAKASI_PLUGIN_MEM_DEVICE);
        void* dY = v->mem_alloc(dev, (uint64_t) M * ldy * 4, KAKASI_PLUGIN_MEM_DEVICE);
        void* dY2 = v->mem_alloc(dev, (uint64_t) M * ldy * 4, KAKASI_PLUGIN_MEM_DEVICE);
        if (!dX || !dW || !dB || !dY || !dY2) { fail("mem_alloc"); break; }
        bool ok = v->copy_h2d(stream, dX, X.data(), X.size() * 4) == KAKASI_PLUGIN_OK
               && v->copy_h2d(stream, dW, W.data(), W.size() * 4) == KAKASI_PLUGIN_OK
               && v->copy_h2d(stream, dB, bias.data(), bias.size() * 4) == KAKASI_PLUGIN_OK;
        if (!ok) { fail("copy_h2d"); break; }
        const int32_t rc1 = v->gemv_mm(stream, (const float*) dX, (const float*) dW,
                                       (const float*) dB, (float*) dY, K, N, M, ldy);
        const int32_t rc2 = v->gemv_mm(stream, (const float*) dX, (const float*) dW,
                                       (const float*) dB, (float*) dY2, K, N, M, ldy);
        if (rc1 != KAKASI_PLUGIN_OK || rc2 != KAKASI_PLUGIN_OK) {
            std::printf("PLUGIN-CLOSED-LOOP: FAIL gemv_mm rc=%d/%d (%s)\n", rc1, rc2, v->last_error(dev));
            ++failures;
        } else {
            v->stream_sync(stream);
            std::vector<float> Ygot((size_t) M * ldy, 0.0f), Ygot2((size_t) M * ldy, 0.0f);
            v->copy_d2h(stream, Ygot.data(), dY, Ygot.size() * 4);
            v->copy_d2h(stream, Ygot2.data(), dY2, Ygot2.size() * 4);
            Check c = cmp(Ygot, Yref);
            const bool num = c.rel < 5e-4;
            const bool det = (Ygot.size() == Ygot2.size()) &&
                             (std::memcmp(Ygot.data(), Ygot2.data(), Ygot.size() * 4) == 0);
            if (!num || !det) ++failures;
            std::printf("PLUGIN-LOOP gemv_mm M=%d K=%d N=%d: maxdiff=%.3e rel=%.3e %s | determinism=%s\n",
                        M, K, N, c.maxdiff, c.rel, num ? "ok" : "TOLERANCE_FAIL", det ? "PASS" : "FAIL");
        }
        v->mem_free(dev, dX); v->mem_free(dev, dW); v->mem_free(dev, dB);
        v->mem_free(dev, dY); v->mem_free(dev, dY2);
    }

    // ---------- op 3: gemm_tiled ----------
    {
        const int M = 512, K = 896, N = 4864;
        std::srand(200);
        std::vector<float> X((size_t) M * K), W((size_t) N * K);
        for (auto& f : X) f = (float) std::rand() / RAND_MAX - 0.5f;
        for (auto& f : W) f = ((float) std::rand() / RAND_MAX - 0.5f) * 0.1f;
        std::vector<float> Yref((size_t) M * N, 0.0f);
        for (int r = 0; r < M; ++r) {
            for (int col = 0; col < N; ++col) {
                double acc = 0.0;
                for (int k = 0; k < K; ++k) acc += (double) X[(size_t) r * K + k] * (double) W[(size_t) col * K + k];
                Yref[(size_t) r * N + col] = (float) acc;
            }
        }
        void* dX = v->mem_alloc(dev, (uint64_t) X.size() * 4, KAKASI_PLUGIN_MEM_DEVICE);
        void* dW = v->mem_alloc(dev, (uint64_t) W.size() * 4, KAKASI_PLUGIN_MEM_DEVICE);
        void* dY = v->mem_alloc(dev, (uint64_t) M * N * 4, KAKASI_PLUGIN_MEM_DEVICE);
        if (dX && dW && dY
            && v->copy_h2d(stream, dX, X.data(), X.size() * 4) == KAKASI_PLUGIN_OK
            && v->copy_h2d(stream, dW, W.data(), W.size() * 4) == KAKASI_PLUGIN_OK
            && v->gemm_tiled(stream, (const float*) dX, (const float*) dW, (float*) dY, M, N, K) == KAKASI_PLUGIN_OK) {
            v->stream_sync(stream);
            std::vector<float> Ygot((size_t) M * N, 0.0f);
            v->copy_d2h(stream, Ygot.data(), dY, Ygot.size() * 4);
            Check c = cmp(Ygot, Yref);
            const bool num = c.rel < 5e-3;
            if (!num) ++failures;
            std::printf("PLUGIN-LOOP gemm_tiled M=%d K=%d N=%d: maxdiff=%.3e rel=%.3e %s\n",
                        M, K, N, c.maxdiff, c.rel, num ? "ok" : "TOLERANCE_FAIL");
        } else {
            fail("gemm_tiled path");
        }
        v->mem_free(dev, dX); v->mem_free(dev, dW); v->mem_free(dev, dY);
    }

    // ---------- op 4: dequant_q8_0 (bit-exact contract) ----------
    {
        const uint64_t nb = 10000;
        std::srand(300);
        std::vector<uint8_t> raw((size_t) nb * 34);
        for (auto& b : raw) b = (uint8_t) (std::rand() & 0xFF);
        // Clamp each block's f16 scale OUT of the NaN/Inf exponent field (exp all-ones): real
        // model scales are finite, and two NaNs may carry different payloads, which a bitwise
        // compare would call a false mismatch. srand(300) hit one (scale16=ffca) - findings F694.
        for (uint64_t b = 0; b < nb; ++b) {
            uint8_t* p = raw.data() + b * 34;
            if ((p[1] & 0x7C) == 0x7C) p[1] = (uint8_t) ((p[1] & 0x83) | 0x78);  /* exp 31 -> 30 */
        }
        std::vector<float> Oref((size_t) nb * 32, 0.0f);
        for (uint64_t b = 0; b < nb; ++b) {
            const uint8_t* p = raw.data() + b * 34;
            const uint16_t hb = (uint16_t)(p[0] | ((uint16_t) p[1] << 8));
            __fp16 h;
            std::memcpy(&h, &hb, 2);
            const float d = (float) h;
            for (int i = 0; i < 32; ++i) Oref[b * 32 + i] = d * (float) ((int) ((char) p[2 + i]));
        }
        void* dR = v->mem_alloc(dev, (uint64_t) raw.size(), KAKASI_PLUGIN_MEM_DEVICE);
        void* dO = v->mem_alloc(dev, (uint64_t) nb * 32 * 4, KAKASI_PLUGIN_MEM_DEVICE);
        if (dR && dO
            && v->copy_h2d(stream, dR, raw.data(), raw.size()) == KAKASI_PLUGIN_OK
            && v->dequant_q8_0(stream, (const uint8_t*) dR, (float*) dO, nb) == KAKASI_PLUGIN_OK) {
            v->stream_sync(stream);
            std::vector<float> Ogot((size_t) nb * 32, 0.0f);
            v->copy_d2h(stream, Ogot.data(), dO, Ogot.size() * 4);
            int badIdx = -1;
            for (size_t i = 0; i < Oref.size(); ++i) {
                const bool bothNaN = ([](float f) { uint32_t u; std::memcpy(&u, &f, 4); return (u & 0x7fffffffu) > 0x7f800000u; })(Ogot[i])
                                  && ([](float f) { uint32_t u; std::memcpy(&u, &f, 4); return (u & 0x7fffffffu) > 0x7f800000u; })(Oref[i]);
                if (!bothNaN && std::memcmp(&Ogot[i], &Oref[i], 4) != 0) { badIdx = (int) i; break; }
            }
            if (badIdx >= 0) {
                const uint64_t blk = (uint64_t) badIdx / 32;
                const uint8_t* p = raw.data() + blk * 34;
                std::printf("PLUGIN-LOOP dequant DIAG: first mismatch idx=%d blk=%llu got=%.6f ref=%.6f scale16=%02x%02x q0=%d\n",
                            badIdx, (unsigned long long) blk, Ogot[(size_t) badIdx], Oref[(size_t) badIdx],
                            p[1], p[0], (int) ((char) p[2]));
            }
            const bool exact = (badIdx < 0);
            if (!exact) ++failures;
            std::printf("PLUGIN-LOOP dequant_q8_0 blocks=%llu: %s\n",
                        (unsigned long long) nb, exact ? "ok (bit-exact)" : "EXACTNESS_FAIL");
        } else {
            fail("dequant path");
        }
        v->mem_free(dev, dR); v->mem_free(dev, dO);
    }

    // ---------- op 5: rmsnorm ----------
    {
        const int D = 896;
        std::srand(400);
        std::vector<float> x(D), w(D);
        for (auto& f : x) f = (float) std::rand() / RAND_MAX - 0.5f;
        for (auto& f : w) f = 0.5f + (float) std::rand() / RAND_MAX;
        const float eps = 1e-5f;
        double ss = 0.0;
        for (int i = 0; i < D; ++i) ss += (double) x[i] * (double) x[i];
        const float inv = (float) (1.0 / std::sqrt(ss / (double) D + (double) eps));
        std::vector<float> Oref(D);
        for (int i = 0; i < D; ++i) Oref[(size_t) i] = x[(size_t) i] * inv * w[(size_t) i];
        void* dX = v->mem_alloc(dev, (uint64_t) D * 4, KAKASI_PLUGIN_MEM_DEVICE);
        void* dW = v->mem_alloc(dev, (uint64_t) D * 4, KAKASI_PLUGIN_MEM_DEVICE);
        void* dO = v->mem_alloc(dev, (uint64_t) D * 4, KAKASI_PLUGIN_MEM_DEVICE);
        if (dX && dW && dO
            && v->copy_h2d(stream, dX, x.data(), x.size() * 4) == KAKASI_PLUGIN_OK
            && v->copy_h2d(stream, dW, w.data(), w.size() * 4) == KAKASI_PLUGIN_OK
            && v->rmsnorm(stream, (const float*) dX, (const float*) dW, (float*) dO, D, eps) == KAKASI_PLUGIN_OK) {
            v->stream_sync(stream);
            std::vector<float> Ogot(D, 0.0f);
            v->copy_d2h(stream, Ogot.data(), dO, Ogot.size() * 4);
            Check c = cmp(Ogot, Oref);
            const bool num = c.rel < 1e-4;
            if (!num) ++failures;
            std::printf("PLUGIN-LOOP rmsnorm D=%d: maxdiff=%.3e rel=%.3e %s\n",
                        D, c.maxdiff, c.rel, num ? "ok" : "TOLERANCE_FAIL");
        } else {
            fail("rmsnorm path");
        }
        v->mem_free(dev, dX); v->mem_free(dev, dW); v->mem_free(dev, dO);
    }

    v->stream_destroy(stream);
    v->device_close(dev);

    // ---------- ③-2: caps-missing stub must fail the role check, by name ----------
    {
        void* so2 = dlopen(stubNocaps, RTLD_NOW | RTLD_LOCAL);
        if (!so2) { std::printf("PLUGIN-REFUSE caps: FAIL cannot load stub %s (%s)\n", stubNocaps, dlerror()); ++failures; }
        else {
            entry_t e2 = (entry_t) dlsym(so2, "kakasi_plugin_get_vtable");
            const kakasi_plugin_vtable* v2 = e2 ? e2(KAKASI_PLUGIN_ABI(1, 0)) : nullptr;
            if (!v2) { std::printf("PLUGIN-REFUSE caps: FAIL stub handshake\n"); ++failures; }
            else {
                const uint64_t missing = KAKASI_PLUGIN_ROLE_COMPUTE_CAPS & ~v2->query_caps();
                if (missing) {
                    std::printf("PLUGIN-REFUSE caps: stub refused, role=compute missing=0x%llx -> named refusal ok\n",
                                (unsigned long long) missing);
                } else {
                    std::printf("PLUGIN-REFUSE caps: FAIL stub unexpectedly satisfied compute role\n");
                    ++failures;
                }
            }
        }
    }
    // ---------- ③-3: entry-symbol-missing dylib must fail dlsym, by name ----------
    {
        void* so3 = dlopen(stubNoentry, RTLD_NOW | RTLD_LOCAL);
        if (!so3) { std::printf("PLUGIN-REFUSE entry: FAIL cannot load stub %s (%s)\n", stubNoentry, dlerror()); ++failures; }
        else {
            entry_t e3 = (entry_t) dlsym(so3, "kakasi_plugin_get_vtable");
            if (!e3) {
                std::printf("PLUGIN-REFUSE entry: symbol not found in %s -> named refusal ok\n", stubNoentry);
            } else {
                std::printf("PLUGIN-REFUSE entry: FAIL stub unexpectedly exports the entry\n");
                ++failures;
            }
        }
    }

    if (failures == 0) {
        std::printf("PLUGIN-CLOSED-LOOP: PASS (closed loop + 3 named refusal paths on %s)\n", di.name);
    } else {
        std::printf("PLUGIN-CLOSED-LOOP: FAIL failures=%d\n", failures);
    }
    return failures == 0 ? 0 : 1;
}
