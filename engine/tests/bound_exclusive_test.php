<?php
// F422: unit test for api_schema_bound_exclusive - the adjacent-integer conversion of a STRICT
// (exclusive) bound on an integer leaf. Runs under the toolchain PHP CLI, no engine and no GPU:
//   php engine/tests/bound_exclusive_test.php
// The function is extracted from engine/main.php at run time, so the test cannot drift from the
// implementation it is checking. exclusiveMinimum -> floor(X)+1, exclusiveMaximum -> ceil(X)-1;
// a literal that is not a plain decimal (an exponent form) rejects with the shared sentinel.
$src = file_get_contents(__DIR__ . "/../main.php");
if ($src === false || preg_match('/^function api_schema_bound_exclusive.*?^}/ms', $src, $m) != 1) {
    echo "EXTRACT: FAIL (api_schema_bound_exclusive not found in engine/main.php)\n";
    exit(1);
}
eval($m[0]);

// lit, isMin, expected. isMin=1 -> floor(X)+1; isMin=0 -> ceil(X)-1. Both signs, the +/-half
// fractions, zero fractions, and the exponent sentinel.
$cases = array(
    array("0", 1, 1), array("0", 0, -1),
    array("5", 1, 6), array("5", 0, 4),
    array("-2", 1, -1), array("-2", 0, -3),
    array("1.5", 1, 2), array("1.5", 0, 1),
    array("-1.5", 1, -1), array("-1.5", 0, -2),
    array("0.5", 1, 1), array("0.5", 0, 0),
    array("-0.5", 1, 0), array("-0.5", 0, -1),
    array("2.0", 1, 3), array("2.0", 0, 1),
    array("9.5", 1, 10), array("9.5", 0, 9),
    array("100000", 0, 99999), array("100000", 1, 100001),
    array("1e1", 1, -1000000), array("1e1", 0, -1000000),
);
$bad = 0;
foreach ($cases as $c) {
    $got = api_schema_bound_exclusive($c[0], $c[1]);
    $ok = $got === $c[2];
    if (!$ok) {
        $bad++;
    }
    echo ($ok ? "ok  " : "BAD ") . $c[0] . " isMin=" . $c[1] . " got=" . $got . " want=" . $c[2] . "\n";
}
echo "EXCLUSIVE VERDICT: " . ($bad == 0 ? "PASS (" . count($cases) . " cases)" : "FAIL cases=" . $bad) . "\n";
exit($bad == 0 ? 0 : 1);
