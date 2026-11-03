// PHP-facing bridge for the compute ops, compiled by cl.
#include <phpx.h>

#include <string>

namespace ops {
std::string last_error();
double elapsed_ms();
double dequant_maxerr(int backend, int tensor);
double linear_relerr(int backend, int tensor, int m);
double rmsnorm_maxerr(int backend, int len);
}  // namespace ops

using namespace php;

php::Str php_ops_last_error() { return php::Str(ops::last_error()); }
php::Float php_ops_elapsed_ms() { return php::Float(ops::elapsed_ms()); }
php::Float php_ops_dequant_maxerr(php::Int backend, php::Int tensor) {
    return php::Float(ops::dequant_maxerr((int) backend, (int) tensor));
}
php::Float php_ops_linear_relerr(php::Int backend, php::Int tensor, php::Int m) {
    return php::Float(ops::linear_relerr((int) backend, (int) tensor, (int) m));
}
php::Float php_ops_rmsnorm_maxerr(php::Int backend, php::Int len) {
    return php::Float(ops::rmsnorm_maxerr((int) backend, (int) len));
}
