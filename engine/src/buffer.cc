// F675 Stage 1 (FR-02.1): the shared host buffer_type, the allocation ledger, and the
// two one-line routing helpers. Compiled by cl - pure C++, no SYCL, so the contract
// lives in a translation unit that never pulls in <sycl/sycl.hpp>.
#include "buffer.h"

#include <algorithm>
#include <cstdio>

namespace buf {
namespace {
    HostBufferType g_host;
    Ledger g_ledger;
}  // namespace

HostBufferType& host() { return g_host; }
Ledger& ledger() { return g_ledger; }

// F675 Stage 1: one-line allocation and release helpers. alloc_bt registers the
// pointer with the ledger before returning it; null (allocation failure) registers
// nothing, so a failed allocation cannot leave an ownership record behind.
void* alloc_bt(BufferType* bt, size_t bytes) {
    void* p = bt->alloc(bytes);
    if (p) ledger().note_alloc(p, bytes, bt);
    return p;
}

void free_bt(BufferType* bt, void* p, size_t bytes) {
    if (!p) return;
    ledger().note_free(p, bytes, bt);
    bt->deallocate(p, bytes);
}

// ---- Ledger -----------------------------------------------------------

void Ledger::note_alloc(void* p, size_t bytes, const BufferType* bt) {
    if (!p) return;
    if (live.find(p) != live.end()) {
        ++anomalies;
        last_anomaly_ = "double-alloc " + bt->name();
        return;
    }
    live[(const void*) p] = {bytes, bt};
    register_bt(bt);
}

void Ledger::note_free(void* p, size_t bytes, const BufferType* bt) {
    auto it = live.find(p);
    if (it == live.end()) {
        ++anomalies;
        last_anomaly_ = "free of unknown pointer " + bt->name();
        return;
    }
    if (it->second.bt != bt) {
        // The pointer was allocated from a different memory class and freed from this
        // one - the exact class of hazard the split run used to invite (a weight freed
        // on the wrong device). Counted, not fatal: the owning queue is the enforcement
        // point, the ledger only makes it visible.
        ++anomalies;
        last_anomaly_ = "free from wrong buffer_type " + std::string(it->second.bt->name())
                        + " -> " + bt->name();
    }
    live.erase(it);
    (void) bytes;
}

bool Ledger::known(void* p) const {
    return p && live.find((const void*) p) != live.end();
}

size_t Ledger::live_bytes(const BufferType* bt) const {
    size_t sum = 0;
    for (const auto& kv : live) sum += (kv.second.bt == bt) ? kv.second.bytes : 0;
    return sum;
}

size_t Ledger::live_count(const BufferType* bt) const {
    size_t n = 0;
    for (const auto& kv : live) n += (kv.second.bt == bt) ? 1 : 0;
    return n;
}

std::string Ledger::report() const {
    std::vector<const BufferType*> order = bts;
    std::sort(order.begin(), order.end(),
              [](const BufferType* a, const BufferType* b) { return a->name() < b->name(); });
    std::string out;
    for (size_t i = 0; i < order.size(); ++i) {
        if (i) out += "|";
        char line[96];
        std::snprintf(line, sizeof(line), "%s:%.1fMiB", order[i]->name().c_str(),
                      live_bytes(order[i]) / (double) (1 << 20));
        out += line;
    }
    if (anomalies) {
        char tail[32];
        std::snprintf(tail, sizeof(tail), " anomalies=%d", anomalies);
        out += tail;
    }
    return out.empty() ? std::string("(none)") : out;
}

void Ledger::register_bt(const BufferType* bt) {
    if (std::find(bts.begin(), bts.end(), bt) == bts.end()) bts.push_back(bt);
}

}  // namespace buf
