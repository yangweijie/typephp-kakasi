// Qwen2 forward pass, executed on the SYCL backend.
// Weights are dequantized to device memory once and kept (budgeted residency); the tied
// lm_head still runs in row chunks so its 136M-element matrix never lands on the host.
#include <sycl/sycl.hpp>

#include "gguf.h"
#include "sycl_backend.h"

#include <algorithm>
#include <chrono>
#include <cmath>
#include <cstdint>
#include <cstdio>
#include <fstream>
#include <map>
#include <string>
#include <unordered_map>
#include <unordered_set>
#include <vector>

namespace model {
namespace {
    std::string g_err;
    double g_top = 0.0;
    double g_second = 0.0;
    double g_expect_logit = 0.0;
    double g_logsumexp = 0.0;      // log Z of the last expect_id evaluation
    int g_expect_rank = -1;
    int g_expect_id = -1;
    std::vector<double> g_trace;   // max|x| after embedding, then after each layer
    // Milliseconds split across the stages of one forward pass (embedding, block loop,
    // lm_head), so a per-step cost can be attributed instead of guessed at.
    std::vector<double> g_stage;
    // Logits of the most recent forward pass, kept so the sampler can run over them.
    // Cleared at the start of every pass: a failed pass must leave nothing to sample from.
    std::vector<float> g_last_logits;
    int g_nucleus = 0;             // nucleus size of the first sampled token of the last run

    // Persistent KV cache: the prefill writes rows [0,T) and each decode step appends one
    // row, so a step costs one token of block work instead of re-running the prompt.
    struct KVCache {
        sycl::queue* q = nullptr;
        int backend = -1;
        int cap = 0;
        int len = 0;
        int dkv = 0;
        bool f16 = false;                 // half-width storage (FR-18 KV quantization knob)
        std::vector<void*> k;
        std::vector<void*> v;
        size_t elem() const { return f16 ? sizeof(sycl::half) : sizeof(float); }
        void free_all() {
            if (q) {
                // Queued kernels may still read these rows (resizing, backend switch, KV mode
                // switch). Drain before the memory goes back to the driver.
                q->wait_and_throw();
                for (void* p : k) if (p) sycl::free(p, *q);
                for (void* p : v) if (p) sycl::free(p, *q);
            }
            k.clear(); v.clear();
            q = nullptr; cap = 0; len = 0; backend = -1;
        }
    };
    KVCache g_kv;
    KVCache g_kv2;                     // second device's cache, used only by the layer split
    int g_split_backend = -1;          // second device for layers [g_split_layer, L)
    int g_split_layer = -1;
    constexpr int KV_MIN_CAP = 512;

    // Lazy weight residency: a dequantized tensor is uploaded once and kept, instead of
    // being re-read and re-dequantized on every decode step. The budget is a fraction of
    // the device's memory; past that, tensors fall back to temporary buffers. This is also
    // the memory-budget primitive the orchestration layer (FR-18) needs.
    struct Residency {
        sycl::queue* q = nullptr;
        bool enabled = false;
        size_t used = 0;
        size_t budget = 0;
        std::unordered_map<int, float*> keep;        // small tensors, f32
        std::unordered_map<int, sycl::half*> keep_h; // big tensors, f16
        std::unordered_map<int, std::pair<int8_t*, float*>> keep_q;  // big tensors, int8 + scales
        // Streamed buffers remember which queue allocated them: with the layer split, weights
        // for the second device must be freed on that device, never on the primary one.
        std::unordered_map<float*, sycl::queue*> temp;
        void clear_all() {
            if (q) {
                q->wait_and_throw();   // queued kernels may still read the kept tensors
                for (auto& kv : keep) sycl::free(kv.second, *q);
                for (auto& kv : keep_h) sycl::free(kv.second, *q);
                for (auto& kv : keep_q) {
                    sycl::free(kv.second.first, *q);
                    sycl::free(kv.second.second, *q);
                }
            }
            for (auto& kv : temp) {
                kv.second->wait_and_throw();
                sycl::free(kv.first, *kv.second);
            }
            keep.clear(); keep_h.clear(); keep_q.clear(); temp.clear(); used = 0;
        }
    };
    Residency g_res;
    double g_dev_mem_gib = 0.0;
    double g_budget_override_gib = 0.0;   // >0 pins the residency budget (low-memory device)
    int g_wprec = 1;                      // resident big-tensor precision: 0 = f16, 1 = int8
    int g_sess_len = 0;                   // tokens consumed so far by the streaming session
    uint64_t g_sess_rng = 0;
    // Speculative decoding state: how many positions the head should score, the argmax it found
    // for each (all but the last), and counters for the accept/reject report.
    int g_head_rows = 1;
    std::vector<int> g_step_top1;
    // Tree speculative sampling (F307): the verifier needs p(draft) at EVERY node of the tree,
    // not only at the last row, so the lm_head loop can be handed one wanted id per row and
    // reports back that id's probability plus a draw from the residual of the same row.
    std::vector<int> g_head_want;
    std::vector<double> g_head_prob;
    std::vector<int> g_head_alt;
    double g_head_temp = 1.0;
    // F308: RoPE positions for the rows of the current pass, RELATIVE to `pos`. Empty means the
    // plain causal convention (row t sits at pos+t). A tree pass fills it with each node's LOGICAL
    // depth, because all branches stand at the same depth and must be rotated as if they were the
    // one true continuation - otherwise only branch 0 is ever verified against a real position.
    std::vector<int> g_row_pos;
    int g_spec_alt = 0;               // commits taken from a non-zero tree branch (F308 coverage)
    int g_spec_bmax = 0;              // widest branch list ever collected in this call (F313)
    int g_spec_freq = 1;              // FR-06.2: frequency-ranked draft branches (0 = recency order)
    int g_spec_multiwin = 1;          // FR-06.2 step 2: widen the lookup window to fill B branches
    int g_spec_fix = 1;               // F315 control: 0 disables the depth-position + compaction
                                      // repair inside the SAME build, so the defect can be shown
                                      // behaviourally instead of argued.
    // Device-side tree mask for the current verify pass; nullptr means plain causal attention.
    // Kept as module state so prefill_top1's external signature (and its mangled name) never
    // changes - the same reason g_head_rows is a global.
    const uint32_t* g_tree_mask = nullptr;
    int g_spec_drafts = 0, g_spec_accepted = 0, g_spec_verified = 0;
    int g_spec_on = 1;                    // 0 once the adaptive rule disabled drafting
    // Which tensors residency intends to keep, chosen by reuse value per byte rather than by
    // the order the model file happens to list them in.
    std::unordered_set<int> g_keep_plan;
    size_t g_keep_plan_bytes = 0;
    int g_keep_plan_total = 0;

    inline uint16_t ld16(const uint8_t* p) { return (uint16_t)(p[0] | (p[1] << 8)); }
    inline float h2f(uint16_t b) { return (float) sycl::bit_cast<sycl::half>(b); }

    // ---- device helpers -------------------------------------------------------

    // Dequantizes a whole tensor's raw bytes into a fresh device float buffer.
    float* dequant_device(sycl::queue& q, const std::vector<uint8_t>& raw, uint32_t dtype, uint64_t numel) {
        uint8_t* dRaw = sycl::malloc_device<uint8_t>(raw.size(), q);
        float* dOut = sycl::malloc_device<float>(numel, q);
        q.memcpy(dRaw, raw.data(), raw.size());
        q.wait_and_throw();

        const uint64_t blk = (dtype == 0) ? 1u : ((dtype == 12 || dtype == 14) ? 256u : 32u);
        const uint64_t nblocks = numel / blk;
        q.parallel_for(sycl::range<1>(nblocks), [=](sycl::item<1> it) {
            const uint64_t b = it.get_id(0);
            const uint8_t* r = dRaw;
            if (dtype == 0) {
                dOut[b] = ((const float*) r)[b];
                return;
            }
            if (dtype == 8) {
                const uint8_t* p = r + b * 34;
                const float d = h2f(ld16(p));
                for (int i = 0; i < 32; ++i) dOut[b * 32 + i] = d * (float) ((int8_t) p[2 + i]);
                return;
            }
            if (dtype == 6) {
                const uint8_t* p = r + b * 22;
                const float d = h2f(ld16(p));
                const uint32_t qhw = (uint32_t) p[2] | ((uint32_t) p[3] << 8) |
                                     ((uint32_t) p[4] << 16) | ((uint32_t) p[5] << 24);
                // upstream dequantize_row_q5_0 packing: qs[j] -> element j (low) and j+16 (high)
                for (int j = 0; j < 16; ++j) {
                    const int xh0 = ((qhw >> j) << 4) & 0x10;
                    const int xh1 = (qhw >> (j + 12)) & 0x10;
                    dOut[b * 32 + j] = d * (float) (((p[6 + j] & 0x0F) | xh0) - 16);
                    dOut[b * 32 + j + 16] = d * (float) (((p[6 + j] >> 4) | xh1) - 16);
                }
                return;
            }
            if (dtype == 12) {
                const uint8_t* p = r + b * 144;
                const float d = h2f(ld16(p));
                const float mn = h2f(ld16(p + 2));
                const uint8_t* sc = p + 4;
                const uint8_t* qs = p + 16;
                for (int s = 0; s < 8; ++s) {
                    uint8_t a, m;
                    if (s < 4) { a = sc[s] & 63; m = sc[s + 4] & 63; }
                    else {
                        a = (sc[s + 4] & 0xF) | ((sc[s - 4] >> 6) << 4);
                        m = (sc[s + 4] >> 4) | ((sc[s] >> 6) << 4);
                    }
                    const float ds = d * (float) a;
                    const float ms = mn * (float) m;
                    const int qbase = (s / 2) * 32;
                    const bool hi_nib = (s & 1) != 0;
                    for (int l = 0; l < 32; ++l) {
                        const int nib = hi_nib ? (qs[qbase + l] >> 4) : (qs[qbase + l] & 0xF);
                        dOut[b * 256 + s * 32 + l] = ds * (float) nib - ms;
                    }
                }
                return;
            }
            const uint8_t* p = r + b * 210;
            const float d = h2f(ld16(p + 208));
            const uint8_t* ql = p;
            const uint8_t* qh = p + 128;
            const int8_t* sc = (const int8_t*)(p + 192);
            for (int e = 0; e < 256; ++e) {
                const int c = e >> 7, r = e & 127, j = r >> 5, l = r & 31;
                const int si = c * 8 + j * 2 + (l >> 4);
                const int qhv = qh[c * 32 + l];
                int nib, two;
                if (j == 0) { nib = ql[c * 64 + l] & 0xF; two = (qhv >> 0) & 3; }
                else if (j == 1) { nib = ql[c * 64 + 32 + l] & 0xF; two = (qhv >> 2) & 3; }
                else if (j == 2) { nib = ql[c * 64 + l] >> 4; two = (qhv >> 4) & 3; }
                else { nib = ql[c * 64 + 32 + l] >> 4; two = (qhv >> 6) & 3; }
                dOut[b * 256 + e] = d * (float) sc[si] * (float) ((nib | (two << 4)) - 32);
            }
        });
        q.wait_and_throw();
        sycl::free(dRaw, q);
        return dOut;
    }

    // tiled GEMM: Y[M][N] = X[M][K] * W[N][K]^T (+ optional bias[N])
    // A weight handle. Big tensors are kept on the device as f16 - decode is bound by the bytes
    // it must read per token, so halving them is the remaining lever after F32 - while small
    // tensors (biases, norms) stay f32 so their consumers need no changes. Nothing here changes
    // the math: every element is widened on read.
    struct Wt {
        const void* p = nullptr;
        const float* s = nullptr;   // per-block scales, kind 2 only
        int kind = 0;               // 0 = f32, 1 = f16, 2 = int8 with one scale per 64 elements
        bool ok() const { return p != nullptr; }
    };

    // The single place that widens a weight element, so all three precisions share one kernel.
    inline float wat(Wt w, size_t i) {
        if (w.kind == 0) return ((const float*) w.p)[i];
        if (w.kind == 1) return (float) ((const sycl::half*) w.p)[i];
        return (float) ((const int8_t*) w.p)[i] * w.s[i >> 6];
    }

    // Single-token matrix-vector product (M=1). The tiled GEMM always fills a 16x16 tile, so a
    // decode step would compute 16x the needed work and discard 15 rows; decode was dominated
    // by exactly that waste (findings F32). One workgroup per output row, K reduced in strides.
    void gemv(sycl::queue& q, const float* X, int K, Wt w, int N, float* Y, const float* bias) {
        constexpr int TG = 64;
        q.submit([&](sycl::handler& h) {
            sycl::local_accessor<float, 1> red(sycl::range<1>(TG), h);
            h.parallel_for(sycl::nd_range<1>((size_t) N * TG, TG), [=](sycl::nd_item<1> item) {
                const int row = (int) item.get_group(0);
                const int lane = (int) item.get_local_id(0);
                const size_t base = (size_t) row * (size_t) K;
                float acc = 0.0f;
                for (int k = lane; k < K; k += TG) acc += X[k] * wat(w, base + (size_t) k);
                red[lane] = acc;
                item.barrier(sycl::access::fence_space::local_space);
                for (int s = TG / 2; s > 0; s >>= 1) {
                    if (lane < s) red[lane] += red[lane + s];
                    item.barrier(sycl::access::fence_space::local_space);
                }
                if (lane == 0) {
                    Y[(size_t) row] = red[0] + (bias ? bias[row] : 0.0f);
                }
            });
        });
    }

