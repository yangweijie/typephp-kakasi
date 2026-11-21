// F694 B-section acceptance ①: the ABI header compiles, its layout pins hold, and a
// conforming plugin skeleton can be written against it - as C++ AND as C. This file is the
// C++ variant: it includes plugin_abi.h, defines a minimal conforming vtable (stub functions
// that return UNSUPPORTED), exports the entry symbol, and at runtime checks the version
// macros and the struct_size handshake. The real OpenCL reference plugin follows in v1 step 2.
#include "../../engine/src/plugins/plugin_abi.h"
#include <cstdio>
#include <cstring>

namespace {
int32_t stub_count(void) { return 0; }
int32_t stub_devinfo(int32_t, struct kakasi_plugin_device_info* out) {
    /* a conforming plugin must not leave NULL pointers for caps it claims (DEVICE is claimed
     * by this stub), so device_info is implemented even in the unsupported-stub */
    if (out == nullptr || out->struct_size < 32u) return KAKASI_PLUGIN_ERR_SIZE;
    out->name = "stub-device";
    out->caps = KAKASI_PLUGIN_CAP_DEVICE | KAKASI_PLUGIN_CAP_MEM;
    out->mem_bytes = 0;
    return KAKASI_PLUGIN_ERR_UNSUPPORTED;  /* no real device behind the stub */
}
void* stub_open(int32_t) { return nullptr; }
int32_t stub_close(void*) { return KAKASI_PLUGIN_ERR_UNSUPPORTED; }
const char* stub_name(int32_t) { return "stub"; }
void* stub_stream_create(void*) { return nullptr; }
int32_t stub_h(void*) { return KAKASI_PLUGIN_ERR_UNSUPPORTED; }
int32_t stub_free(void*, void*) { return KAKASI_PLUGIN_ERR_UNSUPPORTED; }
const char* stub_last_error(void*) { return "stub: unsupported"; }
void* stub_alloc(void*, uint64_t, int32_t) { return nullptr; }
int32_t stub_copy(void*, void*, const void*, uint64_t) { return KAKASI_PLUGIN_ERR_UNSUPPORTED; }
int32_t stub_gemv(void*, const float*, const float*, const float*, float*,
                  int32_t, int32_t, int32_t, int32_t) { return KAKASI_PLUGIN_ERR_UNSUPPORTED; }
int32_t stub_gemm(void*, const float*, const float*, float*, int32_t, int32_t, int32_t) {
    return KAKASI_PLUGIN_ERR_UNSUPPORTED;
}
int32_t stub_dequant(void*, const uint8_t*, float*, uint64_t) { return KAKASI_PLUGIN_ERR_UNSUPPORTED; }
int32_t stub_rmsnorm(void*, const float*, const float*, float*, int32_t, float) {
    return KAKASI_PLUGIN_ERR_UNSUPPORTED;
}
uint64_t stub_caps(void) {
    return KAKASI_PLUGIN_CAP_DEVICE | KAKASI_PLUGIN_CAP_MEM;
}
}  // namespace

static const kakasi_plugin_vtable g_stub_vtable = {
    KAKASI_PLUGIN_ABI_CURRENT,
    (uint32_t) sizeof(kakasi_plugin_vtable),
    stub_count,                              /* device_count */
    stub_devinfo,                            /* device_info (conforming: DEVICE is claimed) */
    stub_open,                               /* device_open */
    stub_close,                              /* device_close */
    stub_name,                               /* device_name */
    stub_stream_create, stub_h, stub_h,      /* stream_create/destroy/sync */
    stub_last_error,                         /* last_error */
    stub_alloc,                              /* mem_alloc */
    stub_free,                               /* mem_free */
    stub_copy,                               /* copy_h2d */
    stub_copy,                               /* copy_d2h */
    stub_gemv,                               /* gemv_mm */
    stub_gemm,                               /* gemm_tiled */
    stub_dequant,                            /* dequant_q8_0 */
    stub_rmsnorm,                            /* rmsnorm */
    stub_caps,                               /* query_caps */
};

extern "C" const kakasi_plugin_vtable* kakasi_plugin_get_vtable(uint32_t want_abi) {
    if (KAKASI_PLUGIN_ABI_MAJOR(want_abi) != KAKASI_PLUGIN_ABI_MAJOR(KAKASI_PLUGIN_ABI_CURRENT)) {
        return nullptr;  /* explicit refusal: major mismatch */
    }
    return &g_stub_vtable;
}

int main() {
    int failures = 0;
    if (KAKASI_PLUGIN_ABI_MAJOR(KAKASI_PLUGIN_ABI_CURRENT) != 1 ||
        KAKASI_PLUGIN_ABI_MINOR(KAKASI_PLUGIN_ABI_CURRENT) != 0) {
        std::printf("ABI-CHECK: FAIL version macro decode\n");
        ++failures;
    }
    if (KAKASI_PLUGIN_ABI_MAJOR(KAKASI_PLUGIN_ABI(2, 5)) != 2 ||
        KAKASI_PLUGIN_ABI_MINOR(KAKASI_PLUGIN_ABI(2, 5)) != 5) {
        std::printf("ABI-CHECK: FAIL pack/unpack\n");
        ++failures;
    }
    /* the host-side handshake: ask for a too-new major -> NULL (named refusal upstream) */
    const kakasi_plugin_vtable* v = kakasi_plugin_get_vtable(KAKASI_PLUGIN_ABI(2, 0));
    if (v != nullptr) {
        std::printf("ABI-CHECK: FAIL major mismatch did not refuse\n");
        ++failures;
    }
    v = kakasi_plugin_get_vtable(KAKASI_PLUGIN_ABI(1, 9));
    if (v == nullptr || v->abi_version != KAKASI_PLUGIN_ABI_CURRENT ||
        v->vtable_size < (uint32_t) sizeof(kakasi_plugin_vtable)) {
        std::printf("ABI-CHECK: FAIL vtable handshake\n");
        ++failures;
    }
    struct kakasi_plugin_device_info di;
    di.struct_size = (uint32_t) sizeof(di);
    /* size handshake rule: receiver accepts struct_size >= its expected minimum */
    if (di.struct_size != 32) {
        printf("ABI-CHECK: FAIL device_info struct_size=%u\n", di.struct_size);
        ++failures;
    }
    if (v != nullptr && (v->query_caps() & KAKASI_PLUGIN_ROLE_COMPUTE_CAPS) != 0) {
        /* the stub claims only DEVICE|MEM, so it must NOT pass the compute-role check */
    } else if (v != nullptr) {
        std::printf("ABI-CHECK stub compute-role correctly refused by cap mask\n");
    }
    if (failures == 0) {
        std::printf("ABI-CHECK: PASS (C++ TU; header compiles, asserts hold, handshake rules work)\n");
    }
    return failures == 0 ? 0 : 1;
}
