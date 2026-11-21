// plugin_opencl.cc - the F694 v1 reference vendor-backend plugin (OpenCL), implementing
// engine/src/plugins/plugin_abi.h. Builds as a shared library (dylib/dll/so) and is loaded
// IN-PROCESS by the host. Kernel sources mirror the engine's hot-path structure (model_sycl.cc:649
// gemvM, ops_sycl.cc:246 tiled GEMM, ops_sycl.cc:160 Q8_0 dequant) and were numerically verified
// on this machine's Apple M4 GPU by probe/acpp/ocl_kernels_probe.cpp (findings F694: 5/5 correct,
// dequant bit-exact). The all-lanes same-value output writes are the probe-verified form; the
// engine's lane0-only form is not separately verified on this device.
//
// Numeric contract (B-section spec): op-level vs an independent double-accumulated CPU reference
// within the declared tolerance; self-determinism (same inputs -> bit-identical outputs);
// dequant_q8_0 bit-exact. NOT bit-identical to the built-in SYCL backends (different hardware
// and accumulation order) - that is the engine-level drift gate's job (v2), reported not assumed.
//
// Build:  clang++ -O2 -std=c++17 -shared -fPIC plugin_opencl.cc -o libkakasi_plugin_opencl.dylib -framework OpenCL
#include "plugin_abi.h"

#include <OpenCL/opencl.h>
#include <cstdio>
#include <cstring>
#include <cstdlib>
#include <string>
#include <vector>

