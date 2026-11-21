// plugin_stub_nocaps.cc - acceptance ③ negative-control fixture: a well-formed plugin that
// claims only DEVICE|MEM (no compute caps). The host's compute-role check must refuse it
// with a named output. Conforming with its claimed caps: device/stream/mem functions are
// implemented; compute op slots are NULL (allowed for unclaimed capabilities).
#include "../../engine/src/plugins/plugin_abi.h"
#include <cstdio>

namespace {
int32_t s_count(void) { return 1; }
int32_t s_info(int32_t, struct kakasi_plugin_device_info* out) {
    if (!out || out->struct_size < 32u) return KAKASI_PLUGIN_ERR_SIZE;
    out->name = "stub-nocaps-device";
    out->caps = KAKASI_PLUGIN_CAP_DEVICE | KAKASI_PLUGIN_CAP_MEM;
    out->mem_bytes = 0;
    return KAKASI_PLUGIN_OK;
}
void* s_open(int32_t) { return (void*) 0x1; }
int32_t s_close(void*) { return KAKASI_PLUGIN_OK; }
const char* s_name(int32_t) { return "stub-nocaps-device"; }
void* s_screate(void*) { return (void*) 0x2; }
int32_t s_h(void*) { return KAKASI_PLUGIN_ERR_UNSUPPORTED; }
const char* s_err(void*) { return "stub-nocaps: memory-only plugin"; }
void* s_alloc(void*, uint64_t, int32_t) { return (void*) 0x3; }
int32_t s_free(void*, void*) { return KAKASI_PLUGIN_OK; }
int32_t s_copy(void*, void*, const void*, uint64_t) { return KAKASI_PLUGIN_ERR_UNSUPPORTED; }
uint64_t s_caps(void) { return KAKASI_PLUGIN_CAP_DEVICE | KAKASI_PLUGIN_CAP_MEM; }

const kakasi_plugin_vtable g_vt = {
    KAKASI_PLUGIN_ABI_CURRENT,
    (uint32_t) sizeof(kakasi_plugin_vtable),
    s_count, s_info, s_open, s_close, s_name,
    s_screate, s_h, s_h, s_err,
    s_alloc, s_free, s_copy, s_copy,
    nullptr, nullptr, nullptr, nullptr,   /* compute ops: unclaimed -> NULL is legal */
    s_caps,
};
}  // namespace

extern "C" const kakasi_plugin_vtable* kakasi_plugin_get_vtable(uint32_t want_abi) {
    if (KAKASI_PLUGIN_ABI_MAJOR(want_abi) != 1) return nullptr;
    return &g_vt;
}
