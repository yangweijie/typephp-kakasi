// SYCL side of the probe. Compiled by Intel DPC++ (icx -fsycl), never by cl, and it
// deliberately does not include phpx.h so the SYCL toolchain stays out of the PHP build.
#include <sycl/sycl.hpp>

#include <algorithm>
#include <cmath>
#include <string>
#include <vector>

namespace kakasi {
namespace {
    std::string g_error;

    const std::vector<sycl::device>& devices() {
        static std::vector<sycl::device> cache;
        if (cache.empty()) {
            for (const auto& p : sycl::platform::get_platforms()) {
                for (const auto& d : p.get_devices()) cache.push_back(d);
            }
        }
        return cache;
    }
}  // namespace

int device_count() { return (int) devices().size(); }

std::string device_name(int index) {
    if (index < 0 || index >= (int) devices().size()) return "";
    return devices()[(size_t) index].get_info<sycl::info::device::name>();
}

std::string last_error() { return g_error; }

double matmul_maxerr(int index, int n) {
    g_error.clear();
    if (index < 0 || index >= (int) devices().size()) {
        g_error = "device index out of range";
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

        sycl::queue q(devices()[(size_t) index]);
        float* dA = sycl::malloc_device<float>(nn, q);
        float* dB = sycl::malloc_device<float>(nn, q);
        float* dC = sycl::malloc_device<float>(nn, q);
        q.memcpy(dA, A.data(), sizeof(float) * nn);
        q.memcpy(dB, B.data(), sizeof(float) * nn);
        q.wait();
        q.parallel_for(sycl::range<2>(n, n), [=](sycl::item<2> it) {
            int i = (int) it.get_id(0);
            int j = (int) it.get_id(1);
            float s = 0.0f;
            for (int k = 0; k < n; ++k) s += dA[i * n + k] * dB[k * n + j];
            dC[i * n + j] = s;
        });
        q.memcpy(C.data(), dC, sizeof(float) * nn);
        q.wait();
        sycl::free(dA, q);
        sycl::free(dB, q);
        sycl::free(dC, q);

        double maxerr = 0.0;
        for (size_t i = 0; i < nn; ++i) maxerr = std::max(maxerr, (double) std::fabs(C[i] - ref[i]));
        return maxerr;
    } catch (const std::exception& e) {
        g_error = e.what();
        return -1.0;
    }
}

}  // namespace kakasi