    void gemm(sycl::queue& q, const float* X, int M, int K, Wt w, int N, float* Y,
              const float* bias) {
        // Decode (M=1) goes to the matvec path; prefill keeps the tiled GEMM, which is the right
        // choice once M is large. Either reads any of the three weight precisions via wat().
        if (M == 1) { gemv(q, X, K, w, N, Y, bias); return; }
        constexpr int TM = 16, TN = 16, TK = 32;
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
                    for (int i = 0; i < 2; ++i) {
                        const int flat = (tn * TM + tm) * 2 + i;
                        const int xr = flat / TK, xc = flat % TK;
                        const int gr = row - tm + xr;
                        const int gc = kt + xc;
                        Xs[xr][xc] = (gr < M && gc < K) ? X[(size_t) gr * K + gc] : 0.0f;
                        const int wr = col - tn + xr;
                        Ws[xr][xc] = (wr < N && gc < K) ? wat(w, (size_t) wr * K + gc) : 0.0f;
                    }
                    item.barrier(sycl::access::fence_space::local_space);
                    for (int kk = 0; kk < TK; ++kk) acc += Xs[tm][kk] * Ws[tn][kk];
                    item.barrier(sycl::access::fence_space::local_space);
                }
                if (row < M && col < N) {
                    if (bias) acc += bias[col];
                    Y[(size_t) row * N + col] = acc;
                }
            });
        });
    }

    void rmsnorm(sycl::queue& q, const float* X, int T, int D, const float* w, float eps, float* Y) {
        q.parallel_for(sycl::range<2>(T, D), [=](sycl::item<2> it) {
            const int t = (int) it.get_id(0);
            const int d = (int) it.get_id(1);
            const float* row = X + (size_t) t * D;
            float s = 0.0f;
            for (int i = 0; i < D; ++i) s += row[i] * row[i];
            Y[(size_t) t * D + d] = row[d] * (1.0f / sycl::sqrt(s / (float) D + eps)) * w[d];
        });
    }

    // cos/sin tables: [T][D/2]
    void rope(sycl::queue& q, float* X, int T, int NH, int HD, const float* cosT, const float* sinT) {
        const int half = HD / 2;
        q.parallel_for(sycl::range<3>(T, NH, half), [=](sycl::item<3> it) {
            const int t = (int) it.get_id(0);
            const int h = (int) it.get_id(1);
            const int j = (int) it.get_id(2);
            float* base = X + ((size_t) t * NH + h) * HD;
            const float c = cosT[(size_t) t * half + j];
            const float s = sinT[(size_t) t * half + j];
            const float x1 = base[j];
            const float x2 = base[j + half];
            base[j] = x1 * c - x2 * s;
            base[j + half] = x2 * c + x1 * s;
        });
    }

    // Element-wise row store into the KV cache at absolute rows [pos, pos+T), narrowing to the
    // cache element type. Layout is [row][kv_head][dim], so source element (t, c) lands at
    // (pos + t) * dkv + c.
    template <typename KVt>
    void kv_store(sycl::queue& q, const float* src, KVt* dst, int T, int dkv, int pos) {
        const size_t n = (size_t) T * dkv;
        q.parallel_for(sycl::range<1>(n), [=](sycl::item<1> it) {
            const size_t i = it.get_id(0);
            const size_t t = i / (size_t) dkv;
            const size_t c = i % (size_t) dkv;
            dst[(pos + t) * (size_t) dkv + c] = (KVt) src[i];
        });
    }

    // Allocate (or grow) the per-layer cache. The prefill path and the prefix-cache loader must
    // agree on this layout exactly, so it lives in one place. Element width follows g_kv.f16,
    // so switching KV precision drops the old cache and rebuilds it at the new width.
    // `keep_rows` rows already written are copied into the new buffers: freeing first used to
    // discard them, so the first growth past KV_MIN_CAP left rows 0..511 uninitialized and every
    // later attention over them produced NaN logits (findings F265).
    bool ensure_kv(int backend, sycl::queue& q, int need_rows, int dkv, int layers, int keep_rows = 0) {
        if (g_kv.backend == backend && g_kv.cap >= need_rows) return true;
        if (keep_rows > g_kv.cap) keep_rows = g_kv.cap;
        const int cap = std::max(need_rows, KV_MIN_CAP);
        const size_t bytes = (size_t) cap * (size_t) dkv * g_kv.elem();
        std::vector<char*> nk;
        std::vector<char*> nv;
        for (int l = 0; l < layers; ++l) {
            nk.push_back(sycl::malloc_device<char>(bytes, q));
            nv.push_back(sycl::malloc_device<char>(bytes, q));
            if (!nk.back() || !nv.back()) return false;
        }
        if (keep_rows > 0 && (int) g_kv.k.size() == layers) {
            const size_t keep = (size_t) keep_rows * (size_t) dkv * g_kv.elem();
            for (int l = 0; l < layers; ++l) {
                q.memcpy(nk[l], g_kv.k[l], keep);
                q.memcpy(nv[l], g_kv.v[l], keep);
            }
            q.wait_and_throw();
        }
        g_kv.free_all();
        g_kv.q = &q; g_kv.backend = backend; g_kv.cap = cap; g_kv.dkv = dkv; g_kv.len = keep_rows;
        for (int l = 0; l < layers; ++l) {
            g_kv.k.push_back(nk[l]);
            g_kv.v.push_back(nv[l]);
        }
        return true;
    }

    // Causal attention over a KV cache holding rows [0, pos+T). Three key passes
    // (max, denominator, weighted sum) avoid per-item score storage, so sequence
    // length is bounded only by the cache. Templated on the cache element type so f32 and f16
    // storage share one kernel and one numeric path; each stored element is widened on read.
    //
    // `tree` (optional) turns plain causal masking into TREE masking: entry b is a bitmask of
    // the draft nodes that query b may attend to, where bit k means cache row pos+k. Prefix rows
    // (< pos) are always visible. This is what lets several draft branches share ONE verify pass
    // without the branches seeing each other - the requirement behind KFlash (FR-06.1).
    template <typename KVt>
    void attention(sycl::queue& q, const float* Q, const KVt* K, const KVt* V, int T, int pos, int NH, int NKV,
                   int HD, float scale, float* O, const uint32_t* tree = nullptr) {
        q.parallel_for(sycl::range<2>(T, NH), [=](sycl::item<2> it) {
            const int b = (int) it.get_id(0);
            const int h = (int) it.get_id(1);
            const int kv = h * NKV / NH;
            const float* qrow = Q + ((size_t) b * NH + h) * HD;
            const int last = pos + b;
            const bool masked = (tree != nullptr);
            const uint32_t allow = masked ? tree[b] : 0u;
            float mx = -1e30f;
            float sum = 0.0f;
            for (int j = 0; j <= last; ++j) {
                if (masked && j >= pos && !((allow >> (j - pos)) & 1u)) continue;
                const KVt* krow = K + ((size_t) j * NKV + kv) * HD;
                float s = 0.0f;
                for (int d = 0; d < HD; ++d) s += qrow[d] * (float) krow[d];
                s *= scale;
                mx = sycl::max(mx, s);
            }
            for (int j = 0; j <= last; ++j) {
                if (masked && j >= pos && !((allow >> (j - pos)) & 1u)) continue;
                const KVt* krow = K + ((size_t) j * NKV + kv) * HD;
                float s = 0.0f;
                for (int d = 0; d < HD; ++d) s += qrow[d] * (float) krow[d];
                sum += sycl::exp(s * scale - mx);
            }
            float* orow = O + ((size_t) b * NH + h) * HD;
            for (int d = 0; d < HD; ++d) orow[d] = 0.0f;
            for (int j = 0; j <= last; ++j) {
                if (masked && j >= pos && !((allow >> (j - pos)) & 1u)) continue;
                const KVt* krow = K + ((size_t) j * NKV + kv) * HD;
                const KVt* vrow = V + ((size_t) j * NKV + kv) * HD;
                float s = 0.0f;
                for (int d = 0; d < HD; ++d) s += qrow[d] * (float) krow[d];
                const float w = sycl::exp(s * scale - mx) / sum;
                for (int d = 0; d < HD; ++d) orow[d] += w * (float) vrow[d];
            }
        });
    }

    void silu_mul(sycl::queue& q, const float* G, const float* U, size_t n, float* O) {
        q.parallel_for(sycl::range<1>(n), [=](sycl::item<1> it) {
            const size_t i = it.get_id(0);
            const float g = G[i];
            O[i] = (g / (1.0f + sycl::exp(-g))) * U[i];
        });
    }

    void add_inplace(sycl::queue& q, float* A, const float* B, size_t n) {
        q.parallel_for(sycl::range<1>(n), [=](sycl::item<1> it) {
            const size_t i = it.get_id(0);
            A[i] += B[i];
        });
    }

    // Device-side narrow, used once per kept tensor.
    void to_half(sycl::queue& q, const float* src, sycl::half* dst, uint64_t n) {
        q.parallel_for(sycl::range<1>((size_t) n), [=](sycl::item<1> it) {
            const size_t i = it.get_id(0);
            dst[i] = (sycl::half) src[i];
        });
        q.wait_and_throw();
    }

    // Symmetric int8 with one scale per 64 contiguous elements: 1.0625 bytes per weight against
    // f16's 2, which is what decode is bound by. One work-item per block, so it is a single pass.
    void to_int8(sycl::queue& q, const float* src, int8_t* dst, float* scales, uint64_t n) {
        const uint64_t nb = (n + 63u) / 64u;
        q.parallel_for(sycl::range<1>((size_t) nb), [=](sycl::item<1> it) {
            const uint64_t b = it.get_id(0);
            const uint64_t i0 = b * 64u;
            const uint64_t i1 = (i0 + 64u < n) ? (i0 + 64u) : n;
            float mx = 0.0f;
            for (uint64_t i = i0; i < i1; ++i) mx = sycl::fmax(mx, sycl::fabs(src[i]));
            const float s = (mx > 0.0f) ? mx / 127.0f : 0.0f;
            const float inv = (s > 0.0f) ? 1.0f / s : 0.0f;
            scales[b] = s;
            for (uint64_t i = i0; i < i1; ++i) {
                float v = src[i] * inv;
                v = sycl::fmax(-127.0f, sycl::fmin(127.0f, v));
                dst[i] = (int8_t) sycl::round(v);
            }
        });
        q.wait_and_throw();
    }

    // Loads a named tensor as a device weight handle, keeping it resident when the budget
    // allows. Kept tensors larger than 1 MiB are narrowed to f16 in device memory: decode is
    // bound by the bytes it reads per token (findings F32), and every consumer widens on read.
    Wt load_weight_idx(sycl::queue& q, int idx, uint64_t numel) {
        auto hit = g_res.keep.find(idx);
        if (hit != g_res.keep.end()) return {hit->second, nullptr, 0};
        auto hit_h = g_res.keep_h.find(idx);
        if (hit_h != g_res.keep_h.end()) return {hit_h->second, nullptr, 1};
        auto hit_q = g_res.keep_q.find(idx);
        if (hit_q != g_res.keep_q.end()) return {hit_q->second.first, hit_q->second.second, 2};

        std::vector<uint8_t> raw;
        if (!gguf::raw_bytes(idx, raw)) { g_err = "raw read failed for tensor " + std::to_string(idx); return {}; }
        const uint32_t dt = (uint32_t) gguf::tensor_dtype(idx);
        float* p = dequant_device(q, raw, dt, numel);
        if (!p) { g_err = "dequant failed for tensor " + std::to_string(idx); return {}; }

        const size_t need = (size_t) numel * sizeof(float);
        // Residency keeps a single device's footprint, so it is bypassed while the layer split
        // is active; every weight streams on its own device instead.
        const bool keepable = g_res.enabled && g_split_layer < 0 &&
                              g_keep_plan.find(idx) != g_keep_plan.end();
        if (keepable && need > (1u << 20)) {
            if (g_wprec == 1) {
                const uint64_t nb = (numel + 63u) / 64u;
                int8_t* pq = sycl::malloc_device<int8_t>(numel, q);
                float* ps = sycl::malloc_device<float>(nb, q);
                to_int8(q, p, pq, ps, numel);
                sycl::free(p, q);
                g_res.keep_q[idx] = {pq, ps};
                g_res.used += (size_t) numel + (size_t) nb * sizeof(float);
                return {pq, ps, 2};
            }
            sycl::half* ph = sycl::malloc_device<sycl::half>(numel, q);
            to_half(q, p, ph, numel);
            sycl::free(p, q);
            g_res.keep_h[idx] = ph;
            g_res.used += (size_t) numel * sizeof(sycl::half);
            return {ph, nullptr, 1};
        }
        if (keepable) {
            g_res.keep[idx] = p;
            g_res.used += need;
        } else {
            g_res.temp[p] = &q;
        }
        return {p, nullptr, 0};
    }

    Wt load_weight(sycl::queue& q, const std::string& name, uint64_t numel) {
        const int idx = gguf::find_tensor(name);
        if (idx < 0) { g_err = "missing tensor " + name; return {}; }
        return load_weight_idx(q, idx, numel);
    }

    // Only non-resident tensors are freed; resident ones stay for the next step. The owning
    // queue is remembered per pointer so a split run frees each weight on its own device.
    // A streamed weight is drained before its memory goes back to the driver: the kernel that
    // consumed it is only ordered *behind* the free, and reclaiming it immediately corrupts
    // low-memory runs (the resident path never reaches this code, so it keeps running async).
    void weight_release(Wt w) {
        if (!w.p || w.kind != 0) return;   // f16/int8 handles are always resident
        float* p = (float*) w.p;
        auto it = g_res.temp.find(p);
        if (it == g_res.temp.end()) return;
        it->second->wait_and_throw();
        sycl::free(p, *it->second);
        g_res.temp.erase(it);
    }

    float* resident_ptr(int idx) {
        auto it = g_res.keep.find(idx);
        return it == g_res.keep.end() ? nullptr : it->second;
    }

    // Resident handle for a tensor that may be stored either way (the tied embedding is big and
    // therefore f16). Row arithmetic stays in the caller, in elements.
    Wt resident_handle(int idx) {
        auto it = g_res.keep.find(idx);
        if (it != g_res.keep.end()) return {it->second, nullptr, 0};
        auto it_h = g_res.keep_h.find(idx);
        if (it_h != g_res.keep_h.end()) return {it_h->second, nullptr, 1};
        auto it_q = g_res.keep_q.find(idx);
        if (it_q != g_res.keep_q.end()) return {it_q->second.first, it_q->second.second, 2};
        return {};
    }

    void copy_row(sycl::queue& q, Wt src, uint64_t offset, float* dst, int n) {
        if (src.kind == 0) {
            q.memcpy(dst, (const float*) src.p + offset, sizeof(float) * (size_t) n);
            return;
        }
        q.parallel_for(sycl::range<1>((size_t) n), [=](sycl::item<1> it) {
            const size_t i = it.get_id(0);
            dst[i] = wat(src, (size_t) offset + i);
        });
    }

    // Decide the resident set BEFORE touching any weight. Priority classes, hottest per byte
    // first: the tied embedding (read once per embedded token and again as the lm_head for all
    // 151936 output rows), then small tensors (norms and biases - touched by every layer, a few
    // KB each), then the large single-use-per-step weights. Within a class, smaller first, and
    // a tensor that does not fit is skipped rather than ending the scan, so one oversized
    // weight no longer blocks everything after it.
    void plan_residency(size_t budget) {
        g_keep_plan.clear();
        g_keep_plan_bytes = 0;
        const int embd = gguf::find_tensor("token_embd.weight");
        const int nt = gguf::tensor_count();
        g_keep_plan_total = nt;
        struct Cand { int idx; int prio; size_t bytes; };
        std::vector<Cand> v;
        v.reserve((size_t) nt);
        for (int i = 0; i < nt; ++i) {
            const size_t f32_bytes = (size_t) gguf::tensor_ne(i, 0) * (size_t) gguf::tensor_ne(i, 1)
                                     * sizeof(float);
            // Big tensors are stored narrow, so they cost less than their f32 footprint in the
            // budget: f16 is half, int8 (plus one scale per 64) is a quarter plus a 64th.
            const size_t cost = f32_bytes <= (1u << 20) ? f32_bytes
                : (g_wprec == 1 ? (f32_bytes / 4 + f32_bytes / 64) : f32_bytes / 2);
            int prio = 2;
            if (i == embd) prio = 0;
            else if (f32_bytes <= (1u << 20)) prio = 1;
            v.push_back({i, prio, cost});
        }
        std::sort(v.begin(), v.end(), [](const Cand& a, const Cand& b) {
            if (a.prio != b.prio) return a.prio < b.prio;
            return a.bytes < b.bytes;
        });
        size_t used = 0;
        for (const Cand& c : v) {
            if (used + c.bytes > budget) continue;
            used += c.bytes;
            g_keep_plan.insert(c.idx);
        }
        g_keep_plan_bytes = used;
    }

    void residency_prepare(sycl::queue& q, int backend) {
        if (g_res.q == &q && g_res.enabled) return;
        if (g_res.q != &q) g_res.clear_all();
        g_res.q = &q;
        g_res.enabled = true;
        const size_t total = q.get_device().get_info<sycl::info::device::global_mem_size>();
        g_dev_mem_gib = (double) total / (double) (1ull << 30);
        // Leave headroom for the KV cache, activations and driver allocations.
        // The orchestrator can shrink this to exercise the low-memory streaming path.
        const double frac = g_budget_override_gib > 0.0
            ? (double) (g_budget_override_gib * (double) (1ull << 30)) / (double) total : 0.55;
        g_res.budget = (size_t) ((double) total * frac);
        plan_residency(g_res.budget);
    }

    // Per-(queue) bump arena for the per-layer temporaries. Nothing is recycled inside a layer,
    // so the slot-reuse hazard that broke both earlier attempts (free-list pool, and a pool
    // ordered by ext_oneapi_submit_barrier events) cannot arise here; the bump pointer resets
    // only after a queue drain. Long-lived buffers - the running hidden state and per-device
    // scratch - do not use the arena, so a mid-pass reset can never take their memory.
    struct Arena {
        static constexpr size_t BLK = 1u << 20;   // 4 MiB blocks, floats
        std::vector<float*> blocks;
        std::vector<float*> oversized;
        size_t used = 0;
        size_t cap() const { return blocks.size() * BLK; }
    };
    std::map<const void*, Arena> g_arena;

    float* arena_acquire(sycl::queue& q, size_t count) {
        Arena& a = g_arena[(const void*) &q];
        if (count > Arena::BLK) {
            float* p = sycl::malloc_device<float>(count, q);
            a.oversized.push_back(p);
            return p;
        }
        // A slot must live inside ONE block: the bump pointer is aligned up to the next block
        // boundary when the remainder is too small, otherwise the request would silently wrap
        // into the following block and overlap the next allocation.
        size_t off = a.used;
        const size_t in_block = off % Arena::BLK;
        if (in_block + count > Arena::BLK) off += Arena::BLK - in_block;
        while (off + count > a.cap()) {
            a.blocks.push_back(sycl::malloc_device<float>(Arena::BLK, q));
        }
        a.used = off + count;
        return a.blocks[off / Arena::BLK] + (off % Arena::BLK);
    }

    // Only valid right after a queue drain: every kernel that touched these slots has finished.
    void arena_reset(sycl::queue& q) {
        Arena& a = g_arena[(const void*) &q];
        a.used = 0;
        for (float* p : a.oversized) sycl::free(p, q);
        a.oversized.clear();
    }

    struct Dev {
        float* p = nullptr;
        size_t n = 0;
        sycl::queue* q = nullptr;
        bool owned = false;    // true: this object allocated its own memory and must free it
        Dev() = default;
        explicit Dev(sycl::queue* qq, size_t count, bool own = false) : q(qq), owned(own) {
            n = count;
            if (count == 0) return;
            p = own ? sycl::malloc_device<float>(count, *qq) : arena_acquire(*qq, count);
            // No zeroing, deliberately. Every buffer built here is immediately and fully
            // written by the kernel that follows: gemm assigns every in-bounds output element
            // (accumulator starts in registers, not in Y), rmsnorm writes range(T,D), attention
            // writes all HD lanes per (token,head), swiglu writes DD, and the embedding rows
            // are memcpy'd in full. Two cheaper variants that kept the zeroing were measured
            // and rejected for correctness (see findings F24): q->fill() broke the CPU backend,
            // and an async memcpy without wait changed CPU logits while the argmax stayed right.
            // This is guarded by the cross-backend logit assertion and the window-0 logprob
            // tolerance in the test suite, not by argmax.
        }
        ~Dev() { if (owned && p && q) sycl::free(p, *q); }
        Dev(Dev&& o) noexcept : p(o.p), n(o.n), q(o.q), owned(o.owned) { o.p = nullptr; }
        Dev& operator=(Dev&& o) noexcept {
            if (this != &o) {
                if (owned && p && q) sycl::free(p, *q);
                p = o.p; n = o.n; q = o.q; owned = o.owned; o.p = nullptr;
            }
            return *this;
        }
        Dev(const Dev&) = delete;
        Dev& operator=(const Dev&) = delete;
    };

    bool load_row_embd(sycl::queue& q, int embdIdx, int row, int D, float* dst) {
        const Wt res = resident_handle(embdIdx);
        if (res.p) {  // already on device: a row read, no disk read and no dequant
            copy_row(q, res, (uint64_t) row * (uint64_t) D, dst, D);
            q.wait_and_throw();
            return true;
        }
        std::vector<uint8_t> raw;
        // one row = D contiguous elements; quant blocks are 32-aligned and D % 32 == 0
        const uint32_t dt = (uint32_t) gguf::tensor_dtype(embdIdx);
        const uint64_t blk = (dt == 0) ? 1u : ((dt == 12 || dt == 14) ? 256u : 32u);
        const uint64_t bpr = (uint64_t) D / blk;              // blocks per row
        const uint64_t bytes_per_blk = (dt == 0) ? 4 : (dt == 8 ? 34 : (dt == 6 ? 22 : (dt == 12 ? 144 : 210)));
        const uint64_t off = (uint64_t) row * bpr * bytes_per_blk;
        const uint64_t len = bpr * bytes_per_blk;
        if (!gguf::raw_bytes_range(embdIdx, off, len, raw)) { g_err = "embd row read failed"; return false; }
        float* tmp = dequant_device(q, raw, dt, (uint64_t) D);
        if (!tmp) return false;
        q.memcpy(dst, tmp, sizeof(float) * D);
        q.wait_and_throw();
        sycl::free(tmp, q);
        return true;
    }
}  // namespace

