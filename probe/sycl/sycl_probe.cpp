// Phase 0 probe: SYCL matmul on every available device, checked against a CPU reference.
#include <sycl/sycl.hpp>
#include <chrono>
#include <cmath>
#include <cstdio>
#include <vector>

static const int N = 256;

static void cpu_matmul(const std::vector<float>& A, const std::vector<float>& B, std::vector<float>& C) {
    for (int i = 0; i < N; ++i)
        for (int j = 0; j < N; ++j) {
            float s = 0.0f;
            for (int k = 0; k < N; ++k) s += A[i * N + k] * B[k * N + j];
            C[i * N + j] = s;
        }
}

static bool run_on(const sycl::device& dev, const std::vector<float>& A, const std::vector<float>& B,
                   const std::vector<float>& ref) {
    try {
        std::vector<float> C(N * N, 0.0f);
        auto t0 = std::chrono::steady_clock::now();
        {
            sycl::queue q(dev);
            size_t bytes = sizeof(float) * N * N;
            float* dA = sycl::malloc_device<float>(N * N, q);
            float* dB = sycl::malloc_device<float>(N * N, q);
            float* dC = sycl::malloc_device<float>(N * N, q);
            q.memcpy(dA, A.data(), bytes);
            q.memcpy(dB, B.data(), bytes);
            q.wait();
            q.parallel_for(sycl::nd_range<2>(sycl::range<2>(N, N), sycl::range<2>(16, 16)),
                           [=](sycl::nd_item<2> it) {
                               int i = (int)it.get_global_id(0);
                               int j = (int)it.get_global_id(1);
                               float s = 0.0f;
                               for (int k = 0; k < N; ++k) s += dA[i * N + k] * dB[k * N + j];
                               dC[i * N + j] = s;
                           });
            q.memcpy(C.data(), dC, bytes);
            q.wait();
            sycl::free(dA, q);
            sycl::free(dB, q);
            sycl::free(dC, q);
        }
        auto t1 = std::chrono::steady_clock::now();
        double ms = std::chrono::duration<double, std::milli>(t1 - t0).count();
        double maxerr = 0.0;
        for (size_t i = 0; i < C.size(); ++i) maxerr = std::max(maxerr, (double)std::fabs(C[i] - ref[i]));
        printf("  %-28s %s  maxerr=%.4f  %.2f ms\n", dev.get_info<sycl::info::device::name>().c_str(),
               maxerr < 1e-2 ? "PASS" : "FAIL", maxerr, ms);
        return maxerr < 1e-2;
    } catch (const sycl::exception& e) {
        printf("  %-28s SKIP (sycl: %s)\n", dev.get_info<sycl::info::device::name>().c_str(), e.what());
        return false;
    }
}

int main() {
    std::vector<float> A(N * N), B(N * N), REF(N * N);
    for (int i = 0; i < N * N; ++i) {
        A[i] = (float)((i * 7) % 13) / 13.0f;
        B[i] = (float)((i * 11) % 17) / 17.0f;
    }
    cpu_matmul(A, B, REF);

    int devices = 0, passed = 0;
    for (const auto& p : sycl::platform::get_platforms()) {
        printf("platform: %s\n", p.get_info<sycl::info::platform::name>().c_str());
        for (const auto& d : p.get_devices()) {
            ++devices;
            if (run_on(d, A, B, REF)) ++passed;
        }
    }
    printf("RESULT: %d device(s) seen, %d matmul PASS\n", devices, passed);
    return passed >= 2 ? 0 : 1;
}
