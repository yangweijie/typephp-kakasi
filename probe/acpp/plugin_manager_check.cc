// plugin_manager_check.cc - F694 v2 step 1 unit check: the cross-platform plugin manager
// against the real dylibs. Every refusal must come back as a NAMED reason with the stable
// prefix documented in plugin_manager.h; every success must actually work (device_count > 0).
//
// argv: <good.dylib> <nocaps.dylib> <noentry.dylib> <nullfn.dylib> <scan_dir_with_two> <empty_or_missing_dir>
#include "../../engine/src/plugins/plugin_manager.h"
#include <cstdio>
#include <cstring>

namespace {
int failures = 0;
void expect(bool cond, const char* what) {
    if (!cond) {
        std::printf("PMGR-CHECK: FAIL %s\n", what);
        ++failures;
    }
}
bool startsWith(const char* s, const char* prefix) {
    return std::strncmp(s, prefix, std::strlen(prefix)) == 0;
}
}  // namespace

using namespace kakasi_plugins;

int main(int argc, char** argv) {
    const char* good = (argc > 1) ? argv[1] : "/tmp/f693/libkakasi_plugin_opencl.dylib";
    const char* nocaps = (argc > 2) ? argv[2] : "/tmp/f693/libplugin_stub_nocaps.dylib";
    const char* noentry = (argc > 3) ? argv[3] : "/tmp/f693/libplugin_stub_noentry.dylib";
    const char* nullfn = (argc > 4) ? argv[4] : "/tmp/f693/libplugin_stub_nullfn.dylib";
    const char* scanDir = (argc > 5) ? argv[5] : "/tmp/f693/pmgr_scan";
    const char* emptyDir = (argc > 6) ? argv[6] : "/tmp/f693/pmgr_empty";

    // ---- 1. success path: full role, real device workable ----
    {
        LoadResult r = plugin_load(good, KAKASI_PLUGIN_ABI(1, 0), KAKASI_PLUGIN_ROLE_COMPUTE_CAPS);
        expect(r.vtable != nullptr && r.handle != nullptr && r.reason[0] == '\0', "good plugin load");
        if (r.vtable) {
            expect(r.vtable->device_count() > 0, "good plugin device_count > 0");
            expect(plugin_unload(r.handle) == KAKASI_PLUGIN_OK, "good plugin unload");
        }
        std::printf("PMGR-CHECK load-good: reason=\"%s\" %s\n", r.reason,
                    r.vtable ? "ok" : "FAIL");
    }
    // ---- 2. abi major refused by the plugin, named ----
    {
        LoadResult r = plugin_load(good, KAKASI_PLUGIN_ABI(2, 0), KAKASI_PLUGIN_ROLE_COMPUTE_CAPS);
        expect(r.vtable == nullptr && startsWith(r.reason, "abi refused by plugin"), "abi refusal named");
        std::printf("PMGR-CHECK abi-refusal: reason=\"%s\"\n", r.reason);
    }
    // ---- 3. caps missing, named with the exact missing mask ----
    {
        LoadResult r = plugin_load(nocaps, KAKASI_PLUGIN_ABI(1, 0), KAKASI_PLUGIN_ROLE_COMPUTE_CAPS);
        expect(r.vtable == nullptr && startsWith(r.reason, "caps missing"), "caps-missing named");
        expect(std::strstr(r.reason, "missing=0x3c") != nullptr, "missing mask 0x3c named");
        std::printf("PMGR-CHECK caps-refusal: reason=\"%s\"\n", r.reason);
    }
    // ---- 4. entry symbol missing, named ----
    {
        LoadResult r = plugin_load(noentry, KAKASI_PLUGIN_ABI(1, 0), 0);
        expect(r.vtable == nullptr && startsWith(r.reason, "entry missing"), "entry-missing named");
        std::printf("PMGR-CHECK entry-refusal: reason=\"%s\"\n", r.reason);
    }
    // ---- 5. nonexistent path, named ----
    {
        LoadResult r = plugin_load("/tmp/f693/does_not_exist.dylib", KAKASI_PLUGIN_ABI(1, 0), 0);
        expect(r.vtable == nullptr && startsWith(r.reason, "open failed"), "open-failed named");
        std::printf("PMGR-CHECK open-refusal: reason=\"%s\"\n", r.reason);
    }
    // ---- 6. claimed-cap-with-NULL-fn invariant, named (stub claims ALL caps, gemv_mm NULL) ----
    {
        LoadResult r = plugin_load(nullfn, KAKASI_PLUGIN_ABI(1, 0), KAKASI_PLUGIN_ROLE_COMPUTE_CAPS);
        expect(r.vtable == nullptr && startsWith(r.reason, "caps null fn"), "nullfn invariant named");
        expect(std::strstr(r.reason, "name=gemv_mm") != nullptr, "nullfn names the slot");
        std::printf("PMGR-CHECK nullfn-refusal: reason=\"%s\"\n", r.reason);
    }
    // ---- 7. scan_dir: two dylibs found, missing dir negative ----
    {
        char out[8][512];
        const int n = plugin_scan_dir(scanDir, out, 8);
        expect(n == 2, "scan_dir finds exactly the two stub dylibs");
        const int n2 = plugin_scan_dir(emptyDir, out, 8);
        expect(n2 == 0 || n2 == KAKASI_PLUGIN_ERR_NO_DEVICE, "empty/missing dir handled");
        std::printf("PMGR-CHECK scan-dir: n=%d empty=%d\n", n, n2);
    }
    // ---- 8. shared caps-slot violation helper: clean plugin passes ----
    {
        LoadResult r = plugin_load(good, KAKASI_PLUGIN_ABI(1, 0), 0);
        if (r.vtable) {
            expect(plugin_caps_slot_violation(r.vtable, r.vtable->query_caps()) == nullptr,
                   "good plugin passes the shared slot invariant");
            plugin_unload(r.handle);
        }
    }

    if (failures == 0) {
        std::printf("PMGR-CHECK: PASS (loader + all refusal paths + scan via plugin_manager)\n");
    } else {
        std::printf("PMGR-CHECK: FAIL failures=%d\n", failures);
    }
    return failures == 0 ? 0 : 1;
}
