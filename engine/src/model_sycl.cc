// Qwen2 forward pass, executed on the SYCL backend.
// Weights are dequantized to device memory once and kept (budgeted residency); the tied
// lm_head still runs in row chunks so its 136M-element matrix never lands on the host.
#include <sycl/sycl.hpp>

#include "gguf.h"
#include "sycl_backend.h"
#include "buffer.h"

#include <algorithm>
#include <chrono>
#include <cmath>
#include <cstdint>
#include <cstdio>
#include <cstdlib>
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
    // F365/F366 A/B switch: 0 = the score cache (each attention score computed once and
    // reused by the three passes that need it), 1 = the pre-F365 kernel (the score recomputed in
    // every pass), 2 = split-KV (the range cut into chunks, one work item each, then combined).
    // All three live in this binary so they can be measured in one process; 0 and 1 are
    // arithmetically identical, 2 groups the same sum differently on purpose.
    // F368: shipped default. Split-KV measured 3.89x on the block stage at L=256 and 1.73x at
    // L=64, and ranges too short to split (P=1) now fall through to the score-cache path, so this
    // is the faster branch at every length. KAKASI_ATTN_MODE=0 pins the old behaviour for A/B.
    int g_attn_mode = 2;
    // F369: the chunk count the last attention pass actually used, so a served trace line can
    // carry the branch it took instead of leaving the reader to infer it from a timing delta.
    int g_split_p = 1;
    // The server reads the same switch from the environment, so the served path can be A/B'd
    // in this binary too: KAKASI_ATTN_MODE=1 starts it on the pre-F365 kernel.
    struct AttnModeFromEnv {
        AttnModeFromEnv() {
#if defined(_MSC_VER)
#pragma warning(push)
#pragma warning(disable : 4996)
#endif
            const char* e = std::getenv("KAKASI_ATTN_MODE");
#if defined(_MSC_VER)
#pragma warning(pop)
#endif
            if (e && e[0] != '\0') {
                const int m = std::atoi(e);
                g_attn_mode = (m >= 0 && m <= 5) ? m : 2;
            }
        }
    };
    AttnModeFromEnv g_attn_mode_from_env;

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
                buf::BufferType* btp = kakasi::buffer_type_for_queue(q);
                const size_t rb = (size_t) cap * (size_t) dkv * elem();
                for (void* pp : k) if (pp) buf::free_bt(btp, pp, rb);
                for (void* pp : v) if (pp) buf::free_bt(btp, pp, rb);
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

    // F675 Stage 2: N contiguous segments. A segment split is a list of
    // (backend, first_layer); segments run in start order, are contiguous, and cover
    // every layer. The two-segment "primary b0 + one second" shape keeps its dedicated
    // fast path (g_kv2 and the decode-side per-backend mirrors all live on it); a shape
    // that needs more than two segments - or does not start on b0 - takes this generic
    // path, where every segment owns its own KV, rope tables, scratch and hidden state,
    // and the boundary handoff walks the shared host buffer_type the way the two-device
    // path already does.
    struct SegmentSplit {
        std::vector<int> backend;
        std::vector<int> start;    // ascending
        // F681: op-granular override - the FFN op of ONE layer runs on segment |ffnSeg|
        // while its ATTN (and every other op) stays on the layer-range segments. The
        // override segment may own zero layers (its start == L); the copy nodes around
        // that op are the TENSOR-granular boundary copies (hidden state across devices
        // at op granularity, not only at segment boundaries).
        int ffnSegLayer = -1;
        int ffnSeg = -1;
    };
    // F681: the segment owning an OP. ATTN always sits on the layer-range segment; FFN
    // moves to the override segment when the plan declares one for this layer. Free
    // function so the graph build, the conformance check and the pass setup all share it.
    int segOfOp(const SegmentSplit& split, int l, bool ffn) {
        const int nSeg = (int) split.backend.size();
        int sk = 0;
        for (int k = nSeg - 1; k >= 0; --k) {
            if (split.start[(size_t) k] <= l) { sk = k; break; }
        }
        if (ffn && split.ffnSegLayer == l && split.ffnSeg >= 0
            && split.ffnSeg < nSeg) {
            return split.ffnSeg;
        }
        return sk;
    }
    SegmentSplit g_seg_split;
    bool g_seg_active = false;

    struct SegRT {
        sycl::queue* q = nullptr;
        int backend = -1;
        float* xs = nullptr;       // running hidden state on this segment
        float* hs = nullptr;       // per-layer scratch
        float* dCos = nullptr;
        float* dSin = nullptr;
        float* scores = nullptr;
        float* part = nullptr;
        int kv_layers = 0;         // layers this segment owns
        void free_scratch(buf::BufferType* btp) {
            // Ownership is proven by the ledger (exactly-once, right class); byte sizes
            // are recorded at alloc time, so 0 on the free side matches the residency
            // and KV-row convention.
            if (dCos) { buf::free_bt(btp, dCos, 0); dCos = nullptr; }
            if (dSin) { buf::free_bt(btp, dSin, 0); dSin = nullptr; }
            if (scores) { buf::free_bt(btp, scores, 0); scores = nullptr; }
            if (part) { buf::free_bt(btp, part, 0); part = nullptr; }
        }
    };
    // Persistent per-segment KV slots, index-aligned with g_seg_split: they survive across
    // passes exactly like the dedicated g_kv2 (the prefix rows they hold are what the
    // next pass's attention reads). Per-pass scratch (hidden state, rope, score buffers)
    // is allocated and freed inside each pass.
    struct SegKvSlot {
        int backend = -1;
        KVCache kv;
    };
    std::vector<SegKvSlot> g_seg_kv;
    std::vector<SegRT> g_seg;

    // F675 Stage 3 (FR-02.3) + F677: the explicit compute graph at OP granularity. The
    // graph is the single source of truth for which segment runs which op of which layer,
    // where the boundary copies sit, and which buffer_type each node's tensors live in.
    // Built when a generic N-segment split or the two-device fast split is armed; each
    // executing loop consumes it (per-op conformance check); model_graph_report() shows
    // the full plan.
    struct GraphNode {
        // F677: the graph is declared at op granularity - every transformer layer is its
        // two ops (attention block: norms/qkv/rope/KV-store/attention/o_proj; ffn block:
        // ffn norm/gate/up/silu-mul/down), not one opaque LAYER node.
        enum Kind { EMBED = 0, ATTN = 1, FFN = 2, BOUNDARY_COPY = 3, FINAL_NORM = 4, LM_HEAD = 5 } kind;
        int layer;       // transformer layer index, -1 for non-layer ops
        int segment;     // owning segment index into g_seg_split
        int backend;     // the device that executes this node
        // F679: the explicit dependency edges. Each node names the node indices that must
        // finish before it runs - the graph is a DAG, not just an ordered list. The
        // executor's sequential walk is valid only where every edge points to an earlier
        // execution index; a future scheduler may reorder any subgraph that still honors
        // these edges (that is the non-sequential topology seam).
        std::vector<int> deps;
        // F681: for BOUNDARY_COPY nodes, the segment the tensor comes FROM (the copy
        // moves the running hidden state srcSeg -> segment). -1 for every other kind.
        int srcSeg = -1;
        GraphNode(Kind k, int l, int s, int b) : kind(k), layer(l), segment(s), backend(b) {}
    };
    struct ForwardGraph {
        std::vector<GraphNode> nodes;
        int copies = 0;      // boundary-copy count
        // Build the graph for L layers under the given segment split.
        void build(int L, const SegmentSplit& split) {
            nodes.clear();
            copies = 0;
            const int nSeg = (int) split.backend.size();
            // F679: every node depends on the one added just before it - the plan is a
            // linear chain DAG, so the edge set is N-1 predecessor links. EMBED has no
            // predecessor. A future non-sequential topology re-emits this edge set with
            // real branch merges.
            auto add = [&](GraphNode::Kind k, int l, int s, int b, int src) {
                nodes.push_back(GraphNode(k, l, s, b));
                nodes.back().srcSeg = src;
                if (nodes.size() > 1) nodes.back().deps.push_back((int) nodes.size() - 2);
            };
            // EMBED always on the primary device (segment 0).
            add(GraphNode::EMBED, -1, 0, split.backend.empty() ? 0 : split.backend[0], -1);
            // F681: copies are emitted at OP granularity - wherever the running hidden
            // state must cross devices between two consecutive ops. For layer-range
            // plans that is exactly the old segment-boundary set; with an FFN override
            // the state crosses twice around the overridden op.
            int prevSeg = 0;
            for (int l = 0; l < L; ++l) {
                const int aSeg = segOfOp(split, l, false);
                if (aSeg != prevSeg) {
                    add(GraphNode::BOUNDARY_COPY, l, aSeg, split.backend[(size_t) aSeg], prevSeg);
                    ++copies;
                    prevSeg = aSeg;
                }
                // F677: each layer declares its two ops; both run on the segment the plan
                // assigns (intra-layer overlap is an explicit scheduler decision, not a
                // hidden default - the loop may only move ops across devices if the graph
                // says so).
                add(GraphNode::ATTN, l, aSeg, split.backend[(size_t) aSeg], -1);
                const int fSeg = segOfOp(split, l, true);
                if (fSeg != aSeg) {
                    add(GraphNode::BOUNDARY_COPY, l, fSeg, split.backend[(size_t) fSeg], aSeg);
                    ++copies;
                }
                add(GraphNode::FFN, l, fSeg, split.backend[(size_t) fSeg], -1);
                prevSeg = fSeg;
            }
            // Final norm + lm_head always return to the primary device; the return trip
            // is the driver's handback epilogue, not a graph node (unchanged since F675).
            add(GraphNode::FINAL_NORM, -1, 0, split.backend.empty() ? 0 : split.backend[0], -1);
            add(GraphNode::LM_HEAD, -1, 0, split.backend.empty() ? 0 : split.backend[0], -1);
        }
        // F679: total declared edge count.
        size_t edge_count() const {
            size_t n = 0;
            for (const auto& x : nodes) n += x.deps.size();
            return n;
        }
        // Kahn topological order over the declared edges. ok=false on a cycle or an
        // edge naming a nonexistent node; for the current linear plan the order is the
        // node list unchanged.
        std::vector<int> topo_order(bool& ok) const {
            ok = false;
            const int N = (int) nodes.size();
            std::vector<std::vector<int>> succ((size_t) N > 0 ? (size_t) N : 1);
            std::vector<int> in((size_t) N > 0 ? (size_t) N : 1, 0);
            for (int v = 0; v < N; ++v) {
                for (int u : nodes[(size_t) v].deps) {
                    if (u < 0 || u >= N || u == v) return {};
                    succ[(size_t) u].push_back(v);
                    in[(size_t) v]++;
                }
            }
            std::vector<int> ready;
            for (int v = 0; v < N; ++v) if (in[(size_t) v] == 0) ready.push_back(v);
            std::vector<int> order;
            order.reserve((size_t) N);
            for (size_t head = 0; head < ready.size(); ++head) {
                const int u = ready[head];
                order.push_back(u);
                for (int v : succ[(size_t) u]) if (--in[(size_t) v] == 0) ready.push_back(v);
            }
            ok = ((int) order.size() == N);
            return order;
        }
        // F679: the executing loop walks nodes in declaration (vector) order. That walk
        // is a valid execution only where every declared edge points to a node that runs
        // EARLIER in the same walk - the walk itself must be a topological order of the
        // declared DAG. A cycle or a forward edge means the loop would execute a node
        // before one of its dependencies.
        bool dag_conforms() const {
            const int N = (int) nodes.size();
            if (N <= 1) return true;
            std::vector<int> in((size_t) N, 0);
            for (int v = 0; v < N; ++v) {
                for (int u : nodes[(size_t) v].deps) {
                    if (u < 0 || u >= N || u == v) return false;
                    in[(size_t) v]++;
                }
            }
            // Walk the declared order: every node must have all its deps already seen,
            // and no node may depend on a later position.
            for (int v = 0; v < N; ++v) {
                for (int u : nodes[(size_t) v].deps) {
                    if (u >= v) return false;   // forward/self edge => loop order invalid
                }
            }
            return true;
        }
        // Human-readable plan: one field per non-copy node, "kind:layer@bN".
        std::string report() const {
            std::string out = "nodes=" + std::to_string(nodes.size())
                            + " copies=" + std::to_string(copies)
                            + " edges=" + std::to_string(edge_count());
            bool topoOk = false;
            topo_order(topoOk);
            out += " topo=" + std::string(topoOk ? "ok" : "cycle");
            for (const auto& n : nodes) {
                if (n.kind == GraphNode::BOUNDARY_COPY) continue;
                const char* kn = n.kind == GraphNode::EMBED ? "emb"
                               : n.kind == GraphNode::ATTN ? "a"
                               : n.kind == GraphNode::FFN ? "f"
                               : n.kind == GraphNode::FINAL_NORM ? "fn" : "lm";
                // F677: op nodes carry their layer index (a0..f23); a0@b1 f0@b1 names
                // attention+ffn of layer 0 on device b1.
                out += " " + std::string(kn)
                     + (n.kind == GraphNode::ATTN || n.kind == GraphNode::FFN ? std::to_string(n.layer) : "")
                     + "@b" + std::to_string(n.backend);
            }
            return out;
        }
    };
    ForwardGraph g_graph;
