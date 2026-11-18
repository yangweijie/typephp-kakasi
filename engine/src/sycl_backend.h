// Shared between the two icx-compiled translation units only.
#pragma once

#include <sycl/sycl.hpp>

#include "buffer.h"

namespace kakasi {
// Returns a persistent, warm, validated queue for the device, or nullptr if the
// device cannot be acquired/validated. Never creates a per-call queue.
sycl::queue* queue_for(int index);

// F675 Stage 1: the device's buffer_type - allocation, alignment and live-byte accounting
// for exactly this device's memory class. Lazily created with the slot and registered
// with the shared host ledger so the release discipline (who allocated, who frees, on
// which queue) has one reportable owner. Invalid index => nullptr.
buf::BufferType* buffer_type_for(int index);
// The buffer_type that owns a given persistent queue, or nullptr if that queue does not
// belong to one of our device slots. This is what the model layer calls with the
// sycl::queue& it already holds - it is not handed a backend index at most call sites.
buf::BufferType* buffer_type_for_queue(const sycl::queue* q);
}  // namespace kakasi
