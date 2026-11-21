/* plugin_abi.h - Kakasi vendor-backend plugin ABI v1 (F694 B-section spec, task_plan.md).
 *
 * The cross-system contract between the engine (host) and dynamically loaded vendor backend
 * plugins (OpenCL/Metal/CUDA/HIP/...). Pure C-compatible header: the ONLY shared artifact -
 * the plugin is built per-OS (dll/dylib/so) by its author against this header, loaded
 * in-process. F650's retraction left the ABI undecided; this header pins it.
 *
 * Layout rules (see task_plan F694 B 段规格):
 *   - every struct starts with uint32_t struct_size (Vulkan/CL style): the receiver checks
 *     >= its expected minimum and ignores unknown tail fields - the ONLY field-evolution path.
 *   - fixed-width POD only; no std:: types, no virtuals, no exceptions, no references across
 *     the boundary; natural alignment; layout pinned by static_asserts at the bottom.
 *   - identity strings are NUL-terminated const char* in static (plugin-owned) storage;
 *     data buffers always carry an explicit byte/element count.
 *   - opaque handles (device/stream) are void* created and destroyed by the plugin; the host
 *     never interprets them.
 *   - ownership: device memory comes from mem_alloc and is released ONLY via mem_free; host
 *     memory is allocated by the CALLER with the capacity passed in - neither side ever
 *     free()s a pointer it did not allocate.
 *   - op-function buffer pointers (X/W/bias/Y/...) are DEVICE-side pointers as returned by
 *     mem_alloc; the host moves data with copy_h2d/copy_d2h.
 *   - every callback returns int32_t status (0 = OK, negative = error, see enum); the
 *     detailed reason is always available via last_error() - a failure is never silent.
 */
#ifndef KAKASI_PLUGIN_ABI_H
#define KAKASI_PLUGIN_ABI_H

#include <stdint.h>

