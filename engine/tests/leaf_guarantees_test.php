<?php
// F457: unit test for api_schema_leaf_guarantees - the static judgement "does this plan leaf kind
// guarantee every value satisfies the subschema". Runs under the toolchain PHP CLI, no engine:
//   php engine/tests/leaf_guarantees_test.php
// The functions are extracted from engine/main.php at run time, so this test cannot drift from the
// implementation it checks. The enum kind is the only one that promises more than a bare type; the
// plan encodes it as "e:v1~v2" and both sides of the comparison go through api_list/api_unesc, so
// the test also pins that the two parsers agree on spaces and escapes.
$src = file_get_contents(__DIR__ . "/../main.php");
if ($src === false) {
    echo "EXTRACT: FAIL (engine/main.php unreadable)\n";
    exit(1);
}
foreach (array("api_unesc", "api_list", "api_schema_leaf_guarantees") as $fn) {
    if (preg_match('/^function ' . $fn . '.*?^}/ms', $src, $m) != 1) {
        echo "EXTRACT: FAIL (" . $fn . " not found in engine/main.php)\n";
        exit(1);
    }
    eval($m[0]);
}

$cases = array(
    array("e:paris~lyon", '{"enum":["paris","lyon"]}', 1),
    array("e:paris~lyon", '{"enum":["paris"]}', 0),
    array("e:paris", '{"const":"paris"}', 1),
    array("e:paris~lyon", '{"const":"paris"}', 0),
    array("e:paris~prague", '{"pattern":"^p"}', 1),
    array("e:paris~lyon", '{"pattern":"^p"}', 0),
    array("s", '{"enum":["a"]}', 0),
    array("i", '{"const":"1"}', 0),
    array("e:paris", '{"type":"string","const":"paris"}', 1),
    array("e:paris~lyon", '{"type":"string","enum":["lyon","paris"]}', 1),
    array("e:new work", '{"enum":["new work"]}', 1),
    array("e:a", '{}', 0),
);
$bad = 0;
foreach ($cases as $c) {
    $got = api_schema_leaf_guarantees($c[0], $c[1]);
    $ok = $got === $c[2];
    if (!$ok) {
        $bad++;
    }
    echo ($ok ? "ok  " : "BAD ") . $c[0] . " sub=" . $c[1] . " got=" . $got . " want=" . $c[2] . "\n";
}
echo "GUARANTEES VERDICT: " . ($bad == 0 ? "PASS (" . count($cases) . " cases)"
    : "FAIL cases=" . $bad) . "\n";
exit($bad == 0 ? 0 : 1);
