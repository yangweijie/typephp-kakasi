// F694 A-section kernel-math probe (no acpp needed): the five engine hot-path kernels, written
// in OpenCL C with the engine's exact structure, run on this machine's Apple M4 GPU through the
// SYSTEM OpenCL runtime. This answers the native-plugin track's core feasibility question -
// does the engine's kernel math run CORRECTLY on a non-Intel GPU - without waiting for the
// AdaptiveCpp toolchain. Kernel structure mirrors model_sycl.cc:649 (gemvM, TG=64 butterfly),
// ops_sycl.cc:246 (tiled SGEMM 16/16/32), ops_sycl.cc:160 (Q8_0 dequant block loop). Every op
// is checked against an independent CPU reference; PASS/FAIL is judged on correctness only,
// speed is REPORTED (F688 rule).
//
// Build: clang++ -O2 -std=c++17 ocl_kernels_probe.cpp -o ocl_kernels_probe -framework OpenCL
#include <OpenCL/opencl.h>
#include <cstdio>
#include <cstdint>
#include <cstring>
#include <cstdlib>
#include <cmath>
#include <vector>
#include <chrono>

namespace {
constexpr int D = 896;
constexpr int FFN = 4864;
constexpr int HD = 64;
constexpr int TG = 64;
constexpr int TM = 16, TN = 16, TK = 32;

double now_ms() {
    using namespace std::chrono;
    return duration<double, std::milli>(steady_clock::now().time_since_epoch()).count();
}

struct CL {
    cl_platform_id plat = nullptr;
    cl_device_id dev = nullptr;
    cl_context ctx = nullptr;
    cl_command_queue q = nullptr;
    bool ok = false;
};

bool cl_init(CL& c, bool want_gpu) {
    cl_uint np = 0;
    if (clGetPlatformIDs(0, nullptr, &np) != CL_SUCCESS || np == 0) return false;
    std::vector<cl_platform_id> plats(np);
    clGetPlatformIDs(np, plats.data(), nullptr);
    for (cl_platform_id p : plats) {
        cl_uint nd = 0;
        if (clGetDeviceIDs(p, want_gpu ? CL_DEVICE_TYPE_GPU : CL_DEVICE_TYPE_ALL, 0, nullptr, &nd) != CL_SUCCESS || nd == 0) continue;
        std::vector<cl_device_id> devs(nd);
        clGetDeviceIDs(p, want_gpu ? CL_DEVICE_TYPE_GPU : CL_DEVICE_TYPE_ALL, nd, devs.data(), nullptr);
        c.plat = p; c.dev = devs[0];
        c.ctx = clCreateContext(nullptr, 1, &c.dev, nullptr, nullptr, nullptr);
        if (!c.ctx) continue;
        c.q = clCreateCommandQueue(c.ctx, c.dev, 0, nullptr);
        c.ok = (c.q != nullptr);
        return c.ok;
    }
    return false;
}

cl_program build(CL& c, const char* src) {
    cl_int err = 0;
    cl_program p = clCreateProgramWithSource(c.ctx, 1, &src, nullptr, &err);
    if (!p || err != CL_SUCCESS) return nullptr;
    if (clBuildProgram(p, 1, &c.dev, nullptr, nullptr, nullptr) != CL_SUCCESS) {
        char log[8192] = {0};
        clGetProgramBuildInfo(p, c.dev, CL_PROGRAM_BUILD_LOG, sizeof(log), log, nullptr);
        std::printf("OCLK-PROBE build log: %.1500s\n", log);
        clReleaseProgram(p);
        return nullptr;
    }
    return p;
}

struct Check { double maxdiff; double rel; };
Check cmp(const std::vector<float>& got, const std::vector<float>& ref) {
    Check c{0.0, 0.0};
    double refmax = 1e-30;
    for (size_t i = 0; i < ref.size(); ++i) {
        const double d = std::fabs((double) got[i] - (double) ref[i]);
        if (d > c.maxdiff) c.maxdiff = d;
        const double a = std::fabs((double) ref[i]);
        if (a > refmax) refmax = a;
    }
    c.rel = c.maxdiff / refmax;
    return c;
}

double bench(CL& c, cl_kernel k, size_t g, size_t l, int iters) {
    const double t0 = now_ms();
    for (int i = 0; i < iters; ++i) {
        clEnqueueNDRangeKernel(c.q, k, 1, nullptr, &g, &l, 0, nullptr, nullptr);
        clFinish(c.q);
    }
    return (now_ms() - t0) / (double) iters;
}
}  // namespace

