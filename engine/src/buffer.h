// F675 Stage 1 (FR-02.1): the unified buffer_type contract.
//
// The engine used to own its memory ad hoc - raw sycl::malloc_device calls scattered through
// model_sycl.cc, a global KVCache, a global Residency, per-queue arenas, and a bare
// std::vector handoff buffer. This header makes "which memory class does this pointer belong
// to" a first-class question: every device buffer is spoken through a BufferType, and the
// host buffer_type makes the segment-boundary staging an explicit buffer rather than a side
// effect. The scheduler (Stages 2/3) will consult the same contract when it decides where a
// tensor lives and what crosses a boundary.
//
// Contract rules (kept deliberately small, ggml's buffer_type shape):
//   alloc/deallocate   - fail-soft: a failed alloc is null + last error, never an exception
//                        thrown into the forward pass (repo-wide discipline, F9/F25).
//   alignment          - the minimum alignment every handed-out pointer satisfies.
//   max_size           - 0 = unbounded, otherwise the live-byte ceiling for this class
//                        (the residency budget on a device; host is unbounded).
//   tensor_size        - ggml-style row padding: a row-major tensor of `elems` elements of
//                        `elem_bytes` each occupies this many bytes. Kernels may rely on rows
//                        landing on a 64-byte boundary.
//
// Compiled by both cl (this header, pure C++) and icx -fsycl (the device implementation in
// sycl_backend.cc).

#pragma once

#include <cstddef>
#include <cstdint>
#include <map>
#include <string>
#include <vector>

#ifdef _WIN32
#include <malloc.h>
#else
#include <stdlib.h>
#endif

namespace buf {

// 64-byte row alignment for tensor_size: matches the ggml buffer_type convention the plan
// cites as its design model, and is comfortable for every kernel we ship (float4 loads need
// 16, this just gives rows a little more).
constexpr size_t ROW_ALIGN = 64;

struct BufferType {
    virtual ~BufferType() {}
    // Allocate `bytes` in this memory class. null on failure (allocation failures never
    // throw into the forward pass; the caller checks and sets its own error).
    virtual void* alloc(size_t bytes) = 0;
    // Release a pointer from alloc(), exactly once, with the original size. The size is
    // carried because the Windows host path (aligned_alloc) needs it and it lets the ledger
    // detect double-frees.
    virtual void deallocate(void* p, size_t bytes) = 0;
    virtual size_t alignment() const = 0;
    virtual size_t max_size() const = 0;
    virtual size_t tensor_size(size_t elems, size_t elem_bytes) const = 0;
    // Report name, unique per instance ("b0", "b1", ..., "host").
    virtual std::string name() const = 0;
};

// Pinned host buffer_type, the staging class for every boundary handoff. It is the only
// buffer_type with an unbounded max_size and it never touches a device queue.
struct HostBufferType final : BufferType {
    void* alloc(size_t bytes) override {
        void* p = nullptr;
#ifdef _WIN32
        p = _aligned_malloc(bytes ? bytes : 1, ROW_ALIGN);
#else
        p = aligned_alloc(ROW_ALIGN, (bytes + ROW_ALIGN - 1) & ~(ROW_ALIGN - 1));
#endif
        return p;
    }
    void deallocate(void* p, size_t) override {
        if (!p) return;
#ifdef _WIN32
        _aligned_free(p);
#else
        free(p);
#endif
    }
    size_t alignment() const override { return ROW_ALIGN; }
    size_t max_size() const override { return SIZE_MAX; }
    size_t tensor_size(size_t elems, size_t elem_bytes) const override {
        const size_t raw = elems * elem_bytes;
        return (raw + ROW_ALIGN - 1) & ~(ROW_ALIGN - 1);
    }
    std::string name() const override { return "host"; }
};

// The shared host instance. Staging across a segment boundary must come from ONE place so
// the ledger can name it; a per-call local would be invisible to "who owns these bytes".
HostBufferType& host();

// Allocation accounting for everything routed through a BufferType: KV rows, boundary
// scratch, arena blocks, resident weights, host staging. It answers "who owns which bytes"
// with a single report, and it makes the release discipline checkable: a pointer freed
// outside its owning buffer_type, or twice, is a visible error instead of a silent hole.
//
// Single-threaded by construction (one forward pass at a time, in-order queues), so no lock.
class Ledger {
public:
    void note_alloc(void* p, size_t bytes, const BufferType* bt);
    void note_free(void* p, size_t bytes, const BufferType* bt);
    bool known(void* p) const;
    size_t live_bytes(const BufferType* bt) const;
    size_t live_count(const BufferType* bt) const;
    // "b0:2 items,3.1MiB|host:1 items,0.0MiB" - one field per registered buffer_type.
    // Sorted by name so the string is deterministic no matter the registration order
    // (host registers on first use, devices register lazily as slots are acquired).
    std::string report() const;
    // Device buffer_types register here so report() knows the full set. The host instance is
    // registered once at static init.
    void register_bt(const BufferType* bt);
    // A double-free or a free from the wrong buffer_type: counted, not fatal - the report
    // surfaces it, and the caller's own bookkeeping (which queue allocated the pointer) is
    // the enforcement point.
    int anomaly_count() const { return anomalies; }
    const std::string& last_anomaly() const { return last_anomaly_; }

private:
    struct Entry { size_t bytes; const BufferType* bt; };
    std::map<const void*, Entry> live;
    std::vector<const BufferType*> bts;
    int anomalies = 0;
    std::string last_anomaly_;
};

// The one shared ledger. Every alloc/deallocate routed through a BufferType goes through
// these two helpers, so the call sites stay one line each. alloc_bt registers the
// allocation with the ledger before returning; null (allocation failure) registers nothing.
// A mutable BufferType* is required: the virtuals are non-const (they own the allocator).
Ledger& ledger();
void* alloc_bt(BufferType* bt, size_t bytes);
void free_bt(BufferType* bt, void* p, size_t bytes);

}  // namespace buf
