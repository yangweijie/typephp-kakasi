<?php

function php_roundtrip(int $x): int
{
    return $x + 1;
}

function main(): void
{
    // --- native call overhead: same work, once through C++ and once in PHP ---
    $iters = 2000000;

    $t0 = microtime(true);
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $acc += native_roundtrip($i);
    }
    $t1 = microtime(true);
    $nativeNs = ($t1 - $t0) * 1000000000.0 / $iters;

    $t2 = microtime(true);
    $acc2 = 0;
    for ($i = 0; $i < $iters; $i++) {
        $acc2 += php_roundtrip($i);
    }
    $t3 = microtime(true);
    $phpNs = ($t3 - $t2) * 1000000000.0 / $iters;

    echo "sink: {$acc}/{$acc2}\n";
    echo "native call: {$nativeNs} ns/op   php call: {$phpNs} ns/op\n";

    // --- per-device matmul through the natively linked SYCL object ---
    // Two rounds over the same device list: distinguishes "this device class is
    // broken" from "reusing/switching devices inside one process is broken".
    $count = sycl_device_count();
    $n = 64;
    echo "devices: {$count}\n";

    $passed = 0;
    for ($round = 1; $round <= 2; $round++) {
        for ($i = 0; $i < $count; $i++) {
            $name = sycl_device_name($i);
            $err = sycl_matmul_maxerr($i, $n);
            if ($err >= 0.0 && $err < 0.01) {
                echo "r{$round} [{$i}] {$name} PASS maxerr={$err}\n";
                $passed++;
            } else {
                echo "r{$round} [{$i}] {$name} FAIL err={$err} why=" . sycl_last_error() . "\n";
            }
        }
    }

    echo "RESULT: {$passed}/" . ($count * 2) . " runs PASS\n";
    echo $passed >= 2 ? "PROBE: PASS (PHP -> SYCL native, >=2 backends)\n" : "PROBE: INCONCLUSIVE\n";
}
