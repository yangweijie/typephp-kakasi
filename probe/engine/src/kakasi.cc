// PHP-facing bridge, compiled by cl together with the AOT output. This is the only
// file that knows about both php:: types and the SYCL side.
#include <phpx.h>

#include <string>

namespace kakasi {
int device_count();
std::string device_name(int index);
std::string last_error();
double matmul_maxerr(int index, int n);
}  // namespace kakasi

using namespace php;

php::Int php_sycl_device_count() {
    return php::Int(kakasi::device_count());
}

php::Str php_sycl_device_name(php::Int index) {
    return php::Str(kakasi::device_name((int) index));
}

php::Str php_sycl_last_error() {
    return php::Str(kakasi::last_error());
}

php::Float php_sycl_matmul_maxerr(php::Int index, php::Int n) {
    return php::Float(kakasi::matmul_maxerr((int) index, (int) n));
}

php::Int php_native_roundtrip(php::Int x) {
    return php::Int(x + 1);
}