// ==================== OpenCL C kernels (engine structure verbatim) ====================
static const char* kGemvM2 =
"#pragma OPENCL EXTENSION cl_khr_fp16 : enable\n"
"__kernel void gemvM2(__global const float* X, __global const float* W, __global const float* bias,\n"
"                     __global float* Y, const int K, const int N) {\n"
"    __local float redA[64];\n"
"    __local float redB[64];\n"
"    const int row = get_group_id(0);\n"
"    const int lane = get_local_id(0);\n"
"    const int base = row * K;\n"
"    float a0 = 0.0f, a1 = 0.0f;\n"
"    for (int k = lane; k < K; k += 64) {\n"
"        const float wv = W[base + k];\n"
"        a0 += X[k] * wv;\n"
"        a1 += X[K + k] * wv;\n"
"    }\n"
"    redA[lane] = a0; redB[lane] = a1;\n"
"    barrier(CLK_LOCAL_MEM_FENCE);\n"
"    for (int s = 32; s > 0; s >>= 1) {\n"
"        if (lane < s) { redA[lane] += redA[lane + s]; redB[lane] += redB[lane + s]; }\n"
"        barrier(CLK_LOCAL_MEM_FENCE);\n"
"    }\n"
"    Y[row] = redA[0] + bias[row];\n"
"    Y[N + row] = redB[0] + bias[row];\n"
"}\n";

// 2D tiled GEMM (local range (16,16), 32-wide k tiles):

static const char* kGemm2D =
"__kernel void tiled_gemm2(__global const float* X, __global const float* W, __global float* Y,\n"
"                          const int M, const int N, const int K) {\n"
"    __local float Xs[16][32];\n"
"    __local float Ws[16][32];\n"
"    const int tm = get_local_id(0);\n"
"    const int tn = get_local_id(1);\n"
"    const int row = get_group_id(0) * 16 + tm;\n"
"    const int col = get_group_id(1) * 16 + tn;\n"
"    float acc = 0.0f;\n"
"    for (int kt = 0; kt < K; kt += 32) {\n"
"        if (row < M) { for (int t = 0; t < 32; ++t) Xs[tm][t] = (kt + t < K) ? X[row * K + kt + t] : 0.0f; }\n"
"        if (col < N) { for (int t = 0; t < 32; ++t) Ws[tn][t] = (kt + t < K) ? W[col * K + kt + t] : 0.0f; }\n"
"        barrier(CLK_LOCAL_MEM_FENCE);\n"
"        for (int t = 0; t < 32; ++t) acc += Xs[tm][t] * Ws[tn][t];\n"
"        barrier(CLK_LOCAL_MEM_FENCE);\n"
"    }\n"
"    if (row < M && col < N) Y[row * N + col] = acc;\n"
"}\n";

static const char* kDequant =
"#pragma OPENCL EXTENSION cl_khr_fp16 : enable\n"
"__kernel void dequant_q8(__global const uchar* raw, __global float* out, const ulong nblocks) {\n"
"    const ulong b = get_global_id(0);\n"
"    if (b >= nblocks) return;\n"
"    __global const uchar* p = raw + b * 34;\n"
"    const ushort hb = (ushort)(p[0] | ((ushort) p[1] << 8));\n"
"    const float d = (float) as_half(hb);\n"
"    for (int i = 0; i < 32; ++i) out[b * 32 + i] = d * (float) ((int) ((char) p[2 + i]));\n"
"}\n";

static const char* kRmsnorm =
"__kernel void rmsnorm(__global const float* x, __global const float* w, __global float* out,\n"
"                      const int n, const float eps) {\n"
"    __local float red[64];\n"
"    const int lane = get_local_id(0);\n"
"    float a = 0.0f;\n"
"    for (int i = lane; i < n; i += 64) a += x[i] * x[i];\n"
"    red[lane] = a;\n"
"    barrier(CLK_LOCAL_MEM_FENCE);\n"
"    for (int s = 32; s > 0; s >>= 1) {\n"
"        if (lane < s) red[lane] += red[lane + s];\n"
"        barrier(CLK_LOCAL_MEM_FENCE);\n"
"    }\n"
"    if (lane == 0) red[0] = rsqrt(red[0] / (float) n + eps);\n"
"    barrier(CLK_LOCAL_MEM_FENCE);\n"
"    const float inv = red[0];\n"
"    for (int i = lane; i < n; i += 64) out[i] = x[i] * inv * w[i];\n"
"}\n";

