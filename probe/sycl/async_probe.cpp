// F688 Phase-A probe (spec 2026-11-21): measure the serialization tax the split executor pays
// BEFORE proposing any async change. Zero engine code changes - this standalone probe measures,
// on the REAL devices the engine enumerates (platforms x devices, engine order):
//   1. device truth        - what b0/b1 actually are (cpu? gpu? mem)
//   2. wait-empty cost     - host round trip of wait_and_throw on an idle in-order queue
//   3. per-layer drain tax - 24-layer kernel chain: wait-per-layer (current engine shape,
//                            model_sycl.cc:2959) vs back-to-back enqueue (one wait at end)
//   4. handoff chain cost  - d2h -> pinned host -> h2d for T=1 (10 KB) and T=512 (5 MB):
//                            sync pattern (memcpy+wait x2, model_sycl.cc:2940-2943) vs
//                            event-chained cross-queue (the Phase-B primitive 2 feasibility test,
//                            per-device contexts exactly like sycl_backend.cc:85)
//   5. roofline inputs     - one-directional d2h/h2d bandwidth on 5 MB
//   6. independent timeline- queue enable_profiling events give per-kernel device busy vs wall,
//                            so the idle fraction comes from the RUNTIME's timestamps, not ours
// Every correctness-sensitive async shape verifies its bytes (F24: async on the CPU backend was
// silently wrong once - no async claim here without a data check).
// Output uses ==NAME=value== lines (repo log convention).
#include <sycl/sycl.hpp>
#include <algorithm>
#include <chrono>
#include <cstdio>
#include <cstring>
#include <vector>

using clk = std::chrono::steady_clock;
static double ms_since(clk::time_point t0) {
    return std::chrono::duration<double, std::milli>(clk::now() - t0).count();
}
static double median_of(std::vector<double>& v) {
    std::sort(v.begin(), v.end());
    return v[v.size() / 2];
}
static double p95_of(std::vector<double>& v) {
    std::sort(v.begin(), v.end());
    return v[(size_t)((v.size() - 1) * 0.95)];
}
static void rep(const char* k, double v) { std::printf("==%s=%.3f==\n", k, v); }

static const int D = 2560;                 // engine hidden width
static const int L = 24;                   // engine block count
static const size_t WNUM = (size_t)D * D;  // per-layer gemv reads 26.2 MB

// one realistic decode-layer: gemv (bandwidth-shaped) + tiny elementwise, both on ql
static void layer_kernels(sycl::queue& ql, const float* W, const float* x, float* y) {
    ql.parallel_for(sycl::range<1>((size_t)D), [=](sycl::item<1> it) {
        const size_t j = it.get_id(0);
        float s = 0.0f;
        for (int k = 0; k < D; ++k) s += x[k] * W[j * D + k];
        y[j] = s;
    });
    ql.parallel_for(sycl::range<1>((size_t)D), [=](sycl::item<1> it) { y[it.get_id(0)] += 1.0f; });
}

