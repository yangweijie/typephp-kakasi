// F694 A-section device probe (independent of acpp): does the SYSTEM OpenCL runtime work on
// this machine's Apple GPU at all? AdaptiveCpp's OpenCL backend rides on this runtime, so if
// a trivial OpenCL C kernel cannot build+run here, the acpp round is dead on arrival. Also
// prints the device/platform identities the SYCL probe will report against.
// Build: clang++ -O2 -std=c++17 opencl_probe.cpp -o opencl_probe -framework OpenCL
#include <OpenCL/opencl.h>
#include <cstdio>
#include <cstring>
#include <cstdlib>
#include <vector>

static const char* kSrc =
"__kernel void vadd(__global const float* a, __global const float* b, __global float* c, const int n) {\n"
"    int i = get_global_id(0);\n"
"    if (i < n) c[i] = a[i] + b[i];\n"
"}\n";

#define CHECK(cond, msg) do { if (!(cond)) { std::printf("OCL-PROBE: FAIL %s (line %d)\n", msg, __LINE__); return 1; } } while (0)

int main() {
    cl_uint nplat = 0;
    CHECK(clGetPlatformIDs(0, nullptr, &nplat) == CL_SUCCESS && nplat > 0, "no OpenCL platform");
    std::vector<cl_platform_id> plats(nplat);
    clGetPlatformIDs(nplat, plats.data(), nullptr);
    cl_device_id gpu = nullptr;
    char name[256] = {0}, platname[256] = {0};
    for (cl_platform_id p : plats) {
        clGetPlatformInfo(p, CL_PLATFORM_NAME, sizeof(platname), platname, nullptr);
        cl_uint ndev = 0;
        clGetDeviceIDs(p, CL_DEVICE_TYPE_ALL, 0, nullptr, &ndev);
        std::vector<cl_device_id> devs(ndev ? ndev : 0);
        if (ndev) clGetDeviceIDs(p, CL_DEVICE_TYPE_ALL, ndev, devs.data(), nullptr);
        for (cl_device_id d : devs) {
            cl_device_type t = 0;
            clGetDeviceInfo(d, CL_DEVICE_TYPE, sizeof(t), &t, nullptr);
            char dn[256] = {0};
            clGetDeviceInfo(d, CL_DEVICE_NAME, sizeof(dn), dn, nullptr);
            std::printf("OCL-PROBE device: %s | %s | type=%s\n", dn, platname,
                        (t & CL_DEVICE_TYPE_GPU) ? "GPU" : (t & CL_DEVICE_TYPE_CPU) ? "CPU" : "other");
            if ((t & CL_DEVICE_TYPE_GPU) && !gpu) gpu = d;
        }
    }
    if (!gpu) { std::printf("OCL-PROBE: FAIL no OpenCL GPU device\n"); return 1; }
    char gn[256] = {0};
    clGetDeviceInfo(gpu, CL_DEVICE_NAME, sizeof(gn), gn, nullptr);
    cl_context ctx = clCreateContext(nullptr, 1, &gpu, nullptr, nullptr, nullptr);
    CHECK(ctx, "clCreateContext");
    cl_command_queue qq = clCreateCommandQueue(ctx, gpu, 0, nullptr);
    CHECK(qq, "clCreateCommandQueue");
    cl_int err = 0;
    cl_program prog = clCreateProgramWithSource(ctx, 1, &kSrc, nullptr, &err);
    CHECK(prog && err == CL_SUCCESS, "clCreateProgramWithSource");
    err = clBuildProgram(prog, 1, &gpu, nullptr, nullptr, nullptr);
    if (err != CL_SUCCESS) {
        char log[4096] = {0};
        clGetProgramBuildInfo(prog, gpu, CL_PROGRAM_BUILD_LOG, sizeof(log), log, nullptr);
        std::printf("OCL-PROBE: FAIL build err=%d log=%.800s\n", err, log);
        return 1;
    }
    cl_kernel k = clCreateKernel(prog, "vadd", &err);
    CHECK(k && err == CL_SUCCESS, "clCreateKernel");
    const int n = 1 << 20;
    std::vector<float> a((size_t) n), b((size_t) n), c((size_t) n, 0.0f);
    std::srand(9);
    for (int i = 0; i < n; ++i) { a[(size_t) i] = (float) std::rand() / RAND_MAX; b[(size_t) i] = (float) std::rand() / RAND_MAX; }
    cl_mem da = clCreateBuffer(ctx, CL_MEM_READ_ONLY | CL_MEM_COPY_HOST_PTR, sizeof(float) * n, a.data(), &err);
    CHECK(da, "buf a");
    cl_mem db = clCreateBuffer(ctx, CL_MEM_READ_ONLY | CL_MEM_COPY_HOST_PTR, sizeof(float) * n, b.data(), &err);
    CHECK(db, "buf b");
    cl_mem dc = clCreateBuffer(ctx, CL_MEM_WRITE_ONLY, sizeof(float) * n, nullptr, &err);
    CHECK(dc, "buf c");
    clSetKernelArg(k, 0, sizeof(cl_mem), &da);
    clSetKernelArg(k, 1, sizeof(cl_mem), &db);
    clSetKernelArg(k, 2, sizeof(cl_mem), &dc);
    clSetKernelArg(k, 3, sizeof(int), &n);
    size_t g = (size_t) n;
    err = clEnqueueNDRangeKernel(qq, k, 1, nullptr, &g, nullptr, 0, nullptr, nullptr);
    CHECK(err == CL_SUCCESS, "clEnqueueNDRangeKernel");
    err = clEnqueueReadBuffer(qq, dc, CL_TRUE, 0, sizeof(float) * n, c.data(), 0, nullptr, nullptr);
    CHECK(err == CL_SUCCESS, "readback");
    for (int i = 0; i < n; ++i) {
        if (std::fabs((double) c[(size_t) i] - ((double) a[(size_t) i] + (double) b[(size_t) i])) > 1e-6) {
            std::printf("OCL-PROBE: FAIL wrong result at %d\n", i);
            return 1;
        }
    }
    std::printf("OCL-PROBE: PASS vadd n=%d on GPU %s\n", n, gn);
    return 0;
}
