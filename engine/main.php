<?php

// ---- Phase 3: OpenAI-compatible endpoint -------------------------------------------------
// One JSON field by name. Values are simple strings/numbers here, so a single capture group is
// enough; the escaped-quote alternative keeps content with quotes intact.
function api_str(string $json, string $key): string
{
    $pat = "/\"" . $key . "\"\\s*:\\s*\"((?:[^\"\\\\]|\\\\.)*)\"/";
    $m = [];
    if (preg_match($pat, $json, $m)) {
        return $m[1];
    }
    return "";
}

// The LAST occurrence, which for "messages" is the newest turn.
function api_str_last(string $json, string $key): string
{
    $pat = "/.*\"" . $key . "\"\\s*:\\s*\"((?:[^\"\\\\]|\\\\.)*)\"/s";
    $m = [];
    if (preg_match($pat, $json, $m)) {
        return $m[1];
    }
    return "";
}

function api_num(string $json, string $key, int $def): int
{
    $pat = "/\"" . $key . "\"\\s*:\\s*(-?[0-9]+)/";
    $m = [];
    if (preg_match($pat, $json, $m)) {
        return intval($m[1]);
    }
    return $def;
}

// Decimal string to float without floatval: sign, integer part, fractional part.
function api_float(string $json, string $key, float $def): float
{
    $pat = "/\"" . $key . "\"\\s*:\\s*(-?[0-9]+(?:\\.[0-9]+)?)/";
    $m = [];
    if (!preg_match($pat, $json, $m)) {
        return $def;
    }
    $s = $m[1];
    $neg = 0.0;
    if (substr($s, 0, 1) == "-") {
        $neg = 1.0;
        $s = substr($s, 1);
    }
    $dot = strpos($s, ".");
    $ip = $dot === false ? $s : substr($s, 0, $dot);
    $fp = $dot === false ? "" : substr($s, $dot + 1);
    $v = (float) intval($ip);
    if (strlen($fp) > 0) {
        $den = 1.0;
        for ($i = 0; $i < strlen($fp); $i++) {
            $den = $den * 10.0;
        }
        $v = $v + (float) intval($fp) / $den;
    }
    return $neg > 0.0 ? -$v : $v;
}

// OpenAI "stop" / Anthropic "stop_sequences": a single string or an array of strings.
function api_stop_list(string $body): array
{
    $out = [];
    $m = [];
    if (preg_match('/"stop_sequences"\s*:\s*\[(.*?)\]/s', $body, $m)) {
        $segs = explode("\"", $m[1]);
        for ($i = 1; $i < count($segs); $i += 2) {
            $v = api_unesc($segs[$i]);
            if ($v != "") {
                $out[] = $v;
            }
        }
        if (count($out) > 0) {
            return $out;
        }
    }
    if (preg_match('/"stop"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $body, $m)) {
        $v = api_unesc($m[1]);
        if ($v != "") {
            $out[] = $v;
        }
        return $out;
    }
    if (preg_match('/"stop"\s*:\s*\[(.*?)\]/s', $body, $m)) {
        $segs = explode("\"", $m[1]);
        for ($i = 1; $i < count($segs); $i += 2) {
            $v = api_unesc($segs[$i]);
            if ($v != "") {
                $out[] = $v;
            }
        }
    }
    return $out;
}

// Earliest index of any stop string, or -1.
function api_stop_cut(string $text, array $stops): int
{
    $cut = -1;
    for ($i = 0; $i < count($stops); $i++) {
        $p = strpos($text, $stops[$i]);
        if ($p !== false && ($cut < 0 || $p < $cut)) {
            $cut = $p;
        }
    }
    return $cut;
}

// Structural JSON check for `response_format: json_object`. This is VALIDATION, not constrained
// decoding: the model is free to emit anything, the caller retries once if the shape is broken.
// A tiny scanner is enough - braces/brackets must balance outside string literals, and the
// result must start with an object or array.
function api_json_ok(string $s): int
{
    $n = strlen($s);
    $i = 0;
    while ($i < $n) {
        $c = substr($s, $i, 1);
        if ($c != " " && $c != "\n" && $c != "\r" && $c != "\t") {
            break;
        }
        $i++;
    }
    if ($i >= $n) {
        return 0;
    }
    $c0 = substr($s, $i, 1);
    if ($c0 != "{" && $c0 != "[") {
        return 0;
    }
    $depth = 0;
    $inStr = 0;
    $esc = 0;
    $last = "";
    for (; $i < $n; $i++) {
        $c = substr($s, $i, 1);
        if ($inStr == 1) {
            if ($esc == 1) {
                $esc = 0;
            } else if ($c == "\\") {
                $esc = 1;
            } else if ($c == "\"") {
                $inStr = 0;
            }
            continue;
        }
        if ($c == "\"") {
            $inStr = 1;
        } else if ($c == "{" || $c == "[") {
            $depth++;
        } else if ($c == "}" || $c == "]") {
            $depth--;
            if ($depth < 0) {
                return 0;
            }
        }
        if ($c != " " && $c != "\n" && $c != "\r" && $c != "\t") {
            $last = $c;
        }
    }
    if ($depth != 0 || $inStr != 0) {
        return 0;
    }
    return ($last == "}" || $last == "]") ? 1 : 0;
}

// Split the body of a JSON array into trimmed, unquoted strings: `"a","b"` -> a, b.
function api_list(string $inner): array
{
    // Quoted entries win: they keep spaces and commas inside a value ("New York", "a,b").
    $out = [];
    $segs = explode("\"", $inner);
    for ($i = 1; $i < count($segs); $i += 2) {
        $v = api_unesc($segs[$i]);
        if ($v != "") {
            $out[] = $v;
        }
    }
    if (count($out) > 0) {
        return $out;
    }
    // Bare tokens (numbers etc.): the old comma split, with quotes/spaces removed.
    foreach (explode(",", $inner) as $raw) {
        $s = $raw;
        $s = str_replace("\"", "", $s);
        $s = str_replace(" ", "", $s);
        $s = str_replace("\n", "", $s);
        if ($s != "") {
            $out[] = $s;
        }
    }
    return $out;
}

// Constrained decoding over a known set of continuations (enum / const). Each step restricts the
// greedy choice to the tokens that keep us inside SOME candidate that still matches the prefix,
// so a forbidden answer is not merely discouraged - it cannot be emitted. This is the real
// counterpart to the json_object validator in F44.
// "count|bytes" from a "name|bytes" listing (prefix-cache buckets).
function api_cache_stats(string $listing): string
{
    $lines = explode("\n", $listing);
    $n = 0;
    $b = 0;
    for ($i = 0; $i < count($lines); $i++) {
        if ($lines[$i] == "") {
            continue;
        }
        $f = explode("|", $lines[$i]);
        $n++;
        if (count($f) >= 2) {
            $b = $b + (int) $f[1];
        }
    }
    return "" . $n . "|" . $b;
}

// Requested completion length: default 64, capped at the engine's 512-token KV window.
// (Before this, anything above 512 silently became 64.)
function api_max_tokens(int $v): int
{
    if ($v <= 0) {
        return 64;
    }
    return $v > 512 ? 512 : $v;
}

// Prefix-cache bucket: different conversations get different files instead of overwriting
// each other; the same prompt always maps to the same bucket.
function api_cache_path(string $ids): string
{
    return "C:/git/c/typephp-kakasi/engine/api_prefix_b" . model_cache_key($ids, 64) . ".kv";
}

// Store only when the entry is worth keeping AND we are not clobbering a better prefix with a
// short accidental match (a divergent prompt sharing only the chat header used to evict the
// whole conversation cache).
function api_cache_should_store(int $hit, int $promptN): int
{
    if ($hit < 0 || $promptN < 64 || $hit >= $promptN) {
        return 0;
    }
    return ($hit == 0 || $hit >= 32) ? 1 : 0;
}

// Candidate-prefix argmax loop over the CURRENT session: at every step only tokens that extend
// some candidate's already-matched prefix are reachable. Returns the chosen ids as strings.
function api_constrain_loop(array $vals, int $maxSteps): array
{
    $cand = [];
    for ($i = 0; $i < count($vals); $i++) {
        $t = tok_encode($vals[$i]);
        if ($t != "") {
            $cand[] = explode(",", $t);
        }
    }
    $chosen = [];
    if (count($cand) == 0) {
        return $chosen;
    }
    for ($d = 0; $d < $maxSteps; $d++) {
        $allowed = "";
        $done = 0;
        for ($i = 0; $i < count($cand); $i++) {
            if (count($cand[$i]) < $d + 1) {
                if (count($cand[$i]) == $d) {
                    $done = 1;      // this candidate is complete and its prefix matched
                }
                continue;
            }
            $match = 1;
            for ($j = 0; $j < $d; $j++) {
                if ($chosen[$j] != $cand[$i][$j]) {
                    $match = 0;
                    break;
                }
            }
            if ($match == 0) {
                continue;
            }
            $allowed = $allowed == "" ? $cand[$i][$d] : $allowed . "," . $cand[$i][$d];
        }
        if ($done == 1 || $allowed == "") {
            break;
        }
        $nx = model_session_next_allowed(0, $allowed);
        if ($nx < 0) {
            break;
        }
        $chosen[] = "" . $nx;
    }
    return $chosen;
}

function api_constrained(string $ids, array $vals, int $maxSteps, int $useCache = 1): string
{
    // Constrained decoding is greedy, so it can start from a cached prefix like plain decoding.
    $pcPathC = api_cache_path($ids);
    $promptNc = count(explode(",", $ids));
    $hitc = $useCache == 1 ? model_cache_prefill(0, $ids, $pcPathC) : -1;
    if ($hitc < 0 && model_session_start(0, $ids) != 0) {
        return "";
    }
    $chosen = api_constrain_loop($vals, $maxSteps);
    if (api_cache_should_store($hitc, $promptNc) == 1) {
        model_cache_store(0, $ids, $pcPathC);
    }
    $s = $ids;
    for ($i = 0; $i < count($chosen); $i++) {
        $s = $s . "," . $chosen[$i];
    }
    return $s;
}

// Same loop, but the caller already prefilled the session (used to force a literal continuation
// after a grammar-constrained value). Returns the chosen ids as CSV, no prompt prefix.
function api_constrained_continue(array $vals, int $maxSteps): string
{
    $chosen = api_constrain_loop($vals, $maxSteps);
    $s = "";
    for ($i = 0; $i < count($chosen); $i++) {
        if ($i > 0) {
            $s = $s . ",";
        }
        $s = $s . $chosen[$i];
    }
    return $s;
}

// Tokens that are complete JSON escapes by themselves: a \" pair or an even run of
// backslashes. Odd runs or mixed pieces would leave the string in an ambiguous state.
function api_json_escape_safe_ids(string $idsCsv): string
{
    $out = "";
    $parts = explode(",", $idsCsv);
    for ($i = 0; $i < count($parts); $i++) {
        if ($parts[$i] == "") {
            continue;
        }
        $pc = gguf_token_str((int) $parts[$i]);
        $ok = 0;
        if ($pc == "\\\"") {
            $ok = 1;
        } else if ($pc != "" && preg_match('/^\\\\+$/', $pc) == 1 && strlen($pc) % 2 == 0) {
            $ok = 1;
        }
        if ($ok == 1) {
            $out = $out == "" ? $parts[$i] : $out . "," . $parts[$i];
        }
    }
    return $out;
}

// Grammar-constrained JSON string value. Force `prefix` literally, then emit only tokens whose
// decoded text stays inside `charset`, allowing the closing quote to terminate early; finally
// force the rest of the object so the result is always parseable. Returns the full id string.
function api_string_value(string $ids, string $prefix, string $charset, int $maxSteps, int $useCache = 1, int $forceEsc = 0): string
{
    $charsetIds = gguf_charset_ids($charset);
    if ($charsetIds == "") {
        return "";
    }
    $s = api_constrained($ids, [$prefix], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $quoteIds = gguf_charset_ids("\"");
    $bsIds = api_json_escape_safe_ids(gguf_charset_ids("\\\""));
    $stepIds = $charsetIds;
    if ($quoteIds != "") {
        $stepIds = $stepIds . "," . $quoteIds;
    }
    if ($bsIds != "") {
        $stepIds = $stepIds . "," . $bsIds;
    }
    $q = "," . $quoteIds . ",";
    if ($forceEsc == 1) {
        $fb = api_constrained_continue(["\\\""], 8);
        if ($fb == "") {
            return "";
        }
        $s = $s . "," . $fb;
    }
    $closed = 0;
    $tail = "";
    for ($d = 0; $d < $maxSteps; $d++) {
        $nx = model_session_next_allowed(0, $stepIds);
        if ($nx < 0) {
            break;
        }
        if ($tail != "") {
            $tail = $tail . ",";
        }
        $tail = $tail . $nx;
        if ($quoteIds != "" && strpos($q, "," . $nx . ",") !== false) {
            $closed = 1;
            break;
        }
    }
    $rest = $closed == 1 ? api_constrained_continue(["}"], 8) : api_constrained_continue(["\"}"], 8);
    $out = $tail;
    if ($rest != "") {
        $out = $out == "" ? $rest : $out . "," . $rest;
    }
    if ($out == "") {
        return "";
    }
    return $s . "," . $out;
}

// One level of nesting: force `{"outer":{"inner":"`, fill the leaf with charset tokens, then
// force the quote (if the model did not emit it) and both closing braces.
function api_string_value_nested(string $ids, string $outer, string $inner, string $charset, int $maxSteps, int $useCache = 1): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    if ($charsetIds == "" || $quoteIds == "") {
        return "";
    }
    $s = api_constrained($ids, ["{\"" . $outer . "\":{\"" . $inner . "\":\""], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $stepIds = $charsetIds . "," . $quoteIds;
    $q = "," . $quoteIds . ",";
    $closed = 0;
    for ($d = 0; $d < $maxSteps; $d++) {
        $nx = model_session_next_allowed(0, $stepIds);
        if ($nx < 0) {
            break;
        }
        $s = $s . "," . $nx;
        if (strpos($q, "," . $nx . ",") !== false) {
            $closed = 1;
            break;
        }
    }
    if ($closed == 0) {
        $cq = api_constrained_continue(["\""], 4);
        if ($cq != "") {
            $s = $s . "," . $cq;
        }
    }
    $end = api_constrained_continue(["}}"], 8);
    if ($end != "") {
        $s = $s . "," . $end;
    }
    return $s;
}

// Same idea for an object whose values are all charset-filled strings: force each key and opening
// quote, emit charset tokens until the closing quote, then force the separator for the next key
// (or the final brace). Every forced piece is a literal, so the object stays well-formed even if
// a value hits the step budget and must be closed by the grammar.
function api_string_value_multi(string $ids, array $props, string $charset, int $maxSteps, int $useCache = 1): string
{
    if (count($props) == 0) {
        return "";
    }
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    if ($charsetIds == "" || $quoteIds == "") {
        return "";
    }
    $s = api_constrained($ids, ["{\"" . $props[0] . "\":\""], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $stepIds = $charsetIds . "," . $quoteIds;
    $bsIds = api_json_escape_safe_ids(gguf_charset_ids("\\\""));
    if ($bsIds != "") {
        $stepIds = $stepIds . "," . $bsIds;
    }
    $q = "," . $quoteIds . ",";
    for ($pi = 0; $pi < count($props); $pi++) {
        if ($pi > 0) {
            $sep = api_constrained_continue([",\"" . $props[$pi] . "\":\""], 32);
            if ($sep == "") {
                return "";
            }
            $s = $s . "," . $sep;
        }
        $closed = 0;
        for ($d = 0; $d < $maxSteps; $d++) {
            $nx = model_session_next_allowed(0, $stepIds);
            if ($nx < 0) {
                break;
            }
            $s = $s . "," . $nx;
            if (strpos($q, "," . $nx . ",") !== false) {
                $closed = 1;
                break;
            }
        }
        if ($closed == 0) {
            $cq = api_constrained_continue(["\""], 4);
            if ($cq != "") {
                $s = $s . "," . $cq;
            }
        }
    }
    $end = api_constrained_continue(["}"], 4);
    if ($end != "") {
        $s = $s . "," . $end;
    }
    return $s;
}

// One-level nested string: {"outer":{"inner":"<charset>"}}. Checked BEFORE the flat-string
// detector, otherwise the inner property would be mistaken for a top-level one. Returns
// "<outer>|<inner>" or "".
function api_response_schema_nested_string(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern)"\s*:/', $schema) == 1) {
        return "";
    }
    $pm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{[^{}]*"properties"\s*:\s*\{\s*'
        . '"([A-Za-z0-9_\-]+)"\s*:\s*\{[^{}]*"type"\s*:\s*"string"/s', $schema, $pm) != 1) {
        return "";
    }
    return $pm[1] . "|" . $pm[2];
}

// One-level nested number: {"outer":{"inner":123}}. Same placement rule as the nested string
// detector (before the flat detectors, which would otherwise claim the inner property for the
// top level). Returns "<outer>|<inner>|<allowFrac>" or "".
function api_response_schema_nested_number(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern|minimum|maximum|multipleOf)"\s*:/', $schema) == 1) {
        return "";
    }
    $pm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{[^{}]*"properties"\s*:\s*\{\s*'
        . '"([A-Za-z0-9_\-]+)"\s*:\s*\{[^{}]*"type"\s*:\s*"(integer|number)"/s',
        $schema, $pm) != 1) {
        return "";
    }
    return $pm[1] . "|" . $pm[2] . "|" . ($pm[3] == "number" ? "1" : "0");
}

// An array of charset-filled strings, e.g. {"cities":["paris","lyon"]}. The model decides when to
// stop: after each element only "," (continue) and "]" (end) are reachable, and the closing brace
// is forced. Runs BEFORE the flat detectors, which would otherwise mistake "items" for a key.
function api_array_string_value(string $ids, string $prop, string $charset, int $maxItems, int $maxSteps, int $useCache = 1): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($charsetIds == "" || $quoteIds == "" || $commaIds == "" || $bracketIds == "") {
        return "";
    }
    $s = api_constrained($ids, ["{\"" . $prop . "\":[\""], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $stepIds = $charsetIds . "," . $quoteIds;
    $q = "," . $quoteIds . ",";
    $endIds = $commaIds . "," . $bracketIds;
    $qComma = "," . $commaIds . ",";
    $done = 0;
    for ($it = 0; $it < $maxItems; $it++) {
        if ($it > 0) {
            $open = api_constrained_continue(["\""], 4);
            if ($open == "") {
                return "";
            }
            $s = $s . "," . $open;
        }
        $closed = 0;
        for ($d = 0; $d < $maxSteps; $d++) {
            $nx = model_session_next_allowed(0, $stepIds);
            if ($nx < 0) {
                break;
            }
            $s = $s . "," . $nx;
            if (strpos($q, "," . $nx . ",") !== false) {
                $closed = 1;
                break;
            }
        }
        if ($closed == 0) {
            $cq = api_constrained_continue(["\""], 4);
            if ($cq != "") {
                $s = $s . "," . $cq;
            }
        }
        $nx2 = model_session_next_allowed(0, $it < $maxItems - 1 ? $endIds : $bracketIds);
        if ($nx2 < 0) {
            break;
        }
        $s = $s . "," . $nx2;
        if (strpos($qComma, "," . $nx2 . ",") === false) {
            $done = 1;   // "]"
            break;
        }
    }
    if ($done == 0) {
        $rb = api_constrained_continue(["]"], 4);
        if ($rb != "") {
            $s = $s . "," . $rb;
        }
    }
    $end = api_constrained_continue(["}"], 4);
    if ($end != "") {
        $s = $s . "," . $end;
    }
    return $s;
}

// Detect {"prop":{"type":"array","items":{"type":"string"}}}. Returns the property name or "".
function api_response_schema_array_string(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern)"\s*:/', $schema) == 1) {
        return "";
    }
    $pm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*'
        . '"items"\s*:\s*\{\s*"type"\s*:\s*"string"\s*\}\s*\}/s', $schema, $pm) != 1) {
        return "";
    }
    return $pm[1];
}

// An array of numbers, e.g. {"nums":[123,456]}. Each element is filled with api_number_fill for a
// fixed token budget, then only "," (continue) or "]" (end) is reachable. Runs before the flat
// number detector, which would otherwise mistake "items" for a key.
function api_array_number_value(string $ids, string $prop, int $allowFrac, int $maxItems, int $elemSteps, int $useCache = 1): string
{
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($commaIds == "" || $bracketIds == "") {
        return "";
    }
    $s = api_constrained($ids, ["{\"" . $prop . "\":["], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $endIds = $commaIds . "," . $bracketIds;
    $qComma = "," . $commaIds . ",";
    $done = 0;
    for ($it = 0; $it < $maxItems; $it++) {
        $body = api_number_fill($allowFrac, $elemSteps);
        if ($body == "") {
            break;
        }
        $s = $s . "," . $body;
        $nx = model_session_next_allowed(0, $it < $maxItems - 1 ? $endIds : $bracketIds);
        if ($nx < 0) {
            break;
        }
        $s = $s . "," . $nx;
        if (strpos($qComma, "," . $nx . ",") === false) {
            $done = 1;   // "]"
            break;
        }
    }
    if ($done == 0) {
        $rb = api_constrained_continue(["]"], 4);
        if ($rb != "") {
            $s = $s . "," . $rb;
        }
    }
    $end = api_constrained_continue(["}"], 4);
    if ($end != "") {
        $s = $s . "," . $end;
    }
    return $s;
}

// Detect {"prop":{"type":"array","items":{"type":"integer|number"}}}. Returns
// "<prop>|<allowFrac>" or "".
function api_response_schema_array_number(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern|minimum|maximum|multipleOf)"\s*:/', $schema) == 1) {
        return "";
    }
    $pm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*'
        . '"items"\s*:\s*\{\s*"type"\s*:\s*"(integer|number)"\s*\}\s*\}/s', $schema, $pm) != 1) {
        return "";
    }
    return $pm[1] . "|" . ($pm[2] == "number" ? "1" : "0");
}

// A top-level number array: [123,456]. Elements use api_number_fill for a fixed budget, then
// ","/"\]" decides whether the list continues or ends.
function api_top_array_number_value(string $ids, int $allowFrac, int $maxItems, int $elemSteps, int $useCache = 1): string
{
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($commaIds == "" || $bracketIds == "") {
        return "";
    }
    $s = api_constrained($ids, ["["], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $endIds = $commaIds . "," . $bracketIds;
    $qComma = "," . $commaIds . ",";
    $bracketSeen = 0;
    for ($i = 0; $i < $maxItems && $bracketSeen == 0; $i++) {
        $body = api_number_fill($allowFrac, $elemSteps);
        if ($body == "") {
            break;
        }
        $s = $s . "," . $body;
        $nx = model_session_next_allowed(0, $i < $maxItems - 1 ? $endIds : $bracketIds);
        if ($nx < 0) {
            break;
        }
        $s = $s . "," . $nx;
        if (strpos($qComma, "," . $nx . ",") === false) {
            $bracketSeen = 1;
        }
    }
    if ($bracketSeen == 0) {
        $rb = api_constrained_continue(["]"], 4);
        if ($rb != "") {
            $s = $s . "," . $rb;
        }
    }
    return $s;
}

// A top-level array whose elements each hold ONE nested object: [{"meta":{"name":" a","age":1}}].
// Element prefix is {"outer": + the inner plan head; each element closes with "}}" (inner object
// plus element), then "," or "]" decides whether another element follows.
function api_top_array_nested_object_value(string $ids, string $outer, string $plan, string $charset, int $maxItems, int $maxSteps, int $useCache = 1): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($charsetIds == "" || $quoteIds == "" || $commaIds == "" || $bracketIds == "") {
        return "";
    }
    $items = explode(",", $plan);
    if (count($items) == 0) {
        return "";
    }
    $head = "{\"" . $outer . "\":" . api_object_head($plan);
    $s = api_constrained($ids, ["[" . $head], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $stepIds = $charsetIds . "," . $quoteIds;
    $q = "," . $quoteIds . ",";
    $endIds = $commaIds . "," . $bracketIds;
    $qComma = "," . $commaIds . ",";
    $bracketSeen = 0;
    for ($it = 0; $it < $maxItems && $bracketSeen == 0; $it++) {
        if ($it > 0) {
            $hd = api_constrained_continue([$head], 32);
            if ($hd == "") {
                return "";
            }
            $s = $s . "," . $hd;
        }
        for ($k = 0; $k < count($items); $k++) {
            $kv = explode(":", $items[$k]);
            if (count($kv) != 2) {
                return "";
            }
            $ty = $kv[1];
            if ($k > 0) {
                $sep = ",\"" . $kv[0] . "\":";
                if ($ty == "s") {
                    $sep = $sep . "\"";
                }
                $sp = api_constrained_continue([$sep], 32);
                if ($sp == "") {
                    return "";
                }
                $s = $s . "," . $sp;
            }
            if ($ty == "s") {
                $closed = 0;
                for ($d = 0; $d < $maxSteps; $d++) {
                    $nx = model_session_next_allowed(0, $stepIds);
                    if ($nx < 0) {
                        break;
                    }
                    $s = $s . "," . $nx;
                    if (strpos($q, "," . $nx . ",") !== false) {
                        $closed = 1;
                        break;
                    }
                }
                if ($closed == 0) {
                    $cq = api_constrained_continue(["\""], 4);
                    if ($cq != "") {
                        $s = $s . "," . $cq;
                    }
                }
            } else {
                $body = api_number_fill($ty == "n" ? 1 : 0, 3);
                if ($body != "") {
                    $s = $s . "," . $body;
                }
            }
        }
        $cb = api_constrained_continue(["}}"], 8);
        if ($cb != "") {
            $s = $s . "," . $cb;
        }
        $nx2 = model_session_next_allowed(0, $it < $maxItems - 1 ? $endIds : $bracketIds);
        if ($nx2 < 0) {
            break;
        }
        $s = $s . "," . $nx2;
        if (strpos($qComma, "," . $nx2 . ",") === false) {
            $bracketSeen = 1;
        }
    }
    if ($bracketSeen == 0) {
        $rb = api_constrained_continue(["]"], 4);
        if ($rb != "") {
            $s = $s . "," . $rb;
        }
    }
    return $s;
}

// Detect a top-level array of objects where each element is {"outer":{"flat leaves"}}. Returns
// "<outer>|<plan>" or "" (flat elements belong to api_response_schema_top_array_object).
function api_response_schema_top_array_nested_object(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern|minimum|maximum|multipleOf)"\s*:/', $schema) == 1) {
        return "";
    }
    if (preg_match('/^\s*\{\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{\s*"type"\s*:\s*"object"/s',
        $schema) != 1) {
        return "";
    }
    $pp = strpos($schema, "\"properties\"");
    if ($pp === false) {
        return "";
    }
    $bo = strpos($schema, "{", $pp);
    if ($bo === false) {
        return "";
    }
    $block1 = api_balance_block($schema, $bo);
    if ($block1 == "") {
        return "";
    }
    if (api_top_leaves($block1, 4) != "") {
        return "";   // flat elements: handled by the plain object-array path
    }
    $pm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{(?=[^{}]*"properties"\s*:\s*\{)/s',
        $block1, $pm) != 1) {
        return "";
    }
    $ip = strpos($block1, $pm[0]);
    if ($ip === false) {
        return "";
    }
    $pp2 = strpos($block1, "\"properties\"", $ip);
    if ($pp2 === false) {
        return "";
    }
    $bo2 = strpos($block1, "{", $pp2);
    if ($bo2 === false) {
        return "";
    }
    $block2 = api_balance_block($block1, $bo2);
    if ($block2 == "") {
        return "";
    }
    $plan = api_top_leaves($block2, 4);
    if ($plan == "") {
        return "";
    }
    return $pm[1] . "|" . $plan;
}

// A top-level array of flat objects: [{"name":" a","age":123}, …]. Each element is closed by a
// forced "}", then "," (another element) or "]" (end); no trailing brace.
function api_top_array_object_value(string $ids, string $plan, string $charset, int $maxItems, int $maxSteps, int $useCache = 1): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($charsetIds == "" || $quoteIds == "" || $commaIds == "" || $bracketIds == "") {
        return "";
    }
    $items = explode(",", $plan);
    if (count($items) == 0) {
        return "";
    }
    $head = api_object_head($plan);
    $s = api_constrained($ids, ["[" . $head], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $stepIds = $charsetIds . "," . $quoteIds;
    $q = "," . $quoteIds . ",";
    $endIds = $commaIds . "," . $bracketIds;
    $qComma = "," . $commaIds . ",";
    $bracketSeen = 0;
    for ($it = 0; $it < $maxItems && $bracketSeen == 0; $it++) {
        if ($it > 0) {
            $hd = api_constrained_continue([$head], 32);
            if ($hd == "") {
                return "";
            }
            $s = $s . "," . $hd;
        }
        for ($k = 0; $k < count($items); $k++) {
            $kv = explode(":", $items[$k]);
            if (count($kv) != 2) {
                return "";
            }
            $ty = $kv[1];
            if ($k > 0) {
                $sep = ",\"" . $kv[0] . "\":";
                if ($ty == "s") {
                    $sep = $sep . "\"";
                }
                $sp = api_constrained_continue([$sep], 32);
                if ($sp == "") {
                    return "";
                }
                $s = $s . "," . $sp;
            }
            if ($ty == "s") {
                $closed = 0;
                for ($d = 0; $d < $maxSteps; $d++) {
                    $nx = model_session_next_allowed(0, $stepIds);
                    if ($nx < 0) {
                        break;
                    }
                    $s = $s . "," . $nx;
                    if (strpos($q, "," . $nx . ",") !== false) {
                        $closed = 1;
                        break;
                    }
                }
                if ($closed == 0) {
                    $cq = api_constrained_continue(["\""], 4);
                    if ($cq != "") {
                        $s = $s . "," . $cq;
                    }
                }
            } else {
                $body = api_number_fill($ty == "n" ? 1 : 0, 3);
                if ($body != "") {
                    $s = $s . "," . $body;
                }
            }
        }
        $cb = api_constrained_continue(["}"], 4);
        if ($cb != "") {
            $s = $s . "," . $cb;
        }
        $nx2 = model_session_next_allowed(0, $it < $maxItems - 1 ? $endIds : $bracketIds);
        if ($nx2 < 0) {
            break;
        }
        $s = $s . "," . $nx2;
        if (strpos($qComma, "," . $nx2 . ",") === false) {
            $bracketSeen = 1;
        }
    }
    if ($bracketSeen == 0) {
        $rb = api_constrained_continue(["]"], 4);
        if ($rb != "") {
            $s = $s . "," . $rb;
        }
    }
    return $s;
}

// Plan for a top-level array of objects, or "" when the schema is not that shape.
function api_response_schema_top_array_object(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern|minimum|maximum|multipleOf)"\s*:/', $schema) == 1) {
        return "";
    }
    if (preg_match('/^\s*\{\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{\s*"type"\s*:\s*"object"/s',
        $schema) != 1) {
        return "";
    }
    $pp = strpos($schema, "\"properties\"");
    if ($pp === false) {
        return "";
    }
    $bo = strpos($schema, "{", $pp);
    if ($bo === false) {
        return "";
    }
    $block = api_balance_block($schema, $bo);
    if ($block == "") {
        return "";
    }
    return api_top_leaves($block, 4);
}

// All boolean arrays of length 1..3 as literal candidates (finite domain, so plain constrained
// decoding is enough - no grammar needed).
function api_top_bool_candidates(): array
{
    return [
        "[true]", "[false]",
        "[true,true]", "[true,false]", "[false,true]", "[false,false]",
        "[true,true,true]", "[true,true,false]", "[true,false,true]", "[true,false,false]",
        "[false,true,true]", "[false,true,false]", "[false,false,true]", "[false,false,false]",
    ];
}

// 1 when the schema root is an object literal (type object, or starts with properties). A
// top-level array schema has no top-level properties; property-based detectors must skip it.
function api_schema_is_object(string $schema): int
{
    if (preg_match('/^\s*\{\s*"type"\s*:\s*"object"/', $schema) == 1) {
        return 1;
    }
    if (preg_match('/^\s*\{\s*"properties"/', $schema) == 1) {
        return 1;
    }
    return 0;
}

// 1 when the schema is a top-level array of booleans.
function api_response_schema_top_array_bool(string $body): int
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return 0;
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return 0;
    }
    if (preg_match('/"(enum|const|pattern)"\s*:/', $schema) == 1) {
        return 0;
    }
    if (preg_match('/^\s*\{\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{\s*"type"\s*:\s*"boolean"'
        . '\s*\}\s*\}/s', $schema) != 1) {
        return 0;
    }
    return 1;
}

// -1 when the schema is not a top-level number array; otherwise 0 (integer) / 1 (number).
function api_response_schema_top_array_number(string $body): int
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return -1;
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return -1;
    }
    $pm = [];
    if (preg_match('/^\s*\{\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{\s*"type"\s*:\s*'
        . '"(integer|number)"\s*\}\s*\}/s', $schema, $pm) != 1) {
        return -1;
    }
    if (preg_match('/"(enum|const|pattern|minimum|maximum|multipleOf)"\s*:/', $schema) == 1) {
        return -1;
    }
    return $pm[1] == "number" ? 1 : 0;
}

// A top-level string array schema: ["a","b"]. No object wrapper, so the closing brace is not
// forced; elements use the charset grammar and ","/"\]" decide when the list ends.
function api_top_array_string_value(string $ids, string $charset, int $maxItems, int $maxSteps, int $useCache = 1): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($charsetIds == "" || $quoteIds == "" || $commaIds == "" || $bracketIds == "") {
        return "";
    }
    $s = api_constrained($ids, ["[\""], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $stepIds = $charsetIds . "," . $quoteIds;
    $q = "," . $quoteIds . ",";
    $endIds = $commaIds . "," . $bracketIds;
    $qComma = "," . $commaIds . ",";
    $bracketSeen = 0;
    for ($i = 0; $i < $maxItems && $bracketSeen == 0; $i++) {
        if ($i > 0) {
            $op = api_constrained_continue(["\""], 4);
            if ($op == "") {
                return "";
            }
            $s = $s . "," . $op;
        }
        $closed = 0;
        for ($d = 0; $d < $maxSteps; $d++) {
            $nx = model_session_next_allowed(0, $stepIds);
            if ($nx < 0) {
                break;
            }
            $s = $s . "," . $nx;
            if (strpos($q, "," . $nx . ",") !== false) {
                $closed = 1;
                break;
            }
        }
        if ($closed == 0) {
            $cq = api_constrained_continue(["\""], 4);
            if ($cq != "") {
                $s = $s . "," . $cq;
            }
        }
        $nx2 = model_session_next_allowed(0, $i < $maxItems - 1 ? $endIds : $bracketIds);
        if ($nx2 < 0) {
            break;
        }
        $s = $s . "," . $nx2;
        if (strpos($qComma, "," . $nx2 . ",") === false) {
            $bracketSeen = 1;
        }
    }
    if ($bracketSeen == 0) {
        $rb = api_constrained_continue(["]"], 4);
        if ($rb != "") {
            $s = $s . "," . $rb;
        }
    }
    return $s;
}

// 1 when the schema itself is {"type":"array","items":{"type":"string"}} (top level, no object).
function api_response_schema_top_array_string(string $body): int
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return 0;
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return 0;
    }
    if (preg_match('/^\s*\{\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{\s*"type"\s*:\s*"string"'
        . '\s*\}\s*\}/s', $schema) != 1) {
        return 0;
    }
    return 1;
}

// A four-dimensional string array: {"m":[[[["red"]]]]}. Same rule one level deeper: each level
// closes when the model emits its "]" or when its element budget runs out, and the separator at a
// level is "," (another element of that level) or "]" (close it).
function api_array4_string_value(string $ids, string $prop, string $charset, int $maxOuter, int $maxL2, int $maxL3, int $maxInner, int $maxSteps, int $useCache = 1): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($charsetIds == "" || $quoteIds == "" || $commaIds == "" || $bracketIds == "") {
        return "";
    }
    $s = api_constrained($ids, ["{\"" . $prop . "\":[[[[\""], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $stepIds = $charsetIds . "," . $quoteIds;
    $q = "," . $quoteIds . ",";
    $endIds = $commaIds . "," . $bracketIds;
    $qComma = "," . $commaIds . ",";
    $outerSeen = 0;
    $outerBracket = 0;
    for ($o = 0; $o < $maxOuter && $outerSeen == 0; $o++) {
        if ($o > 0) {
            $op = api_constrained_continue(["[[[\""], 4);
            if ($op == "") {
                return "";
            }
            $s = $s . "," . $op;
        }
        $l2Seen = 0;
        $l2Bracket = 0;
        for ($p = 0; $p < $maxL2 && $l2Seen == 0; $p++) {
            if ($p > 0) {
                $p2 = api_constrained_continue(["[[\""], 4);
                if ($p2 == "") {
                    return "";
                }
                $s = $s . "," . $p2;
            }
            $l3Seen = 0;
            $l3Bracket = 0;
            for ($r = 0; $r < $maxL3 && $l3Seen == 0; $r++) {
                if ($r > 0) {
                    $r2 = api_constrained_continue(["[\""], 4);
                    if ($r2 == "") {
                        return "";
                    }
                    $s = $s . "," . $r2;
                }
                $innerSeen = 0;
                for ($in = 0; $in < $maxInner && $innerSeen == 0; $in++) {
                    if ($in > 0) {
                        $iq = api_constrained_continue(["\""], 4);
                        if ($iq == "") {
                            return "";
                        }
                        $s = $s . "," . $iq;
                    }
                    $closed = 0;
                    for ($d = 0; $d < $maxSteps; $d++) {
                        $nx = model_session_next_allowed(0, $stepIds);
                        if ($nx < 0) {
                            break;
                        }
                        $s = $s . "," . $nx;
                        if (strpos($q, "," . $nx . ",") !== false) {
                            $closed = 1;
                            break;
                        }
                    }
                    if ($closed == 0) {
                        $cq = api_constrained_continue(["\""], 4);
                        if ($cq != "") {
                            $s = $s . "," . $cq;
                        }
                    }
                    $nx2 = model_session_next_allowed(0, $in < $maxInner - 1 ? $endIds : $bracketIds);
                    if ($nx2 < 0) {
                        break;
                    }
                    $s = $s . "," . $nx2;
                    if (strpos($qComma, "," . $nx2 . ",") === false) {
                        $innerSeen = 1;
                    }
                }
                if ($innerSeen == 0) {
                    $ib = api_constrained_continue(["]"], 4);
                    if ($ib != "") {
                        $s = $s . "," . $ib;
                    }
                }
                if ($r < $maxL3 - 1) {
                    $nx3 = model_session_next_allowed(0, $endIds);
                    if ($nx3 < 0) {
                        $l3Seen = 1;
                    } else {
                        $s = $s . "," . $nx3;
                        if (strpos($qComma, "," . $nx3 . ",") === false) {
                            $l3Seen = 1;
                            $l3Bracket = 1;
                        }
                    }
                } else {
                    $l3Seen = 1;
                }
            }
            if ($l3Bracket == 0) {
                $lb = api_constrained_continue(["]"], 4);
                if ($lb != "") {
                    $s = $s . "," . $lb;
                }
            }
            if ($p < $maxL2 - 1) {
                $nx4 = model_session_next_allowed(0, $endIds);
                if ($nx4 < 0) {
                    $l2Seen = 1;
                } else {
                    $s = $s . "," . $nx4;
                    if (strpos($qComma, "," . $nx4 . ",") === false) {
                        $l2Seen = 1;
                        $l2Bracket = 1;
                    }
                }
            } else {
                $l2Seen = 1;
            }
        }
        if ($l2Bracket == 0) {
            $lb2 = api_constrained_continue(["]"], 4);
            if ($lb2 != "") {
                $s = $s . "," . $lb2;
            }
        }
        if ($o < $maxOuter - 1) {
            $nx5 = model_session_next_allowed(0, $endIds);
            if ($nx5 < 0) {
                $outerSeen = 1;
            } else {
                $s = $s . "," . $nx5;
                if (strpos($qComma, "," . $nx5 . ",") === false) {
                    $outerSeen = 1;
                    $outerBracket = 1;
                }
            }
        } else {
            $outerSeen = 1;
        }
    }
    if ($outerBracket == 0) {
        $ob = api_constrained_continue(["]"], 4);
        if ($ob != "") {
            $s = $s . "," . $ob;
        }
    }
    $end = api_constrained_continue(["}"], 4);
    if ($end != "") {
        $s = $s . "," . $end;
    }
    return $s;
}

// Detect four nested array levels ending in a string items type.
function api_response_schema_array4_string(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern)"\s*:/', $schema) == 1) {
        return "";
    }
    $pm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{'
        . '\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*'
        . '"items"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{\s*"type"\s*:\s*"string"'
        . '\s*\}\s*\}\s*\}\s*\}\s*\}/s', $schema, $pm) != 1) {
        return "";
    }
    return $pm[1];
}

// A five-dimensional string array: {"m":[[[[["red"]]]]]}. Same budget/close discipline as the
// four-level builder with one extra list level around it.
function api_array5_string_value(string $ids, string $prop, string $charset, int $maxOuter, int $maxL2, int $maxL3, int $maxL4, int $maxInner, int $maxSteps, int $useCache = 1): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($charsetIds == "" || $quoteIds == "" || $commaIds == "" || $bracketIds == "") {
        return "";
    }
    $s = api_constrained($ids, ["{\"" . $prop . "\":[[[[[\""], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $stepIds = $charsetIds . "," . $quoteIds;
    $q = "," . $quoteIds . ",";
    $endIds = $commaIds . "," . $bracketIds;
    $qComma = "," . $commaIds . ",";
    $outerSeen = 0;
    $outerBracket = 0;
    for ($o = 0; $o < $maxOuter && $outerSeen == 0; $o++) {
        if ($o > 0) {
            $op = api_constrained_continue(["[[[[\""], 4);
            if ($op == "") {
                return "";
            }
            $s = $s . "," . $op;
        }
        $l2Seen = 0;
        $l2Bracket = 0;
        for ($p = 0; $p < $maxL2 && $l2Seen == 0; $p++) {
            if ($p > 0) {
                $p2 = api_constrained_continue(["[[[\""], 4);
                if ($p2 == "") {
                    return "";
                }
                $s = $s . "," . $p2;
            }
            $l3Seen = 0;
            $l3Bracket = 0;
            for ($r = 0; $r < $maxL3 && $l3Seen == 0; $r++) {
                if ($r > 0) {
                    $r2 = api_constrained_continue(["[[\""], 4);
                    if ($r2 == "") {
                        return "";
                    }
                    $s = $s . "," . $r2;
                }
                $l4Seen = 0;
                $l4Bracket = 0;
                for ($t = 0; $t < $maxL4 && $l4Seen == 0; $t++) {
                    if ($t > 0) {
                        $t2 = api_constrained_continue(["[\""], 4);
                        if ($t2 == "") {
                            return "";
                        }
                        $s = $s . "," . $t2;
                    }
                    $innerSeen = 0;
                    for ($in = 0; $in < $maxInner && $innerSeen == 0; $in++) {
                        if ($in > 0) {
                            $iq = api_constrained_continue(["\""], 4);
                            if ($iq == "") {
                                return "";
                            }
                            $s = $s . "," . $iq;
                        }
                        $closed = 0;
                        for ($d = 0; $d < $maxSteps; $d++) {
                            $nx = model_session_next_allowed(0, $stepIds);
                            if ($nx < 0) {
                                break;
                            }
                            $s = $s . "," . $nx;
                            if (strpos($q, "," . $nx . ",") !== false) {
                                $closed = 1;
                                break;
                            }
                        }
                        if ($closed == 0) {
                            $cq = api_constrained_continue(["\""], 4);
                            if ($cq != "") {
                                $s = $s . "," . $cq;
                            }
                        }
                        $nx2 = model_session_next_allowed(0, $in < $maxInner - 1 ? $endIds : $bracketIds);
                        if ($nx2 < 0) {
                            break;
                        }
                        $s = $s . "," . $nx2;
                        if (strpos($qComma, "," . $nx2 . ",") === false) {
                            $innerSeen = 1;
                        }
                    }
                    if ($innerSeen == 0) {
                        $ib = api_constrained_continue(["]"], 4);
                        if ($ib != "") {
                            $s = $s . "," . $ib;
                        }
                    }
                    if ($t < $maxL4 - 1) {
                        $nx3 = model_session_next_allowed(0, $endIds);
                        if ($nx3 < 0) {
                            $l4Seen = 1;
                        } else {
                            $s = $s . "," . $nx3;
                            if (strpos($qComma, "," . $nx3 . ",") === false) {
                                $l4Seen = 1;
                                $l4Bracket = 1;
                            }
                        }
                    } else {
                        $l4Seen = 1;
                    }
                }
                if ($l4Bracket == 0) {
                    $lb4 = api_constrained_continue(["]"], 4);
                    if ($lb4 != "") {
                        $s = $s . "," . $lb4;
                    }
                }
                if ($r < $maxL3 - 1) {
                    $nx4 = model_session_next_allowed(0, $endIds);
                    if ($nx4 < 0) {
                        $l3Seen = 1;
                    } else {
                        $s = $s . "," . $nx4;
                        if (strpos($qComma, "," . $nx4 . ",") === false) {
                            $l3Seen = 1;
                            $l3Bracket = 1;
                        }
                    }
                } else {
                    $l3Seen = 1;
                }
            }
            if ($l3Bracket == 0) {
                $lb3 = api_constrained_continue(["]"], 4);
                if ($lb3 != "") {
                    $s = $s . "," . $lb3;
                }
            }
            if ($p < $maxL2 - 1) {
                $nx5 = model_session_next_allowed(0, $endIds);
                if ($nx5 < 0) {
                    $l2Seen = 1;
                } else {
                    $s = $s . "," . $nx5;
                    if (strpos($qComma, "," . $nx5 . ",") === false) {
                        $l2Seen = 1;
                        $l2Bracket = 1;
                    }
                }
            } else {
                $l2Seen = 1;
            }
        }
        if ($l2Bracket == 0) {
            $lb2 = api_constrained_continue(["]"], 4);
            if ($lb2 != "") {
                $s = $s . "," . $lb2;
            }
        }
        if ($o < $maxOuter - 1) {
            $nx6 = model_session_next_allowed(0, $endIds);
            if ($nx6 < 0) {
                $outerSeen = 1;
            } else {
                $s = $s . "," . $nx6;
                if (strpos($qComma, "," . $nx6 . ",") === false) {
                    $outerSeen = 1;
                    $outerBracket = 1;
                }
            }
        } else {
            $outerSeen = 1;
        }
    }
    if ($outerBracket == 0) {
        $ob = api_constrained_continue(["]"], 4);
        if ($ob != "") {
            $s = $s . "," . $ob;
        }
    }
    $end = api_constrained_continue(["}"], 4);
    if ($end != "") {
        $s = $s . "," . $end;
    }
    return $s;
}

// Detect five nested array levels ending in a string items type.
function api_response_schema_array5_string(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern)"\s*:/', $schema) == 1) {
        return "";
    }
    $pm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{'
        . '\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*'
        . '"items"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*'
        . '"items"\s*:\s*\{\s*"type"\s*:\s*"string"'
        . '\s*\}\s*\}\s*\}\s*\}\s*\}\s*\}/s', $schema, $pm) != 1) {
        return "";
    }
    return $pm[1];
}

// A six-dimensional string array: {"m":[[[[[["red"]]]]]]}. Same budget/close discipline as the
// five-level builder with one extra list level around it.
function api_array6_string_value(string $ids, string $prop, string $charset, int $maxOuter, int $maxL2, int $maxL3, int $maxL4, int $maxL5, int $maxInner, int $maxSteps, int $useCache = 1): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($charsetIds == "" || $quoteIds == "" || $commaIds == "" || $bracketIds == "") {
        return "";
    }
    $s = api_constrained($ids, ["{\"" . $prop . "\":[[[[[[\""], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $stepIds = $charsetIds . "," . $quoteIds;
    $q = "," . $quoteIds . ",";
    $endIds = $commaIds . "," . $bracketIds;
    $qComma = "," . $commaIds . ",";
    $outerSeen = 0;
    $outerBracket = 0;
    for ($o = 0; $o < $maxOuter && $outerSeen == 0; $o++) {
        if ($o > 0) {
            $op = api_constrained_continue(["[[[[[\""], 4);
            if ($op == "") {
                return "";
            }
            $s = $s . "," . $op;
        }
        $l2Seen = 0;
        $l2Bracket = 0;
        for ($p = 0; $p < $maxL2 && $l2Seen == 0; $p++) {
            if ($p > 0) {
                $p2 = api_constrained_continue(["[[[[\""], 4);
                if ($p2 == "") {
                    return "";
                }
                $s = $s . "," . $p2;
            }
            $l3Seen = 0;
            $l3Bracket = 0;
            for ($r = 0; $r < $maxL3 && $l3Seen == 0; $r++) {
                if ($r > 0) {
                    $r2 = api_constrained_continue(["[[[\""], 4);
                    if ($r2 == "") {
                        return "";
                    }
                    $s = $s . "," . $r2;
                }
                $l4Seen = 0;
                $l4Bracket = 0;
                for ($t = 0; $t < $maxL4 && $l4Seen == 0; $t++) {
                    if ($t > 0) {
                        $t2 = api_constrained_continue(["[[\""], 4);
                        if ($t2 == "") {
                            return "";
                        }
                        $s = $s . "," . $t2;
                    }
                    $l5Seen = 0;
                    $l5Bracket = 0;
                    for ($u = 0; $u < $maxL5 && $l5Seen == 0; $u++) {
                        if ($u > 0) {
                            $u2 = api_constrained_continue(["[\""], 4);
                            if ($u2 == "") {
                                return "";
                            }
                            $s = $s . "," . $u2;
                        }
                        $innerSeen = 0;
                        for ($in = 0; $in < $maxInner && $innerSeen == 0; $in++) {
                            if ($in > 0) {
                                $iq = api_constrained_continue(["\""], 4);
                                if ($iq == "") {
                                    return "";
                                }
                                $s = $s . "," . $iq;
                            }
                            $closed = 0;
                            for ($d = 0; $d < $maxSteps; $d++) {
                                $nx = model_session_next_allowed(0, $stepIds);
                                if ($nx < 0) {
                                    break;
                                }
                                $s = $s . "," . $nx;
                                if (strpos($q, "," . $nx . ",") !== false) {
                                    $closed = 1;
                                    break;
                                }
                            }
                            if ($closed == 0) {
                                $cq = api_constrained_continue(["\""], 4);
                                if ($cq != "") {
                                    $s = $s . "," . $cq;
                                }
                            }
                            $nx2 = model_session_next_allowed(0, $in < $maxInner - 1 ? $endIds : $bracketIds);
                            if ($nx2 < 0) {
                                break;
                            }
                            $s = $s . "," . $nx2;
                            if (strpos($qComma, "," . $nx2 . ",") === false) {
                                $innerSeen = 1;
                            }
                        }
                        if ($innerSeen == 0) {
                            $ib = api_constrained_continue(["]"], 4);
                            if ($ib != "") {
                                $s = $s . "," . $ib;
                            }
                        }
                        if ($u < $maxL5 - 1) {
                            $nx3 = model_session_next_allowed(0, $endIds);
                            if ($nx3 < 0) {
                                $l5Seen = 1;
                            } else {
                                $s = $s . "," . $nx3;
                                if (strpos($qComma, "," . $nx3 . ",") === false) {
                                    $l5Seen = 1;
                                    $l5Bracket = 1;
                                }
                            }
                        } else {
                            $l5Seen = 1;
                        }
                    }
                    if ($l5Bracket == 0) {
                        $lb5 = api_constrained_continue(["]"], 4);
                        if ($lb5 != "") {
                            $s = $s . "," . $lb5;
                        }
                    }
                    if ($t < $maxL4 - 1) {
                        $nx4 = model_session_next_allowed(0, $endIds);
                        if ($nx4 < 0) {
                            $l4Seen = 1;
                        } else {
                            $s = $s . "," . $nx4;
                            if (strpos($qComma, "," . $nx4 . ",") === false) {
                                $l4Seen = 1;
                                $l4Bracket = 1;
                            }
                        }
                    } else {
                        $l4Seen = 1;
                    }
                }
                if ($l4Bracket == 0) {
                    $lb4 = api_constrained_continue(["]"], 4);
                    if ($lb4 != "") {
                        $s = $s . "," . $lb4;
                    }
                }
                if ($r < $maxL3 - 1) {
                    $nx5 = model_session_next_allowed(0, $endIds);
                    if ($nx5 < 0) {
                        $l3Seen = 1;
                    } else {
                        $s = $s . "," . $nx5;
                        if (strpos($qComma, "," . $nx5 . ",") === false) {
                            $l3Seen = 1;
                            $l3Bracket = 1;
                        }
                    }
                } else {
                    $l3Seen = 1;
                }
            }
            if ($l3Bracket == 0) {
                $lb3 = api_constrained_continue(["]"], 4);
                if ($lb3 != "") {
                    $s = $s . "," . $lb3;
                }
            }
            if ($p < $maxL2 - 1) {
                $nx6 = model_session_next_allowed(0, $endIds);
                if ($nx6 < 0) {
                    $l2Seen = 1;
                } else {
                    $s = $s . "," . $nx6;
                    if (strpos($qComma, "," . $nx6 . ",") === false) {
                        $l2Seen = 1;
                        $l2Bracket = 1;
                    }
                }
            } else {
                $l2Seen = 1;
            }
        }
        if ($l2Bracket == 0) {
            $lb2 = api_constrained_continue(["]"], 4);
            if ($lb2 != "") {
                $s = $s . "," . $lb2;
            }
        }
        if ($o < $maxOuter - 1) {
            $nx7 = model_session_next_allowed(0, $endIds);
            if ($nx7 < 0) {
                $outerSeen = 1;
            } else {
                $s = $s . "," . $nx7;
                if (strpos($qComma, "," . $nx7 . ",") === false) {
                    $outerSeen = 1;
                    $outerBracket = 1;
                }
            }
        } else {
            $outerSeen = 1;
        }
    }
    if ($outerBracket == 0) {
        $ob = api_constrained_continue(["]"], 4);
        if ($ob != "") {
            $s = $s . "," . $ob;
        }
    }
    $end = api_constrained_continue(["}"], 4);
    if ($end != "") {
        $s = $s . "," . $end;
    }
    return $s;
}

// Generic N-dimensional string array for ranks not covered by the fixed builders (>= 7):
// {"m":[[[…["word"]…]]]}. Each level allows up to maxPerLevel entries ("," opens the sublist
// chain back down to the innermost level), then "]" closes one level; the tail closes the rest.
function api_array_n_string_value(string $ids, string $prop, int $dims, string $charset, int $maxPerLevel, int $maxSteps, int $useCache = 1): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($charsetIds == "" || $quoteIds == "" || $commaIds == "" || $bracketIds == "" || $dims < 2) {
        return "";
    }
    $open = "";
    for ($i = 0; $i < $dims; $i++) {
        $open = $open . "[";
    }
    $pre = "{\"" . $prop . "\":" . $open . "\"";
    $s = api_constrained($ids, [$pre], $dims * 8 + 64, $useCache);
    if ($s == "") {
        return "";
    }
    $stepIds = $charsetIds . "," . $quoteIds;
    $q = "," . $quoteIds . ",";
    $cnt = [];
    for ($i = 0; $i < $dims; $i++) {
        $cnt[$i] = 1;
    }
    $closed = 0;
    for ($d = 0; $d < $maxSteps; $d++) {
        $nx = model_session_next_allowed(0, $stepIds);
        if ($nx < 0) {
            break;
        }
        $s = $s . "," . $nx;
        if (strpos($q, "," . $nx . ",") !== false) {
            $closed = 1;
            break;
        }
    }
    if ($closed == 0) {
        $cq = api_constrained_continue(["\""], 4);
        if ($cq == "") {
            return "";
        }
        $s = $s . "," . $cq;
    }
    $lv = $dims - 1;
    $guard = 0;
    while ($lv >= 0 && $guard < 64) {
        $guard++;
        $allow = $cnt[$lv] >= $maxPerLevel ? $bracketIds : $commaIds . "," . $bracketIds;
        $nx = model_session_next_allowed(0, $allow);
        if ($nx < 0) {
            break;
        }
        $s = $s . "," . $nx;
        if (strpos("," . $bracketIds . ",", "," . $nx . ",") !== false) {
            $lv = $lv - 1;
            continue;
        }
        $nest = "";
        for ($i = 0; $i < $dims - 1 - $lv; $i++) {
            $nest = $nest . "[";
        }
        $fc = api_constrained_continue([$nest . "\""], 48);
        if ($fc == "") {
            return "";
        }
        $s = $s . "," . $fc;
        $c2 = 0;
        for ($d2 = 0; $d2 < $maxSteps; $d2++) {
            $n2 = model_session_next_allowed(0, $stepIds);
            if ($n2 < 0) {
                break;
            }
            $s = $s . "," . $n2;
            if (strpos($q, "," . $n2 . ",") !== false) {
                $c2 = 1;
                break;
            }
        }
        if ($c2 == 0) {
            $cq2 = api_constrained_continue(["\""], 4);
            if ($cq2 == "") {
                return "";
            }
            $s = $s . "," . $cq2;
        }
        $cnt[$lv] = $cnt[$lv] + 1;
        for ($i = $lv + 1; $i < $dims; $i++) {
            $cnt[$i] = 1;
        }
        $lv = $dims - 1;
    }
    $rest = "";
    if ($lv >= 0) {
        for ($i = 0; $i <= $lv; $i++) {
            $rest = $rest . "]";
        }
    }
    $rest = $rest . "}";
    $cb = api_constrained_continue([$rest], 2 * $dims + 8);
    if ($cb == "") {
        return "";
    }
    return $s . "," . $cb;
}

// Rank of a pure nested string array (prop → array^n → string). Returns "<prop>|<n>" only for
// ranks >= 7; the fixed 2..6 builders own the smaller ranks and run earlier.
function api_response_schema_array_n_string(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern)"\s*:/', $schema) == 1) {
        return "";
    }
    $pm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{/', $schema, $pm) != 1) {
        return "";
    }
    $ip = strpos($schema, $pm[0]);
    if ($ip === false) {
        return "";
    }
    $win = substr($schema, $ip, 4000);
    $sm = [];
    if (preg_match('/^(.*?)"type"\s*:\s*"string"/s', $win, $sm) != 1) {
        return "";
    }
    $head = $sm[1];
    if (strpos($head, '"properties"') !== false) {
        return "";
    }
    $na = preg_match_all('/"type"\s*:\s*"array"/', $head);
    $nt = preg_match_all('/"type"\s*:/', $head);
    if ($na < 7 || $na != $nt) {
        return "";
    }
    return $pm[1] . "|" . $na;
}

// Generic N-dimensional integer array for ranks >= 7: {"m":[[…[7]…]]}. One integer per
// innermost list; each outer level allows up to maxPerLevel entries.
function api_array_n_int_value(string $ids, string $prop, int $dims, int $maxPerLevel, int $maxSteps, int $useCache = 1): string
{
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($commaIds == "" || $bracketIds == "" || $dims < 2) {
        return "";
    }
    $open = "";
    for ($i = 0; $i < $dims; $i++) {
        $open = $open . "[";
    }
    $pre = "{\"" . $prop . "\":" . $open;
    $s = api_constrained($ids, [$pre], $dims * 8 + 64, $useCache);
    if ($s == "") {
        return "";
    }
    $cnt = [];
    for ($i = 0; $i < $dims; $i++) {
        $cnt[$i] = 1;
    }
    $s = $s . "," . api_number_fill(0, $maxSteps);
    $lv = $dims - 1;
    $guard = 0;
    while ($lv >= 0 && $guard < 64) {
        $guard++;
        $allow = $cnt[$lv] >= $maxPerLevel ? $bracketIds : $commaIds . "," . $bracketIds;
        $nx = model_session_next_allowed(0, $allow);
        if ($nx < 0) {
            break;
        }
        $s = $s . "," . $nx;
        if (strpos("," . $bracketIds . ",", "," . $nx . ",") !== false) {
            $lv = $lv - 1;
            continue;
        }
        $nest = "";
        for ($i = 0; $i < $dims - 1 - $lv; $i++) {
            $nest = $nest . "[";
        }
        if ($nest != "") {
            $fc = api_constrained_continue([$nest], 48);
            if ($fc == "") {
                return "";
            }
            $s = $s . "," . $fc;
        }
        $s = $s . "," . api_number_fill(0, $maxSteps);
        $cnt[$lv] = $cnt[$lv] + 1;
        for ($i = $lv + 1; $i < $dims; $i++) {
            $cnt[$i] = 1;
        }
        $lv = $dims - 1;
    }
    $rest = "";
    if ($lv >= 0) {
        for ($i = 0; $i <= $lv; $i++) {
            $rest = $rest . "]";
        }
    }
    $rest = $rest . "}";
    $cb = api_constrained_continue([$rest], 2 * $dims + 8);
    if ($cb == "") {
        return "";
    }
    return $s . "," . $cb;
}

// Rank of a pure nested integer array (prop → array^n → integer); only ranks >= 7.
function api_response_schema_array_n_int(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern|minimum|maximum|multipleOf)"\s*:/', $schema) == 1) {
        return "";
    }
    $pm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{/', $schema, $pm) != 1) {
        return "";
    }
    $ip = strpos($schema, $pm[0]);
    if ($ip === false) {
        return "";
    }
    $win = substr($schema, $ip, 4000);
    $sm = [];
    if (preg_match('/^(.*?)"type"\s*:\s*"integer"/s', $win, $sm) != 1) {
        return "";
    }
    $head = $sm[1];
    if (strpos($head, '"properties"') !== false) {
        return "";
    }
    $na = preg_match_all('/"type"\s*:\s*"array"/', $head);
    $nt = preg_match_all('/"type"\s*:/', $head);
    if ($na < 7 || $na != $nt) {
        return "";
    }
    return $pm[1] . "|" . $na;
}

// Detect six nested array levels ending in a string items type.
function api_response_schema_array6_string(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern)"\s*:/', $schema) == 1) {
        return "";
    }
    $pm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{'
        . '\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*'
        . '"items"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*'
        . '"items"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{\s*"type"\s*:\s*"string"'
        . '\s*\}\s*\}\s*\}\s*\}\s*\}\s*\}\s*\}/s', $schema, $pm) != 1) {
        return "";
    }
    return $pm[1];
}

// A three-dimensional string array: {"m":[[["red"]]]}. Three nested "element ends" decisions:
// inside the innermost list ",", at the middle level "," (another inner list) or "]" (close), and
// at the outer level "," (another middle list) or "]" (close). Every level is force-closed when
// its element budget runs out, so the result always parses.
function api_array3_string_value(string $ids, string $prop, string $charset, int $maxOuter, int $maxMid, int $maxInner, int $maxSteps, int $useCache = 1): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($charsetIds == "" || $quoteIds == "" || $commaIds == "" || $bracketIds == "") {
        return "";
    }
    $s = api_constrained($ids, ["{\"" . $prop . "\":[[[\""], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $stepIds = $charsetIds . "," . $quoteIds;
    $q = "," . $quoteIds . ",";
    $endIds = $commaIds . "," . $bracketIds;
    $qComma = "," . $commaIds . ",";
    $outerSeen = 0;
    $outerBracket = 0;
    for ($o = 0; $o < $maxOuter && $outerSeen == 0; $o++) {
        if ($o > 0) {
            $op = api_constrained_continue(["[[\""], 4);
            if ($op == "") {
                return "";
            }
            $s = $s . "," . $op;
        }
        $midSeen = 0;
        $midBracket = 0;
        for ($m = 0; $m < $maxMid && $midSeen == 0; $m++) {
            if ($m > 0) {
                $mq = api_constrained_continue(["\""], 4);
                if ($mq == "") {
                    return "";
                }
                $s = $s . "," . $mq;
            }
            $innerSeen = 0;
            for ($in = 0; $in < $maxInner && $innerSeen == 0; $in++) {
                if ($in > 0) {
                    $iq = api_constrained_continue(["\""], 4);
                    if ($iq == "") {
                        return "";
                    }
                    $s = $s . "," . $iq;
                }
                $closed = 0;
                for ($d = 0; $d < $maxSteps; $d++) {
                    $nx = model_session_next_allowed(0, $stepIds);
                    if ($nx < 0) {
                        break;
                    }
                    $s = $s . "," . $nx;
                    if (strpos($q, "," . $nx . ",") !== false) {
                        $closed = 1;
                        break;
                    }
                }
                if ($closed == 0) {
                    $cq = api_constrained_continue(["\""], 4);
                    if ($cq != "") {
                        $s = $s . "," . $cq;
                    }
                }
                $nx2 = model_session_next_allowed(0, $in < $maxInner - 1 ? $endIds : $bracketIds);
                if ($nx2 < 0) {
                    break;
                }
                $s = $s . "," . $nx2;
                if (strpos($qComma, "," . $nx2 . ",") === false) {
                    $innerSeen = 1;
                }
            }
            if ($innerSeen == 0) {
                $ib = api_constrained_continue(["]"], 4);
                if ($ib != "") {
                    $s = $s . "," . $ib;
                }
            }
            if ($m < $maxMid - 1) {
                $nx3 = model_session_next_allowed(0, $endIds);
                if ($nx3 < 0) {
                    $midSeen = 1;
                } else {
                    $s = $s . "," . $nx3;
                    if (strpos($qComma, "," . $nx3 . ",") === false) {
                        $midSeen = 1;
                        $midBracket = 1;
                    } else {
                        $no = api_constrained_continue(["[\""], 4);
                        if ($no == "") {
                            return "";
                        }
                        $s = $s . "," . $no;
                    }
                }
            } else {
                $midSeen = 1;
            }
        }
        if ($midBracket == 0) {
            $mb = api_constrained_continue(["]"], 4);
            if ($mb != "") {
                $s = $s . "," . $mb;
            }
        }
        if ($o < $maxOuter - 1) {
            $nx4 = model_session_next_allowed(0, $endIds);
            if ($nx4 < 0) {
                $outerSeen = 1;
            } else {
                $s = $s . "," . $nx4;
                if (strpos($qComma, "," . $nx4 . ",") === false) {
                    $outerSeen = 1;
                    $outerBracket = 1;
                }
            }
        } else {
            $outerSeen = 1;
        }
    }
    if ($outerBracket == 0) {
        $ob = api_constrained_continue(["]"], 4);
        if ($ob != "") {
            $s = $s . "," . $ob;
        }
    }
    $end = api_constrained_continue(["}"], 4);
    if ($end != "") {
        $s = $s . "," . $end;
    }
    return $s;
}

// Detect {"prop":{"type":"array","items":{"type":"array","items":{"type":"array",
// "items":{"type":"string"}}}}}; returns the property name or "".
function api_response_schema_array3_string(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern)"\s*:/', $schema) == 1) {
        return "";
    }
    $pm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{'
        . '\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*'
        . '"items"\s*:\s*\{\s*"type"\s*:\s*"string"\s*\}\s*\}\s*\}\s*\}/s',
        $schema, $pm) != 1) {
        return "";
    }
    return $pm[1];
}

// A two-dimensional string array: {"m":[[" a"," b"],[" c"]]}. Inner elements use the charset
// grammar; after each element only "," (next element) or "]" (close the inner list) is
// reachable, and after the inner "]" only "," (next inner list) or "]" (close outer) is.
function api_array2_string_value(string $ids, string $prop, string $charset, int $maxOuter, int $maxInner, int $maxSteps, int $useCache = 1): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($charsetIds == "" || $quoteIds == "" || $commaIds == "" || $bracketIds == "") {
        return "";
    }
    $s = api_constrained($ids, ["{\"" . $prop . "\":[[\""], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $stepIds = $charsetIds . "," . $quoteIds;
    $q = "," . $quoteIds . ",";
    $endIds = $commaIds . "," . $bracketIds;
    $qComma = "," . $commaIds . ",";
    $outerClosed = 0;
    $bracketSeen = 0;
    for ($o = 0; $o < $maxOuter && $outerClosed == 0; $o++) {
        $innerClosed = 0;
        for ($in = 0; $in < $maxInner && $innerClosed == 0; $in++) {
            if ($in > 0) {
                $op = api_constrained_continue(["\""], 4);
                if ($op == "") {
                    return "";
                }
                $s = $s . "," . $op;
            }
            $closed = 0;
            for ($d = 0; $d < $maxSteps; $d++) {
                $nx = model_session_next_allowed(0, $stepIds);
                if ($nx < 0) {
                    break;
                }
                $s = $s . "," . $nx;
                if (strpos($q, "," . $nx . ",") !== false) {
                    $closed = 1;
                    break;
                }
            }
            if ($closed == 0) {
                $cq = api_constrained_continue(["\""], 4);
                if ($cq != "") {
                    $s = $s . "," . $cq;
                }
            }
            $nx2 = model_session_next_allowed(0, $in < $maxInner - 1 ? $endIds : $bracketIds);
            if ($nx2 < 0) {
                break;
            }
            $s = $s . "," . $nx2;
            if (strpos($qComma, "," . $nx2 . ",") === false) {
                $innerClosed = 1;
            }
        }
        if ($innerClosed == 0) {
            $rb = api_constrained_continue(["]"], 4);
            if ($rb != "") {
                $s = $s . "," . $rb;
            }
        }
        if ($o < $maxOuter - 1) {
            $nx3 = model_session_next_allowed(0, $endIds);
            if ($nx3 < 0) {
                $outerClosed = 1;
            } else {
                $s = $s . "," . $nx3;
                if (strpos($qComma, "," . $nx3 . ",") === false) {
                    $outerClosed = 1;
                    $bracketSeen = 1;
                } else {
                    $nb = api_constrained_continue(["[\""], 4);
                    if ($nb == "") {
                        return "";
                    }
                    $s = $s . "," . $nb;
                }
            }
        } else {
            $outerClosed = 1;
        }
    }
    if ($bracketSeen == 0) {
        $rb2 = api_constrained_continue(["]"], 4);
        if ($rb2 != "") {
            $s = $s . "," . $rb2;
        }
    }
    $end = api_constrained_continue(["}"], 4);
    if ($end != "") {
        $s = $s . "," . $end;
    }
    return $s;
}

// Detect {"prop":{"type":"array","items":{"type":"array","items":{"type":"string"}}}}.
function api_response_schema_array2_string(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern)"\s*:/', $schema) == 1) {
        return "";
    }
    $pm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{'
        . '\s*"type"\s*:\s*"array"\s*,\s*"items"\s*:\s*\{\s*"type"\s*:\s*"string"\s*\}'
        . '\s*\}\s*\}/s', $schema, $pm) != 1) {
        return "";
    }
    return $pm[1];
}

// First-key head of an object plan: {"k": with an opening quote when k is a string leaf.
function api_object_head(string $plan): string
{
    $items = explode(",", $plan);
    $kv = explode(":", $items[0]);
    $h = "{\"" . $kv[0] . "\":";
    if ($kv[1] == "s") {
        $h = $h . "\"";
    }
    return $h;
}

// Typed leaves declared DIRECTLY inside a properties block (depth 1; the value object has no
// nesting of its own). Returns "<name>:<s|i|n>,..." in order, at most maxN entries.
function api_top_leaves(string $block, int $maxN): string
{
    $out = "";
    $n = strlen($block);
    $i = 0;
    $count = 0;
    while ($i < $n && $count < $maxN) {
        $c = substr($block, $i, 1);
        if ($c != "\"") {
            $i++;
            continue;
        }
        $j = $i + 1;
        $key = "";
        while ($j < $n) {
            $d = substr($block, $j, 1);
            if ($d == "\\") {
                $j += 2;
                continue;
            }
            if ($d == "\"") {
                break;
            }
            $key = $key . $d;
            $j++;
        }
        if ($j >= $n) {
            break;
        }
        $k = $j + 1;
        while ($k < $n && substr($block, $k, 1) != "{") {
            $k++;
        }
        if ($k >= $n) {
            break;
        }
        $val = api_balance_block($block, $k);
        if ($val == "") {
            break;
        }
        // Depth-1 leaves are flat objects: a nested object has more than its own opening brace.
        if (substr_count($val, "{") == 1) {
            $m = [];
            if (preg_match('/"type"\s*:\s*"(string|integer|number)"/', $val, $m) == 1) {
                $mk = "s";
                if ($m[1] == "number") {
                    $mk = "n";
                } else if ($m[1] == "integer") {
                    $mk = "i";
                }
                $out = $out == "" ? $key . ":" . $mk : $out . "," . $key . ":" . $mk;
                $count++;
            }
        }
        $i = $k + strlen($val);
    }
    return $out;
}

// Substring from the "{" at openPos through its matching "}", honouring string literals. Used to
// keep a detector inside one object instead of letting its leaf regex swallow later properties.
function api_balance_block(string $s, int $openPos): string
{
    $n = strlen($s);
    if ($openPos < 0 || $openPos >= $n || substr($s, $openPos, 1) != "{") {
        return "";
    }
    $depth = 0;
    $inStr = 0;
    $esc = 0;
    for ($i = $openPos; $i < $n; $i++) {
        $c = substr($s, $i, 1);
        if ($inStr == 1) {
            if ($esc == 1) {
                $esc = 0;
            } else if ($c == "\\") {
                $esc = 1;
            } else if ($c == "\"") {
                $inStr = 0;
            }
            continue;
        }
        if ($c == "\"") {
            $inStr = 1;
            continue;
        }
        if ($c == "{") {
            $depth++;
        } else if ($c == "}") {
            $depth--;
            if ($depth == 0) {
                return substr($s, $openPos, $i - $openPos + 1);
            }
        }
    }
    return "";
}

// One or more nested objects below a top-level property: {"a":{"b":{"c":"…","d":123}}}. The path
// uses "/" between key names; the plan lists the innermost object's flat leaves. Assembly forces
// every opening brace/key, fills the leaves, then forces path+1 closing braces.
function api_nested_object_value(string $ids, string $path, string $plan, string $charset, int $maxSteps, int $useCache = 1): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    if ($charsetIds == "" || $quoteIds == "") {
        return "";
    }
    $keys = explode("/", $path);
    $items = explode(",", $plan);
    if (count($keys) == 0 || count($items) == 0) {
        return "";
    }
    $head = api_object_head($plan);
    $pre = "{";
    for ($pi = 0; $pi < count($keys); $pi++) {
        $pre = $pre . "\"" . $keys[$pi] . "\":";
        if ($pi < count($keys) - 1) {
            $pre = $pre . "{";
        }
    }
    $pre = $pre . $head;
    $s = api_constrained($ids, [$pre], count($keys) * 8 + 64, $useCache);
    if ($s == "") {
        return "";
    }
    $stepIds = $charsetIds . "," . $quoteIds;
    $q = "," . $quoteIds . ",";
    for ($k = 0; $k < count($items); $k++) {
        $kv = explode(":", $items[$k]);
        if (count($kv) != 2) {
            return "";
        }
        $ty = $kv[1];
        if ($k > 0) {
            $sep = ",\"" . $kv[0] . "\":";
            if ($ty == "s") {
                $sep = $sep . "\"";
            }
            $sp = api_constrained_continue([$sep], 32);
            if ($sp == "") {
                return "";
            }
            $s = $s . "," . $sp;
        }
        if ($ty == "s") {
            $closed = 0;
            for ($d = 0; $d < $maxSteps; $d++) {
                $nx = model_session_next_allowed(0, $stepIds);
                if ($nx < 0) {
                    break;
                }
                $s = $s . "," . $nx;
                if (strpos($q, "," . $nx . ",") !== false) {
                    $closed = 1;
                    break;
                }
            }
            if ($closed == 0) {
                $cq = api_constrained_continue(["\""], 4);
                if ($cq != "") {
                    $s = $s . "," . $cq;
                }
            }
        } else {
            $body = api_number_fill($ty == "n" ? 1 : 0, 4);
            if ($body != "") {
                $s = $s . "," . $body;
            }
        }
    }
    $closers = "";
    for ($ci = 0; $ci < count($keys) + 1; $ci++) {
        $closers = $closers . "}";
    }
    $cb = api_constrained_continue([$closers], count($keys) * 2 + 8);
    if ($cb != "") {
        $s = $s . "," . $cb;
    }
    return $s;
}

// Detect {"outer":{"type":"object","properties":{"a":…,"b":…}}} (1..3 flat leaves, any order of
// type/properties). Returns "<outer>|<plan>" or "". Runs after the array detectors, which own the
// items-object case, and before the flat detectors.
function api_response_schema_nested_object(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (api_schema_is_object($schema) != 1) {
        return "";
    }
    if (preg_match('/"(enum|const|pattern|minimum|maximum|multipleOf)"\s*:/', $schema) == 1) {
        return "";
    }
    $pm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{(?=[^{}]*"properties"\s*:\s*\{)/s',
        $schema, $pm) != 1) {
        return "";
    }
    $ip = strpos($schema, $pm[0]);
    if ($ip === false) {
        return "";
    }
    $path = $pm[1];
    $win = substr($schema, $ip, 8000);
    // Descend while the current properties block has no flat leaves, up to 64 levels.
    for ($depth = 0; $depth < 128; $depth++) {
        $pp = strpos($win, "\"properties\"");
        if ($pp === false) {
            return "";
        }
        $bo = strpos($win, "{", $pp);
        if ($bo === false) {
            return "";
        }
        $block = api_balance_block($win, $bo);
        if ($block == "") {
            return "";
        }
        $plan = api_top_leaves($block, 3);
        if ($plan != "") {
            return $path . "|" . $plan;
        }
        $cm = [];
        if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{(?=[^{}]*"properties"\s*:\s*\{)/s',
            $block, $cm) != 1) {
            return "";
        }
        $path = $path . "/" . $cm[1];
        $ci = strpos($block, $cm[0]);
        if ($ci === false) {
            return "";
        }
        $win = substr($block, $ci, 20000);
    }
    return "";
}

// An array of flat objects, e.g. {"people":[{"name":" a","age":123}, …]}. The element plan is
// "<name>:<s|i|n>,…" taken from items.properties. Each element is closed by a forced "}", then
// only "," (another element) or "]" (end) is reachable.
function api_array_object_value(string $ids, string $prop, string $plan, string $charset, int $maxItems, int $maxSteps, int $useCache = 1): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($charsetIds == "" || $quoteIds == "" || $commaIds == "" || $bracketIds == "") {
        return "";
    }
    $items = explode(",", $plan);
    if (count($items) == 0) {
        return "";
    }
    $head = api_object_head($plan);
    $s = api_constrained($ids, ["{\"" . $prop . "\":[" . $head], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $stepIds = $charsetIds . "," . $quoteIds;
    $q = "," . $quoteIds . ",";
    $endIds = $commaIds . "," . $bracketIds;
    $qComma = "," . $commaIds . ",";
    $done = 0;
    for ($it = 0; $it < $maxItems; $it++) {
        if ($it > 0) {
            $hd = api_constrained_continue([$head], 32);
            if ($hd == "") {
                return "";
            }
            $s = $s . "," . $hd;
        }
        for ($k = 0; $k < count($items); $k++) {
            $kv = explode(":", $items[$k]);
            if (count($kv) != 2) {
                return "";
            }
            $ty = $kv[1];
            if ($k > 0) {
                $sep = ",\"" . $kv[0] . "\":";
                if ($ty == "s") {
                    $sep = $sep . "\"";
                }
                $sp = api_constrained_continue([$sep], 32);
                if ($sp == "") {
                    return "";
                }
                $s = $s . "," . $sp;
            }
            if ($ty == "s") {
                $closed = 0;
                for ($d = 0; $d < $maxSteps; $d++) {
                    $nx = model_session_next_allowed(0, $stepIds);
                    if ($nx < 0) {
                        break;
                    }
                    $s = $s . "," . $nx;
                    if (strpos($q, "," . $nx . ",") !== false) {
                        $closed = 1;
                        break;
                    }
                }
                if ($closed == 0) {
                    $cq = api_constrained_continue(["\""], 4);
                    if ($cq != "") {
                        $s = $s . "," . $cq;
                    }
                }
            } else {
                $body = api_number_fill($ty == "n" ? 1 : 0, 3);
                if ($body != "") {
                    $s = $s . "," . $body;
                }
            }
        }
        $cb = api_constrained_continue(["}"], 4);
        if ($cb != "") {
            $s = $s . "," . $cb;
        }
        $nx2 = model_session_next_allowed(0, $it < $maxItems - 1 ? $endIds : $bracketIds);
        if ($nx2 < 0) {
            break;
        }
        $s = $s . "," . $nx2;
        if (strpos($qComma, "," . $nx2 . ",") === false) {
            $done = 1;   // "]"
            break;
        }
    }
    if ($done == 0) {
        $rb = api_constrained_continue(["]"], 4);
        if ($rb != "") {
            $s = $s . "," . $rb;
        }
    }
    $end = api_constrained_continue(["}"], 4);
    if ($end != "") {
        $s = $s . "," . $end;
    }
    return $s;
}

// An array of objects each holding one nested chain of objects, e.g.
// {"items":[{"meta":{"inner":{"city":" a","count":1}}}, …]}. The path "meta/inner" is the
// element-relative nesting; the bottom level carries the plan leaves. This is the element loop
// of api_array_object_value composed with the nested prefix/closers of api_nested_object_value.
function api_array_object_nested_value(string $ids, string $prop, string $path, string $plan, string $charset, int $maxItems, int $maxSteps, int $useCache = 1): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($charsetIds == "" || $quoteIds == "" || $commaIds == "" || $bracketIds == "") {
        return "";
    }
    $keys = explode("/", $path);
    $items = explode(",", $plan);
    if (count($keys) == 0 || $keys[0] == "" || count($items) == 0) {
        return "";
    }
    $head = api_object_head($plan);
    $pre = "{";
    for ($pi = 0; $pi < count($keys); $pi++) {
        $pre = $pre . "\"" . $keys[$pi] . "\":";
        if ($pi < count($keys) - 1) {
            $pre = $pre . "{";
        }
    }
    $pre = $pre . $head;
    $closers = "";
    for ($ci = 0; $ci < count($keys) + 1; $ci++) {
        $closers = $closers . "}";
    }
    $s = api_constrained($ids, ["{\"" . $prop . "\":[" . $pre], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $stepIds = $charsetIds . "," . $quoteIds;
    $q = "," . $quoteIds . ",";
    $endIds = $commaIds . "," . $bracketIds;
    $qComma = "," . $commaIds . ",";
    $done = 0;
    for ($it = 0; $it < $maxItems; $it++) {
        if ($it > 0) {
            $hd = api_constrained_continue([$pre], count($keys) * 8 + 64);
            if ($hd == "") {
                return "";
            }
            $s = $s . "," . $hd;
        }
        for ($k = 0; $k < count($items); $k++) {
            $kv = explode(":", $items[$k]);
            if (count($kv) != 2) {
                return "";
            }
            $ty = $kv[1];
            if ($k > 0) {
                $sep = ",\"" . $kv[0] . "\":";
                if ($ty == "s") {
                    $sep = $sep . "\"";
                }
                $sp = api_constrained_continue([$sep], 32);
                if ($sp == "") {
                    return "";
                }
                $s = $s . "," . $sp;
            }
            if ($ty == "s") {
                $closed = 0;
                for ($d = 0; $d < $maxSteps; $d++) {
                    $nx = model_session_next_allowed(0, $stepIds);
                    if ($nx < 0) {
                        break;
                    }
                    $s = $s . "," . $nx;
                    if (strpos($q, "," . $nx . ",") !== false) {
                        $closed = 1;
                        break;
                    }
                }
                if ($closed == 0) {
                    $cq = api_constrained_continue(["\""], 4);
                    if ($cq != "") {
                        $s = $s . "," . $cq;
                    }
                }
            } else {
                $body = api_number_fill($ty == "n" ? 1 : 0, 3);
                if ($body != "") {
                    $s = $s . "," . $body;
                }
            }
        }
        $cb = api_constrained_continue([$closers], count($keys) * 2 + 8);
        if ($cb == "") {
            return "";
        }
        $s = $s . "," . $cb;
        $nx2 = model_session_next_allowed(0, $it < $maxItems - 1 ? $endIds : $bracketIds);
        if ($nx2 < 0) {
            break;
        }
        $s = $s . "," . $nx2;
        if (strpos($qComma, "," . $nx2 . ",") === false) {
            $done = 1;   // "]"
            break;
        }
    }
    if ($done == 0) {
        $rb = api_constrained_continue(["]"], 4);
        if ($rb == "") {
            return "";
        }
        $s = $s . "," . $rb;
    }
    $end = api_constrained_continue(["}"], 4);
    if ($end == "") {
        return "";
    }
    return $s . "," . $end;
}

// Detect {"prop":{"type":"array","items":{"type":"object","properties":{…}}}} with 1..4 flat
// leaves. Returns "<prop>|<plan>" or "". Must run BEFORE the nested-string detector: the items
// object has "properties", so otherwise "items" would look like an outer key.
function api_response_schema_array_object(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern|minimum|maximum|multipleOf)"\s*:/', $schema) == 1) {
        return "";
    }
    $pm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*'
        . '"items"\s*:\s*\{\s*"type"\s*:\s*"object"/s', $schema, $pm) != 1) {
        return "";
    }
    $ip = strpos($schema, $pm[0]);
    if ($ip === false) {
        return "";
    }
    $win = substr($schema, $ip, 1500);
    // Leaves must come from the items object's own properties block, never from later siblings.
    $pp = strpos($win, "\"properties\"");
    if ($pp === false) {
        return "";
    }
    $bo = strpos($win, "{", $pp);
    if ($bo === false) {
        return "";
    }
    $block = api_balance_block($win, $bo);
    if ($block == "") {
        return "";
    }
    $plan = api_top_leaves($block, 4);
    if ($plan == "") {
        return "";
    }
    return $pm[1] . "|" . $plan;
}

// An array of objects whose properties are a string array and optionally a scalar string:
// {"items":[{"tags":["red"],"note":"x"}]}. One string per element keeps the "]} close unambiguous.
function api_array_object_array_value(string $ids, string $prop, string $arrProp, string $scalar, string $charset, int $maxItems, int $maxSteps, int $useCache = 1): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($charsetIds == "" || $quoteIds == "" || $commaIds == "" || $bracketIds == "") {
        return "";
    }
    $head = "{\"" . $prop . "\":[{\"" . $arrProp . "\":[\"";
    $s = api_constrained($ids, [$head], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $stepIds = $charsetIds . "," . $quoteIds;
    $q = "," . $quoteIds . ",";
    $qComma = "," . $commaIds . ",";
    $done = 0;
    for ($it = 0; $it < $maxItems; $it++) {
        if ($it > 0) {
            $hd = api_constrained_continue(["{\"" . $arrProp . "\":[\""], 48);
            if ($hd == "") {
                return "";
            }
            $s = $s . "," . $hd;
        }
        $closed = 0;
        for ($d = 0; $d < $maxSteps; $d++) {
            $nx = model_session_next_allowed(0, $stepIds);
            if ($nx < 0) {
                break;
            }
            $s = $s . "," . $nx;
            if (strpos($q, "," . $nx . ",") !== false) {
                $closed = 1;
                break;
            }
        }
        if ($closed == 0) {
            $cq = api_constrained_continue(["\""], 4);
            if ($cq == "") {
                return "";
            }
            $s = $s . "," . $cq;
        }
        $cb = api_constrained_continue(["]"], 8);
        if ($cb == "") {
            return "";
        }
        $s = $s . "," . $cb;
        if ($scalar != "") {
            $ns = api_constrained_continue([",\"" . $scalar . "\":\""], 48);
            if ($ns == "") {
                return "";
            }
            $s = $s . "," . $ns;
            $scClosed = 0;
            for ($sd = 0; $sd < $maxSteps; $sd++) {
                $sn = model_session_next_allowed(0, $stepIds);
                if ($sn < 0) {
                    break;
                }
                $s = $s . "," . $sn;
                if (strpos($q, "," . $sn . ",") !== false) {
                    $scClosed = 1;
                    break;
                }
            }
            if ($scClosed == 0) {
                $scq = api_constrained_continue(["\""], 4);
                if ($scq == "") {
                    return "";
                }
                $s = $s . "," . $scq;
            }
        }
        $cb2 = api_constrained_continue(["}"], 8);
        if ($cb2 == "") {
            return "";
        }
        $s = $s . "," . $cb2;
        $nx2 = model_session_next_allowed(0, $it < $maxItems - 1 ? $commaIds : $bracketIds);
        if ($nx2 < 0) {
            break;
        }
        $s = $s . "," . $nx2;
        if (strpos($qComma, "," . $nx2 . ",") === false) {
            $done = 1;
            break;
        }
    }
    if ($done == 0) {
        $rb = api_constrained_continue(["]"], 4);
        if ($rb == "") {
            return "";
        }
        $s = $s . "," . $rb;
    }
    $end = api_constrained_continue(["}"], 4);
    if ($end == "") {
        return "";
    }
    return $s . "," . $end;
}

// An array of objects whose only property is an array of integers:
// {"items":[{"nums":[7,12]}]}. 1..maxInts numbers per element; "," keeps the inner list open and
// "]" closes it; a forced "}" then closes the element.
function api_array_object_int_array_value(string $ids, string $prop, string $arrProp, int $maxItems, int $maxInts, int $maxSteps, int $useCache = 1): string
{
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($commaIds == "" || $bracketIds == "") {
        return "";
    }
    $head = "{\"" . $prop . "\":[{\"" . $arrProp . "\":[";
    $s = api_constrained($ids, [$head], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $qComma = "," . $commaIds . ",";
    $done = 0;
    for ($it = 0; $it < $maxItems; $it++) {
        if ($it > 0) {
            $hd = api_constrained_continue(["{\"" . $arrProp . "\":["], 48);
            if ($hd == "") {
                return "";
            }
            $s = $s . "," . $hd;
        }
        $innerClosed = 0;
        for ($ni = 0; $ni < $maxInts; $ni++) {
            $nb = api_number_fill(0, $maxSteps);
            if ($nb == "") {
                return "";
            }
            $s = $s . "," . $nb;
            $nx = model_session_next_allowed(0, $ni < $maxInts - 1 ? $commaIds : $bracketIds);
            if ($nx < 0) {
                break;
            }
            $s = $s . "," . $nx;
            if (strpos($qComma, "," . $nx . ",") === false) {
                $innerClosed = 1;
                break;
            }
        }
        if ($innerClosed == 0) {
            $rb = api_constrained_continue(["]"], 4);
            if ($rb == "") {
                return "";
            }
            $s = $s . "," . $rb;
        }
        $cb = api_constrained_continue(["}"], 4);
        if ($cb == "") {
            return "";
        }
        $s = $s . "," . $cb;
        $nx2 = model_session_next_allowed(0, $it < $maxItems - 1 ? $commaIds : $bracketIds);
        if ($nx2 < 0) {
            break;
        }
        $s = $s . "," . $nx2;
        if (strpos($qComma, "," . $nx2 . ",") === false) {
            $done = 1;
            break;
        }
    }
    if ($done == 0) {
        $rb2 = api_constrained_continue(["]"], 4);
        if ($rb2 == "") {
            return "";
        }
        $s = $s . "," . $rb2;
    }
    $end = api_constrained_continue(["}"], 4);
    if ($end == "") {
        return "";
    }
    return $s . "," . $end;
}

// Same outer shape when the element's only property is an array of integers.
// Returns "<prop>|<arrProp>"; runs before the flat-leaf array-object detector.
function api_response_schema_array_object_int_array(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern|minimum|maximum|multipleOf)"\s*:/', $schema) == 1) {
        return "";
    }
    $pm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*'
        . '"items"\s*:\s*\{\s*"type"\s*:\s*"object"\s*,\s*"properties"\s*:\s*\{\s*'
        . '"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*'
        . '"items"\s*:\s*\{\s*"type"\s*:\s*"integer"/s', $schema, $pm) != 1) {
        return "";
    }
    $ip = strpos($schema, $pm[0]);
    if ($ip === false) {
        return "";
    }
    $win = substr($schema, $ip, 1500);
    $pp = strpos($win, "\"properties\"");
    if ($pp === false) {
        return "";
    }
    $bo = strpos($win, "{", $pp);
    if ($bo === false) {
        return "";
    }
    $block = api_balance_block($win, $bo);
    if ($block == "") {
        return "";
    }
    if (preg_match('/"properties"\s*:/', $block) == 1) {
        return "";
    }
    if (preg_match_all('/"type"\s*:/', $block) != 2) {
        return "";
    }
    $apos = strpos($block, "\"" . $pm[2] . "\"");
    if ($apos === false) {
        return "";
    }
    $ao = strpos($block, "{", $apos);
    if ($ao === false) {
        return "";
    }
    $ab = api_balance_block($block, $ao);
    if ($ab == "") {
        return "";
    }
    $aend = strpos($block, $ab, $apos);
    if ($aend === false) {
        return "";
    }
    if (trim(substr($block, $aend + strlen($ab))) != "}") {
        return "";
    }
    return $pm[1] . "|" . $pm[2];
}

// An array of objects whose only property is a two-level string array:
// {"items":[{"m":[["red"]]}]}. One string per inner list keeps the "]]} close unambiguous.
function api_array_object_array2_value(string $ids, string $prop, string $arrProp, string $charset, int $maxItems, int $maxSteps, int $useCache = 1): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($charsetIds == "" || $quoteIds == "" || $commaIds == "" || $bracketIds == "") {
        return "";
    }
    $head = "{\"" . $prop . "\":[{\"" . $arrProp . "\":[[\"";
    $s = api_constrained($ids, [$head], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $stepIds = $charsetIds . "," . $quoteIds;
    $q = "," . $quoteIds . ",";
    $qComma = "," . $commaIds . ",";
    $done = 0;
    for ($it = 0; $it < $maxItems; $it++) {
        if ($it > 0) {
            $hd = api_constrained_continue(["{\"" . $arrProp . "\":[[\""], 48);
            if ($hd == "") {
                return "";
            }
            $s = $s . "," . $hd;
        }
        $closed = 0;
        for ($d = 0; $d < $maxSteps; $d++) {
            $nx = model_session_next_allowed(0, $stepIds);
            if ($nx < 0) {
                break;
            }
            $s = $s . "," . $nx;
            if (strpos($q, "," . $nx . ",") !== false) {
                $closed = 1;
                break;
            }
        }
        if ($closed == 0) {
            $cq = api_constrained_continue(["\""], 4);
            if ($cq == "") {
                return "";
            }
            $s = $s . "," . $cq;
        }
        $cb = api_constrained_continue(["]" . "]}"], 8);
        if ($cb == "") {
            return "";
        }
        $s = $s . "," . $cb;
        $nx2 = model_session_next_allowed(0, $it < $maxItems - 1 ? $commaIds : $bracketIds);
        if ($nx2 < 0) {
            break;
        }
        $s = $s . "," . $nx2;
        if (strpos($qComma, "," . $nx2 . ",") === false) {
            $done = 1;
            break;
        }
    }
    if ($done == 0) {
        $rb = api_constrained_continue(["]"], 4);
        if ($rb == "") {
            return "";
        }
        $s = $s . "," . $rb;
    }
    $end = api_constrained_continue(["}"], 4);
    if ($end == "") {
        return "";
    }
    return $s . "," . $end;
}

// Same outer shape when the element's only property is a two-level string array.
// Returns "<prop>|<arrProp>"; runs before the one-level and flat-leaf object-array detectors.
function api_response_schema_array_object_array2(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern|minimum|maximum|multipleOf)"\s*:/', $schema) == 1) {
        return "";
    }
    $pm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*'
        . '"items"\s*:\s*\{\s*"type"\s*:\s*"object"\s*,\s*"properties"\s*:\s*\{\s*'
        . '"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*'
        . '"items"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*'
        . '"items"\s*:\s*\{\s*"type"\s*:\s*"string"/s', $schema, $pm) != 1) {
        return "";
    }
    $ip = strpos($schema, $pm[0]);
    if ($ip === false) {
        return "";
    }
    $win = substr($schema, $ip, 1500);
    $pp = strpos($win, "\"properties\"");
    if ($pp === false) {
        return "";
    }
    $bo = strpos($win, "{", $pp);
    if ($bo === false) {
        return "";
    }
    $block = api_balance_block($win, $bo);
    if ($block == "") {
        return "";
    }
    if (preg_match('/"properties"\s*:/', $block) == 1) {
        return "";
    }
    if (preg_match_all('/"type"\s*:/', $block) != 3) {
        return "";
    }
    $apos = strpos($block, "\"" . $pm[2] . "\"");
    if ($apos === false) {
        return "";
    }
    $ao = strpos($block, "{", $apos);
    if ($ao === false) {
        return "";
    }
    $ab = api_balance_block($block, $ao);
    if ($ab == "") {
        return "";
    }
    $aend = strpos($block, $ab, $apos);
    if ($aend === false) {
        return "";
    }
    if (trim(substr($block, $aend + strlen($ab))) != "}") {
        return "";
    }
    return $pm[1] . "|" . $pm[2];
}

// Same outer array-of-objects shape when the element's only property is a string array.
// Returns "<prop>|<arrProp>". Must run BEFORE api_response_schema_array_object, whose flat-leaf
// scan would otherwise read the inner array's "items" as a sibling property.
function api_response_schema_array_object_array(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern|minimum|maximum|multipleOf)"\s*:/', $schema) == 1) {
        return "";
    }
    $pm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*'
        . '"items"\s*:\s*\{\s*"type"\s*:\s*"object"\s*,\s*"properties"\s*:\s*\{\s*'
        . '"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*'
        . '"items"\s*:\s*\{\s*"type"\s*:\s*"string"/s', $schema, $pm) != 1) {
        return "";
    }
    $ip = strpos($schema, $pm[0]);
    if ($ip === false) {
        return "";
    }
    $win = substr($schema, $ip, 1500);
    $pp = strpos($win, "\"properties\"");
    if ($pp === false) {
        return "";
    }
    $bo = strpos($win, "{", $pp);
    if ($bo === false) {
        return "";
    }
    $block = api_balance_block($win, $bo);
    if ($block == "") {
        return "";
    }
    if (preg_match('/"properties"\s*:/', $block) == 1) {
        return "";
    }
    $apos = strpos($block, "\"" . $pm[2] . "\"");
    if ($apos === false) {
        return "";
    }
    $ao = strpos($block, "{", $apos);
    if ($ao === false) {
        return "";
    }
    $ab = api_balance_block($block, $ao);
    if ($ab == "") {
        return "";
    }
    $aend = strpos($block, $ab, $apos);
    if ($aend === false) {
        return "";
    }
    $tail = trim(substr($block, $aend + strlen($ab)));
    $scalar = "";
    $want = 2;
    if ($tail != "}") {
        // The tail always carries the properties block's own closing brace; drop that one
        // brace, then the remainder must be exactly one scalar string sibling.
        $t = preg_replace('/\}$/', "", $tail);
        $sm = [];
        if ($t === $tail
            || preg_match('/^,\s*"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"string"\s*\}$/s', $t, $sm) != 1) {
            return "";
        }
        $scalar = $sm[1];
        $want = 3;
    }
    // Exactly the element's own properties: array (+ optional scalar), no nested object.
    if (preg_match_all('/"type"\s*:/', $block) != $want) {
        return "";
    }
    $r = $pm[1] . "|" . $pm[2];
    if ($scalar != "") {
        $r = $r . "|" . $scalar;
    }
    return $r;
}

// An array of objects each holding a single chain of nested objects ending in flat leaves:
// {"items":[{"meta":{"inner":{"city":"x","count":1}}}]}. The chain is element-relative and every
// level on it must be the only property of its block. Returns "<prop>|<path>|<plan>" or "".
// Runs beside the array-object detectors: the element top level has no flat leaves, so the flat
// array-object detector would not claim it, and a plain nested-object detector could misread
// "items" as an outer key.
function api_response_schema_array_object_nested(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern|minimum|maximum|multipleOf)"\s*:/', $schema) == 1) {
        return "";
    }
    $pm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"array"\s*,\s*'
        . '"items"\s*:\s*\{\s*"type"\s*:\s*"object"\s*,\s*"properties"\s*:\s*\{/', $schema, $pm) != 1) {
        return "";
    }
    $ip = strpos($schema, $pm[0]);
    if ($ip === false) {
        return "";
    }
    $win = substr($schema, $ip, 1500);
    $pp = strpos($win, "\"properties\"");
    if ($pp === false) {
        return "";
    }
    $bo = strpos($win, "{", $pp);
    if ($bo === false) {
        return "";
    }
    $block = api_balance_block($win, $bo);
    if ($block == "") {
        return "";
    }
    // The element's top level must hold no flat leaves (the flat detector owns that case).
    if (api_top_leaves($block, 4) != "") {
        return "";
    }
    // Descend the single chain: each level's block must contain exactly one property (an object
    // with its own "properties"), ending at the first level that has flat leaves.
    $path = "";
    $cur = $block;
    for ($depth = 0; $depth < 128; $depth++) {
        $cm = [];
        if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{(?=[^{}]*"properties"\s*:\s*\{)/s', $cur, $cm) != 1) {
            return "";
        }
        $ci = strpos($cur, $cm[0]);
        if ($ci === false) {
            return "";
        }
        $co = strpos($cur, "{", $ci);
        if ($co === false) {
            return "";
        }
        $child = api_balance_block($cur, $co);
        if ($child == "") {
            return "";
        }
        // Locate where the child block actually occurs (the match spans the key, the child
        // starts at its own brace), then the remainder must be just this block's closer.
        $aend = strpos($cur, $child, $ci);
        if ($aend === false) {
            return "";
        }
        $tail = trim(substr($cur, $aend + strlen($child)));
        if ($tail != "}") {
            return "";
        }
        // Into the child's own properties block: api_top_leaves reads properties blocks, and the
        // value object's "type"/"properties" wrapper must not feed it.
        $pp2 = strpos($child, "\"properties\"");
        if ($pp2 === false) {
            return "";
        }
        $bo2 = strpos($child, "{", $pp2);
        if ($bo2 === false) {
            return "";
        }
        $cur = api_balance_block($child, $bo2);
        if ($cur == "") {
            return "";
        }
        $path = $path == "" ? $cm[1] : $path . "/" . $cm[1];
        $plan = api_top_leaves($cur, 3);
        if ($plan != "") {
            return $pm[1] . "|" . $path . "|" . $plan;
        }
    }
    return "";
}

// A oneOf/anyOf property whose branches are small objects: the FIRST property of every branch is
// a distinct const string (the branch selector), the optional second is a grammar leaf (charset
// string or integer) or another const. Const-only branch sets stay with the flatten/candidates
// path, so this detector only claims when at least one branch carries a grammar leaf. Returns
// "<prop>|<branch>;<branch>" where a branch is "key=const" or "key:s"/"key:i", or "".
function api_response_schema_oneof_grammar(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    $pm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"(oneOf|anyOf)"\s*:\s*\[/', $schema, $pm) != 1) {
        return "";
    }
    // The oneOf property must sit inside a properties block that precedes it (a root-level
    // oneOf belongs to the candidate-union path) and be that block's only property.
    $ip = strpos($schema, $pm[0]);
    if ($ip === false) {
        return "";
    }
    $pp = strrpos(substr($schema, 0, $ip), "\"properties\"");
    if ($pp === false) {
        return "";
    }
    $pb = strpos($schema, "{", $pp);
    if ($pb === false) {
        return "";
    }
    $block = api_balance_block($schema, $pb);
    if ($block == "" || strpos($block, $pm[0]) === false) {
        return "";
    }
    $vo = strpos($schema, "{", $ip);
    if ($vo === false) {
        return "";
    }
    $value = api_balance_block($schema, $vo);
    if ($value == "") {
        return "";
    }
    $ob = strpos($value, "[");
    if ($ob === false) {
        return "";
    }
    $arr = api_balance_square($value, $ob);
    if ($arr == "" || $value != "{\"" . $pm[2] . "\":" . $arr . "}") {
        return "";
    }
    $aend = strpos($block, $value);
    if ($aend === false) {
        return "";
    }
    $tail = trim(substr($block, $aend + strlen($value)));
    if ($tail != "}") {
        return "";
    }
    $inner = substr($arr, 1, strlen($arr) - 2);
    $branches = [];
    $pos = 0;
    while ($pos < strlen($inner)) {
        $bo = strpos($inner, "{", $pos);
        if ($bo === false) {
            break;
        }
        $branch = api_balance_block($inner, $bo);
        if ($branch == "") {
            return "";
        }
        $branches[] = $branch;
        $pos = $bo + strlen($branch);
    }
    if (count($branches) < 2 || count($branches) > 3) {
        return "";
    }
    $plans = [];
    $hasGrammar = 0;
    $consts = [];
    for ($bi = 0; $bi < count($branches); $bi++) {
        if (preg_match('/^\{"type"\s*:\s*"object"\s*,\s*"properties"\s*:\s*\{/', $branches[$bi]) != 1) {
            return "";
        }
        $pb2 = strpos($branches[$bi], "{", 1);
        if ($pb2 === false) {
            return "";
        }
        $pblock = api_balance_block($branches[$bi], $pb2);
        if ($pblock == "") {
            return "";
        }
        $props = [];
        $i = 0;
        $n = strlen($pblock);
        while ($i < $n) {
            if (substr($pblock, $i, 1) != "\"") {
                $i++;
                continue;
            }
            $j = strpos($pblock, "\"", $i + 1);
            if ($j === false) {
                return "";
            }
            $key = substr($pblock, $i + 1, $j - $i - 1);
            if (preg_match('/^[A-Za-z0-9_\-]{1,24}$/', $key) != 1) {
                return "";
            }
            $k = strpos($pblock, "{", $j);
            if ($k === false) {
                return "";
            }
            $val = api_balance_block($pblock, $k);
            if ($val == "") {
                return "";
            }
            if (strpos($val, "\"const\"") !== false) {
                $cm = [];
                if (preg_match('/"const"\s*:\s*"([A-Za-z0-9_\- ]{1,24})"/', $val, $cm) != 1
                    || preg_match('/"(type|enum|pattern)"/', $val) == 1) {
                    return "";
                }
                if ($cm[1] == "" || $cm[1] == "s" || $cm[1] == "i" || $cm[1] == "n") {
                    return "";
                }
                $consts[] = $cm[1];
                $props[] = $key . "=" . $cm[1];
            } else if (substr_count($val, "{") == 1
                && preg_match('/"type"\s*:\s*"string"/', $val) == 1) {
                $props[] = $key . ":s";
                $hasGrammar = 1;
            } else if (substr_count($val, "{") == 1
                && preg_match('/"type"\s*:\s*"integer"/', $val) == 1) {
                $props[] = $key . ":i";
                $hasGrammar = 1;
            } else {
                return "";
            }
            $i = $k + strlen($val);
        }
        if (count($props) < 1 || count($props) > 2) {
            return "";
        }
        if (strpos($props[0], "=") === false) {
            return "";
        }
        $plans[] = implode(",", $props);
    }
    if (count($consts) != count(array_unique($consts))) {
        return "";
    }
    if ($hasGrammar == 0) {
        return "";
    }
    return $pm[1] . "|" . implode(";", $plans);
}

// Generate the oneOf shape claimed by api_response_schema_oneof_grammar. api_constrained picks
// the branch (the const first property differs per branch); the chosen branch is identified by
// matching each candidate's tok_encode against the emitted ids; then the branch's own grammar
// fills the second property and the two braces close.
function api_oneof_grammar_value(string $ids, string $prop, string $branches, string $charset, int $maxSteps, int $useCache = 1): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    if ($charsetIds == "" || $quoteIds == "") {
        return "";
    }
    $stepIds = $charsetIds . "," . $quoteIds;
    $q = "," . $quoteIds . ",";
    $specs = explode(";", $branches);
    if (count($specs) < 2 || count($specs) > 3) {
        return "";
    }
    $cands = [];
    $candIds = [];
    for ($b = 0; $b < count($specs); $b++) {
        $props = explode(",", $specs[$b]);
        if (count($props) < 1 || count($props) > 2) {
            return "";
        }
        $p0 = explode("=", $props[0]);
        if (count($p0) != 2 || $p0[0] == "" || $p0[1] == "") {
            return "";
        }
        $cands[] = "{\"" . $prop . "\":{\"" . $p0[0] . "\":\"" . $p0[1] . "\"";
        $candIds[] = explode(",", tok_encode($cands[$b]));
    }
    $s = api_constrained($ids, $cands, 64, $useCache);
    if ($s == "") {
        return "";
    }
    $base = count(explode(",", $ids));
    $chosen = implode(",", array_slice(explode(",", $s), $base));
    $b = -1;
    for ($k = 0; $k < count($cands); $k++) {
        if ($chosen == implode(",", $candIds[$k])) {
            $b = $k;
            break;
        }
    }
    if ($b < 0) {
        return "";
    }
    $props = explode(",", $specs[$b]);
    for ($k = 1; $k < count($props); $k++) {
        $ty = explode(":", $props[$k]);
        $eq = explode("=", $props[$k]);
        if (count($ty) == 2 && ($ty[1] == "s" || $ty[1] == "i")) {
            if ($ty[1] == "s") {
                $sp = api_constrained_continue([",\"" . $ty[0] . "\":\""], 32);
                if ($sp == "") {
                    return "";
                }
                $s = $s . "," . $sp;
                $closed = 0;
                for ($d = 0; $d < $maxSteps; $d++) {
                    $nx = model_session_next_allowed(0, $stepIds);
                    if ($nx < 0) {
                        break;
                    }
                    $s = $s . "," . $nx;
                    if (strpos($q, "," . $nx . ",") !== false) {
                        $closed = 1;
                        break;
                    }
                }
                if ($closed == 0) {
                    $cq = api_constrained_continue(["\""], 4);
                    if ($cq == "") {
                        return "";
                    }
                    $s = $s . "," . $cq;
                }
            } else {
                $sp = api_constrained_continue([",\"" . $ty[0] . "\":"], 32);
                if ($sp == "") {
                    return "";
                }
                $s = $s . "," . $sp;
                $nb = api_number_fill(0, 3);
                if ($nb == "") {
                    return "";
                }
                $s = $s . "," . $nb;
            }
        } else if (count($eq) == 2 && $eq[0] != "" && $eq[1] != "") {
            $sp = api_constrained_continue([",\"" . $eq[0] . "\":\"" . $eq[1] . "\""], 32);
            if ($sp == "") {
                return "";
            }
            $s = $s . "," . $sp;
        } else {
            return "";
        }
    }
    $end = api_constrained_continue(["}}"], 8);
    if ($end == "") {
        return "";
    }
    return $s . "," . $end;
}

// Root-level oneOf/anyOf whose branches are objects with a const discriminator plus grammar
// leaves: schema == {"oneOf":[{"type":"object","properties":{"kind":{"const":"book"},
// "title":{"type":"string"}}},…]}. Returns "root|<branch plans>"; F159's detector owns the
// property-nested form, and const-only branch sets stay with the candidate-union path.
function api_response_schema_root_oneof_grammar(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    $pm = [];
    if (preg_match('/^\{\s*"(oneOf|anyOf)"\s*:\s*\[/', $schema, $pm) != 1) {
        return "";
    }
    $ob = strpos($schema, "[");
    if ($ob === false) {
        return "";
    }
    $arr = api_balance_square($schema, $ob);
    if ($arr == "" || $schema != "{\"" . $pm[1] . "\":" . $arr . "}") {
        return "";
    }
    $inner = substr($arr, 1, strlen($arr) - 2);
    $branches = [];
    $pos = 0;
    while ($pos < strlen($inner)) {
        $bo = strpos($inner, "{", $pos);
        if ($bo === false) {
            break;
        }
        $branch = api_balance_block($inner, $bo);
        if ($branch == "") {
            return "";
        }
        $branches[] = $branch;
        $pos = $bo + strlen($branch);
    }
    if (count($branches) < 2 || count($branches) > 3) {
        return "";
    }
    $plans = [];
    $hasGrammar = 0;
    $consts = [];
    for ($bi = 0; $bi < count($branches); $bi++) {
        if (preg_match('/^\{"type"\s*:\s*"object"\s*,\s*"properties"\s*:\s*\{/', $branches[$bi]) != 1) {
            return "";
        }
        $pb = strpos($branches[$bi], "{", 1);
        if ($pb === false) {
            return "";
        }
        $pblock = api_balance_block($branches[$bi], $pb);
        if ($pblock == "") {
            return "";
        }
        $props = [];
        $i = 0;
        $n = strlen($pblock);
        while ($i < $n) {
            if (substr($pblock, $i, 1) != "\"") {
                $i++;
                continue;
            }
            $j = strpos($pblock, "\"", $i + 1);
            if ($j === false) {
                return "";
            }
            $key = substr($pblock, $i + 1, $j - $i - 1);
            if (preg_match('/^[A-Za-z0-9_\-]{1,24}$/', $key) != 1) {
                return "";
            }
            $k = strpos($pblock, "{", $j);
            if ($k === false) {
                return "";
            }
            $val = api_balance_block($pblock, $k);
            if ($val == "") {
                return "";
            }
            if (count($props) == 0) {
                $cm = [];
                if (preg_match('/"const"\s*:\s*"([A-Za-z0-9_\- ]{1,24})"/', $val, $cm) != 1
                    || preg_match('/"(type|enum|pattern)"/', $val) == 1) {
                    return "";
                }
                if ($cm[1] == "" || $cm[1] == "s" || $cm[1] == "i" || $cm[1] == "n") {
                    return "";
                }
                $consts[] = $cm[1];
                $props[] = $key . "=" . $cm[1];
            } else if (substr_count($val, "{") == 1
                && preg_match('/"type"\s*:\s*"string"/', $val) == 1) {
                $props[] = $key . ":s";
                $hasGrammar = 1;
            } else if (substr_count($val, "{") == 1
                && preg_match('/"type"\s*:\s*"integer"/', $val) == 1) {
                $props[] = $key . ":i";
                $hasGrammar = 1;
            } else {
                return "";
            }
            $i = $k + strlen($val);
        }
        if (count($props) < 1 || count($props) > 2) {
            return "";
        }
        $plans[] = implode(",", $props);
    }
    if ($hasGrammar != 1) {
        return "";
    }
    for ($x = 0; $x < count($consts); $x++) {
        for ($y = $x + 1; $y < count($consts); $y++) {
            if ($consts[$x] == $consts[$y]) {
                return "";
            }
        }
    }
    return "root|" . implode(";", $plans);
}

// Root-level counterpart of api_oneof_grammar_value: the branch objects are the response root,
// so candidates start at "{" and the final forced close is a single "}".
function api_root_oneof_grammar_value(string $ids, string $branches, string $charset, int $maxSteps, int $useCache = 1): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    if ($charsetIds == "" || $quoteIds == "") {
        return "";
    }
    $stepIds = $charsetIds . "," . $quoteIds;
    $q = "," . $quoteIds . ",";
    $specs = explode(";", $branches);
    if (count($specs) < 2 || count($specs) > 3) {
        return "";
    }
    $cands = [];
    $candIds = [];
    for ($b = 0; $b < count($specs); $b++) {
        $props = explode(",", $specs[$b]);
        if (count($props) < 1 || count($props) > 2) {
            return "";
        }
        $p0 = explode("=", $props[0]);
        if (count($p0) != 2 || $p0[0] == "" || $p0[1] == "") {
            return "";
        }
        $cands[] = "{\"" . $p0[0] . "\":\"" . $p0[1] . "\"";
        $candIds[] = explode(",", tok_encode($cands[$b]));
    }
    $s = api_constrained($ids, $cands, 64, $useCache);
    if ($s == "") {
        return "";
    }
    $base = count(explode(",", $ids));
    $chosen = implode(",", array_slice(explode(",", $s), $base));
    $b = -1;
    for ($k = 0; $k < count($cands); $k++) {
        if ($chosen == implode(",", $candIds[$k])) {
            $b = $k;
            break;
        }
    }
    if ($b < 0) {
        return "";
    }
    $props = explode(",", $specs[$b]);
    for ($k = 1; $k < count($props); $k++) {
        $ty = explode(":", $props[$k]);
        $eq = explode("=", $props[$k]);
        if (count($ty) == 2 && ($ty[1] == "s" || $ty[1] == "i")) {
            if ($ty[1] == "s") {
                $sp = api_constrained_continue([",\"" . $ty[0] . "\":\""], 32);
                if ($sp == "") {
                    return "";
                }
                $s = $s . "," . $sp;
                $closed = 0;
                for ($d = 0; $d < $maxSteps; $d++) {
                    $nx = model_session_next_allowed(0, $stepIds);
                    if ($nx < 0) {
                        break;
                    }
                    $s = $s . "," . $nx;
                    if (strpos($q, "," . $nx . ",") !== false) {
                        $closed = 1;
                        break;
                    }
                }
                if ($closed == 0) {
                    $cq = api_constrained_continue(["\""], 4);
                    if ($cq == "") {
                        return "";
                    }
                    $s = $s . "," . $cq;
                }
            } else {
                $sp = api_constrained_continue([",\"" . $ty[0] . "\":"], 32);
                if ($sp == "") {
                    return "";
                }
                $s = $s . "," . $sp;
                $nb = api_number_fill(0, 3);
                if ($nb == "") {
                    return "";
                }
                $s = $s . "," . $nb;
            }
        } else if (count($eq) == 2 && $eq[0] != "" && $eq[1] != "") {
            $sp = api_constrained_continue([",\"" . $eq[0] . "\":\"" . $eq[1] . "\""], 32);
            if ($sp == "") {
                return "";
            }
            $s = $s . "," . $sp;
        } else {
            return "";
        }
    }
    $end = api_constrained_continue(["}"], 4);
    if ($end == "") {
        return "";
    }
    return $s . "," . $end;
}

// An object whose top-level leaves mix strings and numbers (2..4 of them, no constraints). The
// plan is "<name>:<s|i|n>,..." in schema order. Each key is forced; string leaves use the
// charset grammar, number leaves use api_number_fill, and the closing brace is forced.
function api_object_mixed(string $ids, string $plan, string $charset, int $maxSteps, int $useCache = 1): string
{
    $items = explode(",", $plan);
    if (count($items) == 0) {
        return "";
    }
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    if ($charsetIds == "" || $quoteIds == "") {
        return "";
    }
    $stepIds = $charsetIds . "," . $quoteIds;
    $q = "," . $quoteIds . ",";
    $first = explode(":", $items[0]);
    if (count($first) != 2) {
        return "";
    }
    $head = "{\"" . $first[0] . "\":";
    if ($first[1] == "s") {
        $head = $head . "\"";
    }
    $s = api_constrained($ids, [$head], 64, $useCache);
    if ($s == "") {
        return "";
    }
    for ($k = 0; $k < count($items); $k++) {
        $kv = explode(":", $items[$k]);
        if (count($kv) != 2) {
            return "";
        }
        $ty = $kv[1];
        if ($k > 0) {
            $sep = ",\"" . $kv[0] . "\":";
            if ($ty == "s") {
                $sep = $sep . "\"";
            }
            $sp = api_constrained_continue([$sep], 32);
            if ($sp == "") {
                return "";
            }
            $s = $s . "," . $sp;
        }
        if ($ty == "s") {
            $closed = 0;
            for ($d = 0; $d < $maxSteps; $d++) {
                $nx = model_session_next_allowed(0, $stepIds);
                if ($nx < 0) {
                    break;
                }
                $s = $s . "," . $nx;
                if (strpos($q, "," . $nx . ",") !== false) {
                    $closed = 1;
                    break;
                }
            }
            if ($closed == 0) {
                $cq = api_constrained_continue(["\""], 4);
                if ($cq != "") {
                    $s = $s . "," . $cq;
                }
            }
        } else {
            $body = api_number_fill($ty == "n" ? 1 : 0, 6);
            if ($body != "") {
                $s = $s . "," . $body;
            }
        }
    }
    $end = api_constrained_continue(["}"], 4);
    if ($end != "") {
        $s = $s . "," . $end;
    }
    return $s;
}

// Detect that mixed object: 2..4 typed leaves, at least one number, so all-string schemas keep
// using api_string_value_multi. Returns the plan string or "".
function api_response_schema_mixed_object(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern|minimum|maximum|multipleOf)"\s*:/', $schema) == 1) {
        return "";
    }
    // Scan only the top-level properties block: a nested object's leaves must not be pulled up.
    if (api_schema_is_object($schema) != 1) {
        return "";
    }
    $pp = strpos($schema, "\"properties\"");
    if ($pp === false) {
        return "";
    }
    $bo = strpos($schema, "{", $pp);
    if ($bo === false) {
        return "";
    }
    $block = api_balance_block($schema, $bo);
    if ($block == "") {
        return "";
    }
    $plan = api_top_leaves($block, 4);
    if ($plan == "") {
        return "";
    }
    $items = explode(",", $plan);
    if (count($items) < 2) {
        return "";
    }
    $hasNum = 0;
    for ($i = 0; $i < count($items); $i++) {
        $kv = explode(":", $items[$i]);
        if (count($kv) == 2 && $kv[1] != "s") {
            $hasNum = 1;
        }
    }
    if ($hasNum == 0) {
        return "";
    }
    return $plan;
}

// Digit-only vocabulary. With $single == 1 only one-character digits are allowed, so the first
// character of a number can never be a multi-digit run starting with an illegal leading zero.
function api_digit_ids(int $single): string
{
    $all = gguf_charset_ids("0123456789");
    if ($single == 0) {
        return $all;
    }
    $out = "";
    $parts = explode(",", $all);
    for ($i = 0; $i < count($parts); $i++) {
        if (strlen(gguf_token_str((int) $parts[$i])) == 1) {
            $out = $out == "" ? $parts[$i] : $out . "," . $parts[$i];
        }
    }
    return $out;
}

// Ids of tokens whose decoded text is exactly this one character (e.g. "-"), never a longer
// token that merely contains it.
function api_punct_ids(string $ch): string
{
    $all = gguf_charset_ids($ch);
    $out = "";
    $parts = explode(",", $all);
    for ($i = 0; $i < count($parts); $i++) {
        if (gguf_token_str((int) $parts[$i]) == $ch) {
            $out = $out == "" ? $parts[$i] : $out . "," . $parts[$i];
        }
    }
    return $out;
}

// Fill a number on the CURRENT session (the caller already forced the prefix) and return the
// chosen ids as CSV. Digits only, optional "-", optional ".", leading zero rules as documented
// on api_number_value.
function api_number_fill(int $allowFrac, int $maxSteps): string
{
    $single = api_digit_ids(1);
    $all = api_digit_ids(0);
    if ($single == "" || $all == "") {
        return "";
    }
    $minus = api_punct_ids("-");
    $dot = $allowFrac == 1 ? api_punct_ids(".") : "";
    $step0 = $minus == "" ? $single : $single . "," . $minus;
    $intStep = $all;
    if ($allowFrac == 1 && $dot != "") {
        $intStep = $all . "," . $dot;
    }
    $s = "";
    $neg = 0;
    $gotDigit = 0;
    $leadZero = 0;
    $frac = 0;
    $gotFrac = 0;
    for ($d = 0; $d < $maxSteps; $d++) {
        $allowed = $intStep;
        if ($d == 0) {
            $allowed = $step0;
        } else if ($neg == 1 && $gotDigit == 0) {
            $allowed = $single;
        } else if ($frac == 1) {
            $allowed = $single;
        } else if ($leadZero == 1) {
            $allowed = $dot;
        }
        if ($allowed == "") {
            break;
        }
        $nx = model_session_next_allowed(0, $allowed);
        if ($nx < 0) {
            break;
        }
        $s = $s == "" ? "" . $nx : $s . "," . $nx;
        $tok = gguf_token_str($nx);
        if ($tok == "-") {
            $neg = 1;
            continue;
        }
        if ($tok == ".") {
            $frac = 1;
            continue;
        }
        if ($frac == 1) {
            $gotFrac = 1;
            continue;
        }
        if ($gotDigit == 0) {
            $gotDigit = 1;
            if ($tok == "0") {
                if ($dot == "") {
                    break;   // integer schema: a leading zero cannot grow
                }
                $leadZero = 1;
            }
        }
    }
    if ($gotDigit == 0) {
        $z = api_constrained_continue(["0"], 4);
        if ($z != "") {
            $s = $s == "" ? $z : $s . "," . $z;
        }
    } else if ($frac == 1 && $gotFrac == 0) {
        $z2 = api_constrained_continue(["0"], 4);
        if ($z2 != "") {
            $s = $s == "" ? $z2 : $s . "," . $z2;
        }
    }
    return $s;
}

// A single unbounded numeric property has no finite candidate set. Fill it with a digit grammar:
// optional "-", integer digits (first digit a single-char token, so no illegal leading zero),
// optionally "." plus at least one fraction digit when $allowFrac is set. Leading "0" closes
// unless a fraction follows; a bare "-"/"1." gets a forced "0". The forced brace closes the object.
function api_number_value(string $ids, string $prop, int $allowFrac, int $maxSteps, int $useCache = 1): string
{
    $s = api_constrained($ids, ["{\"" . $prop . "\":"], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $body = api_number_fill($allowFrac, $maxSteps);
    if ($body != "") {
        $s = $s . "," . $body;
    }
    $end = api_constrained_continue(["}"], 4);
    if ($end != "") {
        $s = $s . "," . $end;
    }
    return $s;
}

// Same number, one level deep: {"outer":{"inner":123}}.
function api_number_nested(string $ids, string $outer, string $inner, int $allowFrac, int $maxSteps, int $useCache = 1): string
{
    $s = api_constrained($ids, ["{\"" . $outer . "\":{\"" . $inner . "\":"], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $body = api_number_fill($allowFrac, $maxSteps);
    if ($body != "") {
        $s = $s . "," . $body;
    }
    $end = api_constrained_continue(["}}"], 8);
    if ($end != "") {
        $s = $s . "," . $end;
    }
    return $s;
}

// Detect a single unbounded integer/number property so the number grammar can fill it. Bounded
// numbers are already covered by api_schema_candidates; enum/const/pattern mean "not this shape".
// Returns "<prop>|<allowFrac>" or "".
function api_response_schema_number_prop(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern|minimum|maximum|multipleOf)"\s*:/', $schema) == 1) {
        return "";
    }
    $pm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{[^{}]*?"type"\s*:\s*"(integer|number)"[^{}]*\}/s',
        $schema, $pm) != 1) {
        return "";
    }
    return $pm[1] . "|" . ($pm[2] == "number" ? "1" : "0");
}

// Unbounded integer multipleOf. No finite candidate set exists, so decode a short digit prefix
// and force a three-digit suffix x = (m - (p*1000) % m) % m. Since m <= 999, x < 1000 and
// p*1000 + x is divisible by m while staying a legal JSON integer (zero-padded suffix).
function api_multiple_fill(int $mult, int $maxSteps): string
{
    $single = api_digit_ids(1);
    if ($single == "" || $mult < 2 || $mult > 999) {
        return "";
    }
    $nonzero = "";
    $parts = explode(",", $single);
    for ($i = 0; $i < count($parts); $i++) {
        $t = gguf_token_str((int) $parts[$i]);
        if (strlen($t) == 1 && $t != "0") {
            $nonzero = $nonzero == "" ? $parts[$i] : $nonzero . "," . $parts[$i];
        }
    }
    if ($nonzero == "") {
        return "";
    }
    $s = "";
    $p = 0;
    $got = 0;
    for ($d = 0; $d < $maxSteps; $d++) {
        $nx = model_session_next_allowed(0, $d == 0 ? $nonzero : $single);
        if ($nx < 0) {
            break;
        }
        $tok = gguf_token_str($nx);
        if (strlen($tok) != 1 || strpos("0123456789", $tok) === false) {
            break;
        }
        $s = $s == "" ? "" . $nx : $s . "," . $nx;
        $p = $p * 10 + (int) $tok;
        $got = 1;
    }
    if ($got == 0) {
        return "";
    }
    $x = ($mult - ($p * 1000) % $mult) % $mult;
    $digits = "" . $x;
    while (strlen($digits) < 3) {
        $digits = "0" . $digits;
    }
    for ($i = 0; $i < 3; $i++) {
        $f = api_constrained_continue([substr($digits, $i, 1)], 4);
        if ($f == "") {
            return $s;
        }
        $s = $s . "," . $f;
    }
    return $s;
}

function api_multiple_value(string $ids, string $prop, int $mult, int $maxSteps, int $useCache = 1): string
{
    $s = api_constrained($ids, ["{\"" . $prop . "\":"], 64, $useCache);
    if ($s == "") {
        return "";
    }
    $body = api_multiple_fill($mult, $maxSteps);
    if ($body != "") {
        $s = $s . "," . $body;
    }
    $end = api_constrained_continue(["}"], 4);
    if ($end != "") {
        $s = $s . "," . $end;
    }
    return $s;
}

// Detect an integer property with multipleOf and no bounds; bounded multiples are enumerated by
// api_schema_candidates. Returns "<prop>|<mult>" or "".
function api_response_schema_multiple_unbounded(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    if (preg_match('/"(enum|const|pattern|minimum|maximum|exclusiveMinimum|exclusiveMaximum)"\s*:/', $schema) == 1) {
        return "";
    }
    $mm = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{[^{}]*?"type"\s*:\s*"integer"[^{}]*?"multipleOf"\s*:\s*([0-9]+)[^{}]*?\}/s', $schema, $mm) == 1) {
        return $mm[1] . "|" . $mm[2];
    }
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{[^{}]*?"multipleOf"\s*:\s*([0-9]+)[^{}]*?"type"\s*:\s*"integer"[^{}]*?\}/s', $schema, $mm) == 1) {
        return $mm[1] . "|" . $mm[2];
    }
    return "";
}

// Tool-call plumbing (FR-08.2 / FR-09.1). Two paths, both honest about their guarantee:
//  * tool_choice names a function -> we CONSTRAIN the output to that call's skeleton, so a call
//    is guaranteed (arguments are left empty; filling them needs a schema-aware decoder).
//  * tools declared but no tool_choice -> generate freely, then accept the output only if it
//    parses as JSON naming one of the declared functions.
function api_tool_names(string $body): array
{
    $out = [];
    // Only the "tools" array declares what may be called; history messages also carry
    // "name" fields inside tool_calls, and scanning the whole body would whitelist those.
    $tp = strpos($body, "\"tools\"");
    if ($tp === false) {
        return $out;
    }
    $ob = strpos($body, "[", $tp);
    if ($ob === false) {
        return $out;
    }
    $arr = api_balance_square($body, $ob);
    if ($arr == "") {
        return $out;
    }
    $parts = explode("\"name\"", $arr);
    for ($i = 1; $i < count($parts); $i++) {
        $m = [];
        if (!preg_match('/^\s*:\s*"([A-Za-z0-9_\-]+)"/', $parts[$i], $m)) {
            continue;
        }
        $seen = 0;
        for ($j = 0; $j < count($out); $j++) {
            if ($out[$j] == $m[1]) {
                $seen = 1;
            }
        }
        if ($seen == 0) {
            $out[] = $m[1];
        }
    }
    return $out;
}

function api_tool_choice(string $body): string
{
    $parts = explode("\"tool_choice\"", $body);
    if (count($parts) < 2) {
        return "";
    }
    $rest = $parts[1];
    // String forms are never a named choice; without this guard the search below would find a
    // function name inside the "tools" array that follows "tool_choice":"none".
    $sm = [];
    if (preg_match('/^\s*:\s*"[a-zA-Z_\-]+"/', $rest, $sm) == 1) {
        return "";
    }
    $ob = strpos($rest, "{");
    if ($ob === false) {
        return "";
    }
    $obj = api_balance_block($rest, $ob);
    if ($obj == "") {
        return "";
    }
    $m = [];
    return preg_match('/"name"\s*:\s*"([A-Za-z0-9_\-]+)"/', $obj, $m) ? $m[1] : "";
}

function api_tool_call_json(string $text, array $names): string
{
    // The model's own template asks for the call inside <tool_call></tool_call>; accept both
    // that wire form and a bare JSON object.
    $json = $text;
    $tp = strpos($json, "<tool_call>");
    if ($tp !== false) {
        $json = substr($json, $tp + 11);
        $te = strpos($json, "</tool_call>");
        if ($te !== false) {
            $json = substr($json, 0, $te);
        }
    }
    if (api_json_ok($json) != 1) {
        return "";
    }
    $name = api_str($json, "name");
    if ($name == "") {
        return "";
    }
    $declared = 0;
    for ($i = 0; $i < count($names); $i++) {
        if ($names[$i] == $name) {
            $declared = 1;
        }
    }
    if ($declared == 0) {
        return "";
    }
    // Arguments are passed through as the model wrote the object; an empty object when the
    // constrained skeleton was used. Nested extraction is not attempted (see findings F52).
    $args = api_str($json, "arguments");
    if ($args != "") {
        $args = api_unesc($args);   // schema candidates carry the object as an escaped string
    }
    if ($args == "") {
        $args = "{}";
    }
    return "{\"id\":\"call_1\",\"type\":\"function\",\"function\":{\"name\":\"" . api_esc($name)
        . "\",\"arguments\":\"" . api_esc($args) . "\"}}";
}

// api_constrained returns "prompt,ids,generated,ids"; the answer starts at $promptN.
function api_gen_text(string $csv, int $promptN): string
{
    if ($csv == "") {
        return "";
    }
    $parts = explode(",", $csv);
    $gen = "";
    for ($i = $promptN; $i < count($parts); $i++) {
        $gen = $i == $promptN ? $parts[$i] : $gen . "," . $parts[$i];
    }
    return $gen == "" ? "" : api_text($gen);
}

// Undo api_esc for a value that was embedded as a JSON string (schema-derived arguments).
function api_unesc(string $s): string
{
    $s = str_replace("\\\"", "\"", $s);
    $s = str_replace("\\\\", "\\", $s);
    return $s;
}

// Flat-schema argument derivation (FR-08.2): properties whose schema is an enum or a const
// give a finite candidate set, so the constrained decoder can pick a real arguments object.
// Free-form strings, numbers, booleans and nested objects are NOT enumerated - when none of
// those remain we return [] and the caller keeps the empty-object skeleton.
// Extend the cartesian product with one property. $rawv=1 emits boolean/number values unquoted.
function api_combine_add(array $combos, string $prop, array $vals, int $rawv): array
{
    $next = [];
    for ($c = 0; $c < count($combos); $c++) {
        for ($v = 0; $v < count($vals); $v++) {
            $valJson = $rawv == 1 ? $vals[$v] : "\"" . api_esc($vals[$v]) . "\"";
            $frag = "\"" . $prop . "\":" . $valJson;
            $next[] = $combos[$c] == "" ? $frag : $combos[$c] . "," . $frag;
        }
    }
    return $next;
}

// Finite candidates from a schema fragment (tool parameters or response_format schema).
// Substring from the "[" at openPos through its matching "]", honouring string literals.
function api_balance_square(string $s, int $openPos): string
{
    $n = strlen($s);
    if ($openPos < 0 || $openPos >= $n || substr($s, $openPos, 1) != "[") {
        return "";
    }
    $depth = 0;
    $inStr = 0;
    $esc = 0;
    for ($i = $openPos; $i < $n; $i++) {
        $c = substr($s, $i, 1);
        if ($inStr == 1) {
            if ($esc == 1) {
                $esc = 0;
            } else if ($c == "\\") {
                $esc = 1;
            } else if ($c == "\"") {
                $inStr = 0;
            }
            continue;
        }
        if ($c == "\"") {
            $inStr = 1;
            continue;
        }
        if ($c == "[") {
            $depth++;
        } else if ($c == "]") {
            $depth--;
            if ($depth == 0) {
                return substr($s, $openPos, $i - $openPos + 1);
            }
        }
    }
    return "";
}

// Merge {"allOf":[{"properties":{...}}, …]} into one object by concatenating the members' own
// properties blocks. This is the common tool-schema shape; sibling keys of the allOf holder are
// dropped (only constraint-relevant keys matter here). Run after local $ref resolution.
function api_schema_merge_allof(string $s): string
{
    for ($round = 0; $round < 4; $round++) {
        $ap = strpos($s, "\"allOf\"");
        if ($ap === false) {
            break;
        }
        $ab = strpos($s, "[", $ap);
        if ($ab === false) {
            break;
        }
        $arr = api_balance_square($s, $ab);
        if ($arr == "") {
            break;
        }
        $inner = substr($arr, 1, strlen($arr) - 2);
        $merged = "";
        $pos = 0;
        while ($pos < strlen($inner)) {
            $ob = strpos($inner, "{", $pos);
            if ($ob === false) {
                break;
            }
            $obj = api_balance_block($inner, $ob);
            if ($obj == "") {
                break;
            }
            $pp = strpos($obj, "\"properties\"");
            if ($pp !== false) {
                $pb = strpos($obj, "{", $pp);
                if ($pb !== false) {
                    $pblk = api_balance_block($obj, $pb);
                    if ($pblk != "") {
                        $content = substr($pblk, 1, strlen($pblk) - 2);
                        if ($content != "") {
                            $merged = $merged == "" ? $content : $merged . "," . $content;
                        }
                    }
                }
            }
            $pos = $ob + strlen($obj);
        }
        if ($merged == "") {
            break;
        }
        $back = $ap;
        while ($back >= 0 && substr($s, $back, 1) != "{") {
            $back--;
        }
        if ($back < 0) {
            break;
        }
        $holder = api_balance_block($s, $back);
        if ($holder == "") {
            break;
        }
        $repl = "{\"type\":\"object\",\"properties\":{" . $merged . "}}";
        $s = substr($s, 0, $back) . $repl . substr($s, $back + strlen($holder));
    }
    return $s;
}

// Flatten a property-level oneOf/anyOf of enum branches into a single enum: the candidate set is
// the union of the branches, which is exactly what finite-domain decoding needs. Runs after
// $ref/allOf preprocessing.
function api_schema_flatten_choices(string $s): string
{
    for ($round = 0; $round < 4; $round++) {
        $ap = strpos($s, "\"oneOf\"");
        $np = strpos($s, "\"anyOf\"");
        if ($np !== false && ($ap === false || $np < $ap)) {
            $ap = $np;
        }
        if ($ap === false) {
            break;
        }
        $ab = strpos($s, "[", $ap);
        if ($ab === false) {
            break;
        }
        $arr = api_balance_square($s, $ab);
        if ($arr == "") {
            break;
        }
        $inner = substr($arr, 1, strlen($arr) - 2);
        $vals = "";
        $pos = 0;
        while ($pos < strlen($inner)) {
            $ep = strpos($inner, "\"enum\"", $pos);
            if ($ep === false) {
                break;
            }
            $eb = strpos($inner, "[", $ep);
            if ($eb === false) {
                break;
            }
            $eblk = api_balance_square($inner, $eb);
            if ($eblk == "") {
                break;
            }
            $content = substr($eblk, 1, strlen($eblk) - 2);
            if ($content != "") {
                $vals = $vals == "" ? $content : $vals . "," . $content;
            }
            $pos = $eb + strlen($eblk);
        }
        if ($vals == "") {
            break;
        }
        $back = $ap;
        while ($back >= 0 && substr($s, $back, 1) != "{") {
            $back--;
        }
        if ($back < 0) {
            break;
        }
        $holder = api_balance_block($s, $back);
        if ($holder == "") {
            break;
        }
        // A root-level oneOf/anyOf selects between whole schemas; flattening it to an enum would
        // destroy the branch structure. Leave it for the candidate-union path.
        $sp = strpos($s, "\"schema\"");
        if ($sp !== false) {
            $so = strpos($s, "{", $sp);
            if ($so !== false && $so == $back) {
                break;
            }
        }
        $repl = "{\"enum\":[" . $vals . "]}";
        $s = substr($s, 0, $back) . $repl . substr($s, $back + strlen($holder));
    }
    return $s;
}

// Inline local "#/definitions/X" / "#/$defs/X" references: replace each {"$ref":...} object with
// the definition body, then empty the definitions container so property detectors never scan it.
// Handles chains of refs (up to six replacements); external refs are left untouched.
// Anthropic structured outputs carry {"output_format":{"type":"json_schema","schema":{…}}}.
// Append the same schema under the {"json_schema":{"schema":…}} marker that every grammar
// detector already understands, so the Messages exit reuses the whole constrained-Decoding chain.
function api_resp_start(string $modelId): string
{
    return "event: response.created\ndata: {\"type\":\"response.created\",\"response\":{\"id\":\"resp_1\","
        . "\"object\":\"response\",\"status\":\"in_progress\",\"model\":\"" . $modelId
        . "\",\"output\":[]}}\n\n"
        . "event: response.output_item.added\ndata: {\"type\":\"response.output_item.added\","
        . "\"output_index\":0,\"item\":{\"id\":\"msg_1\",\"type\":\"message\",\"status\":\"in_progress\","
        . "\"role\":\"assistant\",\"content\":[]}}\n\n"
        . "event: response.content_part.added\ndata: {\"type\":\"response.content_part.added\","
        . "\"item_id\":\"msg_1\",\"output_index\":0,\"content_index\":0,"
        . "\"part\":{\"type\":\"output_text\",\"text\":\"\",\"annotations\":[]}}\n\n";
}

function api_resp_delta(string $text): string
{
    return "event: response.output_text.delta\ndata: {\"type\":\"response.output_text.delta\","
        . "\"item_id\":\"msg_1\",\"output_index\":0,\"content_index\":0,"
        . "\"delta\":\"" . api_esc($text) . "\"}\n\n";
}

function api_resp_done(string $text, int $outTok, string $modelId, int $promptN): string
{
    return "event: response.output_text.done\ndata: {\"type\":\"response.output_text.done\","
        . "\"item_id\":\"msg_1\",\"output_index\":0,\"content_index\":0,"
        . "\"text\":\"" . api_esc($text) . "\"}\n\n"
        . "event: response.content_part.done\ndata: {\"type\":\"response.content_part.done\","
        . "\"item_id\":\"msg_1\",\"output_index\":0,\"content_index\":0,"
        . "\"part\":{\"type\":\"output_text\",\"text\":\"" . api_esc($text) . "\",\"annotations\":[]}}\n\n"
        . "event: response.output_item.done\ndata: {\"type\":\"response.output_item.done\","
        . "\"output_index\":0,\"item\":{\"id\":\"msg_1\",\"type\":\"message\",\"status\":\"completed\","
        . "\"role\":\"assistant\",\"content\":[{\"type\":\"output_text\",\"text\":\"" . api_esc($text)
        . "\",\"annotations\":[]}]}}\n\n"
        . "event: response.completed\ndata: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_1\","
        . "\"object\":\"response\",\"status\":\"completed\",\"model\":\"" . $modelId . "\",\"output\":["
        . "{\"id\":\"msg_1\",\"type\":\"message\",\"status\":\"completed\",\"role\":\"assistant\","
        . "\"content\":[{\"type\":\"output_text\",\"text\":\"" . api_esc($text) . "\",\"annotations\":[]}]}],"
        . "\"usage\":{\"input_tokens\":" . $promptN . ",\"output_tokens\":" . $outTok
        . ",\"total_tokens\":" . ($promptN + $outTok) . "}}}\n\n";
}

function api_resp_call_start(string $modelId, string $name): string
{
    return "event: response.created\ndata: {\"type\":\"response.created\",\"response\":{\"id\":\"resp_1\","
        . "\"object\":\"response\",\"status\":\"in_progress\",\"model\":\"" . $modelId
        . "\",\"output\":[]}}\n\n"
        . "event: response.output_item.added\ndata: {\"type\":\"response.output_item.added\","
        . "\"output_index\":0,\"item\":{\"id\":\"fc_1\",\"type\":\"function_call\","
        . "\"status\":\"in_progress\",\"call_id\":\"call_1\",\"name\":\"" . api_esc($name)
        . "\",\"arguments\":\"\"}}\n\n";
}

function api_resp_call_done(string $name, string $args, int $outTok, string $modelId, int $promptN): string
{
    return "event: response.function_call_arguments.delta\ndata: {\"type\":\"response.function_call_arguments.delta\","
        . "\"item_id\":\"fc_1\",\"output_index\":0,\"delta\":\"" . $args . "\"}\n\n"
        . "event: response.function_call_arguments.done\ndata: {\"type\":\"response.function_call_arguments.done\","
        . "\"item_id\":\"fc_1\",\"output_index\":0,\"arguments\":\"" . $args . "\"}\n\n"
        . "event: response.output_item.done\ndata: {\"type\":\"response.output_item.done\","
        . "\"output_index\":0,\"item\":{\"id\":\"fc_1\",\"type\":\"function_call\","
        . "\"status\":\"completed\",\"call_id\":\"call_1\",\"name\":\"" . api_esc($name)
        . "\",\"arguments\":\"" . $args . "\"}}\n\n"
        . "event: response.completed\ndata: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_1\","
        . "\"object\":\"response\",\"status\":\"completed\",\"model\":\"" . $modelId . "\",\"output\":["
        . "{\"id\":\"fc_1\",\"type\":\"function_call\",\"status\":\"completed\",\"call_id\":\"call_1\","
        . "\"name\":\"" . api_esc($name) . "\",\"arguments\":\"" . $args . "\"}],"
        . "\"usage\":{\"input_tokens\":" . $promptN . ",\"output_tokens\":" . $outTok
        . ",\"total_tokens\":" . ($promptN + $outTok) . "}}}\n\n";
}

// Requested number of choices ("n"), defaulting to one.
function api_request_n(string $body): int
{
    $m = [];
    if (preg_match('/"n"\s*:\s*([0-9]+)/', $body, $m) == 1) {
        return (int) $m[1];
    }
    return 1;
}

function api_anthropic_output_format(string $body): string
{
    $o = strpos($body, "\"output_format\"");
    if ($o === false) {
        return $body;
    }
    $s = strpos($body, "\"schema\"", $o);
    if ($s === false) {
        return $body;
    }
    $b = strpos($body, "{", $s);
    if ($b === false) {
        return $body;
    }
    $schema = api_balance_block($body, $b);
    if ($schema == "") {
        return $body;
    }
    return $body . ",\"json_schema\":{\"schema\":" . $schema . "}";
}

// Full JSON-schema grammar dispatch used by the streaming exits: returns the generated token-id
// list ("" when no schema applies). Mirrors the non-stream chain so every detector/writer is
// reachable from a single call site.
function api_schema_token_value(string $ids, string $body, int $useCache): string
{
    $schemaCands = api_response_schema_candidates($body);
    $scOut = "";
    if (count($schemaCands) > 0) {
        $scOut = api_constrained($ids, $schemaCands, 128, $useCache);
        return $scOut;
    }
    $scArrN = api_response_schema_array_n_string($body);
    $scArrNI = api_response_schema_array_n_int($body);
    $scArr6 = "";
    if ($scArrN == "") {
        $scArr6 = api_response_schema_array6_string($body);
    }
    $scArr5 = "";
    if ($scArr6 == "") {
        $scArr5 = api_response_schema_array5_string($body);
    }
    $scArr4 = "";
    if ($scArr5 == "") {
        $scArr4 = api_response_schema_array4_string($body);
    }
    $scArr3 = "";
    if ($scArr4 == "") {
        $scArr3 = api_response_schema_array3_string($body);
    }
    $scArr2 = "";
    if ($scArr4 == "" && $scArr3 == "") {
        // Element-level two-level arrays win over the flat top-level detector.
        $scArr2e = api_response_schema_array_object_array2($body);
        if ($scArr2e == "") {
            $scArr2 = api_response_schema_array2_string($body);
        }
    }
    $scArrObj2 = "";
    $scArrObjNum = "";
    $scRootOneof = "";
    $scArrObjArr = "";
    if ($scArr3 == "" && $scArr2 == "") {
        $scArrObjArr = api_response_schema_array_object_array($body);
        $scArrObjNest = api_response_schema_array_object_nested($body);
        $scOneof = api_response_schema_oneof_grammar($body);
        $scRootOneof = api_response_schema_root_oneof_grammar($body);
        $scArrObjNum = api_response_schema_array_object_int_array($body);
        $scArrObj2 = api_response_schema_array_object_array2($body);
    }
    $scArrObj = "";
    if ($scArrObjArr == "" && $scArr3 == "" && $scArr2 == "") {
        $scArrObj = api_response_schema_array_object($body);
    }
    $scNest = "";
    if ($scArr2 == "" && $scArrObj == "") {
        $scNest = api_response_schema_nested_object($body);
    }
    $scArr = "";
    if ($scArrObj == "" && $scNest == "") {
        $scArr = api_response_schema_array_string($body);
    }
    $scArrNum = "";
    if ($scArrObj == "" && $scNest == "" && $scArr == "") {
        $scArrNum = api_response_schema_array_number($body);
    }
    $scMix = "";
    if ($scArrObj == "" && $scNest == "" && $scArr == "" && $scArrNum == "") {
        $scMix = api_response_schema_mixed_object($body);
    }
    $scProp = [];
    if ($scArrNI != "") {
        $sani = explode("|", $scArrNI);
        $scOut = api_array_n_int_value($ids, $sani[0], (int) $sani[1], 2, 3, 1);
    } else if ($scArrN != "") {
        $sann = explode("|", $scArrN);
        $scOut = api_array_n_string_value($ids, $sann[0], (int) $sann[1],
            api_schema_charset(), 2, 3, 1);
    } else if ($scArr6 != "") {
        $scOut = api_array6_string_value($ids, $scArr6,
            api_schema_charset(), 2, 2, 2, 2, 2, 2, 3, 1);
    } else if ($scArr5 != "") {
        $scOut = api_array5_string_value($ids, $scArr5,
            api_schema_charset(), 2, 2, 2, 2, 2, 3, 1);
    } else if ($scArr4 != "") {
        $scOut = api_array4_string_value($ids, $scArr4,
            api_schema_charset(), 2, 2, 2, 2, 3, 1);
    } else if ($scArr3 != "") {
        $scOut = api_array3_string_value($ids, $scArr3,
            api_schema_charset(), 2, 2, 2, 4, 1);
    } else if ($scArr2 != "") {
        $scOut = api_array2_string_value($ids, $scArr2,
            api_schema_charset(), 2, 2, 4, 1);
    } else if ($scArrObj2 != "") {
        $sao2d = explode("|", $scArrObj2);
        $scOut = api_array_object_array2_value($ids, $sao2d[0], $sao2d[1],
            api_schema_charset(), 2, 4, 1);
    } else if ($scArrObjNum != "") {
        $saoin = explode("|", $scArrObjNum);
        $scOut = api_array_object_int_array_value($ids, $saoin[0],
            $saoin[1], 2, 2, 3, 1);
    } else if ($scArrObjArr != "") {
        $saoa = explode("|", $scArrObjArr);
        $scOut = api_array_object_array_value($ids, $saoa[0], $saoa[1],
            (isset($saoa[2]) ? $saoa[2] : ""), api_schema_charset(), 2, 6, 1);
    } else if ($scArrObjNest != "") {
        $saon = explode("|", $scArrObjNest);
        $scOut = api_array_object_nested_value($ids, $saon[0], $saon[1],
            $saon[2], api_schema_charset(), 2, 6, 1);
    } else if ($scArrObj != "") {
        $sao = explode("|", $scArrObj);
        $scOut = api_array_object_value($ids, $sao[0], $sao[1],
            api_schema_charset(), 3, 6, 1);
    } else if ($scRootOneof != "") {
        $sro = explode("|", $scRootOneof);
        $scOut = api_root_oneof_grammar_value($ids, $sro[1],
            api_schema_charset(), 6, 1);
    } else if ($scOneof != "") {
        $sof = explode("|", $scOneof);
        $scOut = api_oneof_grammar_value($ids, $sof[0], $sof[1],
            api_schema_charset(), 6, 1);
    } else if ($scNest != "") {
        $snp = explode("|", $scNest);
        $scOut = api_nested_object_value($ids, $snp[0], $snp[1],
            api_schema_charset(), 8, 1);
    } else if ($scArr != "") {
        $scOut = api_array_string_value($ids, $scArr,
            api_schema_charset(), 4, 6, 1);
    } else if ($scArrNum != "") {
        $san = explode("|", $scArrNum);
        $scOut = api_array_number_value($ids, $san[0], (int) $san[1],
            4, 3, 1);
    } else if ($scMix != "") {
        $scOut = api_object_mixed($ids, $scMix, api_schema_charset(), 16, 1);
    } else if (api_response_schema_top_array_string($body) == 1) {
        $scOut = api_top_array_string_value($ids, api_schema_charset(), 4, 4, 1);
    } else if (api_response_schema_top_array_number($body) >= 0) {
        $scOut = api_top_array_number_value($ids,
            api_response_schema_top_array_number($body), 3, 3, 1);
    } else if (api_response_schema_top_array_bool($body) == 1) {
        $scOut = api_constrained($ids, api_top_bool_candidates(), 8, 1);
    } else if (api_response_schema_top_array_nested_object($body) != "") {
        $tson = explode("|", api_response_schema_top_array_nested_object($body));
        $scOut = api_top_array_nested_object_value($ids, $tson[0],
            $tson[1], api_schema_charset(), 2, 6, 1);
    } else if (api_response_schema_top_array_object($body) != "") {
        $scOut = api_top_array_object_value($ids,
            api_response_schema_top_array_object($body),
            api_schema_charset(), 2, 6, 1);
    } else {
        $scProp = api_response_schema_string_props($body);
        if (count($scProp) > 0) {
            $scOut = api_string_value_multi($ids, $scProp,
                api_schema_charset(), 16, 1);
        } else {
            $scNestNum = api_response_schema_nested_number($body);
            if ($scNestNum != "") {
                $snn = explode("|", $scNestNum);
                $scOut = api_number_nested($ids, $snn[0], $snn[1],
                    (int) $snn[2], 6, 1);
            } else {
                $scMult = api_response_schema_multiple_unbounded($body);
                if ($scMult != "") {
                    $smu = explode("|", $scMult);
                    $scOut = api_multiple_value($ids, $smu[0], (int) $smu[1], 4, 1);
                } else {
                    $scNum = api_response_schema_number_prop($body);
                    if ($scNum != "") {
                        $sp2 = explode("|", $scNum);
                        $scOut = api_number_value($ids, $sp2[0], (int) $sp2[1], 6, 1);
                    }
                }
            }
        }
    }
    return $scOut;
}

function api_schema_resolve_refs(string $s): string
{
    $defKey = "\"definitions\"";
    $dk = strpos($s, $defKey);
    if ($dk === false) {
        $defKey = "\"\$defs\"";
        $dk = strpos($s, $defKey);
    }
    if ($dk === false) {
        return $s;
    }
    for ($round = 0; $round < 6; $round++) {
        $rp = strpos($s, '"$ref"');
        if ($rp === false) {
            break;
        }
        $m = [];
        if (preg_match('/"\$ref"\s*:\s*"#\/(?:definitions|\$defs)\/([A-Za-z0-9_\-]+)"/',
            substr($s, $rp, 200), $m) != 1) {
            break;
        }
        $dk = strpos($s, $defKey);
        if ($dk === false) {
            break;
        }
        $bo = strpos($s, "{", $dk);
        if ($bo === false) {
            break;
        }
        $defs = api_balance_block($s, $bo);
        if ($defs == "") {
            break;
        }
        $np = strpos($defs, "\"" . $m[1] . "\"");
        if ($np === false) {
            break;
        }
        $nbo = strpos($defs, "{", $np);
        if ($nbo === false) {
            break;
        }
        $val = api_balance_block($defs, $nbo);
        if ($val == "") {
            break;
        }
        // Walk back to the "{" that opens the ref object itself.
        $back = $rp;
        while ($back >= 0 && substr($s, $back, 1) != "{") {
            $back--;
        }
        if ($back < 0) {
            break;
        }
        $refObj = api_balance_block($s, $back);
        if ($refObj == "") {
            break;
        }
        $s = substr($s, 0, $back) . $val . substr($s, $back + strlen($refObj));
    }
    // Empty the definitions container so it cannot be mistaken for the schema's properties.
    $dk = strpos($s, $defKey);
    if ($dk !== false) {
        $bo = strpos($s, "{", $dk);
        if ($bo !== false) {
            $blk = api_balance_block($s, $bo);
            if ($blk != "") {
                $s = substr($s, 0, $bo) . "{}" . substr($s, $bo + strlen($blk));
            }
        }
    }
    return $s;
}

function api_schema_candidates(string $tail): array
{
    $out = [];
    $combos = [""];
    $used = [];
    // Work only inside the object's own properties block. Without this, an array schema's
    // "items" (or a nested object's leaf) would be treated as a top-level property.
    $pp = strpos($tail, "\"properties\"");
    if ($pp === false) {
        return $out;
    }
    $bo = strpos($tail, "{", $pp);
    if ($bo === false) {
        return $out;
    }
    $block = api_balance_block($tail, $bo);
    if ($block == "") {
        return $out;
    }
    $tail = $block;
    // Pass 1: enum / const values (always strings).
    $pm = [];
    $n = preg_match_all('/"([A-Za-z0-9_\-]+)"\s*:\s*\{[^{}]*?"(?:enum|const)"\s*:\s*'
        . '(\[[^\]]*\]|"(?:[^"\\\\]|\\\\.)*")/s', $tail, $pm);
    for ($i = 0; $i < $n; $i++) {
        $vals = [];
        $lit = $pm[2][$i];
        if (substr($lit, 0, 1) == "[") {
            $vals = api_list(substr($lit, 1, strlen($lit) - 2));
        } else {
            $sv = api_unesc(substr($lit, 1, strlen($lit) - 2));
            if ($sv != "") {
                $vals[] = $sv;
            }
        }
        if (count($vals) == 0) {
            continue;
        }
        // A quoted literal is a string enum; unquoted numbers and booleans stay raw (unquoted),
        // or the value would violate the schema's type.
        $numeric = 1;
        if (strpos($lit, "\"") !== false) {
            $numeric = 0;
        } else {
            for ($k = 0; $k < count($vals); $k++) {
                if (preg_match('/^-?[0-9]+(\.[0-9]+)?([eE][+-]?[0-9]+)?$/', $vals[$k]) != 1
                    && $vals[$k] != "true" && $vals[$k] != "false") {
                    $numeric = 0;
                }
            }
        }
        $combos = api_combine_add($combos, $pm[1][$i], $vals, $numeric);
        if (count($combos) > 16) {
            return [];
        }
        $used[] = $pm[1][$i];
    }
    // Pass 1.2: numeric const (unquoted). Numeric enums already flow through Pass 1 because
    // api_list keeps bare tokens; this pins the constant case to the same machinery.
    $cm = [];
    $nc = preg_match_all('/"([A-Za-z0-9_\-]+)"\s*:\s*\{[^{}]*?"const"\s*:\s*'
        . '(-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/s', $tail, $cm);
    for ($i = 0; $i < $nc; $i++) {
        $prop = $cm[1][$i];
        $dup = 0;
        for ($k = 0; $k < count($used); $k++) {
            if ($used[$k] == $prop) {
                $dup = 1;
            }
        }
        if ($dup == 1) {
            continue;
        }
        $combos = api_combine_add($combos, $prop, [$cm[2][$i]], 1);
        if (count($combos) > 16) {
            return [];
        }
        $used[] = $prop;
    }
    // Pass 1.2b: boolean const (unquoted true/false) is finite too.
    $bm = [];
    $nb = preg_match_all('/"([A-Za-z0-9_\-]+)"\s*:\s*\{[^{}]*?"const"\s*:\s*(true|false)/s',
        $tail, $bm);
    for ($i = 0; $i < $nb; $i++) {
        $prop = $bm[1][$i];
        $dup = 0;
        for ($k = 0; $k < count($used); $k++) {
            if ($used[$k] == $prop) {
                $dup = 1;
            }
        }
        if ($dup == 1) {
            continue;
        }
        $combos = api_combine_add($combos, $prop, [$bm[2][$i]], 1);
        if (count($combos) > 16) {
            return [];
        }
        $used[] = $prop;
    }
    // Pass 1.5: a fully anchored alternation `^(a|b|c)$` is finite too. General regexes are not
    // handled; anything else simply yields no candidates here.
    $rm = [];
    $r = preg_match_all('/"([A-Za-z0-9_\-]+)"\s*:\s*\{[^{}]*?"pattern"\s*:\s*"\^\(([^)]*)\)\$"/s',
        $tail, $rm);
    for ($i = 0; $i < $r; $i++) {
        $prop = $rm[1][$i];
        $dup = 0;
        for ($k = 0; $k < count($used); $k++) {
            if ($used[$k] == $prop) {
                $dup = 1;
            }
        }
        if ($dup == 1) {
            continue;
        }
        $vals = explode("|", $rm[2][$i]);
        if (count($vals) == 0 || count($vals) > 16) {
            continue;
        }
        $combos = api_combine_add($combos, $prop, $vals, 0);
        if (count($combos) > 16) {
            return [];
        }
        $used[] = $prop;
    }
    // Pass 2: booleans and bounded integers are finite too; unbounded numbers are not.
    $qm = [];
    $q = preg_match_all('/"([A-Za-z0-9_\-]+)"\s*:\s*(\{[^{}]*"type"\s*:\s*"(?:boolean|integer)"'
        . '[^{}]*\})/s', $tail, $qm);
    for ($i = 0; $i < $q; $i++) {
        $prop = $qm[1][$i];
        $dup = 0;
        for ($k = 0; $k < count($used); $k++) {
            if ($used[$k] == $prop) {
                $dup = 1;
            }
        }
        if ($dup == 1) {
            continue;
        }
        $obj = $qm[2][$i];
        $ty = api_str($obj, "type");
        $vals = [];
        if ($ty == "boolean") {
            $vals[] = "true";
            $vals[] = "false";
        } else {
            $mm = [];
            $xm = [];
            $lo = 0;
            $hi = 0;
            $haveLo = 0;
            $haveHi = 0;
            if (preg_match('/"minimum"\s*:\s*(-?[0-9]+)/', $obj, $mm)) {
                $lo = (int) $mm[1];
                $haveLo = 1;
            } else if (preg_match('/"exclusiveMinimum"\s*:\s*(-?[0-9]+)/', $obj, $mm)) {
                $lo = (int) $mm[1] + 1;
                $haveLo = 1;
            }
            if (preg_match('/"maximum"\s*:\s*(-?[0-9]+)/', $obj, $xm)) {
                $hi = (int) $xm[1];
                $haveHi = 1;
            } else if (preg_match('/"exclusiveMaximum"\s*:\s*(-?[0-9]+)/', $obj, $xm)) {
                $hi = (int) $xm[1] - 1;
                $haveHi = 1;
            }
            if ($haveLo == 0 || $haveHi == 0) {
                continue;
            }
            if ($hi < $lo || $hi - $lo > 15) {
                continue;
            }
            // multipleOf narrows a bounded integer to its multiples (the only unbounded-number
            // constraint we can enumerate safely).
            $mo = [];
            $mult = 0;
            if (preg_match('/"multipleOf"\s*:\s*([0-9]+)/', $obj, $mo)) {
                $mult = (int) $mo[1];
            }
            for ($v = $lo; $v <= $hi; $v++) {
                if ($mult > 1 && $v % $mult != 0) {
                    continue;
                }
                $vals[] = "" . $v;
            }
        }
        if (count($vals) == 0) {
            continue;
        }
        $combos = api_combine_add($combos, $prop, $vals, 1);
        if (count($combos) > 16) {
            return [];
        }
        $used[] = $prop;
    }
    if (count($combos) == 1 && $combos[0] == "") {
        return $out;
    }
    for ($i = 0; $i < count($combos); $i++) {
        $out[] = "{" . $combos[$i] . "}";
    }
    return $out;
}

// Candidates for a named tool: scan a window from the tool's name.
function api_schema_arg_candidates(string $body, string $tool): array
{
    $tp = strpos($body, "\"" . $tool . "\"");
    if ($tp === false) {
        return [];
    }
    return api_schema_candidates(substr($body, $tp, 1500));
}

// Structured outputs: response_format.json_schema.schema is itself the schema fragment.
function api_response_schema_candidates(string $body): array
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return [];
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return [];
    }
    // Root-level oneOf/anyOf over object branches: the constrained set is the union of the
    // branches' own candidate sets (finite branches only).
    $op = strpos($schema, "\"oneOf\"");
    if ($op === false) {
        $op = strpos($schema, "\"anyOf\"");
    }
    if ($op !== false) {
        $ob = strpos($schema, "[", $op);
        if ($ob !== false) {
            $arr = api_balance_square($schema, $ob);
            if ($arr != "") {
                $out = [];
                $inner = substr($arr, 1, strlen($arr) - 2);
                $pos = 0;
                $ok = 1;
                while ($pos < strlen($inner) && count($out) < 16) {
                    $bo = strpos($inner, "{", $pos);
                    if ($bo === false) {
                        break;
                    }
                    $branch = api_balance_block($inner, $bo);
                    if ($branch == "") {
                        break;
                    }
                    $bc = api_schema_candidates($branch);
                    if (count($bc) == 0) {
                        $ok = 0;
                        break;
                    }
                    for ($i = 0; $i < count($bc) && count($out) < 16; $i++) {
                        $out[] = $bc[$i];
                    }
                    $pos = $bo + strlen($branch);
                }
                if ($ok == 1 && count($out) > 0) {
                    return $out;
                }
            }
        }
    }
    return api_schema_candidates($schema);
}

// Vocabulary charset for a free-form string value: printable ASCII that cannot break out of a
// JSON string (no quote, no backslash, no control bytes).
function api_schema_charset(): string
{
    return "abcdefghijklmnopqrstuvwxyz ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 .,'-";
}

// A schema whose values are all free-form strings has no finite candidate set. Detect that shape
// (1..3 string properties, no enum/const/pattern) so the charset grammar can fill them.
function api_response_schema_string_props(string $body): array
{
    $out = [];
    if (preg_match('/"json_schema"/', $body) != 1) {
        return $out;
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return $out;
    }
    // Finite domains (enum/const/pattern) are handled by api_schema_candidates.
    if (preg_match('/"(enum|const|pattern)"\s*:/', $schema) == 1) {
        return $out;
    }
    if (api_schema_is_object($schema) != 1) {
        return $out;
    }
    $pp = strpos($schema, "\"properties\"");
    if ($pp === false) {
        return $out;
    }
    $bo = strpos($schema, "{", $pp);
    if ($bo === false) {
        return $out;
    }
    $block = api_balance_block($schema, $bo);
    if ($block == "") {
        return $out;
    }
    $plan = api_top_leaves($block, 3);
    if ($plan == "") {
        return $out;
    }
    $items = explode(",", $plan);
    for ($i = 0; $i < count($items); $i++) {
        $kv = explode(":", $items[$i]);
        if (count($kv) == 2 && $kv[1] == "s") {
            $out[] = $kv[0];
        }
    }
    return $out;
}

// Anthropic's tool call is a content block, not a message field: {"type":"tool_use",...}.
// Input is an object (empty until schema-aware decoding exists), and stop_reason says "tool_use".
function api_anthropic_tool_block(string $toolJson): string
{
    $name = api_str($toolJson, "name");
    if ($name == "") {
        return "";
    }
    $input = api_unesc(api_str($toolJson, "arguments"));
    if ($input == "" || api_json_ok($input) != 1) {
        $input = "{}";
    }
    return "{\"type\":\"tool_use\",\"id\":\"toolu_1\",\"name\":\"" . api_esc($name)
        . "\",\"input\":" . $input . "}";
}

function api_esc(string $s): string
{
    $s = str_replace("\\", "\\\\", $s);
    $s = str_replace("\"", "\\\"", $s);
    $s = str_replace("\n", "\\n", $s);
    $s = str_replace("\r", "", $s);
    $s = str_replace("\t", "\\t", $s);
    return $s;
}

// Decode generated ids to text. Control tokens are dropped by ID shape (their vocab strings are
// the <|...|> markers), and the byte-level space/newline symbols are turned back into whitespace.
function api_text(string $idsCsv): string
{
    $out = "";
    foreach (explode(",", $idsCsv) as $t) {
        $s = gguf_token_str((int) $t);
        if (strpos($s, "<|") !== false) {
            continue;
        }
        $s = str_replace("Ġ", " ", $s);
        $s = str_replace("Ċ", "\n", $s);
        $out = $out . $s;
    }
    return $out;
}

// Build the prompt ids for a whole conversation, in order. Works for both request shapes the
// two APIs use: OpenAI puts a system turn inside "messages", Anthropic puts a top-level
// "system" string. Rendering every turn (not just the last) is what makes multi-turn work.
// Tool declarations as the template wants them: one raw JSON object per line inside <tools>.
// The request body is not re-serialized (no JSON writer here); top-level objects of the "tools"
// array are sliced out with a small string/escape-aware scanner.
function api_tools_objects(string $body): string
{
    $tp = strpos($body, "\"tools\"");
    if ($tp === false) {
        return "";
    }
    $lb = strpos($body, "[", $tp);
    if ($lb === false) {
        return "";
    }
    $out = "";
    $depth = 0;
    $inStr = 0;
    $esc = 0;
    $objStart = 0;
    $n = strlen($body);
    for ($k = $lb; $k < $n; $k++) {
        $ch = substr($body, $k, 1);
        if ($inStr == 1) {
            if ($esc == 1) {
                $esc = 0;
            } else if ($ch == "\\") {
                $esc = 1;
            } else if ($ch == "\"") {
                $inStr = 0;
            }
            continue;
        }
        if ($ch == "\"") {
            $inStr = 1;
        } else if ($ch == "[" || $ch == "{") {
            $depth++;
            if ($depth == 2 && $ch == "{") {
                $objStart = $k;
            }
        } else if ($ch == "]" || $ch == "}") {
            if ($depth == 2 && $ch == "}") {
                $obj = substr($body, $objStart, $k - $objStart + 1);
                $out = $out == "" ? $obj : $out . "\n" . $obj;
            }
            $depth--;
            if ($depth == 0) {
                break;
            }
        }
    }
    return $out;
}

// The template's "# Tools" system section, built only for models whose own template has it.
function api_tools_prompt(string $body): string
{
    if (strpos(gguf_meta_str("tokenizer.chat_template"), "# Tools") === false) {
        return "";
    }
    $objs = api_tools_objects($body);
    if ($objs == "") {
        return "";
    }
    return "\n\n# Tools\n\nYou may call one or more functions to assist with the user query.\n\n"
         . "You are provided with function signatures within <tools></tools> XML tags:\n<tools>\n"
         . $objs . "\n</tools>\n\nFor each function call, return a json object with function name "
         . "and arguments within <tool_call></tool_call> XML tags:\n<tool_call>\n"
         . "{\"name\": <function-name>, \"arguments\": <args-json-object>}\n</tool_call>";
}

// The default system line the template injects when the caller gives none.
function api_default_system(): string
{
    return "You are Qwen, created by Alibaba Cloud. You are a helpful assistant.";
}

// The JSON object that follows "key": (string/escape aware), or "" when there is none.
function api_json_object_after(string $s, string $key): string
{
    $kp = strpos($s, "\"" . $key . "\"");
    if ($kp === false) {
        return "";
    }
    $lb = strpos($s, "{", $kp);
    if ($lb === false) {
        return "";
    }
    $depth = 0;
    $inStr = 0;
    $esc = 0;
    $n = strlen($s);
    for ($k = $lb; $k < $n; $k++) {
        $ch = substr($s, $k, 1);
        if ($inStr == 1) {
            if ($esc == 1) {
                $esc = 0;
            } else if ($ch == "\\") {
                $esc = 1;
            } else if ($ch == "\"") {
                $inStr = 0;
            }
            continue;
        }
        if ($ch == "\"") {
            $inStr = 1;
        } else if ($ch == "{") {
            $depth++;
        } else if ($ch == "}") {
            $depth--;
            if ($depth == 0) {
                return substr($s, $lb, $k - $lb + 1);
            }
        }
    }
    return "";
}

// All string contents of tool_result blocks in one message, in order. Explode instead of offsets:
// parallel tool results arrive as several blocks inside a single user message.
function api_tool_result_contents(string $part): array
{
    $out = [];
    $segs = explode("\"type\":\"tool_result\"", $part);
    for ($i = 1; $i < count($segs); $i++) {
        $cm = [];
        if (preg_match('/"content"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $segs[$i], $cm)) {
            if ($cm[1] != "") {
                $out[] = $cm[1];
            }
        } else {
            // Anthropic also allows content as [{"type":"text","text":...}] blocks.
            $am = [];
            if (preg_match('/"content"\s*:\s*\[(.*?)\]/s', $segs[$i], $am)) {
                $joined = "";
                $tsegs = explode("\"type\":\"text\"", $am[1]);
                for ($k = 1; $k < count($tsegs); $k++) {
                    $tm = [];
                    if (preg_match('/"text"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $tsegs[$k], $tm)) {
                        $joined = $joined == "" ? $tm[1] : $joined . "\n" . $tm[1];
                    }
                }
                if ($joined != "") {
                    $out[] = $joined;
                }
            }
        }
    }
    return $out;
}

// Consecutive tool results share ONE user turn in the model's template. Joining the contents at
// the block boundary and letting the single-block renderer wrap them yields exactly that.
function api_tool_results_group(array $contents): string
{
    $joined = "";
    for ($i = 0; $i < count($contents); $i++) {
        $joined = $i == 0 ? $contents[$i]
            : $joined . "\n</tool_response>\n<tool_response>\n" . $contents[$i];
    }
    return tok_tool_result($joined);
}

// All calls in one assistant message as flat [name, args, name, args, ...] pairs.
function api_tool_calls_of(string $part): array
{
    $out = [];
    $segs = explode("\"function\":{", $part);
    for ($i = 1; $i < count($segs); $i++) {
        $nm = api_str($segs[$i], "name");
        if ($nm == "") {
            continue;
        }
        $ar = api_unesc(api_str($segs[$i], "arguments"));
        if ($ar == "" || api_json_ok($ar) != 1) {
            $ar = "{}";
        }
        $out[] = $nm;
        $out[] = $ar;
    }
    return $out;
}

function api_anth_tool_calls_of(string $part): array
{
    $out = [];
    $segs = explode("\"type\":\"tool_use\"", $part);
    for ($i = 1; $i < count($segs); $i++) {
        $nm = api_str($segs[$i], "name");
        if ($nm == "") {
            continue;
        }
        $ar = api_json_object_after($segs[$i], "input");
        if ($ar == "") {
            $ar = "{}";
        }
        $out[] = $nm;
        $out[] = $ar;
    }
    return $out;
}

// One assistant turn with N <tool_call> blocks; the separator is unlikely inside a name/args.
function api_tool_calls_group(array $pairs): string
{
    $names = "";
    $args = "";
    for ($i = 0; $i + 1 < count($pairs); $i += 2) {
        $sep = $i == 0 ? "" : "@@TOOLCALL@@";
        $names = $names . $sep . $pairs[$i];
        $args = $args . $sep . $pairs[$i + 1];
    }
    if ($names == "") {
        return "";
    }
    return tok_tool_calls($names, $args);
}

// Anthropic allows "system" as a string or an array of text blocks. Blocks are joined with a
// newline (SDK convention); anything after "messages" is not part of the system field.
function api_system_text(string $body): string
{
    $s = api_str($body, "system");
    if ($s != "") {
        return $s;
    }
    // OpenAI Responses: "instructions" is the system prompt.
    $ins = api_str($body, "instructions");
    if ($ins != "") {
        return $ins;
    }
    // Array form: "system":[...] or (Responses) "instructions":[...].
    $sp = strpos($body, "\"system\"");
    if ($sp === false) {
        $sp = strpos($body, "\"instructions\"");
    }
    if ($sp === false) {
        return "";
    }
    $lb = strpos($body, "[", $sp);
    if ($lb === false) {
        return "";
    }
    $rest = substr($body, $lb);
    $mp = strpos($rest, "\"messages\"");
    if ($mp !== false) {
        $rest = substr($rest, 0, $mp);
    }
    $ip = strpos($rest, "\"input\"");
    if ($ip !== false) {
        $rest = substr($rest, 0, $ip);
    }
    $out = "";
    $segs = explode("\"type\":\"text\"", $rest);
    for ($i = 1; $i < count($segs); $i++) {
        $tm = [];
        if (preg_match('/"text"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $segs[$i], $tm)) {
            if ($tm[1] != "") {
                $out = $out == "" ? $tm[1] : $out . "\n" . $tm[1];
            }
        }
    }
    return $out;
}

function api_history(string $body): string
{
    $ids = "";
    // Declared tools go into the FIRST system turn, exactly where the template puts them.
    $tb = api_tools_prompt($body);
    $sysSeen = 0;
    $sys = api_system_text($body);
    if ($sys != "") {
        $ids = tok_chat("system", $sys . $tb);
        $sysSeen = 1;
    }
    $pat = '/"messages"\s*:\s*\[(.*)\]/s';
    $m = [];
    $block = preg_match($pat, $body, $m) ? $m[1] : "";
    if ($block == "") {
        // OpenAI Responses shape: "input" may be an array of message objects.
        $m2 = [];
        if (preg_match('/"input"\s*:\s*\[(.*)\]/s', $body, $m2)) {
            $block = $m2[1];
        }
    }
    if ($block == "") {
        return $ids;
    }
    // Splitting on the "role" key keeps the turns in order and avoids needing match offsets.
    $parts = explode("\"role\"", $block);
    $n = count($parts);
    $i = 1;
    while ($i < $n) {
        $rm = [];
        $role = preg_match('/^\s*:\s*"([a-z]+)"/', $parts[$i], $rm) ? $rm[1] : "user";
        if ($role == "developer") {
            // New OpenAI clients send "developer"; ChatML only has "system".
            $role = "system";
        }
        $cm = [];
        $content = preg_match('/"content"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $parts[$i], $cm) ? $cm[1] : "";
        $turn = "";
        $advance = 1;
        if ($role == "tool") {
            // Consecutive tool results are ONE user turn with several <tool_response> blocks.
            if ($content == "") {
                $i++;
                continue;
            }
            $batch = [$content];
            $j = $i + 1;
            while ($j < $n) {
                $rm2 = [];
                $role2 = preg_match('/^\s*:\s*"([a-z]+)"/', $parts[$j], $rm2) ? $rm2[1] : "user";
                if ($role2 != "tool") {
                    break;
                }
                $cm2 = [];
                $c2 = preg_match('/"content"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $parts[$j], $cm2) ? $cm2[1] : "";
                if ($c2 == "") {
                    break;
                }
                $batch[] = $c2;
                $j++;
            }
            $turn = api_tool_results_group($batch);
            $advance = $j - $i;
        } else if ($role == "assistant" && strpos($parts[$i], "\"tool_calls\"") !== false) {
            // An assistant turn that called tools carries the calls in tool_calls, not content.
            $tcalls = api_tool_calls_of($parts[$i]);
            if (count($tcalls) == 0) {
                $i++;
                continue;
            }
            $turn = api_tool_calls_group($tcalls);
        } else if (strpos($parts[$i], "\"type\":\"tool_use\"") !== false) {
            // Anthropic-shaped assistant turn: one or more tool_use blocks, args in "input".
            $acalls = api_anth_tool_calls_of($parts[$i]);
            if (count($acalls) == 0) {
                $i++;
                continue;
            }
            $turn = api_tool_calls_group($acalls);
        } else if (strpos($parts[$i], "\"type\":\"tool_result\"") !== false) {
            // Anthropic-shaped tool result blocks; parallel results live in the same message.
            $tres = api_tool_result_contents($parts[$i]);
            if (count($tres) == 0) {
                $i++;
                continue;
            }
            $turn = api_tool_results_group($tres);
        } else if (strpos($parts[$i], "\"type\":\"text\"") !== false) {
            // Anthropic-shaped plain text block.
            $ttext = api_str($parts[$i], "text");
            if ($ttext == "") {
                $i++;
                continue;
            }
            $turn = tok_chat($role, $ttext);
        } else {
            if ($content == "") {
                $i++;
                continue;
            }
            if ($role == "system" && $sysSeen == 0) {
                $turn = tok_chat($role, $content . $tb);
                $sysSeen = 1;
            } else {
                $turn = tok_chat($role, $content);
            }
        }
        $ids = $ids == "" ? $turn : $ids . "," . $turn;
        $i = $i + $advance;
    }
    if ($tb != "" && $sysSeen == 0) {
        // The template always emits a system turn; use its default persona plus the tools.
        $head = tok_chat("system", api_default_system() . $tb);
        $ids = $ids == "" ? $head : $head . "," . $ids;
    }
    return $ids;
}

// Serves POST /v1/chat/completions (non-streaming) and GET /v1/models. One request at a time,
// which is what a single-device engine can honestly promise.
function api_par_trace(string $line): void
{
    $tf = fopen("par_trace.log", "a");
    if ($tf !== false) {
        fwrite($tf, "" . microtime(true) . " " . $line . "
");
        fclose($tf);
    }
}

// Cooperative two-slot streaming server (FR-07/FR-18): every iteration accepts new connections
// and advances each active stream by ONE token, parking/resuming KV via the snapshot primitive.
// Scope is deliberately narrow - plain streaming text only (no tools/json/stop/n). The verified
// api_serve stays untouched; this exists to show a long stream no longer starves a short one.
function api_serve_par(int $port): void
{
    $srv = stream_socket_server("tcp://127.0.0.1:" . $port);
    if (!$srv) {
        echo "API_SOCK_FAIL\n";
        return;
    }
    stream_set_blocking($srv, false);
    echo "API_LISTEN " . $port . " (parallel: streaming text only)\n";
    echo "PAR loop start\n";
    $onA = 0;
    $onB = 0;
    $kA = 1;
    $kB = 1;
    $mxA = 0;
    $mxB = 0;
    $teA = 0.0;
    $teB = 0.0;
    $tpA = 0.0;
    $tpB = 0.0;
    $sdA = 0;
    $sdB = 0;
    $bgA = 0;
    $bgB = 0;
    $t0A = 0.0;
    $t0B = 0.0;
    $rsA = 0;
    $rsB = 0;
    $snA = 0;
    $snB = 0;
    $cur = -1;
    $stopA = "";
    $stopB = "";
    $holdA = "";
    $holdB = "";
    while (true) {
        // stream_select avoids calling accept() on an empty listener, which raises a PHP-level
        // "Accept failed" warning on every timeout. When nothing is readable, $c points at the
        // listener itself (same resource type) and the request block below is skipped.
        $rd = [$srv];
        $wr = [];
        $ex = [];
        $sel = stream_select($rd, $wr, $ex, 0, 1000);
        $c = ($sel !== false && $sel > 0) ? stream_socket_accept($srv, 0) : $srv;
        if ($c !== false && $c !== $srv) {
            // A socket accepted from a non-blocking listener is non-blocking too, so fread would
            // return "" before the body arrives. Make the client blocking with a timeout.
            stream_set_blocking($c, true);
            stream_set_timeout($c, 2);
            $req = fread($c, 32768);
            $hdrEnd = strpos($req, "\r\n\r\n");
            $wantLen = 0;
            $cm = [];
            if ($hdrEnd !== false && preg_match('/Content-Length:\s*([0-9]+)/i', $req, $cm)) {
                $wantLen = (int) $cm[1];
            }
            $guard = 0;
            while ($hdrEnd === false || strlen($req) < $hdrEnd + 4 + $wantLen) {
                if ($guard++ > 4) {
                    break;
                }
                $more = fread($c, 32768);
                if ($more == "") {
                    // The body may still be in flight when the headers arrived alone. Wait
                    // (bounded by $guard) instead of treating the request as complete - the same
                    // class of bug as F53 on the main server.
                    continue;
                }
                $req = $req . $more;
                $hdrEnd = strpos($req, "\r\n\r\n");
                if ($hdrEnd !== false && $wantLen == 0 && preg_match('/Content-Length:\s*([0-9]+)/i', $req, $cm)) {
                    $wantLen = (int) $cm[1];
                }
            }
            $body = $hdrEnd === false ? "" : substr($req, $hdrEnd + 4);
            if ($body != "" && strpos($req, "/v1/messages") !== false) {
                $body = api_anthropic_output_format($body);
            }
            if (strpos($body, '"$ref"') !== false) {
                $body = api_schema_resolve_refs($body);
            }
            if (strpos($body, '"allOf"') !== false) {
                $body = api_schema_merge_allof($body);
            }
            if (strpos($body, '"oneOf"') !== false || strpos($body, '"anyOf"') !== false) {
                $body = api_schema_flatten_choices($body);
            }
            api_par_trace("ACCEPT path=" . (strpos($req, "/v1/messages") !== false ? "anth" : "oai")
                . " len=" . strlen($body));
            if (strpos($req, "GET /health") !== false) {
                $payload = "{\"status\":\"ok\",\"model\":\"" . api_esc(gguf_meta_str("general.architecture")) . "\"}";
                fwrite($c, "HTTP/1.1 200 OK
Content-Type: application/json
Connection: close
"
                         . "Content-Length: " . strlen($payload) . "

" . $payload);
                fclose($c);
            } else if (strpos($req, "GET /v1/models") !== false) {
                $payload = api_models_json();
                fwrite($c, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nConnection: close\r\n"
                         . "Content-Length: " . strlen($payload) . "\r\n\r\n" . $payload);
                fclose($c);
            } else {
                $bad = 0;
                if (preg_match('/"stream"\s*:\s*true/', $body) != 1) {
                    $bad = 1;
                }
                if (strpos($body, "\"tools\"") !== false
                    || strpos($body, "\"tool_choice\"") !== false || strpos($body, "\"spec_k\"") !== false) {
                    $bad = 1;
                }
                if (preg_match('/"n"\s*:\s*[2-9]/', $body) == 1) {
                    $bad = 1;
                }
                $stopsV = api_stop_list($body);
                if (count($stopsV) > 1) {
                    $bad = 1;   // one stop sequence is supported in parallel mode
                }
                $stopV = count($stopsV) > 0 ? $stopsV[0] : "";
                $schemaCands = api_response_schema_candidates($body);
                $slot = -1;
                if ($bad == 0) {
                    $slot = $onA == 0 ? 0 : ($onB == 0 ? 1 : -1);
                }
                if ($bad == 1 || $slot < 0) {
                    $payload = $bad == 1
                        ? "{\"error\":{\"message\":\"parallel mode: streaming text only (no tools/json/n; one stop sequence max)\",\"type\":\"invalid_request_error\"}}"
                        : "{\"error\":{\"message\":\"parallel mode: two streams at a time\",\"type\":\"server_error\"}}";
                    $code = $bad == 1 ? "400 Bad Request" : "503 Service Unavailable";
                    fwrite($c, "HTTP/1.1 " . $code . "\r\nContent-Type: application/json\r\nConnection: close\r\n"
                             . "Content-Length: " . strlen($payload) . "\r\n\r\n" . $payload);
                    fclose($c);
                } else {
                    $ids = api_history($body);
                    if ($ids == "") {
                        $payload = "{\"error\":{\"message\":\"no user content\",\"type\":\"invalid_request_error\"}}";
                        fwrite($c, "HTTP/1.1 400 Bad Request\r\nContent-Type: application/json\r\nConnection: close\r\n"
                                 . "Content-Length: " . strlen($payload) . "\r\n\r\n" . $payload);
                        fclose($c);
                    } else {
                        $ids = $ids . "," . tok_chat_open("assistant");
                        $pn = count(explode(",", $ids));
                        $mxv = api_max_tokens(api_num($body, "max_tokens",
                            api_num($body, "max_completion_tokens", 64)));
                        $tev = api_float($body, "temperature", 0.0);
                        $tpv = api_float($body, "top_p", 1.0);
                        $sdv = api_num($body, "seed", 12345);
                        $bgv = api_num($body, "timeout_ms", 0);
                        $kind = strpos($req, "/v1/messages") !== false ? 2 : 1;
                        if ($cur >= 0) {
                            model_session_save($cur);
                        }
                        $hit = model_cache_prefill(0, $ids, api_cache_path($ids));
                        if ($hit < 0 && model_session_start(0, $ids) != 0) {
                            fclose($c);
                        } else {
                            model_session_seed($sdv);
                            $cur = $slot;
                            api_par_trace("START slot=" . $slot . " kind=" . $kind . " mx=" . $mxv);

                            if ($slot == 0) {
                                $cA = $c;
                                $onA = 1;
                                $kA = $kind;
                                $mxA = $mxv;
                                $teA = $tev;
                                $tpA = $tpv;
                                $bgA = $bgv;
                                $t0A = microtime(true);
                                $rsA = 0;
                                $snA = 0;
                                $stopA = $stopV;
                                $holdA = "";
                            } else {
                                $cB = $c;
                                $onB = 1;
                                $kB = $kind;
                                $mxB = $mxv;
                                $teB = $tev;
                                $tpB = $tpv;
                                $bgB = $bgv;
                                $t0B = microtime(true);
                                $rsB = 0;
                                $snB = 0;
                                $stopB = $stopV;
                                $holdB = "";
                            }
                            $modelId = api_esc(gguf_meta_str("general.architecture"));
                            fwrite($c, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\n"
                                     . "Cache-Control: no-cache\r\nConnection: close\r\n\r\n");
                            if ($kind == 2) {
                                fwrite($c, "event: message_start\ndata: {\"type\":\"message_start\",\"message\":"
                                         . "{\"id\":\"msg_1\",\"type\":\"message\",\"role\":\"assistant\","
                                         . "\"model\":\"" . $modelId . "\",\"content\":[],"
                                         . "\"usage\":{\"input_tokens\":" . $pn . ",\"output_tokens\":0}}}\n\n");
                                fwrite($c, "event: content_block_start\ndata: {\"type\":\"content_block_start\","
                                         . "\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n");
                            }
                            $scOut = "";
                            if (count($schemaCands) > 0) {
                                // Finite json_schema: one constrained pass, then a normal-looking
                                // stream. It does not occupy the slot afterwards.
                                $scOut = api_constrained($ids, $schemaCands, 128, 1);
                            } else {
                                // One-level nested string first (see the non-stream path).
                                $scArrN = api_response_schema_array_n_string($body);
    $scArrNI = api_response_schema_array_n_int($body);
                                $scArrNI = api_response_schema_array_n_int($body);
                                $scArr6 = "";
                                if ($scArrN == "") {
                                    $scArr6 = api_response_schema_array6_string($body);
                                }
                                $scArr5 = "";
                                if ($scArr6 == "") {
                                    $scArr5 = api_response_schema_array5_string($body);
                                }
                                $scArr4 = "";
                                if ($scArr5 == "") {
                                    $scArr4 = api_response_schema_array4_string($body);
                                }
                                $scArr3 = "";
                                if ($scArr4 == "") {
                                    $scArr3 = api_response_schema_array3_string($body);
                                }
                                $scArr2 = "";
                                if ($scArr4 == "" && $scArr3 == "") {
                                    // An element-level two-level array must win over the flat
                                    // top-level detector, which would match the inner "m" key.
                                    $scArr2e = api_response_schema_array_object_array2($body);
                                    if ($scArr2e == "") {
                                        $scArr2 = api_response_schema_array2_string($body);
                                    }
                                }
                                $scArrObj2 = "";
                                $scArrObjNum = "";
                                $scRootOneof = "";
                                $scArrObjArr = "";
                                if ($scArr3 == "" && $scArr2 == "") {
                                    $scArrObjArr = api_response_schema_array_object_array($body);
                                    $scArrObjNest = api_response_schema_array_object_nested($body);
                                    $scOneof = api_response_schema_oneof_grammar($body);
                                    $scRootOneof = api_response_schema_root_oneof_grammar($body);
                                    $scArrObjNum = api_response_schema_array_object_int_array($body);
                                    $scArrObj2 = api_response_schema_array_object_array2($body);
                                }
                                $scArrObj = "";
                                if ($scArrObjArr == "" && $scArr3 == "" && $scArr2 == "") {
                                    $scArrObj = api_response_schema_array_object($body);
                                }
                                $scNest = "";
                                if ($scArr2 == "" && $scArrObj == "") {
                                    $scNest = api_response_schema_nested_object($body);
                                }
                                $scArr = "";
                                if ($scArrObj == "" && $scNest == "") {
                                    $scArr = api_response_schema_array_string($body);
                                }
                                $scArrNum = "";
                                if ($scArrObj == "" && $scNest == "" && $scArr == "") {
                                    $scArrNum = api_response_schema_array_number($body);
                                }
                                $scMix = "";
                                if ($scArrObj == "" && $scNest == "" && $scArr == "" && $scArrNum == "") {
                                    $scMix = api_response_schema_mixed_object($body);
                                }
                                $scProp = [];
                                if ($scArrNI != "") {
                                    $sani2 = explode("|", $scArrNI);
                                    $scOut = api_array_n_int_value($ids, $sani2[0], (int) $sani2[1],
                                        2, 3, 1);
                                } else if (isset($scArrN) && $scArrN != "") {
                                    $sann = explode("|", $scArrN);
                                    $scOut = api_array_n_string_value($ids, $sann[0], (int) $sann[1],
                                        api_schema_charset(), 2, 3, 1);
                                } else if ($scArr6 != "") {
                                    $scOut = api_array6_string_value($ids, $scArr6,
                                        api_schema_charset(), 2, 2, 2, 2, 2, 2, 3, 1);
                                } else if ($scArr5 != "") {
                                    $scOut = api_array5_string_value($ids, $scArr5,
                                        api_schema_charset(), 2, 2, 2, 2, 2, 3, 1);
                                } else if ($scArr4 != "") {
                                    $scOut = api_array4_string_value($ids, $scArr4,
                                        api_schema_charset(), 2, 2, 2, 2, 3, 1);
                                } else if ($scArr3 != "") {
                                    $scOut = api_array3_string_value($ids, $scArr3,
                                        api_schema_charset(), 2, 2, 2, 4, 1);
                                } else if ($scArr2 != "") {
                                    $scOut = api_array2_string_value($ids, $scArr2,
                                        api_schema_charset(), 2, 2, 4, 1);
                                } else if ($scArrObj2 != "") {
                                    $sao2d = explode("|", $scArrObj2);
                                    $scOut = api_array_object_array2_value($ids, $sao2d[0], $sao2d[1],
                                        api_schema_charset(), 2, 4, 1);
                                } else if ($scArrObjNum != "") {
                                    $saoin = explode("|", $scArrObjNum);
                                    $scOut = api_array_object_int_array_value($ids, $saoin[0],
                                        $saoin[1], 2, 2, 3, 1);
                                } else if ($scArrObjArr != "") {
                                    $saoa = explode("|", $scArrObjArr);
                                    $scOut = api_array_object_array_value($ids, $saoa[0], $saoa[1],
                                        (isset($saoa[2]) ? $saoa[2] : ""), api_schema_charset(), 2, 6, 1);
                                } else if ($scArrObjNest != "") {
                                    $saon = explode("|", $scArrObjNest);
                                    $scOut = api_array_object_nested_value($ids, $saon[0], $saon[1],
                                        $saon[2], api_schema_charset(), 2, 6, 1);
                                } else if ($scArrObj != "") {
                                    $sao = explode("|", $scArrObj);
                                    $scOut = api_array_object_value($ids, $sao[0], $sao[1],
                                        api_schema_charset(), 3, 6, 1);
                                } else if ($scRootOneof != "") {
                                    $sro = explode("|", $scRootOneof);
                                    $scOut = api_root_oneof_grammar_value($ids, $sro[1],
                                        api_schema_charset(), 6, 1);
                                } else if ($scOneof != "") {
                                    $sof = explode("|", $scOneof);
                                    $scOut = api_oneof_grammar_value($ids, $sof[0], $sof[1],
                                        api_schema_charset(), 6, 1);
                                } else if ($scNest != "") {
                                    $snp = explode("|", $scNest);
                                    $scOut = api_nested_object_value($ids, $snp[0], $snp[1],
                                        api_schema_charset(), 8, 1);
                                } else if ($scArr != "") {
                                    $scOut = api_array_string_value($ids, $scArr,
                                        api_schema_charset(), 4, 6, 1);
                                } else if ($scArrNum != "") {
                                    $san = explode("|", $scArrNum);
                                    $scOut = api_array_number_value($ids, $san[0], (int) $san[1],
                                        4, 3, 1);
                                } else if ($scMix != "") {
                                    $scOut = api_object_mixed($ids, $scMix, api_schema_charset(), 16, 1);
                                } else if (api_response_schema_top_array_string($body) == 1) {
                                    $scOut = api_top_array_string_value($ids, api_schema_charset(),
                                        4, 4, 1);
                                } else if (api_response_schema_top_array_number($body) >= 0) {
                                    $scOut = api_top_array_number_value($ids,
                                        api_response_schema_top_array_number($body), 3, 3, 1);
                                } else if (api_response_schema_top_array_bool($body) == 1) {
                                    $scOut = api_constrained($ids, api_top_bool_candidates(), 8, 1);
                                } else if (api_response_schema_top_array_nested_object($body) != "") {
                                    $tson = explode("|",
                                        api_response_schema_top_array_nested_object($body));
                                    $scOut = api_top_array_nested_object_value($ids, $tson[0],
                                        $tson[1], api_schema_charset(), 2, 6, 1);
                                } else if (api_response_schema_top_array_object($body) != "") {
                                    $scOut = api_top_array_object_value($ids,
                                        api_response_schema_top_array_object($body),
                                        api_schema_charset(), 2, 6, 1);
                                } else {
                                    // Free-form string properties: the charset grammar fills them.
                                    $scProp = api_response_schema_string_props($body);
                                    if (count($scProp) > 0) {
                                        $scOut = api_string_value_multi($ids, $scProp,
                                            api_schema_charset(), 16, 1);
                                    } else {
                                        // Nested number first (see the non-stream path).
                                        $scNestNum = api_response_schema_nested_number($body);
                                        if ($scNestNum != "") {
                                            $snn = explode("|", $scNestNum);
                                            $scOut = api_number_nested($ids, $snn[0], $snn[1],
                                                (int) $snn[2], 6, 1);
                                        } else {
                                            // Unbounded multipleOf first; bounded multiples come from
                                            // api_schema_candidates and never reach this branch.
                                            $scMult = api_response_schema_multiple_unbounded($body);
                                            if ($scMult != "") {
                                                $smu = explode("|", $scMult);
                                                $scOut = api_multiple_value($ids, $smu[0], (int) $smu[1], 4, 1);
                                            } else {
                                                // Bare unbounded number: digits with an optional fraction.
                                                $scNum = api_response_schema_number_prop($body);
                                                if ($scNum != "") {
                                                    $sp2 = explode("|", $scNum);
                                                    $scOut = api_number_value($ids, $sp2[0], (int) $sp2[1], 6, 1);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                            if ($scOut != "") {
                                $scText = api_gen_text($scOut, $pn);
                                $scEsc = api_esc($scText);
                                $scGen = $scText == "" ? 0 : count(explode(",", $scOut)) - $pn;
                                if ($kind == 1) {
                                    fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                             . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                             . "\"delta\":{\"content\":\"" . $scEsc . "\"},"
                                             . "\"finish_reason\":null}]}\n\n");
                                    fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                             . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                             . "\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n");
                                    fwrite($c, "data: [DONE]\n\n");
                                } else {
                                    fwrite($c, "event: content_block_delta\ndata: {\"type\":\"content_block_delta\","
                                             . "\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\""
                                             . $scEsc . "\"}}\n\n");
                                    fwrite($c, "event: content_block_stop\ndata: "
                                             . "{\"type\":\"content_block_stop\",\"index\":0}\n\n");
                                    fwrite($c, "event: message_delta\ndata: {\"type\":\"message_delta\","
                                             . "\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":"
                                             . $scGen . "}}\n\n");
                                    fwrite($c, "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n");
                                }
                                echo "SESSION par schema slot=" . $slot . " bytes=" . strlen($scText) . "\n";
                                if ($slot == 0) {
                                    $onA = 0;
                                } else {
                                    $onB = 0;
                                }
                                fclose($c);
                            }
                        }
                    }
                }
            }
        }
        // One token per active session per iteration, parked/resumed through the KV snapshot.
        if ($onA == 1) {
            if ($cur != 0) {
                if ($cur >= 0) {
                    model_session_save($cur);
                }
                model_session_restore(0);
                $cur = 0;
            }
            if ($bgA > 0 && (microtime(true) - $t0A) * 1000.0 > $bgA) {
                $rsA = 3;
            } else if ($snA >= $mxA) {
                $rsA = 2;
            } else {
                $t = model_session_next(0, $teA, $tpA);
                if ($t < 0) {
                    $rsA = 1;
                } else {
                    $piece = api_text("" . $t);
                    $emitA = $piece;
                    if ($stopA != "") {
                        $holdA = $holdA . $piece;
                        $cutA = strpos($holdA, $stopA);
                        if ($cutA !== false) {
                            $emitA = substr($holdA, 0, $cutA);
                            $rsA = 5;
                        } else {
                            $keepA = strlen($stopA) - 1;
                            $emitA = strlen($holdA) > $keepA ? substr($holdA, 0, strlen($holdA) - $keepA) : "";
                            $holdA = $keepA > 0 ? substr($holdA, strlen($holdA) - $keepA) : "";
                        }
                    }
                    $w = 1;
                    if ($emitA != "") {
                        if ($kA == 1) {
                            $w = fwrite($cA, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                           . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                           . "\"delta\":{\"content\":\"" . api_esc($emitA) . "\"},"
                                           . "\"finish_reason\":null}]}\n\n");
                        } else {
                            $w = fwrite($cA, "event: content_block_delta\ndata: {\"type\":\"content_block_delta\","
                                           . "\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\""
                                           . api_esc($emitA) . "\"}}\n\n");
                        }
                    }
                    if ($w < 1) {
                        $rsA = 4;
                    } else if ($emitA != "") {
                        $snA++;
                        api_par_trace("STEP A n=" . $snA);
                    }
                }
            }
            if ($rsA != 0) {
                if ($rsA != 5 && $holdA != "") {
                    if ($kA == 1) {
                        fwrite($cA, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                  . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                  . "\"delta\":{\"content\":\"" . api_esc($holdA) . "\"},"
                                  . "\"finish_reason\":null}]}\n\n");
                    } else {
                        fwrite($cA, "event: content_block_delta\ndata: {\"type\":\"content_block_delta\","
                                  . "\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\""
                                  . api_esc($holdA) . "\"}}\n\n");
                    }
                    $snA++;
                }
                if ($kA == 1) {
                    $fin = ($rsA == 2 || $rsA == 3) ? "length" : "stop";
                    fwrite($cA, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                              . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                              . "\"delta\":{},\"finish_reason\":\"" . $fin . "\"}]}\n\n");
                    fwrite($cA, "data: [DONE]\n\n");
                } else {
                    $stopr = ($rsA == 5) ? "stop_sequence" : (($rsA == 2 || $rsA == 3) ? "max_tokens" : "end_turn");
                    fwrite($cA, "event: content_block_stop\ndata: {\"type\":\"content_block_stop\",\"index\":0}\n\n");
                    fwrite($cA, "event: message_delta\ndata: {\"type\":\"message_delta\","
                              . "\"delta\":{\"stop_reason\":\"" . $stopr . "\"},\"usage\":{\"output_tokens\":"
                              . $snA . "}}\n\n");
                    fwrite($cA, "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n");
                }
                $why = $rsA == 1 ? "eos" : ($rsA == 2 ? "max" : ($rsA == 3 ? "timeout" : ($rsA == 5 ? "stop" : "client-gone")));
                echo "SESSION par slot=A tokens=" . $snA . " reason=" . $why . "\n";
                fclose($cA);
                $onA = 0;
            }
        }
        if ($onB == 1) {
            if ($cur != 1) {
                if ($cur >= 0) {
                    model_session_save($cur);
                }
                model_session_restore(1);
                $cur = 1;
            }
            if ($bgB > 0 && (microtime(true) - $t0B) * 1000.0 > $bgB) {
                $rsB = 3;
            } else if ($snB >= $mxB) {
                $rsB = 2;
            } else {
                $t = model_session_next(0, $teB, $tpB);
                if ($t < 0) {
                    $rsB = 1;
                } else {
                    $piece = api_text("" . $t);
                    $emitB = $piece;
                    if ($stopB != "") {
                        $holdB = $holdB . $piece;
                        $cutB = strpos($holdB, $stopB);
                        if ($cutB !== false) {
                            $emitB = substr($holdB, 0, $cutB);
                            $rsB = 5;
                        } else {
                            $keepB = strlen($stopB) - 1;
                            $emitB = strlen($holdB) > $keepB ? substr($holdB, 0, strlen($holdB) - $keepB) : "";
                            $holdB = $keepB > 0 ? substr($holdB, strlen($holdB) - $keepB) : "";
                        }
                    }
                    $w = 1;
                    if ($emitB != "") {
                        if ($kB == 1) {
                            $w = fwrite($cB, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                           . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                           . "\"delta\":{\"content\":\"" . api_esc($emitB) . "\"},"
                                           . "\"finish_reason\":null}]}\n\n");
                        } else {
                            $w = fwrite($cB, "event: content_block_delta\ndata: {\"type\":\"content_block_delta\","
                                           . "\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\""
                                           . api_esc($emitB) . "\"}}\n\n");
                        }
                    }
                    if ($w < 1) {
                        $rsB = 4;
                    } else if ($emitB != "") {
                        $snB++;
                        api_par_trace("STEP B n=" . $snB);
                    }
                }
            }
            if ($rsB != 0) {
                if ($rsB != 5 && $holdB != "") {
                    if ($kB == 1) {
                        fwrite($cB, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                  . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                  . "\"delta\":{\"content\":\"" . api_esc($holdB) . "\"},"
                                  . "\"finish_reason\":null}]}\n\n");
                    } else {
                        fwrite($cB, "event: content_block_delta\ndata: {\"type\":\"content_block_delta\","
                                  . "\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\""
                                  . api_esc($holdB) . "\"}}\n\n");
                    }
                    $snB++;
                }
                if ($kB == 1) {
                    $fin = ($rsB == 2 || $rsB == 3) ? "length" : "stop";
                    fwrite($cB, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                              . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                              . "\"delta\":{},\"finish_reason\":\"" . $fin . "\"}]}\n\n");
                    fwrite($cB, "data: [DONE]\n\n");
                } else {
                    $stopr = ($rsB == 5) ? "stop_sequence" : (($rsB == 2 || $rsB == 3) ? "max_tokens" : "end_turn");
                    fwrite($cB, "event: content_block_stop\ndata: {\"type\":\"content_block_stop\",\"index\":0}\n\n");
                    fwrite($cB, "event: message_delta\ndata: {\"type\":\"message_delta\","
                              . "\"delta\":{\"stop_reason\":\"" . $stopr . "\"},\"usage\":{\"output_tokens\":"
                              . $snB . "}}\n\n");
                    fwrite($cB, "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n");
                }
                $why = $rsB == 1 ? "eos" : ($rsB == 2 ? "max" : ($rsB == 3 ? "timeout" : ($rsB == 5 ? "stop" : "client-gone")));
                echo "SESSION par slot=B tokens=" . $snB . " reason=" . $why . "\n";
                fclose($cB);
                $onB = 0;
            }
        }
        usleep(2000);
    }
}

function api_serve(int $port): void
{
    $srv = stream_socket_server("tcp://127.0.0.1:" . $port);
    if (!$srv) {
        echo "API_SOCK_FAIL\n";
        return;
    }
    echo "API_LISTEN " . $port . "\n";

    while (true) {
        // Same select-gating as the parallel slots: accept() on an idle listener raises a
        // PHP-layer "Accept failed" warning on every timeout.
        $rd = [$srv];
        $wr = [];
        $ex = [];
        $sel = stream_select($rd, $wr, $ex, 0, 1000);
        $c = ($sel !== false && $sel > 0) ? stream_socket_accept($srv, 0) : $srv;
        if ($c === false || $c === $srv) {
            continue;   // idle tick: stay serving
        }
        $req = fread($c, 32768);
        // Keep reading until the headers are complete AND the declared body has arrived. A single
        // fread can return just the headers; closing with unread body bytes makes Windows send
        // RST, which the client sees as a connection reset (observed with 4 concurrent clients).
        $hdrEnd = strpos($req, "\r\n\r\n");
        $wantLen = 0;
        $cm = [];
        if ($hdrEnd !== false && preg_match('/Content-Length:\s*([0-9]+)/i', $req, $cm)) {
            $wantLen = (int) $cm[1];
        }
        $guard = 0;
        while ($hdrEnd === false || strlen($req) < $hdrEnd + 4 + $wantLen) {
            if ($guard++ > 8) {
                break;                      // ~256 KB cap: refuse to grow without bound
            }
            $more = fread($c, 32768);
            if ($more == "") {
                break;
            }
            $req = $req . $more;
            $hdrEnd = strpos($req, "\r\n\r\n");
            if ($hdrEnd !== false && $wantLen == 0 && preg_match('/Content-Length:\s*([0-9]+)/i', $req, $cm)) {
                $wantLen = (int) $cm[1];
            }
        }
        $body = $hdrEnd === false ? "" : substr($req, $hdrEnd + 4);
        if ($body != "" && strpos($req, "/v1/messages") !== false) {
            $body = api_anthropic_output_format($body);
        }
        if (strpos($body, '"$ref"') !== false) {
            $body = api_schema_resolve_refs($body);
        }
        if (strpos($body, '"allOf"') !== false) {
            $body = api_schema_merge_allof($body);
        }
        if (strpos($body, '"oneOf"') !== false || strpos($body, '"anyOf"') !== false) {
            $body = api_schema_flatten_choices($body);
        }

        $payload = "";
        $ctype = "application/json";
        $status = "200 OK";
        // Local chat page on "/" (FR-09.2). Plain form + fetch, no build step, no CDN.
        if (strpos($req, "/v1/") === false && strpos($req, "GET /health") === false) {
            $ctype = "text/html; charset=utf-8";
            $payload = "<!doctype html><meta charset=utf-8><title>Kakashi</title>"
                . "<style>body{font:15px system-ui;max-width:46rem;margin:2rem auto;padding:0 1rem}"
                . "textarea{width:100%;height:5rem}#out{white-space:pre-wrap;margin-top:1rem}</style>"
                . "<h3>typephp-kakasi</h3><textarea id=q>The capital of France is</textarea>"
                . "<p><button onclick=go()>Send</button> <span id=s></span></p><div id=out></div>"
                . "<script>async function go(){const o=document.getElementById('out');"
                . "o.textContent='...';const r=await fetch('/v1/chat/completions',{method:'POST',"
                . "headers:{'Content-Type':'application/json'},body:JSON.stringify({messages:"
                . "[{role:'user',content:document.getElementById('q').value}],max_tokens:64})});"
                . "const j=await r.json();o.textContent=j.choices?j.choices[0].message.content:JSON.stringify(j);}"
                . "</script>";
        } else if (strpos($req, "GET /health") !== false) {
            // Liveness/readiness probe: cheap, no model work.
            $payload = "{\"status\":\"ok\",\"model\":\"" . api_esc(gguf_meta_str("general.architecture")) . "\"}";
        } else if (strpos($req, "/v1/models") !== false) {
            $mp = strpos($req, "/v1/models/");
            if ($mp !== false) {
                // GET /v1/models/{id}: 200 with the entry, or 404 when the id is unknown.
                $tail = substr($req, $mp + 11);
                $id = "";
                $tl = strlen($tail);
                for ($i = 0; $i < $tl; $i++) {
                    $ch = substr($tail, $i, 1);
                    if ($ch == " " || $ch == "?" || $ch == "
" || $ch == "
") {
                        break;
                    }
                    $id = $id . $ch;
                }
                if ($id != "" && strpos(api_models_json(), "\"id\":\"" . api_esc($id) . "\"") !== false) {
                    $payload = "{\"id\":\"" . api_esc($id) . "\",\"object\":\"model\",\"owned_by\":\"typephp-kakasi\"}";
                } else {
                    $payload = "{\"error\":{\"message\":\"model not found\",\"type\":\"invalid_request_error\"}}";
                    $status = "404 Not Found";
                }
            } else {
                $payload = api_models_json();
            }
        } else if (strpos($req, "/v1/completions") !== false
                   && preg_match('/"stream"\s*:\s*true/', $body) == 1) {
            $payload = "{\"error\":{\"message\":\"completions streaming is not supported\",\"type\":\"invalid_request_error\"}}";
            $status = "400 Bad Request";
        } else if (preg_match('/"stream"\s*:\s*true/', $body) == 1 && api_request_n($body) > 1) {
            // Streaming emits one choice (index 0); returning a single stream for n>1 silently
            // drops candidates, so reject the combination instead of pretending.
            $payload = "{\"error\":{\"message\":\"streaming supports one choice; use n=1\",\"type\":\"invalid_request_error\"}}";
            $status = "400 Bad Request";
        } else {
            // Responses API takes a plain "input" string; chat/completions takes "messages".
            $wantResponses = strpos($req, "/v1/responses") !== false;
            $wantCompletions = strpos($req, "/v1/completions") !== false;
            $ids = "";
            if ($wantCompletions) {
                // Legacy text completion: "prompt" is one user turn.
                $pr = api_str($body, "prompt");
                $ids = $pr == "" ? "" : tok_chat("user", $pr);
            } else if ($wantResponses) {
                $inp = api_str($body, "input");
                if ($inp == "") {
                    $ids = api_history($body);
                } else {
                    // String input still gets system/instructions and the tools catalog.
                    $rSys = api_system_text($body);
                    $rTools = api_tools_prompt($body);
                    if ($rSys == "" && $rTools == "") {
                        $ids = tok_chat("user", $inp);
                    } else if ($rSys == "") {
                        $ids = tok_chat("system", api_default_system() . $rTools)
                             . "," . tok_chat("user", $inp);
                    } else {
                        $ids = tok_chat("system", $rSys . $rTools) . "," . tok_chat("user", $inp);
                    }
                }
            } else {
                $ids = api_history($body);
            }
            if ($ids == "") {
                $payload = "{\"error\":{\"message\":\"no user content\",\"type\":\"invalid_request_error\"}}";
            } else {
                $maxTok = api_max_tokens(api_num($body, "max_tokens",
                    api_num($body, "max_completion_tokens",
                        api_num($body, "max_output_tokens", 64))));
                $temp = api_float($body, "temperature", 0.0);
                $seed = api_num($body, "seed", 12345);
                $nChoices = api_num($body, "n", 1);
                if ($nChoices < 1 || $nChoices > 4) {
                    $nChoices = 1;
                }
                $prefixCache = api_num($body, "prefix_cache", 1);
                $topp = api_float($body, "top_p", 1.0);
                // Open the assistant turn ONCE, before either path: without it the model keeps
                // writing the next user header, which is what the streamed chunks showed.
                $ids = $ids . "," . tok_chat_open("assistant");
                $promptN = count(explode(",", $ids));
                $pcPath = api_cache_path($ids);
                // Server-sent events: one chunk per token as soon as it exists. The engine keeps
                // the KV cache between calls, so a step costs one token, not a re-prefill.
                // Anthropic streams the same content as a different event sequence:
                // message_start / content_block_start / content_block_delta* / content_block_stop
                // / message_delta / message_stop, each with a named "event:" line.
                $anthStream = strpos($req, "/v1/messages") !== false;
                if (preg_match('/"stream"\s*:\s*true/', $body) && $anthStream) {
                    fwrite($c, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\n"
                             . "Cache-Control: no-cache\r\nConnection: close\r\n\r\n");
                    $modelId = api_esc(gguf_meta_str("general.architecture"));
                    $tStart = microtime(true);
                    $budgetMs = api_num($body, "timeout_ms", 0);
                    $reason = "eos";
                    $sent = 0;
                    // Anthropic streaming tool call: tool_use block start, one input_json_delta
                    // carrying the whole arguments object, and stop_reason "tool_use".
                    // Anthropic streaming tool_choice {"type":"any"}: constrain to a declared
                    // function and emit the tool_use SSE sequence. Failures fall through.
                    $anAny = preg_match('/"tool_choice"\s*:\s*\{\s*"type"\s*:\s*"any"/', $body) ? 1 : 0;
                    if ($anAny == 1) {
                        $anNames = api_tool_names($body);
                        $anSkels = [];
                        for ($ani = 0; $ani < count($anNames); $ani++) {
                            $anSkels[] = "{\"name\":\"" . $anNames[$ani] . "\",\"arguments\":{}}";
                        }
                        if (count($anSkels) > 0) {
                            $anOut = api_constrained($ids, $anSkels, 64, $prefixCache);
                            $anText = api_gen_text($anOut, $promptN);
                            $anPick = $anSkels[0];
                            for ($ani = 0; $ani < count($anSkels); $ani++) {
                                if (strpos($anText, $anSkels[$ani]) === 0) {
                                    $anPick = $anSkels[$ani];
                                }
                            }
                            $anCall = api_tool_call_json($anPick, $anNames);
                            $anName = $anCall == "" ? "" : api_str($anCall, "name");
                            if ($anName != "") {
                                $anArgs = api_unesc(api_str($anCall, "arguments"));
                                if ($anArgs == "") {
                                    $anArgs = "{}";
                                }
                                $anGen = count(explode(",", $anOut)) - $promptN;
                                if ($anGen < 0) {
                                    $anGen = 0;
                                }
                                fwrite($c, "event: message_start\ndata: {\"type\":\"message_start\",\"message\":"
                                         . "{\"id\":\"msg_1\",\"type\":\"message\",\"role\":\"assistant\","
                                         . "\"model\":\"" . $modelId . "\",\"content\":[],"
                                         . "\"usage\":{\"input_tokens\":" . $promptN . ",\"output_tokens\":0}}}\n\n");
                                fwrite($c, "event: content_block_start\ndata: {\"type\":\"content_block_start\","
                                         . "\"index\":0,\"content_block\":{\"type\":\"tool_use\",\"id\":\"toolu_1\","
                                         . "\"name\":\"" . api_esc($anName) . "\",\"input\":{}}}\n\n");
                                fwrite($c, "event: content_block_delta\ndata: {\"type\":\"content_block_delta\","
                                         . "\"index\":0,\"delta\":{\"type\":\"input_json_delta\","
                                         . "\"partial_json\":\"" . api_esc($anArgs) . "\"}}\n\n");
                                fwrite($c, "event: content_block_stop\ndata: "
                                         . "{\"type\":\"content_block_stop\",\"index\":0}\n\n");
                                fwrite($c, "event: message_delta\ndata: {\"type\":\"message_delta\","
                                         . "\"delta\":{\"stop_reason\":\"tool_use\"},\"usage\":{\"output_tokens\":"
                                         . $anGen . "}}\n\n");
                                fwrite($c, "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n");
                                echo "SESSION anth-stream tool=call reason=complete name=" . $anName
                                    . " mode=any\n";
                                fclose($c);
                                continue;
                            }
                        }
                    }
                    $aChoice = api_tool_choice($body);
                    if ($aChoice != "") {
                        $aNames = api_tool_names($body);
                        $aArgs0 = api_schema_arg_candidates($body, $aChoice);
                        $aSkels = [];
                        if (count($aArgs0) > 0) {
                            for ($i = 0; $i < count($aArgs0); $i++) {
                                $aSkels[] = "{\"name\":\"" . $aChoice . "\",\"arguments\":\""
                                          . api_esc($aArgs0[$i]) . "\"}";
                            }
                        } else {
                            $aSkels[] = "{\"name\":\"" . $aChoice . "\",\"arguments\":{}}";
                        }
                        $aOut = api_constrained($ids, $aSkels, 96, $prefixCache);
                        $aCall = api_tool_call_json(api_gen_text($aOut, $promptN), $aNames);
                        $aName = $aCall == "" ? "" : api_str($aCall, "name");
                        if ($aName == "") {
                            fwrite($c, "event: error\ndata: {\"type\":\"error\",\"error\":"
                                     . "{\"type\":\"api_error\",\"message\":\"tool constraint failed\"}}\n\n");
                            echo "SESSION anth-stream tool=failed reason=constraint\n";
                            fclose($c);
                            continue;
                        }
                        $aArgs = api_unesc(api_str($aCall, "arguments"));
                        if ($aArgs == "") {
                            $aArgs = "{}";
                        }
                        $aGen = count(explode(",", $aOut)) - $promptN;
                        if ($aGen < 0) {
                            $aGen = 0;
                        }
                        fwrite($c, "event: message_start\ndata: {\"type\":\"message_start\",\"message\":"
                                 . "{\"id\":\"msg_1\",\"type\":\"message\",\"role\":\"assistant\","
                                 . "\"model\":\"" . $modelId . "\",\"content\":[],"
                                 . "\"usage\":{\"input_tokens\":" . $promptN . ",\"output_tokens\":0}}}\n\n");
                        fwrite($c, "event: content_block_start\ndata: {\"type\":\"content_block_start\","
                                 . "\"index\":0,\"content_block\":{\"type\":\"tool_use\",\"id\":\"toolu_1\","
                                 . "\"name\":\"" . api_esc($aName) . "\",\"input\":{}}}\n\n");
                        fwrite($c, "event: content_block_delta\ndata: {\"type\":\"content_block_delta\","
                                 . "\"index\":0,\"delta\":{\"type\":\"input_json_delta\","
                                 . "\"partial_json\":\"" . api_esc($aArgs) . "\"}}\n\n");
                        fwrite($c, "event: content_block_stop\ndata: "
                                 . "{\"type\":\"content_block_stop\",\"index\":0}\n\n");
                        fwrite($c, "event: message_delta\ndata: {\"type\":\"message_delta\","
                                 . "\"delta\":{\"stop_reason\":\"tool_use\"},\"usage\":{\"output_tokens\":"
                                 . $aGen . "}}\n\n");
                        fwrite($c, "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n");
                        echo "SESSION anth-stream tool=call reason=complete name=" . $aName . "\n";
                        fclose($c);
                        continue;
                    }
                    // Structured output over the Messages stream: generate the constrained value
                    // first, then replay it as text deltas (grammar ran to completion above).
                    $anthJson = "";
                    if (strpos($body, '"json_schema"') !== false) {
                        $anthJson = api_schema_token_value($ids, $body, 0);
                    }
                    if ($anthJson != "") {
                        $ajText = api_gen_text($anthJson, $promptN);
                        $ajGen = count(explode(",", $anthJson)) - $promptN;
                        if ($ajGen < 0) {
                            $ajGen = 0;
                        }
                        fwrite($c, "event: message_start\ndata: {\"type\":\"message_start\",\"message\":"
                                 . "{\"id\":\"msg_1\",\"type\":\"message\",\"role\":\"assistant\","
                                 . "\"model\":\"" . $modelId . "\",\"content\":[],"
                                 . "\"usage\":{\"input_tokens\":" . $promptN . ",\"output_tokens\":0}}}\n\n");
                        fwrite($c, "event: content_block_start\ndata: {\"type\":\"content_block_start\","
                                 . "\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n");
                        $ajLeft = $ajText;
                        while ($ajLeft != "") {
                            $ajChunk = strlen($ajLeft) > 16 ? substr($ajLeft, 0, 16) : $ajLeft;
                            fwrite($c, "event: content_block_delta\ndata: {\"type\":\"content_block_delta\","
                                     . "\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\""
                                     . api_esc($ajChunk) . "\"}}\n\n");
                            $ajLeft = strlen($ajLeft) > 16 ? substr($ajLeft, 16) : "";
                        }
                        fwrite($c, "event: content_block_stop\ndata: "
                                 . "{\"type\":\"content_block_stop\",\"index\":0}\n\n");
                        fwrite($c, "event: message_delta\ndata: {\"type\":\"message_delta\","
                                 . "\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":"
                                 . $ajGen . "}}\n\n");
                        fwrite($c, "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n");
                        echo "SESSION anth-stream json reason=complete\n";
                        fclose($c);
                        continue;
                    }
                    $anthCacheHit = -1;
                    if ($prefixCache == 1) {
                        $anthCacheHit = model_cache_prefill(0, $ids, $pcPath);
                    }
                    if ($anthCacheHit < 0 && model_session_start(0, $ids) != 0) {
                        $reason = "start-failed";
                    } else {
                        model_session_seed($seed);
                        fwrite($c, "event: message_start\ndata: {\"type\":\"message_start\",\"message\":"
                                 . "{\"id\":\"msg_1\",\"type\":\"message\",\"role\":\"assistant\","
                                 . "\"model\":\"" . $modelId . "\",\"content\":[],"
                                 . "\"usage\":{\"input_tokens\":" . $promptN . ",\"output_tokens\":0}}}\n\n");
                        fwrite($c, "event: content_block_start\ndata: {\"type\":\"content_block_start\","
                                 . "\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n");
                        $astops = api_stop_list($body);
                        $amaxStop = 0;
                        for ($asi = 0; $asi < count($astops); $asi++) {
                            if (strlen($astops[$asi]) > $amaxStop) {
                                $amaxStop = strlen($astops[$asi]);
                            }
                        }
                        $ahold = "";
                        $aStopHit = 0;
                        for ($i = 0; $i < $maxTok; $i++) {
                            if ($budgetMs > 0 && (microtime(true) - $tStart) * 1000.0 > $budgetMs) {
                                $reason = "timeout";
                                break;
                            }
                            $tid = model_session_next(0, $temp, $topp);
                            if ($tid < 0) {
                                break;
                            }
                            $piece = api_text("" . $tid);
                            $aemit = $piece;
                            if (count($astops) > 0) {
                                $ahold = $ahold . $piece;
                                $acut = api_stop_cut($ahold, $astops);
                                if ($acut >= 0) {
                                    $aemit = substr($ahold, 0, $acut);
                                    $aStopHit = 1;
                                } else {
                                    $akeep = $amaxStop > 1 ? $amaxStop - 1 : 0;
                                    $aemit = strlen($ahold) > $akeep ? substr($ahold, 0, strlen($ahold) - $akeep) : "";
                                    $ahold = $akeep > 0 ? substr($ahold, strlen($ahold) - $akeep) : "";
                                }
                            }
                            if ($aemit != "") {
                                $wrote = fwrite($c, "event: content_block_delta\ndata: "
                                         . "{\"type\":\"content_block_delta\",\"index\":0,\"delta\":"
                                         . "{\"type\":\"text_delta\",\"text\":\"" . api_esc($aemit) . "\"}}\n\n");
                                if ($wrote < 1) {
                                    $reason = "client-gone";
                                    break;
                                }
                                $sent++;
                            }
                            if ($aStopHit == 1) {
                                $reason = "stop-seq";
                                break;
                            }
                        }
                        if ($aStopHit == 0 && $ahold != "") {
                            fwrite($c, "event: content_block_delta\ndata: "
                                     . "{\"type\":\"content_block_delta\",\"index\":0,\"delta\":"
                                     . "{\"type\":\"text_delta\",\"text\":\"" . api_esc($ahold) . "\"}}\n\n");
                            $sent++;
                        }
                        if ($reason == "eos" && $i >= $maxTok) {
                            $reason = "max-tokens";
                        }
                        fwrite($c, "event: content_block_stop\ndata: "
                                 . "{\"type\":\"content_block_stop\",\"index\":0}\n\n");
                        fwrite($c, "event: message_delta\ndata: {\"type\":\"message_delta\","
                                 . "\"delta\":{\"stop_reason\":\""
                                 . ($reason == "timeout" || $reason == "max-tokens" ? "max_tokens" : ($reason == "stop-seq" ? "stop_sequence" : "end_turn"))
                                 . "\"},\"usage\":{\"output_tokens\":" . $sent . "}}\n\n");
                        fwrite($c, "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n");
                        if (api_cache_should_store($anthCacheHit, $promptN) == 1) {
                            model_cache_store(0, $ids, $pcPath);
                        }
                    }
                    echo "SESSION anth-stream tokens=" . $sent . " reason=" . $reason
                        . " seed=" . $seed . " cache=" . $anthCacheHit . "\n";
                    fclose($c);
                    continue;
                }
                if (preg_match('/"stream"\s*:\s*true/', $body) && strpos($req, "/v1/messages") === false) {

                    fwrite($c, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\n"
                             . "Cache-Control: no-cache\r\nConnection: close\r\n\r\n");
                    $modelId = api_esc(gguf_meta_str("general.architecture"));
                    // Session lifecycle (FR-07.2): a streaming request can be preempted, so it
                    // carries a wall-clock budget and watches for a client that went away. The
                    // reason is logged, which is the "terminal order" record for this request.
                    $tStart = microtime(true);
                    $budgetMs = api_num($body, "timeout_ms", 0);
                    $reason = "eos";
                    $sent = 0;
                    // A named tool_choice is deterministic, so the stream can carry the call as
                    // tool_calls deltas. The constrained decode still runs: the guarantee that the
                    // model produced exactly this call must not be simulated.
                    // Streaming "required"/"any": constrain to one of the declared function
                    // skeletons and emit the call, mirroring the named-choice path. Additive:
                    // any failure falls through to the normal text stream below.
                    $qReq = preg_match('/"tool_choice"\s*:\s*"(required|any)"/', $body) ? 1 : 0;
                    if ($qReq == 1) {
                        $qNames = api_tool_names($body);
                        $qSkels = [];
                        for ($qi = 0; $qi < count($qNames); $qi++) {
                            $qSkels[] = "{\"name\":\"" . $qNames[$qi] . "\",\"arguments\":{}}";
                        }
                        if (count($qSkels) > 0) {
                            $qOut = api_constrained($ids, $qSkels, 64, $prefixCache);
                            $qText = api_gen_text($qOut, $promptN);
                            $qPick = $qSkels[0];
                            for ($qi = 0; $qi < count($qSkels); $qi++) {
                                if (strpos($qText, $qSkels[$qi]) === 0) {
                                    $qPick = $qSkels[$qi];
                                }
                            }
                            $qCall = api_tool_call_json($qPick, $qNames);
                            $qName = $qCall == "" ? "" : api_str($qCall, "name");
                            if ($qName != "") {
                                $qArgs = api_str($qCall, "arguments");
                                if ($qArgs == "") {
                                    $qArgs = "{}";
                                }
                                if ($wantResponses) {
                                    $quTok = count(explode(",", $qOut)) - $promptN;
                                    if ($quTok < 0) {
                                        $quTok = 0;
                                    }
                                    fwrite($c, api_resp_call_start($modelId, $qName));
                                    fwrite($c, api_resp_call_done($qName, $qArgs, $quTok,
                                        $modelId, $promptN));
                                } else {
                                    fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                             . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                             . "\"delta\":{\"role\":\"assistant\",\"content\":null,\"tool_calls\":["
                                             . "{\"index\":0,\"id\":\"call_1\",\"type\":\"function\","
                                             . "\"function\":{\"name\":\"" . api_esc($qName) . "\",\"arguments\":\"\"}}]},"
                                             . "\"finish_reason\":null}]}\n\n");
                                    fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                             . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                             . "\"delta\":{\"tool_calls\":[{\"index\":0,"
                                             . "\"function\":{\"arguments\":\"" . $qArgs . "\"}}]},"
                                             . "\"finish_reason\":null}]}\n\n");
                                    fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                             . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                             . "\"delta\":{},\"finish_reason\":\"tool_calls\"}]}\n\n");
                                    if (preg_match('/"include_usage"\s*:\s*true/', $body) == 1) {
                                        $quTok = count(explode(",", $qOut)) - $promptN;
                                        if ($quTok < 0) {
                                            $quTok = 0;
                                        }
                                        fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                                 . "\"model\":\"" . $modelId . "\",\"choices\":[],\"usage\":{"
                                                 . "\"prompt_tokens\":" . $promptN . ",\"completion_tokens\":" . $quTok
                                                 . ",\"total_tokens\":" . ($promptN + $quTok) . "}}\n\n");
                                    }
                                    fwrite($c, "data: [DONE]\n\n");
                                }
                                echo "SESSION stream tool=call reason=complete name=" . $qName
                                    . " mode=required\n";
                                fclose($c);
                                continue;
                            }
                        }
                    }
                    $sChoice = api_tool_choice($body);
                    if ($sChoice != "") {
                        $sNames = api_tool_names($body);
                        $sArgs0 = api_schema_arg_candidates($body, $sChoice);
                        $sSkels = [];
                        if (count($sArgs0) > 0) {
                            for ($i = 0; $i < count($sArgs0); $i++) {
                                $sSkels[] = "{\"name\":\"" . $sChoice . "\",\"arguments\":\""
                                          . api_esc($sArgs0[$i]) . "\"}";
                            }
                        } else {
                            $sSkels[] = "{\"name\":\"" . $sChoice . "\",\"arguments\":{}}";
                        }
                        $sOut = api_constrained($ids, $sSkels, 96, $prefixCache);
                        // api_constrained returns token ids: decode the generated part before
                        // handing it to the call parser.
                        $sText = api_gen_text($sOut, $promptN);
                        $sCall = $sText == "" ? "" : api_tool_call_json($sText, $sNames);
                        $sName = $sCall == "" ? "" : api_str($sCall, "name");
                        if ($sName == "") {
                            fwrite($c, "data: {\"error\":{\"message\":\"tool constraint failed\"}}\n\n");
                            echo "SESSION stream tool=failed reason=constraint cands=" . count($sArgs0)
                                . " out=" . strlen($sOut) . " call=" . strlen($sCall) . "\n";
                            fclose($c);
                            continue;
                        }
                        $sArgs = api_str($sCall, "arguments");
                        if ($sArgs == "") {
                            $sArgs = "{}";
                        }
                        if ($wantResponses) {
                            $suTok = count(explode(",", $sOut)) - $promptN;
                            if ($suTok < 0) {
                                $suTok = 0;
                            }
                            fwrite($c, api_resp_call_start($modelId, $sName));
                            fwrite($c, api_resp_call_done($sName, $sArgs, $suTok,
                                $modelId, $promptN));
                        } else {
                            fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                     . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                     . "\"delta\":{\"role\":\"assistant\",\"content\":null,\"tool_calls\":["
                                     . "{\"index\":0,\"id\":\"call_1\",\"type\":\"function\","
                                     . "\"function\":{\"name\":\"" . api_esc($sName) . "\",\"arguments\":\"\"}}]},"
                                     . "\"finish_reason\":null}]}\n\n");
                            fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                     . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                     . "\"delta\":{\"tool_calls\":[{\"index\":0,"
                                     . "\"function\":{\"arguments\":\"" . $sArgs . "\"}}]},"
                                     . "\"finish_reason\":null}]}\n\n");
                            fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                     . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                     . "\"delta\":{},\"finish_reason\":\"tool_calls\"}]}\n\n");
                            if (preg_match('/"include_usage"\s*:\s*true/', $body) == 1) {
                                $suTok = count(explode(",", $sOut)) - $promptN;
                                if ($suTok < 0) {
                                    $suTok = 0;
                                }
                                fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                         . "\"model\":\"" . $modelId . "\",\"choices\":[],\"usage\":{"
                                         . "\"prompt_tokens\":" . $promptN . ",\"completion_tokens\":" . $suTok
                                         . ",\"total_tokens\":" . ($promptN + $suTok) . "}}\n\n");
                            }
                            fwrite($c, "data: [DONE]\n\n");
                        }
                        echo "SESSION stream tool=call reason=complete name=" . $sName . "\n";
                        fclose($c);
                        continue;
                    }
                    // Structured outputs over SSE: finite candidates, or the same grammar
                    // fallbacks the non-streaming path uses (nested string, flat strings, number).
                    $sSchema = api_response_schema_candidates($body);
                    $sJOut = "";
                    if (count($sSchema) > 0) {
                        $sJOut = api_constrained($ids, $sSchema, 128, $prefixCache);
                    } else {
                        $sArrN = api_response_schema_array_n_string($body);
                        $sArrNI = api_response_schema_array_n_int($body);
                        $sArr6 = "";
                        if ($sArrN == "") {
                            $sArr6 = api_response_schema_array6_string($body);
                        }
                        $sArr5 = "";
                        if ($sArr6 == "") {
                            $sArr5 = api_response_schema_array5_string($body);
                        }
                        $sArr4 = "";
                        if ($sArr5 == "") {
                            $sArr4 = api_response_schema_array4_string($body);
                        }
                        $sArr3 = "";
                        if ($sArr4 == "") {
                            $sArr3 = api_response_schema_array3_string($body);
                        }
                        $sArr2 = "";
                        if ($sArr4 == "" && $sArr3 == "") {
                            $sArr2e = api_response_schema_array_object_array2($body);
                            if ($sArr2e == "") {
                                $sArr2 = api_response_schema_array2_string($body);
                            }
                        }
                        $sArrObj2 = "";
                        $sArrObjNum = "";
                        $sRootOneof = "";
                        $sArrObjArr = "";
                        if ($sArr3 == "" && $sArr2 == "") {
                            $sArrObjArr = api_response_schema_array_object_array($body);
                            $sArrObjNest = api_response_schema_array_object_nested($body);
                            $sOneof = api_response_schema_oneof_grammar($body);
                            $sRootOneof = api_response_schema_root_oneof_grammar($body);
                            $sArrObjNum = api_response_schema_array_object_int_array($body);
                            $sArrObj2 = api_response_schema_array_object_array2($body);
                        }
                        $sArrObj = "";
                        if ($sArrObjArr == "" && $sArr3 == "" && $sArr2 == "") {
                            $sArrObj = api_response_schema_array_object($body);
                        }
                        $sNest = "";
                        if ($sArr2 == "" && $sArrObj == "") {
                            $sNest = api_response_schema_nested_object($body);
                        }
                        $sArr = "";
                        if ($sArrObj == "" && $sNest == "") {
                            $sArr = api_response_schema_array_string($body);
                        }
                        $sArrNum = "";
                        if ($sArrObj == "" && $sNest == "" && $sArr == "") {
                            $sArrNum = api_response_schema_array_number($body);
                        }
                        $sMix = "";
                        if ($sArrObj == "" && $sNest == "" && $sArr == "" && $sArrNum == "") {
                            $sMix = api_response_schema_mixed_object($body);
                        }
                        if ($sArrNI != "") {
                            $sani3 = explode("|", $sArrNI);
                            $sJOut = api_array_n_int_value($ids, $sani3[0], (int) $sani3[1],
                                2, 3, $prefixCache);
                        } else if (isset($sArrN) && $sArrN != "") {
                            $sann2 = explode("|", $sArrN);
                            $sJOut = api_array_n_string_value($ids, $sann2[0], (int) $sann2[1],
                                api_schema_charset(), 2, 3, $prefixCache);
                        } else if ($sArr6 != "") {
                            $sJOut = api_array6_string_value($ids, $sArr6, api_schema_charset(), 2,
                                2, 2, 2, 2, 2, 3, $prefixCache);
                        } else if ($sArr5 != "") {
                            $sJOut = api_array5_string_value($ids, $sArr5, api_schema_charset(), 2,
                                2, 2, 2, 2, 3, $prefixCache);
                        } else if ($sArr4 != "") {
                            $sJOut = api_array4_string_value($ids, $sArr4, api_schema_charset(), 2,
                                2, 2, 2, 3, $prefixCache);
                        } else if ($sArr3 != "") {
                            $sJOut = api_array3_string_value($ids, $sArr3, api_schema_charset(), 2,
                                2, 2, 4, $prefixCache);
                        } else if ($sArr2 != "") {
                            $sJOut = api_array2_string_value($ids, $sArr2, api_schema_charset(), 2,
                                2, 4, $prefixCache);
                        } else if ($sArrObj2 != "") {
                            $sao2d2 = explode("|", $sArrObj2);
                            $sJOut = api_array_object_array2_value($ids, $sao2d2[0], $sao2d2[1],
                                api_schema_charset(), 2, 4, $prefixCache);
                        } else if ($sArrObjNum != "") {
                            $saoin2 = explode("|", $sArrObjNum);
                            $sJOut = api_array_object_int_array_value($ids, $saoin2[0],
                                $saoin2[1], 2, 2, 3, $prefixCache);
                        } else if ($sArrObjArr != "") {
                            $saoa2 = explode("|", $sArrObjArr);
                            $sOut = api_array_object_array_value($ids, $saoa2[0], $saoa2[1],
                                (isset($saoa2[2]) ? $saoa2[2] : ""), api_schema_charset(), 2, 6, 1);
                        } else if ($sArrObjNest != "") {
                            $saon2 = explode("|", $sArrObjNest);
                            $sOut = api_array_object_nested_value($ids, $saon2[0], $saon2[1],
                                $saon2[2], api_schema_charset(), 2, 6, 1);
                        } else if ($sArrObj != "") {
                            $sao2 = explode("|", $sArrObj);
                            $sJOut = api_array_object_value($ids, $sao2[0], $sao2[1],
                                api_schema_charset(), 3, 6, $prefixCache);
                        } else if ($sRootOneof != "") {
                            $sro2 = explode("|", $sRootOneof);
                            $sJOut = api_root_oneof_grammar_value($ids, $sro2[1],
                                api_schema_charset(), 6, $prefixCache);
                        } else if ($sOneof != "") {
                            $sof2 = explode("|", $sOneof);
                            $sJOut = api_oneof_grammar_value($ids, $sof2[0], $sof2[1],
                                api_schema_charset(), 6, $prefixCache);
                        } else if ($sNest != "") {
                            $snp = explode("|", $sNest);
                            $sJOut = api_nested_object_value($ids, $snp[0], $snp[1],
                                api_schema_charset(), 8, $prefixCache);
                        } else if ($sArr != "") {
                            $sJOut = api_array_string_value($ids, $sArr, api_schema_charset(), 4,
                                6, $prefixCache);
                        } else if ($sArrNum != "") {
                            $san2 = explode("|", $sArrNum);
                            $sJOut = api_array_number_value($ids, $san2[0], (int) $san2[1], 4, 3,
                                $prefixCache);
                        } else if ($sMix != "") {
                            $sJOut = api_object_mixed($ids, $sMix, api_schema_charset(), 16,
                                $prefixCache);
                        } else if (api_response_schema_top_array_string($body) == 1) {
                            $sJOut = api_top_array_string_value($ids, api_schema_charset(), 4, 4,
                                $prefixCache);
                        } else if (api_response_schema_top_array_number($body) >= 0) {
                            $sJOut = api_top_array_number_value($ids,
                                api_response_schema_top_array_number($body), 3, 3, $prefixCache);
                        } else if (api_response_schema_top_array_bool($body) == 1) {
                            $sJOut = api_constrained($ids, api_top_bool_candidates(), 8,
                                $prefixCache);
                        } else if (api_response_schema_top_array_nested_object($body) != "") {
                            $tson2 = explode("|",
                                api_response_schema_top_array_nested_object($body));
                            $sJOut = api_top_array_nested_object_value($ids, $tson2[0], $tson2[1],
                                api_schema_charset(), 2, 6, $prefixCache);
                        } else if (api_response_schema_top_array_object($body) != "") {
                            $sJOut = api_top_array_object_value($ids,
                                api_response_schema_top_array_object($body), api_schema_charset(),
                                2, 6, $prefixCache);
                        } else {
                            $sProps = api_response_schema_string_props($body);
                            if (count($sProps) > 0) {
                                $sJOut = api_string_value_multi($ids, $sProps, api_schema_charset(),
                                    16, $prefixCache);
                            } else {
                                $sNestNum = api_response_schema_nested_number($body);
                                if ($sNestNum != "") {
                                    $snn2 = explode("|", $sNestNum);
                                    $sJOut = api_number_nested($ids, $snn2[0], $snn2[1],
                                        (int) $snn2[2], 6, $prefixCache);
                                } else {
                                    $sMult = api_response_schema_multiple_unbounded($body);
                                    if ($sMult != "") {
                                        $smu2 = explode("|", $sMult);
                                        $sJOut = api_multiple_value($ids, $smu2[0], (int) $smu2[1], 4,
                                            $prefixCache);
                                    } else {
                                        $sNum = api_response_schema_number_prop($body);
                                        if ($sNum != "") {
                                            $snp2 = explode("|", $sNum);
                                            $sJOut = api_number_value($ids, $snp2[0], (int) $snp2[1], 6,
                                                $prefixCache);
                                        }
                                    }
                                }
                            }
                        }
                    }
                    if ($sJOut != "") {
                        $sJson = api_gen_text($sJOut, $promptN);
                        if ($sJson == "") {
                            fwrite($c, "data: {\"error\":{\"message\":\"schema constraint failed\"}}\n\n");
                            echo "SESSION stream json=failed reason=constraint\n";
                            fclose($c);
                            continue;
                        }
                        if ($wantResponses) {
                            $sjTok = count(explode(",", $sJOut)) - $promptN;
                            if ($sjTok < 0) {
                                $sjTok = 0;
                            }
                            fwrite($c, api_resp_start($modelId));
                            fwrite($c, api_resp_delta($sJson));
                            fwrite($c, api_resp_done($sJson, $sjTok, $modelId, $promptN));
                        } else {
                            fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                     . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                     . "\"delta\":{\"content\":\"" . api_esc($sJson) . "\"},"
                                     . "\"finish_reason\":null}]}\n\n");
                            fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                     . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                     . "\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n");
                            if (preg_match('/"include_usage"\s*:\s*true/', $body) == 1) {
                                $sjTok = count(explode(",", $sJOut)) - $promptN;
                                if ($sjTok < 0) {
                                    $sjTok = 0;
                                }
                                fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                         . "\"model\":\"" . $modelId . "\",\"choices\":[],\"usage\":{"
                                         . "\"prompt_tokens\":" . $promptN . ",\"completion_tokens\":" . $sjTok
                                         . ",\"total_tokens\":" . ($promptN + $sjTok) . "}}\n\n");
                            }
                            fwrite($c, "data: [DONE]\n\n");
                        }
                        echo "SESSION stream json=ok reason=complete\n";
                        fclose($c);
                        continue;
                    }
                    $sCacheHit = -1;
                    if ($prefixCache == 1) {
                        $sCacheHit = model_cache_prefill(0, $ids, $pcPath);
                    }
                    if ($sCacheHit < 0 && model_session_start(0, $ids) != 0) {
                        fwrite($c, "data: {\"error\":{\"message\":\"session start failed\"}}\n\n");
                        $reason = "start-failed";
                    } else {
                        model_session_seed($seed);
                        $respText = "";
                        if ($wantResponses) {
                            fwrite($c, api_resp_start($modelId));
                        }
                        $stops = api_stop_list($body);
                        $maxStop = 0;
                        for ($si = 0; $si < count($stops); $si++) {
                            if (strlen($stops[$si]) > $maxStop) {
                                $maxStop = strlen($stops[$si]);
                            }
                        }
                        $hold = "";
                        $stopHit = 0;
                        for ($i = 0; $i < $maxTok; $i++) {
                            if ($budgetMs > 0 && (microtime(true) - $tStart) * 1000.0 > $budgetMs) {
                                $reason = "timeout";
                                break;
                            }
                            $tid = model_session_next(0, $temp, $topp);
                            if ($tid < 0) {
                                break;      // eos: end the stream cleanly
                            }
                            $piece = api_text("" . $tid);
                            $emit = $piece;
                            if (count($stops) > 0) {
                                // Hold back (maxStop-1) chars so a stop spanning chunks never leaks.
                                $hold = $hold . $piece;
                                $cutAt = api_stop_cut($hold, $stops);
                                if ($cutAt >= 0) {
                                    $emit = substr($hold, 0, $cutAt);
                                    $stopHit = 1;
                                } else {
                                    $keep = $maxStop > 1 ? $maxStop - 1 : 0;
                                    $emit = strlen($hold) > $keep ? substr($hold, 0, strlen($hold) - $keep) : "";
                                    $hold = $keep > 0 ? substr($hold, strlen($hold) - $keep) : "";
                                }
                            }
                            if ($emit != "") {
                                $respText = $respText . $emit;
                                if ($wantResponses) {
                                    $wrote = fwrite($c, api_resp_delta($emit));
                                } else {
                                    $wrote = fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                             . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                             . "\"delta\":{\"content\":\"" . api_esc($emit) . "\"},"
                                             . "\"finish_reason\":null}]}\n\n");
                                }
                                if ($wrote < 1) {
                                    $reason = "client-gone";   // the peer closed: stop generating
                                    break;
                                }
                                $sent++;
                            }
                            if ($stopHit == 1) {
                                $reason = "stop-seq";
                                break;
                            }
                        }
                        if ($stopHit == 0 && $hold != "") {
                            $respText = $respText . $hold;
                            if ($wantResponses) {
                                $wrote = fwrite($c, api_resp_delta($hold));
                            } else {
                                fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                         . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                         . "\"delta\":{\"content\":\"" . api_esc($hold) . "\"},"
                                         . "\"finish_reason\":null}]}\n\n");
                            }
                            $sent++;
                        }
                        if ($reason == "eos" && $i >= $maxTok) {
                            $reason = "max-tokens";
                        }
                        $fin = ($reason == "timeout" || $reason == "max-tokens") ? "length" : "stop";
                        if ($wantResponses) {
                            fwrite($c, api_resp_done($respText, $sent, $modelId, $promptN));
                        } else {
                            fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                     . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                     . "\"delta\":{},\"finish_reason\":\"" . $fin . "\"}]}\n\n");
                            if (preg_match('/"include_usage"\s*:\s*true/', $body) == 1) {
                                fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                         . "\"model\":\"" . $modelId . "\",\"choices\":[],\"usage\":{"
                                         . "\"prompt_tokens\":" . $promptN . ",\"completion_tokens\":" . $sent
                                         . ",\"total_tokens\":" . ($promptN + $sent) . "}}\n\n");
                            }
                            fwrite($c, "data: [DONE]\n\n");
                        }
                        if (api_cache_should_store($sCacheHit, $promptN) == 1) {
                            model_cache_store(0, $ids, $pcPath);
                        }
                    }
                    echo "SESSION stream tokens=" . $sent . " reason=" . $reason
                        . " seed=" . $seed . " cache=" . $sCacheHit . "\n";
                    fclose($c);
                    continue;   // the streamed response is complete
                }
                // JSON-object mode (FR-08.2 / FR-09.1). Validation, not constrained decoding:
                // the model is free to emit anything, so a broken shape earns exactly one retry
                // and the outcome is logged rather than hidden.
                $jsonMode = preg_match('/"response_format"\s*:\s*\{\s*"type"\s*:\s*"json_object"/', $body) ? 1 : 0;
                if ($jsonMode == 0) {
                    // Responses spells the same request as `text.format`; without it the outlet skipped
                    // validation entirely and returned fenced code text (F184).
                    $jsonMode = preg_match('/"format"\s*:\s*\{\s*"type"\s*:\s*"json_object"/', $body) ? 1 : 0;
                }
                // Structured outputs: a finite json_schema becomes a constrained object set.
                $schemaCands = api_response_schema_candidates($body);
                if (count($schemaCands) > 0) {
                    $jsonMode = 1;
                }
                // enum/const constraint (non-streaming): decode only inside the listed answers.
                $enumOut = "";
                if (count($schemaCands) > 0) {
                    $enumOut = api_constrained($ids, $schemaCands, 128, $prefixCache);
                } else if (preg_match('/"enum"\s*:\s*\[(.*?)\]/s', $body, $em)) {
                    $evals = api_list($em[1]);
                    if (count($evals) > 0) {
                        $enumOut = api_constrained($ids, $evals, 24, $prefixCache);
                    }
                } else {
                    // One-level nested string first: the flat detector would otherwise mistake
                    // the inner property for a top-level one.
                    $arrN = api_response_schema_array_n_string($body);
                    $arrNI = api_response_schema_array_n_int($body);
                    $arr6 = "";
                    if ($arrN == "") {
                        $arr6 = api_response_schema_array6_string($body);
                    }
                    $arr5 = "";
                    if ($arr6 == "") {
                        $arr5 = api_response_schema_array5_string($body);
                    }
                    $arr4 = "";
                    if ($arr5 == "") {
                        $arr4 = api_response_schema_array4_string($body);
                    }
                    $arr3 = "";
                    if ($arr4 == "") {
                        $arr3 = api_response_schema_array3_string($body);
                    }
                    $arrArr = "";
                    if ($arr4 == "" && $arr3 == "") {
                        $arrArrE = api_response_schema_array_object_array2($body);
                        if ($arrArrE == "") {
                            $arrArr = api_response_schema_array2_string($body);
                        }
                    }
                    $arrObjArr = "";
                    if ($arr3 == "" && $arrArr == "") {
                        $arrObjArr = api_response_schema_array_object_array($body);
                    }
                    $arrObjNest = "";
                    if ($arrObjArr == "" && $arr3 == "" && $arrArr == "") {
                        $arrObjNest = api_response_schema_array_object_nested($body);
                    }
                    $arrObj = "";
                    if ($arrObjArr == "" && $arrObjNest == "" && $arr3 == "" && $arrArr == "") {
                        $arrObj = api_response_schema_array_object($body);
                    }
                    $arrObj2 = "";
                    $arrObjNum = "";
                    $arrRootOneof = "";
                    $arrOneof = "";
                    if ($arrObjArr == "" && $arrObjNest == "" && $arr3 == "" && $arrArr == "") {
                        $arrOneof = api_response_schema_oneof_grammar($body);
                        $arrRootOneof = api_response_schema_root_oneof_grammar($body);
                        $arrObjNum = api_response_schema_array_object_int_array($body);
                        $arrObj2 = api_response_schema_array_object_array2($body);
                    }
                    $nestProp = "";
                    if ($arrArr == "" && $arrObj == "") {
                        $nestProp = api_response_schema_nested_object($body);
                    }
                    $arrProp = "";
                    if ($arrObj == "" && $nestProp == "") {
                        $arrProp = api_response_schema_array_string($body);
                    }
                    $arrNum = "";
                    if ($arrObj == "" && $nestProp == "" && $arrProp == "") {
                        $arrNum = api_response_schema_array_number($body);
                    }
                    $mixPlan = "";
                    if ($arrObj == "" && $nestProp == "" && $arrProp == "" && $arrNum == "") {
                        $mixPlan = api_response_schema_mixed_object($body);
                    }
                    $strProps = [];
                    if ($arrNI != "") {
                        $sani4 = explode("|", $arrNI);
                        $enumOut = api_array_n_int_value($ids, $sani4[0], (int) $sani4[1],
                            2, 3, $prefixCache);
                        $jsonMode = 1;
                    } else if (isset($arrN) && $arrN != "") {
                        $aann = explode("|", $arrN);
                        $enumOut = api_array_n_string_value($ids, $aann[0], (int) $aann[1],
                            api_schema_charset(), 2, 3, $prefixCache);
                        $jsonMode = 1;
                    } else if ($arr6 != "") {
                        $enumOut = api_array6_string_value($ids, $arr6, api_schema_charset(), 2, 2,
                            2, 2, 2, 2, 3, $prefixCache);
                        $jsonMode = 1;
                    } else if ($arr5 != "") {
                        $enumOut = api_array5_string_value($ids, $arr5, api_schema_charset(), 2, 2,
                            2, 2, 2, 3, $prefixCache);
                        $jsonMode = 1;
                    } else if ($arr4 != "") {
                        $enumOut = api_array4_string_value($ids, $arr4, api_schema_charset(), 2, 2,
                            2, 2, 3, $prefixCache);
                        $jsonMode = 1;
                    } else if ($arr3 != "") {
                        $enumOut = api_array3_string_value($ids, $arr3, api_schema_charset(), 2, 2, 2,
                            4, $prefixCache);
                        $jsonMode = 1;
                    } else if ($arrArr != "") {
                        $enumOut = api_array2_string_value($ids, $arrArr, api_schema_charset(), 2, 2,
                            4, $prefixCache);
                        $jsonMode = 1;
                    } else if ($arrObj2 != "") {
                        $ao2d = explode("|", $arrObj2);
                        $enumOut = api_array_object_array2_value($ids, $ao2d[0], $ao2d[1],
                            api_schema_charset(), 2, 4, $prefixCache);
                        $jsonMode = 1;
                    } else if ($arrObjNum != "") {
                        $aoin = explode("|", $arrObjNum);
                        $enumOut = api_array_object_int_array_value($ids, $aoin[0],
                            $aoin[1], 2, 2, 3, $prefixCache);
                        $jsonMode = 1;
                    } else if ($arrObjArr != "") {
                        $aoa = explode("|", $arrObjArr);
                        $enumOut = api_array_object_array_value($ids, $aoa[0], $aoa[1],
                            (isset($aoa[2]) ? $aoa[2] : ""), api_schema_charset(), 2, 6, 1);
                        $jsonMode = 1;
                    } else if ($arrObjNest != "") {
                        $aon = explode("|", $arrObjNest);
                        $enumOut = api_array_object_nested_value($ids, $aon[0], $aon[1],
                            $aon[2], api_schema_charset(), 2, 6, 1);
                        $jsonMode = 1;
                    } else if ($arrObj != "") {
                        $aop = explode("|", $arrObj);
                        $enumOut = api_array_object_value($ids, $aop[0], $aop[1],
                            api_schema_charset(), 3, 6, $prefixCache);
                        $jsonMode = 1;
                    } else if ($arrRootOneof != "") {
                        $aro = explode("|", $arrRootOneof);
                        $enumOut = api_root_oneof_grammar_value($ids, $aro[1],
                            api_schema_charset(), 6, $prefixCache);
                        $jsonMode = 1;
                    } else if ($arrOneof != "") {
                        $aof = explode("|", $arrOneof);
                        $enumOut = api_oneof_grammar_value($ids, $aof[0], $aof[1],
                            api_schema_charset(), 6, $prefixCache);
                        $jsonMode = 1;
                    } else if ($nestProp != "") {
                        $npp = explode("|", $nestProp);
                        $enumOut = api_nested_object_value($ids, $npp[0], $npp[1],
                            api_schema_charset(), 8, $prefixCache);
                        $jsonMode = 1;
                    } else if ($arrProp != "") {
                        $enumOut = api_array_string_value($ids, $arrProp, api_schema_charset(), 4, 6,
                            $prefixCache);
                        $jsonMode = 1;
                    } else if ($arrNum != "") {
                        $anp = explode("|", $arrNum);
                        $enumOut = api_array_number_value($ids, $anp[0], (int) $anp[1], 4, 3,
                            $prefixCache);
                        $jsonMode = 1;
                    } else if ($mixPlan != "") {
                        $enumOut = api_object_mixed($ids, $mixPlan, api_schema_charset(), 16,
                            $prefixCache);
                        $jsonMode = 1;
                    } else if (api_response_schema_top_array_string($body) == 1) {
                        $enumOut = api_top_array_string_value($ids, api_schema_charset(), 4, 4,
                            $prefixCache);
                        $jsonMode = 1;
                    } else if (api_response_schema_top_array_number($body) >= 0) {
                        $enumOut = api_top_array_number_value($ids,
                            api_response_schema_top_array_number($body), 3, 3, $prefixCache);
                        $jsonMode = 1;
                    } else if (api_response_schema_top_array_bool($body) == 1) {
                        $enumOut = api_constrained($ids, api_top_bool_candidates(), 8,
                            $prefixCache);
                        $jsonMode = 1;
                    } else if (api_response_schema_top_array_nested_object($body) != "") {
                        $tonp = explode("|", api_response_schema_top_array_nested_object($body));
                        $enumOut = api_top_array_nested_object_value($ids, $tonp[0], $tonp[1],
                            api_schema_charset(), 2, 6, $prefixCache);
                        $jsonMode = 1;
                    } else if (api_response_schema_top_array_object($body) != "") {
                        $enumOut = api_top_array_object_value($ids,
                            api_response_schema_top_array_object($body), api_schema_charset(), 2, 6,
                            $prefixCache);
                        $jsonMode = 1;
                    } else {
                        // Free-form string properties: the charset grammar fills each value, and
                        // the forced quotes/separators/brace keep the object well-formed.
                        $strProps = api_response_schema_string_props($body);
                        if (count($strProps) > 0) {
                            $enumOut = api_string_value_multi($ids, $strProps, api_schema_charset(),
                                16, $prefixCache);
                            $jsonMode = 1;
                        } else {
                            // Nested number first: its inner property would otherwise match the
                            // flat number detector and lose the outer object.
                            $nestNum = api_response_schema_nested_number($body);
                            if ($nestNum != "") {
                                $nnp = explode("|", $nestNum);
                                $enumOut = api_number_nested($ids, $nnp[0], $nnp[1], (int) $nnp[2],
                                    6, $prefixCache);
                                $jsonMode = 1;
                            } else {
                                // An unbounded integer with multipleOf: digit prefix plus a forced
                                // divisible suffix, so the value is always a valid multiple.
                                $numMult = api_response_schema_multiple_unbounded($body);
                                if ($numMult != "") {
                                    $nmu = explode("|", $numMult);
                                    $enumOut = api_multiple_value($ids, $nmu[0], (int) $nmu[1], 4,
                                        $prefixCache);
                                    $jsonMode = 1;
                                } else {
                                    // A bare unbounded integer/number: digits (and an optional
                                    // fraction), so the value is always a valid JSON number.
                                    $numProp = api_response_schema_number_prop($body);
                                    if ($numProp != "") {
                                        $np = explode("|", $numProp);
                                        $enumOut = api_number_value($ids, $np[0], (int) $np[1], 6, $prefixCache);
                                        $jsonMode = 1;
                                    }
                                }
                            }
                        }
                    }
                }
                // Tool calls: a named tool_choice is turned into a constrained skeleton, so the
                // call cannot come out malformed; otherwise we parse whatever the model wrote.
                $toolNames = api_tool_names($body);
                $toolChoice = api_tool_choice($body);
                // OpenAI string forms: "none" forbids calls, "required"/"any" force one.
                $tcNone = preg_match('/"tool_choice"\s*:\s*"none"/', $body) ? 1 : 0;
                $tcRequired = preg_match('/"tool_choice"\s*:\s*"(required|any)"/', $body) ? 1 : 0;
                $toolSkel = "";
                $argCands = [];
                if ($toolChoice == "" && $tcRequired == 1 && count($toolNames) > 0) {
                    $reqSkels = [];
                    for ($ri = 0; $ri < count($toolNames); $ri++) {
                        $reqSkels[] = "{\"name\":\"" . $toolNames[$ri] . "\",\"arguments\":{}}";
                    }
                    $enumOut = api_constrained($ids, $reqSkels, 64, $prefixCache);
                }
                if ($toolChoice != "") {
                    // Flat enum/const properties give real arguments; otherwise the object stays {}.
                    $argCands = api_schema_arg_candidates($body, $toolChoice);
                    if (count($argCands) > 0) {
                        $skels = [];
                        for ($i = 0; $i < count($argCands); $i++) {
                            $skels[] = "{\"name\":\"" . $toolChoice . "\",\"arguments\":\""
                                     . api_esc($argCands[$i]) . "\"}";
                        }
                        $enumOut = api_constrained($ids, $skels, 96, $prefixCache);
                    } else {
                        $toolSkel = "{\"name\":\"" . $toolChoice . "\",\"arguments\":{}}";
                        $enumOut = api_constrained($ids, [$toolSkel], 64, $prefixCache);
                    }
                }
                $toolJson = "";
                if ($enumOut != "" && ($toolSkel != "" || count($argCands) > 0)) {
                    $toolJson = api_tool_call_json($enumOut, $toolNames);
                }
                $tries = $enumOut != "" ? 1 : ($jsonMode ? 2 : 1);
                // Draft length for speculative decoding; 0 = plain decoding. Greedy only, since
                // acceptance is defined against the model's own argmax.
                $specK = api_num($body, "spec_k", 0);
                $out = "";
                $text = "";
                $genN = 0;
                $cacheHit = -1;
                $jsonOk = $jsonMode ? 0 : 1;
                $stopCut = 0;
                for ($attempt = 0; $attempt < $tries; $attempt++) {
                    if ($enumOut != "") {
                        $out = $enumOut;
                        $jsonOk = 1;      // the constraint already decided the tokens
                    } else if ($specK > 0 && $temp <= 0.0) {
                        $out = model_spec_generate(0, $ids, $maxTok, $specK);
                    } else if ($jsonMode == 0 && $prefixCache == 1) {
                        // Reuse the SSD prefix cache: cache_prefill leaves the session at the
                        // prompt tail, so decoding (greedy or sampled) continues from there.
                                $cacheHit = model_cache_prefill(0, $ids, $pcPath);
                        if ($cacheHit < 0) {
                            $out = $temp > 0.0
                                ? model_generate_sampled(0, $ids, $maxTok, $temp, $topp, $seed)
                                : model_generate(0, $ids, $maxTok);
                        } else {
                            model_session_seed($seed);
                            $genIds = "";
                            for ($ci = 0; $ci < $maxTok; $ci++) {
                                $tid = model_session_next(0, $temp, $topp);
                                if ($tid < 0) {
                                    break;
                                }
                                $genIds = $genIds == "" ? "" . $tid : $genIds . "," . $tid;
                            }
                            $out = $genIds == "" ? $ids : $ids . "," . $genIds;
                            // Only pay the write when a later request could reuse enough of it.
                            if (api_cache_should_store($cacheHit, $promptN) == 1) {
                                model_cache_store(0, $ids, $pcPath);
                            }
                        }
                    } else {
                        $out = $temp > 0.0
                            ? model_generate_sampled(0, $ids, $maxTok, $temp, $topp, $seed)
                            : model_generate(0, $ids, $maxTok);
                    }
                    if ($out == "") {
                        break;
                    }
                    $parts = explode(",", $out);
                    $genN = count($parts) - $promptN;
                    $gen = "";
                    for ($i = $promptN; $i < count($parts); $i++) {
                        $gen = $i == $promptN ? $parts[$i] : $gen . "," . $parts[$i];
                    }
                    $text = $gen == "" ? "" : api_text($gen);
                    if ($jsonMode == 0) {
                        break;
                    }
                    $jsonOk = api_json_ok($text);
                    if ($jsonOk == 1) {
                        break;
                    }
                }
                // OpenAI "stop": cut the text at the earliest stop string (JSON modes excluded).
                if ($jsonMode == 0) {
                    $stops = api_stop_list($body);
                    if (count($stops) > 0) {
                        $cutAt = api_stop_cut($text, $stops);
                        if ($cutAt >= 0) {
                            $text = substr($text, 0, $cutAt);
                            $stopCut = 1;
                        }
                    }
                }
                if ($out == "") {
                    $payload = "{\"error\":{\"message\":\"" . api_esc(model_last_error()) . "\"}}";
                } else {
                    if ($toolJson == "" && count($toolNames) > 0 && $tcNone == 0) {
                        $toolJson = api_tool_call_json($text, $toolNames);
                    }
                    if ($toolJson == "" && $tcRequired == 1 && count($toolNames) > 0) {
                        // The constrained decode picked one skeleton; its decoded text can lose a
                        // trailing char, so rebuild the call from the skeleton it matched.
                        $reqSkels = [];
                        for ($ri = 0; $ri < count($toolNames); $ri++) {
                            $reqSkels[] = "{\"name\":\"" . $toolNames[$ri] . "\",\"arguments\":{}}";
                        }
                        $pick = $reqSkels[0];
                        for ($ri = 0; $ri < count($reqSkels); $ri++) {
                            if (strpos($text, $reqSkels[$ri]) === 0) {
                                $pick = $reqSkels[$ri];
                            }
                        }
                        $toolJson = api_tool_call_json($pick, $toolNames);
                    }
                    echo "SESSION request completion=" . $genN . " reason=complete json="
                        . ($jsonMode == 0 ? "off" : ($jsonOk == 1 ? "ok" : "bad"))
                        . " tools=" . ($toolJson == "" ? "none" : "call") . " seed=" . $seed
                        . " cache=" . $cacheHit . "\n";
                    $modelId = api_esc(gguf_meta_str("general.architecture"));
                    $finReason = $genN >= $maxTok ? "length" : "stop";
                    if ($stopCut == 1) {
                        $finReason = "stop";
                    }
                    if ($nChoices > 1 && $toolJson == "" && $jsonMode == 0
                        && !$wantResponses && strpos($req, "/v1/messages") === false) {
                        // OpenAI "n": independent continuations, one per choice (same seed+i).
                        $choices = "";
                        for ($ci2 = 0; $ci2 < $nChoices; $ci2++) {
                            $textC = $text;
                            if ($ci2 > 0) {
                                $outC = $temp > 0.0
                                    ? model_generate_sampled(0, $ids, $maxTok, $temp, $topp, $seed + $ci2)
                                    : model_generate(0, $ids, $maxTok);
                                $pC = explode(",", $outC);
                                $gC = "";
                                for ($gi = $promptN; $gi < count($pC); $gi++) {
                                    $gC = $gi == $promptN ? $pC[$gi] : $gC . "," . $pC[$gi];
                                }
                                $textC = $gC == "" ? "" : api_text($gC);
                                $cutC = count($stops) > 0 ? api_stop_cut($textC, $stops) : -1;
                                if ($cutC >= 0) {
                                    $textC = substr($textC, 0, $cutC);
                                }
                            }
                            $finC = $finReason;
                            if ($ci2 > 0) {
                                $nC = count(explode(",", $outC)) - $promptN;
                                $finC = $nC >= $maxTok ? "length" : "stop";
                                if ($cutC >= 0) {
                                    $finC = "stop";
                                }
                            }
                            $choice = "{\"index\":" . $ci2 . ",\"message\":{\"role\":\"assistant\","
                                    . "\"content\":\"" . api_esc($textC) . "\"},\"finish_reason\":\"" . $finC . "\"}";
                            $choices = $ci2 == 0 ? $choice : $choices . "," . $choice;
                        }
                        $payload = "{\"id\":\"chatcmpl-1\",\"object\":\"chat.completion\",\"created\":0,"
                                 . "\"model\":\"" . $modelId . "\",\"choices\":[" . $choices . "],"
                                 . "\"usage\":{\"prompt_tokens\":" . $promptN
                                 . ",\"completion_tokens\":" . ($genN * $nChoices)
                                 . ",\"total_tokens\":" . ($promptN + $genN * $nChoices) . "}}";
                    } else if ($toolJson != "" && strpos($req, "/v1/messages") !== false) {
                        // Anthropic tool call: tool_use content block + stop_reason "tool_use".
                        $payload = "{\"id\":\"msg_1\",\"type\":\"message\",\"role\":\"assistant\","
                                 . "\"model\":\"" . $modelId . "\","
                                 . "\"content\":[" . api_anthropic_tool_block($toolJson) . "],"
                                 . "\"stop_reason\":\"tool_use\",\"usage\":{\"input_tokens\":" . $promptN
                                 . ",\"output_tokens\":" . $genN . "}}";
                    } else if ($toolJson != "" && $wantResponses) {
                        // Responses API: a tool call is a function_call output item, not a chat choice.
                        $rName = api_str($toolJson, "name");
                        $rArgs = api_unesc(api_str($toolJson, "arguments"));
                        if ($rArgs == "") {
                            $rArgs = "{}";
                        }
                        $payload = "{\"id\":\"resp_1\",\"object\":\"response\",\"created_at\":0,"
                                 . "\"status\":\"completed\",\"model\":\"" . $modelId . "\","
                                 . "\"output\":[{\"type\":\"function_call\",\"id\":\"fc_1\","
                                 . "\"call_id\":\"call_1\",\"name\":\"" . api_esc($rName)
                                 . "\",\"arguments\":\"" . api_esc($rArgs) . "\",\"status\":\"completed\"}],"
                                 . "\"usage\":{\"input_tokens\":" . $promptN
                                 . ",\"output_tokens\":" . $genN
                                 . ",\"total_tokens\":" . ($promptN + $genN) . "}}";
                    } else if ($toolJson != "") {
                        // Tool call answer: content is null and finish_reason says why (OpenAI).
                        $payload = "{\"id\":\"chatcmpl-1\",\"object\":\"chat.completion\",\"created\":0,"
                                 . "\"model\":\"" . $modelId . "\","
                                 . "\"choices\":[{\"index\":0,\"message\":{\"role\":\"assistant\","
                                 . "\"content\":null,\"tool_calls\":[" . $toolJson . "]},"
                                 . "\"finish_reason\":\"tool_calls\"}],"
                                 . "\"usage\":{\"prompt_tokens\":" . $promptN
                                 . ",\"completion_tokens\":" . $genN
                                 . ",\"total_tokens\":" . ($promptN + $genN) . "}}";
                    } else if ($wantResponses) {
                        // OpenAI Responses shape.
                        $payload = "{\"id\":\"resp_1\",\"object\":\"response\",\"created_at\":0,"
                                 . "\"status\":\"completed\",\"model\":\"" . $modelId . "\","
                                 . "\"output\":[{\"type\":\"message\",\"id\":\"msg_1\",\"role\":\"assistant\","
                                 . "\"status\":\"completed\",\"content\":[{\"type\":\"output_text\","
                                 . "\"text\":\"" . api_esc($text) . "\",\"annotations\":[]}]}],"
                                 . "\"usage\":{\"input_tokens\":" . $promptN
                                 . ",\"output_tokens\":" . $genN
                                 . ",\"total_tokens\":" . ($promptN + $genN) . "}}";
                    } else if ($wantCompletions) {
                        // Legacy /v1/completions shape.
                        $payload = "{\"id\":\"cmpl-1\",\"object\":\"text_completion\",\"created\":0,"
                                 . "\"model\":\"" . $modelId . "\",\"choices\":[{\"text\":\""
                                 . api_esc($text) . "\",\"index\":0,\"finish_reason\":\"" . $finReason . "\"}],"
                                 . "\"usage\":{\"prompt_tokens\":" . $promptN
                                 . ",\"completion_tokens\":" . $genN
                                 . ",\"total_tokens\":" . ($promptN + $genN) . "}}";
                    } else if (strpos($req, "/v1/messages") !== false) {
                        // Anthropic Messages shape.
                        $payload = "{\"id\":\"msg_1\",\"type\":\"message\",\"role\":\"assistant\","
                                 . "\"model\":\"" . $modelId . "\","
                                 . "\"content\":[{\"type\":\"text\",\"text\":\"" . api_esc($text) . "\"}],"
                                 . "\"stop_reason\":\"" . ($stopCut == 1 ? "stop_sequence" : ($genN >= $maxTok ? "max_tokens" : "end_turn"))
                                 . "\",\"usage\":{\"input_tokens\":" . $promptN
                                 . ",\"output_tokens\":" . $genN . "}}";
                    } else {
                        $payload = "{\"id\":\"chatcmpl-1\",\"object\":\"chat.completion\",\"created\":0,"
                                 . "\"model\":\"" . $modelId . "\","
                                 . "\"choices\":[{\"index\":0,\"message\":{\"role\":\"assistant\","
                                 . "\"content\":\"" . api_esc($text) . "\"},\"finish_reason\":\"" . $finReason . "\"}],"
                                 . "\"usage\":{\"prompt_tokens\":" . $promptN
                                 . ",\"completion_tokens\":" . $genN
                                 . ",\"total_tokens\":" . ($promptN + $genN) . "}}";
                    }
                }
            }
        }

        fwrite($c, "HTTP/1.1 " . $status . "\r\nContent-Type: " . $ctype . "\r\nConnection: close\r\n"
                 . "Content-Length: " . strlen($payload) . "\r\n\r\n" . $payload);
        fclose($c);
    }
}

// `engine doctor` (FR-04): what the runtime actually sees - device matrix, driver fingerprint,
// model shape and the quantisation mix - plus the per-device residency plan.
function doctor(): void
{
    echo "KAKASI DOCTOR\n";
    $n = backend_device_count();
    echo "backends=" . $n . "\n";
    $ok = 0;
    for ($i = 0; $i < $n; $i++) {
        $v = backend_validate($i) ? "ok" : "fail";
        if ($v == "ok") {
            $ok++;
        }
        echo "  [" . $i . "] " . backend_device_name($i) . "\n"
            . "       " . model_device_fingerprint($i) . " validate=" . $v . "\n";
    }
    echo "model=" . gguf_meta_str("general.architecture")
        . " layers=" . gguf_meta_num("qwen2.block_count")
        . " hidden=" . gguf_meta_num("qwen2.embedding_length")
        . " ffn=" . gguf_meta_num("qwen2.feed_forward_length")
        . " heads=" . gguf_meta_num("qwen2.attention.head_count")
        . "/" . gguf_meta_num("qwen2.attention.head_count_kv")
        . " tensors=" . gguf_tensor_count() . "\n";

    // Quantisation mix: how many tensors of each format, so "which kernels must exist" is
    // answered by the file rather than by assumption.
    $tn = gguf_tensor_count();
    $counts = [];
    $order = "";
    for ($i = 0; $i < $tn; $i++) {
        $dt = gguf_tensor_dtype_name($i);
        if (!isset($counts[$dt])) {
            $counts[$dt] = 0;
            $order = $order . "|" . $dt;
        }
        $counts[$dt] = $counts[$dt] + 1;
    }
    $mix = "";
    foreach (explode("|", $order) as $dname) {
        if ($dname != "") {
            $mix = $mix . " " . $dname . "=" . $counts[$dname];
        }
    }
    echo "quant:" . $mix . "\n";
    for ($i = 0; $i < $n; $i++) {
        if (backend_validate($i)) {
            echo "plan: " . model_plan($i, 2048) . "\n";
        }
    }
    echo "doctor: " . ($ok > 0 ? "READY" : "NO_BACKEND") . " (validated " . $ok . "/" . $n . ")\n";
}

// `engine models <cmd>` (FR-10): import / list / rm over a local model directory, with an index
// that records what each file actually is (re-loaded on import, not trusted from the name).
function models_dir(): string { return "C:/git/c/typephp-kakasi/engine/models"; }

// "i/N" when the name is a split-GGUF shard (model-00001-of-00002.gguf), else "".
function api_shard_info(string $name): string
{
    $m = [];
    if (preg_match('/-([0-9]{5})-of-([0-9]{5})\.gguf$/i', $name, $m)) {
        return "" . (int) $m[1] . "/" . (int) $m[2];
    }
    return "";
}

// API id for a stored file: drop the ".gguf" suffix, keep everything else.
function api_model_id(string $file): string
{
    $n = strlen($file);
    if ($n > 5 && substr($file, $n - 5, 5) == ".gguf") {
        return substr($file, 0, $n - 5);
    }
    return $file;
}

// GET /v1/models: the model actually loaded plus everything import/pull recorded in the
// store index, deduped. Entries are ids only; OpenAI clients tolerate the extra fields.
// One entry per model: the loaded one first, then store entries (deduped), each with the
// metadata the index already recorded.
function api_models_data(string $indexText, string $loaded): string
{
    $ids = [];
    $ids[] = $loaded;
    $objs = [];
    $objs[] = "{\"id\":\"" . api_esc($loaded) . "\",\"object\":\"model\",\"owned_by\":\"typephp-kakasi\"}";
    $lines = explode("\n", $indexText);
    for ($i = 0; $i < count($lines); $i++) {
        if ($lines[$i] == "") {
            continue;
        }
        $f = explode("|", $lines[$i]);
        $id = api_model_id($f[0]);
        $seen = 0;
        for ($j = 0; $j < count($ids); $j++) {
            if ($ids[$j] == $id) {
                $seen = 1;
            }
        }
        if ($seen == 1) {
            continue;
        }
        $ids[] = $id;
        $obj = "{\"id\":\"" . api_esc($id) . "\",\"object\":\"model\",\"owned_by\":\"typephp-kakasi\"";
        if (count($f) >= 5) {
            $obj = $obj . ",\"bytes\":" . $f[1] . ",\"architecture\":\"" . api_esc($f[2])
                 . "\",\"layers\":" . $f[3] . ",\"hidden\":" . $f[4];
        }
        $objs[] = $obj . "}";
    }
    $out = "";
    for ($i = 0; $i < count($objs); $i++) {
        $out = $i == 0 ? $objs[$i] : $out . "," . $objs[$i];
    }
    return $out;
}

function api_models_json(): string
{
    $idx = models_dir() . "/index.txt";
    $txt = file_exists($idx) ? file_get_contents($idx) : "";
    return "{\"object\":\"list\",\"data\":["
         . api_models_data($txt, gguf_meta_str("general.architecture")) . "]}";
}

function models_read_cmd(): string
{
    if (!file_exists("models.cmd")) {
        return "";
    }
    $s = file_get_contents("models.cmd");
    $s = str_replace("\r", "", $s);
    $s = str_replace("\n", " ", $s);
    $s = str_replace("\"", "", $s);
    // cmd's `echo` leaves a trailing space; a path with one appended does not exist.
    $n = strlen($s);
    while ($n > 0 && substr($s, $n - 1, 1) == " ") {
        $n--;
    }
    return substr($s, 0, $n);
}

function models_index_append(string $line): void
{
    $path = models_dir() . "/index.txt";
    $old = file_exists($path) ? file_get_contents($path) : "";
    file_put_contents($path, $old . $line . "\n");
}

function models_cli(): void
{
    $dir = models_dir();
    if (!file_exists($dir)) {
        mkdir($dir);
    }
    $line = models_read_cmd();
    $sp = strpos($line, " ");
    $cmd = $sp === false ? $line : substr($line, 0, $sp);
    $arg = $sp === false ? "" : substr($line, $sp + 1);
    $index = $dir . "/index.txt";

    if ($cmd == "import") {
        // Directory-style model folder (Kapitsa): the folder holds the template files and
        // exactly one .gguf. Split shards are not merged, so anything but one file is refused.
        $src = $arg;
        $dg = $arg == "" ? "" : model_dir_gguf($arg);
        if (substr($dg, 0, 1) == "!") {
            echo "import: directory has " . substr($dg, 1)
                . " .gguf files; split GGUFs are not merged\n";
            return;
        }
        if ($dg != "") {
            $src = $dg;
        }
        if ($src == "" || !file_exists($src)) {
            echo "import: no such file\n";
            return;
        }
        $name = basename($src);
        $shard = api_shard_info($name);
        if ($shard != "") {
            echo "import: split GGUF shard " . $shard . " - merge shards first (unsupported)\n";
            return;
        }
        $dst = $dir . "/" . $name;
        if (!copy($src, $dst)) {
            echo "import: copy failed\n";
            return;
        }
        // Load the copy so the index records the real shape, and so a broken file is caught here.
        if (!gguf_load($dst)) {
            echo "import: not loadable (" . gguf_error() . ")\n";
            unlink($dst);   // do not leave a half-usable file in the model directory
            return;
        }
        models_index_append($name . "|" . filesize($dst)
            . "|" . gguf_meta_str("general.architecture")
            . "|" . gguf_meta_num("qwen2.block_count")
            . "|" . gguf_meta_num("qwen2.embedding_length"));
        echo "import ok: " . $name . " (" . filesize($dst) . " B, "
            . gguf_meta_str("general.architecture") . ")\n";
        return;
    }

    if ($cmd == "pull") {
        if ($arg == "") {
            echo "pull: need a URL\n";
            return;
        }
        // File name = the URL's last path segment (scanned by hand: strrpos is not verified).
        $slash = 0;
        for ($i = 0; $i < strlen($arg); $i++) {
            if (substr($arg, $i, 1) == "/") {
                $slash = $i;
            }
        }
        $name = substr($arg, $slash + 1);
        if ($name == "") {
            echo "pull: cannot derive a file name from the URL\n";
            return;
        }
        $dst = $dir . "/" . $name;
        // copy() streams to disk, so a 380 MB model never has to fit in memory.
        if (!@copy($arg, $dst)) {
            echo "pull: download failed\n";
            return;
        }
        if (!gguf_load($dst)) {
            echo "pull: downloaded file is not loadable (" . gguf_error() . ")\n";
            unlink($dst);
            return;
        }
        models_index_append($name . "|" . filesize($dst)
            . "|" . gguf_meta_str("general.architecture")
            . "|" . gguf_meta_num("qwen2.block_count")
            . "|" . gguf_meta_num("qwen2.embedding_length"));
        echo "pull ok: " . $name . " (" . filesize($dst) . " B, "
            . gguf_meta_str("general.architecture") . ")\n";
        return;
    }

    if ($cmd == "cache") {
        $cdir = "C:/git/c/typephp-kakasi/engine";
        $listing = model_dir_files($cdir, ".kv");
        if ($arg == "clear") {
            $lines = explode("\n", $listing);
            $removed = 0;
            for ($i = 0; $i < count($lines); $i++) {
                if ($lines[$i] == "") {
                    continue;
                }
                $f = explode("|", $lines[$i]);
                if (unlink($cdir . "/" . $f[0])) {
                    $removed++;
                }
            }
            echo "cache: removed " . $removed . " files\n";
            return;
        }
        $lines = explode("\n", $listing);
        for ($i = 0; $i < count($lines); $i++) {
            if ($lines[$i] != "") {
                echo "  " . $lines[$i] . "\n";
            }
        }
        $st = explode("|", api_cache_stats($listing));
        echo "cache: " . $st[0] . " files, " . $st[1] . " bytes\n";
        return;
    }

    if ($cmd == "list") {
        if (!file_exists($index)) {
            echo "(no models)\n";
            return;
        }
        echo "name | bytes | arch | layers | hidden\n";
        echo file_get_contents($index);
        return;
    }

    if ($cmd == "rm") {
        if ($arg == "" || !file_exists($index)) {
            echo "rm: nothing to remove\n";
            return;
        }
        $keep = "";
        $removed = 0;
        foreach (explode("\n", file_get_contents($index)) as $l) {
            if ($l == "") {
                continue;
            }
            $name = substr($l, 0, strpos($l, "|"));
            if ($name == $arg) {
                $p = $dir . "/" . $name;
                if (file_exists($p)) {
                    unlink($p);
                }
                $removed++;
            } else {
                $keep = $keep . $l . "\n";
            }
        }
        file_put_contents($index, $keep);
        echo "rm: removed " . $removed . " entr" . ($removed == 1 ? "y" : "ies") . "\n";
        return;
    }

    echo "usage: models.bat import <path> | pull <url> | list | rm <name>\n";
}

function main(): void
{
    // Model management runs before any model is loaded: `import` may be bringing the first one.
    if (file_exists("models.on")) {
        models_cli();
        return;
    }

    $path = "C:/Users/ASUS/.lmstudio/models/arzaan789/qwen2.5-0.5b-uncensored/qwen2.5_0.5b_uncensored.Q4_K_M.gguf";

    if (!gguf_load($path)) {
        echo "LOAD FAILED: " . gguf_error() . "\n";
        return;
    }

    if (file_exists("doctor.on")) {
        doctor();
        return;
    }

    // Opt-in server mode: a sentinel file next to the binary keeps the self-test default path
    // untouched (the API has to run forever, the test suite has to exit).
    if (file_exists("serve.par.on")) {
        // Cooperative two-slot streaming server (port 8001); serves streaming text only.
        api_serve_par(8001);
        return;
    }

    if (file_exists("serve.on")) {
        // Loopback only, port 8000 as the spec's default (FR-09.3).
        api_serve(8000);
        return;
    }

    $arch = gguf_meta_str("general.architecture");
    $layers = gguf_meta_num("qwen2.block_count");
    $hidden = gguf_meta_num("qwen2.embedding_length");
    echo "model: {$arch} layers={$layers} hidden={$hidden}\n";
    echo gguf_validate() . "\n";

    $n = gguf_tensor_count();

    // Pick real weights by dtype rather than by hard-coded index.
    $tQ8 = -1;
    $tQ5 = -1;
    $tF32 = -1;
    $tQ4 = -1;
    $tQ6 = -1;
    for ($i = 0; $i < $n; $i++) {
        $dt = gguf_tensor_dtype_name($i);
        if ($dt == "f32" && $tF32 < 0) {
            $tF32 = $i;
        }
        if ($dt == "q8_0" && $tQ8 < 0) {
            $tQ8 = $i;
        }
        if ($dt == "q5_0" && $tQ5 < 0 && gguf_tensor_ne($i, 0) == 896 && gguf_tensor_ne($i, 1) == 896) {
            $tQ5 = $i;
        }
        if ($dt == "q4_k" && $tQ4 < 0) {
            $tQ4 = $i;
        }
        if ($dt == "q6_k" && $tQ6 < 0) {
            $tQ6 = $i;
        }
    }
    echo "picked f32={$tF32} q8_0={$tQ8} q5_0={$tQ5} q4_k={$tQ4} q6_k={$tQ6} of {$n}\n";

    $count = backend_device_count();
    $usable = 0;
    for ($i = 0; $i < $count; $i++) {
        if (backend_validate($i)) {
            $usable++;
        } else {
            echo "  backend {$i} rejected: " . backend_last_error() . "\n";
        }
    }
    echo "backends validated: {$usable}/{$count}\n";

    if ($usable < 1) {
        echo "OPS: SKIPPED (no backend)\n";
        return;
    }

    $fails = 0;

    // Each op runs 3 times: one lucky pass proves nothing, and a result that moves
    // between runs is treated as a failure (nondeterminism is a correctness bug here).
    // kind: 0 = dequant, 1 = gemm on that weight, 2 = rmsnorm(len)
    $labels = ["deq_f32", "deq_q5", "deq_q4", "deq_q6", "gemm_q5", "gemm_q4", "rms"];
    $kinds = [0, 0, 0, 0, 1, 1, 2];
    $args = [$tF32, $tQ5, $tQ4, $tQ6, $tQ5, $tQ4, 896];
    $nchecks = 7;

    for ($b = 0; $b < $count; $b++) {
        for ($c = 0; $c < $nchecks; $c++) {
            $arg = $args[$c];
            if ($arg < 0) {
                continue;
            }
            $good = 0;
            $last = 0.0;
            for ($r = 0; $r < 3; $r++) {
                if ($kinds[$c] == 2) {
                    $last = ops_rmsnorm_maxerr($b, $arg);
                } elseif ($kinds[$c] == 1) {
                    $last = ops_linear_relerr($b, $arg, 8);
                } else {
                    $last = ops_dequant_maxerr($b, $arg);
                }
                if ($last >= 0.0 && $last < 0.0001) {
                    $good++;
                }
            }
            if ($good == 3) {
                echo "  b{$b} {$labels[$c]} PASS x3 (last={$last})\n";
            } else {
                echo "  b{$b} {$labels[$c]} FAIL {$good}/3 (last={$last}) " . ops_last_error() . "\n";
                $fails++;
            }
        }

        if ($tQ8 >= 0) {
            $e = ops_dequant_maxerr($b, $tQ8);
            if ($e == 0.0) {
                echo "  b{$b} deq_q8_0(embd) EXACT (" . ops_elapsed_ms() . " ms)\n";
            } else {
                echo "  b{$b} deq_q8_0(embd) FAIL err={$e}\n";
                $fails++;
            }
        }
    }

    echo $fails == 0 ? "OPS: PASS\n" : "OPS: {$fails} FAILURE(S)\n";

    // End-to-end assertion against the llama.cpp greedy oracle (findings F15):
    // prompt "The capital of France is" -> next token must be 12095 (' Paris').
    $ids = "785,6722,315,9625,374";
    $expect = 12095;
    $ffails = 0;

    // Bisection probe retired: its expectation assumed llama used exactly 1 token for
    // "The", but the oracle tool appends a special token, so the expected id was wrong.
    // An argmax-only judge is too weak: a backend-numerics regression can keep the right top-1
    // and still be wrong by nats (observed once when a sync was removed). Assert the two
    // backends agree on the logit value itself.
    $refLogit = 0.0;
    for ($b = 0; $b < 2; $b++) {
        $t0 = microtime(true);
        $top = model_prefill($b, $ids, $expect);
        $ms = (microtime(true) - $t0) * 1000.0;
        $rank = model_expect_rank();
        $elog = model_expect_logit();
        if ($top < 0) {
            echo "  b{$b} forward ERROR: " . model_last_error() . "\n";
            $ffails++;
        } elseif ($top == $expect) {
            echo "  b{$b} forward PASS top1={$top} logit=" . model_top_logit() . " ({$ms} ms)\n";
            if ($b == 0) {
                $refLogit = model_top_logit();
            } else {
                $d = model_top_logit() - $refLogit;
                if ($d < 0) {
                    $d = -$d;
                }
                if ($d > 0.05) {
                    echo "  backend-drift FAIL |b1-b0| logit = " . $d . " (> 0.05)\n";
                    $ffails++;
                } else {
                    echo "  backend-drift PASS |b1-b0| logit = " . $d . "\n";
                }
            }
        } else {
            echo "  b{$b} forward MISMATCH top1={$top} expected={$expect} logit=" . model_top_logit()
                . " second=" . model_second_logit() . " expect_logit={$elog} expect_rank={$rank} ({$ms} ms)\n";
            if ($b == 0) {
                $tr = "  maxabs trace embd+layers:";
                for ($i = 0; $i < model_trace_count(); $i++) {
                    $tr = $tr . " " . model_trace_at($i);
                }
                echo $tr . "\n";
            }
            $ffails++;
        }
    }
    echo $ffails == 0 ? "FORWARD: PASS (oracle ' Paris')\n" : "FORWARD: {$ffails} FAILURE(S)\n";

    // Greedy autoregressive generation (naive re-prefill per step) + vocab decode.
    $gen = model_generate(0, $ids, 6);
    $parts = explode(",", $gen);
    $gn = count($parts);
    echo "gen ids({$gn}): {$gen}\n";
    $text = "";
    for ($i = 0; $i < $gn; $i++) {
        $text = $text . gguf_token_str((int) $parts[$i]);
    }
    $pretty = str_replace("Ġ", " ", $text);
    echo "decoded: {$pretty}\n";
    // The oracle's first continuation token is ' Paris' (12095); later tokens are recorded
    // separately below rather than asserted, so a divergence cannot hide as a generic FAIL.
    if ($gn >= 6 && $parts[5] == "12095") {
        echo "GENERATE: PASS (step-1 matches oracle; n={$gn})\n";
    } else {
        echo "GENERATE: FAIL (n={$gn} ids={$gen})\n";
    }
    echo "perf: prefill=" . model_gen_prefill_ms() . " ms, decode steps=" . model_gen_step_ms() . " ms\n";
    echo "  " . model_residency_report() . "\n";

    // Sampling checks. temp=0 must reproduce greedy exactly (same stop semantics), a very
    // small nucleus must collapse to greedy too, and a fixed seed must be reproducible.
    $sp = "785,6722,315,9625,374";
    $g0 = model_generate_sampled(0, $sp, 6, 0.0, 1.0, 12345);
    $gt = model_generate(0, $sp, 6);
    // Read the nucleus immediately: model_nucleus_size() reports the last run, so sampling
    // later calls first would report their number instead (the same stale-global trap as
    // g_expect_id).
    $sT = model_generate_sampled(0, $sp, 6, 0.7, 0.03, 999);
    $nk = model_nucleus_size();
    $sA = model_generate_sampled(0, $sp, 12, 1.0, 0.9, 7);
    $sB = model_generate_sampled(0, $sp, 12, 1.0, 0.9, 7);
    $sC = model_generate_sampled(0, $sp, 12, 1.0, 0.9, 8);
    $sfail = 0;
    if ($g0 != $gt) {
        echo "  samp[greedy-equiv] FAIL temp0={$g0} greedy={$gt}\n";
        $sfail++;
    } else {
        echo "  samp[greedy-equiv] PASS\n";
    }
    if ($sT != $gt) {
        echo "  samp[tiny-nucleus] FAIL p0.03={$sT} want={$gt}\n";
        $sfail++;
    } elseif ($nk != 1) {
        // A nucleus wider than one token means matching greedy was luck, not top-p working.
        echo "  samp[tiny-nucleus] WEAK first-nucleus={$nk}\n";
        $sfail++;
    } else {
        echo "  samp[tiny-nucleus] PASS first-nucleus=1\n";
    }
    if ($sA != $sB) {
        echo "  samp[determinism] FAIL {$sA} vs {$sB}\n";
        $sfail++;
    } else {
        echo "  samp[determinism] PASS\n";
    }
    $sAn = count(explode(",", $sA));
    if ($sAn < 8) {
        echo "  samp[spread] FAIL n={$sAn} ids={$sA}\n";
        $sfail++;
    } else {
        echo "  samp[spread] PASS (seed7 gives {$sAn} ids, seed8 differs="
            . ($sA != $sC ? "yes" : "no") . ")\n";
    }
    $sText = "";
    foreach (explode(",", $sA) as $t) {
        $sText = $sText . gguf_token_str((int) $t);
    }
    echo "  sampled decode: " . str_replace("Ġ", " ", $sText) . "\n";
    echo $sfail == 0 ? "SAMPLING: PASS\n" : "SAMPLING: {$sfail} FAILURE(S)\n";

    // Step-2 parity probe. Oracle: "The capital of France is Paris" -> ',' (id 11) then
    // ' the'(279) ' largest'(7772) ' city'(3283). Print the margin so a near-tie on
    // quantized weights is distinguishable from a genuine mismatch.
    $s2 = model_prefill(0, "785,6722,315,9625,374,12095", 11);
    echo "  step2 ours={$s2} top=" . model_top_logit() . " second=" . model_second_logit()
        . " comma_logit=" . model_expect_logit() . " comma_rank=" . model_expect_rank() . "\n";

    // Long-context check: the KV cache must remove the old 64-token ceiling.
    $long = tok_encode("The capital of France is Paris. The largest ocean is the Pacific. Water boils at one hundred degrees Celsius. The capital of France is Paris. The largest ocean is the Pacific. Water boils at one hundred degrees Celsius. The capital of France is now known to be Paris. The largest ocean in the world is the Pacific Ocean and water boils at one hundred degrees Celsius when the pressure is normal. The capital of France is");
    $ln = count(explode(",", $long));
    $gen2 = model_generate(0, $long, 4);
    $g2n = count(explode(",", $gen2));
    echo "long prompt tokens: {$ln} -> ids after gen: {$g2n}\n";
    if ($ln > 64 && $g2n > $ln) {
        echo "LONGCTX: PASS (generated past the old 64-token limit)\n";
    } else {
        echo "LONGCTX: FAIL " . model_last_error() . "\n";
    }

    // BPE encode, checked against ids captured from llama-tokenize (oracle). The whitespace,
    // newline, tab and punctuation cases below were measured on this exact model, so a change
    // in the splitter that deviates from llama.cpp fails here instead of drifting silently.
    $tcases = ["The", "The capital of France is", "I'm not sure", "The capital of France is Paris.",
        "a ", "a  ", "a   ", "   ", "a  b", "a,  b", "a\nb", "a\tb", "a.\nb", "a\n\nb", "a \n",
        "hello  \nworld", "100", "12345", "don't", 'The price is $3.50.'];
    $twants = ["785", "785,6722,315,9625,374", "40,2776,537,2704",
        "785,6722,315,9625,374,12095,13",
        "64,220", "64,256", "64,262", "262", "64,220,293", "64,11,220,293", "64,198,65",
        "64,2233", "64,624,65", "64,271,65", "64,715", "14990,2303,14615", "16,15,15",
        "16,17,18,19,20", "15007,944", "785,3349,374,400,18,13,20,15,13"];
    $tfails = 0;
    $tcaseN = count($tcases);
    for ($i = 0; $i < $tcaseN; $i++) {
        $got = tok_encode($tcases[$i]);
        if ($got == $twants[$i]) {
            echo "  tok[" . $i . "] PASS ids=" . $got . "\n";
        } else {
            echo "  tok[" . $i . "] FAIL got=" . $got . " want=" . $twants[$i]
                . " err=" . tok_last_error() . "\n";
            $tfails++;
        }
    }
    echo $tfails == 0 ? "TOKENIZER: PASS ({$tcaseN} cases vs llama-tokenize)\n"
        : "TOKENIZER: {$tfails} FAILURE(S)\n";

    // Chat template + control tokens. Markers are assembled piecewise so this source (and the
    // binary) never carries a raw control-token literal.
    $IM_S = "<" . "|" . "im_start" . "|" . ">";
    $IM_E = "<" . "|" . "im_end" . "|" . ">";
    $cfail = 0;
    if (tok_chat_template_matches() != 1) {
        echo "  chat[template] FAIL (model template is not the ChatML dialect tok_chat renders)\n";
        $cfail++;
    } else {
        echo "  chat[template] PASS\n";
    }
    $cwants = [[$IM_S, "151644"], ["content" . $IM_E . "\n", "1796,151645,198"]];
    for ($i = 0; $i < 2; $i++) {
        $got = tok_encode($cwants[$i][0]);
        if ($got == $cwants[$i][1]) {
            echo "  chat[ctrl-" . $i . "] PASS ids=" . $got . "\n";
        } else {
            echo "  chat[ctrl-" . $i . "] FAIL got=" . $got . " want=" . $cwants[$i][1]
                . " err=" . tok_last_error() . "\n";
            $cfail++;
        }
    }
    $cIds = tok_chat("user", "Hi");
    if ($cIds == "151644,872,198,13048,151645,198") {
        echo "  chat[turn] PASS ids=" . $cIds . "\n";
    } else {
        echo "  chat[turn] FAIL got=" . $cIds . " err=" . tok_last_error() . "\n";
        $cfail++;
    }
    // End to end: a chat-formatted prompt must still drive the engine to the oracle answer.
    $chatPrompt = tok_chat("user", "The capital of France is");
    $chatGen = model_generate(0, $chatPrompt, 6);
    $cgParts = explode(",", $chatGen);
    $cgText = "";
    for ($i = 0; $i < count($cgParts); $i++) {
        $cgText = $cgText . gguf_token_str((int) $cgParts[$i]);
    }
    echo "  chat[e2e] prompt=" . $chatPrompt . " -> " . str_replace("Ġ", " ", $cgText) . "\n";
    if (count($cgParts) <= count(explode(",", $chatPrompt))) {
        echo "  chat[e2e] FAIL (no tokens generated)\n";
        $cfail++;
    }
    echo $cfail == 0 ? "CHAT: PASS\n" : "CHAT: {$cfail} FAILURE(S)\n";

    // Full pipeline: arbitrary text in -> tokens -> generated tokens -> text out.
    $autoIds = tok_encode("The capital of France is");
    if ($autoIds != $ids) {
        echo "PIPELINE: SKIP (encoder ids differ from oracle ids)\n";
    } else {
        $p2 = model_generate(0, $autoIds, 3);
        $txt2 = "";
        foreach (explode(",", $p2) as $t) {
            $txt2 = $txt2 . gguf_token_str((int) $t);
        }
        echo "PIPELINE decoded: " . str_replace("Ġ", " ", $txt2) . "\n";
        echo "PIPELINE: text-in/text-out OK\n";
    }

    // Per-position log-probability parity against llama.cpp's saved logits
    // (engine/tests/llama_logits_c128.bin from llama-perplexity --save-all-logits -c 128).
    // Must be the same text as tests/ppl_long.txt so both sides see the same tokenization.
    $sent = "The capital of France is Paris. The largest ocean is the Pacific. Water boils at one hundred degrees. ";
    $par = "";
    for ($i = 0; $i < 13; $i++) {
        $par = $par . $sent;
    }
    $pv = explode(",", tok_encode($par));
    $np = count($pv);
    echo "parity tokens: {$np}\n";

    // Steady-state timing (weights already resident, KV cache warm) — the first prefill in
    // this process pays for uploading 290 tensors, so it is not the number to quote.
    $p200 = "";
    for ($k = 0; $k < 200; $k++) {
        if ($k > 0) {
            $p200 = $p200 . ",";
        }
        $p200 = $p200 . $pv[$k];
    }
    model_generate(0, $p200, 1);
    $pref200 = model_gen_prefill_ms();
    model_generate(0, $ids, 1);
    $pref5 = model_gen_prefill_ms();
    $dg = model_generate(0, $ids, 16);
    $dgParts = explode(",", $dg);
    $produced = count($dgParts) - count(explode(",", $ids));
    $steps = $produced - 1;
    $stepMs = model_gen_step_ms();
    echo "PERF steady: prefill(5 tok)=" . $pref5 . " ms  prefill(200 tok)=" . $pref200
        . " ms  decode=" . $stepMs . " ms over {$steps} steps\n";
    if ($steps > 0) {
        echo "PERF tok/s (decode, steady) = " . (1000.0 * $steps / $stepMs) . "\n";
    }
    // Stage attribution. A 1-token forward has the same buffer and launch count as a decode
    // step, so where its time sits tells us what the per-step fixed cost actually is.
    $stag = "";
    model_prefill(0, "13", -1);
    for ($i = 0; $i < model_stage_count(); $i++) {
        $stag = $stag . " s" . $i . "=" . model_stage_at($i);
    }
    echo "PERF stages(1 tok):" . $stag . " (s0=emb+rope s1=24 blocks s2=norm+lm_head)\n";
    $stag = "";
    model_prefill(0, $ids, -1);
    for ($i = 0; $i < model_stage_count(); $i++) {
        $stag = $stag . " s" . $i . "=" . model_stage_at($i);
    }
    echo "PERF stages(5 tok):" . $stag . "\n";

    // Long-context prefill point: the baseline only had 5/200 tokens, which is not enough to say
    // anything about scaling. 512 tokens is the next meaningful step on this model.
    $longTxt = "";
    for ($i = 0; $i < 26; $i++) {
        $longTxt = $longTxt . $sent;
    }
    $lv = explode(",", tok_encode($longTxt));
    $n512 = "";
    for ($i = 0; $i < 512 && $i < count($lv); $i++) {
        $n512 = $i == 0 ? $lv[$i] : $n512 . "," . $lv[$i];
    }
    $nreal = count(explode(",", $n512));
    $tL = microtime(true);
    model_prefill(0, $n512, -1);
    $msL = (microtime(true) - $tL) * 1000.0;
    echo "PERF long: prefill(" . $nreal . " tok)=" . $msL . " ms ("
        . (1000.0 * $nreal / $msL) . " tok/s) err=" . model_last_error() . "\n";

    // FR-18 orchestration: ask each backend what it can hold, then prove the low-memory
    // fallback path is numerically the same engine, not a degraded approximation. The budget
    // is pinned far below the model's f32 footprint to simulate a small device on hardware
    // that happens to be large; if the policy were inert, the plan line would not change.
    $planGpu = model_plan(0, 2048);
    $planCpu = model_plan(1, 2048);
    echo "ORCH " . $planGpu . "\n";
    echo "ORCH " . $planCpu . "\n";
    $base = model_generate(0, $ids, 6);
    // Degradation curve: same request under three residency budgets. Every regime must
    // produce identical tokens - a memory-tight device gets slower, never different.
    $budgets = [0.0, 0.6, 0.05];
    $ofail = 0;
    for ($i = 0; $i < 3; $i++) {
        model_set_budget_gib($budgets[$i]);
        $g = model_generate(0, $ids, 6);
        $nprod = count(explode(",", $g)) - count(explode(",", $ids));
        $nsteps = $nprod - 1;
        $per = $nsteps > 0 ? (model_gen_step_ms() / $nsteps) : 0.0;
        $rep = model_residency_report();
        if ($g != $base) {
            echo "  orch[curve " . $i . "] FAIL ids differ from resident baseline\n";
            $ofail++;
        }
        echo "  curve[" . $budgets[$i] . "GiB] step=" . $per . " ms  " . $rep . "\n";
    }
    model_set_budget_gib(0.0);
    $planTiny = model_plan(0, 2048);
    model_set_budget_gib(0.05);
    $planPinned = model_plan(0, 2048);
    $stream = model_generate(0, $ids, 6);
    $repOff = model_residency_report();
    model_set_budget_gib(0.0);
    $back = model_generate(0, $ids, 6);
    if ($planPinned == $planGpu) {
        echo "  orch[policy] FAIL pinning the budget did not change the verdict\n";
        $ofail++;
    } else {
        echo "  orch[policy] PASS -> " . $planPinned . "\n";
    }
    if ($stream != $base || $back != $base) {
        echo "  orch[fallback] FAIL resident={$base} stream={$stream} restored={$back}\n";
        $ofail++;
    } else {
        echo "  orch[fallback] PASS streaming path produced identical tokens (6 steps)\n";
    }
    echo "  residency tiny=" . $repOff . "\n";
    // CPU+GPU heterogeneous offload: layers [0,12) on the Arc GPU, [12,24) on the CPU. The
    // only assertion that matters is that the split engine is the SAME engine - identical ids.
    $rc = model_set_split(1, 12);
    echo "  split: " . model_split_state() . "\n";
    $sgen = model_generate(0, $ids, 6);
    model_set_split(-1, -1);
    if ($sgen == $base) {
        echo "  orch[split] PASS 24 layers split GPU[0,12)+CPU[12,24) produced identical ids\n";
    } else {
        echo "  orch[split] FAIL resident={$base} split={$sgen} err=" . model_last_error() . "\n";
        $ofail++;
    }
    echo $ofail == 0 ? "ORCH: PASS\n" : "ORCH: {$ofail} FAILURE(S)\n";
    $acc = $pv[0];
    $lp32 = [];
    for ($k = 1; $k < $np; $k++) {
        $tgt = (int) $pv[$k];
        model_prefill(0, $acc, $tgt);
        $lp32[$k] = model_expect_logit() - model_logsumexp();
        echo "PARITY " . $k . " " . $tgt . " " . $lp32[$k] . "\n";
        $acc = $acc . "," . $pv[$k];
    }
    echo "KVQ cache f32 = " . model_kv_cache_gib() . " GiB\n";

    // FR-18 KV quantization: rerun the same sequence with an f16 cache and price it against
    // the f32 log-probabilities just measured. Same engine, same order, only the stored KV
    // width changes, so any difference is the quantization itself.
    $kvMode = model_set_kv_f16(1);
    $acc = $pv[0];
    $dmax = 0.0;
    $dsum = 0.0;
    for ($k = 1; $k < $np; $k++) {
        $tgt = (int) $pv[$k];
        model_prefill(0, $acc, $tgt);
        $lp16 = model_expect_logit() - model_logsumexp();
        $d = $lp16 - $lp32[$k];
        if ($d < 0.0) {
            $d = -$d;
        }
        if ($d > $dmax) {
            $dmax = $d;
        }
        $dsum = $dsum + $d;
        // Emitted as its own prefix so the same oracle harness can score f16 on its own,
        // instead of inferring f16-vs-oracle from "f32-vs-oracle plus f16-vs-f32".
        echo "PARITY16 " . $k . " " . $tgt . " " . $lp16 . "\n";
        $acc = $acc . "," . $pv[$k];
    }
    $g16 = model_generate(0, $ids, 6);
    echo "KVQ cache f16 = " . model_kv_cache_gib() . " GiB (mode=" . $kvMode . ")\n";
    echo "KVQ f16 vs f32 logp over " . ($np - 1) . " positions: mean=" . ($dsum / ($np - 1))
        . " max=" . $dmax . "\n";
    if ($dmax > 0.5) {
        echo "KVQ: FAIL max|dlogp| too large\n";
    } else {
        echo "KVQ: PASS (f16 cache within 0.5 nat of f32; greedy ids "
            . ($g16 == $base ? "identical" : "DIFFER") . ")\n";
    }
    if ($g16 != $base) {
        echo "KVQ: FAIL greedy ids changed under f16 KV\n";
    }
    model_set_kv_f16(0);

    // Resident weight precision A/B: int8 (+ scale per 64) against f16, same request. Bytes are
    // what decode is bound by, so this is the direct measure of "fewer bytes, same tokens".
    $g8 = model_generate(0, $ids, 6);
    $t8 = model_gen_step_ms() / 5.0;
    $r8 = model_residency_report();
    model_set_weight_prec(0);
    $gf = model_generate(0, $ids, 6);
    $tf = model_gen_step_ms() / 5.0;
    $rf = model_residency_report();
    model_set_weight_prec(1);
    echo "WPREC int8 step=" . $t8 . " ms  " . $r8 . "\n";
    echo "WPREC f16  step=" . $tf . " ms  " . $rf . "\n";
    if ($g8 == $gf) {
        echo "WPREC: PASS (ids identical across int8 and f16 weight residency)\n";
    } else {
        echo "WPREC: FAIL int8={$g8} f16={$gf}\n";
    }

    // The API layer's parsers, checked without a socket: these are the parts that would silently
    // misread a request.
    $req = '{"model":"m","messages":[{"role":"system","content":"be brief"},'
         . '{"role":"user","content":"first"},{"role":"user","content":"The capital"}],'
         . '"temperature":0.7,"top_p":0.9,"max_tokens":12}';
    $afail = 0;
    if (api_str_last($req, "content") != "The capital") {
        echo "  api[last-content] FAIL got=" . api_str_last($req, "content") . "\n";
        $afail++;
    }
    if (api_str($req, "role") != "system") {
        echo "  api[first-role] FAIL got=" . api_str($req, "role") . "\n";
        $afail++;
    }
    if (api_num($req, "max_tokens", 0) != 12) {
        echo "  api[num] FAIL\n";
        $afail++;
    }
    $t = api_float($req, "temperature", 0.0) - 0.7;
    if ($t > 0.0001 || $t < -0.0001) {
        echo "  api[float] FAIL got=" . api_float($req, "temperature", 0.0) . "\n";
        $afail++;
    }
    if (api_float($req, "top_p", 0.0) < 0.899 || api_float($req, "top_p", 0.0) > 0.901) {
        echo "  api[float-dec] FAIL\n";
        $afail++;
    }
    if (api_esc("a\"b\nc") == "a\"b\nc") {
        echo "  api[escape] FAIL (no escaping applied)\n";
        $afail++;
    }
    // Multi-turn history must render EVERY turn, in order; the exact CSV makes order testable.
    $conv = '{"messages":[{"role":"system","content":"be brief"},{"role":"user","content":"hi"},'
          . '{"role":"assistant","content":"hello"},{"role":"user","content":"again"}]}';
    $want = tok_chat("system", "be brief") . "," . tok_chat("user", "hi") . ","
          . tok_chat("assistant", "hello") . "," . tok_chat("user", "again");
    if (api_history($conv) != $want) {
        echo "  api[history] FAIL got=" . api_history($conv) . " want=" . $want . "\n";
        $afail++;
    }
    // Anthropic puts the system prompt outside "messages".
    $anth = '{"system":"be terse","messages":[{"role":"user","content":"hi"}]}';
    $wantA = tok_chat("system", "be terse") . "," . tok_chat("user", "hi");
    if (api_history($anth) != $wantA) {
        echo "  api[anthropic-system] FAIL got=" . api_history($anth) . "\n";
        $afail++;
    }
    // JSON-object mode's structural check, on cases that do not need the model.
    $jcases = ['{"a":1}', '[1,2]', '{"a":"}"}', '  {"x":[1,2]}  '];
    for ($i = 0; $i < 4; $i++) {
        if (api_json_ok($jcases[$i]) != 1) {
            echo "  api[json-ok-" . $i . "] FAIL accepted-shape rejected\n";
            $afail++;
        }
    }
    $jbad = ['{"a":1', 'not json', '{"a":1} trailing', '}'];
    for ($i = 0; $i < 4; $i++) {
        if (api_json_ok($jbad[$i]) != 0) {
            echo "  api[json-bad-" . $i . "] FAIL broken-shape accepted\n";
            $afail++;
        }
    }
    $el = api_list('"Paris","London"');
    if (count($el) != 2 || $el[0] != "Paris" || $el[1] != "London") {
        echo "  api[list] FAIL n=" . count($el) . " e0=" . $el[0] . "\n";
        $afail++;
    }
    $el2 = api_list('"New York","Los Angeles"');
    $el3 = api_list('"a,b","c"');
    if (count($el2) != 2 || $el2[0] != "New York" || $el2[1] != "Los Angeles"
        || count($el3) != 2 || $el3[0] != "a,b") {
        echo "  api[list-spaces] FAIL\n";
        $afail++;
    }
    // Tool-call plumbing, all deterministic (no model needed).
    $tbody = '{"tools":[{"type":"function","function":{"name":"get_weather","parameters":{}}},'
           . '{"type":"function","function":{"name":"search","parameters":{}}}],'
           . '"tool_choice":{"type":"function","function":{"name":"search"}}}';
    $tn = api_tool_names($tbody);
    if (count($tn) != 2 || $tn[0] != "get_weather" || $tn[1] != "search") {
        echo "  api[tool-names] FAIL n=" . count($tn) . "\n";
        $afail++;
    }
    if (api_tool_choice($tbody) != "search") {
        echo "  api[tool-choice] FAIL got=" . api_tool_choice($tbody) . "\n";
        $afail++;
    }
    if (strpos(api_tool_call_json('{"name":"get_weather","arguments":{}}', $tn), "get_weather") === false) {
        echo "  api[tool-call] FAIL declared name not turned into a call\n";
        $afail++;
    }
    if (api_tool_call_json('{"name":"undeclared","arguments":{}}', $tn) != "") {
        echo "  api[tool-undeclared] FAIL accepted a function that was not declared\n";
        $afail++;
    }
    if (api_tool_call_json("not json", $tn) != "") {
        echo "  api[tool-nonjson] FAIL accepted non-JSON as a call\n";
        $afail++;
    }
    // Anthropic declares tools name-first and selects with {"type":"tool","name":X}.
    $abody = '{"tools":[{"name":"get_weather","description":"w","input_schema":{"type":"object"}}],'
           . '"tool_choice":{"type":"tool","name":"get_weather"}}';
    $atn = api_tool_names($abody);
    if (count($atn) != 1 || $atn[0] != "get_weather" || api_tool_choice($abody) != "get_weather") {
        echo "  api[anth-tools] FAIL n=" . count($atn) . " choice=" . api_tool_choice($abody) . "\n";
        $afail++;
    }
    $ablk = api_anthropic_tool_block(api_tool_call_json('{"name":"get_weather","arguments":{}}', $atn));
    if (strpos($ablk, "\"type\":\"tool_use\"") === false || strpos($ablk, "\"name\":\"get_weather\"") === false
        || strpos($ablk, "\"input\":{}") === false) {
        echo "  api[anth-block] FAIL got=" . $ablk . "\n";
        $afail++;
    }
    if (api_anthropic_tool_block("") != "") {
        echo "  api[anth-block-empty] FAIL empty call produced a block\n";
        $afail++;
    }
    // Flat-schema arguments: enum/const properties enumerate; free-form strings do not.
    $sbody = '{"tools":[{"type":"function","function":{"name":"get_weather","parameters":'
           . '{"type":"object","properties":{"city":{"type":"string","enum":["Paris","London"]},'
           . '"unit":{"type":"string","const":"c"}}}}}],'
           . '"tool_choice":{"type":"function","function":{"name":"get_weather"}}}';
    $sc = api_schema_arg_candidates($sbody, "get_weather");
    if (count($sc) != 2 || $sc[0] != "{\"city\":\"Paris\",\"unit\":\"c\"}"
        || $sc[1] != "{\"city\":\"London\",\"unit\":\"c\"}") {
        echo "  api[schema-args] FAIL n=" . count($sc) . "\n";
        $afail++;
    }
    $sfree = '{"tools":[{"function":{"name":"f","parameters":{"properties":'
           . '{"q":{"type":"string"}}}}}]}';
    if (count(api_schema_arg_candidates($sfree, "f")) != 0) {
        echo "  api[schema-args-free] FAIL enumerated a free-form string\n";
        $afail++;
    }
    // Boolean and bounded-integer properties are finite; unbounded numbers are not.
    $nbody = '{"tools":[{"type":"function","function":{"name":"book","parameters":{"type":"object",'
           . '"properties":{"days":{"type":"integer","minimum":1,"maximum":3},'
           . '"express":{"type":"boolean"}}}}}],'
           . '"tool_choice":{"type":"function","function":{"name":"book"}}}';
    $nc = api_schema_arg_candidates($nbody, "book");
    if (count($nc) != 6 || $nc[0] != "{\"days\":1,\"express\":true}"
        || $nc[1] != "{\"days\":1,\"express\":false}") {
        echo "  api[schema-typed] FAIL n=" . count($nc) . "\n";
        $afail++;
    }
    $ubody = '{"tools":[{"function":{"name":"f","parameters":{"properties":'
           . '{"n":{"type":"integer"}}}}}]}';
    if (count(api_schema_arg_candidates($ubody, "f")) != 0) {
        echo "  api[schema-unbounded] FAIL enumerated an unbounded integer\n";
        $afail++;
    }
    $wbody = '{"tools":[{"function":{"name":"f","parameters":{"properties":'
           . '{"n":{"type":"integer","minimum":0,"maximum":100}}}}}]}';
    if (count(api_schema_arg_candidates($wbody, "f")) != 0) {
        echo "  api[schema-wide] FAIL enumerated a 101-value range\n";
        $afail++;
    }
    $patBody = '{"tools":[{"type":"function","function":{"name":"pick","parameters":{"type":"object",'
             . '"properties":{"unit":{"type":"string","pattern":"^(celsius|fahrenheit)$"}}}}}],'
             . '"tool_choice":{"type":"function","function":{"name":"pick"}}}';
    $patC = api_schema_arg_candidates($patBody, "pick");
    if (count($patC) != 2 || $patC[0] != "{\"unit\":\"celsius\"}"
        || $patC[1] != "{\"unit\":\"fahrenheit\"}") {
        echo "  api[schema-pattern] FAIL n=" . count($patC) . "\n";
        $afail++;
    }
    // Structured outputs: response_format.json_schema feeds the same finite-candidate machinery.
    $rfbody = '{"response_format":{"type":"json_schema","json_schema":{"name":"answer",'
            . '"schema":{"type":"object","properties":{"city":{"type":"string","enum":["Paris","London"]},'
            . '"express":{"type":"boolean"}}}}},"messages":[{"role":"user","content":"x"}]}';
    $rcands = api_response_schema_candidates($rfbody);
    if (count($rcands) != 4 || $rcands[0] != "{\"city\":\"Paris\",\"express\":true}") {
        echo "  api[response-schema] FAIL n=" . count($rcands) . "\n";
        $afail++;
    }
    if (count(api_response_schema_candidates('{"response_format":{"type":"json_object"}}')) != 0) {
        echo "  api[response-schema-none] FAIL produced candidates without json_schema\n";
        $afail++;
    }
    // stop / stop_sequences parsing and earliest cut.
    $sl1 = api_stop_list('{"stop":["a","b"]}');
    $sl2 = api_stop_list('{"stop":"END"}');
    $sl3 = api_stop_list('{"stop_sequences":["x","y"]}');
    if (count($sl1) != 2 || $sl1[0] != "a" || count($sl2) != 1 || $sl2[0] != "END"
        || count($sl3) != 2 || $sl3[1] != "y") {
        echo "  api[stop-list] FAIL parsing mismatch\n";
        $afail++;
    }
    if (api_stop_cut("abcdef", ["cd", "ab"]) != 0 || api_stop_cut("abcdef", ["zz"]) != -1) {
        echo "  api[stop-cut] FAIL earliest-cut mismatch\n";
        $afail++;
    }
    if (api_unesc("{\\\"city\\\":\\\"Paris\\\"}") != "{\"city\":\"Paris\"}") {
        echo "  api[unesc] FAIL\n";
        $afail++;
    }
    // /v1/models lists the loaded model even with an empty store, and ids drop ".gguf".
    $mj = api_models_json();
    if (api_json_ok($mj) != 1 || strpos($mj, gguf_meta_str("general.architecture")) === false) {
        echo "  api[models-json] FAIL got=" . substr($mj, 0, 80) . "\n";
        $afail++;
    }
    if (api_model_id("abc.gguf") != "abc" || api_model_id("abc") != "abc") {
        echo "  api[model-id] FAIL\n";
        $afail++;
    }
    if (api_shard_info("m-00001-of-00002.gguf") != "1/2" || api_shard_info("m.gguf") != "") {
        echo "  api[shard-info] FAIL\n";
        $afail++;
    }
    if (api_max_tokens(0) != 64 || api_max_tokens(-5) != 64
        || api_max_tokens(1000) != 512 || api_max_tokens(128) != 128) {
        echo "  api[max-tokens] FAIL\n";
        $afail++;
    }
    if (api_cache_stats("a.kv|100\nb.kv|50\n") != "2|150" || api_cache_stats("") != "0|0") {
        echo "  api[cache-stats] FAIL\n";
        $afail++;
    }
    // Store entries carry the index metadata; duplicate ids are listed once.
    $md = api_models_data("m1.gguf|100|qwen2|24|896\ndup.gguf|1|x|1|1\ndup.gguf|2|x|2|2\n", "qwen2");
    if (strpos($md, "\"id\":\"m1\",") === false || strpos($md, "\"bytes\":100") === false
        || strpos($md, "\"layers\":24") === false || strpos($md, "\"architecture\":\"qwen2\"") === false
        || count(explode("\"id\":\"dup\",", $md)) != 2
        || count(explode("\"id\":\"qwen2\",", $md)) != 2) {
        echo "  api[models-meta] FAIL got=" . $md . "\n";
        $afail++;
    }
    // Tool history uses the model's own template blocks, not a "tool" role header.
    // api_text drops control tokens, so compare ids against the expected wire text encoded.
    $want_tr = "<|im_start|>user\n<tool_response>\n18C\n</tool_response><|im_end|>\n";
    if (tok_tool_result("18C") != tok_encode($want_tr)) {
        echo "  api[tool-result] FAIL ids differ from the template's block\n";
        $afail++;
    }
    $want_tc = "<|im_start|>assistant\n<tool_call>\n{\"name\": \"get_weather\", \"arguments\": "
             . "{\"city\":\"Paris\"}}\n</tool_call><|im_end|>\n";
    if (tok_tool_call("get_weather", "{\"city\":\"Paris\"}") != tok_encode($want_tc)) {
        echo "  api[tool-call-hist] FAIL ids differ from the template's block\n";
        $afail++;
    }
    $hbody = '{"messages":[{"role":"user","content":"hi"},{"role":"assistant","content":null,'
           . '"tool_calls":[{"id":"call_1","type":"function","function":{"name":"get_weather",'
           . '"arguments":"{\"city\":\"Paris\"}"}}]},{"role":"tool","content":"18C"}]}';
    $want_h = "<|im_start|>user\nhi<|im_end|>\n" . $want_tc . $want_tr;
    if (api_history($hbody) != tok_encode($want_h)) {
        echo "  api[tool-history] FAIL ids differ from the expected prompt\n";
        $afail++;
    }
    // Consecutive tool results share one user turn (template groups them).
    $gbody = '{"messages":[{"role":"user","content":"hi"},'
           . '{"role":"assistant","content":null,"tool_calls":[{"id":"call_1","type":"function",'
           . '"function":{"name":"get_weather","arguments":"{\"city\":\"Paris\"}"}}]},'
           . '{"role":"tool","content":"18C"},{"role":"tool","content":"sunny"}]}';
    $want_g = "<|im_start|>user\nhi<|im_end|>\n" . $want_tc
            . "<|im_start|>user\n<tool_response>\n18C\n</tool_response>\n"
            . "<tool_response>\nsunny\n</tool_response><|im_end|>\n";
    if (api_history($gbody) != tok_encode($want_g)) {
        echo "  api[tool-group] FAIL consecutive tool results not grouped\n";
        $afail++;
    }
    // Anthropic parallel results: several tool_result blocks inside ONE user message.
    $pbody = '{"messages":[{"role":"user","content":[{"type":"text","text":"hi"}]},'
           . '{"role":"assistant","content":[{"type":"tool_use","id":"toolu_1","name":"get_weather",'
           . '"input":{"city":"Paris"}}]},'
           . '{"role":"user","content":[{"type":"tool_result","tool_use_id":"toolu_1","content":"18C"},'
           . '{"type":"tool_result","tool_use_id":"toolu_2","content":"12C"}]}]}';
    $want_p = "<|im_start|>user\nhi<|im_end|>\n" . $want_tc
            . "<|im_start|>user\n<tool_response>\n18C\n</tool_response>\n"
            . "<tool_response>\n12C\n</tool_response><|im_end|>\n";
    if (api_history($pbody) != tok_encode($want_p)) {
        echo "  api[tool-multiblock] FAIL parallel tool_result blocks not grouped\n";
        $afail++;
    }
    // tool_result.content as text blocks (Anthropic allows both shapes; blocks join by newline).
    $bbody = '{"messages":[{"role":"user","content":[{"type":"text","text":"hi"}]},'
           . '{"role":"assistant","content":[{"type":"tool_use","id":"toolu_1","name":"get_weather",'
           . '"input":{"city":"Paris"}}]},'
           . '{"role":"user","content":[{"type":"tool_result","tool_use_id":"toolu_1","content":'
           . '[{"type":"text","text":"18C"},{"type":"text","text":"sunny"}]}]}]}';
    $want_bb = "<|im_start|>user\nhi<|im_end|>\n" . $want_tc
             . "<|im_start|>user\n<tool_response>\n18C\nsunny\n</tool_response><|im_end|>\n";
    if (api_history($bbody) != tok_encode($want_bb)) {
        echo "  api[tool-content-blocks] FAIL text-block content not rendered\n";
        $afail++;
    }
    // Parallel assistant calls: one assistant turn with two <tool_call> blocks, both shapes.
    $want_c2 = "<|im_start|>assistant\n<tool_call>\n{\"name\": \"get_weather\", \"arguments\": "
             . "{\"city\":\"Paris\"}}\n</tool_call>\n<tool_call>\n{\"name\": \"get_weather\", "
             . "\"arguments\": {\"city\":\"London\"}}\n</tool_call><|im_end|>\n";
    $mbody = '{"messages":[{"role":"user","content":"hi"},{"role":"assistant","content":null,'
           . '"tool_calls":[{"id":"call_1","type":"function","function":{"name":"get_weather",'
           . '"arguments":"{\"city\":\"Paris\"}"}},{"id":"call_2","type":"function",'
           . '"function":{"name":"get_weather","arguments":"{\"city\":\"London\"}"}}]},'
           . '{"role":"tool","content":"18C"},{"role":"tool","content":"12C"}]}';
    $want_m = "<|im_start|>user\nhi<|im_end|>\n" . $want_c2
            . "<|im_start|>user\n<tool_response>\n18C\n</tool_response>\n"
            . "<tool_response>\n12C\n</tool_response><|im_end|>\n";
    if (api_history($mbody) != tok_encode($want_m)) {
        echo "  api[tool-multicall] FAIL parallel assistant calls not grouped\n";
        $afail++;
    }
    $ambody = '{"messages":[{"role":"user","content":[{"type":"text","text":"hi"}]},'
            . '{"role":"assistant","content":[{"type":"tool_use","id":"toolu_1","name":"get_weather",'
            . '"input":{"city":"Paris"}},{"type":"tool_use","id":"toolu_2","name":"get_weather",'
            . '"input":{"city":"London"}}]},'
            . '{"role":"user","content":[{"type":"tool_result","tool_use_id":"toolu_1","content":"18C"},'
            . '{"type":"tool_result","tool_use_id":"toolu_2","content":"12C"}]}]}';
    if (api_history($ambody) != tok_encode($want_m)) {
        echo "  api[anth-multicall] FAIL Anthropic parallel calls differ\n";
        $afail++;
    }
    // Declared tools land in the first system turn, exactly as the template writes them.
    $tobj = '{"type":"function","function":{"name":"get_weather","parameters":{"type":"object"}}}';
    $tbody2 = '{"messages":[{"role":"user","content":"x"}],"tools":[' . $tobj . ']}';
    if (api_tools_objects($tbody2) != $tobj) {
        echo "  api[tools-objects] FAIL extraction mismatch\n";
        $afail++;
    }
    $want_tp = "\n\n# Tools\n\nYou may call one or more functions to assist with the user query.\n\n"
             . "You are provided with function signatures within <tools></tools> XML tags:\n<tools>\n"
             . $tobj . "\n</tools>\n\nFor each function call, return a json object with function name "
             . "and arguments within <tool_call></tool_call> XML tags:\n<tool_call>\n"
             . "{\"name\": <function-name>, \"arguments\": <args-json-object>}\n</tool_call>";
    if (api_tools_prompt($tbody2) != $want_tp) {
        echo "  api[tools-prompt] FAIL differs from the template's system section\n";
        $afail++;
    }
    $want_th = "<|im_start|>system\nYou are Qwen, created by Alibaba Cloud. You are a helpful assistant."
             . $want_tp . "<|im_end|>\n<|im_start|>user\nx<|im_end|>\n";
    if (api_history($tbody2) != tok_encode($want_th)) {
        echo "  api[tools-history] FAIL tools not merged into the first system turn\n";
        $afail++;
    }
    // Anthropic array-shaped history: text block, tool_use block, tool_result block.
    $abody = '{"messages":[{"role":"user","content":[{"type":"text","text":"hi"}]},'
           . '{"role":"assistant","content":[{"type":"tool_use","id":"toolu_1","name":"get_weather",'
           . '"input":{"city":"Paris"}}]},'
           . '{"role":"user","content":[{"type":"tool_result","tool_use_id":"toolu_1","content":"18C"}]}]}';
    if (api_history($abody) != tok_encode($want_h)) {
        echo "  api[anth-history] FAIL array-shaped blocks not rendered\n";
        $afail++;
    }
    // Anthropic system given as text blocks is joined with a newline.
    $sysbody = '{"system":[{"type":"text","text":"Be brief."},{"type":"text","text":"No lists."}],'
             . '"messages":[{"role":"user","content":"hi"}]}';
    $want_sys = "<|im_start|>system\nBe brief.\nNo lists.<|im_end|>\n<|im_start|>user\nhi<|im_end|>\n";
    if (api_history($sysbody) != tok_encode($want_sys)) {
        echo "  api[anth-system] FAIL system blocks not joined into the system turn\n";
        $afail++;
    }
    if (api_system_text('{"instructions":"Be terse."}') != "Be terse.") {
        echo "  api[instructions] FAIL\n";
        $afail++;
    }
    if (api_system_text('{"instructions":[{"type":"text","text":"Be terse."},{"type":"text","text":"No lists."}],"input":"hi"}')
        != "Be terse.\nNo lists.") {
        echo "  api[instructions-array] FAIL\n";
        $afail++;
    }
    $devBody = '{"messages":[{"role":"developer","content":"Be brief."},{"role":"user","content":"hi"}]}';
    $wantDev = "<|im_start|>system\nBe brief.<|im_end|>\n<|im_start|>user\nhi<|im_end|>\n";
    if (api_history($devBody) != tok_encode($wantDev)) {
        echo "  api[developer-role] FAIL\n";
        $afail++;
    }
    if (api_tool_call_json("<tool_call>\n{\"name\":\"get_weather\",\"arguments\":{}}\n</tool_call>", $tn) == "") {
        echo "  api[tool-xml] FAIL <tool_call> wrapper not accepted\n";
        $afail++;
    }
    echo $afail == 0 ? "API-UNIT: PASS\n" : "API-UNIT: {$afail} FAILURE(S)\n";

    // Streaming must be the same engine as batching: step the session token by token and require
    // the ids to equal what generate() produced for the same prompt.
    $sids = tok_chat("user", "The capital of France is") . "," . tok_chat_open("assistant");
    $spre = count(explode(",", $sids));
    $gb = explode(",", model_generate(0, $sids, 6));
    // The batched path records the eos id; the streaming path stops before emitting it, so the
    // comparison drops it here. Anything else differing would be a real disagreement.
    $bgen = "";
    for ($i = $spre; $i < count($gb); $i++) {
        if ((int) $gb[$i] == model_eos_id()) {
            break;
        }
        $bgen = $bgen == "" ? $gb[$i] : $bgen . "," . $gb[$i];
    }
    $sgen = "";
    $sok = model_session_start(0, $sids);
    if ($sok == 0) {
        for ($i = 0; $i < 6; $i++) {
            $tid = model_session_next(0, 0.0, 1.0);
            if ($tid < 0) {
                break;
            }
            $sgen = $sgen == "" ? "" . $tid : $sgen . "," . $tid;
        }
    }
    if ($sok != 0 || $sgen != $bgen) {
        echo "STREAM-UNIT: FAIL batch={$bgen} stream={$sgen} (start=" . $sok . ")\n";
    } else {
        echo "STREAM-UNIT: PASS (session stepping == batched generate, 6 tokens)\n";
    }

    // SESSION-SNAP: park A after 2 tokens, run B, resume A - the resumed ids must equal an
    // uninterrupted run (and likewise for B). This is the interleaving primitive, not a speedup.
    $pA = tok_chat("user", "Alpha alpha alpha.") . "," . tok_chat_open("assistant");
    $pB = tok_chat("user", "Beta beta beta beta.") . "," . tok_chat_open("assistant");
    $snapOk = 1;
    $stA = model_session_start(0, $pA);
    if ($stA != 0) {
        $snapOk = 0;
    }
    $seqA1 = "";
    for ($i = 0; $i < 2 && $snapOk == 1; $i++) {
        $t = model_session_next(0, 0.0, 1.0);
        if ($t < 0) {
            $snapOk = 0;
            break;
        }
        $seqA1 = $seqA1 == "" ? "" . $t : $seqA1 . "," . $t;
    }
    $sv0 = $snapOk == 1 ? model_session_save(0) : -1;
    $stB = $snapOk == 1 ? model_session_start(0, $pB) : -1;
    if ($sv0 != 1 || $stB != 0) {
        $snapOk = 0;
    }
    $seqB1 = "";
    for ($i = 0; $i < 2 && $snapOk == 1; $i++) {
        $t = model_session_next(0, 0.0, 1.0);
        if ($t < 0) {
            $snapOk = 0;
            break;
        }
        $seqB1 = $seqB1 == "" ? "" . $t : $seqB1 . "," . $t;
    }
    if ($snapOk == 1 && model_session_save(1) != 1) {
        $snapOk = 0;
    }
    if ($snapOk == 1 && model_session_restore(0) != 1) {
        $snapOk = 0;
    }
    $seqA2 = "";
    for ($i = 0; $i < 3 && $snapOk == 1; $i++) {
        $t = model_session_next(0, 0.0, 1.0);
        if ($t < 0) {
            $snapOk = 0;
            break;
        }
        $seqA2 = $seqA2 == "" ? "" . $t : $seqA2 . "," . $t;
    }
    if ($snapOk == 1 && model_session_restore(1) != 1) {
        $snapOk = 0;
    }
    $seqB2 = "";
    for ($i = 0; $i < 3 && $snapOk == 1; $i++) {
        $t = model_session_next(0, 0.0, 1.0);
        if ($t < 0) {
            $snapOk = 0;
            break;
        }
        $seqB2 = $seqB2 == "" ? "" . $t : $seqB2 . "," . $t;
    }
    $refA = "";
    $refB = "";
    if ($snapOk == 1) {
        model_session_start(0, $pA);
        for ($i = 0; $i < 5; $i++) {
            $t = model_session_next(0, 0.0, 1.0);
            if ($t < 0) {
                break;
            }
            $refA = $refA == "" ? "" . $t : $refA . "," . $t;
        }
        model_session_start(0, $pB);
        for ($i = 0; $i < 5; $i++) {
            $t = model_session_next(0, 0.0, 1.0);
            if ($t < 0) {
                break;
            }
            $refB = $refB == "" ? "" . $t : $refB . "," . $t;
        }
    }
    $gotA = $seqA1 . ($seqA2 == "" ? "" : "," . $seqA2);
    $gotB = $seqB1 . ($seqB2 == "" ? "" : "," . $seqB2);
    if ($snapOk == 1 && $refA != "" && $refB != "" && $gotA == $refA && $gotB == $refB) {
        echo "SESSION-SNAP: PASS (parked/resumed two conversations, ids identical)\n";
    } else {
        echo "SESSION-SNAP: FAIL stA=" . $stA . " sv0=" . $sv0 . " stB=" . $stB
            . " a=[" . $gotA . "] refA=[" . $refA . "] b=[" . $gotB . "] refB=[" . $refB . "]\n";
    }

    // SESSION-PAR: interleave two parked conversations token by token. Each stream must match
    // its own uninterrupted run; the wall time includes every snapshot switch on purpose.
    $pP = tok_chat("user", "Paris is the capital.") . "," . tok_chat_open("assistant");
    $pQ = tok_chat("user", "Berlin is a city.") . "," . tok_chat_open("assistant");
    $seqP = "";
    $seqQ = "";
    $parOk = 1;
    $parStage = 0;
    if (model_session_start(0, $pP) != 0) {
        $parOk = 0;
    }
    if ($parOk == 1 && model_session_save(0) != 1) {
        $parOk = 0;
        $parStage = 10;
    }
    if ($parOk == 1 && model_session_start(0, $pQ) != 0) {
        $parOk = 0;
        $parStage = 20;
    }
    if ($parOk == 1 && model_session_save(1) != 1) {
        $parOk = 0;
        $parStage = 30;
    }
    $tPar0 = microtime(true);
    for ($i = 0; $i < 8 && $parOk == 1; $i++) {
        if (model_session_restore(0) != 1) {
            $parOk = 0;
            $parStage = 41;
            break;
        }
        $t = model_session_next(0, 0.0, 1.0);
        if ($t < 0) {
            break;      // eos: the stream ended early, which the reference run also does
        }
        $seqP = $seqP == "" ? "" . $t : $seqP . "," . $t;
        if (model_session_save(0) != 1) {
            $parOk = 0;
            $parStage = 43;
            break;
        }
        if (model_session_restore(1) != 1) {
            $parOk = 0;
            $parStage = 44;
            break;
        }
        $t2 = model_session_next(0, 0.0, 1.0);
        if ($t2 < 0) {
            break;      // eos: same truncation as the reference run
        }
        $seqQ = $seqQ == "" ? "" . $t2 : $seqQ . "," . $t2;
        if (model_session_save(1) != 1) {
            $parOk = 0;
            $parStage = 46;
            break;
        }
    }
    $msPar = (microtime(true) - $tPar0) * 1000.0;
    $refP = "";
    $refQ = "";
    $stP = -9;
    $stQ = -9;
    if ($parOk == 1) {
        $stP = model_session_start(0, $pP);
        for ($i = 0; $i < 8; $i++) {
            $t = model_session_next(0, 0.0, 1.0);
            if ($t < 0) {
                break;
            }
            $refP = $refP == "" ? "" . $t : $refP . "," . $t;
        }
        $stQ = model_session_start(0, $pQ);
        for ($i = 0; $i < 8; $i++) {
            $t = model_session_next(0, 0.0, 1.0);
            if ($t < 0) {
                break;
            }
            $refQ = $refQ == "" ? "" . $t : $refQ . "," . $t;
        }
    }
    if ($parOk == 1 && $refP != "" && $refQ != "" && $seqP == $refP && $seqQ == $refQ) {
        echo "SESSION-PAR: PASS (16 tokens interleaved, ids identical; "
            . $msPar . " ms incl switches)\n";
    } else {
        echo "SESSION-PAR: FAIL stage=" . $parStage . " stP=" . $stP . " stQ=" . $stQ . " p=[" . $seqP . "/" . $refP
            . "] q=[" . $seqQ . "/" . $refQ . "]\n";
    }

    // Speculative decoding (KFlash-style accept/reject, prompt-lookup drafter): drafting and
    // batched verification must not change the output. The text repeats, so the lookup hits.
    $specTxt = "";
    for ($i = 0; $i < 3; $i++) {
        $specTxt = $specTxt . "The capital of France is Paris. The largest ocean is the Pacific. ";
    }
    // End on a word, not on a lone space token: a tail containing the trailing-space token only
    // occurs once, so the lookup would never find an earlier copy of it.
    $specTxt = $specTxt . "The capital of France is";
    $specIds = tok_encode($specTxt);
    // Diagnostic: does the prompt's last 3-gram actually repeat earlier? (Batching is not the
    // issue if this is zero - the drafter simply has nothing to propose.)
    $sv = explode(",", $specIds);
    $sn = count($sv);
    $tail3 = $sv[$sn - 3] . "," . $sv[$sn - 2] . "," . $sv[$sn - 1];
    $shits = 0;
    for ($i = 0; $i + 3 < $sn; $i++) {
        if ($sv[$i] . "," . $sv[$i + 1] . "," . $sv[$i + 2] == $tail3) {
            $shits++;
        }
    }
    echo "SPEC-DBG prompt_tokens=" . $sn . " tail3_earlier_hits=" . $shits . "\n";

    // FR-06.4: the draft length must be chosen by measured cost on THIS device, not hard-coded
    // from someone else's benchmark. Sweep k on the same prompt and report cost per accepted
    // token, so the default has a number behind it.
    $sweep = "";
    $sweepRef = model_generate(0, $specIds, 24);   // plain decoding reference for this comparison
    for ($kk = 2; $kk <= 8; $kk = $kk + 3) {
        $t = microtime(true);
        $g = model_spec_generate(0, $specIds, 24, $kk);
        $ms = (microtime(true) - $t) * 1000.0;
        // Report length and prefix separately: a shorter list ending at eos is a contract
        // nuance, a differing prefix would be a real bug.
        $gp = explode(",", $g);
        $rp = explode(",", $sweepRef);
        $pn = count($gp) < count($rp) ? count($gp) : count($rp);
        $same = 1;
        for ($q = 0; $q < $pn; $q++) {
            if ($gp[$q] != $rp[$q]) {
                $same = 0;
                break;
            }
        }
        $sweep = $sweep . "  k=" . $kk . " " . $ms . " ms " . model_spec_stats()
            . " len=" . count($gp) . "/" . count($rp)
            . " prefix=" . ($same == 1 ? "ok" : "DIFF@" . $q) . "\n";
    }
    echo "SPEC-SWEEP\n" . $sweep;
    // k=0 disables drafting entirely: this exercises only the loop's position bookkeeping, so a
    // failure here is a bug in the driver, not in batching.
    $gShort = model_generate(0, $specIds, 8);
    $gSpec0 = model_spec_generate(0, $specIds, 8, 0);
    if ($gShort != $gSpec0) {
        echo "SPEC0: FAIL\n  greedy={$gShort}\n  spec0={$gSpec0}\n";
    } else {
        echo "SPEC0: PASS (k=0 reduces to greedy stepping)\n";
    }
    $tA = microtime(true);
    $gRef = model_generate(0, $specIds, 24);
    $msA = (microtime(true) - $tA) * 1000.0;
    $tB = microtime(true);
    $gSpec = model_spec_generate(0, $specIds, 24, 4);
    $msB = (microtime(true) - $tB) * 1000.0;
    $spd = $msB > 0.0 ? $msA / $msB : 0.0;
    echo "SPEC " . model_spec_stats() . " greedy=" . $msA . " ms spec=" . $msB
        . " ms speedup=" . $spd . "x\n";
    if ($gRef == $gSpec) {
        echo "SPEC: PASS (identical ids; speculative path is the same engine)\n";
    } else {
        echo "SPEC: FAIL\n  greedy={$gRef}\n  spec={$gSpec}\n";
    }

    // FR-06.1: ONE verify pass over a TREE of draft branches, with attention masked so branches
    // cannot see each other. Output must still equal greedy, because every accepted token is the
    // model's own argmax along its own path.
    $tT = microtime(true);
    $gTree = model_spec_tree(0, $specIds, 24, 2, 3);
    $msT = (microtime(true) - $tT) * 1000.0;
    echo "SPEC-TREE bins=2 depth=3 " . $msT . " ms " . model_spec_stats() . "\n";
    if ($gTree == $gRef) {
        echo "SPEC-TREE: PASS (tree verify: ids identical)\n";
    } else {
        echo "SPEC-TREE: FAIL len=" . count(explode(",", $gTree)) . "/"
            . count(explode(",", $gRef)) . "\n";
    }
    // FR-06.4 for trees: the shape is a measured choice too. Compare against the best chain
    // (k=8 was 355 ms) so "tree or chain" stops being an opinion.
    $tsweep = "";
    $shapes = "2,2;2,3;3,2";
    foreach (explode(";", $shapes) as $sh) {
        $bins = (int) substr($sh, 0, 1);
        $dep = (int) substr($sh, 2, 1);
        $t2 = microtime(true);
        $g2 = model_spec_tree(0, $specIds, 24, $bins, $dep);
        $ms2 = (microtime(true) - $t2) * 1000.0;
        $ok2 = $g2 == $gRef ? "ok" : "DIFF";
        $tsweep = $tsweep . "  tree bins=" . $bins . " depth=" . $dep . " " . $ms2 . " ms "
            . model_spec_stats() . " " . $ok2 . "\n";
    }
    echo "SPEC-TREE-SWEEP\n" . $tsweep;

    // The case a tree is FOR: the nearest repeat of the tail has a continuation the model will
    // now reject, while an OLDER repeat has the one it wants. Chain has only the nearest branch;
    // the tree can fall through to the older one. Both must still equal greedy.
    $dec = tok_encode("The answer is 42. Lorem ipsum dolor sit amet consectetur adipiscing. "
        . "The answer is 99. The answer is");
    $decRef = model_generate(0, $dec, 12);
    $t3 = microtime(true);
    $decChain = model_spec_generate(0, $dec, 12, 3);
    $msC = (microtime(true) - $t3) * 1000.0;
    $cStats = model_spec_stats();
    $t4 = microtime(true);
    $decTree = model_spec_tree(0, $dec, 12, 2, 3);
    $msT2 = (microtime(true) - $t4) * 1000.0;
    $tStats = model_spec_stats();
    echo "SPEC-AMB chain=" . $msC . " ms " . $cStats . "\n";
    echo "SPEC-AMB tree =" . $msT2 . " ms " . $tStats . "\n";
    if ($decChain == $decRef && $decTree == $decRef) {
        echo "SPEC-AMB: PASS (both greedy-identical; numbers above say which drafting paid)\n";
    } else {
        echo "SPEC-AMB: FAIL chain_ok=" . ($decChain == $decRef ? "yes" : "no")
            . " tree_ok=" . ($decTree == $decRef ? "yes" : "no") . "\n";
    }

    // Constrained decoding (enum): forbid the model's own favourite answer and confirm the
    // forbidden token cannot appear at all. This is the real counterpart to json_object.
    $cids = tok_chat("user", "The capital of France is") . "," . tok_chat_open("assistant");
    $cpre = count(explode(",", $cids));
    $cout = api_constrained($cids, ["London", "Berlin"], 12, 0);
    $cp = explode(",", $cout);
    $ctext = "";
    for ($i = $cpre; $i < count($cp); $i++) {
        $ctext = $ctext . gguf_token_str((int) $cp[$i]);
    }
    $ctext = str_replace("Ġ", " ", $ctext);
    $cfree = model_generate(0, $cids, 3);
    echo "SPEC-CONSTRAIN text=[" . $ctext . "] unconstrained=" . $cfree . "\n";
    if ($ctext == "London" || $ctext == "Berlin") {
        echo "SPEC-CONSTRAIN: PASS (only the allowed answers are reachable)\n";
    } else {
        echo "SPEC-CONSTRAIN: FAIL (emitted a forbidden continuation)\n";
    }

    // FR-08 grammar increment: the model itself fills a free-form JSON string, but the vocabulary
    // is limited to a charset (lowercase + space). The closing quote ends the value; the trailing
    // brace is forced, so the object is always parseable.
    $strPre = '{"city":"';
    $strIds = tok_chat("user", "The capital of France is") . "," . tok_chat_open("assistant");
    $strOut = api_string_value($strIds, $strPre, "abcdefghijklmnopqrstuvwxyz ", 8, 0);
    $strParts = explode(",", $strOut);
    $strBase = count(explode(",", $strIds));
    $strText = "";
    for ($i = $strBase; $i < count($strParts); $i++) {
        $strText = $strText . gguf_token_str((int) $strParts[$i]);
    }
    $strText = str_replace("Ġ", " ", $strText);
    $strShape = preg_match('/^\{"city":"[a-z ]*"\}$/', $strText);
    echo "SCHEMA-STR text=[" . $strText . "]\n";
    if ($strOut != "" && $strShape == 1 && api_json_ok($strText) == 1) {
        echo "SCHEMA-STR: PASS (grammar filled a free-form string; only charset bytes emitted)\n";
    } else {
        echo "SCHEMA-STR: FAIL shape=" . $strShape . "\n";
    }

    // Escaped string: force one complete \" pair, then continue the charset grammar; the pair
    // must survive and the object must still parse.
    $escPre = "{\"s\":\"a";
    $escIds = tok_chat("user", "Write a quoted note.") . "," . tok_chat_open("assistant");
    $escOut = api_string_value($escIds, $escPre, "bcdefghijklmnopqrstuvwxyz ", 4, 0, 1);
    $escParts = explode(",", $escOut);
    $escBase = count(explode(",", $escIds));
    $escText = "";
    for ($i = $escBase; $i < count($escParts); $i++) {
        $escText = $escText . gguf_token_str((int) $escParts[$i]);
    }
    $escText = str_replace("Ġ", " ", $escText);
    $escStart = substr($escText, 0, 9) == "{\"s\":\"a\\\"";
    $escJson = api_json_ok($escText);
    echo "SCHEMA-ESCAPE text=[" . $escText . "] json=" . $escJson . "\n";
    if ($escOut != "" && $escStart == 1 && $escJson == 1) {
        echo "SCHEMA-ESCAPE: PASS (escaped quote kept inside the JSON string)\n";
    } else {
        echo "SCHEMA-ESCAPE: FAIL start=" . $escStart . " json=" . $escJson . "\n";
    }

    // Bare integer: digits (optional leading "-"), so the value is valid JSON whatever the model
    // wanted to say next. The arithmetic prompt makes a negative answer likely.
    $numIds = tok_chat("user", "What is 5 minus 10? The answer is") . "," . tok_chat_open("assistant");
    $nOut = api_number_value($numIds, "answer", 0, 6, 0);
    $nParts = explode(",", $nOut);
    $nBase = count(explode(",", $numIds));
    $nText = "";
    for ($i = $nBase; $i < count($nParts); $i++) {
        $nText = $nText . gguf_token_str((int) $nParts[$i]);
    }
    $nText = str_replace("Ġ", " ", $nText);
    $nShape = preg_match('/^\{"answer":-?(0|[1-9][0-9]*)\}$/', $nText);
    $nMinus = api_punct_ids("-");
    echo "SCHEMA-NUM text=[" . $nText . "] minus_ids=" . ($nMinus == "" ? "none" : "ok") . "\n";
    if ($nOut != "" && $nShape == 1 && api_json_ok($nText) == 1 && $nMinus != "") {
        echo "SCHEMA-NUM: PASS (digit grammar produced a valid JSON integer; sign reachable)\n";
    } else {
        echo "SCHEMA-NUM: FAIL shape=" . $nShape . "\n";
    }

    // Decimal value ("type":"number"): '.' is allowed after the integer part and forces at least
    // one fraction digit; a leading zero can only grow through '.', so JSON stays valid.
    $fIds = tok_chat("user", "What is 1 divided by 2? The answer is") . "," . tok_chat_open("assistant");
    $fOut = api_number_value($fIds, "answer", 1, 8, 0);
    $fParts = explode(",", $fOut);
    $fBase = count(explode(",", $fIds));
    $fText = "";
    for ($i = $fBase; $i < count($fParts); $i++) {
        $fText = $fText . gguf_token_str((int) $fParts[$i]);
    }
    $fText = str_replace("Ġ", " ", $fText);
    $fShape = preg_match('/^\{"answer":-?(0|[1-9][0-9]*)(\.[0-9]+)?\}$/', $fText);
    echo "SCHEMA-FRAC text=[" . $fText . "]\n";
    if ($fOut != "" && $fShape == 1 && api_json_ok($fText) == 1) {
        echo "SCHEMA-FRAC: PASS (numeric grammar kept the value parseable)\n";
    } else {
        echo "SCHEMA-FRAC: FAIL shape=" . $fShape . "\n";
    }

    // One level of nesting: the forced {"capital":{"city":" skeleton and the forced }} close a
    // leaf that the charset grammar filled.
    $zIds = tok_chat("user", "The capital of France is") . "," . tok_chat_open("assistant");
    $zOut = api_string_value_nested($zIds, "capital", "city", "abcdefghijklmnopqrstuvwxyz ", 8, 0);
    $zParts = explode(",", $zOut);
    $zBase = count(explode(",", $zIds));
    $zText = "";
    for ($i = $zBase; $i < count($zParts); $i++) {
        $zText = $zText . gguf_token_str((int) $zParts[$i]);
    }
    $zText = str_replace("Ġ", " ", $zText);
    $zShape = preg_match('/^\{"capital":\{"city":"[a-z ]*"\}\}$/', $zText);
    echo "SCHEMA-NEST text=[" . $zText . "]\n";
    if ($zOut != "" && $zShape == 1 && api_json_ok($zText) == 1) {
        echo "SCHEMA-NEST: PASS (nested leaf filled inside a forced object skeleton)\n";
    } else {
        echo "SCHEMA-NEST: FAIL shape=" . $zShape . "\n";
    }

    // Nested integer leaf: the number grammar inside a forced {"meta":{"count": … }} skeleton.
    $qIds = tok_chat("user", "The answer is") . "," . tok_chat_open("assistant");
    $qOut = api_number_nested($qIds, "meta", "count", 0, 6, 0);
    $qParts = explode(",", $qOut);
    $qBase = count(explode(",", $qIds));
    $qText = "";
    for ($i = $qBase; $i < count($qParts); $i++) {
        $qText = $qText . gguf_token_str((int) $qParts[$i]);
    }
    $qText = str_replace("Ġ", " ", $qText);
    $qShape = preg_match('/^\{"meta":\{"count":-?(0|[1-9][0-9]*)\}\}$/', $qText);
    echo "SCHEMA-NESTNUM text=[" . $qText . "]\n";
    if ($qOut != "" && $qShape == 1 && api_json_ok($qText) == 1) {
        echo "SCHEMA-NESTNUM: PASS (nested integer inside a forced skeleton)\n";
    } else {
        echo "SCHEMA-NESTNUM: FAIL shape=" . $qShape . "\n";
    }

    // Nested object with several leaves: detector returns the inner plan, builder assembles both
    // keys inside the outer braces.
    $noBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"type":"object","properties":{"meta":{"type":"object","properties":{'
        . '"city":{"type":"string"},"count":{"type":"integer"}}}}}}}';
    $noPlan = api_response_schema_nested_object($noBody);
    $nIds2 = tok_chat("user", "The capital of France is") . "," . tok_chat_open("assistant");
    $noOut = api_nested_object_value($nIds2, "meta", "city:s,count:i",
        "abcdefghijklmnopqrstuvwxyz ", 6, 0);
    $noParts = explode(",", $noOut);
    $noBase = count(explode(",", $nIds2));
    $noText = "";
    for ($i = $noBase; $i < count($noParts); $i++) {
        $noText = $noText . gguf_token_str((int) $noParts[$i]);
    }
    $noText = str_replace("Ġ", " ", $noText);
    $noShape = preg_match('/^\{"meta":\{"city":"[a-z ]*","count":-?(0|[1-9][0-9]*)\}\}$/', $noText);
    echo "SCHEMA-NEST-OBJ plan=[" . $noPlan . "] text=[" . $noText . "]\n";
    if ($noPlan == "meta|city:s,count:i" && $noShape == 1 && api_json_ok($noText) == 1) {
        echo "SCHEMA-NEST-OBJ: PASS (nested object with two leaves assembled)\n";
    } else {
        echo "SCHEMA-NEST-OBJ: FAIL shape=" . $noShape . "\n";
    }

    // Two-level nesting: the detector descends to the innermost properties block and the builder
    // forces the whole {"a":{"b": … }} skeleton.
    $dBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"type":"object","properties":{"a":{"type":"object","properties":{'
        . '"b":{"type":"object","properties":{"city":{"type":"string"},'
        . '"count":{"type":"integer"}}}}}}}}}';
    $dPlan = api_response_schema_nested_object($dBody);
    $dIds = tok_chat("user", "The capital of France is") . "," . tok_chat_open("assistant");
    $dOut = api_nested_object_value($dIds, "a/b", "city:s,count:i",
        "abcdefghijklmnopqrstuvwxyz ", 6, 0);
    $dParts = explode(",", $dOut);
    $dBase = count(explode(",", $dIds));
    $dText = "";
    for ($i = $dBase; $i < count($dParts); $i++) {
        $dText = $dText . gguf_token_str((int) $dParts[$i]);
    }
    $dText = str_replace("Ġ", " ", $dText);
    $dShape = preg_match('/^\{"a":\{"b":\{"city":"[a-z ]*","count":-?(0|[1-9][0-9]*)\}\}\}$/',
        $dText);
    echo "SCHEMA-DEEP plan=[" . $dPlan . "] text=[" . $dText . "]\n";
    if ($dPlan == "a/b|city:s,count:i" && $dShape == 1 && api_json_ok($dText) == 1) {
        echo "SCHEMA-DEEP: PASS (two-level nested object assembled)\n";
    } else {
        echo "SCHEMA-DEEP: FAIL shape=" . $dShape . "\n";
    }

    // Three levels: the descent loop needs three iterations, so this also pins the documented
    // "up to 3 levels" limit.
    $d3Body = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"type":"object","properties":{"a":{"type":"object","properties":{'
        . '"b":{"type":"object","properties":{"c":{"type":"object","properties":{'
        . '"city":{"type":"string"},"count":{"type":"integer"}}}}}}}}}}}';
    $d3Plan = api_response_schema_nested_object($d3Body);
    $d3Ids = tok_chat("user", "The capital of France is") . "," . tok_chat_open("assistant");
    $d3Out = api_nested_object_value($d3Ids, "a/b/c", "city:s,count:i",
        "abcdefghijklmnopqrstuvwxyz ", 6, 0);
    $d3Parts = explode(",", $d3Out);
    $d3Base = count(explode(",", $d3Ids));
    $d3Text = "";
    for ($i = $d3Base; $i < count($d3Parts); $i++) {
        $d3Text = $d3Text . gguf_token_str((int) $d3Parts[$i]);
    }
    $d3Text = str_replace("Ġ", " ", $d3Text);
    $d3Shape = preg_match('/^\{"a":\{"b":\{"c":\{"city":"[a-z ]*","count":-?(0|[1-9][0-9]*)\}\}\}\}$/',
        $d3Text);
    echo "SCHEMA-DEEP3 plan=[" . $d3Plan . "] text=[" . $d3Text . "]\n";
    if ($d3Plan == "a/b/c|city:s,count:i" && $d3Shape == 1 && api_json_ok($d3Text) == 1) {
        echo "SCHEMA-DEEP3: PASS (three-level nested object assembled)\n";
    } else {
        echo "SCHEMA-DEEP3: FAIL shape=" . $d3Shape . "\n";
    }

    // Four levels: same path-driven builder, one more descent iteration.
    $d4Body = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"type":"object","properties":{"a":{"type":"object","properties":{'
        . '"b":{"type":"object","properties":{"c":{"type":"object","properties":{'
        . '"d":{"type":"object","properties":{"city":{"type":"string"},'
        . '"count":{"type":"integer"}}}}}}}}}}}}}}';
    $d4Plan = api_response_schema_nested_object($d4Body);
    $d4Ids = tok_chat("user", "The capital of France is") . "," . tok_chat_open("assistant");
    $d4Out = api_nested_object_value($d4Ids, "a/b/c/d", "city:s,count:i",
        "abcdefghijklmnopqrstuvwxyz ", 6, 0);
    $d4Parts = explode(",", $d4Out);
    $d4Base = count(explode(",", $d4Ids));
    $d4Text = "";
    for ($i = $d4Base; $i < count($d4Parts); $i++) {
        $d4Text = $d4Text . gguf_token_str((int) $d4Parts[$i]);
    }
    $d4Text = str_replace("Ġ", " ", $d4Text);
    $d4Shape = preg_match('/^\{"a":\{"b":\{"c":\{"d":\{"city":"[a-z ]*",'
        . '"count":-?(0|[1-9][0-9]*)\}\}\}\}\}$/', $d4Text);
    echo "SCHEMA-DEEP4 plan=[" . $d4Plan . "] text=[" . $d4Text . "]\n";
    if ($d4Plan == "a/b/c/d|city:s,count:i" && $d4Shape == 1 && api_json_ok($d4Text) == 1) {
        echo "SCHEMA-DEEP4: PASS (four-level nested object assembled)\n";
    } else {
        echo "SCHEMA-DEEP4: FAIL shape=" . $d4Shape . "\n";
    }

    // Six levels: the schema is built programmatically so the brace count cannot drift.
    $d6Keys = ["a", "b", "c", "d", "e", "f"];
    $d6Part = "";
    for ($i = 0; $i < 6; $i++) {
        $d6Part = $d6Part . '{"' . $d6Keys[$i] . '":{"type":"object","properties":';
    }
    $d6Close = "";
    for ($i = 0; $i < 6; $i++) {
        $d6Close = $d6Close . "}}";
    }
    $d6Body = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"type":"object","properties":' . $d6Part
        . '{"city":{"type":"string"},"count":{"type":"integer"}}'
        . $d6Close . '}}}}';
    $d6Plan = api_response_schema_nested_object($d6Body);
    $d6Ids = tok_chat("user", "The capital of France is") . "," . tok_chat_open("assistant");
    $d6Out = api_nested_object_value($d6Ids, "a/b/c/d/e/f", "city:s,count:i",
        "abcdefghijklmnopqrstuvwxyz ", 6, 0);
    $d6Parts = explode(",", $d6Out);
    $d6Base = count(explode(",", $d6Ids));
    $d6Text = "";
    for ($i = $d6Base; $i < count($d6Parts); $i++) {
        $d6Text = $d6Text . gguf_token_str((int) $d6Parts[$i]);
    }
    $d6Text = str_replace("Ġ", " ", $d6Text);
    $d6Shape = preg_match('/^\{"a":\{"b":\{"c":\{"d":\{"e":\{"f":\{"city":"[a-z ]*",'
        . '"count":-?(0|[1-9][0-9]*)\}\}\}\}\}\}\}$/', $d6Text);
    echo "SCHEMA-DEEP6 plan=[" . $d6Plan . "] text=[" . $d6Text . "]\n";
    if ($d6Plan == "a/b/c/d/e/f|city:s,count:i" && $d6Shape == 1 && api_json_ok($d6Text) == 1) {
        echo "SCHEMA-DEEP6: PASS (six-level nested object assembled)\n";
    } else {
        echo "SCHEMA-DEEP6: FAIL shape=" . $d6Shape . "\n";
    }

    // Eight levels: programmatic schema again, one descent iteration per key.
    $d8Keys = ["a", "b", "c", "d", "e", "f", "g", "h"];
    $d8Part = "";
    for ($i = 0; $i < 8; $i++) {
        $d8Part = $d8Part . '{"' . $d8Keys[$i] . '":{"type":"object","properties":';
    }
    $d8Close = "";
    for ($i = 0; $i < 8; $i++) {
        $d8Close = $d8Close . "}}";
    }
    $d8Body = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"type":"object","properties":' . $d8Part
        . '{"city":{"type":"string"},"count":{"type":"integer"}}'
        . $d8Close . '}}}}';
    $d8Plan = api_response_schema_nested_object($d8Body);
    $d8Ids = tok_chat("user", "The capital of France is") . "," . tok_chat_open("assistant");
    $d8Out = api_nested_object_value($d8Ids, "a/b/c/d/e/f/g/h", "city:s,count:i",
        "abcdefghijklmnopqrstuvwxyz ", 6, 0);
    $d8Parts = explode(",", $d8Out);
    $d8Base = count(explode(",", $d8Ids));
    $d8Text = "";
    for ($i = $d8Base; $i < count($d8Parts); $i++) {
        $d8Text = $d8Text . gguf_token_str((int) $d8Parts[$i]);
    }
    $d8Text = str_replace("Ġ", " ", $d8Text);
    $d8Shape = preg_match('/^\{"a":\{"b":\{"c":\{"d":\{"e":\{"f":\{"g":\{"h":'
        . '\{"city":"[a-z ]*","count":-?(0|[1-9][0-9]*)\}\}\}\}\}\}\}\}\}$/', $d8Text);
    echo "SCHEMA-DEEP8 plan=[" . $d8Plan . "] text=[" . $d8Text . "]\n";
    if ($d8Plan == "a/b/c/d/e/f/g/h|city:s,count:i" && $d8Shape == 1
        && api_json_ok($d8Text) == 1) {
        echo "SCHEMA-DEEP8: PASS (eight-level nested object assembled)\n";
    } else {
        echo "SCHEMA-DEEP8: FAIL shape=" . $d8Shape . "\n";
    }

    // Twelve levels: past the old eight-level cap, still one descent iteration per key.
    $d12Keys = ["a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k", "l"];
    $d12Part = "";
    for ($i = 0; $i < 12; $i++) {
        $d12Part = $d12Part . '{"' . $d12Keys[$i] . '":{"type":"object","properties":';
    }
    $d12Close = "";
    for ($i = 0; $i < 12; $i++) {
        $d12Close = $d12Close . "}}";
    }
    $d12Body = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"type":"object","properties":' . $d12Part
        . '{"city":{"type":"string"},"count":{"type":"integer"}}'
        . $d12Close . '}}}}';
    $d12Plan = api_response_schema_nested_object($d12Body);
    $d12Ids = tok_chat("user", "The capital of France is") . "," . tok_chat_open("assistant");
    $d12Out = api_nested_object_value($d12Ids, "a/b/c/d/e/f/g/h/i/j/k/l", "city:s,count:i",
        "abcdefghijklmnopqrstuvwxyz ", 6, 0);
    $d12Parts = explode(",", $d12Out);
    $d12Base = count(explode(",", $d12Ids));
    $d12Text = "";
    for ($i = $d12Base; $i < count($d12Parts); $i++) {
        $d12Text = $d12Text . gguf_token_str((int) $d12Parts[$i]);
    }
    $d12Text = str_replace("Ġ", " ", $d12Text);
    $d12Pat = '/^\{"a":';
    for ($i = 1; $i < 12; $i++) {
        $d12Pat = $d12Pat . '\{"' . $d12Keys[$i] . '":';
    }
    $d12Pat = $d12Pat . '\{"city":"[a-z ]*","count":-?(0|[1-9][0-9]*)\}';
    for ($i = 0; $i < 12; $i++) {
        $d12Pat = $d12Pat . '\}';
    }
    $d12Pat = $d12Pat . '$/';
    $d12Shape = preg_match($d12Pat, $d12Text);
    echo "SCHEMA-DEEP12 plan=[" . $d12Plan . "] text=[" . $d12Text . "]\n";
    if ($d12Plan == "a/b/c/d/e/f/g/h/i/j/k/l|city:s,count:i" && $d12Shape == 1
        && api_json_ok($d12Text) == 1) {
        echo "SCHEMA-DEEP12: PASS (twelve-level nested object assembled)\n";
    } else {
        echo "SCHEMA-DEEP12: FAIL shape=" . $d12Shape . "\n";
    }

    // Twenty levels: the descent cap is 32 now, so keys a..t prove depth past the old 12.
    $d20Keys = ["a", "b", "c", "d", "e", "f", "g", "h", "i", "j",
        "k", "l", "m", "n", "o", "p", "q", "r", "s", "t"];
    $d20Part = "";
    for ($i = 0; $i < 20; $i++) {
        $d20Part = $d20Part . '{"' . $d20Keys[$i] . '":{"type":"object","properties":';
    }
    $d20Close = "";
    for ($i = 0; $i < 20; $i++) {
        $d20Close = $d20Close . "}}";
    }
    $d20Body = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"type":"object","properties":' . $d20Part
        . '{"city":{"type":"string"},"count":{"type":"integer"}}'
        . $d20Close . '}}}}';
    $d20Plan = api_response_schema_nested_object($d20Body);
    $d20Path = "";
    for ($i = 0; $i < 20; $i++) {
        $d20Path = $i == 0 ? $d20Keys[$i] : $d20Path . "/" . $d20Keys[$i];
    }
    $d20Ids = tok_chat("user", "The capital of France is") . "," . tok_chat_open("assistant");
    $d20Out = api_nested_object_value($d20Ids, $d20Path, "city:s,count:i",
        "abcdefghijklmnopqrstuvwxyz ", 6, 0);
    $d20Parts = explode(",", $d20Out);
    $d20Base = count(explode(",", $d20Ids));
    $d20Text = "";
    for ($i = $d20Base; $i < count($d20Parts); $i++) {
        $d20Text = $d20Text . gguf_token_str((int) $d20Parts[$i]);
    }
    $d20Text = str_replace("Ġ", " ", $d20Text);
    $d20Pat = '/^\{"a":';
    for ($i = 1; $i < 20; $i++) {
        $d20Pat = $d20Pat . '\{"' . $d20Keys[$i] . '":';
    }
    $d20Pat = $d20Pat . '\{"city":"[a-z ]*","count":-?(0|[1-9][0-9]*)\}';
    for ($i = 0; $i < 20; $i++) {
        $d20Pat = $d20Pat . '\}';
    }
    $d20Pat = $d20Pat . '$/';
    $d20Shape = preg_match($d20Pat, $d20Text);
    echo "SCHEMA-DEEP20 plan=[" . $d20Plan . "] text=[" . $d20Text . "]\n";
    if ($d20Plan == $d20Path . "|city:s,count:i" && $d20Shape == 1
        && api_json_ok($d20Text) == 1) {
        echo "SCHEMA-DEEP20: PASS (twenty-level nested object assembled)\n";
    } else {
        echo "SCHEMA-DEEP20: FAIL shape=" . $d20Shape . "\n";
    }

    // Forty levels: exercises the enlarged window and the depth-scaled forced-prefix budget.
    $d40Keys = ["a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k", "l", "m",
        "n", "o", "p", "q", "r", "s", "t", "u", "v", "w", "x", "y", "z",
        "a0", "a1", "a2", "a3", "a4", "a5", "a6", "a7", "a8", "a9", "a10", "a11", "a12", "a13"];
    $d40Part = "";
    for ($i = 0; $i < 40; $i++) {
        $d40Part = $d40Part . '{"' . $d40Keys[$i] . '":{"type":"object","properties":';
    }
    $d40Close = "";
    for ($i = 0; $i < 40; $i++) {
        $d40Close = $d40Close . "}}";
    }
    $d40Body = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"type":"object","properties":' . $d40Part
        . '{"city":{"type":"string"},"count":{"type":"integer"}}'
        . $d40Close . '}}}}';
    $d40Plan = api_response_schema_nested_object($d40Body);
    $d40Path = "";
    for ($i = 0; $i < 40; $i++) {
        $d40Path = $i == 0 ? $d40Keys[$i] : $d40Path . "/" . $d40Keys[$i];
    }
    $d40Ids = tok_chat("user", "The capital of France is") . "," . tok_chat_open("assistant");
    $d40Out = api_nested_object_value($d40Ids, $d40Path, "city:s,count:i",
        "abcdefghijklmnopqrstuvwxyz ", 6, 0);
    $d40Parts = explode(",", $d40Out);
    $d40Base = count(explode(",", $d40Ids));
    $d40Text = "";
    for ($i = $d40Base; $i < count($d40Parts); $i++) {
        $d40Text = $d40Text . gguf_token_str((int) $d40Parts[$i]);
    }
    $d40Text = str_replace("Ġ", " ", $d40Text);
    $d40Pat = '/^\{"a":';
    for ($i = 1; $i < 40; $i++) {
        $d40Pat = $d40Pat . '\{"' . $d40Keys[$i] . '":';
    }
    $d40Pat = $d40Pat . '\{"city":"[a-z ]*","count":-?(0|[1-9][0-9]*)\}';
    for ($i = 0; $i < 40; $i++) {
        $d40Pat = $d40Pat . '\}';
    }
    $d40Pat = $d40Pat . '$/';
    $d40Shape = preg_match($d40Pat, $d40Text);
    echo "SCHEMA-DEEP40 plan=[" . $d40Plan . "] text=[" . $d40Text . "]\n";
    if ($d40Plan == $d40Path . "|city:s,count:i" && $d40Shape == 1
        && api_json_ok($d40Text) == 1) {
        echo "SCHEMA-DEEP40: PASS (forty-level nested object assembled)\n";
    } else {
        echo "SCHEMA-DEEP40: FAIL shape=" . $d40Shape . "\n";
    }

    // Eighty levels: window and forced-prefix budgets scale with depth, cap is 128 now.
    $d80Keys = [];
    for ($i = 0; $i < 80; $i++) {
        $d80Keys[] = "k" . $i;
    }
    $d80Part = "";
    for ($i = 0; $i < 80; $i++) {
        $d80Part = $d80Part . '{"' . $d80Keys[$i] . '":{"type":"object","properties":';
    }
    $d80Close = "";
    for ($i = 0; $i < 80; $i++) {
        $d80Close = $d80Close . "}}";
    }
    $d80Body = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"type":"object","properties":' . $d80Part
        . '{"city":{"type":"string"},"count":{"type":"integer"}}'
        . $d80Close . '}}}}';
    $d80Plan = api_response_schema_nested_object($d80Body);
    $d80Path = "";
    for ($i = 0; $i < 80; $i++) {
        $d80Path = $i == 0 ? $d80Keys[$i] : $d80Path . "/" . $d80Keys[$i];
    }
    $d80Ids = tok_chat("user", "The capital of France is") . "," . tok_chat_open("assistant");
    $d80Out = api_nested_object_value($d80Ids, $d80Path, "city:s,count:i",
        "abcdefghijklmnopqrstuvwxyz ", 6, 0);
    $d80Parts = explode(",", $d80Out);
    $d80Base = count(explode(",", $d80Ids));
    $d80Text = "";
    for ($i = $d80Base; $i < count($d80Parts); $i++) {
        $d80Text = $d80Text . gguf_token_str((int) $d80Parts[$i]);
    }
    $d80Text = str_replace("Ġ", " ", $d80Text);
    $d80Pat = '/^\{"k0":';
    for ($i = 1; $i < 80; $i++) {
        $d80Pat = $d80Pat . '\{"' . $d80Keys[$i] . '":';
    }
    $d80Pat = $d80Pat . '\{"city":"[a-z ]*","count":-?(0|[1-9][0-9]*)\}';
    for ($i = 0; $i < 80; $i++) {
        $d80Pat = $d80Pat . '\}';
    }
    $d80Pat = $d80Pat . '$/';
    $d80Shape = preg_match($d80Pat, $d80Text);
    echo "SCHEMA-DEEP80 plan=[" . $d80Plan . "] text=[" . $d80Text . "]\n";
    if ($d80Plan == $d80Path . "|city:s,count:i" && $d80Shape == 1
        && api_json_ok($d80Text) == 1) {
        echo "SCHEMA-DEEP80: PASS (eighty-level nested object assembled)\n";
    } else {
        echo "SCHEMA-DEEP80: FAIL shape=" . $d80Shape . "\n";
    }

    // Mixed object: a string leaf and an integer leaf at the same level, keys forced in order.
    $mIds = tok_chat("user", "The capital of France is") . "," . tok_chat_open("assistant");
    $mOut = api_object_mixed($mIds, "city:s,population:i", "abcdefghijklmnopqrstuvwxyz ", 6, 0);
    $mParts = explode(",", $mOut);
    $mBase = count(explode(",", $mIds));
    $mText = "";
    for ($i = $mBase; $i < count($mParts); $i++) {
        $mText = $mText . gguf_token_str((int) $mParts[$i]);
    }
    $mText = str_replace("Ġ", " ", $mText);
    $mShape = preg_match('/^\{"city":"[a-z ]*","population":-?(0|[1-9][0-9]*)\}$/', $mText);
    echo "SCHEMA-MIX text=[" . $mText . "]\n";
    if ($mOut != "" && $mShape == 1 && api_json_ok($mText) == 1) {
        echo "SCHEMA-MIX: PASS (string and number leaves assembled in schema order)\n";
    } else {
        echo "SCHEMA-MIX: FAIL shape=" . $mShape . "\n";
    }

    // Array of strings: after each element only "," or "]" is reachable, so the list closes
    // cleanly whatever the model wanted to say next.
    $aIds = tok_chat("user", "Name two French cities.") . "," . tok_chat_open("assistant");
    $aOut = api_array_string_value($aIds, "cities", "abcdefghijklmnopqrstuvwxyz ", 4, 6, 0);
    $aParts = explode(",", $aOut);
    $aBase = count(explode(",", $aIds));
    $aText = "";
    for ($i = $aBase; $i < count($aParts); $i++) {
        $aText = $aText . gguf_token_str((int) $aParts[$i]);
    }
    $aText = str_replace("Ġ", " ", $aText);
    $aShape = preg_match('/^\{"cities":\["[a-z ]*"(,"[a-z ]*")*\]\}$/', $aText);
    echo "SCHEMA-ARR text=[" . $aText . "]\n";
    if ($aOut != "" && $aShape == 1 && api_json_ok($aText) == 1) {
        echo "SCHEMA-ARR: PASS (array elements charset-constrained; list closed by grammar)\n";
    } else {
        echo "SCHEMA-ARR: FAIL shape=" . $aShape . "\n";
    }

    // Two-dimensional string array: inner lists and the outer list each close via the grammar.
    $twIds = tok_chat("user", "Name two lists of two colors.") . "," . tok_chat_open("assistant");
    $twOut = api_array2_string_value($twIds, "m", "abcdefghijklmnopqrstuvwxyz ", 2, 2, 4, 0);
    $twParts = explode(",", $twOut);
    $twBase = count(explode(",", $twIds));
    $twText = "";
    for ($i = $twBase; $i < count($twParts); $i++) {
        $twText = $twText . gguf_token_str((int) $twParts[$i]);
    }
    $twText = str_replace("Ġ", " ", $twText);
    $twShape = preg_match('/^\{"m":\[\["[a-z ]*"(,"[a-z ]*")*\](,\["[a-z ]*"(,"[a-z ]*")*\])*\]\}$/',
        $twText);
    echo "SCHEMA-ARR2 text=[" . $twText . "]\n";
    if ($twOut != "" && $twShape == 1 && api_json_ok($twText) == 1) {
        echo "SCHEMA-ARR2: PASS (nested arrays assembled and both lists closed)\n";
    } else {
        echo "SCHEMA-ARR2: FAIL shape=" . $twShape . "\n";
    }

    // Three-dimensional string array: every level closes through the grammar or its budget.
    $t3Ids = tok_chat("user", "Name two lists of two lists of colors.") . ","
        . tok_chat_open("assistant");
    $t3Out = api_array3_string_value($t3Ids, "m", "abcdefghijklmnopqrstuvwxyz ", 2, 2, 2, 4, 0);
    $t3Parts = explode(",", $t3Out);
    $t3Base = count(explode(",", $t3Ids));
    $t3Text = "";
    for ($i = $t3Base; $i < count($t3Parts); $i++) {
        $t3Text = $t3Text . gguf_token_str((int) $t3Parts[$i]);
    }
    $t3Text = str_replace("Ġ", " ", $t3Text);
    $t3Shape = preg_match('/^\{"m":\[\[\[.*\]\]\]\}$/', $t3Text);
    echo "SCHEMA-ARR3 text=[" . $t3Text . "]\n";
    if ($t3Out != "" && $t3Shape == 1 && api_json_ok($t3Text) == 1) {
        echo "SCHEMA-ARR3: PASS (three-level array assembled and closed)\n";
    } else {
        echo "SCHEMA-ARR3: FAIL shape=" . $t3Shape . "\n";
    }

    // Four-dimensional string array: one more budget/close level around the three-level builder.
    $f4Ids = tok_chat("user", "Name two lists of two lists of two lists of colors.") . ","
        . tok_chat_open("assistant");
    $f4Out = api_array4_string_value($f4Ids, "m", "abcdefghijklmnopqrstuvwxyz ", 2, 2, 2, 2, 3, 0);
    $f4Parts = explode(",", $f4Out);
    $f4Base = count(explode(",", $f4Ids));
    $f4Text = "";
    for ($i = $f4Base; $i < count($f4Parts); $i++) {
        $f4Text = $f4Text . gguf_token_str((int) $f4Parts[$i]);
    }
    $f4Text = str_replace("Ġ", " ", $f4Text);
    $f4Shape = preg_match('/^\{"m":\[\[\[\[.*\]\]\]\]\}$/', $f4Text);
    echo "SCHEMA-ARR4 text=[" . $f4Text . "]\n";
    if ($f4Out != "" && $f4Shape == 1 && api_json_ok($f4Text) == 1) {
        echo "SCHEMA-ARR4: PASS (four-level array assembled and closed)\n";
    } else {
        echo "SCHEMA-ARR4: FAIL shape=" . $f4Shape . "\n";
    }

    // Five-dimensional string array: one more budget/close level around the four-level builder.
    $f5Ids = tok_chat("user", "Name two lists of two lists of two lists of two lists of colors.") . ","
        . tok_chat_open("assistant");
    $f5Out = api_array5_string_value($f5Ids, "m", "abcdefghijklmnopqrstuvwxyz ", 2, 2, 2, 2, 2, 3, 0);
    $f5Parts = explode(",", $f5Out);
    $f5Base = count(explode(",", $f5Ids));
    $f5Text = "";
    for ($i = $f5Base; $i < count($f5Parts); $i++) {
        $f5Text = $f5Text . gguf_token_str((int) $f5Parts[$i]);
    }
    $f5Text = str_replace("Ġ", " ", $f5Text);
    $f5Shape = preg_match('/^\{"m":\[\[\[\[\[.*\]\]\]\]\]\}$/', $f5Text);
    echo "SCHEMA-ARR5 text=[" . $f5Text . "]\n";
    if ($f5Out != "" && $f5Shape == 1 && api_json_ok($f5Text) == 1) {
        echo "SCHEMA-ARR5: PASS (five-level array assembled and closed)\n";
    } else {
        echo "SCHEMA-ARR5: FAIL shape=" . $f5Shape . "\n";
    }

    // Six-dimensional string array: one more budget/close level around the five-level builder.
    $f6Ids = tok_chat("user", "Name two lists of two lists of two lists of two lists of two lists of colors.") . ","
        . tok_chat_open("assistant");
    $f6Out = api_array6_string_value($f6Ids, "m", "abcdefghijklmnopqrstuvwxyz ", 2, 2, 2, 2, 2, 2, 3, 0);
    $f6Parts = explode(",", $f6Out);
    $f6Base = count(explode(",", $f6Ids));
    $f6Text = "";
    for ($i = $f6Base; $i < count($f6Parts); $i++) {
        $f6Text = $f6Text . gguf_token_str((int) $f6Parts[$i]);
    }
    $f6Text = str_replace("Ġ", " ", $f6Text);
    $f6Shape = preg_match('/^\{"m":\[\[\[\[\[\[.*\]\]\]\]\]\]\}$/', $f6Text);
    echo "SCHEMA-ARR6 text=[" . $f6Text . "]\n";
    if ($f6Out != "" && $f6Shape == 1 && api_json_ok($f6Text) == 1) {
        echo "SCHEMA-ARR6: PASS (six-level array assembled and closed)\n";
    } else {
        echo "SCHEMA-ARR6: FAIL shape=" . $f6Shape . "\n";
    }

    // Seven levels: the generic N-dimensional builder takes over from the fixed 2..6 ones.
    $a7Open = "";
    for ($i = 0; $i < 7; $i++) {
        $a7Open = $a7Open . '{"type":"array","items":';
    }
    $a7Close = "";
    for ($i = 0; $i < 7; $i++) {
        $a7Close = $a7Close . "}";
    }
    $a7Body = '{"json_schema":{"name":"r","schema":{"type":"object","properties":{"m":'
        . $a7Open . '{"type":"string"}' . $a7Close . '}}}}';
    $a7Det = api_response_schema_array_n_string($a7Body);
    $a7Ids = tok_chat("user", "Name a color.") . "," . tok_chat_open("assistant");
    $a7Out = api_array_n_string_value($a7Ids, "m", 7, "abcdefghijklmnopqrstuvwxyz ", 1, 3, 0);
    $a7Parts = explode(",", $a7Out);
    $a7Base = count(explode(",", $a7Ids));
    $a7Text = "";
    for ($i = $a7Base; $i < count($a7Parts); $i++) {
        $a7Text = $a7Text . gguf_token_str((int) $a7Parts[$i]);
    }
    $a7Text = str_replace("Ġ", " ", $a7Text);
    $a7Pat = '/^\{"m":';
    for ($i = 0; $i < 7; $i++) {
        $a7Pat = $a7Pat . '\[';
    }
    $a7Pat = $a7Pat . '"[a-z ]*"';
    for ($i = 0; $i < 7; $i++) {
        $a7Pat = $a7Pat . '\]';
    }
    $a7Pat = $a7Pat . '\}$/';
    $a7Shape = preg_match($a7Pat, $a7Text);
    echo "SCHEMA-ARR7 det=[" . $a7Det . "] text=[" . $a7Text . "]\n";
    if ($a7Det == "m|7" && $a7Out != "" && $a7Shape == 1 && api_json_ok($a7Text) == 1) {
        echo "SCHEMA-ARR7: PASS (seven-level array via generic builder)\n";
    } else {
        echo "SCHEMA-ARR7: FAIL det=[" . $a7Det . "] shape=" . $a7Shape . "\n";
    }

    // Seven levels of integer arrays through the generic integer builder.
    $i7Open = "";
    for ($i = 0; $i < 7; $i++) {
        $i7Open = $i7Open . '{"type":"array","items":';
    }
    $i7Close = "";
    for ($i = 0; $i < 7; $i++) {
        $i7Close = $i7Close . "}";
    }
    $i7Body = '{"json_schema":{"name":"r","schema":{"type":"object","properties":{"m":'
        . $i7Open . '{"type":"integer"}' . $i7Close . '}}}}';
    $i7Det = api_response_schema_array_n_int($i7Body);
    $i7Ids = tok_chat("user", "Pick a number.") . "," . tok_chat_open("assistant");
    $i7Out = api_array_n_int_value($i7Ids, "m", 7, 1, 3, 0);
    $i7Parts = explode(",", $i7Out);
    $i7Base = count(explode(",", $i7Ids));
    $i7Text = "";
    for ($i = $i7Base; $i < count($i7Parts); $i++) {
        $i7Text = $i7Text . gguf_token_str((int) $i7Parts[$i]);
    }
    $i7Text = str_replace("Ġ", " ", $i7Text);
    $i7Pat = '/^\{"m":';
    for ($i = 0; $i < 7; $i++) {
        $i7Pat = $i7Pat . '\[';
    }
    $i7Pat = $i7Pat . '(0|[1-9][0-9]*)';
    for ($i = 0; $i < 7; $i++) {
        $i7Pat = $i7Pat . '\]';
    }
    $i7Pat = $i7Pat . '\}$/';
    $i7Shape = preg_match($i7Pat, $i7Text);
    echo "SCHEMA-ARR7-INT det=[" . $i7Det . "] text=[" . $i7Text . "]\n";
    if ($i7Det == "m|7" && $i7Out != "" && $i7Shape == 1 && api_json_ok($i7Text) == 1) {
        echo "SCHEMA-ARR7-INT: PASS (seven-level integer array via generic builder)\n";
    } else {
        echo "SCHEMA-ARR7-INT: FAIL det=[" . $i7Det . "] shape=" . $i7Shape . "\n";
    }

    // Unbounded multipleOf: 4 model-chosen digits + a forced 3-digit suffix divisible by 7.
    $mbBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"type":"object","properties":{"n":{"type":"integer","multipleOf":7}}}}}';
    $mbIds = tok_chat("user", "Give me a multiple of seven.") . "," . tok_chat_open("assistant");
    $mbDet = api_response_schema_multiple_unbounded($mbBody);
    $mbOut = api_multiple_value($mbIds, "n", 7, 4, 0);
    $mbParts = explode(",", $mbOut);
    $mbBase = count(explode(",", $mbIds));
    $mbText = "";
    for ($i = $mbBase; $i < count($mbParts); $i++) {
        $mbText = $mbText . gguf_token_str((int) $mbParts[$i]);
    }
    $mbNum = [];
    $mbOk = preg_match('/^\{"n":([1-9][0-9]*)\}$/', $mbText, $mbNum);
    $mbVal = $mbOk == 1 ? (int) $mbNum[1] : -1;
    echo "SCHEMA-MULT-UNBOUND det=[" . $mbDet . "] text=[" . $mbText . "] val=" . $mbVal . "\n";
    if ($mbDet == "n|7" && $mbOk == 1 && $mbVal % 7 == 0 && api_json_ok($mbText) == 1) {
        echo "SCHEMA-MULT-UNBOUND: PASS (unbounded multipleOf value is divisible)\n";
    } else {
        echo "SCHEMA-MULT-UNBOUND: FAIL shape=" . $mbOk . "\n";
    }

    // Top-level array schema (no object wrapper): the output is the array itself.
    $taBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"type":"array","items":{"type":"string"}}}}}';
    $taIds = tok_chat("user", "Name two colors.") . "," . tok_chat_open("assistant");
    $taOut = api_top_array_string_value($taIds, "abcdefghijklmnopqrstuvwxyz ", 4, 4, 0);
    $taParts = explode(",", $taOut);
    $taBase = count(explode(",", $taIds));
    $taText = "";
    for ($i = $taBase; $i < count($taParts); $i++) {
        $taText = $taText . gguf_token_str((int) $taParts[$i]);
    }
    $taText = str_replace("Ġ", " ", $taText);
    $taShape = preg_match('/^\["[a-z ]*"(,"[a-z ]*")*\]$/', $taText);
    echo "SCHEMA-TOPARR det=" . api_response_schema_top_array_string($taBody)
        . " text=[" . $taText . "]\n";
    if (api_response_schema_top_array_string($taBody) == 1 && $taShape == 1
        && api_json_ok($taText) == 1) {
        echo "SCHEMA-TOPARR: PASS (top-level string array constrained)\n";
    } else {
        echo "SCHEMA-TOPARR: FAIL shape=" . $taShape . "\n";
    }

    // Top-level number array: digits only, list closed by the grammar.
    $tnBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"type":"array","items":{"type":"integer"}}}}}';
    $tnIds = tok_chat("user", "Name three numbers.") . "," . tok_chat_open("assistant");
    $tnOut = api_top_array_number_value($tnIds, 0, 3, 3, 0);
    $tnParts = explode(",", $tnOut);
    $tnBase = count(explode(",", $tnIds));
    $tnText = "";
    for ($i = $tnBase; $i < count($tnParts); $i++) {
        $tnText = $tnText . gguf_token_str((int) $tnParts[$i]);
    }
    $tnText = str_replace("Ġ", " ", $tnText);
    $tnShape = preg_match('/^\[-?(0|[1-9][0-9]*)(,-?(0|[1-9][0-9]*))*\]$/', $tnText);
    echo "SCHEMA-TOPARR-NUM det=" . api_response_schema_top_array_number($tnBody)
        . " text=[" . $tnText . "]\n";
    if (api_response_schema_top_array_number($tnBody) == 0 && $tnShape == 1
        && api_json_ok($tnText) == 1) {
        echo "SCHEMA-TOPARR-NUM: PASS (top-level number array constrained)\n";
    } else {
        echo "SCHEMA-TOPARR-NUM: FAIL shape=" . $tnShape . "\n";
    }

    // Integer array: digits fill each element for a small budget, then "," or "]" closes it.
    $rIds = tok_chat("user", "Name three numbers.") . "," . tok_chat_open("assistant");
    $rOut = api_array_number_value($rIds, "nums", 0, 4, 3, 0);
    $rParts = explode(",", $rOut);
    $rBase = count(explode(",", $rIds));
    $rText = "";
    for ($i = $rBase; $i < count($rParts); $i++) {
        $rText = $rText . gguf_token_str((int) $rParts[$i]);
    }
    $rText = str_replace("Ġ", " ", $rText);
    $rShape = preg_match('/^\{"nums":\[-?(0|[1-9][0-9]*)(,-?(0|[1-9][0-9]*))*\]\}$/', $rText);
    echo "SCHEMA-ARR-NUM text=[" . $rText . "]\n";
    if ($rOut != "" && $rShape == 1 && api_json_ok($rText) == 1) {
        echo "SCHEMA-ARR-NUM: PASS (number elements valid; list closed by grammar)\n";
    } else {
        echo "SCHEMA-ARR-NUM: FAIL shape=" . $rShape . "\n";
    }

    // multipleOf on a bounded integer: only its multiples may appear as candidates.
    $moSchema = '{"type":"object","properties":{"n":{"type":"integer","minimum":0,'
        . '"maximum":10,"multipleOf":5}}}';
    $moC = api_schema_candidates($moSchema);
    $moJoin = "";
    for ($i = 0; $i < count($moC); $i++) {
        $moJoin = $moJoin . $moC[$i];
    }
    echo "SCHEMA-MULT n=" . count($moC) . " [" . $moJoin . "]\n";
    if (count($moC) == 3 && strpos($moJoin, '{"n":0}') !== false
        && strpos($moJoin, '{"n":5}') !== false && strpos($moJoin, '{"n":10}') !== false) {
        echo "SCHEMA-MULT: PASS (only multiples of 5 enumerated)\n";
    } else {
        echo "SCHEMA-MULT: FAIL\n";
    }

    // Exclusive bounds: integer values must satisfy lo < n < hi (combined with multipleOf here).
    $exSchema = '{"type":"object","properties":{"n":{"type":"integer",'
        . '"exclusiveMinimum":0,"exclusiveMaximum":5,"multipleOf":2}}}';
    $exC = api_schema_candidates($exSchema);
    $exJoin = "";
    for ($i = 0; $i < count($exC); $i++) {
        $exJoin = $exJoin . $exC[$i];
    }
    echo "SCHEMA-EXCL n=" . count($exC) . " [" . $exJoin . "]\n";
    if (count($exC) == 2 && strpos($exJoin, '{"n":2}') !== false
        && strpos($exJoin, '{"n":4}') !== false) {
        echo "SCHEMA-EXCL: PASS (exclusive bounds respected)\n";
    } else {
        echo "SCHEMA-EXCL: FAIL\n";
    }

    // Numeric const and numeric enum both land in the finite candidate set.
    $cnSchema = '{"type":"object","properties":{"n":{"type":"integer","const":42}}}';
    $cnC = api_schema_candidates($cnSchema);
    $cnJoin = "";
    for ($i = 0; $i < count($cnC); $i++) {
        $cnJoin = $cnJoin . $cnC[$i];
    }
    $enSchema = '{"type":"object","properties":{"n":{"enum":[1,3,5]}}}';
    $enC = api_schema_candidates($enSchema);
    $enJoin = "";
    for ($i = 0; $i < count($enC); $i++) {
        $enJoin = $enJoin . $enC[$i];
    }
    echo "SCHEMA-CONST-NUM const=[" . $cnJoin . "] enum=[" . $enJoin . "]\n";
    if (count($cnC) == 1 && $cnJoin == '{"n":42}' && count($enC) == 3
        && strpos($enJoin, '{"n":1}') !== false && strpos($enJoin, '{"n":3}') !== false
        && strpos($enJoin, '{"n":5}') !== false) {
        echo "SCHEMA-CONST-NUM: PASS (numeric const/enum enumerated)\n";
    } else {
        echo "SCHEMA-CONST-NUM: FAIL\n";
    }

    // Floating const / enum keep their decimal form in the candidate JSON.
    $cfSchema = '{"type":"object","properties":{"x":{"type":"number","const":1.5}}}';
    $cfC = api_schema_candidates($cfSchema);
    $cfJoin = "";
    for ($i = 0; $i < count($cfC); $i++) {
        $cfJoin = $cfJoin . $cfC[$i];
    }
    $efSchema = '{"type":"object","properties":{"x":{"type":"number","enum":[0.5,1.5]}}}';
    $efC = api_schema_candidates($efSchema);
    $efJoin = "";
    for ($i = 0; $i < count($efC); $i++) {
        $efJoin = $efJoin . $efC[$i];
    }
    echo "SCHEMA-CONST-FLOAT const=[" . $cfJoin . "] enum=[" . $efJoin . "]\n";
    if (count($cfC) == 1 && $cfJoin == '{"x":1.5}' && count($efC) == 2
        && strpos($efJoin, '{"x":0.5}') !== false && strpos($efJoin, '{"x":1.5}') !== false) {
        echo "SCHEMA-CONST-FLOAT: PASS (floating const/enum enumerated)\n";
    } else {
        echo "SCHEMA-CONST-FLOAT: FAIL\n";
    }

    // Top-level boolean array: finite domain, so the candidate set is the 14 lists of length 1..3.
    $tbBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"type":"array","items":{"type":"boolean"}}}}}';
    $tbC = api_top_bool_candidates();
    $tbIds = tok_chat("user", "Answer yes or no as a list.") . "," . tok_chat_open("assistant");
    $tbOut = api_constrained($tbIds, $tbC, 8, 0);
    $tbParts = explode(",", $tbOut);
    $tbBase = count(explode(",", $tbIds));
    $tbText = "";
    for ($i = $tbBase; $i < count($tbParts); $i++) {
        $tbText = $tbText . gguf_token_str((int) $tbParts[$i]);
    }
    $tbText = str_replace("Ġ", " ", $tbText);
    $tbShape = preg_match('/^\[(true|false)(,(true|false))*\]$/', $tbText);
    echo "SCHEMA-TOPARR-BOOL det=" . api_response_schema_top_array_bool($tbBody)
        . " n=" . count($tbC) . " text=[" . $tbText . "]\n";
    if (api_response_schema_top_array_bool($tbBody) == 1 && count($tbC) == 14 && $tbShape == 1
        && api_json_ok($tbText) == 1) {
        echo "SCHEMA-TOPARR-BOOL: PASS (boolean arrays enumerated)\n";
    } else {
        echo "SCHEMA-TOPARR-BOOL: FAIL shape=" . $tbShape . "\n";
    }

    // Top-level array of objects: plan-driven element assembly without the property wrapper.
    $toIds = tok_chat("user", "Name two people with their age.") . ","
        . tok_chat_open("assistant");
    $toPlan = "name:s,age:i";
    $toOut = api_top_array_object_value($toIds, $toPlan, "abcdefghijklmnopqrstuvwxyz ", 2, 6, 0);
    $toParts = explode(",", $toOut);
    $toBase = count(explode(",", $toIds));
    $toText = "";
    for ($i = $toBase; $i < count($toParts); $i++) {
        $toText = $toText . gguf_token_str((int) $toParts[$i]);
    }
    $toText = str_replace("Ġ", " ", $toText);
    $toShape = preg_match('/^\[\{"name":"[a-z ]*","age":-?(0|[1-9][0-9]*)\}'
        . '(,\{"name":"[a-z ]*","age":-?(0|[1-9][0-9]*)\})*\]$/', $toText);
    echo "SCHEMA-TOPARR-OBJ text=[" . $toText . "]\n";
    if ($toOut != "" && $toShape == 1 && api_json_ok($toText) == 1) {
        echo "SCHEMA-TOPARR-OBJ: PASS (top-level object array assembled)\n";
    } else {
        echo "SCHEMA-TOPARR-OBJ: FAIL shape=" . $toShape . "\n";
    }

    // Top-level object array with one nested object per element: [{"meta":{"name":…,"age":…}}].
    $tn2Ids = tok_chat("user", "Name two people with their age.") . ","
        . tok_chat_open("assistant");
    $tn2Out = api_top_array_nested_object_value($tn2Ids, "meta", "name:s,age:i",
        "abcdefghijklmnopqrstuvwxyz ", 2, 6, 0);
    $tn2Parts = explode(",", $tn2Out);
    $tn2Base = count(explode(",", $tn2Ids));
    $tn2Text = "";
    for ($i = $tn2Base; $i < count($tn2Parts); $i++) {
        $tn2Text = $tn2Text . gguf_token_str((int) $tn2Parts[$i]);
    }
    $tn2Text = str_replace("Ġ", " ", $tn2Text);
    $tn2Shape = preg_match('/^\[\{"meta":\{"name":"[a-z ]*","age":-?(0|[1-9][0-9]*)\}\}'
        . '(,\{"meta":\{"name":"[a-z ]*","age":-?(0|[1-9][0-9]*)\}\})*\]$/', $tn2Text);
    echo "SCHEMA-TOPARR-OBJ2 text=[" . $tn2Text . "]\n";
    if ($tn2Out != "" && $tn2Shape == 1 && api_json_ok($tn2Text) == 1) {
        echo "SCHEMA-TOPARR-OBJ2: PASS (nested object per array element)\n";
    } else {
        echo "SCHEMA-TOPARR-OBJ2: FAIL shape=" . $tn2Shape . "\n";
    }

    // Local $ref: the definition body is inlined before candidate detection.
    $refBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"type":"object","definitions":{"City":{"type":"string","enum":["Paris","London"]}},'
        . '"properties":{"city":{"$ref":"#/definitions/City"}}}}}';
    $refResolved = api_schema_resolve_refs($refBody);
    $refSchema = api_json_object_after($refResolved, "schema");
    $refC = api_schema_candidates($refSchema);
    $refJoin = "";
    for ($i = 0; $i < count($refC); $i++) {
        $refJoin = $refJoin . $refC[$i];
    }
    echo "SCHEMA-REF n=" . count($refC) . " [" . $refJoin . "]\n";
    if (count($refC) == 2 && strpos($refJoin, '"city":"Paris"') !== false
        && strpos($refJoin, '"city":"London"') !== false) {
        echo "SCHEMA-REF: PASS (local \$ref inlined)\n";
    } else {
        echo "SCHEMA-REF: FAIL\n";
    }

    // allOf merge: two object members contribute properties to one finite candidate set.
    $aoBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"allOf":[{"type":"object","properties":{"city":{"type":"string",'
        . '"enum":["Paris","London"]}}},{"type":"object","properties":{"units":{"type":"string",'
        . '"enum":["celsius"]}}}]}}}';
    $aoMerged = api_schema_merge_allof($aoBody);
    $aoSchema = api_json_object_after($aoMerged, "schema");
    $aoC = api_schema_candidates($aoSchema);
    $aoJoin = "";
    for ($i = 0; $i < count($aoC); $i++) {
        $aoJoin = $aoJoin . $aoC[$i];
    }
    echo "SCHEMA-ALLOF n=" . count($aoC) . " [" . $aoJoin . "]\n";
    if (count($aoC) == 2 && strpos($aoJoin, '"city":"Paris","units":"celsius"') !== false
        && strpos($aoJoin, '"city":"London","units":"celsius"') !== false) {
        echo "SCHEMA-ALLOF: PASS (allOf members merged)\n";
    } else {
        echo "SCHEMA-ALLOF: FAIL\n";
    }

    // Property-level oneOf of enum branches: the union becomes the candidate set.
    $ooBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"type":"object","properties":{"city":{"oneOf":[{"type":"string",'
        . '"enum":["Paris"]},{"type":"string","enum":["London"]}]}}}}}';
    $ooMerged = api_schema_flatten_choices($ooBody);
    $ooSchema = api_json_object_after($ooMerged, "schema");
    $ooC = api_schema_candidates($ooSchema);
    $ooJoin = "";
    for ($i = 0; $i < count($ooC); $i++) {
        $ooJoin = $ooJoin . $ooC[$i];
    }
    echo "SCHEMA-ONEOF n=" . count($ooC) . " [" . $ooJoin . "]\n";
    if (count($ooC) == 2 && strpos($ooJoin, '"city":"Paris"') !== false
        && strpos($ooJoin, '"city":"London"') !== false) {
        echo "SCHEMA-ONEOF: PASS (oneOf enum branches unioned)\n";
    } else {
        echo "SCHEMA-ONEOF: FAIL\n";
    }

    // Root-level oneOf: the union of the object branches' candidate sets.
    $roBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"oneOf":[{"type":"object","properties":{"city":{"type":"string",'
        . '"enum":["Paris"]}}},{"type":"object","properties":{"city":{"type":"string",'
        . '"enum":["London"]}}}]}}}';
    $roC = api_response_schema_candidates($roBody);
    $roJoin = "";
    for ($i = 0; $i < count($roC); $i++) {
        $roJoin = $roJoin . $roC[$i];
    }
    echo "SCHEMA-ROOT-ONEOF n=" . count($roC) . " [" . $roJoin . "]\n";
    if (count($roC) == 2 && strpos($roJoin, '"city":"Paris"') !== false
        && strpos($roJoin, '"city":"London"') !== false) {
        echo "SCHEMA-ROOT-ONEOF: PASS (branch candidates unioned)\n";
    } else {
        echo "SCHEMA-ROOT-ONEOF: FAIL\n";
    }

    // Scientific notation survives as a valid JSON number constant / enum value.
    $sciSchema = '{"type":"object","properties":{"x":{"type":"number","const":1e3}}}';
    $sciC = api_schema_candidates($sciSchema);
    $sciJoin = "";
    for ($i = 0; $i < count($sciC); $i++) {
        $sciJoin = $sciJoin . $sciC[$i];
    }
    $senSchema = '{"type":"object","properties":{"x":{"enum":[1e2,2.5e-1]}}}';
    $senC = api_schema_candidates($senSchema);
    $senJoin = "";
    for ($i = 0; $i < count($senC); $i++) {
        $senJoin = $senJoin . $senC[$i];
    }
    echo "SCHEMA-SCI const=[" . $sciJoin . "] enum=[" . $senJoin . "]\n";
    if (count($sciC) == 1 && $sciJoin == '{"x":1e3}' && count($senC) == 2
        && strpos($senJoin, '"x":1e2') !== false && strpos($senJoin, '"x":2.5e-1') !== false) {
        echo "SCHEMA-SCI: PASS (scientific notation enumerated)\n";
    } else {
        echo "SCHEMA-SCI: FAIL\n";
    }

    // Boolean const/enum stay unquoted (a quoted "true" would violate the boolean type).
    $bcSchema = '{"type":"object","properties":{"b":{"type":"boolean","const":true}}}';
    $bcC = api_schema_candidates($bcSchema);
    $bcJoin = "";
    for ($i = 0; $i < count($bcC); $i++) {
        $bcJoin = $bcJoin . $bcC[$i];
    }
    $beSchema = '{"type":"object","properties":{"b":{"type":"boolean","enum":[true,false]}}}';
    $beC = api_schema_candidates($beSchema);
    $beJoin = "";
    for ($i = 0; $i < count($beC); $i++) {
        $beJoin = $beJoin . $beC[$i];
    }
    echo "SCHEMA-BOOL const=[" . $bcJoin . "] enum=[" . $beJoin . "]\n";
    if (count($bcC) == 1 && $bcJoin == '{"b":true}' && count($beC) == 2
        && strpos($beJoin, '{"b":true}') !== false && strpos($beJoin, '{"b":false}') !== false) {
        echo "SCHEMA-BOOL: PASS (boolean const/enum unquoted)\n";
    } else {
        echo "SCHEMA-BOOL: FAIL\n";
    }

    // Array of objects: leaves are filled in plan order, each element is closed by a forced "}",
    // then "," or "]" decides whether another element follows.
    $oIds = tok_chat("user", "Name two people with their age.") . "," . tok_chat_open("assistant");
    $oOut = api_array_object_value($oIds, "people", "name:s,age:i", "abcdefghijklmnopqrstuvwxyz ",
        3, 6, 0);
    $oParts = explode(",", $oOut);
    $oBase = count(explode(",", $oIds));
    $oText = "";
    for ($i = $oBase; $i < count($oParts); $i++) {
        $oText = $oText . gguf_token_str((int) $oParts[$i]);
    }
    $oText = str_replace("Ġ", " ", $oText);
    $oShape = preg_match('/^\{"people":\[\{"name":"[a-z ]*","age":-?(0|[1-9][0-9]*)\}'
        . '(,\{"name":"[a-z ]*","age":-?(0|[1-9][0-9]*)\})*\]\}$/', $oText);
    echo "SCHEMA-ARR-OBJ text=[" . $oText . "]\n";
    if ($oOut != "" && $oShape == 1 && api_json_ok($oText) == 1) {
        echo "SCHEMA-ARR-OBJ: PASS (object elements assembled and array closed)\n";
    } else {
        echo "SCHEMA-ARR-OBJ: FAIL shape=" . $oShape . "\n";
    }

    // Element object whose only property is a string array; detector must claim it before the
    // flat-leaf array-object detector sees the inner "items".
    $oaBody = '{"json_schema":{"name":"r","schema":{"type":"object","properties":{"items":'
        . '{"type":"array","items":{"type":"object","properties":{"tags":{"type":"array",'
        . '"items":{"type":"string"}}}}}}}}}';
    $oaDet = api_response_schema_array_object_array($oaBody);
    $oaIds = tok_chat("user", "List two lists of colors.") . "," . tok_chat_open("assistant");
    $oaOut = api_array_object_array_value($oaIds, "items", "tags", "",
        "abcdefghijklmnopqrstuvwxyz ", 2, 6, 0);
    $oaParts = explode(",", $oaOut);
    $oaBase = count(explode(",", $oaIds));
    $oaText = "";
    for ($i = $oaBase; $i < count($oaParts); $i++) {
        $oaText = $oaText . gguf_token_str((int) $oaParts[$i]);
    }
    $oaText = str_replace("Ġ", " ", $oaText);
    $oaShape = preg_match('/^\{"items":\[\{"tags":\["[a-z ]*"\]\}'
        . '(,\{"tags":\["[a-z ]*"\]\})*\]\}$/', $oaText);
    echo "SCHEMA-ARR-OBJ-ARR det=[" . $oaDet . "] text=[" . $oaText . "]\n";
    if ($oaDet == "items|tags" && $oaOut != "" && $oaShape == 1 && api_json_ok($oaText) == 1) {
        echo "SCHEMA-ARR-OBJ-ARR: PASS (string array inside object-array element)\n";
    } else {
        echo "SCHEMA-ARR-OBJ-ARR: FAIL det=[" . $oaDet . "] shape=" . $oaShape . "\n";
    }

    // Same element plus a scalar string sibling: detector returns "items|tags|note".
    $oa2Body = '{"json_schema":{"name":"r","schema":{"type":"object","properties":{"items":'
        . '{"type":"array","items":{"type":"object","properties":{"tags":{"type":"array",'
        . '"items":{"type":"string"}},"note":{"type":"string"}}}}}}}';
    $oa2Det = api_response_schema_array_object_array($oa2Body);
    $oa2Ids = tok_chat("user", "List two lists of colors, each with a note.")
        . "," . tok_chat_open("assistant");
    $oa2Out = api_array_object_array_value($oa2Ids, "items", "tags", "note",
        "abcdefghijklmnopqrstuvwxyz ", 2, 6, 0);
    $oa2Parts = explode(",", $oa2Out);
    $oa2Base = count(explode(",", $oa2Ids));
    $oa2Text = "";
    for ($i = $oa2Base; $i < count($oa2Parts); $i++) {
        $oa2Text = $oa2Text . gguf_token_str((int) $oa2Parts[$i]);
    }
    $oa2Text = str_replace("Ġ", " ", $oa2Text);
    $oa2Shape = preg_match('/^\{"items":\[\{"tags":\["[a-z ]*"\],"note":"[a-z ]*"\}'
        . '(,\{"tags":\["[a-z ]*"\],"note":"[a-z ]*"\})*\]\}$/', $oa2Text);
    echo "SCHEMA-ARR-OBJ-ARR2 det=[" . $oa2Det . "] text=[" . $oa2Text . "]\n";
    if ($oa2Det == "items|tags|note" && $oa2Out != "" && $oa2Shape == 1 && api_json_ok($oa2Text) == 1) {
        echo "SCHEMA-ARR-OBJ-ARR2: PASS (array plus scalar inside object-array element)\n";
    } else {
        echo "SCHEMA-ARR-OBJ-ARR2: FAIL det=[" . $oa2Det . "] shape=" . $oa2Shape . "\n";
    }

    // Element object whose only property is an integer array.
    $oniBody = '{"json_schema":{"name":"r","schema":{"type":"object","properties":{"items":'
        . '{"type":"array","items":{"type":"object","properties":{"nums":{"type":"array",'
        . '"items":{"type":"integer"}}}}}}}}}';
    $oniDet = api_response_schema_array_object_int_array($oniBody);
    $oniIds = tok_chat("user", "List two records, each with a list of numbers.")
        . "," . tok_chat_open("assistant");
    $oniOut = api_array_object_int_array_value($oniIds, "items", "nums", 2, 2, 3, 0);
    $oniParts = explode(",", $oniOut);
    $oniBase = count(explode(",", $oniIds));
    $oniText = "";
    for ($i = $oniBase; $i < count($oniParts); $i++) {
        $oniText = $oniText . gguf_token_str((int) $oniParts[$i]);
    }
    $oniText = str_replace("Ġ", " ", $oniText);
    $oniShape = preg_match('/^\{"items":\[\{"nums":\[(0|[1-9][0-9]*)(,(0|[1-9][0-9]*))*\]\}'
        . '(,\{"nums":\[(0|[1-9][0-9]*)(,(0|[1-9][0-9]*))*\]\})*\]\}$/', $oniText);
    echo "SCHEMA-ARR-OBJ-NUM det=[" . $oniDet . "] text=[" . $oniText . "]\n";
    if ($oniDet == "items|nums" && $oniOut != "" && $oniShape == 1 && api_json_ok($oniText) == 1) {
        echo "SCHEMA-ARR-OBJ-NUM: PASS (integer array inside object-array element)\n";
    } else {
        echo "SCHEMA-ARR-OBJ-NUM: FAIL det=[" . $oniDet . "] shape=" . $oniShape . "\n";
    }

    // Element object whose only property is a two-level string array.
    $od2Body = '{"json_schema":{"name":"r","schema":{"type":"object","properties":{"items":'
        . '{"type":"array","items":{"type":"object","properties":{"m":{"type":"array",'
        . '"items":{"type":"array","items":{"type":"string"}}}}}}}}}}';
    $od2Det = api_response_schema_array_object_array2($od2Body);
    $od2Ids = tok_chat("user", "List two records, each with a list of lists of colors.")
        . "," . tok_chat_open("assistant");
    $od2Out = api_array_object_array2_value($od2Ids, "items", "m",
        "abcdefghijklmnopqrstuvwxyz ", 2, 4, 0);
    $od2Parts = explode(",", $od2Out);
    $od2Base = count(explode(",", $od2Ids));
    $od2Text = "";
    for ($i = $od2Base; $i < count($od2Parts); $i++) {
        $od2Text = $od2Text . gguf_token_str((int) $od2Parts[$i]);
    }
    $od2Text = str_replace("Ġ", " ", $od2Text);
    $od2Shape = preg_match('/^\{"items":\[\{"m":\[\["[a-z ]*"\]\]\}'
        . '(,\{"m":\[\["[a-z ]*"\]\]\})*\]\}$/', $od2Text);
    echo "SCHEMA-ARR-OBJ-ARR2D det=[" . $od2Det . "] text=[" . $od2Text . "]\n";
    if ($od2Det == "items|m" && $od2Out != "" && $od2Shape == 1 && api_json_ok($od2Text) == 1) {
        echo "SCHEMA-ARR-OBJ-ARR2D: PASS (two-level string array inside object-array element)\n";
    } else {
        echo "SCHEMA-ARR-OBJ-ARR2D: FAIL det=[" . $od2Det . "] shape=" . $od2Shape . "\n";
    }

    // Element object holding a two-level nested chain that ends in flat leaves: the nested
    // generator composed with the array-of-objects skeleton. Every level on the chain must be
    // the only property of its block, so the detector's descent is unambiguous.
    $onBody = '{"json_schema":{"name":"r","schema":{"type":"object","properties":{"items":'
        . '{"type":"array","items":{"type":"object","properties":{"meta":{"type":"object",'
        . '"properties":{"inner":{"type":"object","properties":{"city":{"type":"string"},'
        . '"count":{"type":"integer"}}}}}}}}}}}}}}';
    $onDet = api_response_schema_array_object_nested($onBody);
    $onIds = tok_chat("user", "Give two inner reports.") . "," . tok_chat_open("assistant");
    $onOut = api_array_object_nested_value($onIds, "items", "meta/inner", "city:s,count:i",
        "abcdefghijklmnopqrstuvwxyz ", 2, 6, 0);
    $onParts = explode(",", $onOut);
    $onBase = count(explode(",", $onIds));
    $onText = "";
    for ($i = $onBase; $i < count($onParts); $i++) {
        $onText = $onText . gguf_token_str((int) $onParts[$i]);
    }
    $onText = str_replace("Ġ", " ", $onText);
    $onShape = preg_match('/^\{"items":\[\{"meta":\{"inner":\{"city":"[a-z ]*","count":-?(0|[1-9][0-9]*)\}\}\}'
        . '(,\{"meta":\{"inner":\{"city":"[a-z ]*","count":-?(0|[1-9][0-9]*)\}\}\})*\]\}$/', $onText);
    echo "SCHEMA-ARR-OBJ-NEST det=[" . $onDet . "] text=[" . $onText . "]\n";
    if ($onDet == "items|meta/inner|city:s,count:i" && $onOut != "" && $onShape == 1 && api_json_ok($onText) == 1) {
        echo "SCHEMA-ARR-OBJ-NEST: PASS (nested chain inside object-array element)\n";
    } else {
        echo "SCHEMA-ARR-OBJ-NEST: FAIL det=[" . $onDet . "] shape=" . $onShape . "\n";
    }

    // One-of over small object branches: the const first property selects the branch through
    // constrained decoding, the second property is filled by the branch's own grammar. Either
    // branch may win, so both shapes are accepted.
    $ofBody = '{"json_schema":{"name":"r","schema":{"type":"object","properties":{"item":{"oneOf":'
        . '[{"type":"object","properties":{"kind":{"const":"book"},"title":{"type":"string"}}},'
        . '{"type":"object","properties":{"kind":{"const":"cd"},"tracks":{"type":"integer"}}}]}}}}}';
    $ofDet = api_response_schema_oneof_grammar($ofBody);
    $ofIds = tok_chat("user", "Pick one item and describe it.") . "," . tok_chat_open("assistant");
    $ofOut = api_oneof_grammar_value($ofIds, "item", "kind=book,title:s;kind=cd,tracks:i",
        "abcdefghijklmnopqrstuvwxyz ", 6, 0);
    $ofParts = explode(",", $ofOut);
    $ofBase = count(explode(",", $ofIds));
    $ofText = "";
    for ($i = $ofBase; $i < count($ofParts); $i++) {
        $ofText = $ofText . gguf_token_str((int) $ofParts[$i]);
    }
    $ofText = str_replace("Ġ", " ", $ofText);
    $ofShapeA = preg_match('/^\{"item":\{"kind":"book","title":"[a-z ]*"\}\}$/', $ofText);
    $ofShapeB = preg_match('/^\{"item":\{"kind":"cd","tracks":-?(0|[1-9][0-9]*)\}\}$/', $ofText);
    $ofShape = ($ofShapeA == 1 || $ofShapeB == 1) ? 1 : 0;
    echo "SCHEMA-ONEOF-GRAMMAR det=[" . $ofDet . "] text=[" . $ofText . "]\n";
    if ($ofDet == "item|kind=book,title:s;kind=cd,tracks:i" && $ofOut != "" && $ofShape == 1 && api_json_ok($ofText) == 1) {
        echo "SCHEMA-ONEOF-GRAMMAR: PASS (oneOf branch selected by const, grammar leaf filled)\n";
    } else {
        echo "SCHEMA-ONEOF-GRAMMAR: FAIL det=[" . $ofDet . "] shape=" . $ofShape . "\n";
    }

    // Root-level oneOf with grammar branches: each branch object is the response root itself.
    $rogBody = '{"json_schema":{"name":"r","schema":{"oneOf":['
        . '{"type":"object","properties":{"kind":{"const":"book"},"title":{"type":"string"}}},'
        . '{"type":"object","properties":{"kind":{"const":"cd"},"tracks":{"type":"integer"}}}]}}}';
    $rogDet = api_response_schema_root_oneof_grammar($rogBody);
    $rogIds = tok_chat("user", "Return a media item as JSON.") . "," . tok_chat_open("assistant");
    $rogOut = api_root_oneof_grammar_value($rogIds,
        "kind=book,title:s;kind=cd,tracks:i", "abcdefghijklmnopqrstuvwxyz ", 6, 0);
    $rogParts = explode(",", $rogOut);
    $rogBase = count(explode(",", $rogIds));
    $rogText = "";
    for ($i = $rogBase; $i < count($rogParts); $i++) {
        $rogText = $rogText . gguf_token_str((int) $rogParts[$i]);
    }
    $rogText = str_replace("Ġ", " ", $rogText);
    $rogShape = preg_match('/^\{"kind":"(book|cd)","(title|tracks)":("[a-z ]*"|-?(0|[1-9][0-9]*))\}$/',
        $rogText);
    echo "SCHEMA-ROOT-ONEOF-GRAMMAR det=[" . $rogDet . "] text=[" . $rogText . "]\n";
    if ($rogDet == "root|kind=book,title:s;kind=cd,tracks:i" && $rogOut != ""
        && $rogShape == 1 && api_json_ok($rogText) == 1) {
        echo "SCHEMA-ROOT-ONEOF-GRAMMAR: PASS (root oneOf branch chosen by const, grammar leaf filled)\n";
    } else {
        echo "SCHEMA-ROOT-ONEOF-GRAMMAR: FAIL det=[" . $rogDet . "] shape=" . $rogShape . "\n";
    }

    // Tool whitelist scoping: a function name in message history must not become callable.
    $tnBody = '{"messages":[{"role":"assistant","tool_calls":[{"function":{"name":"ghost"}}]}],'
        . '"tools":[{"type":"function","function":{"name":"real"}}]}';
    $tn = api_tool_names($tnBody);
    if (count($tn) == 1 && $tn[0] == "real") {
        echo "TOOL-NAMES-SCOPE: PASS (history names excluded from the whitelist)\n";
    } else {
        echo "TOOL-NAMES-SCOPE: FAIL names=" . count($tn) . "\n";
    }

    // Detector scoping: a sibling property (count) or a nested object's leaf (n) must not leak
    // into the plan/leaf list -- the regexes are bounded to the target properties block.
    $objBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"type":"object","properties":{"people":{"type":"array","items":{"type":"object",'
        . '"properties":{"name":{"type":"string"},"age":{"type":"integer"}}}},'
        . '"count":{"type":"integer"}}}}}';
    $mixBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"type":"object","properties":{"city":{"type":"string"},"meta":{"type":"object",'
        . '"properties":{"n":{"type":"integer"}}}}}}}';
    $planT = api_response_schema_array_object($objBody);
    $mixT = api_response_schema_mixed_object($mixBody);
    echo "SCHEMA-SCOPE obj=[" . $planT . "] mixed=[" . ($mixT == "" ? "none" : $mixT) . "]\n";
    if ($planT == "people|name:s,age:i" && $mixT == "") {
        echo "SCHEMA-SCOPE: PASS (detectors stay inside the target properties block)\n";
    } else {
        echo "SCHEMA-SCOPE: FAIL\n";
    }

    // Adaptive rule. Invariant, not a timing threshold: drafting may stay enabled only if it is
    // not burning verifies without accepting. A lookup that finds nothing costs no forward pass,
    // so "drafts=0" is fine; "verifies>0 with accepted=0" is what must switch drafting off.
    $nr = tok_encode("Once upon a time there was a curious robot who wanted to learn to paint");
    $gNrRef = model_generate(0, $nr, 16);
    $gNrSpec = model_spec_generate(0, $nr, 16, 4);
    $st = model_spec_stats();
    echo "SPEC-ADAPT " . $st . "\n";
    $wasted = strpos($st, "verifies=0") === false && strpos($st, "accepted=0") !== false;
    if ($gNrRef == $gNrSpec && !$wasted) {
        echo "SPEC-ADAPT: PASS (ids identical; no verify burned without acceptance)\n";
    } else {
        echo "SPEC-ADAPT: FAIL wasted=" . ($wasted ? "yes" : "no")
            . " ids_equal=" . ($gNrRef == $gNrSpec ? "yes" : "no") . "\n";
    }

    // Negative control: k = -4 proposes drafts but rejects all of them. Ids must still equal
    // greedy, and the adaptive rule must have switched drafting off after its miss budget.
    $negRef = model_generate(0, $specIds, 16);
    $negSpec = model_spec_generate(0, $specIds, 16, -4);
    $st3 = model_spec_stats();
    echo "SPEC-NEG " . $st3 . "\n";
    $nWasted = strpos($st3, "verifies=0") === false && strpos($st3, "accepted=0") !== false;
    if ($negRef == $negSpec && $nWasted && strpos($st3, "drafting=off") !== false) {
        echo "SPEC-NEG: PASS (forced rejection: ids identical, drafting disabled after misses)\n";
    } else {
        echo "SPEC-NEG: FAIL ids_equal=" . ($negRef == $negSpec ? "yes" : "no") . "\n";
    }

    // SSD prefix cache: store the KV of a prefix, then a request sharing that prefix must skip
    // the prefill and still continue with exactly the same tokens.
    $pPath = "C:/git/c/typephp-kakasi/engine/build/prefix.kvc";
    $pre = tok_encode("The capital of France is Paris. The largest ocean is the Pacific. The capital of France is");
    model_prefill(0, $pre, -1);
    $stored = model_cache_store(0, $pre, $pPath);
    $one = explode(",", model_generate(0, $pre, 1));
    $ext = $pre . "," . $one[count($one) - 1];
    $cpre = count(explode(",", $ext));
    $tCold0 = microtime(true);
    model_prefill(0, $ext, -1);
    $msCold = (microtime(true) - $tCold0) * 1000.0;
    $tHit0 = microtime(true);
    $hit = model_cache_prefill(0, $ext, $pPath);
    $msHit = (microtime(true) - $tHit0) * 1000.0;
    $warm = "";
    for ($i = 0; $i < 4; $i++) {
        $w = model_session_next(0, 0.0, 1.0);
        if ($w < 0) {
            break;
        }
        $warm = $warm == "" ? "" . $w : $warm . "," . $w;
    }
    $cold = "";
    $cparts = explode(",", model_generate(0, $ext, 4));
    for ($i = $cpre; $i < count($cparts); $i++) {
        if ((int) $cparts[$i] == model_eos_id()) {
            break;
        }
        $cold = $cold == "" ? $cparts[$i] : $cold . "," . $cparts[$i];
    }
    echo "PREFIXCACHE stored=" . $stored . " hit_prefix=" . $hit . "/" . ($cpre - 1)
        . " cold=" . $msCold . " ms warm=" . $msHit . " ms\n";
    if ($stored == 1 && $hit == $cpre - 1 && $warm == $cold) {
        echo "PREFIXCACHE: PASS (reused " . $hit . " prefix tokens; continuation identical)\n";
    } else {
        echo "PREFIXCACHE: FAIL warm={$warm} cold={$cold} err=" . model_last_error() . "\n";
    }

    // EXPERIMENT (F76): partial prefix hit. Store a long prompt, then prefill a different prompt
    // that matches it for the first 3 ids (the API's actual case). Compare logits AND the next
    // three sampled tokens against a fresh session; printed, not gated.
    $ppPath = "C:/git/c/typephp-kakasi/engine/prefix_part.kv";
    $ex = explode(",", $ext);
    $k = 3;
    $alt = "";
    for ($i = 0; $i < $k && $i < count($ex); $i++) {
        $alt = $i == 0 ? $ex[0] : $alt . "," . $ex[$i];
    }
    $alt = $alt . "," . $one[0];
    model_prefill(0, $alt, -1);
    $ltFresh = model_top_logit();
    if (model_session_start(0, $alt) != 0) {
        echo "PREFIXPART session start failed\n";
    }
    $seqFresh = "";
    for ($i = 0; $i < 3; $i++) {
        $t = model_session_next(0, 0.0, 1.0);
        if ($t < 0) {
            break;
        }
        $seqFresh = $seqFresh == "" ? "" . $t : $seqFresh . "," . $t;
    }
    model_prefill(0, $ext, -1);
    model_cache_store(0, $ext, $ppPath);
    $ph = model_cache_prefill(0, $alt, $ppPath);
    $ltPart = model_top_logit();
    $seqPart = "";
    for ($i = 0; $i < 3; $i++) {
        $t = model_session_next(0, 0.0, 1.0);
        if ($t < 0) {
            break;
        }
        $seqPart = $seqPart == "" ? "" . $t : $seqPart . "," . $t;
    }
    $ld = $ltPart > $ltFresh ? $ltPart - $ltFresh : $ltFresh - $ltPart;
    if ($ph == 3 && $ld < 0.01 && $seqFresh == $seqPart) {
        echo "PREFIXPART: PASS (partial hit reproduces fresh logits and tokens)\n";
    } else {
        echo "PREFIXPART: FAIL hit={$ph} delta={$ld} fresh=[{$seqFresh}] part=[{$seqPart}]\n";
    }
    if (file_exists($ppPath)) {
        unlink($ppPath);
    }

    // Experiment, not a pass/fail gate: int8 residency raised the b0/b1 logit gap from 5.7e-6 to
    // 1.6e-2 (F34). If quantization is not reproducible, clearing residency and re-quantizing on
    // the SAME backend should move the logit by the same order; if it is reproducible, the gap
    // must come from the backends' own arithmetic instead.
    model_set_weight_prec(1);                       // drop residency: next prefill re-quantizes
    model_prefill(0, $ids, $expect);
    $b0a = model_top_logit();
    model_set_weight_prec(1);                       // quantize a second time
    model_prefill(0, $ids, $expect);
    $b0b = model_top_logit();
    model_set_weight_prec(1);
    model_prefill(1, $ids, $expect);
    $b1a = model_top_logit();
    model_set_weight_prec(1);
    model_prefill(1, $ids, $expect);
    $b1b = model_top_logit();
    $self0 = $b0a - $b0b;
    if ($self0 < 0.0) {
        $self0 = -$self0;
    }
    $self1 = $b1a - $b1b;
    if ($self1 < 0.0) {
        $self1 = -$self1;
    }
    $cross = $b0a - $b1a;
    if ($cross < 0.0) {
        $cross = -$cross;
    }
    echo "INT8-REPRO requant_b0=" . $self0 . " requant_b1=" . $self1
        . " cross_b0_b1=" . $cross . "\n";
}