static const char* kAttn =
"__kernel void attn_scores(__global const float* Q, __global const float* Kc, __global float* S,\n"
"                          const int rows, const float scale) {\n"
"    __local float red[64];\n"
"    const int row = get_group_id(0);\n"
"    if (row >= rows) return;\n"
"    const int lane = get_local_id(0);\n"
"    const int base = row * 64;\n"
"    float a = 0.0f;\n"
"    for (int i = lane; i < 64; i += 64) a += Q[i] * Kc[base + i];\n"
"    red[lane] = a;\n"
"    barrier(CLK_LOCAL_MEM_FENCE);\n"
"    for (int s = 32; s > 0; s >>= 1) {\n"
"        if (lane < s) red[lane] += red[lane + s];\n"
"        barrier(CLK_LOCAL_MEM_FENCE);\n"
"    }\n"
"    if (lane == 0) S[row] = red[0] * scale;\n"
"}\n";

int main() {
    CL c;
    if (!cl_init(c, true)) { std::printf("OCLK-PROBE: FAIL no GPU context\n"); return 1; }
    char dn[256] = {0};
    clGetDeviceInfo(c.dev, CL_DEVICE_NAME, sizeof(dn), dn, nullptr);
    std::printf("OCLK-PROBE device: %s\n", dn);

    int failures = 0;

    // ---------- op 1: gemvM2 at both layer shapes ----------
    for (int caseIdx = 0; caseIdx < 2; ++caseIdx) {
        const int K = (caseIdx == 0) ? D : FFN;
        const int N = (caseIdx == 0) ? FFN : D;
        cl_program p = build(c, kGemvM2);
        if (!p) { ++failures; continue; }
        cl_kernel k = clCreateKernel(p, "gemvM2", nullptr);
        if (!k) { std::printf("OCLK-PROBE gemvM2 kernel create FAILED\n"); ++failures; clReleaseProgram(p); continue; }
        std::srand(42);
        std::vector<float> X((size_t) 2 * K), W((size_t) N * K), bias((size_t) N);
        for (auto& v : X) v = (float) std::rand() / RAND_MAX - 0.5f;
        for (auto& v : W) v = ((float) std::rand() / RAND_MAX - 0.5f) * 0.1f;
        for (auto& v : bias) v = (float) std::rand() / RAND_MAX * 0.01f;
        cl_mem dX = clCreateBuffer(c.ctx, CL_MEM_READ_ONLY | CL_MEM_COPY_HOST_PTR, X.size() * 4, X.data(), nullptr);
        cl_mem dW = clCreateBuffer(c.ctx, CL_MEM_READ_ONLY | CL_MEM_COPY_HOST_PTR, W.size() * 4, W.data(), nullptr);
        cl_mem dB = clCreateBuffer(c.ctx, CL_MEM_READ_ONLY | CL_MEM_COPY_HOST_PTR, bias.size() * 4, bias.data(), nullptr);
        cl_mem dY = clCreateBuffer(c.ctx, CL_MEM_READ_WRITE, (size_t) 2 * N * 4, nullptr, nullptr);
        clFinish(c.q);  // COPY_HOST_PTR copies are queue items: finish them BEFORE the kernel runs
                      // (empirically NOT reliably serialized on Apple GPU OpenCL - see findings F694)
        cl_int ae[6];
        ae[0] = clSetKernelArg(k, 0, sizeof(cl_mem), &dX);
        ae[1] = clSetKernelArg(k, 1, sizeof(cl_mem), &dW);
        ae[2] = clSetKernelArg(k, 2, sizeof(cl_mem), &dB);
        ae[3] = clSetKernelArg(k, 3, sizeof(cl_mem), &dY);
        ae[4] = clSetKernelArg(k, 4, sizeof(int), &K);
        ae[5] = clSetKernelArg(k, 5, sizeof(int), &N);
        if (ae[0] || ae[1] || ae[2] || ae[3] || ae[4] || ae[5]) {
            std::printf("OCLK-PROBE gemvM2 arg errs: %d %d %d %d %d %d\n", ae[0], ae[1], ae[2], ae[3], ae[4], ae[5]);
        }
        std::vector<float> Yref((size_t) 2 * N, 0.0f);
        for (int m = 0; m < 2; ++m) {
            for (int row = 0; row < N; ++row) {
                double acc = 0.0;
                for (int kk = 0; kk < K; ++kk) acc += (double) X[(size_t) m * K + kk] * (double) W[(size_t) row * K + kk];
                Yref[(size_t) m * N + row] = (float) acc + bias[row];
            }
        }
        size_t g = (size_t) N * 64, l = 64;  // global counts WORK-ITEMS: N groups x 64 lanes
        std::vector<float> Ygot((size_t) 2 * N, 0.0f);
        {   // DIAGNOSTIC: did the host->device input copies actually land?
            float probeX[8] = {0}, probeW[8] = {0}, probeB[8] = {0};
            cl_int rx = clEnqueueReadBuffer(c.q, dX, CL_TRUE, 0, sizeof(probeX), probeX, 0, nullptr, nullptr);
            cl_int rw = clEnqueueReadBuffer(c.q, dW, CL_TRUE, 0, sizeof(probeW), probeW, 0, nullptr, nullptr);
            cl_int rb = clEnqueueReadBuffer(c.q, dB, CL_TRUE, 0, sizeof(probeB), probeB, 0, nullptr, nullptr);
            int mx = 0, mw = 0, mb = 0;
            for (int i = 0; i < 8; ++i) {
                if (std::fabs((double) probeX[i] - (double) X[(size_t) i]) > 1e-9) ++mx;
                if (std::fabs((double) probeW[i] - (double) W[(size_t) i]) > 1e-9) ++mw;
                if (std::fabs((double) probeB[i] - (double) bias[(size_t) i]) > 1e-9) ++mb;
            }
            if (rx || rw || rb || mx || mw || mb) {
                std::printf("OCLK-PROBE gemvM2 INPUT-COPY DIAG rc=%d/%d/%d mismatch x/w/b=%d/%d/%d\n",
                            rx, rw, rb, mx, mw, mb);
            }
        }
        cl_int qe = clEnqueueNDRangeKernel(c.q, k, 1, nullptr, &g, &l, 0, nullptr, nullptr);
        if (qe != CL_SUCCESS) std::printf("OCLK-PROBE gemvM2 enqueue err=%d (results below are stale)\n", qe);
        const cl_int fr = clFinish(c.q);
        const cl_int rr = clEnqueueReadBuffer(c.q, dY, CL_TRUE, 0, Ygot.size() * 4, Ygot.data(), 0, nullptr, nullptr);
        if (fr != CL_SUCCESS || rr != CL_SUCCESS) {
            std::printf("OCLK-PROBE gemvM2 finish=%d read=%d\n", fr, rr);
        }
        Check ch = cmp(Ygot, Yref);
        {   // zero-output distribution: which rows produced exactly 0.0 in the first half?
            int zc = 0, first = -1, last = -1;
            for (int r = 0; r < N; ++r) {
                if (Ygot[(size_t) r] == 0.0f) {
                    ++zc;
                    if (first < 0) first = r;
                    last = r;
                }
            }
            std::printf("OCLK-PROBE gemvM2 zero-rows(first-half): count=%d first=%d last=%d of N=%d\n",
                        zc, first, last, N);
        }
        const double ms = bench(c, k, g, l, 20);
        const bool ok = ch.rel < 5e-4;
        if (!ok) ++failures;
        std::printf("OCLK-PROBE gemvM2 K=%d N=%d: maxdiff=%.3e rel=%.3e ms=%.3f %s\n",
                    K, N, ch.maxdiff, ch.rel, ms, ok ? "ok" : "TOLERANCE_FAIL");
        clReleaseMemObject(dX); clReleaseMemObject(dW); clReleaseMemObject(dB);
        clReleaseMemObject(dY); clReleaseKernel(k); clReleaseProgram(p);
    }

    // ---------- op 2: tiled GEMM 2D, M=512 K=896 N=4864 ----------
    {
        cl_program p = build(c, kGemm2D);
        if (!p) { ++failures; } else {
            cl_kernel k = clCreateKernel(p, "tiled_gemm2", nullptr);
            const int M = 512, K = D, N = FFN;
            std::srand(7);
            std::vector<float> X((size_t) M * K), W((size_t) N * K);
            for (auto& v : X) v = (float) std::rand() / RAND_MAX - 0.5f;
            for (auto& v : W) v = ((float) std::rand() / RAND_MAX - 0.5f) * 0.1f;
            cl_mem dX = clCreateBuffer(c.ctx, CL_MEM_READ_ONLY | CL_MEM_COPY_HOST_PTR, X.size() * 4, X.data(), nullptr);
            cl_mem dW = clCreateBuffer(c.ctx, CL_MEM_READ_ONLY | CL_MEM_COPY_HOST_PTR, W.size() * 4, W.data(), nullptr);
            cl_mem dY = clCreateBuffer(c.ctx, CL_MEM_READ_WRITE, (size_t) M * N * 4, nullptr, nullptr);
            clFinish(c.q);  // same serialization rule
            clSetKernelArg(k, 0, sizeof(cl_mem), &dX);
            clSetKernelArg(k, 1, sizeof(cl_mem), &dW);
            clSetKernelArg(k, 2, sizeof(cl_mem), &dY);
            clSetKernelArg(k, 3, sizeof(int), &M);
            clSetKernelArg(k, 4, sizeof(int), &N);
            clSetKernelArg(k, 5, sizeof(int), &K);
            std::vector<float> Yref((size_t) M * N, 0.0f);
            for (int r = 0; r < M; ++r) {
                for (int col = 0; col < N; ++col) {
                    double acc = 0.0;
                    for (int kk = 0; kk < K; ++kk) acc += (double) X[(size_t) r * K + kk] * (double) W[(size_t) col * K + kk];
                    Yref[(size_t) r * N + col] = (float) acc;
                }
            }
            size_t g[2] = {(size_t) M, (size_t) N}, l[2] = {16, 16};
            clEnqueueNDRangeKernel(c.q, k, 2, nullptr, g, l, 0, nullptr, nullptr);
            clFinish(c.q);
            std::vector<float> Ygot((size_t) M * N, 0.0f);
            clEnqueueReadBuffer(c.q, dY, CL_TRUE, 0, Ygot.size() * 4, Ygot.data(), 0, nullptr, nullptr);
            Check ch = cmp(Ygot, Yref);
            const double t0 = now_ms();
            for (int i = 0; i < 5; ++i) {
                clEnqueueNDRangeKernel(c.q, k, 2, nullptr, g, l, 0, nullptr, nullptr);
                clFinish(c.q);
            }
            const double ms = (now_ms() - t0) / 5.0;
            const bool ok = ch.rel < 5e-3;
            if (!ok) ++failures;
            std::printf("OCLK-PROBE tiled_gemm M=%d K=%d N=%d: maxdiff=%.3e rel=%.3e ms=%.3f %s\n",
                        M, K, N, ch.maxdiff, ch.rel, ms, ok ? "ok" : "TOLERANCE_FAIL");
            clReleaseMemObject(dX); clReleaseMemObject(dW); clReleaseMemObject(dY);
            clReleaseKernel(k); clReleaseProgram(p);
        }
    }

    // ---------- op 3: dequant Q8_0 (bit-exact required) ----------
    {
        cl_program p = build(c, kDequant);
        if (!p) { ++failures; } else {
            cl_kernel k = clCreateKernel(p, "dequant_q8", nullptr);
            const uint64_t nb = 10000;
            std::srand(11);
            std::vector<uint8_t> raw((size_t) nb * 34);
            for (auto& v : raw) v = (uint8_t) (std::rand() & 0xFF);
            cl_mem dR = clCreateBuffer(c.ctx, CL_MEM_READ_ONLY | CL_MEM_COPY_HOST_PTR, raw.size(), raw.data(), nullptr);
            cl_mem dO = clCreateBuffer(c.ctx, CL_MEM_WRITE_ONLY, (size_t) nb * 32 * 4, nullptr, nullptr);
            clFinish(c.q);  // same serialization rule
            clSetKernelArg(k, 0, sizeof(cl_mem), &dR);
            clSetKernelArg(k, 1, sizeof(cl_mem), &dO);
            clSetKernelArg(k, 2, sizeof(unsigned long), &nb);   // ulong = unsigned long on LP64 mac
            std::vector<float> Oref((size_t) nb * 32, 0.0f);
            for (uint64_t b = 0; b < nb; ++b) {
                const uint8_t* pp = raw.data() + b * 34;
                const uint16_t hb = (uint16_t)(pp[0] | ((uint16_t) pp[1] << 8));
                const float d = (float) [hb]() { __fp16 h; std::memcpy(&h, &hb, 2); return h; }();
                for (int i = 0; i < 32; ++i) Oref[b * 32 + i] = d * (float) ((int) ((char) pp[2 + i]));
            }
            const size_t gnb = (size_t) nb;
            clEnqueueNDRangeKernel(c.q, k, 1, nullptr, &gnb, nullptr, 0, nullptr, nullptr);
            clFinish(c.q);
            std::vector<float> Ogot((size_t) nb * 32, 0.0f);
            clEnqueueReadBuffer(c.q, dO, CL_TRUE, 0, Ogot.size() * 4, Ogot.data(), 0, nullptr, nullptr);
            Check ch = cmp(Ogot, Oref);
            const bool ok = (ch.maxdiff == 0.0);
            if (!ok) ++failures;
            std::printf("OCLK-PROBE dequant_q8 blocks=%llu: maxdiff=%.3e %s\n",
                        (unsigned long long) nb, ch.maxdiff, ok ? "ok (bit-exact)" : "EXACTNESS_FAIL");
            clReleaseMemObject(dR); clReleaseMemObject(dO); clReleaseKernel(k); clReleaseProgram(p);
        }
    }

    // ---------- op 4: rmsnorm over D ----------
    {
        cl_program p = build(c, kRmsnorm);
        if (!p) { ++failures; } else {
            cl_kernel k = clCreateKernel(p, "rmsnorm", nullptr);
            std::srand(3);
            std::vector<float> x(D), w(D);
            for (auto& v : x) v = (float) std::rand() / RAND_MAX - 0.5f;
            for (auto& v : w) v = 0.5f + (float) std::rand() / RAND_MAX;
            const float eps = 1e-5f;
            double ss = 0.0;
            for (int i = 0; i < D; ++i) ss += (double) x[i] * (double) x[i];
            const float inv = (float) (1.0 / std::sqrt(ss / (double) D + (double) eps));
            std::vector<float> Oref(D);
            for (int i = 0; i < D; ++i) Oref[i] = x[i] * inv * w[i];
            cl_mem dX = clCreateBuffer(c.ctx, CL_MEM_READ_ONLY | CL_MEM_COPY_HOST_PTR, x.size() * 4, x.data(), nullptr);
            cl_mem dW = clCreateBuffer(c.ctx, CL_MEM_READ_ONLY | CL_MEM_COPY_HOST_PTR, w.size() * 4, w.data(), nullptr);
            cl_mem dO = clCreateBuffer(c.ctx, CL_MEM_READ_WRITE, D * 4, nullptr, nullptr);
            clFinish(c.q);  // same serialization rule
            clSetKernelArg(k, 0, sizeof(cl_mem), &dX);
            clSetKernelArg(k, 1, sizeof(cl_mem), &dW);
            clSetKernelArg(k, 2, sizeof(cl_mem), &dO);
            clSetKernelArg(k, 3, sizeof(int), &D);
            clSetKernelArg(k, 4, sizeof(float), &eps);
            size_t g = 64, l = 64;   // one work-group of 64 lanes: global must be a multiple of local
            cl_int qe = clEnqueueNDRangeKernel(c.q, k, 1, nullptr, &g, &l, 0, nullptr, nullptr);
            if (qe != CL_SUCCESS) std::printf("OCLK-PROBE rmsnorm enqueue err=%d\n", qe);
            clFinish(c.q);
            std::vector<float> Ogot(D, 0.0f);
            clEnqueueReadBuffer(c.q, dO, CL_TRUE, 0, Ogot.size() * 4, Ogot.data(), 0, nullptr, nullptr);
            Check ch = cmp(Ogot, Oref);
            const double ms = bench(c, k, g, l, 100);
            const bool ok = ch.rel < 1e-4;
            if (!ok) ++failures;
            std::printf("OCLK-PROBE rmsnorm D=%d: maxdiff=%.3e rel=%.3e ms=%.4f %s\n",
                        D, ch.maxdiff, ch.rel, ms, ok ? "ok" : "TOLERANCE_FAIL");
            clReleaseMemObject(dX); clReleaseMemObject(dW); clReleaseMemObject(dO);
            clReleaseKernel(k); clReleaseProgram(p);
        }
    }

    // ---------- op 5: attention scores ----------
    {
        cl_program p = build(c, kAttn);
        if (!p) { ++failures; } else {
            // NOTE: kAttn above contains a deliberate typo guard - fixed source here if build failed
            cl_kernel k = clCreateKernel(p, "attn_scores", nullptr);
            const int rows = 46;
            std::srand(5);
            std::vector<float> Qh(HD), Kc((size_t) rows * HD);
            for (auto& v : Qh) v = (float) std::rand() / RAND_MAX - 0.5f;
            for (auto& v : Kc) v = (float) std::rand() / RAND_MAX - 0.5f;
            const float scale = 1.0f / std::sqrt((float) HD);
            cl_mem dQ = clCreateBuffer(c.ctx, CL_MEM_READ_ONLY | CL_MEM_COPY_HOST_PTR, Qh.size() * 4, Qh.data(), nullptr);
            cl_mem dK = clCreateBuffer(c.ctx, CL_MEM_READ_ONLY | CL_MEM_COPY_HOST_PTR, Kc.size() * 4, Kc.data(), nullptr);
            cl_mem dS = clCreateBuffer(c.ctx, CL_MEM_WRITE_ONLY, (size_t) rows * 4, nullptr, nullptr);
            clFinish(c.q);  // same serialization rule
            clSetKernelArg(k, 0, sizeof(cl_mem), &dQ);
            clSetKernelArg(k, 1, sizeof(cl_mem), &dK);
            clSetKernelArg(k, 2, sizeof(cl_mem), &dS);
            clSetKernelArg(k, 3, sizeof(int), &rows);
            clSetKernelArg(k, 4, sizeof(float), &scale);
            std::vector<float> Sref((size_t) rows, 0.0f);
            for (int r = 0; r < rows; ++r) {
                double acc = 0.0;
                for (int i = 0; i < HD; ++i) acc += (double) Qh[i] * (double) Kc[(size_t) r * HD + i];
                Sref[r] = (float) acc * scale;
            }
            size_t g = (size_t) rows * 64, l = 64;  // rows groups x 64 lanes (work-ITEMS)
            cl_int qe = clEnqueueNDRangeKernel(c.q, k, 1, nullptr, &g, &l, 0, nullptr, nullptr);
            if (qe != CL_SUCCESS) std::printf("OCLK-PROBE attn enqueue err=%d\n", qe);
            clFinish(c.q);
            std::vector<float> Sgot((size_t) rows, 0.0f);
            clEnqueueReadBuffer(c.q, dS, CL_TRUE, 0, Sgot.size() * 4, Sgot.data(), 0, nullptr, nullptr);
            Check ch = cmp(Sgot, Sref);
            const double ms = bench(c, k, g, l, 100);
            const bool ok = ch.rel < 1e-4;
            if (!ok) ++failures;
            std::printf("OCLK-PROBE attn_scores rows=%d: maxdiff=%.3e rel=%.3e ms=%.4f %s\n",
                        rows, ch.maxdiff, ch.rel, ms, ok ? "ok" : "TOLERANCE_FAIL");
            clReleaseMemObject(dQ); clReleaseMemObject(dK); clReleaseMemObject(dS);
            clReleaseKernel(k); clReleaseProgram(p);
        }
    }

    if (failures == 0) {
        std::printf("OCLK-PROBE: PASS (all five engine-shaped kernels correct on %s)\n", dn);
    } else {
        std::printf("OCLK-PROBE: FAIL failures=%d\n", failures);
    }
    return failures == 0 ? 0 : 1;
}