namespace {

// ---------------- OpenCL C kernels (probe-verified structure) ----------------

// Shared-weight matvec, runtime M in 1..4 (engine gemvM semantics: one 64-lane group per
// output row, each weight element read once and dotted into M accumulators, butterfly reduce).
static const char* kGemvMMSrc =
"__kernel void gemv_mm(__global const float* X, __global const float* W, __global const float* bias,\n"
"                      __global float* Y, const int K, const int N, const int M, const int ldy) {\n"
"    __local float red[256];\n"   /* 64 lanes x up to 4 slots */
"    const int row = get_group_id(0);\n"
"    const int lane = get_local_id(0);\n"
"    const int base = row * K;\n"
"    float a[4];\n"
"    for (int m = 0; m < M; ++m) a[m] = 0.0f;\n"
"    for (int k = lane; k < K; k += 64) {\n"
"        const float wv = W[base + k];\n"
"        for (int m = 0; m < M; ++m) a[m] += X[m * K + k] * wv;\n"
"    }\n"
"    for (int m = 0; m < M; ++m) red[m * 64 + lane] = a[m];\n"
"    barrier(CLK_LOCAL_MEM_FENCE);\n"
"    for (int s = 32; s > 0; s >>= 1) {\n"
"        if (lane < s) {\n"
"            for (int m = 0; m < M; ++m) red[m * 64 + lane] += red[m * 64 + lane + s];\n"
"        }\n"
"        barrier(CLK_LOCAL_MEM_FENCE);\n"
"    }\n"
"    for (int m = 0; m < M; ++m) {\n"
"        Y[m * ldy + row] = red[m * 64] + bias[row];\n"
"    }\n"
"}\n";

static const char* kGemmSrc =
"__kernel void gemm_tiled(__global const float* X, __global const float* W, __global float* Y,\n"
"                         const int M, const int N, const int K) {\n"
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

static const char* kDequantSrc =
"#pragma OPENCL EXTENSION cl_khr_fp16 : enable\n"
"__kernel void dequant_q8_0(__global const uchar* raw, __global float* out, const ulong nblocks) {\n"
"    const ulong b = get_global_id(0);\n"
"    if (b >= nblocks) return;\n"
"    __global const uchar* p = raw + b * 34;\n"
"    const ushort hb = (ushort)(p[0] | ((ushort) p[1] << 8));\n"
"    const float d = (float) as_half(hb);\n"
"    for (int i = 0; i < 32; ++i) out[b * 32 + i] = d * (float) ((int) ((char) p[2 + i]));\n"
"}\n";

static const char* kRmsnormSrc =
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

// ---------------- plugin state ----------------

constexpr int kMaxDevices = 8;

struct Dev {
    bool open = false;
    cl_device_id dev = nullptr;
    cl_context ctx = nullptr;
    char err[256] = {0};
    cl_program prog_gemv = nullptr, prog_gemm = nullptr, prog_deq = nullptr, prog_rms = nullptr;
    cl_kernel k_gemv = nullptr, k_gemm = nullptr, k_deq = nullptr, k_rms = nullptr;
};

Dev g_dev[kMaxDevices];
int g_devCount = -1;            // -1 = not enumerated yet
cl_device_id g_devList[kMaxDevices];
char g_enumErr[256] = {0};

void set_err(Dev* d, const char* what, cl_int rc) {
    if (!d) { std::snprintf(g_enumErr, sizeof(g_enumErr), "%s rc=%d", what, (int) rc); return; }
    std::snprintf(d->err, sizeof(d->err), "%s rc=%d", what, (int) rc);
}

Dev* dev_from_queue(cl_command_queue q) {
    cl_context ctx = nullptr;
    if (clGetCommandQueueInfo(q, CL_QUEUE_CONTEXT, sizeof(ctx), &ctx, nullptr) != CL_SUCCESS || !ctx) {
        std::snprintf(g_enumErr, sizeof(g_enumErr), "stream: no context");
        return nullptr;
    }
    for (int i = 0; i < kMaxDevices; ++i) {
        if (g_dev[i].open && g_dev[i].ctx == ctx) return &g_dev[i];
    }
    std::snprintf(g_enumErr, sizeof(g_enumErr), "stream: context not opened by this plugin");
    return nullptr;
}

bool build_kernels(Dev* d) {
    if (d->k_gemv && d->k_gemm && d->k_deq && d->k_rms) return true;
    const char* srcs[4] = {kGemvMMSrc, kGemmSrc, kDequantSrc, kRmsnormSrc};
    const char* names[4] = {"gemv_mm", "gemm_tiled", "dequant_q8_0", "rmsnorm"};
    cl_program* progs[4] = {&d->prog_gemv, &d->prog_gemm, &d->prog_deq, &d->prog_rms};
    cl_kernel* kerns[4] = {&d->k_gemv, &d->k_gemm, &d->k_deq, &d->k_rms};
    for (int i = 0; i < 4; ++i) {
        if (*kerns[i]) continue;
        cl_int rc = 0;
        cl_program p = clCreateProgramWithSource(d->ctx, 1, &srcs[i], nullptr, &rc);
        if (!p) { set_err(d, names[i], rc); return false; }
        rc = clBuildProgram(p, 1, &d->dev, nullptr, nullptr, nullptr);
        if (rc != CL_SUCCESS) {
            char log[1024] = {0};
            clGetProgramBuildInfo(p, d->dev, CL_PROGRAM_BUILD_LOG, sizeof(log), log, nullptr);
            std::snprintf(d->err, sizeof(d->err), "%s build rc=%d log=%.300s", names[i], (int) rc, log);
            clReleaseProgram(p);
            return false;
        }
        cl_kernel k = clCreateKernel(p, names[i], &rc);
        if (!k) { set_err(d, names[i], rc); clReleaseProgram(p); return false; }
        *progs[i] = p;
        *kerns[i] = k;
    }
    return true;
}

void enumerate() {
    if (g_devCount >= 0) return;
    g_devCount = 0;
    cl_uint np = 0;
    if (clGetPlatformIDs(0, nullptr, &np) != CL_SUCCESS || np == 0) {
        std::snprintf(g_enumErr, sizeof(g_enumErr), "no OpenCL platform");
        return;
    }
    std::string errAcc;
    std::vector<cl_platform_id> plats(np);
    clGetPlatformIDs(np, plats.data(), nullptr);
    for (cl_platform_id p : plats) {
        cl_uint nd = 0;
        if (clGetDeviceIDs(p, CL_DEVICE_TYPE_GPU, 0, nullptr, &nd) != CL_SUCCESS || nd == 0) continue;
        std::vector<cl_device_id> devs(nd);
        if (clGetDeviceIDs(p, CL_DEVICE_TYPE_GPU, nd, devs.data(), nullptr) != CL_SUCCESS) continue;
        for (cl_device_id dv : devs) {
            if (g_devCount < kMaxDevices) g_devList[g_devCount++] = dv;
        }
    }
    if (g_devCount == 0) {
        std::snprintf(g_enumErr, sizeof(g_enumErr), "no OpenCL GPU device");
    }
}

// ---------------- vtable implementations ----------------

int32_t p_device_count(void) { enumerate(); return g_devCount; }

int32_t p_device_info(int32_t index, struct kakasi_plugin_device_info* out) {
    enumerate();
    if (!out) return KAKASI_PLUGIN_ERR_BAD_ARG;
    if (out->struct_size < (uint32_t) sizeof(struct kakasi_plugin_device_info)) return KAKASI_PLUGIN_ERR_SIZE;
    if (index < 0 || index >= g_devCount) { std::snprintf(g_enumErr, sizeof(g_enumErr), "device index out of range"); return KAKASI_PLUGIN_ERR_NO_DEVICE; }
    char name[256] = {0};
    clGetDeviceInfo(g_devList[index], CL_DEVICE_NAME, sizeof(name), name, nullptr);
    cl_ulong mem = 0;
    clGetDeviceInfo(g_devList[index], CL_DEVICE_GLOBAL_MEM_SIZE, sizeof(mem), &mem, nullptr);
    out->name = nullptr;  /* filled below via static storage */
    static char nameStore[kMaxDevices][256];
    std::snprintf(nameStore[index], sizeof(nameStore[index]), "%s", name);
    out->name = nameStore[index];
    out->caps = KAKASI_PLUGIN_CAP_DEVICE | KAKASI_PLUGIN_CAP_MEM | KAKASI_PLUGIN_CAP_GEMV_MM
              | KAKASI_PLUGIN_CAP_GEMM | KAKASI_PLUGIN_CAP_DEQUANT_Q8_0 | KAKASI_PLUGIN_CAP_RMSNORM;
    out->mem_bytes = (uint64_t) mem;
    return KAKASI_PLUGIN_OK;
}

void* p_device_open(int32_t index) {
    enumerate();
    if (index < 0 || index >= g_devCount) { std::snprintf(g_enumErr, sizeof(g_enumErr), "device index out of range"); return nullptr; }
    for (int i = 0; i < kMaxDevices; ++i) {
        if (g_dev[i].open && g_dev[i].dev == g_devList[index]) return &g_dev[i];
    }
    for (int i = 0; i < kMaxDevices; ++i) {
        if (!g_dev[i].open) {
            Dev* d = &g_dev[i];
            d->dev = g_devList[index];
            d->ctx = clCreateContext(nullptr, 1, &d->dev, nullptr, nullptr, nullptr);
            if (!d->ctx) { set_err(d, "clCreateContext", 0); d->dev = nullptr; return nullptr; }
            d->open = true;
            if (!build_kernels(d)) { /* keep the device usable for memory ops; ops will fail named */ }
            return d;
        }
    }
    std::snprintf(g_enumErr, sizeof(g_enumErr), "device table full");
    return nullptr;
}

int32_t p_device_close(void* device) {
    Dev* d = (Dev*) device;
    if (!d || !d->open) return KAKASI_PLUGIN_ERR_BAD_ARG;
    if (d->k_gemv) clReleaseKernel(d->k_gemv);
    if (d->k_gemm) clReleaseKernel(d->k_gemm);
    if (d->k_deq) clReleaseKernel(d->k_deq);
    if (d->k_rms) clReleaseKernel(d->k_rms);
    if (d->prog_gemv) clReleaseProgram(d->prog_gemv);
    if (d->prog_gemm) clReleaseProgram(d->prog_gemm);
    if (d->prog_deq) clReleaseProgram(d->prog_deq);
    if (d->prog_rms) clReleaseProgram(d->prog_rms);
    if (d->ctx) clReleaseContext(d->ctx);
    *d = Dev();
    return KAKASI_PLUGIN_OK;
}

const char* p_device_name(int32_t index) {
    enumerate();
    if (index < 0 || index >= g_devCount) return "out-of-range";
    static char nameStore2[kMaxDevices][256];
    clGetDeviceInfo(g_devList[index], CL_DEVICE_NAME, sizeof(nameStore2[index]), nameStore2[index], nullptr);
    return nameStore2[index];
}

void* p_stream_create(void* device) {
    Dev* d = (Dev*) device;
    if (!d || !d->open) return nullptr;
    return (void*) clCreateCommandQueue(d->ctx, d->dev, 0, nullptr);
}

int32_t p_stream_destroy(void* stream) {
    if (!stream) return KAKASI_PLUGIN_ERR_BAD_ARG;
    clReleaseCommandQueue((cl_command_queue) stream);
    return KAKASI_PLUGIN_OK;
}

int32_t p_stream_sync(void* stream) {
    if (!stream) return KAKASI_PLUGIN_ERR_BAD_ARG;
    cl_int rc = clFinish((cl_command_queue) stream);
    return rc == CL_SUCCESS ? KAKASI_PLUGIN_OK : KAKASI_PLUGIN_ERR_LAUNCH;
}

const char* p_last_error(void* device) {
    Dev* d = (Dev*) device;
    if (!d) return g_enumErr;
    return d->err[0] ? d->err : "no error";
}

void* p_mem_alloc(void* device, uint64_t bytes, int32_t kind) {
    Dev* d = (Dev*) device;
    if (!d || !d->open) return nullptr;
    if (bytes == 0) { set_err(d, "alloc size 0", 0); return nullptr; }
    if (kind != KAKASI_PLUGIN_MEM_DEVICE) { set_err(d, "only MEM_DEVICE in v1", 0); return nullptr; }
    cl_int rc = 0;
    void* p = clCreateBuffer(d->ctx, CL_MEM_READ_WRITE, (size_t) bytes, nullptr, &rc);
    if (!p) { set_err(d, "clCreateBuffer", rc); return nullptr; }
    return p;
}

int32_t p_mem_free(void* device, void* ptr) {
    Dev* d = (Dev*) device;
    if (!d || !ptr) return KAKASI_PLUGIN_ERR_BAD_ARG;
    cl_int rc = clReleaseMemObject((cl_mem) ptr);
    return rc == CL_SUCCESS ? KAKASI_PLUGIN_OK : KAKASI_PLUGIN_ERR_ALLOC;
}

int32_t p_copy_h2d(void* stream, void* dst, const void* src, uint64_t bytes) {
    if (!stream || !dst || !src) return KAKASI_PLUGIN_ERR_BAD_ARG;
    cl_int rc = clEnqueueWriteBuffer((cl_command_queue) stream, (cl_mem) dst, CL_TRUE, 0,
                                     (size_t) bytes, src, 0, nullptr, nullptr);
    return rc == CL_SUCCESS ? KAKASI_PLUGIN_OK : KAKASI_PLUGIN_ERR_COPY;
}

int32_t p_copy_d2h(void* stream, void* dst, const void* src, uint64_t bytes) {
    if (!stream || !dst || !src) return KAKASI_PLUGIN_ERR_BAD_ARG;
    cl_int rc = clEnqueueReadBuffer((cl_command_queue) stream, (cl_mem) src, CL_TRUE, 0,
                                    (size_t) bytes, dst, 0, nullptr, nullptr);
    return rc == CL_SUCCESS ? KAKASI_PLUGIN_OK : KAKASI_PLUGIN_ERR_COPY;
}

int32_t p_gemv_mm(void* stream, const float* X, const float* W, const float* bias,
                  float* Y, int32_t K, int32_t N, int32_t M, int32_t ldy) {
    cl_command_queue q = (cl_command_queue) stream;
    Dev* d = dev_from_queue(q);
    if (!d || !d->open) return KAKASI_PLUGIN_ERR_NO_DEVICE;
    if (!X || !W || !bias || !Y) return KAKASI_PLUGIN_ERR_BAD_ARG;
    if (M < 1 || M > 4 || ldy < N || K <= 0 || N <= 0) {
        set_err(d, "gemv_mm contract: M in 1..4, ldy>=N", 0);
        return KAKASI_PLUGIN_ERR_BAD_ARG;
    }
    if (!build_kernels(d)) return KAKASI_PLUGIN_ERR_UNSUPPORTED;
    cl_kernel k = d->k_gemv;
    clSetKernelArg(k, 0, sizeof(cl_mem), &X);
    clSetKernelArg(k, 1, sizeof(cl_mem), &W);
    clSetKernelArg(k, 2, sizeof(cl_mem), &bias);
    clSetKernelArg(k, 3, sizeof(cl_mem), &Y);
    clSetKernelArg(k, 4, sizeof(int32_t), &K);
    clSetKernelArg(k, 5, sizeof(int32_t), &N);
    clSetKernelArg(k, 6, sizeof(int32_t), &M);
    clSetKernelArg(k, 7, sizeof(int32_t), &ldy);
    size_t g = (size_t) N * 64, l = 64;   /* global counts WORK-ITEMS (findings F694 judge line 1) */
    cl_int rc = clEnqueueNDRangeKernel(q, k, 1, nullptr, &g, &l, 0, nullptr, nullptr);
    if (rc != CL_SUCCESS) { set_err(d, "gemv_mm enqueue", rc); return KAKASI_PLUGIN_ERR_LAUNCH; }
    return KAKASI_PLUGIN_OK;
}

int32_t p_gemm_tiled(void* stream, const float* X, const float* W, float* Y,
                     int32_t M, int32_t N, int32_t K) {
    cl_command_queue q = (cl_command_queue) stream;
    Dev* d = dev_from_queue(q);
    if (!d || !d->open) return KAKASI_PLUGIN_ERR_NO_DEVICE;
    if (!X || !W || !Y || M <= 0 || N <= 0 || K <= 0) return KAKASI_PLUGIN_ERR_BAD_ARG;
    if (!build_kernels(d)) return KAKASI_PLUGIN_ERR_UNSUPPORTED;
    cl_kernel k = d->k_gemm;
    clSetKernelArg(k, 0, sizeof(cl_mem), &X);
    clSetKernelArg(k, 1, sizeof(cl_mem), &W);
    clSetKernelArg(k, 2, sizeof(cl_mem), &Y);
    clSetKernelArg(k, 3, sizeof(int32_t), &M);
    clSetKernelArg(k, 4, sizeof(int32_t), &N);
    clSetKernelArg(k, 5, sizeof(int32_t), &K);
    size_t g[2] = {(size_t) ((M + 15) / 16) * 16, (size_t) ((N + 15) / 16) * 16};
    size_t l[2] = {16, 16};
    cl_int rc = clEnqueueNDRangeKernel(q, k, 2, nullptr, g, l, 0, nullptr, nullptr);
    if (rc != CL_SUCCESS) { set_err(d, "gemm_tiled enqueue", rc); return KAKASI_PLUGIN_ERR_LAUNCH; }
    return KAKASI_PLUGIN_OK;
}

int32_t p_dequant_q8_0(void* stream, const uint8_t* raw, float* out, uint64_t nblocks) {
    cl_command_queue q = (cl_command_queue) stream;
    Dev* d = dev_from_queue(q);
    if (!d || !d->open) return KAKASI_PLUGIN_ERR_NO_DEVICE;
    if (!raw || !out || nblocks == 0) return KAKASI_PLUGIN_ERR_BAD_ARG;
    if (!build_kernels(d)) return KAKASI_PLUGIN_ERR_UNSUPPORTED;
    cl_kernel k = d->k_deq;
    clSetKernelArg(k, 0, sizeof(cl_mem), &raw);
    clSetKernelArg(k, 1, sizeof(cl_mem), &out);
    clSetKernelArg(k, 2, sizeof(unsigned long), &nblocks);
    size_t g = (size_t) nblocks;
    cl_int rc = clEnqueueNDRangeKernel(q, k, 1, nullptr, &g, nullptr, 0, nullptr, nullptr);
    if (rc != CL_SUCCESS) { set_err(d, "dequant enqueue", rc); return KAKASI_PLUGIN_ERR_LAUNCH; }
    return KAKASI_PLUGIN_OK;
}

int32_t p_rmsnorm(void* stream, const float* x, const float* w, float* out,
                  int32_t n, float eps) {
    cl_command_queue q = (cl_command_queue) stream;
    Dev* d = dev_from_queue(q);
    if (!d || !d->open) return KAKASI_PLUGIN_ERR_NO_DEVICE;
    if (!x || !w || !out || n <= 0) return KAKASI_PLUGIN_ERR_BAD_ARG;
    if (!build_kernels(d)) return KAKASI_PLUGIN_ERR_UNSUPPORTED;
    cl_kernel k = d->k_rms;
    clSetKernelArg(k, 0, sizeof(cl_mem), &x);
    clSetKernelArg(k, 1, sizeof(cl_mem), &w);
    clSetKernelArg(k, 2, sizeof(cl_mem), &out);
    clSetKernelArg(k, 3, sizeof(int32_t), &n);
    clSetKernelArg(k, 4, sizeof(float), &eps);
    size_t g = 64, l = 64;   /* single 64-lane work-group (probe-verified form) */
    cl_int rc = clEnqueueNDRangeKernel(q, k, 1, nullptr, &g, &l, 0, nullptr, nullptr);
    if (rc != CL_SUCCESS) { set_err(d, "rmsnorm enqueue", rc); return KAKASI_PLUGIN_ERR_LAUNCH; }
    return KAKASI_PLUGIN_OK;
}

uint64_t p_query_caps(void) {
    return KAKASI_PLUGIN_CAP_DEVICE | KAKASI_PLUGIN_CAP_MEM | KAKASI_PLUGIN_CAP_GEMV_MM
         | KAKASI_PLUGIN_CAP_GEMM | KAKASI_PLUGIN_CAP_DEQUANT_Q8_0 | KAKASI_PLUGIN_CAP_RMSNORM;
}

const kakasi_plugin_vtable g_vtable = {
    KAKASI_PLUGIN_ABI_CURRENT,
    (uint32_t) sizeof(kakasi_plugin_vtable),
    p_device_count,
    p_device_info,
    p_device_open,
    p_device_close,
    p_device_name,
    p_stream_create,
    p_stream_destroy,
    p_stream_sync,
    p_last_error,
    p_mem_alloc,
    p_mem_free,
    p_copy_h2d,
    p_copy_d2h,
    p_gemv_mm,
    p_gemm_tiled,
    p_dequant_q8_0,
    p_rmsnorm,
    p_query_caps,
};

}  // namespace

extern "C" const kakasi_plugin_vtable* kakasi_plugin_get_vtable(uint32_t want_abi) {
    if (KAKASI_PLUGIN_ABI_MAJOR(want_abi) != KAKASI_PLUGIN_ABI_MAJOR(KAKASI_PLUGIN_ABI_CURRENT)) {
        return nullptr;  /* explicit refusal: major mismatch - the host reports it by name */
    }
    return &g_vtable;
}