std::string last_error() { return g_err; }

std::string residency_report() {
    char buf[256];
    std::snprintf(buf, sizeof(buf),
                  "residency enabled=%d kept=%d/%d planned=%.2fGiB used=%.2fGiB budget=%.2fGiB "
                  "device=%.2fGiB hot_embedding_kept=%d",
                  g_res.enabled ? 1 : 0,
                  (int) (g_res.keep.size() + g_res.keep_h.size() + g_res.keep_q.size()),
                  g_keep_plan_total,

                  (double) g_keep_plan_bytes / (double) (1ull << 30),
                  (double) g_res.used / (double) (1ull << 30),
                  (double) g_res.budget / (double) (1ull << 30), g_dev_mem_gib,
                  g_keep_plan.find(gguf::find_tensor("token_embd.weight")) != g_keep_plan.end() ? 1 : 0);
    return std::string(buf);
}

// Ask the device what it has, then decide the strategy. Everything reported here is measured
// or derived from the model file rather than assumed: f32 weight footprint from the tensor
// table, KV footprint from the architecture, and the budget residency will actually honour.
std::string plan(int backend, int ctx_tokens) {
    sycl::queue* qp = kakasi::queue_for(backend);
    if (!qp) return "plan: backend unavailable";
    sycl::queue& q = *qp;
    const std::string name = q.get_device().get_info<sycl::info::device::name>();
    const size_t total = q.get_device().get_info<sycl::info::device::global_mem_size>();
    const double KiB2 = 1024.0 * 1024.0 * 1024.0;

    double wbytes = 0.0;
    const int nt = gguf::tensor_count();
    for (int i = 0; i < nt; ++i) {
        wbytes += (double) gguf::tensor_ne(i, 0) * (double) gguf::tensor_ne(i, 1) * 4.0;
    }
    const int L = (int) gguf::meta_num("qwen2.block_count");
    const int D = (int) gguf::meta_num("qwen2.embedding_length");
    const int NH = (int) gguf::meta_num("qwen2.attention.head_count");
    const int NKV = (int) gguf::meta_num("qwen2.attention.head_count_kv");
    const int hd = NH > 0 ? D / NH : 0;
    const double kvbytes = 2.0 * (double) L * (double) ctx_tokens * (double) (hd * NKV) * 4.0;
    const double budget = g_budget_override_gib > 0.0
        ? g_budget_override_gib * KiB2 : (double) total * 0.55;
    // Residency stores the big tensors as f16, so the footprint that must fit is about half the
    // f32 one (the rest of the bytes are small tensors that stay f32). Report both.
    const double wbytes16 = wbytes * 0.5;
    const bool resident = (wbytes16 + kvbytes) <= budget;

    char buf[512];
    std::snprintf(buf, sizeof(buf),
        "plan b%d dev=%.40s total=%.2fGiB budget=%.2fGiB weights_f32=%.2fGiB weights_f16=%.2fGiB "
        "kv(%d tok)=%.3fGiB strategy=%s resident_now=%.2fGiB",
        backend, name.c_str(), (double) total / KiB2, budget / KiB2, wbytes / KiB2,
        wbytes16 / KiB2, ctx_tokens, kvbytes / KiB2,
        resident ? "weights-resident" : "stream-weights",
        (double) g_res.used / KiB2);
    return std::string(buf);
}

// 0 restores the default policy; >0 pins the residency budget in GiB, which is how a
// low-memory device is simulated on hardware that happens to have plenty.
void set_budget_gib(double gib) {
    g_budget_override_gib = gib < 0.0 ? 0.0 : gib;
    if (g_res.q) { g_res.clear_all(); g_res.q = nullptr; g_res.enabled = false; }
}

// KV precision knob (FR-18): 0 = f32, 1 = f16. Switching drops the cache so the next pass
// rebuilds it at the new width; nothing else in the engine changes.
void set_kv_f16(int on) {
    const bool want = on != 0;
    if (g_kv.f16 == want) return;
    g_kv.free_all();
    g_kv.f16 = want;
}

// Resident big-tensor precision: 0 = f16, 1 = int8 (+ one scale per 64). Switching drops
// residency so the next pass rebuilds it at the new width.
void set_weight_prec(int p) {
    g_wprec = (p == 1) ? 1 : 0;
    if (g_res.q) { g_res.clear_all(); g_res.q = nullptr; g_res.enabled = false; }
}

int weight_prec() { return g_wprec; }

// Backend fingerprint for `engine doctor` (FR-04): what the runtime actually sees, not what the
// build hoped for.
std::string device_fingerprint(int backend) {
    sycl::queue* qp = kakasi::queue_for(backend);
    if (!qp) return std::string("unavailable");
    const sycl::device& d = qp->get_device();
    char buf[512];
    std::snprintf(buf, sizeof(buf), "type=%s vendor=%s driver=%s mem=%.2fGiB",
                  d.is_cpu() ? "cpu" : (d.is_gpu() ? "gpu" : "other"),
                  d.get_info<sycl::info::device::vendor>().c_str(),
                  d.get_info<sycl::info::device::driver_version>().c_str(),
                  (double) d.get_info<sycl::info::device::global_mem_size>()
                      / (1024.0 * 1024.0 * 1024.0));
    return std::string(buf);
}

int kv_f16() { return g_kv.f16 ? 1 : 0; }

// Bytes the live KV cache occupies, both K and V, all layers.
double kv_cache_gib() {
    if (!g_kv.q) return 0.0;
    const double bytes = 2.0 * (double) g_kv.k.size() * (double) g_kv.cap
                       * (double) g_kv.dkv * (double) g_kv.elem();
    return bytes / (double) (1ull << 30);
}