// F680: did the last generic-segment pass run through the graph-driven (topo-order)
// executor? The generic branch sets it after driving the walk; every pass clears it
// first, so the flag names the LAST pass, not "ever ran it".
bool g_graphExecTopo = false;
    struct LayerPlacementPlan {
        std::vector<int> backend;
        int secondary_backend = -1;
        int handoff_layer = -1;

        int backend_for(int layer) const { return backend[(size_t) layer]; }
        bool handoff_before(int layer) const { return layer == handoff_layer; }
    };

    LayerPlacementPlan make_layer_placement_plan(int layers, int primary, int secondary, int split_layer) {
        LayerPlacementPlan plan;
        if (layers > 0) plan.backend.assign((size_t) layers, primary);
        if (layers > 0 && primary >= 0 && secondary >= 0 && secondary != primary
            && split_layer >= 0 && split_layer < layers) {
            plan.secondary_backend = secondary;
            plan.handoff_layer = split_layer;
            for (int layer = split_layer; layer < layers; ++layer) {
                plan.backend[(size_t) layer] = secondary;
            }
        }
        return plan;
    }

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
                buf::BufferType* btp = kakasi::buffer_type_for_queue(q);
                // Byte sizes are recorded at alloc time (alloc_bt); the free side only
                // proves ownership (which buffer_type a pointer belongs to, exactly-once).
                for (auto& kv : keep) buf::free_bt(btp, kv.second, 0);
                for (auto& kv : keep_h) buf::free_bt(btp, kv.second, 0);
                for (auto& kv : keep_q) {
                    buf::free_bt(btp, kv.second.first, 0);
                    buf::free_bt(btp, kv.second.second, 0);
                }
            }
            for (auto& kv : temp) {
                kv.second->wait_and_throw();
                buf::BufferType* btp = kakasi::buffer_type_for_queue(kv.second);
                buf::free_bt(btp, kv.first, 0);
            }
            keep.clear(); keep_h.clear(); keep_q.clear(); temp.clear(); used = 0;
        }
    };
    Residency g_res;
    double g_dev_mem_gib = 0.0;
    double g_budget_override_gib = 0.0;   // >0 pins the residency budget (low-memory device)
    double g_residency_frac = 0.0;        // F648: >0 replaces the 0.55 resident share; 0 = default
    // Defined here, not next to the setters: this file's globals and residency planner live in the
    // anonymous namespace (23-1062), while the exported setters are in namespace model proper.
    // Declaring the getter only at namespace-model scope leaves the planner with an undefined
    // anonymous-namespace symbol and the link fails with LNK2019.
    double residency_frac() {
        return (g_residency_frac > 0.0 && g_residency_frac < 1.0) ? g_residency_frac : 0.55;
    }
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
    int g_spec_multiwin = 0;          // F320: widening was measured neutral while costing 3 wasted
                                      // tree nodes per pass, so it is off by default (gate toggle
                                      // model_spec_multiwin(1) re-enables it for measurement).
    int g_spec_selfseed = 0;          // F323 (FR-06.2 step 3): extra branches seeded from the
                                      // model's own root distribution instead of the text.
    int g_spec_fix = 1;               // F315 control: 0 disables the depth-position + compaction
                                      // repair inside the SAME build, so the defect can be shown
                                      // behaviourally instead of argued.
    // Device-side tree mask for the current verify pass; nullptr means plain causal attention.
    // Kept as module state so prefill_top1's external signature (and its mangled name) never
    // changes - the same reason g_head_rows is a global.
    const uint32_t* g_tree_mask = nullptr;
    // F359 continuous batching: a pass whose rows belong to DIFFERENT conversations. Every row
    // carries its own KV slot (where its K/V land), its own visible range (the cache rows it may
    // attend to) and its own absolute RoPE position, so two conversations can share ONE forward
    // pass without seeing each other's context. An empty plan means the plain single-session path.
    struct RowPlan {
        bool on = false;
        std::vector<int> slot;    // cache row each row writes to
        std::vector<int> lo;      // first cache row each row may attend to
        std::vector<int> hi;      // last cache row each row may attend to (inclusive)
        std::vector<int> abspos;  // absolute RoPE position of each row
        int head_rows = 1;         // how many rows need logits from the head
        // Device copies of slot/lo/hi. The kernels read them, and a host pointer is NOT
        // accessible from a device kernel: passing the host vectors made the first batched pass
        // fault inside the kernel, which surfaced as a catchable SYCL exception and then as a
        // crash in the next unrelated call.
        int* d_slot = nullptr;
        int* d_lo = nullptr;
        int* d_hi = nullptr;
    } g_plan;
    std::vector<std::vector<float>> g_row_logits;   // per-row logits of the last planned pass
    // The two batched conversations (F359). Each owns a contiguous KV region: session 0 lives in
    // rows [0, half), session 1 in [half, 2*half).
    // F373: the region table used to be exactly two conversations wide, which is also why no
    // measurement could ever say what a THIRD row costs - the claim that a step's fixed work is
    // shared by its rows can only be tested by changing the row count.
    const int kBatchSlotsMax = 8;
    struct BatchSess {
        bool active = false;
        bool done = false;
        int len = 0;              // tokens in the KV cache (rows owned)
        int base = 0;             // first cache row of this session's region
        int cap = 0;              // rows this conversation may use (prompt + what it generates)
        double temp = 0.0;        // sampling temperature of THIS conversation
        double top_p = 1.0;       // nucleus size of THIS conversation
        uint64_t rng = 12345;
        std::vector<float> logits;
        std::vector<int> allowed; // F683: non-empty ⇒ greedy argmax restricted to this set
        int last = -1;            // last token this session emitted (-1 after eos)
        // F684: chunked prefill state (prompt stored until fully prefilled, then cleared)
        std::vector<int> prompt;
        int prefill_cursor = 0;
        int prefill_backend = 0;
    } g_bs[kBatchSlotsMax];
    int g_bhalf = 0;              // rows per session region
    int g_bslots = 2;             // regions the batch layer was started with (F373)
    // Rows reserved for the single-session path. The batch's two regions start AFTER this area,
    // so a request that still needs the single-session path (a constrained pass in the parallel
    // server) cannot overwrite a live conversation's cache rows. It has to be larger than one
    // batch region, because the constrained pass adds its own tokens on top of the prompt.
    const int g_bstage = 8192;
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

    // F675 Stage 1: the memory classes this pass runs through. `dev_bt` resolves the
    // buffer_type that owns a queue - every device allocation and deallocation in this
    // file goes through it, so "who owns these bytes" is answerable from the ledger.
    // `host_bt` is the shared host staging class used by the boundary handoff.
    buf::BufferType* dev_bt(sycl::queue& q) { return kakasi::buffer_type_for_queue(&q); }
    buf::BufferType& host_bt() { return buf::host(); }

    // Dequantizes a whole tensor's raw bytes into a fresh device float buffer.
    float* dequant_device(sycl::queue& q, const std::vector<uint8_t>& raw, uint32_t dtype, uint64_t numel) {
        buf::BufferType* btp = dev_bt(q);
        uint8_t* dRaw = btp ? (uint8_t*) buf::alloc_bt(btp, raw.size()) : nullptr;
        float* dOut = dRaw ? (float*) buf::alloc_bt(btp, numel * sizeof(float)) : nullptr;
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
        buf::free_bt(btp, dRaw, raw.size());
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

    // Two rows in one matvec (F359). A batched decode step has M=2, and the tiled GEMM would
    // compute a full 16-row tile - 8x more MACs than the two rows need - which is why a batch of
    // two conversations used to cost as much as two separate forwards (F360). This kernel loads
    // each weight element ONCE and dots it into two accumulators, so the weight traffic (the
    // largest read of a decode step) is shared instead of doubled. The accumulation order per row
    // is the one gemv uses, so a batched row is bit-identical to the same row decoded alone.
    // F373: the two-row matvec generalized to MM rows. One work-group per OUTPUT row, MM
    // accumulators per lane, and exactly one weight read per (row, k) however many batch rows
    // share the step - that sharing is what batching a decode step is for, and it is the thing a
    // third and fourth row were supposed to pay for but never could, because the engine could not
    // make them. The k-partition and the butterfly reduction are unchanged from the two-row form,
    // so a batched row stays bit-identical to the same row decoded alone (BATCH-EQ asserts it).
    template <int MM>
    void gemvM(sycl::queue& q, const float* X, int K, Wt w, int N, float* Y, int ldy,
               const float* bias) {
        constexpr int TG = 64;
        q.submit([&](sycl::handler& h) {
            sycl::local_accessor<float, 2> red(sycl::range<2>(TG, MM), h);
            h.parallel_for(sycl::nd_range<1>((size_t) N * TG, TG), [=](sycl::nd_item<1> item) {
                const int row = (int) item.get_group(0);
                const int lane = (int) item.get_local_id(0);
                const size_t base = (size_t) row * (size_t) K;
                float a[MM];
                for (int m = 0; m < MM; ++m) a[m] = 0.0f;
                for (int k = lane; k < K; k += TG) {
                    const float wv = wat(w, base + (size_t) k);
                    for (int m = 0; m < MM; ++m) {
                        a[m] += X[(size_t) m * (size_t) K + (size_t) k] * wv;
                    }
                }
                for (int m = 0; m < MM; ++m) red[lane][m] = a[m];
                item.barrier(sycl::access::fence_space::local_space);
                for (int s = TG / 2; s > 0; s >>= 1) {
                    if (lane < s) {
                        for (int m = 0; m < MM; ++m) red[lane][m] += red[lane + s][m];
                    }
                    item.barrier(sycl::access::fence_space::local_space);
                }
                if (lane == 0) {
                    const float bs = bias ? bias[row] : 0.0f;
                    for (int m = 0; m < MM; ++m) {
                        Y[(size_t) m * (size_t) ldy + (size_t) row] = red[0][m] + bs;
                    }
                }
            });
        });
    }

    void gemm(sycl::queue& q, const float* X, int M, int K, Wt w, int N, float* Y,
              const float* bias, int ldy = 0) {
        // Decode (M=1) goes to the matvec path; a two-row batch step goes to the shared matvec
        // path (F359/F360); prefill keeps the tiled GEMM, which is the right choice once M is
        // large. Either reads any of the three weight precisions via wat().
        if (M == 1) { gemv(q, X, K, w, N, Y, bias); return; }
        // F373: up to four rows take the shared-weight matvec; past that the tiled GEMM is the
        // better shape anyway, and the batch layer cannot produce more rows than it has regions.
        if (M == 2) { gemvM<2>(q, X, K, w, N, Y, ldy > 0 ? ldy : N, bias); return; }
        if (M == 3) { gemvM<3>(q, X, K, w, N, Y, ldy > 0 ? ldy : N, bias); return; }
        if (M == 4) { gemvM<4>(q, X, K, w, N, Y, ldy > 0 ? ldy : N, bias); return; }
        // ldy (optional) is the distance between output rows: it lets one GEMM write several rows
        // of a wide output (the batched head writes nOut rows of `rows` logits each).
        const size_t stride = ldy > 0 ? (size_t) ldy : (size_t) N;
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
                    Y[(size_t) row * stride + col] = acc;
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

    // Same store, but each row picks its own destination row (F359): a batched pass writes the
    // rows of two conversations into their own regions of one cache, so the destination of row t
    // is slots[t] rather than pos+t.
    template <typename KVt>
    void kv_store_slots(sycl::queue& q, const float* src, KVt* dst, int T, int dkv, const int* slots) {
        const size_t n = (size_t) T * dkv;
        q.parallel_for(sycl::range<1>(n), [=](sycl::item<1> it) {
            const size_t i = it.get_id(0);
            const size_t t = i / (size_t) dkv;
            const size_t c = i % (size_t) dkv;
            dst[(size_t) slots[t] * (size_t) dkv + c] = (KVt) src[i];
        });
    }

    void plan_free(sycl::queue& q);

    // Upload the row plan to the device (F359). The kernels read slot/lo/hi, and only DEVICE
    // memory is readable from a kernel: the host vectors caused a fault inside the first batched
    // pass, which the forward caught as a SYCL exception - the batch then produced nothing and
    // the queue was left in an error state that crashed the next unrelated call.
    bool plan_upload(sycl::queue& q) {
        const size_t n = g_plan.slot.size();
        if (n == 0) return false;
        buf::BufferType* btp = dev_bt(q);
        g_plan.d_slot = btp ? (int*) buf::alloc_bt(btp, n * sizeof(int)) : nullptr;
        g_plan.d_lo = g_plan.d_slot ? (int*) buf::alloc_bt(btp, n * sizeof(int)) : nullptr;
        g_plan.d_hi = g_plan.d_lo ? (int*) buf::alloc_bt(btp, n * sizeof(int)) : nullptr;
        if (!g_plan.d_slot || !g_plan.d_lo || !g_plan.d_hi) { plan_free(q); return false; }
        q.memcpy(g_plan.d_slot, g_plan.slot.data(), n * sizeof(int));
        q.memcpy(g_plan.d_lo, g_plan.lo.data(), n * sizeof(int));
        q.memcpy(g_plan.d_hi, g_plan.hi.data(), n * sizeof(int));
        q.wait_and_throw();
        return true;
    }

    void plan_free(sycl::queue& q) {
        buf::BufferType* btp = dev_bt(q);
        if (g_plan.d_slot) { buf::free_bt(btp, g_plan.d_slot, (size_t) g_plan.slot.size() * sizeof(int)); g_plan.d_slot = nullptr; }
        if (g_plan.d_lo) { buf::free_bt(btp, g_plan.d_lo, (size_t) g_plan.lo.size() * sizeof(int)); g_plan.d_lo = nullptr; }
        if (g_plan.d_hi) { buf::free_bt(btp, g_plan.d_hi, (size_t) g_plan.hi.size() * sizeof(int)); g_plan.d_hi = nullptr; }
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
        buf::BufferType* btp = dev_bt(q);
        std::vector<char*> nk;
        std::vector<char*> nv;
        for (int l = 0; l < layers; ++l) {
            char* pk = btp ? (char*) buf::alloc_bt(btp, bytes) : nullptr;
            char* pv = pk ? (char*) buf::alloc_bt(btp, bytes) : nullptr;
            nk.push_back(pk);
            nv.push_back(pv);
            if (!pk || !pv) return false;
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
    //
    // `lo`/`hi` (optional) give every row its own visible RANGE of cache rows (F359). The plain
    // path is lo=0, hi=pos+b; a batched pass sets them per row so each row sees only the rows of
    // its own conversation. The two mechanisms are exclusive: a tree pass has no ranges.
    template <typename KVt>
    // F365: the attention used to walk the visible KV range THREE times and recompute the same
    // dot product in every pass - once for the max, once for the softmax sum and once for the
    // weighted sum of V. That cost is linear in the context length, and the measurement (F364:
    // the served per-token cost grows 0.35-0.42ms per token of context, and the sweep showed it
    // is s1 - the 24 blocks - that grows) put ~25ms of a 60-token step inside it. The scores are
    // now computed ONCE into `scores` and reused, which leaves the arithmetic bit-for-bit
    // identical (same values in the same order) and cuts the K reads and the MACs to a third.
    // `scoreRows` is the length of the visible range, so the scratch holds T*NH*scoreRows floats.
    void attention(sycl::queue& q, const float* Q, const KVt* K, const KVt* V, int T, int pos, int NH, int NKV,
                   int HD, float scale, float* O, float* scores, int scoreRows,
                   const uint32_t* tree = nullptr,
                   const int* lo = nullptr, const int* hi = nullptr) {
        // F365 A/B: mode 0 computes each score once and reuses it; mode 1 recomputes it in every
        // pass (the pre-F365 kernel); modes 4 and 5 are the F367 timing-only ablations below.
        // The branch is uniform across the work items, so it costs one predicate per
        // (token, head) and nothing per iteration.
        const int attnMode = g_attn_mode;
        const bool ablateV = (attnMode == 4);    // V accumulator loop -> one global RMW per position
        const bool ablateDot = (attnMode == 5);  // dot chain -> arithmetic, no K read
        q.parallel_for(sycl::range<2>(T, NH), [=](sycl::item<2> it) {
            const int b = (int) it.get_id(0);
            const int h = (int) it.get_id(1);
            const int kv = h * NKV / NH;
            const float* qrow = Q + ((size_t) b * NH + h) * HD;
            const int last = hi ? hi[b] : (pos + b);
            const int first = lo ? lo[b] : 0;
            const bool masked = (tree != nullptr);
            const uint32_t allow = masked ? tree[b] : 0u;
            float* srow = scores + ((size_t) b * NH + h) * (size_t) scoreRows;
            float* orow = O + ((size_t) b * NH + h) * HD;
            float mx = -1e30f;
            float sum = 0.0f;
            if (attnMode == 1) {
                for (int j = first; j <= last; ++j) {
                    if (masked && j >= pos && !((allow >> (j - pos)) & 1u)) continue;
                    const KVt* krow = K + ((size_t) j * NKV + kv) * HD;
                    float s = 0.0f;
                    for (int d = 0; d < HD; ++d) s += qrow[d] * (float) krow[d];
                    s *= scale;
                    mx = sycl::max(mx, s);
                }
                for (int j = first; j <= last; ++j) {
                    if (masked && j >= pos && !((allow >> (j - pos)) & 1u)) continue;
                    const KVt* krow = K + ((size_t) j * NKV + kv) * HD;
                    float s = 0.0f;
                    for (int d = 0; d < HD; ++d) s += qrow[d] * (float) krow[d];
                    sum += sycl::exp(s * scale - mx);
                }
                for (int d = 0; d < HD; ++d) orow[d] = 0.0f;
                for (int j = first; j <= last; ++j) {
                    if (masked && j >= pos && !((allow >> (j - pos)) & 1u)) continue;
                    const KVt* krow = K + ((size_t) j * NKV + kv) * HD;
                    const KVt* vrow = V + ((size_t) j * NKV + kv) * HD;
                    float s = 0.0f;
                    for (int d = 0; d < HD; ++d) s += qrow[d] * (float) krow[d];
                    const float w = sycl::exp(s * scale - mx) / sum;
                    for (int d = 0; d < HD; ++d) orow[d] += w * (float) vrow[d];
                }
                return;
            }
            for (int j = first; j <= last; ++j) {
                if (masked && j >= pos && !((allow >> (j - pos)) & 1u)) continue;
                float s = 0.0f;
                if (!ablateDot) {
                    const KVt* krow = K + ((size_t) j * NKV + kv) * HD;
                    for (int d = 0; d < HD; ++d) s += qrow[d] * (float) krow[d];
                } else {
                    // F367 ablation (timing only, the numbers it makes are meaningless): drop the
                    // 64-long dependent FMA chain and the K load, keep everything else - the store
                    // to the scratch, the max, the exp in the two later loops.
                    s = (float) (j - first) * 0.125f;
                }
                s *= scale;
                srow[j - first] = s;
                mx = sycl::max(mx, s);
            }
            for (int j = first; j <= last; ++j) {
                if (masked && j >= pos && !((allow >> (j - pos)) & 1u)) continue;
                sum += sycl::exp(srow[j - first] - mx);
            }
            for (int d = 0; d < HD; ++d) orow[d] = 0.0f;
            for (int j = first; j <= last; ++j) {
                if (masked && j >= pos && !((allow >> (j - pos)) & 1u)) continue;
                const KVt* vrow = V + ((size_t) j * NKV + kv) * HD;
                const float w = sycl::exp(srow[j - first] - mx) / sum;
                if (!ablateV) {
                    for (int d = 0; d < HD; ++d) orow[d] += w * (float) vrow[d];
                } else {
                    // F367 ablation (timing only): keep the V read and the weight, collapse the
                    // 64 global read-modify-writes per position into one. This is the loop that
                    // cannot live in registers because HD is a runtime value.
                    orow[0] += w * (float) vrow[0];
                }
            }
        });
    }

    // F366: split-KV attention (mode 2, off by default). The kernel above gives each
    // (token, head) exactly ONE work item, so a decode step runs NH=14 of them and the whole
    // visible KV range is a serial loop inside each - the attention is bound by that latency,
    // not by bandwidth or FLOPs (the score cache of F365 cut the work by 3x and the length slope
    // only fell 42%, which is the signature of a latency-bound loop). Here the range is cut into
    // P chunks computed by P separate work items, each keeping its own running max, denominator
    // and unnormalized weighted-V accumulator, and a second, tiny kernel folds the P partials
    // together. Same sum, different grouping, so the result is only equal up to reassociation -
    // that is what the self-test's ATTN-SPLIT check measures rather than assumes.
    template <typename KVt>
    void attention_split(sycl::queue& q, const float* Q, const KVt* K, const KVt* V,
                         int T, int pos, int NH, int NKV, int HD, float scale, float* O,
                         float* part, int P, int partStride, const uint32_t* tree = nullptr,
                         const int* lo = nullptr, const int* hi = nullptr) {
        q.parallel_for(sycl::range<3>(T, NH, P), [=](sycl::item<3> it) {
            const int b = (int) it.get_id(0);
            const int h = (int) it.get_id(1);
            const int p = (int) it.get_id(2);
            const int kv = h * NKV / NH;
            const float* qrow = Q + ((size_t) b * NH + h) * HD;
            const int last = hi ? hi[b] : (pos + b);
            const int first = lo ? lo[b] : 0;
            const int len = last - first + 1;
            const int chunk = (len + P - 1) / P;
            const int j0 = first + p * chunk;
            const int j1 = sycl::min(last, j0 + chunk - 1);
            const bool masked = (tree != nullptr);
            const uint32_t allow = masked ? tree[b] : 0u;
            float* prow = part + ((size_t) b * NH + h) * (size_t) P * partStride
                                + (size_t) p * partStride;
            float* ov = prow + 2;
            float mx = -1e30f;
            float sum = 0.0f;
            for (int d = 0; d < HD; ++d) ov[d] = 0.0f;
            for (int j = j0; j <= j1; ++j) {
                if (masked && j >= pos && !((allow >> (j - pos)) & 1u)) continue;
                const KVt* krow = K + ((size_t) j * NKV + kv) * HD;
                const KVt* vrow = V + ((size_t) j * NKV + kv) * HD;
                float s = 0.0f;
                for (int d = 0; d < HD; ++d) s += qrow[d] * (float) krow[d];
                const float sc = s * scale;
                if (sc > mx) {
                    // online softmax: rescale what has already been accumulated, then fold j in.
                    const float r = sycl::exp(mx - sc);
                    sum *= r;
                    for (int d = 0; d < HD; ++d) ov[d] *= r;
                    mx = sc;
                }
                const float w = sycl::exp(sc - mx);
                sum += w;
                for (int d = 0; d < HD; ++d) ov[d] += w * (float) vrow[d];
            }
            prow[0] = mx;
            prow[1] = sum;
        });
        q.parallel_for(sycl::range<2>(T, NH), [=](sycl::item<2> it) {
            const int b = (int) it.get_id(0);
            const int h = (int) it.get_id(1);
            const float* prow = part + ((size_t) b * NH + h) * (size_t) P * partStride;
            float M = -1e30f;
            for (int p = 0; p < P; ++p) M = sycl::max(M, prow[(size_t) p * partStride]);
            float S = 0.0f;
            for (int p = 0; p < P; ++p) {
                const float mx = prow[(size_t) p * partStride];
                if (mx <= -1e29f) continue;   // an empty chunk contributes nothing
                S += prow[(size_t) p * partStride + 1] * sycl::exp(mx - M);
            }
            float* orow = O + ((size_t) b * NH + h) * HD;
            for (int d = 0; d < HD; ++d) orow[d] = 0.0f;
            for (int p = 0; p < P; ++p) {
                const float mx = prow[(size_t) p * partStride];
                if (mx <= -1e29f || S == 0.0f) continue;
                const float f = sycl::exp(mx - M) / S;
                const float* ov = prow + (size_t) p * partStride + 2;
                for (int d = 0; d < HD; ++d) orow[d] += f * ov[d];
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
        // Residency keeps a single device's footprint, so it is bypassed while ANY split is
        // active (dedicated two-device or the generic N-segment one): weights for a layer on
        // a second device must stream on THAT device, and a tensor kept on the primary device
        // must never be handed to a kernel running elsewhere.
        const bool keepable = g_res.enabled && g_split_layer < 0 && !g_seg_active &&
                              g_keep_plan.find(idx) != g_keep_plan.end();
        if (keepable && need > (1u << 20)) {
            if (g_wprec == 1) {
                const uint64_t nb = (numel + 63u) / 64u;
                buf::BufferType* btp = dev_bt(q);
                int8_t* pq = btp ? (int8_t*) buf::alloc_bt(btp, numel) : nullptr;
                float* ps = pq ? (float*) buf::alloc_bt(btp, nb * sizeof(float)) : nullptr;
                to_int8(q, p, pq, ps, numel);
                buf::free_bt(btp, p, numel * sizeof(float));
                g_res.keep_q[idx] = {pq, ps};
                g_res.used += (size_t) numel + (size_t) nb * sizeof(float);
                return {pq, ps, 2};
            }
            buf::BufferType* btp2 = dev_bt(q);
            sycl::half* ph = btp2 ? (sycl::half*) buf::alloc_bt(btp2, numel * sizeof(sycl::half)) : nullptr;
            to_half(q, p, ph, numel);
            buf::free_bt(btp2, p, numel * sizeof(float));
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
        buf::BufferType* btp = kakasi::buffer_type_for_queue(it->second);
        buf::free_bt(btp, p, 0);
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
        // F648: the default share is a knob now (vendor profiles), and the absolute GiB override
        // still wins, so the two ways of shrinking residency keep one precedence order.
        const double frac = g_budget_override_gib > 0.0
            ? (double) (g_budget_override_gib * (double) (1ull << 30)) / (double) total
            : residency_frac();
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
            buf::BufferType* btp = dev_bt(q);
            float* p = btp ? (float*) buf::alloc_bt(btp, count * sizeof(float)) : nullptr;
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
            buf::BufferType* btp = dev_bt(q);
            a.blocks.push_back(btp ? (float*) buf::alloc_bt(btp, Arena::BLK * sizeof(float)) : nullptr);
        }
        a.used = off + count;
        return a.blocks[off / Arena::BLK] + (off % Arena::BLK);
    }

    // Only valid right after a queue drain: every kernel that touched these slots has finished.
    void arena_reset(sycl::queue& q) {
        Arena& a = g_arena[(const void*) &q];
        a.used = 0;
        buf::BufferType* btp = dev_bt(q);
        for (float* p : a.oversized) buf::free_bt(btp, p, 0);
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
            if (own) {
                buf::BufferType* btp = dev_bt(*qq);
                p = btp ? (float*) buf::alloc_bt(btp, count * sizeof(float)) : nullptr;
            } else {
                p = arena_acquire(*qq, count);
            }
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
        ~Dev() { if (owned && p && q) buf::free_bt(dev_bt(*q), p, n * sizeof(float)); }
        Dev(Dev&& o) noexcept : p(o.p), n(o.n), q(o.q), owned(o.owned) { o.p = nullptr; }
        Dev& operator=(Dev&& o) noexcept {
            if (this != &o) {
                if (owned && p && q) buf::free_bt(dev_bt(*q), p, n * sizeof(float));
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
        buf::free_bt(dev_bt(q), tmp, (size_t) D * sizeof(float));
        return true;
    }
}  // namespace

std::string last_error() { return g_err; }

std::string layer_placement(int layers, int primary, int secondary, int split_layer) {
    const LayerPlacementPlan plan = make_layer_placement_plan(layers, primary, secondary, split_layer);
    std::string out;
    for (size_t i = 0; i < plan.backend.size(); ++i) {
        if (i != 0) out += ",";
        out += std::to_string(plan.backend[i]);
    }
    out += "|handoff=" + std::to_string(plan.handoff_layer);
    return out;
}

// F675 Stage 1: one-line "who owns which bytes" report from the shared allocation ledger.
// "b0:3.1MiB|host:0.0MiB" - a byte class with no live allocations is still listed because
// every alloc registers its buffer_type, and " anomalies=N" appears only when a pointer was
// freed from the wrong class or twice - the release-discipline error the split run invited.
std::string buffer_report() {
    std::string r = buf::ledger().report();
    char head[32];
    std::snprintf(head, sizeof(head), "anomalies=%d ", buf::ledger().anomaly_count());
    return std::string(head) + r;
}

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
        ? g_budget_override_gib * KiB2 : (double) total * residency_frac();
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

// F648: fraction of device memory the residency planner may keep weights in. Out-of-range or 0
// restores the shipped 0.55. Like the other residency knobs it drops the plan so the next pass
// rebuilds under the new share; only ever called from vendor-profile application at start-up.
void set_residency_frac(double f) {
    g_residency_frac = (f > 0.0 && f < 1.0) ? f : 0.0;
    if (g_res.q) { g_res.clear_all(); g_res.q = nullptr; g_res.enabled = false; }
}

// Exported accessor for the effective share. The getter itself has to live in the anonymous
// namespace because that is where the planner reads it, so this model-scope wrapper is what
// php_model_residency_frac() calls; unqualified lookup finds the anonymous one in this TU.
double residency_frac_effective() { return residency_frac(); }

double get_residency_frac() { return g_residency_frac; }

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

// F365/F366 A/B switch: 0 = score cache, 1 = the pre-F365 three-pass kernel, 2 = split-KV.
// 0 and 1 compute the same values in the same order; 2 groups the same sum differently, so it
// is checked by the greedy token rather than by bit-equality.
void set_attn_mode(int m) { g_attn_mode = (m >= 0 && m <= 5) ? m : 2; }
int attn_mode() { return g_attn_mode; }
int attn_split_p() { return g_split_p; }

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
void set_split(int backend, int layer);  // defined below; set_seg_split's two-segment shape re-enters it

// F675 Stage 2: apply an N-segment placement. The string is "b:from|b:from|..." - each
// segment names a backend and the first layer it owns; segments must be contiguous, in
// ascending order, and cover [0, L). The exact "b0:[0,s) + b1:[s,L)" two-segment shape
// re-routes to the dedicated g_split fast path; everything else takes the generic path
// (g_seg_active), which allocates its own per-segment KV/rope/scratch each pass.
// Returns 1 on success (including the no-op single-segment split), 0 on a malformed
// string, an uncovered gap, or a segment on an unavailable device.
// Per-segment KV: dense like the dedicated second-device cache - every non-primary
// segment owns the full [0, L) row set so the layer loop's dense `kv.k[l]` indexing
// stays uniform. Attention over rows a segment does not own reads zeroed scratch on
// a fresh prefix and its own stored rows afterwards.
bool ensure_seg_kv(SegKvSlot& slot, int L, sycl::queue& q, int need_rows, int dkv,
                   int keep_rows, bool f16) {
    if (slot.kv.backend == slot.backend && slot.kv.cap >= need_rows
        && slot.kv.f16 == f16 && (int) slot.kv.k.size() == L) return true;
    if (keep_rows > slot.kv.cap || slot.kv.f16 != f16) keep_rows = 0;
    const int cap = std::max(need_rows, KV_MIN_CAP);
    const size_t bytes = (size_t) cap * (size_t) dkv * (f16 ? sizeof(sycl::half) : sizeof(float));
    buf::BufferType* btp = kakasi::buffer_type_for_queue(&q);
    if (!btp) return false;
    std::vector<void*> nk, nv;
    for (int l = 0; l < L; ++l) {
        char* pk = (char*) buf::alloc_bt(btp, bytes);
        char* pv = pk ? (char*) buf::alloc_bt(btp, bytes) : nullptr;
        if (!pk || !pv) {
            for (void* pp : nk) if (pp) buf::free_bt(btp, pp, bytes);
            for (void* pp : nv) if (pp) buf::free_bt(btp, pp, bytes);
            return false;
        }
        nk.push_back((void*) pk); nv.push_back((void*) pv);
    }
    if (keep_rows > 0 && (int) slot.kv.k.size() == L) {
        const size_t keep = (size_t) keep_rows * (size_t) dkv *
            (slot.kv.f16 ? sizeof(sycl::half) : sizeof(float));
        for (int l = 0; l < L; ++l) {
            q.memcpy(nk[(size_t) l], slot.kv.k[(size_t) l], keep);
            q.memcpy(nv[(size_t) l], slot.kv.v[(size_t) l], keep);
        }
        q.wait_and_throw();
    }
    slot.kv.free_all();
    slot.kv.q = &q; slot.kv.backend = slot.backend; slot.kv.cap = cap; slot.kv.len = keep_rows;
    slot.kv.dkv = dkv; slot.kv.f16 = f16;
    slot.kv.k = std::move(nk); slot.kv.v = std::move(nv);
    return true;
}


void seg_split_release() {
    g_graph.nodes.clear();
    g_graph.copies = 0;
    for (auto& slot : g_seg_kv) {
        slot.kv.free_all();
    }
    g_seg_kv.clear();
    for (auto& sg : g_seg) {
        buf::BufferType* btp = sg.q ? kakasi::buffer_type_for_queue(sg.q) : nullptr;
        sg.free_scratch(btp);
        if (btp) {
            if (sg.xs) { buf::free_bt(btp, sg.xs, 0); sg.xs = nullptr; }
            if (sg.hs) { buf::free_bt(btp, sg.hs, 0); sg.hs = nullptr; }
        }
        sg.q = nullptr; sg.backend = -1; sg.kv_layers = 0;
    }
    g_seg.clear();
}

int set_seg_split(const std::string& spec, int L) {
    if (L <= 0) return 0;
    std::vector<std::pair<int, int>> segs;
    size_t i = 0;
    while (i < spec.size()) {
        size_t bar = spec.find('|', i);
        std::string tok = spec.substr(i, bar == std::string::npos ? std::string::npos : bar - i);
        if (tok.empty()) return 0;
        size_t col = tok.find(':');
        if (col == std::string::npos || col == 0 || col + 1 >= tok.size()) return 0;
        int b, from;
        try {
            b = std::stoi(tok.substr(0, col));
            from = std::stoi(tok.substr(col + 1));
        } catch (...) { return 0; }
        if (b < 0 || from < 0 || from > L) return 0;
        segs.push_back({b, from});
        i = (bar == std::string::npos) ? spec.size() : bar + 1;
    }
    if (segs.empty() || segs.size() > 8) return 0;   // more segments than devices can own
    if (segs.front().second != 0) return 0;
    for (size_t k = 1; k < segs.size(); ++k) {
        if (segs[k].second <= segs[k - 1].second) return 0;   // empty / backwards
    }
    if (segs.back().second >= L) return 0;                    // last segment empty
    for (const auto& sg : segs) {
        if (!kakasi::queue_for(sg.first)) { g_err = "segment backend " + std::to_string(sg.first)
                                                 + " unavailable"; return 0; }
    }
    // The dedicated two-device fast path: first segment on b0, exactly one other
    // backend after it. Everything else is generic.
    if (segs.size() == 2 && segs[0].first == 0) {
        set_split(segs[1].first, segs[1].second);   // releases generic state, arms fast path
        return 1;
    }
    seg_split_release();
    g_split_backend = -1;
    g_split_layer = -1;
    g_res.clear_all(); g_res.q = nullptr; g_res.enabled = false;
    g_kv.free_all();
    g_kv2.free_all();
    g_seg_active = true;
    g_seg_split = SegmentSplit{};   // F681: resets the FFN-override fields too
    g_seg_kv.clear();
    for (const auto& sg : segs) {
        g_seg_split.backend.push_back(sg.first);
        g_seg_split.start.push_back(sg.second);
        SegKvSlot slot;
        slot.backend = sg.first;
        g_seg_kv.push_back(slot);
    }
    g_graph.build(L, g_seg_split);
    return 1;
}

// F681: op-granular placement - the FFN op of ONE layer runs on its own device while
// everything else stays on the primary. The two copies around that op are the
// TENSOR-granular boundary copies: the running hidden state crosses devices at op
// granularity, not only at segment boundaries. Mutually exclusive with the N-segment
// split (arming one releases the other); released by set_op_split(-1, -1).
int set_op_split(int layer, int backend) {
    set_split(-1, -1);   // releases the fast path AND the segment state
    if (layer < 0 || backend < 0) {
        return 1;   // release-only call
    }
    const int L = (int) gguf::meta_num("qwen2.block_count");
    if (layer >= L) {
        g_err = "op split layer " + std::to_string(layer) + " out of range";
        return 0;
    }
    if (backend == 0) {
        g_err = "op split backend must differ from the primary device";
        return 0;
    }
    if (!kakasi::queue_for(backend)) {
        g_err = "op split backend " + std::to_string(backend) + " unavailable";
        return 0;
    }
    g_res.clear_all(); g_res.q = nullptr; g_res.enabled = false;
    g_kv.free_all();
    g_kv2.free_all();
    g_seg_active = true;
    g_seg_split = SegmentSplit{};
    // Segment 0 owns every layer's ATTN (and every un-overridden FFN); segment 1 owns
    // no layers - it exists only to host the overridden FFN op and its scratch.
    g_seg_split.backend.push_back(0);
    g_seg_split.backend.push_back(backend);
    g_seg_split.start.push_back(0);
    g_seg_split.start.push_back(L);
    g_seg_split.ffnSegLayer = layer;
    g_seg_split.ffnSeg = 1;
    SegKvSlot slot;
    slot.backend = backend;
    g_seg_kv.push_back(slot);
    g_graph.build(L, g_seg_split);
    return 1;
}

std::string op_split_state() {
    if (!g_seg_active || g_seg_split.ffnSegLayer < 0) return "op split off";
    return "op split layer=" + std::to_string(g_seg_split.ffnSegLayer)
         + " ffn=b" + std::to_string(g_seg_split.backend[(size_t) g_seg_split.ffnSeg]);
}

std::string seg_split_state() {
    if (!g_seg_active) return "seg off";
    std::string out;
    for (size_t k = 0; k < g_seg_split.backend.size(); ++k) {
        if (k) out += "|";
        out += "b" + std::to_string(g_seg_split.backend[k])
             + ":" + std::to_string(g_seg_split.start[k]);
    }
    return out;
}

// F675 Stage 3: the explicit compute graph, built from the active N-segment split.
std::string graph_report() {
    if (g_graph.nodes.empty()) return "graph off";
    return g_graph.report() + " plan=" + seg_split_state();
}
int graph_exec_topo() { return g_graphExecTopo ? 1 : 0; }

// F678: the backend op dispatch table. Every device declares the kernel variants the model
// layer may dispatch on its queue - the ggml-style "what can this backend run" contract,
// declared per device instead of hardcoded. The table is the single source of truth the
// forward loop checks against before executing: an op the table does not list for a
// backend may not be dispatched on that backend.
enum OpKind {
    DEQUANT = 0, GEMM, RMS_NORM, ROPE, KV_STORE,
    ATTENTION, SPLIT_KV, SILU_MUL, RESIDUAL, LM_HEAD, kOps
};
struct OpTable {
    int nBackends = 0;
    std::vector<std::vector<int>> ops;     // [backend][OpKind]
    bool has(int backend, int kind) const {
        return backend >= 0 && backend < (int) ops.size() && kind >= 0
            && kind < (int) ops[(size_t) backend].size() && ops[(size_t) backend][(size_t) kind];
    }
};
OpTable g_ops;
void build_ops_table() {
    const int N = kakasi::device_count();
    g_ops.nBackends = N;
    g_ops.ops.assign((size_t) N > 0 ? (size_t) N : 1, std::vector<int>(kOps, 0));
    for (int b = 0; b < N; ++b) {
        g_ops.ops[(size_t) b][OpKind::DEQUANT] = 1;
        g_ops.ops[(size_t) b][OpKind::GEMM] = 1;
        g_ops.ops[(size_t) b][OpKind::RMS_NORM] = 1;
        g_ops.ops[(size_t) b][OpKind::ROPE] = 1;
        g_ops.ops[(size_t) b][OpKind::KV_STORE] = 1;
        g_ops.ops[(size_t) b][OpKind::ATTENTION] = 1;
        g_ops.ops[(size_t) b][OpKind::SILU_MUL] = 1;
        g_ops.ops[(size_t) b][OpKind::RESIDUAL] = 1;
        g_ops.ops[(size_t) b][OpKind::LM_HEAD] = 1;
        // splitK (F366/F368) is a device CAPABILITY, not a model requirement: every
        // validated SYCL queue can run the split-KV kernel as long as a partial buffer
        // exists. The runtime KNOB that selects it (g_attn_mode) is checked separately,
        // against this entry - capability table plus runtime policy.
        g_ops.ops[(size_t) b][OpKind::SPLIT_KV] = 1;
    }
}

std::string ops_table_report() {
    if (g_ops.nBackends == 0) return "ops off";
    const char* names[kOps] = {"dequant", "gemm", "rmsnorm", "rope", "kv_store",
                                "attn", "split_k", "silu", "residual", "lm_head"};
    std::string out;
    for (int b = 0; b < g_ops.nBackends; ++b) {
        if (b) out += " ";
        out += "b" + std::to_string(b) + "=[";
        for (int k = 0; k < kOps; ++k) {
            if (g_ops.ops[(size_t) b][(size_t) k]) {
                if (out[out.size() - 1] != '[') out += "+";
                out += names[k];
            }
        }
        out += "]";
    }
    return out;
}


void set_split(int backend, int layer) {
    seg_split_release();
    g_seg_active = false;
    g_seg_split = SegmentSplit{};
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
    // F678: the op dispatch table is (re)built whenever the split state changes - a new
    // secondary backend must declare its own op set before the graph is armed on it.
    build_ops_table();
    // F676: the fast path declares the same explicit graph the generic path does, so the
    // loop consumes it (placement plan and graph must agree at every layer).
    const int L = (int) gguf::meta_num("qwen2.block_count");
    g_graph.nodes.clear();
    g_graph.copies = 0;
    if (g_split_backend >= 0 && g_split_layer >= 0 && g_split_layer < L) {
        SegmentSplit fast;
        fast.backend.push_back(0);
        fast.backend.push_back(g_split_backend);
        fast.start.push_back(0);
        fast.start.push_back(g_split_layer);
        g_graph.build(L, fast);
    }
}

std::string split_state() {
    const int layers = (int) gguf::meta_num("qwen2.block_count");
    const LayerPlacementPlan plan = make_layer_placement_plan(layers, 0, g_split_backend,
                                                               g_split_layer);
    if (plan.secondary_backend < 0) return "split off";
    char buf[160];
    std::snprintf(buf, sizeof(buf), "split primary=b0 secondary=b%d layers=[%d,%d)",
                  plan.secondary_backend, plan.handoff_layer, layers);
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
int sample_from(const std::vector<float>& lg, double temp, double top_p, uint64_t& rng, int* nucleus_out);
int sample_next(double temp, double top_p, uint64_t& rng, int* nucleus_out) {
    return sample_from(g_last_logits, temp, top_p, rng, nucleus_out);
}

// One vocabulary entry for the sampler: the probability mass and the token it belongs to.
struct SmpPair {
    double p;
    int id;
};

// Descending by mass, ties broken by id so the order is total and reproducible.
inline bool smp_by_prob_desc(const SmpPair& a, const SmpPair& b) {
    if (a.p != b.p) return a.p > b.p;
    return a.id < b.id;
}

// Picks the token whose descending cumulative interval contains u (mass scale, not normalised).
//
// The old sampler sorted the WHOLE vocabulary for every token, which the served measurement put
// at ~15ms per token per stream (F361) - and with top_p=1.0 the nucleus IS the whole
// vocabulary, so the sorted order was only ever needed to walk a cumulative. This finds the
// crossing token with nth_element instead: the range is partitioned so that the upper half is
// known to be the largest entries, its mass is summed, and the search continues in whichever
// half contains u. The mass accounting is exact, so the token that comes out has the same
// probability as the one the sorted walk would have produced.
//
// `acc` is the mass already known to sit ABOVE the range; `u` is the draw in the same units.
int smp_select(std::vector<SmpPair>& v, int lo, int hi, double acc, double u) {
    while (hi - lo > 1) {
        // The upper half must be a proper subset of the range, so the pivot is placed one
        // below the midpoint: with hi-lo == 2 the group is the single largest entry.
        const int mid = lo + (hi - lo - 1) / 2;
        std::nth_element(v.begin() + lo, v.begin() + mid, v.begin() + hi, smp_by_prob_desc);
        double top = 0.0;
        for (int i = lo; i <= mid; ++i) top += v[(size_t) i].p;
        if (u < acc + top) {
            hi = mid + 1;      // the crossing is inside the upper half
        } else {
            acc += top;        // the upper half sits above the crossing
            lo = mid + 1;
        }
    }
    return v[(size_t) lo].id;
}

// The same sampler over an explicit set of logits (F359): a batched pass has one logit vector
// per conversation, and each conversation samples from its own.
int sample_from(const std::vector<float>& lg, double temp, double top_p, uint64_t& rng, int* nucleus_out) {
    const int n = (int) lg.size();
    if (n == 0) { g_err = "sample requested with no logits"; return -1; }
    int argmax = 0;
    float mx = lg[0];
    for (int i = 1; i < n; ++i) {
        if (lg[i] > mx) { mx = lg[i]; argmax = i; }
    }
    if (temp <= 0.0) {
        if (nucleus_out) *nucleus_out = 1;
        return argmax;
    }

    // F363: with top_p == 1.0 the nucleus IS the whole vocabulary, so the sorted order was only
    // ever needed to walk a cumulative. smp_select finds the same crossing token without
    // sorting, which is the served hot path (the server's default top_p is 1.0).
    const bool nucleus_cut = (top_p > 0.0 && top_p < 1.0);
    if (!nucleus_cut) {
        // The engine is single-threaded, so one reused buffer is enough: the old code allocated
        // two 151936-entry vectors on every single token.
        static std::vector<SmpPair> buf;
        buf.clear();
        buf.resize((size_t) n);
        double z = 0.0;
        for (int i = 0; i < n; ++i) {
            const double v = std::exp(((double) lg[i] - (double) mx) / temp);
            buf[(size_t) i].p = v;
            buf[(size_t) i].id = i;
            z += v;
        }
        if (!(z > 0.0)) { if (nucleus_out) *nucleus_out = 1; return argmax; }
        if (nucleus_out) *nucleus_out = n;
        const double u = next_u64_unit(rng) * z;
        return smp_select(buf, 0, n, 0.0, u);
    }

    std::vector<double> p((size_t) n);
    double z = 0.0;
    for (int i = 0; i < n; ++i) {
        const double v = std::exp(((double) lg[i] - (double) mx) / temp);
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
    const double target = top_p;
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

// F363: the sort-free sampler must return the same token as the sorted walk for the same draw
// on the model's real logits, and the whole vocabulary must stay reachable. The standalone probe
// that motivated this had a weak distribution check and an off-by-one in its partition range, so
// the proof lives here, in the engine, against the real logits.
//
// Returns "err=none draws=N mism=N amb=N grid=N exact=N ids=N":
//   draws  random draws compared against the sorted walk
//   mism   disagreements that are NOT explainable by a floating-point boundary
//   amb    disagreements that sit on a boundary (the two paths sum the same masses in a
//          different order, so a draw exactly on a boundary may land either way)
//   grid   disagreements over a scan of the whole draw range
//   exact  failures of the exact case (all masses equal, so the crossing token is exactly
//          floor(u) and any range error shows up as a wrong id)
//   ids    distinct tokens the random draws produced
std::string sampler_check(int backend, std::vector<int> ids, int draws, uint64_t seed) {
    char out[256];
    std::vector<double> r = prefill_top1(backend, ids, -1, 0);
    if (r.empty()) {
        std::snprintf(out, sizeof(out), "err=prefill draws=0 mism=1 amb=0 grid=1 exact=1 ids=0");
        return std::string(out);
    }
    const std::vector<float> lg = g_last_logits;
    const int n = (int) lg.size();
    if (n < 2) {
        std::snprintf(out, sizeof(out), "err=vocab draws=0 mism=1 amb=0 grid=1 exact=1 ids=0");
        return std::string(out);
    }
    const double temp = 0.9;

    // Masses, unnormalised: the sampler compares one draw against sums of these.
    std::vector<double> p((size_t) n);
    double z = 0.0;
    float mx = lg[0];
    for (int i = 1; i < n; ++i) if (lg[i] > mx) mx = lg[i];
    for (int i = 0; i < n; ++i) {
        p[(size_t) i] = std::exp(((double) lg[i] - (double) mx) / temp);
        z += p[(size_t) i];
    }
    if (!(z > 0.0)) {
        std::snprintf(out, sizeof(out), "err=mass draws=0 mism=1 amb=0 grid=1 exact=1 ids=0");
        return std::string(out);
    }

    // Reference: the order the sort-free path partitions by, with its cumulative mass.
    std::vector<SmpPair> ref((size_t) n);
    for (int i = 0; i < n; ++i) { ref[(size_t) i].p = p[(size_t) i]; ref[(size_t) i].id = i; }
    std::sort(ref.begin(), ref.end(), smp_by_prob_desc);
    std::vector<double> cum((size_t) n + 1, 0.0);
    for (int i = 0; i < n; ++i) cum[(size_t) i + 1] = cum[(size_t) i] + ref[(size_t) i].p;

    // The crossing rank: the first rank whose cumulative mass exceeds the draw. The interval of
    // that rank is [cum[r], cum[r+1]), which is the same rule the sorted walk uses (u < acc).
    auto ref_rank = [&](double u) -> int {
        int lo = 0, hi = n - 1;
        while (lo < hi) {
            const int mid = (lo + hi) / 2;
            if (cum[(size_t) mid + 1] > u) hi = mid; else lo = mid + 1;
        }
        return lo;
    };

    const double tol = 1e-8 * z;   // both paths sum the same masses in a different order
    int mism = 0, amb = 0, grid = 0, exact = 0, distinct = 0;
    std::vector<char> seen((size_t) n, 0);

    // Random draws through the shipped path. The draw sample_from makes is the one the copied
    // state produces, so the state must NOT be advanced before the call.
    uint64_t rng = seed;
    for (int d = 0; d < draws; ++d) {
        uint64_t s = rng;
        const double rv = next_u64_unit(s);      // the draw sample_from is about to make
        const int got = sample_from(lg, temp, 1.0, rng, nullptr);
        const double u = rv * z;
        const int rank = ref_rank(u);
        if (!seen[(size_t) got]) { seen[(size_t) got] = 1; ++distinct; }
        if (got != ref[(size_t) rank].id) {
            const double d0 = std::fabs(u - cum[(size_t) rank]);
            const double d1 = std::fabs(u - cum[(size_t) rank + 1]);
            if (d0 <= tol || d1 <= tol) ++amb; else ++mism;
        }
    }

    // A scan of the whole draw range: every point must map to the same token as the walk.
    {
        std::vector<SmpPair> gbuf((size_t) n);
        const int G = 128;
        for (int g = 0; g < G; ++g) {
            for (int i = 0; i < n; ++i) { gbuf[(size_t) i].p = p[(size_t) i]; gbuf[(size_t) i].id = i; }
            const double u = z * ((double) g + 0.5) / (double) G;
            const int got = smp_select(gbuf, 0, n, 0.0, u);
            if (got != ref[(size_t) ref_rank(u)].id) ++grid;
        }
    }

    // The exact case: with every mass equal the cumulative mass is a run of integers, so the
    // crossing token is exactly floor(u). This is what catches a range error - the probe's
    // partition stopped one entry short, which made the smallest token unreachable.
    {
        const double un = (double) n;
        const double us[] = {0.001, 0.5, 1.0, 1.5, 2.0, 2.5, un * 0.5, un - 2.5, un - 1.5,
                              un - 1.0, un - 0.5, un - 0.001};
        std::vector<SmpPair> ub((size_t) n);
        for (int i = 0; i < n; ++i) { ub[(size_t) i].p = 1.0; ub[(size_t) i].id = i; }
        for (size_t k = 0; k < sizeof(us) / sizeof(us[0]); ++k) {
            std::vector<SmpPair> tmp = ub;    // the partition permutes the buffer
            const int got = smp_select(tmp, 0, n, 0.0, us[k]);
            const int want = (int) std::floor(us[k]);
            if (got != want) ++exact;
        }
    }

    std::snprintf(out, sizeof(out),
                  "err=none draws=%d mism=%d amb=%d grid=%d exact=%d ids=%d",
                  draws, mism, amb, grid, exact, distinct);
    return std::string(out);
}

// Runs the model over `tokens`, which occupy cache positions [pos, pos+T), and returns
// {top1_id, top1_logit, top2_logit} for the last position.
std::vector<double> prefill_top1(int backend, const std::vector<int>& tokens, int expect_id, int pos) {
    g_err.clear();
    g_trace.clear();
    g_graphExecTopo = false;   // F680: this pass will name its own executor
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
    // F359: a planned pass gives every row its own KV slot, visible range and RoPE position.
    // The plan must describe exactly this many rows, otherwise it is a caller bug and the pass
    // falls back to the plain single-session behaviour (which would silently mix the rows).
    const bool planned = g_plan.on
        && (int) g_plan.slot.size() == T && (int) g_plan.lo.size() == T
        && (int) g_plan.hi.size() == T && (int) g_plan.abspos.size() == T;
    int planNeed = 0;
    if (planned) {
        for (int t = 0; t < T; ++t) planNeed = std::max(planNeed, g_plan.slot[(size_t) t] + 1);
    }
    // F365: the attention scores are computed once per (token, head) and reused by the three
    // passes that used to recompute them, so the scratch is one row of visible-range length per
    // (token, head): T * NH * scoreRows floats. The plain path sees [0, pos+b], a planned pass
    // sees [lo[b], hi[b]].
    int scoreRows = 1;
    if (planned) {
        for (int t = 0; t < T; ++t) {
            scoreRows = std::max(scoreRows, g_plan.hi[(size_t) t] - g_plan.lo[(size_t) t] + 1);
        }
    } else {
        scoreRows = pos + T;
    }
    // F366: how many chunks mode 2 cuts the visible range into. One work item per (token, head)
    // is only T*NH of them - 14 for a decode step - so there is nothing for the GPU to overlap;
    // once a pass already has a couple of hundred items there is nothing to gain, and splitting
    // would only add a second launch and a partial buffer to walk.
    int splitP = 1;
    if (T * NH < 224) {
        splitP = (scoreRows + 15) / 16;
        if (splitP > 16) splitP = 16;
        if (splitP < 1) splitP = 1;
    }
    const int partStride = HD + 2;
    g_split_p = splitP;
    if (theta <= 0.0) theta = 1000000.0;
    const int eIdx = gguf::find_tensor("token_embd.weight");
    if (eIdx < 0 || HD > 128) { g_err = "no token_embd.weight or head too wide"; return {}; }

    // (Re)allocate the cache when the backend changes or the sequence outgrows it. A planned pass
    // keeps nothing on a growth: its rows live in two regions, so the contiguous copy that the
    // plain path does would move one conversation's rows into the other's region.
    if (!ensure_kv(backend, q, planned ? planNeed : (pos + T), DKV, L, planned ? 0 : pos)) return {};

    try {
        // Long-lived: live across every layer, so they own their memory instead of using the
        // per-layer arena that gets reset.
        Dev x(&q, (size_t) T * D, true), h(&q, (size_t) T * D, true);
        // F365: long-lived too - the attention scores of every layer land here, and the per-layer
        // arena is reset between layers, so this one has to own its memory.
        Dev scores(&q, (size_t) T * NH * (size_t) scoreRows, true);
        // F366: the partials of the split-KV path (mode 2): running max, denominator and
        // unnormalized weighted V per (token, head, chunk).
        Dev part(&q, (size_t) T * NH * (size_t) splitP * (size_t) partStride, true);
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

        // rope tables, positioned at [pos, pos+T) - or, for a planned pass, at each row's own
        // absolute position (F359): the second conversation's rows are rotated as if they were
        // the beginning of their own sequence, not as a continuation of the first one's.
        const int half = HD / 2;
        std::vector<float> ctab((size_t) T * half), stab((size_t) T * half);
        for (int t = 0; t < T; ++t) {
            const int rt = (!g_row_pos.empty() && t < (int) g_row_pos.size()) ? g_row_pos[(size_t) t] : t;
            const int apos = planned ? g_plan.abspos[(size_t) t] : (pos + rt);
            for (int j = 0; j < half; ++j) {
                const float freq = (float) std::pow(theta, -(double) (2 * j) / (double) HD);
                const float ang = (float) apos * freq;
                ctab[(size_t) t * half + j] = std::cos(ang);
                stab[(size_t) t * half + j] = std::sin(ang);
            }
        }
        buf::BufferType* cbt = dev_bt(q);
        float* dCos = cbt ? (float*) buf::alloc_bt(cbt, ctab.size() * sizeof(float)) : nullptr;
        float* dSin = dCos ? (float*) buf::alloc_bt(cbt, stab.size() * sizeof(float)) : nullptr;
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
        // Optional dense K/V row tables for the generic N-segment path: when given, the
        // layer reads and writes K/V through kRows[l] / vRows[l] (the owning segment's dense
        // rows) instead of the `kv` cache's own array. Plain and two-device paths pass
        // nulls, so their dense `kv.k[l]` indexing is byte-for-byte unchanged.
        // F677: the graph declares each layer as its two ops; execution runs them as
        // two named blocks (attention: norms/qkv/rope/KV-store/attention/o_proj, ffn:
        // norm/gate/up/silu-mul/down) so the loop can check EACH op against the
        // declared plan. The ops still run on one device per layer; moving them
        // apart later is a scheduler decision that needs a tensor-boundary copy.
        auto run_op_attn = [&](sycl::queue& ql, float* xp, float* hp, KVCache& kv,
                               const float* cosp, const float* sinp, float* sc, float* pc,
                               int l, void* const* kRows, void* const* vRows) -> bool {
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

            void* Kst = kRows ? kRows[(size_t) l] : (void*) kv.k[(size_t) l];
            void* Vst = vRows ? vRows[(size_t) l] : (void*) kv.v[(size_t) l];
            if (planned) {
                // F359: every row lands in its own slot, so the two conversations never share a
                // cache row even before the mask is applied.
                if (kv.f16) {
                    kv_store_slots(ql, kt.p, (sycl::half*) Kst, T, DKV, g_plan.d_slot);
                    kv_store_slots(ql, vt.p, (sycl::half*) Vst, T, DKV, g_plan.d_slot);
                } else {
                    kv_store_slots(ql, kt.p, (float*) Kst, T, DKV, g_plan.d_slot);
                    kv_store_slots(ql, vt.p, (float*) Vst, T, DKV, g_plan.d_slot);
                }
            } else if (kv.f16) {
                kv_store(ql, kt.p, (sycl::half*) Kst, T, DKV, pos);
                kv_store(ql, vt.p, (sycl::half*) Vst, T, DKV, pos);
            } else {
                kv_store(ql, kt.p, (float*) Kst, T, DKV, pos);
                kv_store(ql, vt.p, (float*) Vst, T, DKV, pos);
            }

            Dev ao(&ql, (size_t) T * D);
            // F366: mode 2 runs the split-KV kernel instead - one work item per (token, head,
            // chunk) plus a combine - as long as this device has a partial buffer to write into.
            // F366/F368: mode 2 runs the split-KV kernel - but only where there is more than one
            // chunk to spread. With P=1 the split path costs a second kernel launch and a partial
            // round trip and buys nothing (measured: L=8 was 3% SLOWER than the score cache), so
            // short ranges fall through to the mode-0 path and are then bit-identical to it.
            const bool useSplit = (g_attn_mode == 2) && (pc != nullptr) && (splitP > 1);
            const char* Krd = (const char*) (kRows ? kRows[(size_t) l] : (void*) kv.k[(size_t) l]);
            const char* Vrd = (const char*) (vRows ? vRows[(size_t) l] : (void*) kv.v[(size_t) l]);
            if (kv.f16) {
                if (useSplit) {
                    attention_split(ql, att.p, (const sycl::half*) Krd,
                                    (const sycl::half*) Vrd, T, pos, NH, NKV, HD,
                                    scale, ao.p, pc, splitP, partStride,
                                    planned ? nullptr : g_tree_mask,
                                    planned ? g_plan.d_lo : nullptr,
                                    planned ? g_plan.d_hi : nullptr);
                } else {
                    attention(ql, att.p, (const sycl::half*) Krd,
                              (const sycl::half*) Vrd, T, pos, NH, NKV, HD, scale,
                              ao.p, sc, scoreRows,
                              planned ? nullptr : g_tree_mask, planned ? g_plan.d_lo : nullptr,
                              planned ? g_plan.d_hi : nullptr);
                }
            } else {
                if (useSplit) {
                    attention_split(ql, att.p, (const float*) Krd,
                                    (const float*) Vrd, T, pos, NH, NKV, HD,
                                    scale, ao.p, pc, splitP, partStride,
                                    planned ? nullptr : g_tree_mask,
                                    planned ? g_plan.d_lo : nullptr,
                                    planned ? g_plan.d_hi : nullptr);
                } else {
                    attention(ql, att.p, (const float*) Krd,
                              (const float*) Vrd, T, pos, NH, NKV, HD, scale,
                              ao.p, sc, scoreRows,
                              planned ? nullptr : g_tree_mask, planned ? g_plan.d_lo : nullptr,
                              planned ? g_plan.d_hi : nullptr);
                }
            }

            Wt wo = load_weight(ql, p + "attn_output.weight", (uint64_t) D * D);
            if (!wo.p) return false;
            Dev oo(&ql, (size_t) T * D);
            gemm(ql, ao.p, T, D, wo, D, oo.p, nullptr);
            weight_release(wo);
            add_inplace(ql, xp, oo.p, (size_t) T * D);
            return true;
        };

        auto run_op_ffn = [&](sycl::queue& ql, float* xp, float* hp, int l) -> bool {
            const std::string p = "blk." + std::to_string(l) + ".";
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
            return true;
        };

        // F677: the layer is its two op blocks; both executing loops call the ops
        // separately so each op can be checked against its own graph node.

        // Layer-range offload: each layer's backend and the single boundary handoff come from
        // one placement plan; weights and KV remain owned by that backend's existing resources.
        const LayerPlacementPlan placement = make_layer_placement_plan(L, backend, g_split_backend,
                                                                        g_split_layer);
        sycl::queue* q2p = placement.secondary_backend >= 0
            ? kakasi::queue_for(placement.secondary_backend) : nullptr;
        if (placement.secondary_backend >= 0 && !q2p) {
            g_err = "split backend unavailable";
            return {};
        }
        const bool splitActive = q2p != nullptr;
        sycl::queue& q2 = splitActive ? *q2p : q;
        Dev xs(&q2, (size_t) T * D, true), hs(&q2, (size_t) T * D, true);
        // F675 Stage 1: the boundary staging buffer is now a host buffer_type allocation -
        // one of the two memory classes of the contract - instead of an unowned vector.
        struct HostStage {
            char* p = nullptr;
            size_t bytes = 0;
            explicit HostStage(size_t b) : bytes(b) { p = (char*) buf::alloc_bt(&host_bt(), b); }
            ~HostStage() { if (p) buf::free_bt(&host_bt(), p, bytes); }
        };
        HostStage handoff((size_t) T * (size_t) D * sizeof(float));
        // F675 Stage 1: the second device's memory class, scoped over the whole pass -
        // not just the alloc block - because the success-side frees run after the layer loop.
        buf::BufferType* s2bt = dev_bt(q2);
        // The second device needs its own copies of everything the layers touch: the rope
        // tables and the KV rows. A pointer allocated on one device must never be dereferenced
        // by the other.
        float* dCos2 = nullptr;
        float* dSin2 = nullptr;
        // F365: the second device needs its own score scratch - a pointer allocated on one
        // device must never be dereferenced by the other, which is exactly what the split path
        // would do if the layers on the second device reused the primary scratch.
        float* scores2 = nullptr;
        float* part2 = nullptr;
        if (splitActive) {
            dCos2 = s2bt ? (float*) buf::alloc_bt(s2bt, ctab.size() * sizeof(float)) : nullptr;
            dSin2 = dCos2 ? (float*) buf::alloc_bt(s2bt, stab.size() * sizeof(float)) : nullptr;
            scores2 = dSin2 ? (float*) buf::alloc_bt(s2bt,
                (size_t) T * NH * (size_t) scoreRows * sizeof(float)) : nullptr;
            part2 = scores2 ? (float*) buf::alloc_bt(s2bt,
                (size_t) T * NH * (size_t) splitP * (size_t) partStride * sizeof(float)) : nullptr;
            if (!dCos2 || !dSin2 || !scores2 || !part2) {
                if (dCos2) buf::free_bt(s2bt, dCos2, ctab.size() * sizeof(float));
                if (dSin2) buf::free_bt(s2bt, dSin2, stab.size() * sizeof(float));
                if (scores2) buf::free_bt(s2bt, scores2,
                    (size_t) T * NH * (size_t) scoreRows * sizeof(float));
                if (part2) buf::free_bt(s2bt, part2,
                    (size_t) T * NH * (size_t) splitP * (size_t) partStride * sizeof(float));
                g_err = "split scratch allocation failed";
                return {};
            }
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
                buf::BufferType* b2p = dev_bt(q2);
                std::vector<char*> nk2;
                std::vector<char*> nv2;
                for (int l = 0; l < L; ++l) {
                    char* pk = b2p ? (char*) buf::alloc_bt(b2p, b2) : nullptr;
                    char* pv = pk ? (char*) buf::alloc_bt(b2p, b2) : nullptr;
                    nk2.push_back(pk);
                    nv2.push_back(pv);
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

        // F675 Stage 2: the generic N-segment path. The two-device fast path above owns the
        // g_kv2 + s2bt shape; any other active segment split gives every non-primary segment
        // its own queue, hidden state, rope tables, score scratch and dense KV cache, all
        // through the buffer_type contract. Segment 0 aliases the primary x/h and g_kv; the
        // last segment's running state is handed back to x before the final norm and lm_head.
        if (g_seg_active && !splitActive) {
            int seg_step = 0;
            try {
                build_ops_table();   // F678: the segment backends get their table entries
            g_seg.assign(g_seg_split.backend.size(), SegRT());
            const size_t nSeg = g_seg_split.backend.size();
            // Segment 0 is the primary device: it aliases x/h and g_kv, so only the
            // non-primary segments need queues and per-pass buffers.
            for (size_t k = 0; k < nSeg; ++k) {
                SegRT& sg = g_seg[k];
                sg.backend = g_seg_split.backend[k];
                if (k == 0) {
                    sg.q = &q;
                    sg.xs = x.p;
                    sg.hs = h.p;
                    continue;
                }
                sg.q = kakasi::queue_for(sg.backend);
                if (!sg.q) {
                    g_err = "segment backend " + std::to_string(sg.backend) + " unavailable";
                    return {};
                }
                // Persistent KV slot (survives across passes, like g_kv2): segment k keeps
                // rows [0, pos) that its own layers wrote in earlier passes. F681: a
                // segment that owns NO attention op (an FFN-override segment) never reads
                // or writes KV - skip the allocation entirely instead of wasting the
                // override device's memory on L empty rows.
                bool ownsKv = false;
                for (int l = 0; l < L; ++l) {
                    if (segOfOp(g_seg_split, l, false) == (int) k) { ownsKv = true; break; }
                }
                if (ownsKv) {
                    SegKvSlot& slot = g_seg_kv[(size_t) k];
                    const int keep = (slot.kv.backend == sg.backend && slot.kv.f16 == g_kv.f16)
                                     ? std::min(pos, slot.kv.cap) : 0;
                    seg_step = 1;   // KV alloc
                    if (!ensure_seg_kv(slot, L, *sg.q, pos + T, DKV, keep, g_kv.f16)) {
                        g_err = "segment KV allocation failed";
                        return {};
                    }
                }
                seg_step = 2;   // per-pass scratch
                // Per-pass buffers: allocated now, freed at pass end on success AND on the
                // error returns below are not reached because every alloc failure returns
                // before any buffer is handed to the caller; the RAII-style pair free at the
                // bottom of the success path covers the normal case.
                buf::BufferType* btp = kakasi::buffer_type_for_queue(sg.q);
                if (!btp) { g_err = "segment memory class unavailable"; return {}; }
                sg.xs = (float*) buf::alloc_bt(btp, (size_t) T * (size_t) D * sizeof(float));
                sg.hs = (float*) buf::alloc_bt(btp, (size_t) T * (size_t) D * sizeof(float));
                sg.dCos = (float*) buf::alloc_bt(btp, ctab.size() * sizeof(float));
                sg.dSin = sg.dCos ? (float*) buf::alloc_bt(btp, stab.size() * sizeof(float)) : nullptr;
                sg.scores = sg.dSin ? (float*) buf::alloc_bt(btp,
                    (size_t) T * (size_t) NH * (size_t) scoreRows * sizeof(float)) : nullptr;
                sg.part = sg.scores ? (float*) buf::alloc_bt(btp,
                    (size_t) T * (size_t) NH * (size_t) splitP * (size_t) partStride * sizeof(float)) : nullptr;
                if (!sg.xs || !sg.hs || !sg.dCos || !sg.dSin || !sg.scores || !sg.part) {
                    g_err = "segment scratch allocation failed";
                    return {};
                }
                sg.q->memcpy(sg.dCos, ctab.data(), sizeof(float) * ctab.size());
                sg.q->memcpy(sg.dSin, stab.data(), sizeof(float) * stab.size());
                sg.q->wait_and_throw();
                arena_reset(*sg.q);
            }
            // Segment owning a layer: the first whose start is <= the layer.
            const auto segOf = [&](int layer) {
                size_t k = 0;
                while (k + 1 < g_seg_split.start.size()
                       && g_seg_split.start[k + 1] <= layer) ++k;
                return (int) k;
            };
            // F677: the graph's per-OP nodes must agree with segOf for every op of every
            // layer: the graph is the declared plan, the loop is the executor, and the
            // gate proves they agree.
            const auto graphNode = [&](int l, int op) {
                for (const auto& gn : g_graph.nodes) {
                    const bool is = (op == 0) ? gn.kind == GraphNode::ATTN : gn.kind == GraphNode::FFN;
                    if (is && gn.layer == l) return &gn;
                }
                return (const GraphNode*) nullptr;
            };
            for (int l = 0; l < L; ++l) {
                for (int op = 0; op < 2; ++op) {
                    const GraphNode* gn = graphNode(l, op);
                    // F681: the expected segment is the plan's per-op assignment - the
                    // FFN override moves one op off the layer-range segment.
                    const int expect = segOfOp(g_seg_split, l, op == 1);
                    if (!gn || gn->segment != expect) {
                        g_err = "graph/loop segment mismatch at layer " + std::to_string(l)
                               + " op " + std::to_string(op);
                        return {};
                    }
                }
            }
            // F679: the executing loop walks nodes in declaration order; the declared
            // dependency edges must point only to earlier execution positions (the DAG
            // is acyclic AND the walk is a valid topological order of it).
            if (!g_graph.dag_conforms()) {
                g_err = "graph/loop DAG order violation (edge to a later execution node or cycle)";
                return {};
            }
            // Each layer's K/V lives in the PERSISTENT cache of the segment that owns that
            // layer - the same invariant as the dedicated two-device path (g_kv2). Prefix
            // rows [0, pos) were written by this same split in the previous pass, and
            // this pass's rows are written as each layer runs.
            // The handoff fires ONLY at a segment boundary: every layer of a segment reads
            // and writes that segment's own running state in place, so re-copying the
            // previous segment's state on every layer would clobber the just-finished
            // layer's output with a stale pre-boundary state (the l10 divergence F675 hit).
            // F680: the graph-DRIVEN executor. The walk is no longer hardcoded per layer;
            // it is read off g_graph in topological order. For the current linear plan the
            // order equals declaration order, so results are bit-identical - but the loop
            // now knows nothing about which layer runs where: the GRAPH owns the walk, and
            // a future non-sequential plan (branch merges, cross-segment op moves) re-emits
            // the edges and this same driver consumes them unchanged.
            bool gTopoOk = false;
            const std::vector<int> gOrder = g_graph.topo_order(gTopoOk);
            if (!gTopoOk) {
                g_err = "graph/loop DAG order violation (cycle or bad edge)";
                return {};
            }
            // F681: the segment holding the running hidden state right now. EMBED leaves
            // it on the primary; every copy node and every op moves it per the plan.
            int stateSeg = 0;
            for (int n = 0; n < (int) gOrder.size(); ++n) {
                const GraphNode& gn = g_graph.nodes[(size_t) gOrder[(size_t) n]];
                // Structural markers: embedding ran before the loop; final norm + lm_head
                // run after it. The driver dispatches only the in-graph compute + copy nodes.
                if (gn.kind == GraphNode::EMBED || gn.kind == GraphNode::FINAL_NORM
                    || gn.kind == GraphNode::LM_HEAD) {
                    continue;
                }
                if (gn.kind == GraphNode::BOUNDARY_COPY) {
                    const int src = gn.srcSeg;
                    const int sk = gn.segment;
                    if (src < 0 || src >= (int) g_seg.size() || sk < 0
                        || sk >= (int) g_seg.size() || src == sk) {
                        g_err = "graph copy node with bad src/dst segment";
                        return {};
                    }
                    const size_t hb = (size_t) T * (size_t) D * sizeof(float);
                    sycl::queue& srcQ = *g_seg[(size_t) src].q;
                    srcQ.memcpy(handoff.p, g_seg[(size_t) src].xs, hb);
                    srcQ.wait_and_throw();
                    sycl::queue& cq = *g_seg[(size_t) sk].q;
                    cq.memcpy(g_seg[(size_t) sk].xs, handoff.p, hb);
                    cq.wait_and_throw();
                    stateSeg = sk;
                    continue;
                }
                // ATTN / FFN: dispatch the op on the owning segment, in place.
                const int sk = gn.segment;
                const int l = gn.layer;
                SegRT& sg = g_seg[(size_t) sk];
                sycl::queue& ql = *sg.q;
                const KVCache& layerKv = (sk == 0) ? g_kv : g_seg_kv[(size_t) sk].kv;
                if (gn.kind == GraphNode::ATTN) {
                    seg_step = 3 + l;   // layer l, attention op
                    if (!run_op_attn(ql, sg.xs, sg.hs,
                                      const_cast<KVCache&>(layerKv),
                                      sk == 0 ? dCos : sg.dCos, sk == 0 ? dSin : sg.dSin,
                                      sk == 0 ? scores.p : sg.scores, sk == 0 ? part.p : sg.part,
                                      l, nullptr, nullptr)) {
                        return {};
                    }
                    stateSeg = sk;
                    continue;
                }
                // FFN closes the layer: drain + arena reset here, and the per-layer
                // trace on the primary segment.
                seg_step = 4 + l;
                if (!run_op_ffn(ql, sg.xs, sg.hs, l)) {
                    return {};
                }
                if (sk == 0) probe();   // F677: per-layer |x| trace on the primary segment
                ql.wait_and_throw();
                arena_reset(ql);
                stateSeg = sk;
            }
            g_graphExecTopo = true;   // F680: this pass was driven by the declared DAG walk
            // F681: the handback returns the state from wherever the plan LEFT it - for
            // layer-range plans that is the last segment (as before); with an FFN
            // override the copy-back node already returned it to the primary, so no
            // extra trip happens.
            if (stateSeg > 0 && stateSeg < (int) g_seg.size()) {
                SegRT& last = g_seg[(size_t) stateSeg];
                seg_step = 90;
                const size_t hb2 = (size_t) T * (size_t) D * sizeof(float);
                last.q->memcpy(handoff.p, last.xs, hb2);
                last.q->wait_and_throw();
                q.memcpy(x.p, handoff.p, hb2);
                q.wait_and_throw();
            }
            // F675 cross-pass KV invariant (the exact gap the 2-seg fast path meets with
            // its per-layer sync): every layer's K/V row lives in the cache of the segment
            // that OWNS that layer. The next pass's segment 0 attends layers [0, s) and
            // reads g_kv, so this pass's freshly written rows [pos, pos+T) must land in
            // Per-pass buffers die with the pass; the persistent KV slots stay.
            for (size_t k = 1; k < nSeg; ++k) {
                SegRT& sg = g_seg[k];
                seg_step = 95;
                buf::BufferType* btp = kakasi::buffer_type_for_queue(sg.q);
                if (btp) {
                    if (sg.xs) { buf::free_bt(btp, sg.xs, 0); sg.xs = nullptr; }
                    if (sg.hs) { buf::free_bt(btp, sg.hs, 0); sg.hs = nullptr; }
                    sg.free_scratch(btp);
                }
            }
            g_seg.clear();
            seg_step = 99;
            } catch (const sycl::exception& e) {
                g_err = std::to_string(seg_step) + std::string(" segment pass sycl: ") + e.what();
                return {};
            } catch (const std::exception& e) {
                g_err = std::to_string(seg_step) + std::string(" segment pass: ") + e.what();
                return {};
            } catch (...) {
                g_err = std::to_string(seg_step) + std::string(" segment pass (non-std throw)");
                return {};
            }
        } else {
        // Slots from the previous pass's lm_head loop are finished (that loop waits on every
        // chunk), so the bump pointer can start clean.
        arena_reset(q);
        if (splitActive) arena_reset(q2);
        // F676+F677: the fast path obeys its declared graph, now at OP granularity. When
        // a two-device split is armed (and no N-segment plan is active - the segment path
        // has its own per-op graph==segOf check), every ATTN/FFN node's backend must equal
        // the loop's per-layer backend, and the BOUNDARY_COPY nodes must sit exactly on
        // the handoff position. An empty graph is the plain run: every layer on the
        // caller's device, no copies.
        if (!g_seg_active && !g_graph.nodes.empty()) {
            for (int l = 0; l < L; ++l) {
                for (int op = 0; op < 2; ++op) {
                    int graphBk = -1;
                    for (const auto& gn : g_graph.nodes) {
                        const bool is = (op == 0) ? gn.kind == GraphNode::ATTN : gn.kind == GraphNode::FFN;
                        if (is && gn.layer == l) { graphBk = gn.backend; break; }
                    }
                    if (graphBk != (int) placement.backend_for(l)) {
                        g_err = "fast-path graph/backend mismatch at layer " + std::to_string(l)
                               + " op " + std::to_string(op);
                        return {};
                    }
                }
            }
            int graphCopies = 0;
            for (const auto& gn : g_graph.nodes) {
                if (gn.kind == GraphNode::BOUNDARY_COPY) {
                    ++graphCopies;
                    if (!placement.handoff_before(gn.layer)) {
                        g_err = "fast-path graph copy at non-handoff layer";
                        return {};
                    }
                }
            }
            if (graphCopies != (splitActive ? 1 : 0)) {
                g_err = "fast-path graph copy count mismatch";
                return {};
            }
        }
        // F678: the op dispatch table governs what each backend executes. Every op the
        // table declares for a backend that appears in the armed graph must be available
        // (nothing may be dispatched on a backend that cannot run it); the split-KV
        // kernel is listed as a capability on every validated queue, so the loop may use
        // it whenever the attention-mode policy says so.
        if (!g_graph.nodes.empty()) {
            for (int b = 0; b < g_ops.nBackends; ++b) {
                for (int k = 0; k < kOps; ++k) {
                    if (k == OpKind::SPLIT_KV) continue;   // capability; gated by attn mode
                    if (!g_ops.ops[(size_t) b][(size_t) k]) {
                        g_err = "ops table missing op " + std::to_string(k) + " on b"
                               + std::to_string(b);
                        return {};
                    }
                }
            }
            for (const auto& gn : g_graph.nodes) {
                if (gn.kind == GraphNode::BOUNDARY_COPY) continue;
                if (gn.backend < 0 || gn.backend >= g_ops.nBackends) {
                    g_err = "graph node on unlisted backend " + std::to_string(gn.backend);
                    return {};
                }
            }
            // F679: same DAG-order guarantee on the fast/plain path.
            if (!g_graph.dag_conforms()) {
                g_err = "graph/loop DAG order violation (edge to a later execution node or cycle)";
                return {};
            }
        }
        for (int l = 0; l < L; ++l) {
            const bool on2 = splitActive && placement.backend_for(l) == placement.secondary_backend;
            if (on2 && placement.handoff_before(l)) {
                const size_t hb = (size_t) T * (size_t) D * sizeof(float);
                q.memcpy(handoff.p, x.p, hb);
                q.wait_and_throw();
                q2.memcpy(xs.p, handoff.p, hb);
                q2.wait_and_throw();
            }
            // F677: the two ops of the layer run on the layer's declared device (checked
            // against the graph above); both share the layer's x/h and stay in place.
            if (!run_op_attn(on2 ? q2 : q, on2 ? xs.p : x.p, on2 ? hs.p : h.p,
                             on2 ? g_kv2 : g_kv,
                             on2 ? dCos2 : dCos, on2 ? dSin2 : dSin,
                             on2 ? scores2 : scores.p, on2 ? part2 : part.p,
                             l, nullptr, nullptr)
                || !run_op_ffn(on2 ? q2 : q, on2 ? xs.p : x.p, on2 ? hs.p : h.p, l)) {
                return {};
            }
            if (!on2) probe();   // F677: per-layer |x| trace on primary-device layers
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
        }
        if (splitActive) {
            const size_t hb2 = (size_t) T * (size_t) D * sizeof(float);
            q2.memcpy(handoff.p, xs.p, hb2);
            q2.wait_and_throw();
            q.memcpy(x.p, handoff.p, hb2);
            q.wait_and_throw();
            buf::free_bt(s2bt, dCos2, ctab.size() * sizeof(float));
            buf::free_bt(s2bt, dSin2, stab.size() * sizeof(float));
            buf::free_bt(s2bt, scores2,
                (size_t) T * NH * (size_t) scoreRows * sizeof(float));
            buf::free_bt(s2bt, part2,
                (size_t) T * NH * (size_t) splitP * (size_t) partStride * sizeof(float));
        }

        buf::free_bt(cbt, dCos, ctab.size() * sizeof(float));
        buf::free_bt(cbt, dSin, stab.size() * sizeof(float));
        q.wait_and_throw();
        mark_stage();  // stage 1: the 24 transformer blocks

        // Final norm + tied lm_head. Normally only the last row is needed (sampling). When the
        // speculative verifier asks for it, the head also reports the argmax at each of the last
        // g_head_rows positions, which is what makes a batched accept/reject decision possible.
        // A planned pass (F359) asks for one head row per conversation.
        const int nOut = planned ? std::max(1, std::min(g_plan.head_rows, T))
                                 : std::max(1, std::min(g_head_rows, T));
        g_step_top1.clear();
        g_row_logits.clear();
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
        Dev dLogits(&q, (size_t) rows * (size_t) nOut);
        std::vector<float> all;
        double best = -1e300, second = -1e300;
        int bestId = -1;
        // One walk over the vocabulary serves every row (F359): the embedding table is the biggest
        // read of a decode step, so two rows must share it. The output rows sit `rows` apart,
        // which is what the ldy argument of the GEMM expresses.
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
            if (nOut == 1) {
                gemm(q, xn.p, 1, D, dW, nrows, dLogits.p + (size_t) r0, nullptr);
            } else {
                gemm(q, xn.p, nOut, D, dW, nrows, dLogits.p + (size_t) r0, nullptr, rows);
            }
            if (dStream) {
                q.wait_and_throw();
                buf::free_bt(cbt, dStream, (size_t) nrows * (size_t) D * sizeof(float));
            }
        }
        for (int r = 0; r < nOut; ++r) {
            std::vector<float> row((size_t) rows);
            q.memcpy(row.data(), dLogits.p + (size_t) r * (size_t) rows, sizeof(float) * row.size());
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
            if (planned) {
                g_row_logits.push_back(row);   // every conversation gets its own logits (F359)
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
    if (r.empty()) return -1;
    // F652: advance only after a step that actually produced KV rows, matching session_next_allowed.
    // Incrementing first left a phantom position on the failure path, so every later write and every
    // KV snapshot inherited an offset with no row behind it.
    g_sess_len += 1;
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

// ---- F359: two conversations in ONE forward pass --------------------------------------------
// Each conversation owns a contiguous KV region (conversation 0 in rows [0, half), conversation 1
// in [half, 2*half)) and appends one row per step, so the two never share a cache row. The rows
// of a step form ONE pass: row t has its own RoPE position, its own visible range and its own
// logits, and the head walks the vocabulary once for all of them.

namespace {
// Prefill one conversation into its region: rows [base, base+len), RoPE positions 0..len-1, and
// the visible range of row i is [base, base+i] - its own conversation and nothing else.
bool batch_prefill(int backend, int s, const std::vector<int>& ids) {
    BatchSess& b = g_bs[s];
    const int len = (int) ids.size();
    if (len <= 0) return false;
    g_plan.on = true;
    g_plan.slot.assign((size_t) len, 0);
    g_plan.lo.assign((size_t) len, 0);
    g_plan.hi.assign((size_t) len, 0);
    g_plan.abspos.assign((size_t) len, 0);
    for (int i = 0; i < len; ++i) {
        g_plan.slot[(size_t) i] = b.base + i;
        g_plan.lo[(size_t) i] = b.base;
        g_plan.hi[(size_t) i] = b.base + i;
        g_plan.abspos[(size_t) i] = i;
    }
    g_plan.head_rows = 1;
    sycl::queue* qp = kakasi::queue_for(backend);
    const bool up = qp && plan_upload(*qp);
    if (!up) g_err = "batch: plan upload failed";
    const std::vector<double> r = up ? prefill_top1(backend, ids, -1, 0) : std::vector<double>();
    if (qp) plan_free(*qp);
    g_plan.on = false;
    g_plan.slot.clear();
    g_plan.lo.clear();
    g_plan.hi.clear();
    g_plan.abspos.clear();
    if (r.empty()) return false;
    b.len = len;
    b.logits = g_row_logits.empty() ? g_last_logits : g_row_logits[0];
    return true;
}
}  // namespace

// Start a batch of two conversations. `cap` is the number of KV rows EACH conversation may use
// (its prompt plus the tokens it will generate), so the cache holds 2*cap rows. The two regions
// are fixed for the lifetime of the batch, which is what lets a conversation be added or removed
// at any moment without moving a single cache row.
int batch_start_n(int backend, int cap, int slots) {
    g_err.clear();
    if (cap <= 0) { g_err = "batch: bad cap"; return -1; }
    if (slots < 1 || slots > kBatchSlotsMax) { g_err = "batch: bad slot count"; return -1; }
    for (int s = 0; s < slots; ++s) g_bs[s] = BatchSess();
    g_bhalf = 0;
    const int D = (int) gguf::meta_num("qwen2.embedding_length");
    const int L = (int) gguf::meta_num("qwen2.block_count");
    const int NH = (int) gguf::meta_num("qwen2.attention.head_count");
    const int NKV = (int) gguf::meta_num("qwen2.attention.head_count_kv");
    if (D <= 0 || L <= 0 || NH <= 0 || NKV <= 0) { g_err = "batch: bad model dims"; return -1; }
    const int DKV = NKV * (D / NH);
    sycl::queue* qp = kakasi::queue_for(backend);
    if (!qp) { g_err = "batch: backend unavailable"; return -1; }
    // F373: the cache is sized by the region count, so asking for more regions is what makes
    // a three- or four-row step possible at all; the rows of a step never cross a region.
    if (!ensure_kv(backend, *qp, g_bstage + slots * cap, DKV, L, 0)) {
        g_err = "batch: kv alloc failed";
        return -1;
    }
    g_bhalf = cap;
    g_bslots = slots;
    for (int s = 0; s < slots; ++s) { g_bs[s].base = g_bstage + s * cap; g_bs[s].cap = cap; }
    return 0;
}

// Two regions is what the 8001 server runs, and what every gate before F373 measured.
int batch_start(int backend, int cap) { return batch_start_n(backend, cap, 2); }

// Add a conversation to a slot: its prompt is prefilled into that slot's region and its sampling
// parameters are remembered. A slot that still holds a conversation is replaced - the old rows
// stay in the cache but stay invisible, because a conversation only ever attends to
// [base, base+len).
int batch_add(int backend, int slot, const std::vector<int>& ids,
              double temp, double top_p, uint64_t seed) {
    g_err.clear();
    if (slot < 0 || slot >= g_bslots) { g_err = "batch: bad slot"; return -1; }
    if (g_bhalf <= 0) { g_err = "batch: not started"; return -1; }
    if (ids.empty()) { g_err = "batch: empty prompt"; return -1; }
    if ((int) ids.size() > g_bhalf) { g_err = "batch: prompt longer than the slot"; return -1; }
    BatchSess& b = g_bs[slot];
    const int base = g_bstage + slot * g_bhalf;
    const int cap = g_bhalf;
    b = BatchSess();
    b.base = base;
    b.cap = cap;
    b.active = true;
    b.temp = temp;
    b.top_p = top_p;
    b.rng = seed;
    if (!batch_prefill(backend, slot, ids)) {
        b = BatchSess();
        b.base = base;
        b.cap = cap;
        return -1;
    }
    return 0;
}

// F684: begin a chunked prefill — stores the prompt without running the forward pass.
int batch_add_begin(int backend, int slot, const std::vector<int>& ids,
                    double temp, double top_p, uint64_t seed) {
    g_err.clear();
    if (slot < 0 || slot >= g_bslots) { g_err = "batch: bad slot"; return -1; }
    if (g_bhalf <= 0) { g_err = "batch: not started"; return -1; }
    if (ids.empty()) { g_err = "batch: empty prompt"; return -1; }
    if ((int) ids.size() > g_bhalf) { g_err = "batch: prompt longer than the slot"; return -1; }
    BatchSess& b = g_bs[slot];
    const int base = g_bstage + slot * g_bhalf;
    b = BatchSess();
    b.base = base;
    b.cap = g_bhalf;
    b.temp = temp;
    b.top_p = top_p;
    b.rng = seed;
    b.prompt = ids;
    b.prefill_cursor = 0;
    b.prefill_backend = backend;
    return 0;
}

// F684: prefill the next chunk. Returns remaining tokens (0 = done, slot becomes active).
int batch_prefill_chunk(int slot, int chunk_size) {
    g_err.clear();
    if (slot < 0 || slot >= g_bslots) { g_err = "batch: bad slot"; return -1; }
    BatchSess& b = g_bs[slot];
    if (b.prompt.empty()) return 0;
    if (chunk_size <= 0) chunk_size = 512;
    const int start = b.prefill_cursor;
    const int total = (int) b.prompt.size();
    const int end = (start + chunk_size < total) ? start + chunk_size : total;
    const int len = end - start;
    const std::vector<int> chunk(b.prompt.begin() + start, b.prompt.begin() + end);
    g_plan.on = true;
    g_plan.slot.assign((size_t) len, 0);
    g_plan.lo.assign((size_t) len, 0);
    g_plan.hi.assign((size_t) len, 0);
    g_plan.abspos.assign((size_t) len, 0);
    for (int i = 0; i < len; ++i) {
        g_plan.slot[(size_t) i] = b.base + start + i;
        g_plan.lo[(size_t) i] = b.base;
        g_plan.hi[(size_t) i] = b.base + start + i;
        g_plan.abspos[(size_t) i] = start + i;
    }
    g_plan.head_rows = 1;
    sycl::queue* qp = kakasi::queue_for(b.prefill_backend);
    const bool up = qp && plan_upload(*qp);
    if (!up) g_err = "batch: plan upload failed";
    const std::vector<double> r = up ? prefill_top1(b.prefill_backend, chunk, -1, 0) : std::vector<double>();
    if (qp) plan_free(*qp);
    g_plan.on = false;
    g_plan.slot.clear();
    g_plan.lo.clear();
    g_plan.hi.clear();
    g_plan.abspos.clear();
    if (r.empty()) { g_err = "batch: chunk prefill failed"; return -1; }
    b.prefill_cursor = end;
    b.len = end;
    if (end >= total) {
        b.active = true;
        b.logits = g_row_logits.empty() ? g_last_logits : g_row_logits[0];
        b.prompt.clear();
        b.prompt.shrink_to_fit();
        return 0;
    }
    return total - end;
}

// End one conversation; the slot becomes free for the next request.
int batch_stop(int slot) {
    if (slot < 0 || slot >= g_bslots) return -1;
    const int base = g_bstage + slot * g_bhalf;
    const int cap = g_bhalf;
    g_bs[slot] = BatchSess();
    g_bs[slot].base = base;
    g_bs[slot].cap = cap;
    return 0;
}

// End every conversation; the regions stay allocated.
void batch_reset() {
    for (int s = 0; s < g_bslots; ++s) {
        const int base = g_bstage + s * g_bhalf;
        const int cap = g_bhalf;
        g_bs[s] = BatchSess();
        g_bs[s].base = base;
        g_bs[s].cap = cap;
    }
}

// Seed one conversation's sampler (F359). The single-session path seeds with session_seed, so
// the same seed must land on the same tokens in both paths for the comparison to mean anything.
void batch_seed(int s, uint64_t seed) {
    if (s < 0 || s >= g_bslots) return;
    g_bs[s].rng = seed;
}

// F683: set or clear the per-row allowed token set. Non-empty ⇒ greedy argmax restricted to
// the set (same logic as session_next_allowed); empty ⇒ unconstrained sample_from.
void batch_set_allowed(int s, const std::vector<int>& ids) {
    if (s < 0 || s >= g_bslots) return;
    g_bs[s].allowed = ids;
}

// One step for every live conversation: each samples its next token from its own logits with
// its OWN temperature and nucleus, and all of them are fed through a SINGLE forward pass.
// Returns the number of rows in that pass (0 when nothing is live).
int batch_next(int backend) {
    g_err.clear();
    const int eos = (int) gguf::meta_num("tokenizer.ggml.eos_token_id");
    std::vector<int> toks;
    std::vector<int> rowSess;
    for (int s = 0; s < g_bslots; ++s) {
        BatchSess& b = g_bs[s];
        if (!b.active || b.done) continue;
        // A conversation may never write past its own region: the row after the region belongs
        // to the other conversation.
        if (b.len + 1 > b.cap) { b.done = true; b.last = -1; continue; }
        int t;
        if (!b.allowed.empty()) {
            t = -1;
            double bestv = -1e300;
            for (size_t ai = 0; ai < b.allowed.size(); ++ai) {
                const int id = b.allowed[ai];
                if (id < 0 || id >= (int) b.logits.size()) continue;
                const double v = (double) b.logits[(size_t) id];
                if (v > bestv) { bestv = v; t = id; }
            }
        } else {
            t = sample_from(b.logits, b.temp, b.top_p, b.rng, nullptr);
        }
        if (t < 0) { b.done = true; b.last = -1; continue; }
        // The single-session path returns -1 at eos and never appends the eos token, so the
        // batch reports the same -1: the caller must not see an eos id as text.
        if (eos > 0 && t == eos) { b.done = true; b.last = -1; continue; }
        toks.push_back(t);
        rowSess.push_back(s);
    }
    if (toks.empty()) return 0;
    const int T = (int) toks.size();
    g_plan.on = true;
    g_plan.slot.assign((size_t) T, 0);
    g_plan.lo.assign((size_t) T, 0);
    g_plan.hi.assign((size_t) T, 0);
    g_plan.abspos.assign((size_t) T, 0);
    for (int t = 0; t < T; ++t) {
        BatchSess& b = g_bs[(size_t) rowSess[(size_t) t]];
        g_plan.slot[(size_t) t] = b.base + b.len;
        g_plan.lo[(size_t) t] = b.base;
        g_plan.hi[(size_t) t] = b.base + b.len;
        g_plan.abspos[(size_t) t] = b.len;
    }
    g_plan.head_rows = T;
    sycl::queue* qp = kakasi::queue_for(backend);
    const bool up = qp && plan_upload(*qp);
    if (!up) g_err = "batch: plan upload failed";
    const std::vector<double> r = up ? prefill_top1(backend, toks, -1, 0) : std::vector<double>();
    if (qp) plan_free(*qp);
    g_plan.on = false;
    g_plan.slot.clear();
    g_plan.lo.clear();
    g_plan.hi.clear();
    g_plan.abspos.clear();
    if (r.empty()) return -1;
    for (int t = 0; t < T; ++t) {
        BatchSess& b = g_bs[(size_t) rowSess[(size_t) t]];
        if ((size_t) t < g_row_logits.size()) b.logits = g_row_logits[(size_t) t];
        b.last = toks[(size_t) t];
        b.len += 1;
    }
    return T;
}

// The tokens the last step emitted, one per conversation (-1 when a conversation is not live).
std::vector<int> batch_tokens() {
    std::vector<int> out((size_t) g_bslots, -1);
    for (int s = 0; s < g_bslots; ++s) out[(size_t) s] = g_bs[s].last;
    return out;
}

// The number of rows a conversation owns in the KV cache.
int batch_len(int s) {
    if (s < 0 || s >= g_bslots) return -1;
    return g_bs[s].len;
}

// End the batch and release the plan state.
void batch_end() {
    for (int s = 0; s < g_bslots; ++s) g_bs[s] = BatchSess();
    g_bhalf = 0;
    g_plan.on = false;
    g_plan.slot.clear();
    g_plan.lo.clear();
    g_plan.hi.clear();
    g_plan.abspos.clear();
}

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

// F391: the prompt-lookup drafter matches a fixed three-token tail. A shorter window finds more
// matches (more drafts, lower accuracy), a longer one finds fewer but better ones, so the window
// is a workload-dependent trade - it is a runtime setting here so two values can be measured in
// one binary, and the value in use is logged by the serve record's drafting counters.
int spec_window() {
    static int w = -1;
    if (w < 0) {
        // F392: default is the SHORT window; the widening rule (one wasted verify widens to 3 for
        // the rest of the call) removes what made it dangerous. Measured: 1.865x vs 1.758x on
        // text that repeats, 1.012x vs neutral on text that does not, wasted drafts 48 -> 16.
        w = 2;
#if defined(_MSC_VER)
#pragma warning(push)
#pragma warning(disable : 4996)
#endif
        const char* e = std::getenv("KAKASI_SPEC_WINDOW");
#if defined(_MSC_VER)
#pragma warning(pop)
#endif

        if (e && e[0] != ' ') {
            const int v = std::atoi(e);
            if (v >= 1 && v <= 6) w = v;
        }
    }
    return w;
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
    int m = spec_window();         // lookup window (F391: configurable, default 3)
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
            int take = std::min(acc, n_new - produced);
            // F389: an eos INSIDE the accepted block has to end the run there. Appending the
            // whole block and only then testing out.back() let the tokens after eos be
            // emitted - measured as a speculative response that continued into the next
            // turn's "Human:" text while plain decoding stopped at the sentence. Clamping
            // to the first eos (inclusive) makes the early exit below fire on the same token
            // plain decoding stops on; pos/kv_compact stay consistent because this always
            // breaks immediately afterwards.
            if (eos > 0) {
                for (int i = 0; i < take; ++i) {
                    if (draft[(size_t) i] == eos) {
                        take = i + 1;
                        break;
                    }
                }
            }
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
        // F392: a short window is only worth its risk while it is hitting. One wasted verify is
        // enough evidence to prefer the longer default for the rest of this call - F391 measured a
        // short window turning 48 wrong drafts into a 5.5% loss on text that does not repeat, and
        // the existing three-miss shutoff was the only thing bounding that.
        // F392: widen on the first miss, but keep counting misses so the shutoff still fires after
        // the same three as before - the SPEC-ADAPT gate caught the first version advancing the
        // counter only when it did not widen, which delayed the shutoff by one wasted verify.
        if (m < 3) {
            m = 3;
        }
        if (++misses >= 3) {
            drafting = false;
        }
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
    buf::BufferType* mgt = kakasi::buffer_type_for_queue(qp);
    uint32_t* dm = mgt ? (uint32_t*) buf::alloc_bt(mgt, mask.size() * sizeof(uint32_t)) : nullptr;
    if (!dm) { g_err = "tree verify: mask alloc failed"; g_row_pos.clear(); g_head_want.clear(); return {}; }
    qp->memcpy(dm, mask.data(), sizeof(uint32_t) * mask.size());
    qp->wait_and_throw();
    g_tree_mask = dm;
    const std::vector<int> out = prefill_tops(backend, tokens, pos);
    g_tree_mask = nullptr;
    g_row_pos.clear();
    g_head_want.clear();
    buf::free_bt(mgt, dm, mask.size() * sizeof(uint32_t));
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
            int take = std::min(bestAcc, n_new - produced);
            // F389: same rule as the chain path - stop at an eos inside the accepted block.
            if (eos > 0) {
                for (int i = 0; i < take; ++i) {
                    if (branches[(size_t) bestB][(size_t) i] == eos) {
                        take = i + 1;
                        break;
                    }
                }
            }
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
        int nSelf = 0;               // branches appended from the model's own root row (F323)
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
        // F323 (FR-06.2 step 3): prompt-lookup can only propose continuations the text ALREADY
        // contains, which is why max_branches never rose past 2 (F322) and why bins had no effect.
        // A self-seeded branch starts from the model's own root row instead: the seed is drawn
        // from p, so for that first token q = p and accepting it always is exact (the branch's
        // remainder still goes through the normal p(draft) test). It must be added BEFORE the tree
        // is flattened - otherwise the extra branch is never verified and only perturbs the RNG
        // stream, which is exactly the bug this comment documents. Inert at temp<=0, where p is a
        // point mass, so the greedy-tree gates keep their byte-for-byte guarantee.
        if (g_spec_selfseed && drafting && !greedy_like && (int) branches.size() < B) {
            const int excl = branches.empty() ? -1 : branches[0][(size_t) 0];
            for (int add = (int) branches.size(); add < B; ++add) {
                const int seed = sample_excluding(excl, temp);
                if (seed < 0) break;
                bool seen = false;
                for (size_t bi = 0; bi < branches.size(); ++bi) {
                    if (branches[bi][(size_t) 0] == seed) { seen = true; break; }
                }
                if (seen) continue;
                std::vector<int> br3;
                br3.push_back(seed);      // F326: ONE node, not a padded depth-D chain - see the
                                          // flatten below, which now takes each branch's own length
                branches.push_back(br3);
                ++nSelf;
                if ((int) branches.size() > g_spec_bmax) g_spec_bmax = (int) branches.size();
            }
        }

        // The mask is one uint32_t per node, so the flattened tree cannot exceed 32 rows; drop the
        // tail of the branch list rather than shift past the bitmap width.
        {
            int total = 0;
            for (size_t b = 0; b < branches.size(); ++b) {
                if (total + (int) branches[b].size() > 32) { branches.resize(b); break; }
                total += (int) branches[b].size();
            }
            if (branches.empty()) break;
        }

        // Flatten the tree. `want[node]` is the token this node must predict for its own branch,
        // or -1 at a branch's last node, where the draw is the bonus token instead.
        // F326: branches may differ in LENGTH (a self-seeded chain is one node deep, a lookup chain
        // is D), so every index goes through nodeOff instead of the fixed stride b*D. The mask is a
        // 32-bit bitmap, so the flattened node count is capped at 32.
        std::vector<int> nodes;
        std::vector<int> want;
        std::vector<int> depths;
        std::vector<int> nodeOff;
        std::vector<int> nodeLen;
        std::vector<uint32_t> mask;
        for (size_t b = 0; b < branches.size(); ++b) {
            const int len = (int) branches[b].size();
            nodeOff.push_back((int) nodes.size());    // keep the index parallel to branches
            nodeLen.push_back(len);
            if (len <= 0) continue;
            uint32_t bits = 0;
            for (int i = 0; i < len; ++i) {
                nodes.push_back(branches[b][(size_t) i]);
                depths.push_back(i);      // F308: logical depth, not the flat slot
                want.push_back((i + 1 < len) ? branches[b][(size_t) (i + 1)] : -1);
                bits |= (1u << (uint32_t) (nodeOff[(size_t) b] + i));
                mask.push_back(bits);
            }
        }
        if (nodes.size() > 32) break;

        // Root test first: every branch's first token is judged against the distribution that
        // produced `ref`, still live in g_last_logits, so no forward is needed for it. A
        // self-seeded branch starts from a token DRAWN from that same row, so q(seed) = p(seed)
        // and the exact test is accept-with-probability 1 (F323); rejecting it by the point-mass
        // rule would drop the chain's distribution off p for no reason.
        std::vector<char> rootAcc(branches.size(), 0);
        std::vector<int> rootTail(branches.size(), ref);
        const int selfFrom = (int) branches.size() - nSelf;
        int canonB = 0;
        double bestP = -1.0;
        for (size_t b = 0; b < branches.size(); ++b) {
            const int d0 = branches[b][(size_t) 0];
            const double p = token_prob(d0, temp);
            if (p > bestP) { bestP = p; canonB = (int) b; }
            if ((int) b >= selfFrom && nSelf > 0) {
                rootAcc[b] = 1;
            } else if (p >= 1.0) {
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

        // Longest accepted chain, each ending in its own correction (or bonus) draw. Branch lengths
        // differ (F326), so the walk stops at the branch's own length and indexes through nodeOff.
        int bestB = -1, bestRun = -1, bestTail = -1;
        for (size_t b = 0; b < branches.size(); ++b) {
            const int len = b < nodeLen.size() ? nodeLen[(size_t) b] : 0;
            int run = 0;
            int tail = rootTail[b];
            if (rootAcc[b] && len > 0) {
                run = 1;
                tail = -1;
                bool rejected = false;      // a real rejection produced this branch's correction
                for (int i = 1; i < len; ++i) {
                    const int pi = nodeOff[(size_t) b] + (i - 1);
                    if (pi >= (int) g_head_prob.size()) { run = -1; break; }
                    const double p = g_head_prob[(size_t) pi];
                    if (p >= 1.0 || (p > 0.0 && !greedy_like && rand01() < p)) { ++run; continue; }
                    tail = g_head_alt[(size_t) pi];
                    rejected = true;
                    break;
                }
                if (run >= 0 && !rejected) {
                    // Whole branch accepted: the node after the last draft gives the bonus token.
                    const int pi = nodeOff[(size_t) b] + len - 1;
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
        if (bestB > 0 && committed > 0) { ++g_spec_alt; if (g_spec_fix) kv_compact(backend, pos, pos + nodeOff[(size_t) bestB], committed); }
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
void set_spec_selfseed(int on) { g_spec_selfseed = on ? 1 : 0; }
int spec_selfseed_on() { return g_spec_selfseed; }

// F392: the drafter's lookup window in use, so a server can log it at startup. The window is a
// behaviour knob whose failure mode is silent - a wrong value looks like "the drafter had
// nothing to propose" - which is exactly the class of setting that must announce itself.
int spec_window_value() { return spec_window(); }

std::string spec_stats() {    char buf[220];
    std::snprintf(buf, sizeof(buf),
                  "drafts=%d accepted=%d verifies=%d alt_branch=%d max_branches=%d drafting=%s",
                  g_spec_drafts, g_spec_accepted, g_spec_verified, g_spec_alt, g_spec_bmax,
                  g_spec_on ? "on" : "off");
    return std::string(buf);
}

}  // namespace model