int main() {
    try {
        // ---- 1. device truth, engine enumeration order (sycl_backend.cc raw_devices) ----
        std::vector<sycl::device> devs;
        for (const auto& p : sycl::platform::get_platforms())
            for (const auto& d : p.get_devices()) devs.push_back(d);
        std::printf("==DEVICE_COUNT=%zu==\n", devs.size());
        for (size_t i = 0; i < devs.size(); ++i) {
            const auto& d = devs[i];
            const double gib = (double)d.get_info<sycl::info::device::global_mem_size>() / (1024.0 * 1024.0 * 1024.0);
            std::printf("==DEV_%zu name=%s cpu=%d gpu=%d mem=%.2fGiB==\n", i,
                        d.get_info<sycl::info::device::name>().c_str(),
                        (int)d.is_cpu(), (int)d.is_gpu(), gib);
        }
        if (devs.size() < 2) { std::printf("==ERR=needs_two_devices==\n"); return 1; }
        sycl::device& d0 = devs[0];
        sycl::device& d1 = devs[1];

        // engine-style: own context per device (sycl_backend.cc:85), in-order queues
        sycl::context c0(d0), c1(d1);
        sycl::queue q0(c0, d0, sycl::property::queue::in_order{});
        sycl::queue q1(c1, d1, sycl::property::queue::in_order{});

        // buffers: layer weights + activations on device0; second device mirror + pinned host stage
        float* W0 = sycl::malloc_device<float>(WNUM, q0);
        float* x0 = sycl::malloc_device<float>((size_t)D, q0);
        float* y0 = sycl::malloc_device<float>((size_t)D, q0);
        float* W1 = sycl::malloc_device<float>(WNUM, q1);
        float* y1 = sycl::malloc_device<float>((size_t)D, q1);
        float* stage = sycl::malloc_host<float>((size_t)D, q0);  // engine HostStage equivalent
        std::vector<float> hw(WNUM), hx((size_t)D);
        { uint32_t s = 123456789u;
          for (size_t i = 0; i < WNUM; ++i) { s = s * 1664525u + 1013904223u; hw[i] = ((s >> 9) & 0xFFFFu) / 65535.0f - 0.5f; }
          for (size_t i = 0; i < (size_t)D; ++i) { s = s * 1664525u + 1013904223u; hx[i] = ((s >> 9) & 0xFFFFu) / 65535.0f - 0.5f; } }
        q0.memcpy(W0, hw.data(), WNUM * sizeof(float)).wait();
        q0.memcpy(x0, hx.data(), sizeof(float) * D).wait();
        q1.memcpy(W1, hw.data(), WNUM * sizeof(float)).wait();
        q0.wait_and_throw(); q1.wait_and_throw();

        // ---- 2. wait_and_throw on an idle in-order queue ----
        { std::vector<double> v;
          for (int i = 0; i < 2000; ++i) { auto t = clk::now(); q0.wait_and_throw(); v.push_back(ms_since(t)); }
          rep("WAIT_EMPTY_US_MED", median_of(v) * 1000.0); rep("WAIT_EMPTY_US_P95", p95_of(v) * 1000.0); }

        // ---- 3. per-layer drain tax: 24-layer chain, wait-per-layer vs one-wait-at-end ----
        for (int w = 0; w < 3; ++w) { for (int l = 0; l < L; ++l) layer_kernels(q0, W0, x0, y0); q0.wait_and_throw(); }
        { std::vector<double> per_layer_sync, per_layer_async, pass_sync, pass_async;
          for (int r = 0; r < 20; ++r) {
              auto t0 = clk::now();
              for (int l = 0; l < L; ++l) { layer_kernels(q0, W0, x0, y0); q0.wait_and_throw(); }
              double wall = ms_since(t0); pass_sync.push_back(wall); per_layer_sync.push_back(wall / L);
          }
          for (int r = 0; r < 20; ++r) {
              auto t0 = clk::now();
              for (int l = 0; l < L; ++l) layer_kernels(q0, W0, x0, y0);
              q0.wait_and_throw();
              double wall = ms_since(t0); pass_async.push_back(wall); per_layer_async.push_back(wall / L);
          }
          rep("LAYER_SYNC_US_MED", median_of(per_layer_sync) * 1000.0);
          rep("LAYER_ASYNC_US_MED", median_of(per_layer_async) * 1000.0);
          rep("PASS_SYNC_MS_MED", median_of(pass_sync));
          rep("PASS_ASYNC_MS_MED", median_of(pass_async));
          const double tax = 100.0 * (median_of(pass_sync) - median_of(pass_async)) / median_of(pass_sync);
          rep("DRAIN_TAX_PCT", tax); }

        // ---- 4. handoff chain: sync (engine shape) vs event-chained cross-queue/cross-context ----
        auto check = [&](const char* tag) {
            std::vector<float> src((size_t)D), hchk((size_t)D);
            q0.memcpy(src.data(), y0, sizeof(float) * D).wait();
            q1.memcpy(hchk.data(), y1, sizeof(float) * D).wait();
            bool ok = std::memcmp(src.data(), hchk.data(), sizeof(float) * D) == 0;
            std::printf("==EVENT_CHAIN_%s=%s==\n", tag, ok ? "OK" : "MISMATCH");
            return ok;
        };
        // T=1 (10 KB)
        { std::vector<double> vs, ve; bool ok = true;
          for (int r = 0; r < 60; ++r) {   // sync: memcpy+wait, memcpy+wait (engine :2940-2943)
              auto t = clk::now();
              q0.memcpy(stage, y0, sizeof(float) * D).wait();
              q1.memcpy(y1, stage, sizeof(float) * D).wait();
              vs.push_back(ms_since(t));
          }
          for (int r = 0; r < 60; ++r) {   // event-chained: zero host waits inside
              auto t = clk::now();
              sycl::event e0 = q0.memcpy(stage, y0, sizeof(float) * D);
              sycl::event e1 = q1.memcpy(y1, stage, sizeof(float) * D, std::vector<sycl::event>{e0});
              e1.wait();
              ve.push_back(ms_since(t));
          }
          ok = check("T1");
          rep("HANDOFF1_SYNC_US_MED", median_of(vs) * 1000.0);
          rep("HANDOFF1_EVENT_US_MED", ok ? median_of(ve) * 1000.0 : -1.0); }
        // T=512 (5 MB) on a second pair of buffers
        { const size_t bytes = (size_t)D * 512 * sizeof(float);
          float* a0 = sycl::malloc_device<float>((size_t)D * 512, q0);
          float* a1 = sycl::malloc_device<float>((size_t)D * 512, q1);
          float* stage5 = sycl::malloc_host<float>((size_t)D * 512, q0);
          q0.memcpy(a0, hw.data(), sizeof(float) * (size_t)D * 512).wait();
          std::vector<double> vs, ve;
          for (int r = 0; r < 40; ++r) {
              auto t = clk::now();
              q0.memcpy(stage5, a0, bytes).wait();
              q1.memcpy(a1, stage5, bytes).wait();
              vs.push_back(ms_since(t));
          }
          for (int r = 0; r < 40; ++r) {
              auto t = clk::now();
              sycl::event e0 = q0.memcpy(stage5, a0, bytes);
              sycl::event e1 = q1.memcpy(a1, stage5, bytes, std::vector<sycl::event>{e0});
              e1.wait();
              ve.push_back(ms_since(t));
          }
          std::vector<float> src5((size_t)D * 512), chk5((size_t)D * 512);
          q0.memcpy(src5.data(), a0, bytes).wait();
          q1.memcpy(chk5.data(), a1, bytes).wait();
          std::printf("==EVENT_CHAIN_T512=%s==\n", std::memcmp(src5.data(), chk5.data(), bytes) == 0 ? "OK" : "MISMATCH");
          rep("HANDOFF512_SYNC_US_MED", median_of(vs) * 1000.0);
          rep("HANDOFF512_EVENT_US_MED", median_of(ve) * 1000.0);
          rep("BW_ROUNTRIP_GBS", (double)bytes * 2.0 / (median_of(vs) / 1000.0) / 1e9);
          sycl::free(a0, q0); sycl::free(a1, q1); sycl::free(stage5, q0); }

        // ---- 5. one-directional bandwidth for the roofline (5 MB d2h, 5 MB h2d) ----
        { const size_t bytes = (size_t)D * 512 * sizeof(float);
          float* a0 = sycl::malloc_device<float>((size_t)D * 512, q0);
          float* stage5 = sycl::malloc_host<float>((size_t)D * 512, q0);
          q0.memcpy(a0, hw.data(), bytes).wait();
          std::vector<double> d2h, h2d;
          for (int r = 0; r < 40; ++r) { auto t = clk::now(); q0.memcpy(stage5, a0, bytes).wait(); d2h.push_back(ms_since(t)); }
          for (int r = 0; r < 40; ++r) { auto t = clk::now(); q0.memcpy(a0, stage5, bytes).wait(); h2d.push_back(ms_since(t)); }
          rep("BW_D2H_GBS", (double)bytes / (median_of(d2h) / 1000.0) / 1e9);
          rep("BW_H2D_GBS", (double)bytes / (median_of(h2d) / 1000.0) / 1e9);
          sycl::free(a0, q0); sycl::free(stage5, q0); }

        // ---- 6. independent timeline: runtime event profiling on the 24-layer chain ----
        { sycl::queue pq0(c0, d0, {sycl::property::queue::in_order{}, sycl::property::queue::enable_profiling{}});
          auto chain_prof = [&](bool per_layer_wait) {
              std::vector<sycl::event> evs;
              auto t0 = clk::now();
              for (int l = 0; l < L; ++l) {
                  sycl::event e1 = pq0.parallel_for(sycl::range<1>((size_t)D), [=](sycl::item<1> it) {
                      const size_t j = it.get_id(0);
                      float s = 0.0f;
                      for (int k = 0; k < D; ++k) s += x0[k] * W0[j * D + k];
                      y0[j] = s;
                  });
                  sycl::event e2 = pq0.parallel_for(sycl::range<1>((size_t)D), [=](sycl::item<1> it) { y0[it.get_id(0)] += 1.0f; });
                  evs.push_back(e1); evs.push_back(e2);
                  if (per_layer_wait) pq0.wait_and_throw();
              }
              pq0.wait_and_throw();
              double wall_host = ms_since(t0);
              std::vector<double> st, en;
              for (auto& e : evs) {
                  st.push_back((double)e.get_profiling_info<sycl::info::event_profiling::command_start>() / 1e6);
                  en.push_back((double)e.get_profiling_info<sycl::info::event_profiling::command_end>() / 1e6);
              }
              double dev_first = *std::min_element(st.begin(), st.end());
              double dev_last = *std::max_element(en.begin(), en.end());
              double busy = 0.0;
              for (size_t i = 0; i < evs.size(); ++i) busy += en[i] - st[i];
              double dev_wall = dev_last - dev_first;
              double idle = dev_wall > 0 ? 100.0 * (1.0 - busy / dev_wall) : 0.0;
              std::printf("==PROFILE_%s dev_wall_ms=%.3f busy_ms=%.3f idle_pct=%.2f host_wall_ms=%.3f==\n",
                          per_layer_wait ? "SYNC" : "ASYNC", dev_wall, busy, idle, wall_host);
              return idle;
          };
          for (int w = 0; w < 2; ++w) { chain_prof(true); chain_prof(false); }
          sycl::free(W0, q0); sycl::free(x0, q0); sycl::free(y0, q0);
          sycl::free(W1, q1); sycl::free(y1, q1);
          sycl::free(stage, q0); }
        std::printf("==PROBE_DONE==\n");
        return 0;
    } catch (const sycl::exception& e) {
        std::printf("==SYCL_ERR=%s==\n", e.what()); return 2;
    } catch (const std::exception& e) {
        std::printf("==STD_ERR=%s==\n", e.what()); return 3;
    }
}