// Layer-range offload (FR-18): layers [layer, L) run on `backend`, the rest on the device the
// caller passes to prefill. Disable with layer < 0. Residency is single-device, so it is
// dropped here and every weight streams on whichever device owns its layer.
void set_split(int backend, int layer) {
    if (layer < 0 || backend < 0) {
        g_split_backend = -1;
        g_split_layer = -1;
    } else {
        g_split_backend = backend;
        g_split_layer = layer;
        g_res.clear_all();
        g_res.q = nullptr;
        g_res.enabled = false;
    }
    g_kv.free_all();
    g_kv2.free_all();
    if (!g_kv.f16 && g_kv2.f16) g_kv.f16 = g_kv2.f16;
}

std::string split_state() {
    if (g_split_layer < 0 || g_split_backend < 0) return "split off";
    char buf[160];
    std::snprintf(buf, sizeof(buf), "split primary=b? secondary=b%d layers=[%d,%d)",
                  g_split_backend, g_split_layer, (int) gguf::meta_num("qwen2.block_count"));
    return std::string(buf);
}

double top_logit() { return g_top; }
double second_logit() { return g_second; }
double expect_logit() { return g_expect_logit; }
double logsumexp() { return g_logsumexp; }

// Probability of one token id under the current logits at the given temperature. This is the
// surface speculative sampling needs (F284 step 1): a deterministic drafter's acceptance test is
// p(draft), and the residual resample needs the same normaliser.
double next_u64_unit(uint64_t& s);   // defined below; declared for rand01/sample_excluding

// Argmax of the current logits WITHOUT advancing the session (F303). Speculative sampling must
// decide accept/reject before it knows which token to commit, so the peek has to be free of side
// effects: it neither prefills nor touches g_sess_len.
int peek_argmax() {
    const int n = (int) g_last_logits.size();
    if (n == 0) return -1;
    int best = 0;
    for (int i = 1; i < n; ++i) if (g_last_logits[(size_t) i] > g_last_logits[(size_t) best]) best = i;
    return best;
}

// Uniform draw in [0,1) from the session RNG - the acceptance draw of speculative sampling
// (F284 step 2). Same splitmix64 source as sampling, so a seed makes runs reproducible.
double rand01() { return next_u64_unit(g_sess_rng); }

// Sample from the temperature-scaled distribution with one id excluded. For a deterministic
// drafter (q is a point mass on `exclude`) this is exactly the residual (p-q)+ resample (F284
// step 3), so acceptance plus this draw is an exact sampler for the target distribution.
int sample_excluding(int exclude, double temp) {
    const int n = (int) g_last_logits.size();
    if (n == 0 || temp <= 0.0) return -1;
    double mx = -1e300;
    for (int i = 0; i < n; ++i) if (i != exclude) mx = std::max(mx, (double) g_last_logits[(size_t) i]);
    if (mx == -1e300) return -1;
    double sum = 0.0;
    for (int i = 0; i < n; ++i) if (i != exclude) sum += std::exp(((double) g_last_logits[(size_t) i] - mx) / temp);
    if (!(sum > 0.0)) return -1;
    double u = next_u64_unit(g_sess_rng) * sum;
    for (int i = 0; i < n; ++i) {
        if (i == exclude) continue;
        u -= std::exp(((double) g_last_logits[(size_t) i] - mx) / temp);
        if (u <= 0.0) return i;
    }
    for (int i = n - 1; i >= 0; --i) if (i != exclude) return i;
    return -1;
}

double token_prob(int id, double temp) {
    const int n = (int) g_last_logits.size();
    if (n == 0 || id < 0 || id >= n) return -1.0;
    if (temp <= 0.0) {
        int best = 0;
        for (int i = 1; i < n; ++i) if (g_last_logits[(size_t) i] > g_last_logits[(size_t) best]) best = i;
        return best == id ? 1.0 : 0.0;
    }
    double mx = -1e300;
    for (int i = 0; i < n; ++i) mx = std::max(mx, (double) g_last_logits[(size_t) i]);
    double sum = 0.0;
    for (int i = 0; i < n; ++i) sum += std::exp(((double) g_last_logits[(size_t) i] - mx) / temp);
    if (!(sum > 0.0)) return -1.0;
    return std::exp(((double) g_last_logits[(size_t) id] - mx) / temp) / sum;
}
// Per-row acceptance statistics for a sampled tree verify (F307). `want` is the drafter's token
// at the position this row predicts; prob_out is p(want) under the temperature-scaled row and
// alt_out is a draw from the residual (p - delta_want)+, which is the exact correction token when
// the draft is rejected. want < 0 means "no draft to correct" -> a plain sample from p, which is
// the bonus token of a fully accepted branch. temp <= 0 is the greedy limit: p is 1 on the argmax
// and 0 elsewhere, and the draw is the argmax, so a sampled tree degenerates into the greedy one.
void row_accept_stats(const std::vector<float>& row, int want, double temp,
                      double& prob_out, int& alt_out) {
    const int n = (int) row.size();
    prob_out = -1.0;
    alt_out = -1;
    if (n == 0) return;
    if (want >= n) { prob_out = 0.0; want = -1; }
    double mx = -1e300;
    for (int i = 0; i < n; ++i) mx = std::max(mx, (double) row[(size_t) i]);
    if (!(temp > 0.0)) {
        int best = 0;
        for (int i = 1; i < n; ++i) if (row[(size_t) i] > row[(size_t) best]) best = i;
        prob_out = (want >= 0 && best == want) ? 1.0 : 0.0;
        alt_out = best;
        return;
    }
    const double inv = 1.0 / temp;
    double pw = 0.0, mass = 0.0;
    for (int i = 0; i < n; ++i) {
        const double e = std::exp(((double) row[(size_t) i] - mx) * inv);
        mass += e;
        if (i == want) pw = e;
    }
    if (!(mass > 0.0)) return;
    prob_out = (want >= 0) ? (pw / mass) : 1.0;
    // Residual walk over p restricted to the ids that are NOT the draft: normalising by
    // mass - p(want) is what makes the re-draw an exact (p-q)+ sample for a point-mass drafter.
    const double res = (want >= 0) ? (mass - pw) : mass;
    if (!(res > 0.0)) {
        int sb = -1;
        double sv = -1e300;
        for (int i = 0; i < n; ++i) {
            if (i == want) continue;
            if ((double) row[(size_t) i] > sv) { sv = (double) row[(size_t) i]; sb = i; }
        }
        alt_out = sb;
        return;
    }
    double u = next_u64_unit(g_sess_rng) * res;
    for (int i = 0; i < n; ++i) {
        if (i == want) continue;
        u -= std::exp(((double) row[(size_t) i] - mx) * inv);
        if (u <= 0.0) { alt_out = i; return; }
    }
    for (int i = n - 1; i >= 0; --i) if (i != want) { alt_out = i; return; }
}

int expect_rank() { return g_expect_rank; }
int trace_count() { return (int) g_trace.size(); }
double trace_at(int i) { return (i >= 0 && i < (int) g_trace.size()) ? g_trace[(size_t) i] : 0.0; }
int stage_count() { return (int) g_stage.size(); }
double stage_at(int i) { return (i >= 0 && i < (int) g_stage.size()) ? g_stage[(size_t) i] : 0.0; }

std::vector<double> prefill_top1(int backend, const std::vector<int>& tokens, int expect_id, int pos = 0);

// Naive autoregressive generation: re-prefill the whole sequence per step.
// O(n^2) in compute, but correct without a KV cache; returns the full id list.
// Wall-clock split of generation, for the performance baseline (prefill vs per-token decode).
double g_gen_prefill_ms = 0.0;
double g_gen_step_ms = 0.0;
double gen_prefill_ms() { return g_gen_prefill_ms; }
double gen_step_ms() { return g_gen_step_ms; }

// Greedy generation with a KV cache: the prompt is prefilled once, then each step
// processes exactly one token and stops early on the model's eos_token_id.
std::vector<int> generate(int backend, std::vector<int> ids, int n_new) {
    std::vector<int> out = ids;
    g_gen_prefill_ms = 0.0;
    g_gen_step_ms = 0.0;
    const int eos = (int) gguf::meta_num("tokenizer.ggml.eos_token_id");
    // A fresh generation must not inherit the stop token from an earlier call.
    g_expect_id = -1;
    g_expect_rank = -1;

    const auto t0 = std::chrono::steady_clock::now();
    std::vector<double> r = prefill_top1(backend, out, -1, 0);
    g_gen_prefill_ms = std::chrono::duration<double, std::milli>(std::chrono::steady_clock::now() - t0).count();
    if (r.empty()) return {};

    for (int step = 0; step < n_new; ++step) {
        const int next = (int) r[0];
        out.push_back(next);
        if (eos > 0 && next == eos) break;
        const int pos = (int) out.size() - 1;
        const auto t1 = std::chrono::steady_clock::now();
        r = prefill_top1(backend, std::vector<int>{next}, -1, pos);
        g_gen_step_ms += std::chrono::duration<double, std::milli>(std::chrono::steady_clock::now() - t1).count();
        if (r.empty()) break;
    }
    return out;
}

// splitmix64: one 64-bit state, reproducible from a seed, and no dependency on the
// platform's rand() (which differs across the backends this engine is built for).
double next_u64_unit(uint64_t& s) {
    s += 0x9E3779B97F4A7C15ull;
    uint64_t z = s;
    z = (z ^ (z >> 30)) * 0xBF58476D1CE4E5B9ull;
    z = (z ^ (z >> 27)) * 0x94D049BB133111EBull;
    z = z ^ (z >> 31);
    return (double) (z >> 11) * (1.0 / 9007199254740992.0);
}

// Nucleus (top-p) sampling over g_last_logits. temp <= 0 is greedy, so a temperature of 0
// must reproduce generate() exactly. Returns the token id, or -1 when there is nothing to
// sample from. nucleus_out reports how many tokens survived the cut.
int sample_next(double temp, double top_p, uint64_t& rng, int* nucleus_out) {
    const int n = (int) g_last_logits.size();
    if (n == 0) { g_err = "sample requested with no logits"; return -1; }
    int argmax = 0;
    float mx = g_last_logits[0];
    for (int i = 1; i < n; ++i) {
        if (g_last_logits[i] > mx) { mx = g_last_logits[i]; argmax = i; }
    }
    if (temp <= 0.0) {
        if (nucleus_out) *nucleus_out = 1;
        return argmax;
    }
    std::vector<double> p((size_t) n);
    double z = 0.0;
    for (int i = 0; i < n; ++i) {
        const double v = std::exp(((double) g_last_logits[i] - (double) mx) / temp);
        p[(size_t) i] = v;
        z += v;
    }
    if (!(z > 0.0)) { if (nucleus_out) *nucleus_out = 1; return argmax; }
    const double inv_z = 1.0 / z;

    std::vector<int> order((size_t) n);
    for (int i = 0; i < n; ++i) order[(size_t) i] = i;
    std::sort(order.begin(), order.end(),
              [&](int a, int b) { return p[(size_t) a] > p[(size_t) b]; });

    // Keep tokens until the cumulative probability reaches top_p; the token that crosses
    // the threshold stays in, which is what llama.cpp's top-p does too.
    const double target = (top_p > 0.0 && top_p < 1.0) ? top_p : 1.0;
    int keep = 0;
    double cum = 0.0;
    for (int i = 0; i < n; ++i) {
        cum += p[(size_t) order[(size_t) i]] * inv_z;
        ++keep;
        if (cum >= target) break;
    }
    if (nucleus_out) *nucleus_out = keep;

    const double u = next_u64_unit(rng) * cum;
    double acc = 0.0;
    for (int i = 0; i < keep; ++i) {
        acc += p[(size_t) order[(size_t) i]] * inv_z;
        if (u < acc) return order[(size_t) i];
    }
    return order[(size_t) keep - 1];
}

// Generation with temperature + top-p. temp<=0 with top_p<=0 degenerates to greedy.
std::vector<int> generate_sampled(int backend, std::vector<int> ids, int n_new,
                                  double temp, double top_p, uint64_t seed) {
    std::vector<int> out = ids;
    g_nucleus = 0;
    const int eos = (int) gguf::meta_num("tokenizer.ggml.eos_token_id");
    g_expect_id = -1;
    g_expect_rank = -1;

    std::vector<double> r = prefill_top1(backend, out, -1, 0);
    if (r.empty()) return {};
    uint64_t rng = seed;
    for (int step = 0; step < n_new; ++step) {
        int nk = 0;
        const int next = sample_next(temp, top_p, rng, &nk);
        if (step == 0) g_nucleus = nk;   // the first sampled token is the one worth reporting
        if (next < 0) break;
        out.push_back(next);
        if (eos > 0 && next == eos) break;
        const int pos = (int) out.size() - 1;
        r = prefill_top1(backend, std::vector<int>{next}, -1, pos);
        if (r.empty()) break;
    }
    return out;
}

int nucleus_size() { return g_nucleus; }

