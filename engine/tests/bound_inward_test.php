<?php
// F421: unit test for api_schema_bound_inward - the inward rounding of a fractional bound on an
// integer leaf. Runs under the toolchain PHP CLI, no engine and no GPU:
//   php engine/tests/bound_inward_test.php
// The function is extracted from engine/main.php at run time, so the test cannot drift from the
// implementation it is checking.
$src = file_get_contents(__DIR__ . "/../main.php");
if ($src === false || preg_match('/^function api_schema_bound_inward.*?^}/ms', $src, $m) != 1) {
    echo "EXTRACT: FAIL (api_schema_bound_inward not found in engine/main.php)\n";
    exit(1);
}
eval($m[0]);

// lit, isMin, expected. Negative literals truncate toward zero, which already is the ceiling for a
// minimum and needs -1 for the floor of a maximum; a zero fraction must not move either side.
$cases = array(
    array("1.5", 1, 2), array("9.5", 0, 9), array("1.5", 0, 1), array("1.9", 0, 1),
    array("-9.5", 1, -9), array("-1.5", 0, -2), array("-1.5", 1, -1), array("-9.5", 0, -10),
    array("2.0", 1, 2), array("9.0", 0, 9), array("0.5", 1, 1), array("0.5", 0, 0),
    array("-0.5", 1, 0), array("-0.5", 0, -1), array("1e1", 1, -1000000), array("1e1", 0, -1000000),
    array("7", 1, 7), array("-7", 0, -7), array("1.50", 1, 2), array("1.00", 1, 1),
    array("1.9", 1, 2), array("-2.0", 0, -2),
);
$bad = 0;
foreach ($cases as $c) {
    $got = api_schema_bound_inward($c[0], $c[1]);
    $ok = $got === $c[2];
    if (!$ok) {
        $bad++;
    }
    echo ($ok ? "ok  " : "BAD ") . $c[0] . " isMin=" . $c[1] . " got=" . $got . " want=" . $c[2] . "\n";
}
echo "INWARD VERDICT: " . ($bad == 0 ? "PASS (" . count($cases) . " cases)" : "FAIL cases=" . $bad) . "\n";
exit($bad == 0 ? 0 : 1);
