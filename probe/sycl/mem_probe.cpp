// Reports per-device memory so the engine can decide residency vs streaming.
#include <sycl/sycl.hpp>
#include <cstdio>

int main() {
    for (const auto& p : sycl::platform::get_platforms()) {
        for (const auto& d : p.get_devices()) {
            const double gb = (double) d.get_info<sycl::info::device::global_mem_size>() / (double) (1ull << 30);
            printf("%-30s total=%6.2f GiB free=%6.2f GiB local=%6.2f MiB\n",
                   d.get_info<sycl::info::device::name>().c_str(), gb,
                   (double) d.get_info<sycl::info::device::global_mem_free>() / (double) (1ull << 30),
                   (double) d.get_info<sycl::info::device::local_mem_size>() / (double) (1u << 20));
        }
    }
    return 0;
}