// Runs the model over `tokens`, which occupy cache positions [pos, pos+T), and returns
// {top1_id, top1_logit, top2_logit} for the last position.
std::vector<double> prefill_top1(int backend, const std::vector<int>& tokens, int expect_id, int pos) {
    g_err.clear();
    g_trace.clear();
    g_last_logits.clear();
    g_stage.clear();
    auto t_stage = std::chrono::steady_clock::now();
    auto mark_stage = [&]() {
        const double ms = std::chrono::duration<double, std::milli>(
            std::chrono::steady_clock::now() - t_stage).count();
        t_stage = std::chrono::steady_clock::now();
        g_stage.push_back(ms);
    };
    if (!gguf::loaded()) { g_err = "model not loaded"; return {}; }
    sycl::queue* qp = kakasi::queue_for(backend);
    if (!qp) { g_err = "backend unavailable"; return {}; }
    sycl::queue& q = *qp;
    residency_prepare(q, backend);

    const int D = (int) gguf::meta_num("qwen2.embedding_length");
    const int L = (int) gguf::meta_num("qwen2.block_count");
    const int NH = (int) gguf::meta_num("qwen2.attention.head_count");
    const int NKV = (int) gguf::meta_num("qwen2.attention.head_count_kv");
    const int FFN = (int) gguf::meta_num("qwen2.feed_forward_length");
    double theta = gguf::meta_num("qwen2.rope.freq_base");
    double epsd = gguf::meta_num("qwen2.attention.layer_norm_rms_epsilon");
    const float eps = (epsd > 0.0) ? (float) epsd : 1e-5f;
    const int HD = D / NH;
    const int DKV = NKV * HD;
    const int T = (int) tokens.size();
    if (D <= 0 || L <= 0 || NH <= 0 || NKV <= 0 || HD <= 0 || T <= 0 || pos < 0) {
        g_err = "bad model dims or token count";
        return {};
    }
    if (theta <= 0.0) theta = 1000000.0;
    const int eIdx = gguf::find_tensor("token_embd.weight");
    if (eIdx < 0 || HD > 128) { g_err = "no token_embd.weight or head too wide"; return {}; }

    // (Re)allocate the cache when the backend changes or the sequence outgrows it.
    if (!ensure_kv(backend, q, pos + T, DKV, L, pos)) return {};

    try {
        // Long-lived: live across every layer, so they own their memory instead of using the
        // per-layer arena that gets reset.
        Dev x(&q, (size_t) T * D, true), h(&q, (size_t) T * D, true);
        auto probe = [&]() {
            std::vector<float> px((size_t) T * D);
            q.memcpy(px.data(), x.p, sizeof(float) * px.size());
            q.wait_and_throw();
            double mx = 0.0;
            for (float v : px) mx = std::max(mx, (double) std::fabs(v));
            g_trace.push_back(mx);
        };
        // Pull the tied embedding into residency once, so both row lookups and lm_head
        // chunks become device copies rather than per-call disk reads plus dequantization.
        {
            const uint64_t emb_n = (uint64_t) D * (uint64_t) gguf::tensor_ne(eIdx, 1);
            Wt e0 = load_weight_idx(q, eIdx, emb_n);
            if (!e0.p) return {};
            weight_release(e0);  // no-op while it stays resident
        }
        // embedding table rows
        for (int t = 0; t < T; ++t) {
            if (!load_row_embd(q, eIdx, tokens[t], D, x.p + (size_t) t * D)) return {};
        }
        probe();

        // rope tables, positioned at [pos, pos+T)
        const int half = HD / 2;
        std::vector<float> ctab((size_t) T * half), stab((size_t) T * half);
        for (int t = 0; t < T; ++t) {
            const int rt = (!g_row_pos.empty() && t < (int) g_row_pos.size()) ? g_row_pos[(size_t) t] : t;
            for (int j = 0; j < half; ++j) {
                const float freq = (float) std::pow(theta, -(double) (2 * j) / (double) HD);
                const float ang = (float) (pos + rt) * freq;
                ctab[(size_t) t * half + j] = std::cos(ang);
                stab[(size_t) t * half + j] = std::sin(ang);
            }
        }
        float* dCos = sycl::malloc_device<float>(ctab.size(), q);
        float* dSin = sycl::malloc_device<float>(stab.size(), q);
        q.memcpy(dCos, ctab.data(), sizeof(float) * ctab.size());
        q.memcpy(dSin, stab.data(), sizeof(float) * stab.size());
        q.wait_and_throw();

        const float scale = 1.0f / sycl::sqrt((float) HD);
        // Stage boundaries are synchronized so each stage measures its own device work;
        // the instrumented run is therefore slower than steady state, by design.
        q.wait_and_throw();
        mark_stage();  // stage 0: embedding, rope tables, residency warm

        // One transformer layer on a chosen device. `xp` is the running hidden state, `hp` the
        // per-layer scratch, and `kv` the cache that lives on that same device - all of the
        // second device's state is separate, which is what makes the split sound.
        auto run_layer = [&](sycl::queue& ql, float* xp, float* hp, KVCache& kv,
                             const float* cosp, const float* sinp, int l, bool trace) -> bool {
            const std::string p = "blk." + std::to_string(l) + ".";
            Wt anw = load_weight(ql, p + "attn_norm.weight", (uint64_t) D);
            if (!anw.p) return false;
            rmsnorm(ql, xp, T, D, (const float*) anw.p, eps, hp);   // norm weights are small => f32
            weight_release(anw);

            // attention
            Wt wq = load_weight(ql, p + "attn_q.weight", (uint64_t) D * D);
            if (!wq.p) return false;
            Wt bq = load_weight(ql, p + "attn_q.bias", (uint64_t) D);
            if (!bq.p) { weight_release(wq); return false; }
            Dev att(&ql, (size_t) T * D);
            gemm(ql, hp, T, D, wq, D, att.p, (const float*) bq.p);
            weight_release(wq); weight_release(bq);

            Wt wk = load_weight(ql, p + "attn_k.weight", (uint64_t) DKV * D);
            if (!wk.p) return false;
            Wt bk = load_weight(ql, p + "attn_k.bias", (uint64_t) DKV);
            if (!bk.p) { weight_release(wk); return false; }
            // Rotate in f32, then store once at the cache's precision: applying rope in place
            // on a narrowed cache would quantize twice and lose the rotation's low bits.
            Dev kt(&ql, (size_t) T * DKV);
            gemm(ql, hp, T, D, wk, DKV, kt.p, (const float*) bk.p);
            weight_release(wk); weight_release(bk);

            Wt wv = load_weight(ql, p + "attn_v.weight", (uint64_t) DKV * D);
            if (!wv.p) return false;
            Wt bv = load_weight(ql, p + "attn_v.bias", (uint64_t) DKV);
            if (!bv.p) { weight_release(wv); return false; }
            Dev vt(&ql, (size_t) T * DKV);
            gemm(ql, hp, T, D, wv, DKV, vt.p, (const float*) bv.p);
            weight_release(wv); weight_release(bv);

            rope(ql, att.p, T, NH, HD, cosp, sinp);
            rope(ql, kt.p, T, NKV, HD, cosp, sinp);

            if (kv.f16) {
                kv_store(ql, kt.p, (sycl::half*) kv.k[(size_t) l], T, DKV, pos);
                kv_store(ql, vt.p, (sycl::half*) kv.v[(size_t) l], T, DKV, pos);
            } else {
                kv_store(ql, kt.p, (float*) kv.k[(size_t) l], T, DKV, pos);
                kv_store(ql, vt.p, (float*) kv.v[(size_t) l], T, DKV, pos);
            }

            Dev ao(&ql, (size_t) T * D);
            if (kv.f16) {
                attention(ql, att.p, (const sycl::half*) kv.k[(size_t) l],
                          (const sycl::half*) kv.v[(size_t) l], T, pos, NH, NKV, HD, scale, ao.p,
                          g_tree_mask);
            } else {
                attention(ql, att.p, (const float*) kv.k[(size_t) l],
                          (const float*) kv.v[(size_t) l], T, pos, NH, NKV, HD, scale, ao.p,
                          g_tree_mask);
            }

            Wt wo = load_weight(ql, p + "attn_output.weight", (uint64_t) D * D);
            if (!wo.p) return false;
            Dev oo(&ql, (size_t) T * D);
            gemm(ql, ao.p, T, D, wo, D, oo.p, nullptr);
            weight_release(wo);
            add_inplace(ql, xp, oo.p, (size_t) T * D);

            // ffn
            Wt fnw = load_weight(ql, p + "ffn_norm.weight", (uint64_t) D);
            if (!fnw.p) return false;
            rmsnorm(ql, xp, T, D, (const float*) fnw.p, eps, hp);
            weight_release(fnw);
            Wt wg = load_weight(ql, p + "ffn_gate.weight", (uint64_t) FFN * D);
            if (!wg.p) return false;
            Dev g(&ql, (size_t) T * FFN);
            gemm(ql, hp, T, D, wg, FFN, g.p, nullptr);
            weight_release(wg);

            Wt wu = load_weight(ql, p + "ffn_up.weight", (uint64_t) FFN * D);
            if (!wu.p) return false;
            Dev u(&ql, (size_t) T * FFN);
            gemm(ql, hp, T, D, wu, FFN, u.p, nullptr);
            weight_release(wu);

            silu_mul(ql, g.p, u.p, (size_t) T * FFN, g.p);

            Wt wd = load_weight(ql, p + "ffn_down.weight", (uint64_t) D * FFN);
            if (!wd.p) return false;
            Dev dd(&ql, (size_t) T * D);

            gemm(ql, g.p, T, FFN, wd, D, dd.p, nullptr);
            weight_release(wd);
            add_inplace(ql, xp, dd.p, (size_t) T * D);
            if (trace) probe();   // per-layer |x| trace only on the primary device
            return true;
        };

        // Layer-range offload: [0, splitAt) on the primary device, [splitAt, L) on the second.
        // The hidden state crosses through host memory once, and once more at the end for the
        // final norm and lm_head - a few hundred KB per step, the price of using both devices.
        const int splitAt = (g_split_layer >= 0 && g_split_backend >= 0) ? g_split_layer : L;
        sycl::queue* q2p = (splitAt < L) ? kakasi::queue_for(g_split_backend) : nullptr;
        if (splitAt < L && !q2p) { g_err = "split backend unavailable"; return {}; }
        const bool splitActive = (splitAt < L && q2p);
        sycl::queue& q2 = splitActive ? *q2p : q;
        Dev xs(&q2, (size_t) T * D, true), hs(&q2, (size_t) T * D, true);
        std::vector<float> handoff((size_t) T * D);
        // The second device needs its own copies of everything the layers touch: the rope
        // tables and the KV rows. A pointer allocated on one device must never be dereferenced
        // by the other.
        float* dCos2 = nullptr;
        float* dSin2 = nullptr;
        if (splitActive) {
            dCos2 = sycl::malloc_device<float>(ctab.size(), q2);
            dSin2 = sycl::malloc_device<float>(stab.size(), q2);
            q2.memcpy(dCos2, ctab.data(), sizeof(float) * ctab.size());
            q2.memcpy(dSin2, stab.data(), sizeof(float) * stab.size());
            q2.wait_and_throw();
            if (g_kv2.backend != g_split_backend || g_kv2.cap < pos + T) {
                // Same hazard as ensure_kv (F265): freeing first would discard the rows the
                // second device already holds, and attention over them would read garbage.
                const int keep2 = (g_kv2.backend == g_split_backend && (int) g_kv2.k.size() == L)
                                  ? std::min(pos, g_kv2.cap) : 0;
                const int cap2 = std::max(pos + T, KV_MIN_CAP);
                const size_t b2 = (size_t) cap2 * (size_t) DKV * g_kv2.elem();
                std::vector<char*> nk2;
                std::vector<char*> nv2;
                for (int l = 0; l < L; ++l) {
                    nk2.push_back(sycl::malloc_device<char>(b2, q2));
                    nv2.push_back(sycl::malloc_device<char>(b2, q2));
                }
                if (keep2 > 0) {
                    const size_t kb = (size_t) keep2 * (size_t) DKV * g_kv2.elem();
                    for (int l = 0; l < L; ++l) {
                        q2.memcpy(nk2[l], g_kv2.k[l], kb);
                        q2.memcpy(nv2[l], g_kv2.v[l], kb);
                    }
                    q2.wait_and_throw();
                }
                g_kv2.free_all();
                g_kv2.q = &q2; g_kv2.backend = g_split_backend; g_kv2.cap = cap2;
                g_kv2.dkv = DKV; g_kv2.len = keep2; g_kv2.f16 = g_kv.f16;
                for (int l = 0; l < L; ++l) {
                    g_kv2.k.push_back(nk2[l]);
                    g_kv2.v.push_back(nv2[l]);
                }
            }
        }

        // Slots from the previous pass's lm_head loop are finished (that loop waits on every
        // chunk), so the bump pointer can start clean.
        arena_reset(q);
        if (splitActive) arena_reset(q2);

        for (int l = 0; l < L; ++l) {
            const bool on2 = splitActive && l >= splitAt;
            if (on2 && l == splitAt) {
                q.memcpy(handoff.data(), x.p, sizeof(float) * handoff.size());
                q.wait_and_throw();
                q2.memcpy(xs.p, handoff.data(), sizeof(float) * handoff.size());
                q2.wait_and_throw();
            }
            if (!run_layer(on2 ? q2 : q, on2 ? xs.p : x.p, on2 ? hs.p : h.p,
                           on2 ? g_kv2 : g_kv,
                           on2 ? dCos2 : dCos, on2 ? dSin2 : dSin, l, !on2)) {
                return {};
            }
            // One drain per layer bounds the arena reset: everything this layer queued has run,
            // so its slots may be handed out again. This is the point of the arena - drain count
            // falls from ~15 per layer to 1 - while no slot is ever reused while still being read.
            q.wait_and_throw();
            arena_reset(q);
            if (on2) {
                q2.wait_and_throw();
                arena_reset(q2);
            }
        }
        if (splitActive) {
            q2.memcpy(handoff.data(), xs.p, sizeof(float) * handoff.size());
            q2.wait_and_throw();
            q.memcpy(x.p, handoff.data(), sizeof(float) * handoff.size());
            q.wait_and_throw();
            sycl::free(dCos2, q2);
            sycl::free(dSin2, q2);
        }

        sycl::free(dCos, q);
        sycl::free(dSin, q);
        q.wait_and_throw();
        mark_stage();  // stage 1: the 24 transformer blocks

        // Final norm + tied lm_head. Normally only the last row is needed (sampling). When the
        // speculative verifier asks for it, the head also reports the argmax at each of the last
        // g_head_rows positions, which is what makes a batched accept/reject decision possible.
        const int nOut = std::max(1, std::min(g_head_rows, T));
        g_step_top1.clear();
        Dev xn(&q, (size_t) D * (size_t) nOut, true);
        {
            Wt nw = load_weight(q, "output_norm.weight", (uint64_t) D);
            if (!nw.p) return {};
            rmsnorm(q, x.p + (size_t) (T - nOut) * D, nOut, D, (const float*) nw.p, eps, xn.p);
            weight_release(nw);
        }

        // tied lm_head in row chunks: logits[i] = xn . embd_row[i]. Every chunk writes into one
        // device buffer and the whole vector is read back ONCE - a per-chunk readback cost a
        // queue round trip each (38 of them) and was 11 ms of a 50 ms decode step.
        const int rows = (int) gguf::tensor_ne(eIdx, 1);
        const int chunk = 4096;
        const Wt embH = resident_handle(eIdx);
        Dev dLogits(&q, (size_t) rows);
        std::vector<float> all;
        double best = -1e300, second = -1e300;
        int bestId = -1;
        for (int r = 0; r < nOut; ++r) {
            for (int r0 = 0; r0 < rows; r0 += chunk) {
                const int nrows = std::min(chunk, rows - r0);
                Wt dW;
                if (embH.p) {
                    // Slice the resident table at this chunk. D is a multiple of 64, so a chunk
                    // boundary is also an int8 block boundary and the scale slice stays aligned.
                    const size_t off = (size_t) r0 * (size_t) D;
                    if (embH.kind == 0) dW = {(const float*) embH.p + off, nullptr, 0};
                    else if (embH.kind == 1) dW = {(const sycl::half*) embH.p + off, nullptr, 1};
                    else dW = {(const int8_t*) embH.p + off, embH.s + (off >> 6), 2};
                }
                float* dStream = nullptr;
                if (!dW.p) {
                    std::vector<uint8_t> raw;
                    const uint32_t dt = (uint32_t) gguf::tensor_dtype(eIdx);
                    const uint64_t blk = (dt == 0) ? 1u : ((dt == 12 || dt == 14) ? 256u : 32u);
                    const uint64_t bytes_per_blk = (dt == 0) ? 4 : (dt == 8 ? 34 : (dt == 6 ? 22 : (dt == 12 ? 144 : 210)));
                    const uint64_t off = (uint64_t) r0 * ((uint64_t) D / blk) * bytes_per_blk;
                    const uint64_t len = (uint64_t) nrows * ((uint64_t) D / blk) * bytes_per_blk;
                    if (!gguf::raw_bytes_range(eIdx, off, len, raw)) { g_err = "lm_head chunk read failed"; return {}; }
                    dStream = dequant_device(q, raw, dt, (uint64_t) nrows * D);
                    if (!dStream) return {};
                    dW = {dStream, false};
                }
                gemm(q, xn.p + (size_t) r * D, 1, D, dW, nrows, dLogits.p + (size_t) r0, nullptr);
                if (dStream) { q.wait_and_throw(); sycl::free(dStream, q); }
            }
            std::vector<float> row((size_t) rows);
            q.memcpy(row.data(), dLogits.p, sizeof(float) * row.size());
            q.wait_and_throw();
            double rbest = -1e300, rsecond = -1e300;
            int rbestId = -1;
            for (int i = 0; i < rows; ++i) {
                const double v = row[(size_t) i];
                if (v > rbest) { rsecond = rbest; rbest = v; rbestId = i; }
                else if (v > rsecond) rsecond = v;
            }
            if (!g_head_want.empty() && r < (int) g_head_want.size()) {
                double hp = -1.0;
                int ha = -1;
                row_accept_stats(row, g_head_want[(size_t) r], g_head_temp, hp, ha);
                g_head_prob.push_back(hp);
                g_head_alt.push_back(ha);
            }
            if (r == nOut - 1) {
                best = rbest; second = rsecond; bestId = rbestId;
                all = std::move(row);      // sampling and the expect_id probe use this row
            } else {
                g_step_top1.push_back(rbestId);
            }
        }
        if (expect_id >= 0 && expect_id < (int) all.size()) {
            g_expect_logit = (double) all[(size_t) expect_id];
            int rank = 1;
            double mx = -1e300;
            for (float v : all) mx = std::max(mx, (double) v);
            double sum = 0.0;
            for (float v : all) sum += std::exp((double) v - mx);
            g_logsumexp = mx + std::log(sum);
            for (float v : all) if ((double) v > g_expect_logit) ++rank;
            g_expect_rank = rank;
            g_expect_id = expect_id;
        }
        if (bestId < 0) { g_err = "no logits computed"; return {}; }
        g_top = best;
        g_second = second;
        mark_stage();  // stage 2: final norm + tied lm_head chunks + host logit reduce
        g_last_logits = std::move(all);
        return {(double) bestId, best, second};
    } catch (const std::exception& e) {
        g_err = std::string("forward exception: ") + e.what();
        return {};
    }
}