#ifdef __cplusplus
extern "C" {
#endif

/* ---- ABI versioning: major<<16 | minor. Minor bump = tail-append only (fields/functions);
 *      major bump = any breaking change. Host loads only major-equal plugins with
 *      plugin_minor <= host_minor; anything else is refused BY NAME, never degraded silently. */
#define KAKASI_PLUGIN_ABI(major, minor) (((uint32_t)(major) << 16) | (uint32_t)(minor))
#define KAKASI_PLUGIN_ABI_MAJOR(v) ((uint32_t)(v) >> 16)
#define KAKASI_PLUGIN_ABI_MINOR(v) ((uint32_t)(v) & 0xffffu)
#define KAKASI_PLUGIN_ABI_CURRENT KAKASI_PLUGIN_ABI(1, 0)

/* ---- status codes (all callbacks return these) ---- */
enum kakasi_plugin_status {
    KAKASI_PLUGIN_OK = 0,
    KAKASI_PLUGIN_ERR_UNSUPPORTED = -1,  /* op not implemented by this plugin */
    KAKASI_PLUGIN_ERR_NO_DEVICE = -2,
    KAKASI_PLUGIN_ERR_ALLOC = -3,
    KAKASI_PLUGIN_ERR_COPY = -4,
    KAKASI_PLUGIN_ERR_LAUNCH = -5,       /* kernel launch/execution failed */
    KAKASI_PLUGIN_ERR_BAD_ARG = -6,      /* NULL handle, M out of 1..4, size overflow, ... */
    KAKASI_PLUGIN_ERR_SIZE = -7          /* struct_size too small for the caller's version */
};

/* ---- capability bits (query_caps). The host refuses AT LOAD TIME, with a named reason,
 *      any plugin that claims a role whose required bits are missing; ops a plugin did not
 *      claim are simply never routed to it - there is no silent fallback to built-ins
 *      (a fallback decision belongs to the profile-selection layer, visible in /plugins). */
#define KAKASI_PLUGIN_CAP_DEVICE       ((uint64_t) 1 << 0)  /* enumeration + open/close */
#define KAKASI_PLUGIN_CAP_MEM          ((uint64_t) 1 << 1)  /* alloc/free/copy */
#define KAKASI_PLUGIN_CAP_GEMV_MM      ((uint64_t) 1 << 2)  /* shared-weight matvec, M in 1..4 */
#define KAKASI_PLUGIN_CAP_GEMM         ((uint64_t) 1 << 3)  /* tiled SGEMM */
#define KAKASI_PLUGIN_CAP_DEQUANT_Q8_0 ((uint64_t) 1 << 4)  /* 34-byte-block Q8_0, bit-exact contract */
#define KAKASI_PLUGIN_CAP_RMSNORM      ((uint64_t) 1 << 5)

/* Required bits per role (load-time check). */
#define KAKASI_PLUGIN_ROLE_COMPUTE_CAPS \
    (KAKASI_PLUGIN_CAP_DEVICE | KAKASI_PLUGIN_CAP_MEM | \
     KAKASI_PLUGIN_CAP_GEMV_MM | KAKASI_PLUGIN_CAP_GEMM | \
     KAKASI_PLUGIN_CAP_DEQUANT_Q8_0 | KAKASI_PLUGIN_CAP_RMSNORM)

/* ---- memory kinds (mem_alloc) ---- */
enum kakasi_plugin_mem_kind {
    KAKASI_PLUGIN_MEM_DEVICE = 0,        /* device-global memory */
    KAKASI_PLUGIN_MEM_HOST_PINNED = 1    /* page-locked host memory, device-addressable if supported */
};

/* ---- device description (device_info fills this; host passes struct_size in) ---- */
struct kakasi_plugin_device_info {
    uint32_t struct_size;    /* in: sizeof of the caller's known version */
    const char* name;        /* out: NUL-terminated, static plugin-owned storage */
    uint64_t caps;           /* out: device-level capability bits (subset of the CAP_ set) */
    uint64_t mem_bytes;      /* out: total device memory, 0 = unknown */
};

/* ---- the vtable. Functions are APPEND-ONLY: never reorder, never remove, never change a
 *      signature (a semantic change means a NEW function; the old one is marked superseded
 *      in a comment). vtable_size lets the host know how far the plugin's table extends. */
typedef struct kakasi_plugin_vtable {
    uint32_t abi_version;    /* the ABI this plugin was built against */
    uint32_t vtable_size;    /* sizeof of the plugin's known table (>= host's expected minimum) */

    /* --- devices (CAP_DEVICE) --- */
    int32_t (*device_count)(void);
    int32_t (*device_info)(int32_t index, struct kakasi_plugin_device_info* out);
    void* (*device_open)(int32_t index);                    /* NULL on failure */
    int32_t (*device_close)(void* device);
    const char* (*device_name)(int32_t index);              /* convenience, may be NULL */

    /* --- streams (CAP_DEVICE) --- */
    void* (*stream_create)(void* device);                   /* NULL on failure */
    int32_t (*stream_destroy)(void* stream);
    int32_t (*stream_sync)(void* stream);                   /* block until the stream drains */

    /* --- errors (always present) --- */
    const char* (*last_error)(void* device);                /* named reason for the last failure */

    /* --- memory (CAP_MEM) --- */
    void* (*mem_alloc)(void* device, uint64_t bytes, int32_t kind);  /* NULL on failure */
    int32_t (*mem_free)(void* device, void* ptr);
    int32_t (*copy_h2d)(void* stream, void* dst, const void* src, uint64_t bytes);
    int32_t (*copy_d2h)(void* stream, void* dst, const void* src, uint64_t bytes);

    /* --- compute v1 (OCLK-PROBE-validated shapes, findings F694) --- */
    /* Engine gemvM semantics (model_sycl.cc:649): one 64-lane group per output row, each
     * weight element read once and dotted into M accumulators. Host guarantees M in 1..4
     * and ldy >= N. Y[m*ldy + row]. */
    int32_t (*gemv_mm)(void* stream, const float* X, const float* W, const float* bias,
                       float* Y, int32_t K, int32_t N, int32_t M, int32_t ldy);
    /* Tiled SGEMM (ops_sycl.cc:246): Y[M][N] = X[M][K] * W[N][K]^T, tile 16/16/32. */
    int32_t (*gemm_tiled)(void* stream, const float* X, const float* W, float* Y,
                          int32_t M, int32_t N, int32_t K);
    /* Q8_0 block dequant (ops_sycl.cc:160): 34 bytes per 32-element block (f16 scale +
     * 32 int8). BIT-EXACT contract: out must equal the host reference bit for bit. */
    int32_t (*dequant_q8_0)(void* stream, const uint8_t* raw, float* out, uint64_t nblocks);
    /* rmsnorm: out[i] = x[i] * rsqrt(mean(x^2) + eps) * w[i], strided accumulation. */
    int32_t (*rmsnorm)(void* stream, const float* x, const float* w, float* out,
                       int32_t n, float eps);

    /* --- capabilities --- */
    uint64_t (*query_caps)(void);
} kakasi_plugin_vtable;

/* ---- the single entry point a plugin exports (the ONLY symbol the host looks up) ---- */
/* extern "C" const kakasi_plugin_vtable* kakasi_plugin_get_vtable(uint32_t want_abi);
 * Contract: return NULL if want_abi's major != the plugin's major; return a table whose
 * abi_version = the plugin's ABI and whose vtable_size covers every function the plugin
 * actually provides. A returned table is a promise: no NULL function pointers for
 * capabilities the plugin claims in query_caps. */

/* ---- layout pins (compile-time; a layout change is a MAJOR version bump) ---- */
#if defined(__cplusplus)
#define KAKASI_PLUGIN_ASSERT(cond, msg) static_assert(cond, msg)
#else
#define KAKASI_PLUGIN_ASSERT(cond, msg) _Static_assert(cond, msg)
#endif

KAKASI_PLUGIN_ASSERT(sizeof(struct kakasi_plugin_device_info) == 32, \
                     "device_info layout: 4+pad + 3 pointers");
KAKASI_PLUGIN_ASSERT(sizeof(void*) == 8, "plugin ABI targets 64-bit only");

#ifdef __cplusplus
}
#endif

#endif /* KAKASI_PLUGIN_ABI_H */
