// plugin_stub_nullfn.cc - manager unit-check fixture: claims ALL capability bits but leaves
// gemv_mm NULL. The plugin_load order (role check -> slot invariant) must surface this as
// "caps null fn: ... name=gemv_mm", not as a missing-caps refusal.
#include "../../engine/src/plugins/plugin_abi.h"

namespace {
int32_t s_count(void) { return 1; }
int32_t s_info(int32_t, struct kakasi_plugin_device_info* out) {
    if (!out || out->struct_size < 32u) return KAKASI_PLUGIN_ERR_SIZE;
    out->name = "stub-nullfn-device";
    out->caps = KAKASI_PLUGIN_ROLE_COMPUTE_CAPS;
    out->mem_bytes = 0;
    return KAKASI_PLUGIN_OK;
}
void* s_open(int32_t) { return (void*) 0x1; }
void* s_screate(void*) { return (void*) 0x2; }
int32_t s_h(void*) { return KAKASI_PLUGIN_ERR_UNSUPPORTED; }
int32_t s_h2(void*, void*) { return KAKASI_PLUGIN_ERR_UNSUPPORTED; }
void* s_a(void*, uint64_t, int32_t) { return (void*) 0x2; }
int32_t s_c(void*, void*, const void*, uint64_t) { return KAKASI_PLUGIN_ERR_UNSUPPORTED; }
int32_t s_gemm(void*, const float*, const float*, float*, int32_t, int32_t, int32_t) {
    return KAKASI_PLUGIN_ERR_UNSUPPORTED;
}
int32_t s_deq(void*, const uint8_t*, float*, uint64_t) { return KAKASI_PLUGIN_ERR_UNSUPPORTED; }
int32_t s_rms(void*, const float*, const float*, float*, int32_t, float) {
    return KAKASI_PLUGIN_ERR_UNSUPPORTED;
}
const char* s_err(void*) { return "stub-nullfn"; }
const char* s_dname(int32_t) { return "stub-nullfn-device"; }
uint64_t s_caps(void) { return KAKASI_PLUGIN_ROLE_COMPUTE_CAPS; }

const kakasi_plugin_vtable g_vt = {
    KAKASI_PLUGIN_ABI_CURRENT,
    (uint32_t) sizeof(kakasi_plugin_vtable),
    s_count, s_info, s_open, s_h, s_dname,
    s_screate, s_h, s_h, s_err,
    s_a, s_h2, s_c, s_c,
    nullptr,   /* gemv_mm NULL while CAP_GEMV_MM is claimed - the invariant violation */
    s_gemm, s_deq, s_rms,
    s_caps,
};
}  // namespace

extern "C" const kakasi_plugin_vtable* kakasi_plugin_get_vtable(uint32_t want_abi) {
    if (KAKASI_PLUGIN_ABI_MAJOR(want_abi) != 1) return nullptr;
    return &g_vt;
}
