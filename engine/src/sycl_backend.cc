// SYCL backend: one persistent context+queue per device, validated before use.
// Compiled by icx -fsycl only; never includes phpx.h.
#include <sycl/sycl.hpp>

#include "sycl_backend.h"

#include <algorithm>
#include <cmath>
#include <memory>
#include <sstream>
#include <string>
#include <vector>

namespace kakasi {
namespace {

    struct Slot {
        sycl::device dev;
        std::shared_ptr<sycl::context> ctx;
        std::unique_ptr<sycl::queue> q;
        bool validated = false;
    };

    std::string g_error;

    // Raw device list, enumerated once. The same physical device can appear under
    // two platforms (Level-Zero and OpenCL); each entry gets its own Slot.
    const std::vector<sycl::device>& raw_devices() {
        static std::vector<sycl::device> cache;
        if (cache.empty()) {
            for (const auto& p : sycl::platform::get_platforms()) {
                for (const auto& d : p.get_devices()) cache.push_back(d);
            }
        }
        return cache;
    }

    std::vector<std::unique_ptr<Slot>>& slots() {
        static std::vector<std::unique_ptr<Slot>> s;
        return s;
    }

    bool in_range(int index) { return index >= 0 && index < (int) raw_devices().size(); }

    // Own context per device: sharing one default context across devices is what made
    // the probe's first submission on a non-default device come back as untouched memory.
    Slot* acquire(int index) {
        std::vector<std::unique_ptr<Slot>>& s = slots();
        if (s.size() <= (size_t) index) s.resize((size_t) index + 1);
        std::unique_ptr<Slot>& slot = s[(size_t) index];
        if (slot) return slot.get();

        try {
            slot = std::make_unique<Slot>();
            slot->dev = raw_devices()[(size_t) index];
            slot->ctx = std::make_shared<sycl::context>(slot->dev);
            // Declared in-order on purpose. Kernels here communicate through USM pointers, which
            // carry no dependency information, so submission order is the only thing that can
            // sequence them. Without this the backend may overlap kernels that read a buffer
            // another one is still writing - that is what produced wrong logits whenever the
            // per-operator drains were removed (findings F25/F26/F31).
            slot->q = std::make_unique<sycl::queue>(*slot->ctx, slot->dev,
                                                   sycl::property::queue::in_order{});
            // Warm the device: force runtime/module setup before any real work.
            float* probe_buf = sycl::malloc_device<float>(1, *slot->q);
            slot->q->single_task([=] { probe_buf[0] = 1.0f; });
            slot->q->wait_and_throw();
            float host = 0.0f;
            slot->q->memcpy(&host, probe_buf, sizeof(float));
            slot->q->wait_and_throw();
            sycl::free(probe_buf, *slot->q);
            if (host != 1.0f) {
                std::ostringstream os;
                os << "warm-up lost write on device " << index << " (got " << host << ")";
                g_error = os.str();
                slot.reset();
                return nullptr;
            }
            return slot.get();
        } catch (const std::exception& e) {
            g_error = std::string("acquire: ") + e.what();
            slot.reset();
            return nullptr;
        }
    }

}  // namespace

int device_count() { return (int) raw_devices().size(); }

// Shared with ops_sycl.cc (both are icx TUs): hand out the persistent validated queue.
sycl::queue* queue_for(int index) {
    // F652: acquire() indexes raw_devices() directly, so an out-of-range backend id would read past
    // the device list. Callers pass ids from code, but the failure path itself must be well-defined:
    // an invalid id has to come back as "no queue", not as undefined behaviour.
    if (!in_range(index)) {
        g_error = "backend index out of range";
        return nullptr;
    }
    Slot* slot = acquire(index);
    return slot ? slot->q.get() : nullptr;
}

std::string device_name(int index) {
    if (!in_range(index)) return "";
    return raw_devices()[(size_t) index].get_info<sycl::info::device::name>();
}

std::string last_error() { return g_error; }

// saxpy with a known answer: proves this device can run a kernel AND land results in host memory.
bool validate(int index) {
    g_error.clear();
    Slot* slot = acquire(index);
    if (!slot) return false;
    const int N = 1024;
    try {
        float* buf = sycl::malloc_device<float>(N, *slot->q);
        std::vector<float> init(N, 3.0f);
        slot->q->memcpy(buf, init.data(), sizeof(float) * N);
        slot->q->wait_and_throw();
        slot->q->parallel_for(sycl::range<1>(N), [=](sycl::item<1> it) {
            size_t i = it.get_id(0);
            buf[i] = buf[i] * 2.0f + 1.0f;
        });
        slot->q->wait_and_throw();
        std::vector<float> out(N, 0.0f);
        slot->q->memcpy(out.data(), buf, sizeof(float) * N);
        slot->q->wait_and_throw();
        sycl::free(buf, *slot->q);
        for (int i = 0; i < N; ++i) {
            if (out[i] != 7.0f) {
                std::ostringstream os;
                os << "validate mismatch at " << i << ": " << out[i] << " != 7";
                g_error = os.str();
                return false;
            }
        }
        slot->validated = true;
        return true;
    } catch (const std::exception& e) {
        g_error = std::string("validate: ") + e.what();
        return false;
    }
}

double matmul_maxerr(int index, int n) {
    g_error.clear();
    Slot* slot = acquire(index);
    if (!slot) return -1.0;
    if (!slot->validated && !validate(index)) {
        if (g_error.empty()) g_error = "device failed validation";
        return -1.0;
    }

    const size_t nn = (size_t) n * (size_t) n;
    try {
        std::vector<float> A(nn), B(nn), ref(nn), C(nn, 0.0f);
        for (size_t i = 0; i < nn; ++i) {
            A[i] = (float) ((i * 7) % 13) / 13.0f;
            B[i] = (float) ((i * 11) % 17) / 17.0f;
        }
        for (int i = 0; i < n; ++i)
            for (int j = 0; j < n; ++j) {
                float s = 0.0f;
                for (int k = 0; k < n; ++k) s += A[i * n + k] * B[k * n + j];
                ref[i * n + j] = s;
            }

        sycl::queue& q = *slot->q;
        float* dA = sycl::malloc_device<float>(nn, q);
        float* dB = sycl::malloc_device<float>(nn, q);
        float* dC = sycl::malloc_device<float>(nn, q);
        q.memcpy(dA, A.data(), sizeof(float) * nn);
        q.memcpy(dB, B.data(), sizeof(float) * nn);
        q.wait_and_throw();
        q.parallel_for(sycl::range<2>(n, n), [=](sycl::item<2> it) {
            size_t i = it.get_id(0);
            size_t j = it.get_id(1);
            float s = 0.0f;
            for (int k = 0; k < n; ++k) s += dA[i * n + k] * dB[k * n + j];
            dC[i * n + j] = s;
        });
        q.wait_and_throw();
        q.memcpy(C.data(), dC, sizeof(float) * nn);
        q.wait_and_throw();
        sycl::free(dA, q);
        sycl::free(dB, q);
        sycl::free(dC, q);

        double maxerr = 0.0;
        for (size_t i = 0; i < nn; ++i) maxerr = std::max(maxerr, (double) std::fabs(C[i] - ref[i]));
        return maxerr;
    } catch (const std::exception& e) {
        g_error = std::string("matmul: ") + e.what();
        return -1.0;
    }
}

}  // namespace kakasi
