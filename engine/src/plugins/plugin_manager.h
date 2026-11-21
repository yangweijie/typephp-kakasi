/* plugin_manager.h - cross-platform loader for Kakasi vendor plugins (F694 v2 step 1).
 *
 * The host-side half of plugin_abi.h: dynamic-module open/close, ABI handshake, role/caps
 * checks and the claimed-caps-imply-non-NULL invariant - ALL refusal paths produce a named,
 * stable reason string (the F650 rule: explicit refusal, never silent degradation; the same
 * semantic must have exactly one implementation, F651).
 *
 * This file compiles standalone (no engine headers, no SYCL) so it can be unit-verified on
 * any OS before engine integration. The engine (v2) passes its own exe-relative base dir
 * (the api_state_dir/backend_exe_dir pattern, F656/F657) - the manager does not re-implement
 * exe detection.
 *
 * Refusal reasons (manager.reason, NUL-terminated, stable prefixes for tests):
 *   "open failed: ..."                                   - module could not be opened
 *   "entry missing: ..."                                 - no kakasi_plugin_get_vtable export
 *   "abi refused by plugin: want=0x.. major mismatch"    - entry returned NULL for our ABI
 *   "abi incomplete: vtable_size .. < expected .."       - table smaller than our ABI needs
 *   "caps missing: role=0x.. missing=0x.."               - role bits not claimed
 *   "caps null fn: bit=0x.. name=<fn>"                   - claimed cap with NULL function
 */
#ifndef KAKASI_PLUGIN_MANAGER_H
#define KAKASI_PLUGIN_MANAGER_H

#include "plugin_abi.h"

namespace kakasi_plugins {

struct LoadResult {
    const kakasi_plugin_vtable* vtable;  /* NULL on refusal */
    void* handle;                        /* OS module handle; valid even on plugin-side
                                            abi refusal? No: NULL on every refusal. */
    char reason[256];                    /* named refusal reason; "" on success */
};

/* Load one plugin. role_caps = the bits the caller's intended role REQUIRES (0 = only the
 * handshake and the NULL-for-claimed invariant). On success vtable != NULL, handle set. */
LoadResult plugin_load(const char* path, uint32_t want_abi, uint64_t role_caps);

/* Unload a handle returned by plugin_load. Returns OK / BAD_ARG. */
int32_t plugin_unload(void* handle);

/* Enumerate candidate plugin modules in a directory (the engine passes its exe-relative
 * plugins dir). Returns the count written (0 = none found, negative = dir open failure).
 * Extensions per OS: .dylib (macOS) / .dll (Windows) / .so (Linux). */
int32_t plugin_scan_dir(const char* dir, char out[][512], int32_t max_out);

/* The shared slot table: returns NULL-ness of the function each capability bit requires.
 * 0 = consistent, else a named reason string for the FIRST violated bit. Exported so the
 * harness, the manager and (later) the engine all use ONE implementation. */
const char* plugin_caps_slot_violation(const kakasi_plugin_vtable* v, uint64_t caps);

}  // namespace kakasi_plugins

#endif /* KAKASI_PLUGIN_MANAGER_H */
