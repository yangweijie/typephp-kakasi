// PHP-facing bridge, compiled by cl. Only this file knows both php:: types and the backend.
#include <phpx.h>

#include <string>
#ifdef _WIN32
#include <windows.h>
#endif

namespace kakasi {
int device_count();
std::string device_name(int index);
std::string last_error();
bool validate(int index);
double matmul_maxerr(int index, int n);
}  // namespace kakasi

using namespace php;

php::Int php_backend_device_count() {
    return php::Int(kakasi::device_count());
}

php::Str php_backend_device_name(php::Int index) {
    return php::Str(kakasi::device_name((int) index));
}

php::Str php_backend_last_error() {
    return php::Str(kakasi::last_error());
}

php::Bool php_backend_validate(php::Int index) {
    return php::Bool(kakasi::validate((int) index));
}

php::Float php_backend_matmul_maxerr(php::Int index, php::Int n) {
    return php::Float(kakasi::matmul_maxerr((int) index, (int) n));
}

// F656: the running image's own directory, so files the engine reads at start-up (vendor profiles)
// can sit next to the binary instead of at a path baked into the build machine's checkout.
php::Str php_backend_exe_dir() {
#ifdef _WIN32
    char buf[MAX_PATH];
    DWORD n = GetModuleFileNameA(nullptr, buf, MAX_PATH);
    if (n == 0 || n >= MAX_PATH) return php::Str("");
    std::string p(buf, (size_t) n);
    size_t slash = p.find_last_of("\\/");
    if (slash == std::string::npos) return php::Str("");
    return php::Str(p.substr(0, slash));
#else
    return php::Str("");
#endif
}
