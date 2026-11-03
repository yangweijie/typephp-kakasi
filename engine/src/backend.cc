// PHP-facing bridge, compiled by cl. Only this file knows both php:: types and the backend.
#include <phpx.h>

#include <string>

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
