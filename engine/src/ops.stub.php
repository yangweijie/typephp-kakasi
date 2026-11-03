<?php
// Compute-op ABI, implemented in src/ops.cc -> src/ops_sycl.cc.

function ops_last_error(): string {}

function ops_elapsed_ms(): float {}

function ops_dequant_maxerr(int $backend, int $tensor): float {}

function ops_linear_relerr(int $backend, int $tensor, int $m): float {}

function ops_rmsnorm_maxerr(int $backend, int $len): float {}
