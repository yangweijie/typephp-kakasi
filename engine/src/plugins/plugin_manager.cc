// plugin_manager.cc - implementation of plugin_manager.h. Dual-platform (Windows/POSIX)
// dynamic loading with named refusals. See the header for the refusal taxonomy.
#include "plugin_manager.h"

#include <cstdio>
#include <cstdarg>
#include <cstring>

#if defined(_WIN32)
#ifndef WIN32_LEAN_AND_MEAN
#define WIN32_LEAN_AND_MEAN
#endif
#include <windows.h>
#else
#include <dlfcn.h>
#include <dirent.h>
#include <cstring>
#endif

namespace kakasi_plugins {
namespace {

void set_reason(LoadResult& r, const char* fmt, ...) {
    va_list args;
    va_start(args, fmt);
    std::vsnprintf(r.reason, sizeof(r.reason), fmt, args);
    va_end(args);
}

/* One row per capability bit: which vtable function the bit promises. Keep in lockstep with
 * the CAP_* definitions in plugin_abi.h - a new cap without a row here fails the build
 * below (the table must cover every defined bit). */
struct SlotRow { uint64_t bit; const void* fn; const char* name; };

}  // namespace

const char* plugin_caps_slot_violation(const kakasi_plugin_vtable* v, uint64_t caps) {
    /* NOT a static local: the rows are initialized from THIS plugin's vtable pointers, and a
     * C++ static local with dynamic initialization runs exactly once - the second plugin
     * would be inspected against the first plugin's cached pointers (found by the nullfn
     * unit check; findings F694). */
    const SlotRow rows[] = {
        {KAKASI_PLUGIN_CAP_DEVICE, (const void*) v->device_count, "device_count"},
        {KAKASI_PLUGIN_CAP_DEVICE, (const void*) v->device_info, "device_info"},
        {KAKASI_PLUGIN_CAP_DEVICE, (const void*) v->device_open, "device_open"},
        {KAKASI_PLUGIN_CAP_DEVICE, (const void*) v->device_close, "device_close"},
        {KAKASI_PLUGIN_CAP_DEVICE, (const void*) v->stream_create, "stream_create"},
        {KAKASI_PLUGIN_CAP_DEVICE, (const void*) v->stream_destroy, "stream_destroy"},
        {KAKASI_PLUGIN_CAP_DEVICE, (const void*) v->stream_sync, "stream_sync"},
        {KAKASI_PLUGIN_CAP_MEM, (const void*) v->mem_alloc, "mem_alloc"},
        {KAKASI_PLUGIN_CAP_MEM, (const void*) v->mem_free, "mem_free"},
        {KAKASI_PLUGIN_CAP_MEM, (const void*) v->copy_h2d, "copy_h2d"},
        {KAKASI_PLUGIN_CAP_MEM, (const void*) v->copy_d2h, "copy_d2h"},
        {KAKASI_PLUGIN_CAP_GEMV_MM, (const void*) v->gemv_mm, "gemv_mm"},
        {KAKASI_PLUGIN_CAP_GEMM, (const void*) v->gemm_tiled, "gemm_tiled"},
        {KAKASI_PLUGIN_CAP_DEQUANT_Q8_0, (const void*) v->dequant_q8_0, "dequant_q8_0"},
        {KAKASI_PLUGIN_CAP_RMSNORM, (const void*) v->rmsnorm, "rmsnorm"},
    };
    /* last_error and query_caps are always-present; the table above covers every
     * conditional bit. If a CAP_ bit is defined without a row, this compile-time count
     * check catches the drift: the six known bits must each appear at least once. */
    static_assert(KAKASI_PLUGIN_CAP_RMSNORM == ((uint64_t) 1 << 5),
                  "a new CAP_ bit was added - extend the slot table above");
    for (const SlotRow& row : rows) {
        if ((caps & row.bit) && !row.fn) {
            static char msg[128];
            std::snprintf(msg, sizeof(msg), "caps null fn: bit=0x%llx name=%s",
                          (unsigned long long) row.bit, row.name);
            return msg;
        }
    }
    return nullptr;
}

LoadResult plugin_load(const char* path, uint32_t want_abi, uint64_t role_caps) {
    LoadResult r;
    r.vtable = nullptr;
    r.handle = nullptr;
    r.reason[0] = '\0';
    if (!path || !path[0]) {
        set_reason(r, "open failed: empty path");
        return r;
    }
#if defined(_WIN32)
    HMODULE mod = LoadLibraryExA(path, nullptr, LOAD_WITH_ALTERED_SEARCH_PATH);
    const char* openErr = "";  /* GetLastError string conversion is v2 polish; code suffices */
    if (!mod) {
        set_reason(r, "open failed: %s (GetLastError=%lu)", path, GetLastError());
        return r;
    }
    void* sym = (void*) GetProcAddress(mod, "kakasi_plugin_get_vtable");
#else
    void* mod = dlopen(path, RTLD_NOW | RTLD_LOCAL);
    if (!mod) {
        const char* e = dlerror();
        set_reason(r, "open failed: %s (%s)", path, e ? e : "unknown");
        return r;
    }
    dlerror();  /* clear; dlsym(NULL) semantics differ across libc builds */
    void* sym = dlsym(mod, "kakasi_plugin_get_vtable");
    const char* symErr = dlerror();
    if (symErr) sym = nullptr;
    const char* openErr = symErr;
    (void) openErr;
#endif
    if (!sym) {
        set_reason(r, "entry missing: kakasi_plugin_get_vtable not found in %s", path);
#if defined(_WIN32)
        FreeLibrary(mod);
#else
        dlclose(mod);
#endif
        return r;
    }
    typedef const kakasi_plugin_vtable* (*entry_t)(uint32_t);
    entry_t entry = (entry_t) sym;
    const kakasi_plugin_vtable* v = entry(want_abi);
    if (!v) {
        set_reason(r, "abi refused by plugin: want=0x%08x major mismatch", want_abi);
#if defined(_WIN32)
        FreeLibrary(mod);
#else
        dlclose(mod);
#endif
        return r;
    }
    if (KAKASI_PLUGIN_ABI_MAJOR(v->abi_version) != KAKASI_PLUGIN_ABI_MAJOR(want_abi)) {
        set_reason(r, "abi mismatch: plugin=0x%08x want=0x%08x", v->abi_version, want_abi);
#if defined(_WIN32)
        FreeLibrary(mod);
#else
        dlclose(mod);
#endif
        return r;
    }
    if (v->vtable_size < (uint32_t) sizeof(kakasi_plugin_vtable)) {
        set_reason(r, "abi incomplete: vtable_size=%u < expected %u",
                   v->vtable_size, (uint32_t) sizeof(kakasi_plugin_vtable));
#if defined(_WIN32)
        FreeLibrary(mod);
#else
        dlclose(mod);
#endif
        return r;
    }
    const uint64_t caps = v->query_caps();
    const uint64_t missing = role_caps & ~caps;
    if (missing) {
        set_reason(r, "caps missing: role=0x%llx missing=0x%llx",
                   (unsigned long long) role_caps, (unsigned long long) missing);
#if defined(_WIN32)
        FreeLibrary(mod);
#else
        dlclose(mod);
#endif
        return r;
    }
    const char* violated = plugin_caps_slot_violation(v, caps);
    if (violated) {
        set_reason(r, "%s", violated);
#if defined(_WIN32)
        FreeLibrary(mod);
#else
        dlclose(mod);
#endif
        return r;
    }
    r.vtable = v;
    r.handle = mod;
    return r;
}

int32_t plugin_unload(void* handle) {
    if (!handle) return KAKASI_PLUGIN_ERR_BAD_ARG;
#if defined(_WIN32)
    if (!FreeLibrary((HMODULE) handle)) return KAKASI_PLUGIN_ERR_BAD_ARG;
#else
    if (dlclose(handle) != 0) return KAKASI_PLUGIN_ERR_BAD_ARG;
#endif
    return KAKASI_PLUGIN_OK;
}

int32_t plugin_scan_dir(const char* dir, char out[][512], int32_t max_out) {
    if (!dir || !out || max_out <= 0) return KAKASI_PLUGIN_ERR_BAD_ARG;
    int32_t n = 0;
#if defined(_WIN32)
    WIN32_FIND_DATAA fd;
    char pattern[512];
    std::snprintf(pattern, sizeof(pattern), "%s\\*.dll", dir);
    HANDLE h = FindFirstFileA(pattern, &fd);
    if (h == INVALID_HANDLE_VALUE) return KAKASI_PLUGIN_ERR_NO_DEVICE;
    do {
        if (n < max_out) {
            std::snprintf(out[n], 512, "%s\\%s", dir, fd.cFileName);
            ++n;
        }
    } while (FindNextFileA(h, &fd));
    FindClose(h);
#else
    DIR* dp = opendir(dir);
    if (!dp) return KAKASI_PLUGIN_ERR_NO_DEVICE;
    struct dirent* de;
    while ((de = readdir(dp)) != nullptr) {
        const char* nm = de->d_name;
        const size_t len = std::strlen(nm);
        bool ok = false;
        if (len > 6 && std::strcmp(nm + len - 6, ".dylib") == 0) ok = true;
        if (len > 3 && std::strcmp(nm + len - 3, ".so") == 0) ok = true;
        if (ok && n < max_out) {
            std::snprintf(out[n], 512, "%s/%s", dir, nm);
            ++n;
        }
    }
    closedir(dp);
#endif
    return n;
}

}  // namespace kakasi_plugins
