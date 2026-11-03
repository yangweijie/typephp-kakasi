<?php
// Native (C++/SYCL) functions implemented in src/kakasi.cc. Empty bodies: the
// AOT compiler only reads the signatures and emits direct calls to php_* symbols.

function sycl_device_count(): int {}

function sycl_device_name(int $index): string {}

function sycl_last_error(): string {}

function sycl_matmul_maxerr(int $index, int $n): float {}

// Deliberately SYCL-free: isolates the cost of a PHP -> native C++ call.
function native_roundtrip(int $x): int {}
