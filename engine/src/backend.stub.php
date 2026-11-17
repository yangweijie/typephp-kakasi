<?php
// Backend ABI implemented in C++/SYCL (src/backend.cc -> src/sycl_backend.cc).
// Empty bodies: tpc only reads these signatures and emits direct native calls.

function backend_device_count(): int {}

function backend_device_name(int $index): string {}

function backend_last_error(): string {}

function backend_validate(int $index): bool {}

function backend_matmul_maxerr(int $index, int $n): float {}

// F656: directory of the running binary ("" when it cannot be determined).
function backend_exe_dir(): string {}
