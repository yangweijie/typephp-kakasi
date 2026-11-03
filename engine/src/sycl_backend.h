// Shared between the two icx-compiled translation units only.
#pragma once

#include <sycl/sycl.hpp>

namespace kakasi {
// Returns a persistent, warm, validated queue for the device, or nullptr if the
// device cannot be acquired/validated. Never creates a per-call queue.
sycl::queue* queue_for(int index);
}  // namespace kakasi