// ---- SSD prefix cache -----------------------------------------------------------------------
// The KV rows of a prompt prefix are the expensive part of a long-context request, so they are
// dumped next to the binary and reloaded when the next request shares that prefix.
bool cache_store(int backend, const std::vector<int>& ids, const std::string& path) {
    g_err.clear();
    if (g_kv.backend != backend || ids.empty() || (int) ids.size() > g_kv.cap) {
        g_err = "cache_store: nothing to save (prefill first)";
        return false;
    }
    sycl::queue* qp = kakasi::queue_for(backend);
    if (!qp) { g_err = "cache_store: backend unavailable"; return false; }
    sycl::queue& q = *qp;
    const int n = (int) ids.size();
    const int L = (int) gguf::meta_num("qwen2.block_count");
    const uint32_t ver = 1;
    const uint32_t elem = (uint32_t) g_kv.elem();
    int dkv = g_kv.dkv;
    std::ofstream f(path, std::ios::binary);
    if (!f) { g_err = "cache_store: cannot open " + path; return false; }
    f.write("KVPC1", 5);
    f.write((const char*) &ver, 4);
    f.write((const char*) &L, 4);
    f.write((const char*) &n, 4);
    f.write((const char*) &dkv, 4);
    f.write((const char*) &elem, 4);
    f.write((const char*) ids.data(), sizeof(int) * (size_t) n);
    std::vector<char> buf((size_t) dkv * g_kv.elem() * (size_t) n);
    for (int l = 0; l < L; ++l) {
        for (int pass = 0; pass < 2; ++pass) {
            void* src = pass == 0 ? g_kv.k[(size_t) l] : g_kv.v[(size_t) l];
            q.memcpy(buf.data(), src, buf.size());
            q.wait_and_throw();
            f.write(buf.data(), (std::streamsize) buf.size());
        }
    }
    return f.good();
}

// Prefills `ids` reusing as much of the stored prefix as matches. Returns the reused prefix
// length, or -1 on failure.
int cache_prefill(int backend, const std::vector<int>& ids, const std::string& path) {
    g_err.clear();
    sycl::queue* qp = kakasi::queue_for(backend);
    if (!qp) { g_err = "cache_prefill: backend unavailable"; return -1; }
    sycl::queue& q = *qp;
    const int L = (int) gguf::meta_num("qwen2.block_count");
    const int D = (int) gguf::meta_num("qwen2.embedding_length");
    const int NH = (int) gguf::meta_num("qwen2.attention.head_count");
    const int NKV = (int) gguf::meta_num("qwen2.attention.head_count_kv");
    const int DKV = (NH > 0 ? D / NH : 0) * NKV;

    int prefix = 0;
    std::ifstream f(path, std::ios::binary);
    if (f) {
        char magic[5] = {0};
        uint32_t ver = 0, elem = 0;
        int nl = 0, n = 0, dkv = 0;
        f.read(magic, 5);
        f.read((char*) &ver, 4);
        f.read((char*) &nl, 4);
        f.read((char*) &n, 4);
        f.read((char*) &dkv, 4);
        f.read((char*) &elem, 4);
        // A cache written by a different model, KV precision or layout is simply ignored.
        if (f && ver == 1 && nl == L && dkv == DKV && n > 0 && elem == (uint32_t) g_kv.elem()) {
            std::vector<int> saved((size_t) n);
            f.read((char*) saved.data(), sizeof(int) * (size_t) n);
            if (f) {
                while (prefix < n && prefix < (int) ids.size()
                       && saved[(size_t) prefix] == ids[(size_t) prefix]) {
                    ++prefix;
                }
                if (prefix > 0) {
                    ensure_kv(backend, q, (int) ids.size(), DKV, L);
                    const size_t rowBytes = (size_t) DKV * g_kv.elem();
                    const std::streamoff skipRows = (std::streamoff) (n - prefix) * (std::streamoff) rowBytes;
                    std::vector<char> buf(rowBytes * (size_t) prefix);
                    for (int l = 0; l < L && prefix > 0; ++l) {
                        for (int pass = 0; pass < 2; ++pass) {
                            f.read(buf.data(), (std::streamsize) buf.size());
                            if (!f) { prefix = 0; break; }
                            void* dst = pass == 0 ? g_kv.k[(size_t) l] : g_kv.v[(size_t) l];
                            q.memcpy(dst, buf.data(), buf.size());
                            q.wait_and_throw();
                            // Each layer-pass block holds `n` rows; only the first `prefix`
                            // were reused, so jump over the rest before the next block.
                            if (skipRows > 0) f.seekg(skipRows, std::ios::cur);
                        }
                    }
                    if (prefix > 0) g_kv.len = prefix;
                }
            }
        }
    }

    if (prefix < (int) ids.size()) {
        std::vector<int> suffix(ids.begin() + prefix, ids.end());
        const std::vector<double> r = prefill_top1(backend, suffix, -1, prefix);
        if (r.empty()) return -1;
    } else if (prefix > 0) {
        // Full hit: sampling still needs logits, so re-run the last token over its own row.
        const std::vector<double> r = prefill_top1(backend, std::vector<int>{ids.back()}, -1, prefix - 1);
        if (r.empty()) return -1;
    }
    // Leave the session positioned after these tokens, so a caller can continue with
    // session_next() straight away - that pairing is what makes a cache hit useful.
    g_sess_len = (int) ids.size();
    g_sess_rng = 12345;
    return prefix;
}

// Streaming session: the prompt is prefilled once, then every session_next() call appends
// exactly one token to the KV cache and returns it. Same loop generate() runs, exposed so the
// API can emit a token as soon as it exists instead of waiting for the whole answer.
int session_start(int backend, const std::vector<int>& ids) {
    g_err.clear();
    g_sess_len = 0;
    g_sess_rng = 12345;
    if (ids.empty()) { g_err = "empty prompt"; return -1; }
    const std::vector<double> r = prefill_top1(backend, ids, -1, 0);
    if (r.empty()) return -1;
    g_sess_len = (int) ids.size();
    return 0;
}

// Sampling seed for this session (session_start resets it to 12345); lets the API make
// streamed sampling reproducible exactly like the non-streaming path.
void session_seed(uint64_t seed) {
    g_sess_rng = seed;
}

// Returns the next token id, or -1 at end of stream (eos or failure).
int session_next(int backend, double temp, double top_p) {    int nk = 0;
    const int next = sample_next(temp, top_p, g_sess_rng, &nk);
    g_nucleus = nk;
    if (next < 0) return -1;
    const int eos = (int) gguf::meta_num("tokenizer.ggml.eos_token_id");
    if (eos > 0 && next == eos) return -1;
    const std::vector<double> r = prefill_top1(backend, std::vector<int>{next}, -1, g_sess_len);
    g_sess_len += 1;
    if (r.empty()) return -1;
    return next;
}

// Constrained step: greedy argmax restricted to an allowed id set. This is real constrained
// decoding for the cases where the allowed continuation set is known ahead of time (enum /
// const / a pre-tokenized pattern), because the forbidden tokens simply cannot be chosen.
int session_next_allowed(int backend, const std::vector<int>& allowed) {
    if (g_last_logits.empty() || allowed.empty()) { g_err = "constrained step: no logits/set"; return -1; }
    int best = -1;
    double bestv = -1e300;
    for (size_t i = 0; i < allowed.size(); ++i) {
        const int id = allowed[i];
        if (id < 0 || id >= (int) g_last_logits.size()) continue;
        const double v = (double) g_last_logits[(size_t) id];
        if (v > bestv) { bestv = v; best = id; }
    }
    if (best < 0) { g_err = "constrained step: no allowed id is in range"; return -1; }
    const int eos = (int) gguf::meta_num("tokenizer.ggml.eos_token_id");
    if (eos > 0 && best == eos) return -1;
    const std::vector<double> r = prefill_top1(backend, std::vector<int>{best}, -1, g_sess_len);
    if (r.empty()) return -1;
    // Only a step that actually produced KV rows may advance the position: incrementing first
    // would leave a phantom row that every later write and every KV snapshot inherits.
    g_sess_len += 1;
    return best;
}

int session_len() { return g_sess_len; }

// Host-side KV snapshots: two conversation slots can be parked and resumed exactly, which is the
// primitive a cooperative scheduler needs (the HTTP loop does not use it yet). The logits and the
// sampler state travel with the KV: resuming must continue the same distribution, not just the
// same rows.
struct KvSnap {
    std::vector<std::vector<char>> kv;   // per layer: K then V
    std::vector<float> logits;
    int len = 0;
    int backend = -1;
    int dkv = 0;
    bool f16 = false;
    uint64_t rng = 0;
};
KvSnap g_snap[2];

bool session_save(int slot) {
    // The row count that exists is the session position, not g_kv.len (only the cache loader
    // maintains that field; the normal prefill path leaves it at 0).
    const int used = g_sess_len;
    if (slot < 0 || slot > 1 || g_kv.backend < 0 || used <= 0 || used > g_kv.cap) return false;
    sycl::queue* qp = kakasi::queue_for(g_kv.backend);
    if (!qp) return false;
    const int L = (int) gguf::meta_num("qwen2.block_count");
    if (L <= 0 || (int) g_kv.k.size() < L) return false;
    const size_t per = (size_t) used * (size_t) g_kv.dkv * g_kv.elem();
    KvSnap& s = g_snap[slot];
    s.kv.assign((size_t) (L * 2), std::vector<char>(per));
    for (int l = 0; l < L; ++l) {
        qp->memcpy(s.kv[(size_t) (l * 2)].data(), g_kv.k[(size_t) l], per);
        qp->memcpy(s.kv[(size_t) (l * 2 + 1)].data(), g_kv.v[(size_t) l], per);
    }
    qp->wait_and_throw();
    s.logits = g_last_logits;
    s.len = used;
    s.backend = g_kv.backend;
    s.dkv = g_kv.dkv;
    s.f16 = g_kv.f16;
    s.rng = g_sess_rng;
    return true;
}

