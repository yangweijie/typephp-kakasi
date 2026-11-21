// plugin_stub_noentry.cc - acceptance ③ negative-control fixture: a dylib WITHOUT the
// required entry symbol. dlsym("kakasi_plugin_get_vtable") must fail and the host must
// report the refusal by name (PLUGIN-REFUSE entry).
#include <cstdio>

extern "C" const void* kakasi_plugin_wrong_symbol(void) {
    return nullptr;   /* deliberately NOT the required entry */
}
