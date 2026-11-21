/* F694 B-section acceptance ① (C variant): the ABI header must compile as plain C11 -
 * plugin authors may write in C (e.g., OpenCL hosts without C++). Only the header is
 * included here; the layout static_asserts fire at compile time if it drifted. */
#include "../../engine/src/plugins/plugin_abi.h"
#include <stdio.h>

int main(void) {
    if (KAKASI_PLUGIN_ABI_MAJOR(KAKASI_PLUGIN_ABI_CURRENT) != 1 ||
        KAKASI_PLUGIN_ABI_MINOR(KAKASI_PLUGIN_ABI_CURRENT) != 0) {
        printf("ABI-CHECK-C: FAIL version macro\n");
        return 1;
    }
    printf("ABI-CHECK-C: PASS (header compiles as C11; layout asserts compiled)\n");
    return 0;
}