bool session_restore(int slot) {
    if (slot < 0 || slot > 1) return false;
    KvSnap& s = g_snap[slot];
    if (s.backend < 0 || s.len <= 0) return false;
    sycl::queue* qp = kakasi::queue_for(s.backend);
    if (!qp) return false;
    const int L = (int) gguf::meta_num("qwen2.block_count");
    if (!ensure_kv(s.backend, *qp, s.len, s.dkv, L)) return false;
    if ((int) g_kv.k.size() < L || g_kv.dkv != s.dkv || g_kv.f16 != s.f16) return false;
    for (int l = 0; l < L; ++l) {
        const std::vector<char>& kb = s.kv[(size_t) (l * 2)];
        const std::vector<char>& vb = s.kv[(size_t) (l * 2 + 1)];
        qp->memcpy(g_kv.k[(size_t) l], kb.data(), kb.size());
        qp->memcpy(g_kv.v[(size_t) l], vb.data(), vb.size());
    }
    qp->wait_and_throw();
    g_kv.len = s.len;
    g_sess_len = s.len;
    g_last_logits = s.logits;
    g_sess_rng = s.rng;
    return true;
}

// The model's end-of-stream token, exposed so callers need not hard-code it.
int eos_id() { return (int) gguf::meta_num("tokenizer.ggml.eos_token_id"); }

// Top-1 at each of the T positions of a batch: entry i is the model's pick for the token after
// position i, so a k-token draft yields k predictions (the last one is the "bonus" token).
std::vector<int> prefill_tops(int backend, const std::vector<int>& tokens, int pos) {
    g_head_rows = (int) tokens.size();
    const std::vector<double> r = prefill_top1(backend, tokens, -1, pos);
    std::vector<int> out = g_step_top1;
    g_head_rows = 1;
    if (r.empty()) return {};
    out.push_back((int) r[0]);
    return out;
}

// Greedy speculative decoding with a prompt-lookup drafter: propose the tokens that followed the
// last occurrence of the current tail, verify all of them in ONE batched forward, keep the
// matching prefix. Numerically identical to generate() because every accepted token came from
// the model's own argmax at that position.
std::vector<int> spec_generate(int backend, std::vector<int> ids, int n_new, int k) {
    std::vector<int> out = ids;
    g_spec_drafts = g_spec_accepted = g_spec_verified = 0;
    g_spec_alt = 0;
    g_spec_bmax = 0;
    // Negative control for the self-test: a negative k means "propose drafts but reject every
    // one". Nothing is accepted, so the output must still equal plain greedy generation, and
    // the adaptive rule must switch drafting off after its miss budget.
    bool force_reject = k < 0;
    if (force_reject) k = -k;
    g_head_rows = 1;
    std::vector<double> r = prefill_top1(backend, out, -1, 0);
    if (r.empty()) return {};
    int pos = (int) out.size();
    int produced = 0;
    const int eos = eos_id();
    int ref = (int) r[0];
    const int m = 3;   // lookup window
    // Adaptive: a verify that accepts nothing costs a full batched forward and buys nothing -
    // measured at 0.83x (slower than plain decoding) when the text never repeats. After a few
    // consecutive empty accepts, stop drafting for the rest of this call.
    bool drafting = true;
    int misses = 0;

    while (produced < n_new) {
        std::vector<int> draft;
        const size_t n = out.size();
        const int room = g_kv.cap - pos;
        const int kk = std::min(k, room > 0 ? room : 0);
        if (drafting && kk > 0 && n >= (size_t) m + 1) {
            for (size_t p = n - (size_t) m; p > 0; --p) {
                const size_t start = p - 1;
                bool hit = true;
                for (int j = 0; j < m; ++j) {
                    if (out[start + (size_t) j] != out[n - (size_t) m + (size_t) j]) { hit = false; break; }
                }
                if (!hit) continue;
                const size_t f = start + (size_t) m;
                for (int j = 0; j < kk && f + (size_t) j < n; ++j) draft.push_back(out[f + (size_t) j]);
                if (!draft.empty()) break;
            }
        }

        if (draft.empty()) {
            out.push_back(ref);
            ++produced;
            if (eos > 0 && ref == eos) break;
            // Feed `ref` at ITS OWN position (the index it was just appended at), then advance.
            r = prefill_top1(backend, std::vector<int>{ref}, -1, pos);
            ++pos;
            if (r.empty()) break;
            ref = (int) r[0];
            continue;
        }

        ++g_spec_verified;
        g_spec_drafts += (int) draft.size();
        const std::vector<int> tops = prefill_tops(backend, draft, pos);
        if (tops.size() != draft.size()) break;

        int acc = 0;
        for (int i = 0; i < (int) draft.size(); ++i) {
            const int pred = (i == 0) ? ref : tops[(size_t) i - 1];
            if (draft[(size_t) i] != pred) break;
            ++acc;
        }
        if (force_reject) acc = 0;
        if (acc > 0) {
            // Never emit more than the caller asked for: a wide draft can accept past n_new,
            // which would break the max_tokens contract (and differ from plain decoding).
            const int take = std::min(acc, n_new - produced);
            for (int i = 0; i < take; ++i) out.push_back(draft[(size_t) i]);
            produced += take;
            pos += acc;
            g_spec_accepted += take;
            misses = 0;                 // an accept resets the adaptive miss budget
            if (produced >= n_new) break;
            if (eos > 0 && out.back() == eos) break;
            // No forward needed: the verify pass already told us the token after the accepted
            // prefix - the last head row if every draft was accepted, else the row before.
            ref = tops[(size_t) acc - 1];
            continue;
        }
        // Nothing matched: the draft was wasted, so take the one token we already know.
        if (++misses >= 3) drafting = false;
        out.push_back(ref);
        ++produced;
        if (eos > 0 && ref == eos) break;
        r = prefill_top1(backend, std::vector<int>{ref}, -1, pos);
        ++pos;
        if (r.empty()) break;
        ref = (int) r[0];
    }
    g_spec_on = drafting ? 1 : 0;
    return out;
}

// One verify pass over B draft branches of depth D. The mask makes each node attend only to the
// prefix plus its own ancestors, so the branches cannot see each other - this is the "verify a
// tree in one pass" half of FR-06.1. Returns the argmax at every node, branch-major.
//
// F308: the tree's per-node state is transient. The rows live in FLAT slots (so the branches can
// coexist in one pass) while their rotation comes from the LOGICAL depth in g_row_pos, and both
// scratch vectors are cleared on every exit - a leaked g_row_pos would silently reposition the
// next single-token prefill.
std::vector<int> prefill_tops_tree(int backend, const std::vector<int>& tokens, int pos,
                                   const std::vector<uint32_t>& mask) {
    sycl::queue* qp = kakasi::queue_for(backend);
    if (!qp) { g_err = "tree verify: backend unavailable"; g_row_pos.clear(); g_head_want.clear(); return {}; }
    if (mask.size() != tokens.size()) { g_err = "tree verify: mask size mismatch"; g_row_pos.clear(); g_head_want.clear(); return {}; }
    uint32_t* dm = sycl::malloc_device<uint32_t>(mask.size(), *qp);
    if (!dm) { g_err = "tree verify: mask alloc failed"; g_row_pos.clear(); g_head_want.clear(); return {}; }
    qp->memcpy(dm, mask.data(), sizeof(uint32_t) * mask.size());
    qp->wait_and_throw();
    g_tree_mask = dm;
    const std::vector<int> out = prefill_tops(backend, tokens, pos);
    g_tree_mask = nullptr;
    g_row_pos.clear();
    g_head_want.clear();
    sycl::free(dm, *qp);
    return out;
}

// F308: after a branch other than branch 0 wins, its rows sit at [src0, src0+run) while the
// committed prefix logically occupies [pos0, pos0+run). Without this move, the next token is fed
// right after the prefix and attends to the REJECTED branch-0 rows as if they were the context.
// src0 > pos0 and run <= D <= b*D, so the ranges never overlap.
void kv_compact(int backend, int pos0, int src0, int run) {
    if (run <= 0 || src0 <= pos0) return;
    sycl::queue* qp = kakasi::queue_for(backend);
    if (!qp || g_kv.k.empty()) return;
    const int L = (int) g_kv.k.size();
    const size_t stride = (size_t) g_kv.dkv * g_kv.elem();
    if (stride == 0) return;
    const size_t bytes = (size_t) run * stride;
    for (int l = 0; l < L; ++l) {
        if (g_kv.k[(size_t) l]) {
            qp->memcpy((char*) g_kv.k[(size_t) l] + (size_t) pos0 * stride,
                       (char*) g_kv.k[(size_t) l] + (size_t) src0 * stride, bytes);
        }
        if (g_kv.v[(size_t) l]) {
            qp->memcpy((char*) g_kv.v[(size_t) l] + (size_t) pos0 * stride,
                       (char*) g_kv.v[(size_t) l] + (size_t) src0 * stride, bytes);
        }
    }
    qp->wait_and_throw();
}

// Tree speculative decoding: B branches x D tokens per verify pass, accept the longest verified
// branch. Numerically identical to greedy decoding because every accepted token is the model's
// own argmax at that position (the mask guarantees each node saw exactly its path's context).
std::vector<int> spec_tree_generate(int backend, std::vector<int> ids, int n_new, int bins,
                                    int depth) {
    std::vector<int> out = ids;
    g_spec_drafts = g_spec_accepted = g_spec_verified = 0;
    g_spec_alt = 0;
    g_spec_bmax = 0;
    g_spec_on = 1;
    // F308 negative control: bins<0 keeps the width but proposes the lookup matches in REVERSE
    // recency order, so the branch the model actually wants is no longer branch 0. Without this,
    // every tree gate can pass while never committing a non-zero branch - which is exactly what
    // measured alt_branch=0 across all of them.
    const bool reverseBranches = (bins < 0);
    const int B = std::max(1, reverseBranches ? -bins : bins);
    const int D = std::max(1, depth);
    g_head_rows = 1;
    std::vector<double> r = prefill_top1(backend, out, -1, 0);
    if (r.empty()) return {};
    int pos = (int) out.size();
    int produced = 0;
    const int eos = eos_id();
    int ref = (int) r[0];
    const int m = 3;
    int misses = 0;
    bool drafting = true;

    while (produced < n_new) {
        // Collect up to B branches: each is the D tokens that followed an earlier occurrence of
        // the current tail trigram.
        std::vector<std::vector<int>> branches;
        const size_t n = out.size();
        const int room = g_kv.cap - pos;
        const int maxNodes = std::min(B * D, room > 0 ? room : 0);
        if (drafting && maxNodes >= D && n >= (size_t) m + 1) {
            for (size_t p = n - (size_t) m; p > 0 && (int) branches.size() < B; --p) {
                const size_t start = p - 1;
                bool hit = true;
                for (int j = 0; j < m; ++j) {
                    if (out[start + (size_t) j] != out[n - (size_t) m + (size_t) j]) { hit = false; break; }
                }
                if (!hit) continue;
                const size_t f = start + (size_t) m;
                if (f + (size_t) D > n) continue;      // need a full-depth path
                std::vector<int> br;
                for (int j = 0; j < D; ++j) br.push_back(out[f + (size_t) j]);
                // Repetitive text has the same continuation after every occurrence of the tail
                // trigram, so pushing duplicates would spend every bin on one path (F157/F262).
                // Keep scanning for distinct continuations instead.
                bool dup = false;
                for (size_t bi = 0; bi < branches.size(); ++bi) {
                    if (branches[bi] == br) { dup = true; break; }
                }
                if (dup) continue;
                branches.push_back(std::move(br));
            }
        }

        if (branches.empty()) {
            out.push_back(ref);
            ++produced;
            if (eos > 0 && ref == eos) break;
            r = prefill_top1(backend, std::vector<int>{ref}, -1, pos);
            ++pos;
            if (r.empty()) break;
            ref = (int) r[0];
            continue;
        }

        if (reverseBranches) std::reverse(branches.begin(), branches.end());
        if ((int) branches.size() > g_spec_bmax) g_spec_bmax = (int) branches.size();
        // Flatten the tree and build the mask: node (b,i) may attend to its branch's ancestors.
        std::vector<int> nodes;
        std::vector<uint32_t> mask;
        std::vector<int> depths;
        for (size_t b = 0; b < branches.size(); ++b) {
            uint32_t bits = 0;
            for (int i = 0; i < D; ++i) {
                nodes.push_back(branches[b][(size_t) i]);
                depths.push_back(i);      // F308: every branch stands at the same depth
                bits |= (1u << (uint32_t) ((int) b * D + i));
                mask.push_back(bits);      // cumulative: branch prefix up to and including i
            }
        }
        ++g_spec_verified;
        g_spec_drafts += (int) nodes.size();
        if (g_spec_fix) g_row_pos = depths;
        const std::vector<int> tops = prefill_tops_tree(backend, nodes, pos, mask);
        if (tops.size() != nodes.size()) break;

        // Longest accepted prefix, branch by branch.
        int bestB = -1;
        int bestAcc = 0;
        for (size_t b = 0; b < branches.size(); ++b) {
            int acc = 0;
            for (int i = 0; i < D; ++i) {
                const int pred = (i == 0) ? ref : tops[b * (size_t) D + (size_t) i - 1];
                if (branches[b][(size_t) i] != pred) break;
                ++acc;
            }
            // Ties commit identical tokens (both branches' depth-0 row is the same row, so they
            // agree on every token up to the run length), so preferring the LAST tied branch is
            // not a heuristic - it is what makes the non-zero branch actually get committed, i.e.
            // the F308 compaction path becomes reachable without changing the expected output.
            const bool prefer = reverseBranches ? (acc >= bestAcc && acc > 0) : (acc > bestAcc);
            if (prefer) { bestAcc = acc; bestB = (int) b; }
        }

        if (bestAcc > 0) {
            const int take = std::min(bestAcc, n_new - produced);
            for (int i = 0; i < take; ++i) out.push_back(branches[(size_t) bestB][(size_t) i]);
            produced += take;
            if (bestB > 0) { ++g_spec_alt; if (g_spec_fix) kv_compact(backend, pos, pos + bestB * D, bestAcc); }
            pos += bestAcc;
            g_spec_accepted += take;
            misses = 0;
            if (produced >= n_new) break;
            if (eos > 0 && out.back() == eos) break;
            ref = tops[(size_t) bestB * (size_t) D + (size_t) bestAcc - 1];
            continue;
        }

        if (++misses >= 3) drafting = false;
        out.push_back(ref);
        ++produced;
        if (eos > 0 && ref == eos) break;
        r = prefill_top1(backend, std::vector<int>{ref}, -1, pos);
        ++pos;
        if (r.empty()) break;
        ref = (int) r[0];
    }
    g_spec_on = drafting ? 1 : 0;
    return out;
}

// FR-06 with a SAMPLING verifier. Under the greedy argmax test a tree can accept at most one
// branch, because the argmax along an accepted prefix is unique (F273) - branch diversity only
// pays once acceptance is probabilistic. At each node the model's OWN logits row gives p(draft)
// for its branch's next token: keep the draft when rand01() < p(draft), otherwise end the branch
// with a draw from the residual (p - delta_draft)+. For bins=1 that is exactly speculative
// sampling (Leviathan et al.); for bins>1 the per-chain tests plus "longest accepted chain wins,
// correct from its own residual" is the standard tree rule, and no exactness is claimed for it.
// temp<=0 puts p on the argmax, so this degenerates into spec_tree_generate token for token.
std::vector<int> spec_tree_sample(int backend, std::vector<int> ids, int n_new, int bins, int depth,
                                  double temp, long long seed) {
    std::vector<int> out = ids;
    g_spec_drafts = g_spec_accepted = g_spec_verified = 0;
    g_spec_alt = 0;
    g_spec_bmax = 0;
    g_spec_on = 1;
    g_sess_rng = (uint64_t) (unsigned long long) (long long) seed;
    const bool greedy_like = !(temp > 0.0);
    const bool reverseBranches = (bins < 0);   // F308 negative control, see spec_tree_generate
    const int B = std::max(1, reverseBranches ? -bins : bins);
    const int D = std::max(1, depth);
    std::vector<double> r = prefill_top1(backend, out, -1, 0);
    if (r.empty()) return {};
    int pos = (int) out.size();
    int produced = 0;
    const int eos = eos_id();
    int ref = (int) r[0];
    const int m = 3;
    int misses = 0;
    // bins==0 means "never draft": the loop below then behaves as plain sampled decoding, which is
    // the same-function control for the tree-on/tree-off wall measurement. bins<0 drafts with the
    // branch order reversed (F308 control).
    bool drafting = (bins != 0);

    while (produced < n_new) {
        std::vector<std::vector<int>> branches;
        const size_t n = out.size();
        const int room = g_kv.cap - pos;
        const int maxNodes = std::min(B * D, room > 0 ? room : 0);
        // FR-06.2 step 2: the candidate SET is the ceiling (F318 measured max_branches=2), so the
        // lookup window is widened when the strict trigram cannot fill B branches. A shorter
        // window (m-1) matches more often and yields genuinely different continuations; a longer
        // one is tried last because it is rarer but more precise.
        auto collect = [&](int mm) {
            if (mm <= 0 || n < (size_t) mm + 1) return;
            for (size_t p = n - (size_t) mm; p > 0 && (int) branches.size() < B; --p) {
                const size_t start = p - 1;
                bool hit = true;
                for (int j = 0; j < mm; ++j) {
                    if (out[start + (size_t) j] != out[n - (size_t) mm + (size_t) j]) { hit = false; break; }
                }
                if (!hit) continue;
                const size_t f = start + (size_t) mm;
                if (f + (size_t) D > n) continue;
                std::vector<int> br;
                for (int j = 0; j < D; ++j) br.push_back(out[f + (size_t) j]);
                bool dup = false;
                for (size_t bi = 0; bi < branches.size(); ++bi) {
                    if (branches[bi] == br) { dup = true; break; }
                }
                if (dup) continue;
                branches.push_back(br);
            }
        };
        if (drafting && maxNodes >= D) {
            collect(m);
            if (g_spec_multiwin && (int) branches.size() < B) collect(m - 1);
            if (g_spec_multiwin && (int) branches.size() < B) collect(m + 1);
        }

        if (g_spec_freq && drafting && !branches.empty()) {
            // Re-scan ALL matches (the loop above stops at B, which is exactly what hides the
            // frequent-but-not-nearest continuation), tally each distinct continuation, and keep
            // the top B by count. Ties keep recency: the scan is nearest-first and the sort only
            // swaps on a strict comparison.
            std::vector<std::vector<int>> cands;
            std::vector<int> counts;
            for (int mw = 0; mw < (g_spec_multiwin ? 3 : 1); ++mw) {
                const int mm = (mw == 0) ? m : (mw == 1 ? m - 1 : m + 1);
                if (mm <= 0 || n < (size_t) mm + 1) continue;
            for (size_t p2 = n - (size_t) mm; p2 > 0; --p2) {
                const size_t start2 = p2 - 1;
                bool hit2 = true;
                for (int j2 = 0; j2 < mm; ++j2) {
                    if (out[start2 + (size_t) j2] != out[n - (size_t) mm + (size_t) j2]) { hit2 = false; break; }
                }
                if (!hit2) continue;
                const size_t f2 = start2 + (size_t) mm;
                if (f2 + (size_t) D > n) continue;
                std::vector<int> br2;
                for (int j2 = 0; j2 < D; ++j2) br2.push_back(out[f2 + (size_t) j2]);
                size_t at = cands.size();
                for (size_t ci = 0; ci < cands.size(); ++ci) {
                    if (cands[ci] == br2) { at = ci; break; }
                }
                if (at == cands.size()) { cands.push_back(br2); counts.push_back(0); }
                counts[at] += 1;
            }
            }
            if (!cands.empty()) {
                std::vector<size_t> order(cands.size());
                for (size_t i2 = 0; i2 < order.size(); ++i2) order[i2] = i2;
                for (size_t i2 = 1; i2 < order.size(); ++i2) {
                    size_t j2 = i2;
                    while (j2 > 0 && counts[order[j2 - 1]] < counts[order[j2]]) {
                        const size_t t2 = order[j2];
                        order[j2] = order[j2 - 1];
                        order[j2 - 1] = t2;
                        --j2;
                    }
                }
                branches.clear();
                for (size_t i2 = 0; i2 < order.size() && (int) branches.size() < B; ++i2) {
                    branches.push_back(cands[order[i2]]);
                }
            }
        }

        if (branches.empty()) {
            // Nothing to verify: one plain step. At temp>0 that step is a sample from p, and
            // sample_excluding(-1, temp) is exactly that (no id is skipped).
            int c = ref;
            if (!greedy_like) { const int s1 = sample_excluding(-1, temp); if (s1 >= 0) c = s1; }
            out.push_back(c);
            ++produced;
            if (eos > 0 && c == eos) break;
            r = prefill_top1(backend, std::vector<int>{c}, -1, pos);
            ++pos;
            if (r.empty()) break;
            ref = (int) r[0];
            continue;
        }

        if (reverseBranches) std::reverse(branches.begin(), branches.end());
        if ((int) branches.size() > g_spec_bmax) g_spec_bmax = (int) branches.size();
        // Flatten the tree. `want[node]` is the token this node must predict for its own branch,
        // or -1 at a branch's last node, where the draw is the bonus token instead.
        std::vector<int> nodes;
        std::vector<int> want;
        std::vector<int> depths;
        std::vector<uint32_t> mask;
        for (size_t b = 0; b < branches.size(); ++b) {
            uint32_t bits = 0;
            for (int i = 0; i < D; ++i) {
                nodes.push_back(branches[b][(size_t) i]);
                depths.push_back(i);      // F308: logical depth, not the flat slot
                want.push_back((i + 1 < D) ? branches[b][(size_t) (i + 1)] : -1);
                bits |= (1u << (uint32_t) ((int) b * D + i));
                mask.push_back(bits);
            }
        }

        // Root test first: every branch's first token is judged against the distribution that
        // produced `ref`, still live in g_last_logits, so no forward is needed for it.
        std::vector<char> rootAcc(branches.size(), 0);
        std::vector<int> rootTail(branches.size(), ref);
        int canonB = 0;
        double bestP = -1.0;
        for (size_t b = 0; b < branches.size(); ++b) {
            const int d0 = branches[b][(size_t) 0];
            const double p = token_prob(d0, temp);
            if (p > bestP) { bestP = p; canonB = (int) b; }
            if (p >= 1.0) {
                rootAcc[b] = 1;
            } else if (p > 0.0 && !greedy_like && rand01() < p) {
                rootAcc[b] = 1;
            } else {
                const int s1 = greedy_like ? ref : sample_excluding(d0, temp);
                rootTail[b] = (s1 >= 0) ? s1 : ref;
            }
        }

        ++g_spec_verified;
        g_spec_drafts += (int) nodes.size();
        g_head_rows = (int) nodes.size();
        if (g_spec_fix) g_row_pos = depths;
        g_head_want = want;
        g_head_temp = temp;
        g_head_prob.clear();
        g_head_alt.clear();
        const std::vector<int> tops = prefill_tops_tree(backend, nodes, pos, mask);
        g_head_want.clear();
        if (tops.size() != nodes.size()) break;

        // Longest accepted chain, each ending in its own correction (or bonus) draw.
        int bestB = -1, bestRun = -1, bestTail = -1;
        for (size_t b = 0; b < branches.size(); ++b) {
            int run = 0;
            int tail = rootTail[b];
            if (rootAcc[b]) {
                run = 1;
                tail = -1;
                bool rejected = false;      // a real rejection produced this branch's correction
                for (int i = 1; i < D; ++i) {
                    const int pi = (int) b * D + (i - 1);
                    if (pi >= (int) g_head_prob.size()) { run = -1; break; }
                    const double p = g_head_prob[(size_t) pi];
                    if (p >= 1.0 || (p > 0.0 && !greedy_like && rand01() < p)) { ++run; continue; }
                    tail = g_head_alt[(size_t) pi];
                    rejected = true;
                    break;
                }
                if (run >= 0 && !rejected) {
                    // Whole branch accepted: the node after the last draft gives the bonus token.
                    const int pi = (int) b * D + D - 1;
                    if (pi < (int) g_head_alt.size()) tail = g_head_alt[(size_t) pi];
                }
                if (run < 0) tail = -1;
            }
            // reverseBranches prefers the LAST tied branch: see spec_tree_generate, the tied runs
            // commit identical tokens, so this only routes the commit through a non-zero branch.
            const bool prefer = reverseBranches ? (run >= bestRun && run > 0) : (run > bestRun);
            if (prefer) { bestRun = run; bestB = (int) b; bestTail = tail; }
        }
        if (bestB < 0) break;
        if (bestRun <= 0) { bestB = canonB; bestTail = rootTail[(size_t) canonB]; }
        if (bestTail < 0) break;

        int committed = 0;
        for (int i = 0; i < bestRun && produced < n_new; ++i) {
            out.push_back(branches[(size_t) bestB][(size_t) i]);
            ++produced;
            ++committed;
        }
        if (bestB > 0 && committed > 0) { ++g_spec_alt; if (g_spec_fix) kv_compact(backend, pos, pos + bestB * D, committed); }
        pos += committed;
        g_spec_accepted += committed;
        if (produced >= n_new) break;
        if (committed > 0) misses = 0;
        if (eos > 0 && committed > 0 && out.back() == eos) break;
        // The correction/bonus token was not part of the verified tree, so it gets its own feed.
        out.push_back(bestTail);
        ++produced;
        ++pos;
        ++g_spec_accepted;
        if (eos > 0 && bestTail == eos) break;
        r = prefill_top1(backend, std::vector<int>{bestTail}, -1, pos - 1);
        if (r.empty()) break;
        ref = (int) r[0];
        if (bestRun <= 0 && ++misses >= 3) drafting = false;
    }
    g_spec_on = drafting ? 1 : 0;
    return out;
}

// F315: switch the tree verifier between the repaired path (logical-depth RoPE + KV row
// compaction) and the legacy path, inside one build, so a self-test can show that the repair is
// what makes the non-zero branch greedy-identical.
void set_spec_fix(int on) { g_spec_fix = on ? 1 : 0; }
int spec_fix_on() { return g_spec_fix; }

// FR-06.2 step 1 control: rank draft branches by how often each continuation actually followed the
// tail window, instead of by recency. Toggled inside one build so the A/B is not confounded by the
// cross-round machine drift that made SPEC-TREE-SWEEP unusable (F312).
void set_spec_freq(int on) { g_spec_freq = on ? 1 : 0; }
int spec_freq_on() { return g_spec_freq; }
void set_spec_multiwin(int on) { g_spec_multiwin = on ? 1 : 0; }
int spec_multiwin_on() { return g_spec_multiwin; }

std::string spec_stats() {    char buf[220];
    std::snprintf(buf, sizeof(buf),
                  "drafts=%d accepted=%d verifies=%d alt_branch=%d max_branches=%d drafting=%s",
                  g_spec_drafts, g_spec_accepted, g_spec_verified, g_spec_alt, g_spec_bmax,
                  g_spec_on ? "on" : "off");
    return std::string(buf);
}

}  // namespace model
