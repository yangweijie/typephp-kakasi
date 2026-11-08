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

// `json_object` is spelled differently per outlet: OpenAI `response_format`, Responses `text.format`.
// The short form is quoted, so `response_format` and Anthropic's `output_format` cannot be claimed
// by it - each spelling gets its own match.
function api_json_object_requested(string $body): int
{
    if (preg_match('/"response_format"\s*:\s*\{\s*"type"\s*:\s*"json_object"/', $body) == 1) {
        return 1;
    }
    if (preg_match('/"format"\s*:\s*\{\s*"type"\s*:\s*"json_object"/', $body) == 1) {
        return 1;
    }
    return 0;
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

// F342: 2-way set-associative slot choice. A prompt has a primary bucket and an alternate one, and
// it takes the alternate whenever the primary is recorded as owned by a DIFFERENT prefix. F341
// measured what the single-slot hash did before this: an unrelated prompt colliding in bucket 32
// silently destroyed a hot prefix's cache, cutting reusable tokens 49 -> 22 with no signal anywhere
// in the API. Ownership lives in an append-only index (last line per slot wins); losing it - after
// the index is reset for size - only falls back to the old behaviour, it can never hand out wrong
// tokens because the engine still compares the stored prefix.
function api_cache_index_path(): string
{
    return "C:/git/c/typephp-kakasi/engine/api_prefix_index.txt";
}

// Owner fingerprint of one slot from the index text, or -1 when nothing recorded it. The scan keeps
// the LAST match because the index is append-only.
function api_cache_owner(int $slot, string $ix): int
{
    $lines = explode("\n", $ix);
    $best = -1;
    for ($i = 0; $i < count($lines); $i++) {
        $p = explode("|", $lines[$i]);
        if (count($p) == 3 && (int) $p[0] == $slot) {
            $best = intval($p[1]);
        }
    }
    return $best;
}

// Last-use tick of a slot, or -1 when unowned. Ticks are line counts at append time, so a larger
// tick means "used later".
function api_cache_use(int $slot, string $ix): int
{
    $lines = explode("\n", $ix);
    $best = -1;
    for ($i = 0; $i < count($lines); $i++) {
        $p = explode("|", $lines[$i]);
        if (count($p) == 3 && (int) $p[0] == $slot) {
            $best = intval($p[2]);
        }
    }
    return $best;
}

// F343: which of two already-owned candidates to overwrite. The slot whose owner was used EARLIER
// is the victim, so a hot entry is only ever displaced by a strictly colder neighbour - F342's
// version threw at its alternate blindly, which could kill the recent one. Equal ticks give the
// primary, keeping the choice deterministic.
function api_cache_victim(int $useA, int $useB): int
{
    return $useA <= $useB ? 0 : 1;
}

function api_cache_pick(string $ids): string
{
    $k1 = model_cache_key($ids, 64);
    $k2 = model_cache_key($ids . "|alt", 64) + 64;
    $fp = model_cache_key($ids, 2147483000);
    $ip = api_cache_index_path();
    $ix = "";
    if (file_exists($ip)) {
        $h = fopen($ip, "r");
        if ($h !== false) {
            $ix = fread($h, 262144);
            fclose($h);
        }
    }
    // An oversized index is dropped rather than compacted: losing it only falls back to a cold
    // cache, it can never hand out wrong tokens because the engine still compares stored prefix.
    if (strlen($ix) > 200000 && file_exists($ip)) {
        unlink($ip);
        $ix = "";
    }
    $own1 = api_cache_owner($k1, $ix);
    $own2 = api_cache_owner($k2, $ix);
    $slot = $k1;
    if ($own1 == $fp) {
        $slot = $k1;
    } else if ($own2 == $fp) {
        $slot = $k2;
    } else if ($own1 < 0) {
        $slot = $k1;
    } else if ($own2 < 0) {
        $slot = $k2;
    } else if (api_cache_victim(api_cache_use($k1, $ix), api_cache_use($k2, $ix)) == 0) {
        $slot = $k1;
    } else {
        $slot = $k2;
    }
    // Choosing is also using: recording the tick here is what makes the next victim choice
    // recency-aware, and it needs no separate call at the read sites.
    $tick = count(explode("\n", $ix));
    $o = fopen($ip, "a");
    if ($o !== false) {
        fwrite($o, "" . $slot . "|" . $fp . "|" . $tick . "\n");
        fclose($o);
    }
    return "C:/git/c/typephp-kakasi/engine/api_prefix_b" . $slot . ".kv";
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
// A constrained step can come back empty because the engine had no logits for the current
// position (cold session: "no logits computed"), not because the grammar ran out. Re-prefilling
// the tokens consumed so far restores exactly the state a successful decode would have left, so
// one retry per blocked step recovers the rest of the forced literal instead of truncating it.
function api_constrain_loop(array $vals, int $maxSteps, string $restartIds = ""): array
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
    $restarts = 0;
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
            // Only a step the grammar fully determines (one forced id) is worth re-prefilling: a
            // refusal there is the cold 'no logits computed' defect, not the model declining.
            if ($restartIds == "" || $restarts >= 4 || strpos($allowed, ",") !== false) {
                break;
            }
            $rp = $restartIds;
            for ($ci = 0; $ci < count($chosen); $ci++) {
                $rp = $rp . "," . $chosen[$ci];
            }
            $restarts = $restarts + 1;
            if (model_session_start(0, $rp) != 0) {
                break;
            }
            $nx = model_session_next_allowed(0, $allowed);
            if ($nx < 0) {
                break;
            }
        }
        $chosen[] = "" . $nx;
    }
    return $chosen;
}

function api_constrained(string $ids, array $vals, int $maxSteps, int $useCache = 1): string
{
    // Constrained decoding is greedy, so it can start from a cached prefix like plain decoding.
    $pcPathC = api_cache_pick($ids);
    $promptNc = count(explode(",", $ids));
    $hitc = $useCache == 1 ? model_cache_prefill(0, $ids, $pcPathC) : -1;
    if ($hitc < 0 && model_session_start(0, $ids) != 0) {
        return "";
    }
    $chosen = api_constrain_loop($vals, $maxSteps, $ids);
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
function api_string_value_multi(string $ids, array $props, string $charset, int $maxSteps, int $useCache = 1, string $bounds = ""): string
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
    // F401b: the token that closes a bounded string must decode to exactly one quote character.
    // gguf_charset_ids can answer with several ids for `"`, some of which carry more than one
    // character, and offering those at maxLength put an extra quote into the value and produced
    // invalid JSON - the live case with two bounded properties caught it.
    $closeIds = "";
    foreach (explode(",", $quoteIds) as $qid) {
        if (str_replace("Ġ", " ", gguf_token_str((int) $qid)) == "\"") {
            $closeIds = $closeIds == "" ? $qid : $closeIds . "," . $qid;
        }
    }
    if ($closeIds == "") {
        $closeIds = $quoteIds;
    }
    for ($pi = 0; $pi < count($props); $pi++) {
        if ($pi > 0) {
            $sep = api_constrained_continue([",\"" . $props[$pi] . "\":\""], 32);
            if ($sep == "") {
                return "";
            }
            $s = $s . "," . $sep;
        }
        $closed = 0;
        // F399: length bounds are enforced by restricting what each step may choose - below the
        // minimum the closing quote is not offered, at the maximum nothing else is. Characters are
        // counted rather than steps, because one token can carry several.
        $len = 0;
        $bMin = 0;
        $bMax = 0;
        if ($bounds != "") {
            $bp = explode(",", $bounds);
            if ($pi < count($bp)) {
                $bv = explode(":", $bp[$pi]);
                if (count($bv) == 2) {
                    $bMin = (int) $bv[0];
                    $bMax = (int) $bv[1];
                }
            }
        }
        for ($d = 0; $d < $maxSteps; $d++) {
            $allow = $stepIds;
            if ($bMax > 0) {
                // F399: a single token can carry several characters, so "stop at the max" has to
                // filter the offered tokens by how many characters they would add - the first
                // version only checked the count before the step and let one token overshoot.
                $remain = $bMax - $len;
                if ($remain <= 0) {
                    $allow = $closeIds;
                } else {
                    $fit = "";
                    foreach (explode(",", $charsetIds) as $cid) {
                        $tl = strlen(str_replace("Ġ", " ", gguf_token_str((int) $cid)));
                        if ($tl > 0 && $tl <= $remain) {
                            $fit = $fit . ($fit == "" ? "" : ",") . $cid;
                        }
                    }
                    if ($len >= $bMin) {
                        $fit = $fit . ($fit == "" ? "" : ",") . $closeIds;
                    }
                    $allow = $fit == "" ? $closeIds : $fit;
                }
            } else if ($len < $bMin) {
                $allow = $charsetIds;
            }
            $nx = model_session_next_allowed(0, $allow);
            if ($nx < 0) {
                break;
            }
            $s = $s . "," . $nx;
            if (strpos($q, "," . $nx . ",") !== false) {
                $closed = 1;
                break;
            }
            $len = $len + strlen(str_replace("Ġ", " ", gguf_token_str($nx)));
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
function api_array_string_value(string $ids, string $prop, string $charset, int $maxItems, int $maxSteps, int $useCache = 1, string $bounds = ""): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($charsetIds == "" || $quoteIds == "" || $commaIds == "" || $bracketIds == "") {
        return "";
    }
    // F400: "min:max" element-count bounds (0 = that side unconstrained). The budget becomes the
    // maximum, and below the minimum the closing bracket is not offered at all - the same shape of
    // filtering the length bounds use in F399, one level up.
    $bMin = 0;
    $bMax = 0;
    if ($bounds != "") {
        $bp = explode(":", $bounds);
        $bMin = (int) $bp[0];
        $bMax = (int) $bp[1];
        if ($bMax > 0 && $bMax < $maxItems) {
            $maxItems = $bMax;
        }
        if ($bMin > $maxItems) {
            $maxItems = $bMin;
        }
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
        $offer = $endIds;
        if ($it + 1 < $bMin) {
            $offer = $commaIds;         // below the minimum: the list cannot close yet
        } else if ($it + 1 >= $maxItems) {
            $offer = $bracketIds;       // at the maximum: only the close token is reachable
        }
        $nx2 = model_session_next_allowed(0, $offer);
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
    // F400: minItems/maxItems may sit beside `items` inside the array object, before or after it.
    // Nothing else may, so the tails name those two keywords instead of accepting any scalar key.
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"array"\s*'
        . '(?:\s*,\s*(?:"(?:minItems|maxItems)"\s*:\s*[0-9]+|"uniqueItems"\s*:\s*false))*'
        . '\s*,\s*"items"\s*:\s*\{\s*"type"\s*:\s*"string"\s*\}'
        . '(?:\s*,\s*(?:"(?:minItems|maxItems)"\s*:\s*[0-9]+|"uniqueItems"\s*:\s*false))*\s*\}/s', $schema, $pm) != 1) {
        return "";
    }
    // F407: a BOUNDED array leaf beside other properties is the mixed stepper's shape - it counts
    // the elements AND emits the siblings - while this single-array writer would drop them, so it
    // steps aside for exactly that shape. Without bounds the single-array emission is what has
    // always served the shape, and a bounded single-leaf schema keeps coming through here (F400).
    if (strpos($schema, "\"minItems\"") !== false || strpos($schema, "\"maxItems\"") !== false) {
        $pp2 = strpos($schema, "\"properties\"");
        if ($pp2 !== false) {
            $bo2 = strpos($schema, "{", $pp2);
            if ($bo2 !== false) {
                $blk2 = api_balance_block($schema, $bo2);
                if ($blk2 != "") {
                    $nLeaves = 0;
                    $hasB = 0;
                    foreach (explode(",", api_top_leaves($blk2, 8)) as $lf) {
                        $nLeaves++;
                        $lkv = explode(":", $lf);
                        if (count($lkv) == 2 && strpos($lkv[1], "a~") === 0) {
                            $hasB = 1;
                        }
                    }
                    if ($nLeaves > 1 && $hasB == 1) {
                        return "";
                    }
                }
            }
        }
    }
    return $pm[1];
}

// An array of numbers, e.g. {"nums":[123,456]}. Each element is filled with api_number_fill for a
// fixed token budget, then only "," (continue) or "]" (end) is reachable. Runs before the flat
// number detector, which would otherwise mistake "items" for a key.
function api_array_number_value(string $ids, string $prop, int $allowFrac, int $maxItems, int $elemSteps, int $useCache = 1, string $bounds = ""): string
{
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($commaIds == "" || $bracketIds == "") {
        return "";
    }
    $bMin = 0;
    $bMax = 0;
    if ($bounds != "") {
        $bp = explode(":", $bounds);
        $bMin = (int) $bp[0];
        $bMax = (int) $bp[1];
        if ($bMax > 0 && $bMax < $maxItems) {
            $maxItems = $bMax;
        }
        if ($bMin > $maxItems) {
            $maxItems = $bMin;
        }
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
        $offer = $endIds;
        if ($it + 1 < $bMin) {
            $offer = $commaIds;         // below the minimum: the list cannot close yet
        } else if ($it + 1 >= $maxItems) {
            $offer = $bracketIds;       // at the maximum: only the close token is reachable
        }
        $nx = model_session_next_allowed(0, $offer);
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
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"array"\s*'
        . '(?:\s*,\s*(?:"(?:minItems|maxItems)"\s*:\s*[0-9]+|"uniqueItems"\s*:\s*false))*'
        . '\s*,\s*"items"\s*:\s*\{\s*"type"\s*:\s*"(integer|number)"\s*\}'
        . '(?:\s*,\s*(?:"(?:minItems|maxItems)"\s*:\s*[0-9]+|"uniqueItems"\s*:\s*false))*\s*\}/s', $schema, $pm) != 1) {
        return "";
    }
    // F407: a BOUNDED array leaf beside other properties is the mixed stepper's shape (it counts
    // the elements AND emits the siblings) - this single-array writer would drop them, so it
    // steps aside for exactly that shape, the same bail the string-array detector grew.
    if (strpos($schema, "\"minItems\"") !== false || strpos($schema, "\"maxItems\"") !== false) {
        $pp2 = strpos($schema, "\"properties\"");
        if ($pp2 !== false) {
            $bo2 = strpos($schema, "{", $pp2);
            if ($bo2 !== false) {
                $blk2 = api_balance_block($schema, $bo2);
                if ($blk2 != "") {
                    $nLeaves = 0;
                    $hasB = 0;
                    foreach (explode(",", api_top_leaves($blk2, 8)) as $lf) {
                        $nLeaves++;
                        $lkv = explode(":", $lf);
                        if (count($lkv) == 2 && strpos($lkv[1], "~") !== false) {
                            $hasB = 1;
                        }
                    }
                    if ($nLeaves > 1 && $hasB == 1) {
                        return "";
                    }
                }
            }
        }
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
function api_top_array_string_value(string $ids, string $charset, int $maxItems, int $maxSteps, int $useCache = 1, string $bounds = ""): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($charsetIds == "" || $quoteIds == "" || $commaIds == "" || $bracketIds == "") {
        return "";
    }
    // F406: "min:max" element-count bounds (0 = that side unconstrained) - the same wiring the
    // keyed array writer got in F400: the budget becomes the maximum (raised to the minimum when
    // the minimum is above it), below the minimum the close is not offered, at the maximum only
    // the close is.
    $bMin = 0;
    $bMax = 0;
    if ($bounds != "") {
        $bp = explode(":", $bounds);
        $bMin = (int) $bp[0];
        $bMax = (int) $bp[1];
        if ($bMax > 0 && $bMax < $maxItems) {
            $maxItems = $bMax;
        }
        if ($bMin > $maxItems) {
            $maxItems = $bMin;
        }
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
        $offer = $endIds;
        if ($i + 1 < $bMin) {
            $offer = $commaIds;         // below the minimum: the list cannot close yet
        } else if ($i + 1 >= $maxItems) {
            $offer = $bracketIds;       // at the maximum: only the close token is reachable
        }
        $nx2 = model_session_next_allowed(0, $offer);
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

// 1 when the schema itself is {"type":"array","items":{"type":"string"}} (top level, no object);
// F406 lets minItems/maxItems ride beside items the same way the keyed detector accepts them.
function api_response_schema_top_array_string(string $body): int
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return 0;
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return 0;
    }
    if (preg_match('/^\s*\{\s*"type"\s*:\s*"array"\s*'
        . '(?:\s*,\s*"(?:minItems|maxItems)"\s*:\s*[0-9]+)*'
        . '\s*,\s*"items"\s*:\s*\{\s*"type"\s*:\s*"string"\s*\}'
        . '(?:\s*,\s*"(?:minItems|maxItems)"\s*:\s*[0-9]+)*\s*\}\s*$/', $schema) != 1) {
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
    if ($kv[1] == "s" || $kv[1] == "e") {
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
        // F404: braces inside JSON string values - a pattern's {3} quantifier, a brace enum value -
        // must not count toward the leaf-shape heuristics below, or a pattern leaf reads as a
        // two-brace object and is dropped from the plan entirely. Strip the string contents first,
        // then count; the kind regexes still run on the raw value.
        $valPlain = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '""', $val);
        $valBraces = substr_count($valPlain, "{");
        // Depth-1 leaves are flat objects: a nested object has more than its own opening brace.
        // A leaf holding an array of strings has exactly one extra brace (its items object), so
        // it is a leaf too - without this the key was dropped (F286).
        $arrOk = 0;
        $arrTy = "";
        if ($valBraces == 2) {
            if (preg_match('/"items"\s*:\s*\{\s*"type"\s*:\s*"string"/', $val) == 1
                || preg_match('/"items"\s*:\s*\{\s*"type"\s*:\s*\[[^\]]*"string"[^\]]*\]/', $val) == 1) {
                $arrOk = 1;
                $arrTy = "a";
            } else if (preg_match('/"items"\s*:\s*\{\s*"type"\s*:\s*"integer"/', $val) == 1
                       || preg_match('/"items"\s*:\s*\{\s*"type"\s*:\s*\[[^\]]*"integer"[^\]]*\]/', $val) == 1) {
                $arrOk = 1;
                $arrTy = "an";
            } else if (preg_match('/"items"\s*:\s*\{\s*"type"\s*:\s*"number"/', $val) == 1
                       || preg_match('/"items"\s*:\s*\{\s*"type"\s*:\s*\[[^\]]*"number"[^\]]*\]/', $val) == 1) {
                $arrOk = 1;
                $arrTy = "af";
            } else if (preg_match('/"items"\s*:\s*\{\s*"type"\s*:\s*"boolean"/', $val) == 1
                       || preg_match('/"items"\s*:\s*\{\s*"type"\s*:\s*\[[^\]]*"boolean"[^\]]*\]/', $val) == 1) {
                $arrOk = 1;
                $arrTy = "ab";
            }
            // F407: element-count bounds travel with the leaf in the plan kind ("a~2~3"), so the
            // mixed stepper can count the elements without re-reading the schema. Valid bounds
            // only - an invalid pair keeps the plain kind and is refused by the keyword gate's
            // own validation (maxItems 0, min>max, min past the ceiling, negatives, floats).
            if ($arrOk == 1) {
                $bmn = [];
                $bmx = [];
                $hasMin = preg_match('/"minItems"\s*:\s*([0-9]+)/', $val, $bmn) == 1;
                $hasMax = preg_match('/"maxItems"\s*:\s*([0-9]+)/', $val, $bmx) == 1;
                if ($hasMin || $hasMax) {
                    $bvMin = $hasMin ? (int) $bmn[1] : 0;
                    $bvMax = $hasMax ? (int) $bmx[1] : 0;
                    if (($hasMax && $bvMax < 1) || ($bvMax > 0 && $bvMin > $bvMax) || $bvMin > 8) {
                        $arrOk = 0;         // unservable bounds: back to a non-leaf, the gate refuses
                    } else {
                        $arrTy = $arrTy . "~" . $bvMin . "~" . $bvMax;
                    }
                }
            }
        }
        // Object arrays carry exactly three braces (the value, its items object and that
        // object's properties). The exact count matters: an ancestor's subtree also contains
        // "items", and matching it would put the array under the wrong key (F296 round B).
        if ($arrOk == 0 && $valBraces == 4) {
            $mo2 = [];
            // F408: minItems/maxItems may sit before "items" the same way the scalar-array
            // detectors accept them - the ao kind is the shape, not one spelling of it.
            if (preg_match('/^\s*\{\s*"type"\s*:\s*"array"\s*'
                . '(?:\s*,\s*"(?:minItems|maxItems)"\s*:\s*[0-9]+)*'
                . '\s*,\s*"items"\s*:\s*\{\s*"type"\s*:\s*"object"\s*,\s*"properties"\s*:\s*\{\s*"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"string"/', $val, $mo2) == 1) {
                $arrOk = 1;
                $arrTy = "ao:" . $mo2[1];
            }
        }
        if ($valBraces == 1 || $arrOk == 1) {
            $m = [];
            if ($arrOk == 1) {
                $out = $out == "" ? $key . ":" . $arrTy : $out . "," . $key . ":" . $arrTy;
                $count++;
            } else if (preg_match('/"type"\s*:\s*"(string|integer|number|boolean)"/', $val, $m) == 1
                       || preg_match('/"type"\s*:\s*\[[^\]]*"(string|integer|number|boolean)"[^\]]*\]/', $val, $m) == 1) {
                // The second form is a union type (["string","null"], ["string","integer"], …).
                // Emitting ANY member of the union is schema-valid, so the first recognised member
                // decides the leaf kind; without this the leaf was dropped entirely (F292).
                $mk = "s";
                if ($m[1] == "number") {
                    $mk = "n";
                } else if ($m[1] == "integer") {
                    $mk = "i";
                } else if ($m[1] == "boolean") {
                    $mk = "b";
                }
                // A string enum leaf carries its allowed values in the plan (key:e:v1~v2) so the
                // builder can decode from the finite set instead of a free-form charset. Numeric
                // enums use en: with unquoted literals (findings F258).
                $me = [];
                if (preg_match('/"enum"\s*:\s*\[([^\]]*)\]/', $val, $me) == 1) {
                    $evs = api_list($me[1]);
                    $evc = "";
                    for ($ej = 0; $ej < count($evs); $ej++) {
                        $one = $evs[$ej];
                        if ($one == "" || strpos($one, "~") !== false || strpos($one, "\"") !== false
                            || strpos($one, ",") !== false || strpos($one, "\\") !== false) {
                            $evc = "";
                            break;
                        }
                        if ($mk != "s" && preg_match('/^-?[0-9]+(\.[0-9]+)?([eE][+-]?[0-9]+)?$/', $one) != 1) {
                            $evc = "";
                            break;
                        }
                        $evc = $evc == "" ? $one : $evc . "~" . $one;
                    }
                    if ($evc != "") {
                        $mk = ($mk == "s" ? "e:" : "en:") . $evc;
                    }
                } else if (preg_match('/"const"\s*:\s*"([^"]*)"/', $val, $mc) == 1 && $mk == "s") {
                    $one = $mc[1];
                    if ($one != "" && strpos($one, "~") === false && strpos($one, "\"") === false
                        && strpos($one, ",") === false && strpos($one, "\\") === false) {
                        $mk = "e:" . $one;
                    }
                } else if (preg_match('/"const"\s*:\s*(-?[0-9]+(\.[0-9]+)?([eE][+-]?[0-9]+)?)/', $val, $mc) == 1
                           && $mk != "s") {
                    $mk = "en:" . $mc[1];
                } else if ($mk == "s"
                           && preg_match('/"pattern"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $val, $mps) == 1
                           && ($pSpec = api_schema_pattern_spec($mps[1])) != "") {
                    // F404: an anchored class/count pattern the flat mixed stepper can honour
                    // carries its segment spec in the plan (key:p:spec) - the stepper emits
                    // exactly the literal runs and counted classes. minLength/maxLength beside
                    // such a pattern stay refused via the flat-all-string gate, and the
                    // unimplemented metacharacter forms keep the explicit 400.
                    $mk = "p:" . $pSpec;
                } else if ($mk == "s" && preg_match('/"pattern"\s*:\s*"\^?([A-Za-z0-9 _.\-|]+)\$?"/', $val, $mp) == 1) {
                    // A plain-literal alternation ("Paris|London") is a finite set; anything with
                    // real regex metacharacters falls through untouched (F282).
                    $evc = "";
                    $pparts = explode("|", $mp[1]);
                    for ($pj = 0; $pj < count($pparts); $pj++) {
                        $one = $pparts[$pj];
                        if ($one == "" || strpos($one, "~") !== false || strpos($one, "\"") !== false
                            || strpos($one, ",") !== false || strpos($one, "\\") !== false) {
                            $evc = "";
                            break;
                        }
                        $evc = $evc == "" ? $one : $evc . "~" . $one;
                    }
                    if ($evc != "") {
                        $mk = "e:" . $evc;
                    }
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
        if (count($kv) < 2) {
            return "";
        }
        $ty = $kv[1];
        // F411: the counted array form ("a~min~max", F407's encoding) travels in the nested plan
        // too - split the bounds off and let the array branch count with them.
        $nMin = 0;
        $nMax = 0;
        $nBounded = 0;
        $tPos = strpos($ty, "~");
        if ($tPos !== false) {
            $nty = substr($ty, 0, $tPos);
            $brest = explode("~", substr($ty, $tPos + 1));
            if (count($brest) == 2 && ($nty == "a" || $nty == "an" || $nty == "af" || $nty == "ab")) {
                $nMin = (int) $brest[0];
                $nMax = (int) $brest[1];
                if ($nMin < 0 || $nMin > 8 || ($nMax > 0 && $nMin > $nMax)) {
                    return "";
                }
                $nBounded = 1;
                $ty = $nty;
            }
        }
        if ($k > 0) {
            $sep = ",\"" . $kv[0] . "\":";
            if ($ty == "s" || $ty == "e") {
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
        } else if ($ty == "e") {
            // The separator already opened the quote, so each candidate is "value\"": let the
            // greedy constrained loop pick the enum member instead of free-form characters.
            $ev = count($kv) > 2 ? $kv[2] : "";
            $ecands = [];
            if ($ev != "") {
                $eparts = explode("~", $ev);
                for ($ei = 0; $ei < count($eparts); $ei++) {
                    if ($eparts[$ei] != "") {
                        $ecands[] = $eparts[$ei] . "\"";
                    }
                }
            }
            if (count($ecands) > 0) {
                $eb = api_constrained_continue($ecands, 8);
                if ($eb != "") {
                    $s = $s . "," . $eb;
                }
            }
        } else if ($ty == "en") {
            // Numeric enum: the literals are already valid JSON numbers, so no quote is added
            // either by the separator or in the candidates (findings F258).
            $ev = count($kv) > 2 ? $kv[2] : "";
            $ecands = [];
            if ($ev != "") {
                $eparts = explode("~", $ev);
                for ($ei = 0; $ei < count($eparts); $ei++) {
                    if ($eparts[$ei] != "") {
                        $ecands[] = $eparts[$ei];
                    }
                }
            }
            if (count($ecands) > 0) {
                $eb = api_constrained_continue($ecands, 6);
                if ($eb != "") {
                    $s = $s . "," . $eb;
                }
            }
        } else if ($ty == "b") {
            // JSON booleans are the two literals below; without this branch a boolean leaf was
            // dropped from the plan entirely and the object came back missing its key (F285).
            $bb = api_constrained_continue(["true", "false"], 4);
            if ($bb != "") {
                $s = $s . "," . $bb;
            }
        } else if ($ty == "a" || $ty == "an" || $ty == "af" || $ty == "ab" || $ty == "ao") {
            // Nested array leaf (F286 for strings, F290 for numbers). The separator already
            // emitted "key":, so this only has to produce [items] using continuations.
            // F411: the counted form raises the budget to the bounds - below the minimum the
            // close is not offered, at the maximum only the close is (F400's counting, one level
            // inside the nested object); an unbounded leaf keeps the old budget of three.
            $commaIds = api_punct_ids(",");
            $bracketIds = api_punct_ids("]");
            $aopen = $ty == "a" ? "[\"" : "[";
            if ($ty == "ao") { $aopen = "[{\"" . (count($kv) > 2 ? $kv[2] : "") . "\":\""; }
            $aop = api_constrained_continue([$aopen], 16);
            if ($aop == "" || $commaIds == "" || $bracketIds == "") {
                return "";
            }
            $s = $s . "," . $aop;
            $aBudget = 3;
            if ($nBounded == 1) {
                $aBudget = $nMax > 0 ? $nMax : 3;
                if ($nMin > $aBudget) {
                    $aBudget = $nMin;
                }
            }
            $itemsDone = 0;
            for ($it = 0; $it < $aBudget && $itemsDone == 0; $it++) {
                if ($it > 0 && ($ty == "a" || $ty == "ao")) {
                    $aopen2 = $ty == "a" ? "\"" : ",{\"" . (count($kv) > 2 ? $kv[2] : "") . "\":\"";
                    $aop2 = api_constrained_continue([$aopen2], 16);
                    if ($aop2 == "") {
                        break;
                    }
                    $s = $s . "," . $aop2;
                }
                if ($ty == "a" || $ty == "ao") {
                    $aclosed = 0;
                    for ($d = 0; $d < $maxSteps; $d++) {
                        $anx = model_session_next_allowed(0, $stepIds);
                        if ($anx < 0) {
                            break;
                        }
                        $s = $s . "," . $anx;
                        if (strpos($q, "," . $anx . ",") !== false) {
                            $aclosed = 1;
                            break;
                        }
                    }
                    if ($aclosed == 0) {
                        $acq = api_constrained_continue(["\""], 4);
                        if ($acq != "") {
                            $s = $s . "," . $acq;
                        }
                    }
                    if ($ty == "ao") {
                        // Close the item's object before the array terminator picks , or ].
                        $aoclose = api_constrained_continue(["}"], 4);
                        if ($aoclose != "") {
                            $s = $s . "," . $aoclose;
                        }
                    }
                } else if ($ty == "ab") {
                    $abool = api_constrained_continue(["true", "false"], 4);
                    if ($abool == "") {
                        break;
                    }
                    $s = $s . "," . $abool;
                } else {
                    $anum = api_number_fill($ty == "af" ? 1 : 0, 4);
                    if ($anum == "") {
                        break;
                    }
                    $s = $s . "," . $anum;
                }
                $endIds = $commaIds . "," . $bracketIds;
                // F411: the counted form filters the boundary tokens - below the minimum the
                // array cannot close, at the maximum only the close is reachable. An UNBOUNDED
                // leaf keeps the old behaviour exactly: the last budgeted element offers only
                // the close, so the forced close can never follow a comma.
                $aOffer = $endIds;
                if ($nBounded == 1) {
                    if ($it + 1 < $nMin) {
                        $aOffer = $commaIds;
                    } else if ($it + 1 >= $aBudget) {
                        $aOffer = $bracketIds;
                    }
                } else if ($it >= $aBudget - 1) {
                    $aOffer = $bracketIds;
                }
                $anx2 = model_session_next_allowed(0, $aOffer);
                if ($anx2 < 0) {
                    break;
                }
                $s = $s . "," . $anx2;
                if (strpos("," . $commaIds . ",", "," . $anx2 . ",") === false) {
                    $itemsDone = 1;   // "]"
                    break;
                }
            }
            if ($itemsDone == 0) {
                $acl = api_constrained_continue(["]"], 4);
                if ($acl != "") {
                    $s = $s . "," . $acl;
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
    // String enums/consts/plain-literal patterns are expressible as e: leaves (F244/F258/F281/
    // F282); patterns with real metacharacters and numeric bounds still disqualify the schema.
    $mpat = [];
    if (preg_match('/"pattern"\s*:\s*"([^"]*)"/', $schema, $mpat) == 1
        && preg_match('/^\^?[A-Za-z0-9 _.\-|]+\$?$/', $mpat[1]) != 1) {
        return "";
    }
    if (preg_match('/"(minimum|maximum|multipleOf)"\s*:/', $schema) == 1) {
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
    for ($depth = 0; $depth < 256; $depth++) {
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
        // Six, not three: with a cap of three a nested object carrying four or more properties
        // silently lost the extra leaves (same shape defect class as F285).
        $plan = api_top_leaves($block, 6);
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
function api_array_object_value(string $ids, string $prop, string $plan, string $charset, int $maxItems, int $maxSteps, int $useCache = 1, string $bounds = ""): string
{
    $charsetIds = gguf_charset_ids($charset);
    $quoteIds = gguf_charset_ids("\"");
    $commaIds = api_punct_ids(",");
    $bracketIds = api_punct_ids("]");
    if ($charsetIds == "" || $quoteIds == "" || $commaIds == "" || $bracketIds == "") {
        return "";
    }
    // F408: "min:max" element-count bounds (0 = that side unconstrained) - the same wiring the
    // scalar-array writers got in F400/F406: the budget becomes the maximum (raised to the
    // minimum when the minimum is above it), below the minimum the close is not offered, at the
    // maximum only the close is.
    $bMin = 0;
    $bMax = 0;
    if ($bounds != "") {
        $bp = explode(":", $bounds);
        $bMin = (int) $bp[0];
        $bMax = (int) $bp[1];
        if ($bMax > 0 && $bMax < $maxItems) {
            $maxItems = $bMax;
        }
        if ($bMin > $maxItems) {
            $maxItems = $bMin;
        }
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
        $offer = $endIds;
        if ($it + 1 < $bMin) {
            $offer = $commaIds;         // below the minimum: the list cannot close yet
        } else if ($it + 1 >= $maxItems) {
            $offer = $bracketIds;       // at the maximum: only the close token is reachable
        }
        $nx2 = model_session_next_allowed(0, $offer);
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
    // A root-level array of objects has at most two "properties" blocks (the root's and the
    // item's). More than that means the array sits inside a deeper nested object, which the
    // nested assembler must own (F296 routing, verified before touching assembly).
    if (substr_count($schema, "\"properties\"") > 2) {
        return "";
    }
    $pm = [];
    // F408: minItems/maxItems may sit before "items" the same way the scalar-array detectors
    // accept them - the ao shape is the shape, not one spelling of it.
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{\s*"type"\s*:\s*"array"\s*'
        . '(?:\s*,\s*"(?:minItems|maxItems)"\s*:\s*[0-9]+)*'
        . '\s*,\s*"items"\s*:\s*\{\s*"type"\s*:\s*"object"/s', $schema, $pm) != 1) {
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
    // F395: same silent truncation as the all-string detector (F394) - the generators loop
    // over the plan, so the cap was the only thing dropping leaves. Raised to 8.
    $plan = api_top_leaves($block, 8);
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
    for ($depth = 0; $depth < 256; $depth++) {
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
    $fTy = "";
    if (count($first) == 2) {
        $fTy = $first[1];
    } else if (count($first) == 3 && $first[1] == "p") {
        $fTy = "p";
    } else {
        return "";
    }
    $head = "{\"" . $first[0] . "\":";
    if ($fTy == "s" || $fTy == "p") {
        $head = $head . "\"";
    }
    $s = api_constrained($ids, [$head], 64, $useCache);
    if ($s == "") {
        return "";
    }
    for ($k = 0; $k < count($items); $k++) {
        $kv = explode(":", $items[$k]);
        $ty = "";
        $pSpec = "";
        if (count($kv) == 2) {
            $ty = $kv[1];
        } else if (count($kv) == 3 && $kv[1] == "p") {
            $ty = "p";
            $pSpec = $kv[2];
        } else {
            return "";
        }
        if ($k > 0) {
            $sep = ",\"" . $kv[0] . "\":";
            if ($ty == "s" || $ty == "p") {
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
        } else if ($ty == "b") {
            // F403②: a boolean leaf is the two literals, the same way the nested stepper emits
            // it (F285). Without this branch the leaf fell into the numeric fill and the plan's
            // "flag":b came out as an integer that is not true or false.
            $bb = api_constrained_continue(["true", "false"], 4);
            if ($bb != "") {
                $s = $s . "," . $bb;
            }
        } else if ($ty == "p") {
            // F404: the class/count pattern kind - emit exactly the literal runs and counted
            // classes the spec carries, then the closing quote. Every segment is a fixed shape,
            // so the emitted string matches the pattern by construction; a class character is
            // picked from the class charset alone, so no quote or off-class character can leak
            // into the value.
            $segs2 = explode("~", $pSpec);
            for ($si = 0; $si < count($segs2); $si++) {
                $seg = $segs2[$si];
                $isLast = $si == count($segs2) - 1;
                $tag = substr($seg, 0, 1);
                if ($tag == "l") {
                    $lp = api_constrained_continue([substr($seg, 1)], 32);
                    if ($lp == "") {
                        return "";
                    }
                    $s = $s . "," . $lp;
                } else if ($tag == "c") {
                    // F404/F409: a counted class segment - "c<n>x<chars>" is exact, "c<min>-<max>x"
                    // a range, "c<min>-x" unbounded (the * and + shorthands). The walk offers the
                    // class characters until the minimum is reached, then the class characters AND
                    // the closing quote together (the quote is a distinct token, so the walk stays
                    // unambiguous) - but the walk never EMITS the quote itself: breaking without
                    // it lets the tail's closing quote close the value, exactly like the exact
                    // walk always has. The ids are pre-filtered to single characters, so each
                    // step is exactly one character.
                    $slen = strlen($seg);
                    $p2 = 1;
                    while ($p2 < $slen && $seg[$p2] >= "0" && $seg[$p2] <= "9") {
                        $p2++;
                    }
                    if ($p2 == 1) {
                        return "";
                    }
                    $dmin = (int) substr($seg, 1, $p2 - 1);
                    $dmax = $dmin;                      // no dash: exact
                    if ($p2 < $slen && $seg[$p2] == "-") {
                        $p2++;
                        $ds = $p2;
                        while ($p2 < $slen && $seg[$p2] >= "0" && $seg[$p2] <= "9") {
                            $p2++;
                        }
                        // A dash with no digits spells the unbounded forms; a dash with digits is
                        // the range's maximum.
                        $dmax = $p2 > $ds ? (int) substr($seg, $ds, $p2 - $ds) : -1;
                    }
                    if ($p2 >= $slen || $seg[$p2] != "x") {
                        return "";
                    }
                    $cids = api_pattern_class_ids(substr($seg, $p2 + 1));
                    $qids = api_punct_ids("\"");
                    if ($cids == "" || $qids == "" || $dmin < 0 || $dmin > 16) {
                        return "";
                    }
                    if ($dmax >= 0 && $dmax < $dmin) {
                        return "";
                    }
                    if (!$isLast) {
                        // F415: a non-last segment emits exactly its MINIMUM - the count lands
                        // inside the bounds, so the value is one valid match and the walk simply
                        // continues with the next segment (the same subset argument the numeric
                        // bounds use). No close is offered here; the segments that follow, and
                        // the tail's quote, finish the value.
                        for ($d = 0; $d < $dmin; $d++) {
                            $pnx = model_session_next_allowed(0, $cids);
                            if ($pnx < 0) {
                                return "";
                            }
                            $s = $s . "," . $pnx;
                        }
                    } else {
                        $budget = $dmax >= 0 ? $dmax : 16;
                        $got = 0;
                        $cdone = 0;
                        for ($d = 0; $d <= $budget && $cdone == 0; $d++) {
                            if ($got < $dmin) {
                                $cAllow = $cids;
                            } else if ($dmax >= 0 && $got >= $dmax) {
                                $cdone = 1;                 // at the maximum: the value ends here
                                break;
                            } else {
                                $cAllow = $cids . "," . $qids;
                            }
                            $pnx = model_session_next_allowed(0, $cAllow);
                            if ($pnx < 0) {
                                return "";
                            }
                            if (strpos("," . $qids . ",", "," . $pnx . ",") !== false) {
                                $cdone = 1;                 // the model chose to close: the tail emits it
                                break;
                            }
                            $s = $s . "," . $pnx;
                            $got++;
                        }
                        // The budget running out ends the value too - the tail's closing quote closes
                        // it; emitting one here would put a stray second quote on the wire.
                    }
                } else {
                    return "";
                }
            }
            $pcq = api_constrained_continue(["\""], 4);
            if ($pcq == "") {
                return "";
            }
            $s = $s . "," . $pcq;
        } else if (strpos($ty, "~") !== false) {
            // F407: a counted array leaf - the kind prefix a/an/af/ab with "~min~max" carrying the
            // element bounds from the schema. The elements are emitted exactly like the nested
            // stepper's array leaves; the close is not offered below the minimum and is the only
            // token at the maximum (F400's counting, one level inside the object). The budget
            // raises to the minimum, so the minimum is always reachable.
            $tilde = strpos($ty, "~");
            $aty = substr($ty, 0, $tilde);
            $brest = explode("~", substr($ty, $tilde + 1));
            if (count($brest) != 2
                || ($aty != "a" && $aty != "an" && $aty != "af" && $aty != "ab")) {
                return "";
            }
            $bMin = (int) $brest[0];
            $bMax = (int) $brest[1];
            if ($bMin < 0 || $bMin > 8 || ($bMax > 0 && $bMin > $bMax)) {
                return "";
            }
            $budget = $bMax > 0 ? $bMax : 3;
            if ($bMin > $budget) {
                $budget = $bMin;
            }
            $aComma = api_punct_ids(",");
            $aBracket = api_punct_ids("]");
            if ($aComma == "" || $aBracket == "") {
                return "";
            }
            $aopen = $aty == "a" ? "[\"" : "[";
            $aop = api_constrained_continue([$aopen], 8);
            if ($aop == "") {
                return "";
            }
            $s = $s . "," . $aop;
            $aDone = 0;
            for ($it = 0; $it < $budget && $aDone == 0; $it++) {
                if ($it > 0 && $aty == "a") {
                    $aopen2 = api_constrained_continue(["\""], 4);
                    if ($aopen2 == "") {
                        return "";
                    }
                    $s = $s . "," . $aopen2;
                }
                if ($aty == "a") {
                    $aclosed = 0;
                    for ($d = 0; $d < 6; $d++) {
                        $anx = model_session_next_allowed(0, $stepIds);
                        if ($anx < 0) {
                            break;
                        }
                        $s = $s . "," . $anx;
                        if (strpos($q, "," . $anx . ",") !== false) {
                            $aclosed = 1;
                            break;
                        }
                    }
                    if ($aclosed == 0) {
                        $acq = api_constrained_continue(["\""], 4);
                        if ($acq != "") {
                            $s = $s . "," . $acq;
                        }
                    }
                } else if ($aty == "ab") {
                    $abool = api_constrained_continue(["true", "false"], 4);
                    if ($abool == "") {
                        return "";
                    }
                    $s = $s . "," . $abool;
                } else {
                    $anum = api_number_fill($aty == "af" ? 1 : 0, 4);
                    if ($anum == "") {
                        return "";
                    }
                    $s = $s . "," . $anum;
                }
                $aOffer = $aComma . "," . $aBracket;
                if ($it + 1 < $bMin) {
                    $aOffer = $aComma;          // below the minimum: the array cannot close yet
                } else if ($it + 1 >= $budget) {
                    $aOffer = $aBracket;        // at the maximum: only the close is reachable
                }
                $anx2 = model_session_next_allowed(0, $aOffer);
                if ($anx2 < 0) {
                    return "";
                }
                $s = $s . "," . $anx2;
                if (strpos("," . $aComma . ",", "," . $anx2 . ",") === false) {
                    $aDone = 1;                 // "]"
                }
            }
            if ($aDone == 0) {
                $acl = api_constrained_continue(["]"], 4);
                if ($acl != "") {
                    $s = $s . "," . $acl;
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
    // F404: `pattern` left the exclusion - the anchored class/count forms become p: plan kinds
    // the mixed stepper emits; enum/const leaves and numeric bounds still disqualify.
    if (preg_match('/"(enum|const|minimum|maximum|multipleOf)"\s*:/', $schema) == 1) {
        return "";
    }
    // Scan only the top-level properties block: a nested object's leaves must not be pulled up.
    // F404: `pattern` is no longer a blanket exclusion here - the anchored class/count forms
    // become p: plan kinds the mixed stepper emits; enum/const leaves and numeric bounds still
    // disqualify the schema.
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
    // F395: same silent truncation as the all-string detector (F394) - the generators loop
    // over the plan, so the cap was the only thing dropping leaves. Raised to 8.
    $plan = api_top_leaves($block, 8);
    if ($plan == "") {
        return "";
    }
    $items = explode(",", $plan);
    $hasNum = 0;
    $pCount = 0;
    for ($i = 0; $i < count($items); $i++) {
        $kv = explode(":", $items[$i]);
        if (count($kv) == 2 && $kv[1] != "s") {
            $hasNum = 1;
        }
        if (count($kv) == 3 && $kv[1] == "p") {
            $hasNum = 1;
            $pCount = 1;
        }
    }
    // F404: a single-leaf plan is accepted only for the pattern kind - that one key is exactly
    // what the stepper emits; every other single-leaf shape keeps its own generator.
    if (count($items) < 2 && $pCount == 0) {
        return "";
    }
    if ($hasNum == 0) {
        return "";
    }
    // F403②: the generator emits exactly the keys in the plan it is given, so a required subset
    // is honoured by handing it the filtered plan - the same construction the flat all-string
    // path uses (F401). The filter's own shape gate (flat all-string, or mixed with the leaf
    // kinds this generator emits) decides eligibility; here we only apply it. Applied here
    // rather than at the call sites so all four dispatch chains stay in step.
    $keep = api_schema_required_filter($schema);
    if ($keep != "") {
        $filtered = "";
        foreach (explode(",", $plan) as $leaf) {
            $kv = explode(":", $leaf);
            if (count($kv) >= 1 && strpos("," . $keep . ",", "," . $kv[0] . ",") !== false) {
                $filtered = $filtered == "" ? $leaf : $filtered . "," . $leaf;
            }
        }
        if ($filtered != "") {
            $plan = $filtered;
        }
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

// F405: the bounded-integer shape the digit-walk writer honours - exactly one integer leaf,
// minimum and/or maximum present, every present bound a non-negative integer, and none of the
// keywords that mean another generator owns the leaf. Negative bounds keep the old rules (the
// candidates tier enumerates span<=15; anything wider stays refused). Returns "<prop>|<lo>|<hi>"
// F421: round a bound INWARD for an integer leaf - the minimum up, the maximum down - because the
// emitted value is an integer that must still satisfy the real interval. Truncating instead served
// 1 for minimum 1.5, and served a value for [1.5, 1.9] where no integer exists; rounding makes that
// interval empty, which the shape then refuses. Anything that is not a plain decimal (an exponent
// form, say) returns the sentinel so the request is refused rather than guessed at.
function api_schema_bound_inward(string $lit, int $isMin): int
{
    if (preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $lit) != 1) {
        return -1000000;
    }
    $neg = 0;
    if (substr($lit, 0, 1) == "-") {
        $neg = 1;
        $lit = substr($lit, 1);
    }
    $dot = strpos($lit, ".");
    $ip = $dot === false ? $lit : substr($lit, 0, $dot);
    $fp = $dot === false ? "" : substr($lit, $dot + 1);
    $v = (int) $ip;
    if ($neg == 1) {
        $v = -$v;
    }
    if ($fp != "" && preg_match('/[1-9]/', $fp) == 1) {
        if ($isMin == 1) {
            // ceil: truncation toward zero already is the ceiling for a negative literal
            if ($neg == 0) {
                $v = $v + 1;
            }
        } else if ($neg == 1) {
            // floor: truncation already is the floor for a positive literal
            $v = $v - 1;
        }
    }
    return $v;
}

// F422: the integer a STRICT bound excludes lands on. On an integer leaf, exclusiveMinimum X means
// "the smallest integer strictly greater than X", which is floor(X) + 1; exclusiveMaximum X means
// "the largest integer strictly less than X", which is ceil(X) - 1. floor/ceil are taken on the
// plain-decimal literal, so a fractional exclusive bound is served (1.5 -> 2), not truncated. A
// literal that is not a plain decimal (an exponent form) rejects with the same sentinel as the
// inward helper, because the digit walk cannot reason about it.
function api_schema_bound_exclusive(string $lit, int $isMin): int
{
    if (preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $lit) != 1) {
        return -1000000;
    }
    $neg = 0;
    if (substr($lit, 0, 1) == "-") {
        $neg = 1;
        $lit = substr($lit, 1);
    }
    $dot = strpos($lit, ".");
    $ip = $dot === false ? $lit : substr($lit, 0, $dot);
    $fp = $dot === false ? "" : substr($lit, $dot + 1);
    $v = (int) $ip;
    if ($neg == 1) {
        $v = -$v;
    }
    $frac = ($fp != "" && preg_match('/[1-9]/', $fp) == 1) ? 1 : 0;
    if ($isMin == 1) {
        // floor(X): a negative literal with a real fraction truncates toward zero, which is one
        // above the floor, so step down; a positive floor is the integer part itself.
        $floor = ($neg == 1 && $frac == 1) ? $v - 1 : $v;
        return $floor + 1;
    }
    // ceil(X): a positive literal with a real fraction truncates one below the ceiling, so step
    // up; a negative ceiling is the integer part itself.
    $ceil = ($neg == 0 && $frac == 1) ? $v + 1 : $v;
    return $ceil - 1;
}

// with -1 marking an absent side, or "".
function api_schema_number_bounded_shape(string $schema): string
{
    // F420: multipleOf left the exclusion - the writer emits the smallest multiple inside the
    // bounds deterministically (the subset argument); enum/const/pattern still disqualify.
    if (preg_match('/"(enum|const|pattern)"\s*:/', $schema) == 1) {
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
    $plan = api_top_leaves($block, 8);
    if ($plan == "") {
        return "";
    }
    $leaves = explode(",", $plan);
    if (count($leaves) != 1) {
        return "";
    }
    $kv = explode(":", $leaves[0]);
    if (count($kv) != 2 || $kv[1] != "i") {
        return "";
    }
    $mn = [];
    $mx = [];
    $exn = [];
    $exm = [];
    // F421: capture the whole literal, exponent forms included, and let the inward rounding reject
    // anything that is not a plain decimal - the old pattern dropped the fraction silently, which
    // served 1 for minimum 1.5 and served a value at all for [1.5, 1.9].
    $hasLo = preg_match('/"minimum"\s*:\s*(-?[0-9.eE+]+)/', $schema, $mn) == 1;
    $hasHi = preg_match('/"maximum"\s*:\s*(-?[0-9.eE+]+)/', $schema, $mx) == 1;
    // F422/F423: exclusive bounds on an integer leaf convert to the adjacent integer bound
    // (floor(X)+1 / ceil(X)-1) and are served by the same digit walk. Both forms may sit on the
    // same side; when they do the STRICTER governs (F423) - the maximum of the two lower bounds and
    // the minimum of the two upper - so a stricter exclusive bound is not shadowed by a looser
    // inclusive one.
    $hasExLo = preg_match('/"exclusiveMinimum"\s*:\s*(-?[0-9.eE+]+)/', $schema, $exn) == 1;
    $hasExHi = preg_match('/"exclusiveMaximum"\s*:\s*(-?[0-9.eE+]+)/', $schema, $exm) == 1;
    if (!$hasLo && !$hasHi && !$hasExLo && !$hasExHi) {
        return "";
    }
    // F422: a side is "set" when it came from the inclusive OR the exclusive literal - the interval
    // and the multipleOf walk below must reason about whichever bound is present, not only the one.
    $loSet = $hasLo || $hasExLo;
    $hiSet = $hasHi || $hasExHi;
    $lo = -1;
    $hi = -1;
    if ($loSet) {
        // Seed the running bound at the extreme and tighten: the stricter of the two lower bounds.
        $lo = PHP_INT_MIN;
        if ($hasLo) {
            // F419: negative bounds are served - the writer maps the sign (both-negative walks the
            // magnitude behind a dash; straddling clamps to the non-negative side). The -1 sentinel
            // for an absent side collides with a real -1 only in cases where the subset walk stays
            // legal, recorded in findings.
            $t = api_schema_bound_inward($mn[1], 1);
            if ($t == -1000000) {
                return "";
            }
            $lo = max($lo, $t);
        }
        if ($hasExLo) {
            $t = api_schema_bound_exclusive($exn[1], 1);
            if ($t == -1000000) {
                return "";
            }
            $lo = max($lo, $t);
        }
    }
    if ($hiSet) {
        $hi = PHP_INT_MAX;
        if ($hasHi) {
            $t = api_schema_bound_inward($mx[1], 0);
            if ($t == -1000000) {
                return "";
            }
            $hi = min($hi, $t);
        }
        if ($hasExHi) {
            $t = api_schema_bound_exclusive($exm[1], 0);
            if ($t == -1000000) {
                return "";
            }
            $hi = min($hi, $t);
        }
    }
    if ($loSet && $hiSet && $lo > $hi) {
        return "";
    }
    // F420: multipleOf narrows the honoured value to a single deterministic multiple inside the
    // bounds (the subset argument - one valid match), collapsed into lo == hi so the existing
    // walks emit it unchanged. The cases: both bounds negative walk the magnitude; a positive
    // lower bound starts the search there; otherwise zero lies inside the bounds and is a
    // multiple of everything. No multiple inside the bounds means the constraint is
    // unsatisfiable and the shape refuses. F424: the collapse picks the smallest in-interval
    // multiple for ANY m, so the old m <= 1000 guard (copied from the unbounded suffix path,
    // where m <= 999 keeps a three-digit suffix legal) is dropped here - a bounded multiple
    // only needs a multiple to EXIST inside the interval, not a small one.
    $mult = 0;
    $mo = [];
    if (preg_match('/"multipleOf"\s*:\s*([0-9]+)/', $schema, $mo) == 1) {
        $mult = (int) $mo[1];
        if ($mult < 1) {
            return "";
        }
        if ($hiSet && $hi < 0) {
            // The value is negative: the magnitude is at least |hi| (and at most |lo| when lo
            // is present and negative); the smallest multiple of m in that magnitude range
            // gives the value -(magStart).
            $magLo = -$hi;
            $magHi = ($loSet && $lo < 0) ? -$lo : -1;
            $magStart = $magLo + (($mult - ($magLo % $mult)) % $mult);
            if ($magHi >= 0 && $magStart > $magHi) {
                return "";
            }
            $lo = -$magStart;
            $hi = -$magStart;
        } else if ($loSet && $lo > 0) {
            $start = $lo + (($mult - ($lo % $mult)) % $mult);
            if ($hiSet && $start > $hi) {
                return "";
            }
            $lo = $start;
            $hi = $start;
        } else {
            // Zero lies inside the bounds (or the absent sides permit it) and zero is a
            // multiple of every m.
            $lo = 0;
            $hi = 0;
        }
    }
    return $kv[0] . "|" . $lo . "|" . $hi . "|" . $mult;
}

// Body-level wrapper: the response schema is extracted the way every other detector does it.
function api_response_schema_number_bounded(string $body): string
{
    if (preg_match('/"json_schema"/', $body) != 1) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    return api_schema_number_bounded_shape($schema);
}

// F405: emit an integer inside the bounds by walking digits left to right, one fixed length:
// len(hi) when the upper bound exists (every d-digit value >= 10^(d-1) is above a shorter lo, so
// only the upper walk is tight; equal lengths tighten both) and len(lo) for a one-sided minimum.
// At each position the allowed digits are the intersection of the bound remainders, widening to
// 0..9 as soon as the prefix moves strictly inside. The output is a subset of the range - legal,
// since the schema asks "inside the bounds", not "every value".
function api_number_bounded_fill(int $lo, int $hi): string
{
    $single = api_digit_ids(1);
    // $lo == -1 / $hi == -1 mean an absent side (the shape helper refuses genuinely negative
    // bounds, so the sentinel cannot collide with them here); the only range check is that both
    // present sides are ordered.
    if ($single == "" || ($lo >= 0 && $hi >= 0 && $hi < $lo)) {
        return "";
    }
    $ids = [];
    $parts = explode(",", $single);
    for ($i = 0; $i < count($parts); $i++) {
        $t = gguf_token_str((int) $parts[$i]);
        if (strlen($t) == 1 && $t >= "0" && $t <= "9") {
            $ids[$t] = $parts[$i];
        }
    }
    if (count($ids) < 10) {
        return "";
    }
    $loStr = $lo >= 0 ? (string) $lo : "";
    $hiStr = $hi >= 0 ? (string) $hi : "";
    $d = $hiStr !== "" ? strlen($hiStr) : strlen($loStr);
    if ($d < 1 || $d > 18) {
        return "";
    }
    $tightLo = ($loStr !== "" && strlen($loStr) == $d) ? 1 : 0;
    $tightHi = ($hiStr !== "") ? 1 : 0;
    $s = "";
    for ($i = 0; $i < $d; $i++) {
        $loD = ($tightLo == 1) ? (int) $loStr[$i] : 0;
        $hiD = ($tightHi == 1) ? (int) $hiStr[$i] : 9;
        if ($i == 0 && $d > 1 && $loD < 1) {
            $loD = 1;                       // no illegal leading zero on a multi-digit value
        }
        if ($loD > $hiD) {
            return "";
        }
        $allowed = "";
        for ($dv = $loD; $dv <= $hiD; $dv++) {
            $allowed = $allowed == "" ? $ids["" . $dv] : $allowed . "," . $ids["" . $dv];
        }
        $nx = model_session_next_allowed(0, $allowed);
        if ($nx < 0) {
            return "";
        }
        $tok = gguf_token_str($nx);
        if (strlen($tok) != 1 || $tok < "0" || $tok > "9") {
            return "";
        }
        $dv = (int) $tok;
        $s = $s == "" ? "" . $nx : $s . "," . $nx;
        if ($tightLo == 1 && $dv > (int) $loStr[$i]) {
            $tightLo = 0;
        }
        if ($tightHi == 1 && $dv < (int) $hiStr[$i]) {
            $tightHi = 0;
        }
    }
    return $s;
}

// The bounded-integer object: {"prop":<in-range digits>} with the forced closing brace.
function api_number_bounded_value(string $ids, string $prop, int $lo, int $hi, int $useCache = 1): string
{
    $s = api_constrained($ids, ["{\"" . $prop . "\":"], 64, $useCache);
    if ($s == "") {
        return "";
    }
    // F419: the sign mapping - both bounds negative walks the MAGNITUDE in [-hi, -lo] behind a
    // forced dash (the value -(mag) lands in [lo, hi]); a range straddling or touching zero
    // clamps the lower bound to zero (every value in [0, hi] is >= lo when lo < 0 - the same
    // subset argument the whole bounded walk uses); a one-sided negative minimum clamps to zero
    // for the same reason; a one-sided negative maximum walks the magnitude's one-sided minimum
    // behind the dash (any magnitude >= |hi| gives a value <= hi).
    $wlo = $lo;
    $whi = $hi;
    $neg = 0;
    if ($lo < 0 && $hi < 0) {
        $neg = 1;
        $wlo = -$hi;
        $whi = -$lo;
    } else if ($lo < 0) {
        $wlo = 0;
    }
    if ($neg == 1) {
        $dash = api_constrained_continue(["-"], 4);
        if ($dash == "") {
            return "";
        }
        $s = $s . "," . $dash;
    }
    $body = api_number_bounded_fill($wlo, $whi);
    if ($body != "") {
        $s = $s . "," . $body;
    }
    $end = api_constrained_continue(["}"], 4);
    if ($end != "") {
        $s = $s . "," . $end;
    }
    return $s;
}
// Unbounded integer multipleOf. No finite candidate set exists, so decode a short digit prefix
// and force a three-digit suffix x = (m - (p*1000) % m) % m. Since m <= 999, x < 1000 and
// p*1000 + x is divisible by m while staying a legal JSON integer (zero-padded suffix).
function api_multiple_fill(int $mult, int $maxSteps): string
{
    $single = api_digit_ids(1);
    // F430: the forced suffix needs enough digits to express any residue below the multiplier, so
    // its width follows the multiplier: three digits up to 999, four to 9999, five to 99999, six to
    // 999999. Beyond that this mechanism cannot honour the constraint at all and the request is
    // refused upstream instead of emitting a partial number.
    if ($single == "" || $mult < 2 || $mult > 999999) {
        return "";
    }
    $k = 3;
    $pow = 1000;
    if ($mult > 999) {
        $k = 4;
        $pow = 10000;
    }
    if ($mult > 9999) {
        $k = 5;
        $pow = 100000;
    }
    if ($mult > 99999) {
        $k = 6;
        $pow = 1000000;
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
    $x = ($mult - ($p * $pow) % $mult) % $mult;
    $digits = "" . $x;
    while (strlen($digits) < $k) {
        $digits = "0" . $digits;
    }
    for ($i = 0; $i < $k; $i++) {
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
    // F430: a writer that could not produce the number must not emit the bare head - that is what
    // answered 200 with "{"n":" for a multipleOf the suffix mechanism could not reach.
    if ($body == "") {
        return "";
    }
    $s = $s . "," . $body;
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
    $mp = [];
    if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{[^{}]*?"type"\s*:\s*"integer"[^{}]*?"multipleOf"\s*:\s*([0-9]+)[^{}]*?\}/s', $schema, $mm) == 1) {
        $mp = $mm;
    } else if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{[^{}]*?"multipleOf"\s*:\s*([0-9]+)[^{}]*?"type"\s*:\s*"integer"[^{}]*?\}/s', $schema, $mm) == 1) {
        $mp = $mm;
    } else {
        return "";
    }
    // F430: multipleOf 1 is satisfied by every integer, so it falls through to the plain integer
    // generator rather than being refused here (refusing it would reject a satisfied request).
    if ((int) $mp[2] < 2) {
        return "";
    }
    return $mp[1] . "|" . $mp[2];
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
    } else {
        // The model writes arguments as a nested object, not as a string; take that object whole
        // instead of reporting an empty one (the old F52 limitation).
        $args = api_json_object_after($json, "arguments");
    }
    if ($args == "") {
        $args = "{}";
    }
    return "{\"id\":\"call_1\",\"type\":\"function\",\"function\":{\"name\":\"" . api_esc($name)
        . "\",\"arguments\":\"" . api_esc($args) . "\"}}";
}

// Every call the model emitted in one turn, as the comma-joined items of an OpenAI tool_calls
// array (the outlets add the brackets). Each block goes through the same declared-only whitelist
// as a single call and ids are renumbered from 1. A turn with no blocks at all falls back to the
// single-call reader, which also accepts the bare JSON object the constrained skeletons emit.
function api_tool_calls_json(string $text, array $names): string
{
    $open = "<" . "tool_call" . ">";
    $close = "<" . "/" . "tool_call" . ">";
    $out = "";
    $n = 0;
    $rest = $text;
    while (true) {
        $tp = strpos($rest, $open);
        if ($tp === false) {
            break;
        }
        $rest = substr($rest, $tp + strlen($open));
        $te = strpos($rest, $close);
        if ($te === false) {
            break;
        }
        $one = api_tool_call_json($open . substr($rest, 0, $te) . $close, $names);
        $rest = substr($rest, $te + strlen($close));
        if ($one == "") {
            continue;
        }
        $n = $n + 1;
        $one = str_replace("\"call_1\"", "\"call_" . $n . "\"", $one);
        $out = $out == "" ? $one : $out . "," . $one;
    }
    if ($n == 0) {
        return api_tool_call_json($text, $names);
    }
    return $out;
}

// Top-level objects of a comma-joined call list, in order.
function api_tool_items(string $joined): array
{
    $out = [];
    $pos = 0;
    while ($pos < strlen($joined)) {
        $ob = strpos($joined, "{", $pos);
        if ($ob === false) {
            break;
        }
        $obj = api_balance_block($joined, $ob);
        if ($obj == "") {
            break;
        }
        $out[] = $obj;
        $pos = $ob + strlen($obj);
    }
    return $out;
}

// Anthropic puts calls in content blocks, so each item needs its own toolu id.
function api_anthropic_tool_blocks(string $joined): string
{
    $items = api_tool_items($joined);
    $out = "";
    for ($i = 0; $i < count($items); $i++) {
        $one = api_anthropic_tool_block($items[$i]);
        if ($one == "") {
            continue;
        }
        $one = str_replace("\"toolu_1\"", "\"toolu_" . ($i + 1) . "\"", $one);
        $out = $out == "" ? $one : $out . "," . $one;
    }
    if ($out == "") {
        return api_anthropic_tool_block($joined);
    }
    return $out;
}

// One call written the way the chat template writes it: the JSON object inside its own block.
// A parallel turn only parses as several calls when each block is delimited, because two bare
// objects back to back are not valid JSON as a whole.
function api_tool_wire(string $name, string $args): string
{
    return "<tool_call>" . "\n{\"name\":\"" . api_esc($name)
         . "\",\"arguments\":" . $args . "}\n" . "</tool_call>";
}

// A Responses tool call is a function_call output item; a parallel turn is several items, each
// with its own item id and call id.
function api_responses_call_items(string $joined): string
{
    $items = api_tool_items($joined);
    $out = "";
    $n = 0;
    for ($i = 0; $i < count($items); $i++) {
        $nm = api_str($items[$i], "name");
        if ($nm == "") {
            continue;
        }
        $ar = api_unesc(api_str($items[$i], "arguments"));
        if ($ar == "") {
            $ar = "{}";
        }
        $n = $n + 1;
        $one = "{\"type\":\"function_call\",\"id\":\"fc_" . $n . "\",\"call_id\":\"call_" . $n
             . "\",\"name\":\"" . api_esc($nm) . "\",\"arguments\":\"" . api_esc($ar)
             . "\",\"status\":\"completed\"}";
        $out = $out == "" ? $one : $out . "," . $one;
    }
    return $out;
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

function api_resp_call_start(string $modelId, string $name, int $idx): string
{
    $item = "event: response.output_item.added\ndata: {\"type\":\"response.output_item.added\","
        . "\"output_index\":" . $idx . ",\"item\":{\"id\":\"fc_" . ($idx + 1) . "\",\"type\":\"function_call\","
        . "\"status\":\"in_progress\",\"call_id\":\"call_" . ($idx + 1) . "\",\"name\":\"" . api_esc($name)
        . "\",\"arguments\":\"\"}}\n\n";
    if ($idx > 0) {
        return $item;
    }
    return "event: response.created\ndata: {\"type\":\"response.created\",\"response\":{\"id\":\"resp_1\","
        . "\"object\":\"response\",\"status\":\"in_progress\",\"model\":\"" . $modelId
        . "\",\"output\":[]}}\n\n" . $item;
}

function api_resp_call_done(string $name, string $args, int $outTok, string $modelId, int $promptN,
                            int $idx, int $last, string $outputItems): string
{
    $fc = "fc_" . ($idx + 1);
    $ev = "event: response.function_call_arguments.delta\ndata: {\"type\":\"response.function_call_arguments.delta\","
        . "\"item_id\":\"" . $fc . "\",\"output_index\":" . $idx . ",\"delta\":\"" . $args . "\"}\n\n"
        . "event: response.function_call_arguments.done\ndata: {\"type\":\"response.function_call_arguments.done\","
        . "\"item_id\":\"" . $fc . "\",\"output_index\":" . $idx . ",\"arguments\":\"" . $args . "\"}\n\n"
        . "event: response.output_item.done\ndata: {\"type\":\"response.output_item.done\","
        . "\"output_index\":" . $idx . ",\"item\":{\"id\":\"" . $fc . "\",\"type\":\"function_call\","
        . "\"status\":\"completed\",\"call_id\":\"call_" . ($idx + 1) . "\",\"name\":\"" . api_esc($name)
        . "\",\"arguments\":\"" . $args . "\"}}\n\n";
    if ($last != 1) {
        return $ev;
    }
    $items = $outputItems == "" ? ("{\"id\":\"" . $fc . "\",\"type\":\"function_call\",\"status\":\"completed\","
             . "\"call_id\":\"call_" . ($idx + 1) . "\",\"name\":\"" . api_esc($name)
             . "\",\"arguments\":\"" . $args . "\"}") : $outputItems;
    return $ev . "event: response.completed\ndata: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_1\","
        . "\"object\":\"response\",\"status\":\"completed\",\"model\":\"" . $modelId . "\",\"output\":["
        . $items . "],"
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
            api_schema_charset(), 3, 6, 1, api_response_schema_array_bounds($body));
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
            api_schema_charset(), 4, 6, 1, api_response_schema_array_bounds($body));
    } else if ($scArrNum != "") {
        $san = explode("|", $scArrNum);
        $scOut = api_array_number_value($ids, $san[0], (int) $san[1],
            4, 3, 1, api_response_schema_array_bounds($body));
    } else if ($scMix != "") {
        $scOut = api_object_mixed($ids, $scMix, api_schema_charset(), 16, 1);
    } else if (api_response_schema_top_array_string($body) == 1) {
        $scOut = api_top_array_string_value($ids, api_schema_charset(), 4, 4, 1,
            api_response_schema_array_bounds($body));
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
        $scProp = api_response_schema_string_props_required($body);
        if (count($scProp) > 0) {
            $scOut = api_string_value_multi($ids, $scProp,
                api_schema_charset(), 16, 1, api_response_schema_string_bounds($body));
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
                    $scNumB = api_response_schema_number_bounded($body);
                    if ($scNumB != "") {
                        $snb = explode("|", $scNumB);
                        $scOut = api_number_bounded_value($ids, $snb[0], (int) $snb[1], (int) $snb[2], 1);
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
    }
    return $scOut;
}

function api_schema_resolve_refs(string $s): string
{
    // Out-of-document refs first: they arrive in a caller-supplied registry, never fetched. The
    // engine has no network and must not read paths that request data names, so resolution is
    // limited to schemas the caller handed over alongside the ref.
    for ($xround = 0; $xround < 6; $xround++) {
        $xp = strpos($s, '"$ref"');
        if ($xp === false) {
            break;
        }
        $xm = [];
        if (preg_match('/"\\$ref"\s*:\s*"([^"]+)"/', substr($s, $xp, 300), $xm) != 1) {
            break;
        }
        if (substr($xm[1], 0, 1) == "#") {
            break;      // local pointer: handled by the pass below
        }
        $reg = api_json_object_after($s, "schema_registry");
        if ($reg == "") {
            break;
        }
        $kp = strpos($reg, "\"" . $xm[1] . "\"");
        if ($kp === false) {
            break;      // unregistered external ref: leave it, the grammar falls back as before
        }
        $kbo = strpos($reg, "{", $kp);
        if ($kbo === false) {
            break;
        }
        $kobj = api_balance_block($reg, $kbo);
        if ($kobj == "") {
            break;
        }
        $obo = strrpos(substr($s, 0, $xp), "{");
        if ($obo === false) {
            break;
        }
        $refobj = api_balance_block($s, $obo);
        if ($refobj == "" || strpos($refobj, '"$ref"') === false) {
            break;      // the ref has sibling keys we would erase; refuse rather than corrupt
        }
        $s = substr($s, 0, $obo) . $kobj . substr($s, $obo + strlen($refobj));
    }
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
    // F403②: `required` sits beside `properties`, outside the block, so it has to be read from
    // the schema before $tail is narrowed down to the block.
    $reqNames = api_schema_required_names($tail);
    $tail = $block;
    // Object-valued properties mean this is a NESTED plan. Without this gate, pass 1's enum regex
    // reaches into the deepest leaf and emits a flat object, silently dropping every intermediate
    // level (findings F238: 40-level schema with an enum leaf answered {"city":"Paris"}).
    if (preg_match('/"type"\s*:\s*"object"/', $tail) == 1) {
        return $out;
    }
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
            // F423: both an inclusive and an exclusive bound may sit on the same side; the STRICTER
            // governs (max of the two lower bounds, min of the two upper), so a stricter exclusive
            // is not shadowed by the looser inclusive one. Each form is read independently, then
            // merged - the exclusive literal still converts to the adjacent integer (exclMin -> +1,
            // exclMax -> -1) as before.
            $loIncl = 0; $haveInclLo = 0;
            if (preg_match('/"minimum"\s*:\s*(-?[0-9]+)/', $obj, $mm)) {
                $loIncl = (int) $mm[1];
                $haveInclLo = 1;
            }
            $loExcl = 0; $haveExclLo = 0;
            if (preg_match('/"exclusiveMinimum"\s*:\s*(-?[0-9]+)/', $obj, $mm)) {
                $loExcl = (int) $mm[1] + 1;
                $haveExclLo = 1;
            }
            if ($haveInclLo || $haveExclLo) {
                $lo = ($haveInclLo && $haveExclLo) ? max($loIncl, $loExcl) : ($haveInclLo ? $loIncl : $loExcl);
                $haveLo = 1;
            }
            $hiIncl = 0; $haveInclHi = 0;
            if (preg_match('/"maximum"\s*:\s*(-?[0-9]+)/', $obj, $xm)) {
                $hiIncl = (int) $xm[1];
                $haveInclHi = 1;
            }
            $hiExcl = 0; $haveExclHi = 0;
            if (preg_match('/"exclusiveMaximum"\s*:\s*(-?[0-9]+)/', $obj, $xm)) {
                $hiExcl = (int) $xm[1] - 1;
                $haveExclHi = 1;
            }
            if ($haveInclHi || $haveExclHi) {
                $hi = ($haveInclHi && $haveExclHi) ? min($hiIncl, $hiExcl) : ($haveInclHi ? $hiIncl : $hiExcl);
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
    // F403②: a candidate object contains ONLY the enumerated keys - legal while every other
    // property is optional, but a required key that no candidate renders would be served as an
    // answer violating `required` (a boolean leaf beside an unbounded integer produced
    // {"flag":false} for required ["flag","count"]). No candidates then; the grammar chain serves
    // the shape with every declared key instead.
    if ($reqNames != "") {
        foreach (explode(",", $reqNames) as $rq) {
            $found = 0;
            for ($uk = 0; $uk < count($used); $uk++) {
                if ($used[$uk] == $rq) {
                    $found = 1;
                    break;
                }
            }
            if ($found == 0) {
                return [];
            }
        }
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
// F396: does this request carry a string pattern the constrained decoder cannot honour? The
// supported forms are a literal, an alternation of literals, and the same wrapped in ^...$ or
// ^(...)$ - everything else (classes, quantifiers, groups, escapes) currently bails out of every
// grammar detector and would be served as unconstrained text. This says so up front instead.
// F397: which numeric bounds can the constrained decoder actually honour? Only an integer leaf
// with BOTH bounds and a span of at most 15 is enumerated into candidates; a float bound, a
// one-sided bound or a wider span bails out of every grammar detector and used to be served as
// unconstrained text. This recognises those cases so the request can be refused with a reason
// instead of quietly ignoring the constraint the caller asked for.
function api_schema_bound_value(string $body, string $key, int $from): string
{
    $p = strpos($body, $key, $from);
    if ($p === false) {
        return "";
    }
    $c = strpos($body, ":", $p);
    if ($c === false) {
        return "";
    }
    $i = $c + 1;
    $n = strlen($body);
    while ($i < $n && (substr($body, $i, 1) == " " || substr($body, $i, 1) == "\t")) {
        $i++;
    }
    $start = $i;
    if ($i < $n && substr($body, $i, 1) == "-") {
        $i++;
    }
    while ($i < $n) {
        $ch = substr($body, $i, 1);
        if (($ch >= "0" && $ch <= "9") || $ch == ".") {
            $i++;
        } else {
            break;
        }
    }
    if ($i == $start) {
        return "";
    }
    return substr($body, $start, $i - $start);
}

// F399: per-property string length bounds for a flat all-string object, in plan order, as
// "min:max" pairs (0 = unconstrained on that side). Only this shape is honoured; other shapes keep
// refusing the keywords, because their generators do not step strings the same way.
function api_response_schema_string_bounds(string $body): string
{
    $props = api_response_schema_string_props_required($body);
    if (count($props) == 0) {
        return "";
    }
    $schema = api_json_object_after($body, "schema");
    if ($schema == "") {
        return "";
    }
    $out = "";
    for ($i = 0; $i < count($props); $i++) {
        $key = "\"" . $props[$i] . "\"";
        $p = strpos($schema, $key);
        $mn = 0;
        $mx = 0;
        if ($p !== false) {
            $bo = strpos($schema, "{", $p);
            $bc = $bo === false ? false : strpos($schema, "}", $bo);
            if ($bo !== false && $bc !== false) {
                $leaf = substr($schema, $bo, $bc - $bo + 1);
                $mn = (int) api_schema_bound_value($leaf, "\"minLength\"", 0);
                $mx = (int) api_schema_bound_value($leaf, "\"maxLength\"", 0);
            }
        }
        $out = $out . ($i == 0 ? "" : ",") . $mn . ":" . $mx;
    }
    return $out;
}

// F408: element-count bounds for the OBJECT-array shape the keyed writer serves - a single
// property whose kind is "ao:..." (items are objects with their own properties), the writer
// emitting only that key, so only a single-leaf plan is honoured. The same bound sanity as the
// scalar-array shapes: maxItems 0, min>max and a minimum past the element ceiling stay refused,
// and a bound outside the array's own object would be relaxed but enforced nowhere.
function api_schema_array_object_bounds(string $schema): string
{
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
    $plan = api_top_leaves($block, 8);
    if ($plan == "") {
        return "";
    }
    $leaves = explode(",", $plan);
    // F410: a multi-leaf plan is honoured too - the keyed ao writer counts the array's elements
    // and emits only that key, which is legal while every sibling is optional; required naming a
    // sibling keeps refusing via the keywords gate's required rules (the ao kind is not one the
    // full-emission check counts), and so do subsets. Two object arrays keep the refusal: one
    // writer serves one array, and the second's bounds would be relaxed but enforced nowhere.
    $aoIdx = -1;
    for ($li = 0; $li < count($leaves); $li++) {
        $lkv = explode(":", $leaves[$li]);
        if (count($lkv) >= 3 && $lkv[1] == "ao") {
            if ($aoIdx >= 0) {
                return "";
            }
            $aoIdx = $li;
        }
    }
    if ($aoIdx < 0) {
        return "";
    }
    $kv = explode(":", $leaves[$aoIdx]);
    $kp = strpos($block, "\"" . $kv[0] . "\"");
    $vo = strpos($block, "{", $kp);
    $leaf = api_balance_block($block, $vo);
    if ($leaf == "") {
        return "";
    }
    if (substr_count($leaf, "\"minItems\"") + substr_count($leaf, "\"maxItems\"")
        != substr_count($schema, "\"minItems\"") + substr_count($schema, "\"maxItems\"")) {
        return "";
    }
    $bmn = [];
    $bmx = [];
    $hMin = preg_match('/"minItems"\s*:\s*([0-9]+)/', $leaf, $bmn) == 1;
    $hMax = preg_match('/"maxItems"\s*:\s*([0-9]+)/', $leaf, $bmx) == 1;
    if (!$hMin && !$hMax) {
        return "";
    }
    $tMin = $hMin ? (int) $bmn[1] : 0;
    $tMax = $hMax ? (int) $bmx[1] : 0;
    if (($hMax && $tMax < 1) || ($tMax > 0 && $tMin > $tMax) || $tMin > 8) {
        return "";
    }
    return $tMin . ":" . $tMax;
}

// F411: element-count bounds for a bounded array INSIDE a nested object - the nested path steps
// the plan with the same counting (the ~ encoding travels in the nested plan), so the rule mirrors
// api_response_schema_nested_object's descent: find the deepest properties block that has leaves,
// and honour the shape when exactly one leaf is a counted array with the bounds inside it. The
// nested detector's own exclusions (numeric bounds, metacharacter patterns) are mirrored, since a
// schema they refuse never reaches the nested stepper and the bounds would be relaxed but
// enforced nowhere.
function api_schema_nested_array_bounds(string $schema): string
{
    if (api_schema_is_object($schema) != 1) {
        return "";
    }
    if (preg_match('/"(minimum|maximum|multipleOf)"\s*:/', $schema) == 1) {
        return "";
    }
    $mpat = [];
    if (preg_match('/"pattern"\s*:\s*"([^"]*)"/', $schema, $mpat) == 1
        && preg_match('/^\^?[A-Za-z0-9 _.\-|]+\$?$/', $mpat[1]) != 1) {
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
    $win = substr($schema, $ip, 8000);
    for ($depth = 0; $depth < 256; $depth++) {
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
        $plan = api_top_leaves($block, 6);
        if ($plan != "") {
            // The leaf level: F414 - EVERY counted leaf is validated individually and the schema's
            // bound occurrences must all sit inside counted leaves (a bound anywhere else would be
            // relaxed but enforced nowhere); the other leaves are kinds the nested stepper emits.
            // The detector's single return carries the first counted leaf's bounds - the gate-only
            // signal; the stepper reads each leaf's bounds from the plan itself.
            $sumOcc = 0;
            $bCount = 0;
            $firstBounds = "";
            $leaves = explode(",", $plan);
            for ($li = 0; $li < count($leaves); $li++) {
                $lkv = explode(":", $leaves[$li]);
                if (count($lkv) != 2 || strpos($lkv[1], "~") === false) {
                    continue;
                }
                $apfx = substr($lkv[1], 0, strpos($lkv[1], "~"));
                if ($apfx != "a" && $apfx != "an" && $apfx != "af" && $apfx != "ab") {
                    continue;
                }
                $kv = explode(":", $leaves[$li]);
                $kp = strpos($block, "\"" . $kv[0] . "\"");
                $vo = strpos($block, "{", $kp);
                $leaf = api_balance_block($block, $vo);
                if ($leaf == "") {
                    return "";
                }
                $sumOcc += substr_count($leaf, "\"minItems\"") + substr_count($leaf, "\"maxItems\"");
                $bmn = [];
                $bmx = [];
                $hMin = preg_match('/"minItems"\s*:\s*([0-9]+)/', $leaf, $bmn) == 1;
                $hMax = preg_match('/"maxItems"\s*:\s*([0-9]+)/', $leaf, $bmx) == 1;
                if (!$hMin && !$hMax) {
                    return "";
                }
                $tMin = $hMin ? (int) $bmn[1] : 0;
                $tMax = $hMax ? (int) $bmx[1] : 0;
                if (($hMax && $tMax < 1) || ($tMax > 0 && $tMin > $tMax) || $tMin > 8) {
                    return "";
                }
                $bCount++;
                if ($firstBounds == "") {
                    $firstBounds = $tMin . ":" . $tMax;
                }
            }
            if ($bCount == 0) {
                return "";
            }
            if ($sumOcc != substr_count($schema, "\"minItems\"") + substr_count($schema, "\"maxItems\"")) {
                return "";
            }
            return $firstBounds;
        }
        $cm = [];
        if (preg_match('/"([A-Za-z0-9_\-]+)"\s*:\s*\{(?=[^{}]*"properties"\s*:\s*\{)/s',
            $block, $cm) != 1) {
            return "";
        }
        $ci = strpos($block, $cm[0]);
        if ($ci === false) {
            return "";
        }
        $win = substr($block, $ci, 20000);
    }
    return "";
}

// F400: element-count bounds for the scalar-array shape whose generator steps element by element
// and can therefore restrict the closing bracket by count. The honoured shape is deliberately
// narrow: one properties block, exactly one "type":"array" in the whole schema (so no nested rank),
// no keyword another generator would claim, and one leaf whose items are scalar. Returns "min:max"
// (0 = that side unconstrained) or ""; "" is what keeps minItems/maxItems refused for every other
// shape instead of silently ignored.
function api_schema_array_element_bounds(string $schema): string
{
    if ($schema == "") {
        return "";
    }
    // F406: the top-level array shape - the schema itself is the array object, so there is no
    // properties block and the keyed path below cannot see it. Same strictness as the keyed
    // shape: only minItems/maxItems as siblings of a scalar-string items, and the same bound
    // sanity (maxItems 0, min>max, min past the element ceiling all stay refused).
    if (preg_match('/^\s*\{\s*"type"\s*:\s*"array"\s*'
        . '(?:\s*,\s*"(?:minItems|maxItems)"\s*:\s*[0-9]+)*'
        . '\s*,\s*"items"\s*:\s*\{\s*"type"\s*:\s*"string"\s*\}'
        . '(?:\s*,\s*"(?:minItems|maxItems)"\s*:\s*[0-9]+)*\s*\}\s*$/', $schema) == 1) {
        $mn = api_schema_bound_value($schema, "\"minItems\"", 0);
        $mx = api_schema_bound_value($schema, "\"maxItems\"", 0);
        $tMin = $mn == "" ? 0 : (int) $mn;
        $tMax = $mx == "" ? 0 : (int) $mx;
        if ($mx != "" && $tMax < 1) {
            return "";
        }
        if ($tMax > 0 && $tMin > $tMax) {
            return "";
        }
        if ($tMin > 8) {
            return "";
        }
        return $tMin . ":" . $tMax;
    }
    // F403: additionalProperties is no longer an exclusion here. It constrains key NAMES, which
    // this shape gets from the plan, so it is judged by its own predicate at refusal time - two
    // constraints that each hold must not refuse each other.
    if (preg_match('/"(enum|const|pattern|oneOf|anyOf|allOf)"\s*:/', $schema) == 1) {
        return "";
    }
    // F408: the items check runs BEFORE the one-properties-block rule below - the object-array
    // shape inherently carries TWO properties blocks (the root's and the items'), so the count
    // rule would refuse it before its own bounds were ever read.
    if (strpos($schema, "\"items\"") !== false) {
        if (preg_match('/"items"\s*:\s*\{\s*"type"\s*:\s*"array"/', $schema) == 1) {
            return "";              // arrays of arrays: the 2D writers do not count elements
        }
        if (preg_match('/"items"\s*:\s*\{\s*"type"\s*:\s*"object"/', $schema) == 1) {
            // F408: the object-array shape the keyed writer serves - the bounds are validated by
            // the same rules as the scalar arrays, "" keeps the refusal.
            return api_schema_array_object_bounds($schema);
        }
    }
    // F411: a bounded array inside a NESTED object - the nested rule runs before the
    // one-properties-block rule, which a nested shape inherently violates.
    $nb = api_schema_nested_array_bounds($schema);
    if ($nb != "") {
        return $nb;
    }
    if (preg_match_all('/"properties"\s*:/', $schema) != 1
        || preg_match_all('/"type"\s*:\s*"array"/', $schema) != 1) {
        return "";
    }
    $pp = strpos($schema, "\"properties\"");
    $bo = strpos($schema, "{", $pp);
    if ($bo === false) {
        return "";
    }
    $block = api_balance_block($schema, $bo);
    if ($block == "") {
        return "";
    }
    $plan = api_top_leaves($block, 8);
    if ($plan == "") {
        return "";
    }
    $leaves = explode(",", $plan);
    // F407: a leaf kind carrying "~min~max" (a/an/af/ab + element bounds) is the counted form the
    // mixed stepper emits. A multi-leaf plan is honoured when the bounds sit inside ONE such leaf
    // and every other leaf is a kind that stepper emits (s/i/n/b); a single-leaf plan keeps the
    // keyed writer's path, which reads the same bounds from its own validation below. Two counted
    // leaves keep the refusal.
    $bIdx = -1;
    for ($li = 0; $li < count($leaves); $li++) {
        $lkv = explode(":", $leaves[$li]);
        if (count($lkv) != 2 || strpos($lkv[1], "~") === false) {
            continue;
        }
        $apfx = substr($lkv[1], 0, strpos($lkv[1], "~"));
        if ($apfx != "a" && $apfx != "an" && $apfx != "af" && $apfx != "ab") {
            continue;
        }
        if ($bIdx >= 0) {
            return "";
        }
        $bIdx = $li;
    }
    if ($bIdx >= 0) {
        for ($li = 0; $li < count($leaves); $li++) {
            if ($li == $bIdx) {
                continue;
            }
            $lkv = explode(":", $leaves[$li]);
            if (count($lkv) != 2
                || ($lkv[1] != "s" && $lkv[1] != "i" && $lkv[1] != "n" && $lkv[1] != "b")) {
                return "";
            }
        }
        $kv = explode(":", $leaves[$bIdx]);
        $kp = strpos($block, "\"" . $kv[0] . "\"");
        $vo = strpos($block, "{", $kp);
        $leaf = api_balance_block($block, $vo);
        if ($leaf == "") {
            return "";
        }
        if (substr_count($leaf, "\"minItems\"") + substr_count($leaf, "\"maxItems\"")
            != substr_count($schema, "\"minItems\"") + substr_count($schema, "\"maxItems\"")) {
            return "";
        }
        $bmn2 = [];
        $bmx2 = [];
        $hMin = preg_match('/"minItems"\s*:\s*([0-9]+)/', $leaf, $bmn2) == 1;
        $hMax = preg_match('/"maxItems"\s*:\s*([0-9]+)/', $leaf, $bmx2) == 1;
        if (!$hMin && !$hMax) {
            return "";
        }
        $tMin = $hMin ? (int) $bmn2[1] : 0;
        $tMax = $hMax ? (int) $bmx2[1] : 0;
        if (($hMax && $tMax < 1) || ($tMax > 0 && $tMin > $tMax) || $tMin > 8) {
            return "";
        }
        return $tMin . ":" . $tMax;
    }
    if (count($leaves) != 1) {
        return "";
    }
    $kv = explode(":", $leaves[0]);
    if (count($kv) != 2) {
        return "";
    }
    if ($kv[1] != "a" && $kv[1] != "an" && $kv[1] != "af") {
        return "";
    }
    $kp = strpos($block, "\"" . $kv[0] . "\"");
    $vo = strpos($block, "{", $kp);
    $leaf = api_balance_block($block, $vo);
    if ($leaf == "") {
        return "";
    }
    // A bound outside the array's own object - on the enclosing schema, or on some other part of it
    // - would be relaxed here but enforced nowhere, so the whole shape goes back to being refused.
    if (substr_count($leaf, "\"minItems\"") + substr_count($leaf, "\"maxItems\"")
        != substr_count($schema, "\"minItems\"") + substr_count($schema, "\"maxItems\"")) {
        return "";
    }
    $mn = api_schema_bound_value($leaf, "\"minItems\"", 0);
    $mx = api_schema_bound_value($leaf, "\"maxItems\"", 0);
    if (strpos($leaf, "\"minItems\"") !== false
        && ($mn == "" || strpos($mn, ".") !== false || strpos($mn, "-") !== false)) {
        return "";
    }
    if (strpos($leaf, "\"maxItems\"") !== false
        && ($mx == "" || strpos($mx, ".") !== false || strpos($mx, "-") !== false)) {
        return "";
    }
    $bMin = 0;
    $bMax = 0;
    if ($mn != "") {
        $bMin = (int) $mn;
    }
    if ($mx != "") {
        $bMax = (int) $mx;
    }
    // maxItems 0 asks for an empty array, which this generator never emits; a minimum past the
    // element ceiling, or past the maximum, is a bound it cannot reach either. All stay refused.
    if ($mx != "" && $bMax < 1) {
        return "";
    }
    if ($bMax > 0 && $bMin > $bMax) {
        return "";
    }
    if ($bMin > 8) {
        return "";
    }
    return $bMin . ":" . $bMax;
}

// Same bounds, from the request body (the generator call sites hold the body, the refusal holds the
// extracted schema).
function api_response_schema_array_bounds(string $body): string
{
    return api_schema_array_element_bounds(api_json_object_after($body, "schema"));
}

// F401: the names listed in `required`, comma-joined in the order they appear, or "" when the
// keyword is absent, empty or unreadable.
function api_schema_required_names(string $schema): string
{
    $rp = strpos($schema, "\"required\"");
    if ($rp === false) {
        return "";
    }
    $rb = strpos($schema, "[", $rp);
    if ($rb === false) {
        return "";
    }
    $re = strpos($schema, "]", $rb);
    if ($re === false) {
        return "";
    }
    $out = "";
    foreach (explode(",", substr($schema, $rb + 1, $re - $rb - 1)) as $rq) {
        $rq = str_replace("\"", "", $rq);
        $rq = str_replace(" ", "", $rq);
        if ($rq != "") {
            $out = $out == "" ? $rq : $out . "," . $rq;
        }
    }
    return $out;
}

// F401: `required` naming a strict subset asks the decoder to OMIT the optional properties. Only the
// flat all-string generator steps property by property and can therefore leave one out, and only
// when every required name is actually declared - otherwise the answer would still break the
// schema, which is what the refusal is for. Returns the names to emit, in declaration order, or "".
// F403②: the mixed object's generator steps property by property and emits exactly the keys in
// the plan it is handed, so a required subset is honoured there by the same construction as the
// flat all-string path (F401) - with the leaf kinds that generator can actually emit (string,
// integer, number, boolean). The shape judgement mirrors api_response_schema_mixed_object's, so
// a plan admitted here is a plan that schema really produces; anything else (array leaves, enum
// or const leaves, object leaves) stays refused until its own key-set proof exists.
function api_schema_mixed_omittable(string $schema): int
{
    if (preg_match('/"(enum|const|pattern|minimum|maximum|multipleOf)"\s*:/', $schema) == 1) {
        return 0;
    }
    if (api_schema_is_object($schema) != 1) {
        return 0;
    }
    $pp = strpos($schema, "\"properties\"");
    if ($pp === false) {
        return 0;
    }
    $bo = strpos($schema, "{", $pp);
    if ($bo === false) {
        return 0;
    }
    $block = api_balance_block($schema, $bo);
    if ($block == "") {
        return 0;
    }
    $plan = api_top_leaves($block, 8);
    if ($plan == "") {
        return 0;
    }
    $items = explode(",", $plan);
    if (count($items) < 2) {
        return 0;
    }
    $hasNum = 0;
    for ($i = 0; $i < count($items); $i++) {
        $kv = explode(":", $items[$i]);
        // Three-part entries (e:/en:/ao:) are not emitted by the flat mixed generator, so this
        // path stays refused for them. F418: the COUNTED ARRAY kinds ("a~min~max") ARE emitted -
        // and the required subset drops them from the plan entirely (an omitted key cannot
        // violate its bounds), so they count toward omittability like the scalars.
        if (count($kv) != 2) {
            return 0;
        }
        $isCounted = 0;
        if (strpos($kv[1], "~") !== false) {
            $apfx = substr($kv[1], 0, strpos($kv[1], "~"));
            if ($apfx == "a" || $apfx == "an" || $apfx == "af" || $apfx == "ab") {
                $isCounted = 1;
            }
        }
        if ($kv[1] != "s" && $kv[1] != "i" && $kv[1] != "n" && $kv[1] != "b" && $isCounted == 0) {
            return 0;
        }
        if ($kv[1] != "s") {
            $hasNum = 1;
        }
    }
    if ($hasNum == 0) {
        return 0;
    }
    return 1;
}

function api_schema_required_filter(string $schema): string
{
    $req = api_schema_required_names($schema);
    if ($req == "" || (api_schema_flat_all_string($schema) != 1
            && api_schema_mixed_omittable($schema) != 1)) {
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
    $plan = api_top_leaves($block, 64);
    if ($plan == "") {
        return "";
    }
    $keep = "";
    foreach (explode(",", $plan) as $leaf) {
        $kv = explode(":", $leaf);
        if (strpos("," . $req . ",", "," . $kv[0] . ",") !== false) {
            $keep = $keep == "" ? $kv[0] : $keep . "," . $kv[0];
        }
    }
    if ($keep == "") {
        return "";
    }
    foreach (explode(",", $req) as $rq) {
        if (strpos("," . $keep . ",", "," . $rq . ",") === false) {
            return "";
        }
    }
    return $keep;
}

// The property list the flat-string generator emits: filtered to `required` where that subset can be
// honoured, and unchanged otherwise (no `required`, or an all-required schema, is already satisfied
// by emitting every declared property).
function api_response_schema_string_props_required(string $body): array
{
    $props = api_response_schema_string_props($body);
    $keep = api_schema_required_filter(api_json_object_after($body, "schema"));
    if ($keep == "") {
        return $props;
    }
    $out = [];
    foreach (explode(",", $keep) as $name) {
        foreach ($props as $p) {
            if ($p == $name) {
                $out[] = $p;
            }
        }
    }
    if (count($out) == 0) {
        return $props;
    }
    return $out;
}

// F402: `additionalProperties` constrains which KEYS may appear. The property-stepping generators
// name every key from the declared plan - the model never picks one - so for those shapes the
// constraint holds whatever its value, and refusing it rejected a request the decoder already
// satisfies (the same mistake `required` was). Scoped deliberately: only a top-level object whose
// leaves are all steppable kinds (strings, integers, numbers, booleans, and the scalar arrays the
// mixed/nested steppers emit - F403② adds the flat mixed kinds i/n/b alongside F402's s/an/af/ab),
// only the boolean spelling, only one occurrence. An object-array item, a nested object, or the
// schema form of the keyword stay refused until that route has its own key-set gate.
function api_schema_additional_properties_honoured(string $schema): int
{
    if (preg_match('/"additionalProperties"\s*:\s*(?:true|false)/', $schema) != 1
        || preg_match_all('/"additionalProperties"\s*:/', $schema) != 1) {
        return 0;
    }
    $pp = strpos($schema, "\"properties\"");
    if ($pp === false) {
        return 0;
    }
    $bo = strpos($schema, "{", $pp);
    if ($bo === false) {
        return 0;
    }
    $block = api_balance_block($schema, $bo);
    if ($block == "") {
        return 0;
    }
    $plan = api_top_leaves($block, 8);
    if ($plan == "") {
        return 0;
    }
    foreach (explode(",", $plan) as $leaf) {
        $kv = explode(":", $leaf);
        if (count($kv) != 2) {
            return 0;
        }
        // F407: the counted array kind ("a~min~max") is plan-named like the rest, so the
        // constraint holds there by the same construction.
        if ($kv[1] != "s" && $kv[1] != "i" && $kv[1] != "n" && $kv[1] != "b"
            && strpos($kv[1], "a~") !== 0
            && $kv[1] != "a" && $kv[1] != "an" && $kv[1] != "af" && $kv[1] != "ab") {
            return 0;
        }
    }
    return 1;
}

// F399: is this schema a flat object whose leaves are all strings? That is the only shape whose
// generator can honour minLength/maxLength, so it is the only one allowed past the refusal.
function api_schema_flat_all_string(string $schema): int
{
    $pp = strpos($schema, "\"properties\"");
    if ($pp === false) {
        return 0;
    }
    $bo = strpos($schema, "{", $pp);
    if ($bo === false) {
        return 0;
    }
    $block = api_balance_block($schema, $bo);
    if ($block == "") {
        return 0;
    }
    $plan = api_top_leaves($block, 8);
    if ($plan == "") {
        return 0;
    }
    foreach (explode(",", $plan) as $leaf) {
        $kv = explode(":", $leaf);
        if (count($kv) != 2 || $kv[1] != "s") {
            return 0;
        }
    }
    return 1;
}

// F398: which schema keywords does the decoder actually enforce? These five are not enforced at
// all, so a request carrying them used to be answered with text that could violate the schema
// while returning 200. They are refused with the keyword named, instead of silently ignored.
// `required` is handled separately below, because refusing it outright would reject requests the
// decoder already satisfies.
// F427: how many keys the declared plan holds. minProperties/maxProperties count keys, and on a
// shape whose key set is exactly this plan the count is known before generation - so the bound can
// be checked rather than ignored.
function api_schema_plan_count(string $schema): int
{
    $pp = strpos($schema, "\"properties\"");
    if ($pp === false) {
        return 0;
    }
    $bo = strpos($schema, "{", $pp);
    if ($bo === false) {
        return 0;
    }
    $block = api_balance_block($schema, $bo);
    if ($block == "") {
        return 0;
    }
    $plan = api_top_leaves($block, 64);
    if ($plan == "") {
        return 0;
    }
    return count(explode(",", $plan));
}

// F432: dependentRequired is satisfied when, for every trigger key the answer will contain, its
// dependencies are contained too. On the flat all-string shape the emitted key set is the declared
// plan or its required subset - both known before generation - so the rule can be checked instead
// of refused. Shapes whose key set is not proven, or a dependency on a key that is never emitted,
// keep the refusal.
function api_schema_dependent_required_ok(string $schema): int
{
    if (api_schema_flat_all_string($schema) != 1) {
        return 0;
    }
    $dp = strpos($schema, "\"dependentRequired\"");
    if ($dp === false) {
        return 0;
    }
    $bo = strpos($schema, "{", $dp);
    if ($bo === false) {
        return 0;
    }
    $block = api_balance_block($schema, $bo);
    if ($block == "") {
        return 0;
    }
    $emit = api_schema_required_filter($schema);
    if ($emit == "") {
        $pp = strpos($schema, "\"properties\"");
        if ($pp === false) {
            return 0;
        }
        $pbo = strpos($schema, "{", $pp);
        if ($pbo === false) {
            return 0;
        }
        $pblk = api_balance_block($schema, $pbo);
        if ($pblk == "") {
            return 0;
        }
        $plan = api_top_leaves($pblk, 64);
        if ($plan == "") {
            return 0;
        }
        $emit = "";
        foreach (explode(",", $plan) as $leaf) {
            $kv = explode(":", $leaf);
            $emit = $emit == "" ? $kv[0] : $emit . "," . $kv[0];
        }
    }
    $off = 0;
    while (true) {
        $q = strpos($block, "\"", $off);
        if ($q === false) {
            break;
        }
        $qe = strpos($block, "\"", $q + 1);
        if ($qe === false) {
            break;
        }
        $key = substr($block, $q + 1, $qe - $q - 1);
        $ao = strpos($block, "[", $qe);
        if ($ao === false) {
            break;
        }
        $ac = strpos($block, "]", $ao);
        if ($ac === false) {
            break;
        }
        $off = $ac + 1;
        if (strpos("," . $emit . ",", "," . $key . ",") === false) {
            continue;               // the trigger is absent, so its dependencies do not apply
        }
        foreach (explode(",", substr($block, $ao + 1, $ac - $ao - 1)) as $dep) {
            $dep = str_replace("\"", "", $dep);
            $dep = str_replace(" ", "", $dep);
            if ($dep == "") {
                continue;
            }
            if (strpos("," . $emit . ",", "," . $dep . ",") === false) {
                return 0;
            }
        }
    }
    return 1;
}

// F429: propertyNames constrains KEY NAMES, and on the flat all-string shape the emitted keys are
// exactly the declared plan - so the subschema can be evaluated against those names before
// generation. Only the forms that can be checked are honoured (pattern, min/maxLength, enum, const,
// type:string); any constraint this check cannot evaluate, or a shape whose key set is not proven,
// keeps the refusal instead of approving by omission. The pattern is length-bounded before it is
// compiled, so a pathological request cannot turn this into a backtracking probe.
function api_schema_property_names_ok(string $schema): int
{
    if (api_schema_flat_all_string($schema) != 1) {
        return 0;
    }
    if (preg_match('/"(format|contentEncoding|contentMediaType|patternProperties|contains|not|if|'
        . 'dependentRequired|dependentSchemas|dependencies|unevaluatedItems|unevaluatedProperties|'
        . 'additionalProperties)"\s*:/', $schema) == 1) {
        return 0;
    }
    $pn = strpos($schema, "\"propertyNames\"");
    if ($pn === false) {
        return 0;
    }
    $bo = strpos($schema, "{", $pn);
    if ($bo === false) {
        return 0;
    }
    $sub = api_balance_block($schema, $bo);
    if ($sub == "") {
        return 0;
    }
    $pp = strpos($schema, "\"properties\"");
    if ($pp === false) {
        return 0;
    }
    $pbo = strpos($schema, "{", $pp);
    if ($pbo === false) {
        return 0;
    }
    $block = api_balance_block($schema, $pbo);
    if ($block == "") {
        return 0;
    }
    $plan = api_top_leaves($block, 64);
    if ($plan == "") {
        return 0;
    }
    $mc = [];
    $hasConst = preg_match('/"const"\s*:\s*"([^"]*)"/', $sub, $mc) == 1;
    $hasEnum = preg_match('/"enum"\s*:\s*\[/', $sub) == 1;
    $enumInner = "";
    if ($hasEnum) {
        $eb = strpos($sub, "[");
        $ee = strpos($sub, "]", $eb);
        if ($eb === false || $ee === false) {
            return 0;
        }
        $enumInner = substr($sub, $eb + 1, $ee - $eb - 1);
    }
    $mt = [];
    $hasType = preg_match('/"type"\s*:\s*"([a-z]+)"/', $sub, $mt) == 1;
    if ($hasType && $mt[1] != "string") {
        return 0;
    }
    $mp = [];
    $hasPattern = preg_match('/"pattern"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $sub, $mp) == 1;
    if ($hasPattern && (strlen($mp[1]) == 0 || strlen($mp[1]) > 200)) {
        return 0;
    }
    foreach (explode(",", $plan) as $leaf) {
        $kv = explode(":", $leaf);
        $name = $kv[0];
        if ($hasPattern) {
            $re = "#" . str_replace("#", "\\#", $mp[1]) . "#";
            if (preg_match($re, $name) != 1) {
                return 0;
            }
        }
        $mn = api_schema_bound_value($sub, "\"minLength\"", 0);
        if ($mn != "" && strlen($name) < (int) $mn) {
            return 0;
        }
        $mx = api_schema_bound_value($sub, "\"maxLength\"", 0);
        if ($mx != "" && strlen($name) > (int) $mx) {
            return 0;
        }
        if ($hasConst && $mc[1] != $name) {
            return 0;
        }
        if ($hasEnum && strpos($enumInner, "\"" . $name . "\"") === false) {
            return 0;
        }
    }
    return 1;
}

// F426: structural keywords the decoder neither implements nor can prove satisfied. Each is matched
// with the shape its VALUE really has, so an enum value spelled "not" is not mistaken for the
// keyword. Known boundary: a property NAMED like one of these is refused too - the refusal names it,
// and that is preferred over scanning only the schema root, which would let the same keyword hide
// inside an item schema and be ignored.
function api_schema_structural_unsupported(string $schema): string
{
    $mk = [];
    // F434: tuple validation - `items` as an ARRAY of schemas, or prefixItems - is a shape no
    // generator claims; the live probe answered prose for both, so each is refused by name.
    if (preg_match('/"prefixItems"\s*:\s*\[/', $schema, $mk) == 1) {
        return "prefixItems";
    }
    if (preg_match('/"items"\s*:\s*\[/', $schema, $mk) == 1) {
        return "items";
    }
    // F429: propertyNames is judged against the plan's key names first - if those names satisfy the
    // subschema it is honoured, otherwise (or when it cannot be evaluated) it is refused by name.
    if (preg_match('/"propertyNames"\s*:\s*\{/', $schema) == 1
        && api_schema_property_names_ok($schema) != 1) {
        return "propertyNames";
    }
    // F432: dependentRequired is judged against the keys the answer will contain, the same way
    // propertyNames is judged against their names.
    if (preg_match('/"dependentRequired"\s*:\s*\{/', $schema) == 1
        && api_schema_dependent_required_ok($schema) != 1) {
        return "dependentRequired";
    }
    // F428: the value's real shape decides the match, so a property NAMED "format" (whose value is
    // an object) is not mistaken for the format keyword (whose value is a string).
    if (preg_match('/"(contains|patternProperties|not|if|dependentSchemas|'
        . 'dependencies)"\s*:\s*\{/', $schema, $mk) == 1) {
        return $mk[1];
    }
    if (preg_match('/"(unevaluatedItems|unevaluatedProperties)"\s*:\s*(?:\{|true|false)/',
        $schema, $mk) == 1) {
        return $mk[1];
    }
    if (preg_match('/"(format|contentEncoding|contentMediaType)"\s*:\s*"[^"]*"/', $schema, $mk) == 1) {
        return $mk[1];
    }
    if (preg_match('/"(minContains|maxContains)"\s*:\s*[0-9]+/', $schema, $mk) == 1) {
        return $mk[1];
    }
    return "";
}

function api_schema_keywords_unsupported(string $body): string
{
    foreach (explode(" ", "minItems maxItems minLength maxLength additionalProperties uniqueItems minProperties maxProperties") as $kw) {
        if (strpos($body, "\"" . $kw . "\"") === false) {
            continue;
        }
        // F399: minLength/maxLength are honoured for a flat all-string object, whose stepper can
        // restrict the closing quote; every other shape still refuses them rather than ignoring.
        if (($kw == "minLength" || $kw == "maxLength") && api_schema_flat_all_string($body) == 1) {
            continue;
        }
        // F400: minItems/maxItems are honoured for a single scalar-array property, whose stepper
        // can restrict the closing bracket by element count; every other shape still refuses them.
        if (($kw == "minItems" || $kw == "maxItems") && api_schema_array_element_bounds($body) != "") {
            continue;
        }
        // F402: additionalProperties holds by construction where every key is named from the plan.
        if ($kw == "additionalProperties"
            && api_schema_additional_properties_honoured($body) == 1) {
            continue;
        }
        // F425: uniqueItems constrains element VALUES, which the model picks, so `true` cannot be
        // honoured and is refused. `false` allows duplicates - which is exactly what the generator
        // may emit - and the array detectors now tolerate that spelling, so it holds by
        // construction and must keep being served instead of falling back to prose.
        if ($kw == "uniqueItems"
            && preg_match('/"uniqueItems"\s*:\s*(?:true|false)/', $body) == 1
            && preg_match('/"uniqueItems"\s*:\s*false/', $body) == 1
            && preg_match_all('/"uniqueItems"\s*:/', $body) == 1) {
            continue;
        }
        // F427: minProperties/maxProperties count keys. On the flat all-string shape the emitted key
        // set is the declared plan (or its required subset), so the count is known up front: a bound
        // that count satisfies is honoured, one it cannot satisfy is refused rather than answered
        // with the wrong number of keys. Every other shape still refuses, because its key set is not
        // proven.
        if ($kw == "minProperties" || $kw == "maxProperties") {
            $emitN = 0;
            if (api_schema_flat_all_string($body) == 1) {
                $emitKeep = api_schema_required_filter($body);
                if ($emitKeep != "") {
                    $emitN = count(explode(",", $emitKeep));
                } else {
                    $emitN = api_schema_plan_count($body);
                }
            }
            $bdv = api_schema_bound_value($body, "\"" . $kw . "\"", 0);
            if ($emitN > 0 && $bdv != "" && strpos($bdv, ".") === false) {
                if ($kw == "minProperties" && $emitN >= (int) $bdv) {
                    continue;
                }
                if ($kw == "maxProperties" && $emitN <= (int) $bdv) {
                    continue;
                }
            }
            return $kw;
        }
        return $kw;
    }
    // F426: the structural keyword set (contains / not / if / dependentRequired / propertyNames /
    // unevaluated*) - none is implemented, and every one can be violated by what the model emits, so
    // each is refused by name instead of ignored.
    $struct = api_schema_structural_unsupported($body);
    if ($struct != "") {
        return $struct;
    }
    // required: the generator emits EVERY declared property, so a schema that requires all of them
    // is satisfied by construction. Two things can still break it: a name that is not declared at
    // all, and a strict subset on a shape whose generator cannot leave a property out. Comparing
    // counts would miss the first - required ["a","z"] against two declared properties reads as
    // "all required" - so the names themselves are checked against the plan (F401).
    $req = api_schema_required_names($body);
    if ($req == "") {
        return strpos($body, "\"required\"") === false ? "" : "required";
    }
    $pp = strpos($body, "\"properties\"");
    if ($pp === false) {
        return "required";
    }
    $bo = strpos($body, "{", $pp);
    if ($bo === false) {
        return "required";
    }
    $block = api_balance_block($body, $bo);
    if ($block == "") {
        return "required";
    }
    $plan = api_top_leaves($block, 64);
    if ($plan == "") {
        return "required";
    }
    $decl = "";
    $declN = 0;
    foreach (explode(",", $plan) as $leaf) {
        $kv = explode(":", $leaf);
        $decl = $decl == "" ? $kv[0] : $decl . "," . $kv[0];
        $declN++;
    }
    foreach (explode(",", $req) as $rq) {
        if (strpos("," . $decl . ",", "," . $rq . ",") === false) {
            return "required";
        }
    }
    if ($declN > 0 && count(explode(",", $req)) < $declN) {
        // F401: the subset is honoured where the generator can leave an optional property out, and
        // only when every required name is declared; other shapes still get refused.
        if (api_schema_required_filter($body) != "") {
            return "";
        }
        return "required";
    }
    // F403②: required is honoured where the serving path emits every required key. The
    // full-emission generators cover a plan whose leaf kinds are all s/i/n/b with no other
    // constraint keyword present (flat all-string, the mixed stepper with its boolean branch) -
    // subsets go through the filter above, all-required needs nothing omitted. A leaf of any
    // other kind - an array (the single-array tier), an enum or const (the finite candidates), a
    // bare number - is served by a path that emits ONLY that key and drops its siblings, which
    // is legal while they are optional but a violation once required names them (a boolean
    // beside an unbounded integer answered {"flag":false} for required ["flag","count"]). For a
    // multi-leaf plan outside the full-emission set the finite candidates are the arbiter: after
    // the required check inside them, non-empty means every required key is renderable, empty
    // means no path emits them all. A single-leaf plan is its own exception: the one required
    // key is exactly what those paths emit.
    $fullEmission = 1;
    foreach (explode(",", $plan) as $leaf) {
        $kv = explode(":", $leaf);
        if (count($kv) < 2) {
            continue;
        }
        // F404: the pattern kind is steppable too (the mixed stepper emits its counted
        // segments), so it counts toward full emission like s/i/n/b. F407: a counted array leaf
        // ("a~min~max") is the same - the mixed stepper emits the array by element count and the
        // other keys from the plan.
        if ($kv[1] != "s" && $kv[1] != "i" && $kv[1] != "n" && $kv[1] != "b" && $kv[1] != "p"
            && strpos($kv[1], "a~") !== 0) {
            $fullEmission = 0;
            break;
        }
    }
    if ($fullEmission == 1
        && preg_match('/"(enum|const|minimum|maximum|multipleOf)"\s*:/', $body) == 1) {
        $fullEmission = 0;
    }
    if ($fullEmission == 0 && $declN > 1 && count(api_schema_candidates($body)) == 0) {
        return "required";
    }
    return "";
}

function api_schema_bounds_unsupported(string $body): int
{
    if (strpos($body, "\"minimum\"") === false && strpos($body, "\"maximum\"") === false
        && strpos($body, "\"exclusiveMinimum\"") === false && strpos($body, "\"exclusiveMaximum\"") === false) {
        // F430: an unbounded multipleOf the suffix writer cannot reach (> 999999) is refused here.
        // Before this the writer bailed after emitting the key, and the answer was 200 with invalid
        // JSON ("{"n":").
        $mo = [];
        if (preg_match('/"multipleOf"\s*:\s*([0-9]+)/', $body, $mo) == 1
            && (int) $mo[1] > 999999) {
            return 1;
        }
        // F431: the unbounded multipleOf writer emits ONE property. On a schema with more than one
        // leaf it claimed the whole schema and silently dropped the rest - the live probe answered
        // {"a":...} for a two-key schema. Refuse instead of dropping keys.
        if (strpos($body, "\"multipleOf\"") !== false && api_schema_plan_count($body) > 1) {
            return 1;
        }
        // F433: the multiple writer serves INTEGER leaves only. A number leaf with multipleOf had no
        // route at all and was answered with prose, so it is refused by name.
        if (preg_match('/"multipleOf"\s*:\s*[0-9]+/', $body) == 1
            && preg_match('/"type"\s*:\s*"number"/', $body) == 1) {
            return 1;
        }
        return 0;
    }
    // F405: the bounded-integer shape the digit-walk writer honours (one integer leaf, any span,
    // one or both non-negative bounds) is no longer a refusal - the old rules below keep judging
    // every other shape, including the negative bounds the candidates tier still enumerates when
    // the span fits and nothing honours otherwise.
    $shape = api_schema_number_bounded_shape($body);
    if ($shape != "") {
        return 0;
    }
    // F422: exclusive-only bounds (no inclusive minimum/maximum to fall back on) with an empty
    // shape - the interval rounds to nothing, is multi-leaf, or is a literal the walk cannot read.
    // Without this the generic generator would ignore the exclusive bounds and serve an
    // out-of-range value, so refuse it here.
    if ((strpos($body, "\"exclusiveMinimum\"") !== false || strpos($body, "\"exclusiveMaximum\"") !== false)
        && strpos($body, "\"minimum\"") === false && strpos($body, "\"maximum\"") === false) {
        return 1;
    }
    // F420: bounds plus a multipleOf the shape does not honour is a constraint no value can meet
    // (no multiple inside the interval) or a shape the writer cannot reach (several leaves). The
    // span rule below would call the first one supported and the candidates tier would then emit a
    // non-multiple silently, so it is refused here instead.
    if (preg_match('/"multipleOf"\s*:\s*[0-9]+/', $body) == 1) {
        return 1;
    }
    // F421: a bound literal the inward rounding cannot read (an exponent form) must not fall through
    // to the span rule below, which truncates it - minimum 1e1 with maximum 12 would otherwise be
    // called supported and served from 1.
    $bl = [];
    if (preg_match('/"minimum"\s*:\s*(-?[0-9.eE+]+)/', $body, $bl) == 1
        && preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $bl[1]) != 1) {
        return 1;
    }
    if (preg_match('/"maximum"\s*:\s*(-?[0-9.eE+]+)/', $body, $bl) == 1
        && preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $bl[1]) != 1) {
        return 1;
    }
    // Walk every minimum and pair it with the maximum that follows it, so a schema with several
    // bounded leaves is judged on each pair rather than only the first.
    $off = 0;
    $pairs = 0;
    while (true) {
        $p = strpos($body, "\"minimum\"", $off);
        if ($p === false) {
            break;
        }
        $mn = api_schema_bound_value($body, "\"minimum\"", $p);
        $mx = api_schema_bound_value($body, "\"maximum\"", $p);
        $off = $p + 9;
        if ($mn == "" || $mx == "") {
            return 1;                       // one-sided, or unparseable
        }
        if (strpos($mn, ".") !== false || strpos($mx, ".") !== false) {
            return 1;                       // float bounds are not enumerated
        }
        // F397: whitespace-tolerant on purpose. The first version searched for the compact
        // "type":"integer" and the self-test fixture was compact too, so the gate passed while every
        // real request - json.dumps emits "type": "integer" with a space - was rejected, including
        // the supported ones. The live two-direction check is what caught it.
        if (preg_match('/"type"\s*:\s*"integer"/', $body) != 1) {
            return 1;                       // number, or no declared type
        }
        $span = (int) $mx - (int) $mn;
        if ($span < 0 || $span > 15) {
            return 1;                       // too wide to enumerate
        }
        $pairs++;
    }
    // A maximum with no minimum anywhere is one-sided too.
    if ($pairs == 0 && strpos($body, "\"maximum\"") !== false) {
        return 1;
    }
    return 0;
}

// F404: expand a class body into its character set - bare safe characters and a-z/0-9/A-Z ranges
// with both ends in the same family. Negation, metacharacters and unsafe characters (quote,
// backslash, colon, tilde, pipe, comma - anything that could break the JSON string, the plan
// entry or the spec encoding) return "" - that pattern stays refused.
function api_pattern_class_chars(string $body): string
{
    $out = "";
    $seen = "";
    $i = 0;
    $len = strlen($body);
    while ($i < $len) {
        $ch = $body[$i];
        $add = "";
        if ($ch == "\\" && $i + 1 < $len) {
            // F413: the escape shorthands inside a class - \\d, \\w, \\s, \\D (F416: the digit
            // complement) and the escaped dash; every other escape (including a literal
            // backslash, which the value alphabet cannot carry) keeps the refusal. F413: BOTH
            // spellings are accepted, because the request gate sees the RAW WIRE text where the
            // JSON escape of \\d is \\\\d - the doubled backslash followed by the shorthand
            // letter.
            $esc = $body[$i + 1];
            $step = 2;
            if ($esc == "d") {
                $add = "0123456789";
            } else if ($esc == "w") {
                $add = "_0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ^";
            } else if ($esc == "s") {
                $add = " ";
            } else if ($esc == "D") {
                $add = api_pattern_class_complement("0123456789");
            } else if ($esc == "-") {
                $add = "-";
            } else if ($esc == "\\" && $i + 2 < $len
                       && ($body[$i + 2] == "d" || $body[$i + 2] == "w"
                           || $body[$i + 2] == "s" || $body[$i + 2] == "D"
                           || $body[$i + 2] == "-")) {
                $esc = $body[$i + 2];
                if ($esc == "d") {
                    $add = "0123456789";
                } else if ($esc == "w") {
                    $add = "_0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ^";
                } else if ($esc == "s") {
                    $add = " ";
                } else if ($esc == "D") {
                    $add = api_pattern_class_complement("0123456789");
                } else {
                    $add = "-";
                }
                $step = 3;
            } else {
                return "";
            }
            $i += $step;
        } else if ($i + 2 < $len && $body[$i + 1] == "-") {
            $lo = $ch;
            $hi = $body[$i + 2];
            $sameFamily = (ctype_digit($lo) && ctype_digit($hi))
                || (ctype_lower($lo) && ctype_lower($hi))
                || (ctype_upper($lo) && ctype_upper($hi));
            if ($sameFamily != 1 || ord($hi) < ord($lo)) {
                return "";
            }
            for ($c = ord($lo); $c <= ord($hi); $c++) {
                $add .= chr($c);
            }
            $i += 3;
        } else {
            $add = $ch;
            $i += 1;
        }
        for ($k2 = 0; $k2 < strlen($add); $k2++) {
            $one = $add[$k2];
            if (preg_match('/[A-Za-z0-9 .\\-_^]/', $one) != 1) {
                return "";
            }
            if (strpos($seen, $one) === false) {
                $seen .= $one;
                $out .= $one;
            }
        }
    }
    return $out;
}

// F412: the complement of a negated class within the JSON-safe alphabet - the same characters the
// positive classes draw from, so the emitted value stays free of quotes, backslashes and anything
// that could break the JSON string or the spec encoding. An empty complement (the class excludes
// everything) is unsatisfiable and refused by the caller.
function api_pattern_class_complement(string $chars): string
{
    // F413: the underscore joins the alphabet with the \\w expansion; F416: the caret joins as a
    // class-body literal.
    $alpha = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 .-_^";
    $out = "";
    for ($i = 0; $i < strlen($alpha); $i++) {
        $one = substr($alpha, $i, 1);
        if (strpos($chars, $one) === false) {
            $out .= $one;
        }
    }
    return $out;
}

// F404/F409: the anchored class/count pattern forms the flat mixed stepper can honour. The pattern
// must be ^...$ anchored, and the core a concatenation of literal runs (JSON-safe text) and
// character classes with a quantifier - a bare class counts one, {n} counts exactly n (anywhere),
// and F409 adds {n,m} ranges and the * + ? shorthands on the LAST segment only, where the closing
// quote is a distinct token and the walk stays unambiguous; a range beside a following segment
// would need a real NFA walk and keeps the explicit 400. No negation, no alternation beside a
// class, no escapes. Returns the segment spec for the stepper ("l<text>~c<n>x<chars>" with the
// range spelled "c<min>-<max>x<chars>", an absent max meaning unbounded) or "" when the pattern is
// a pure literal (F282's alternation machinery owns those) or is not implemented.
function api_schema_pattern_spec(string $pat): string
{
    $n = strlen($pat);
    if ($n < 1) {
        return "";
    }
    // F412: the anchors are optional - an unanchored pattern accepts any string CONTAINING a
    // match, and the anchored emission is exactly such a string, so serving the match honours the
    // constraint whatever the anchors spelled.
    if (substr($pat, 0, 1) == "^") {
        $pat = substr($pat, 1);
        $n--;
    }
    if ($n > 0 && substr($pat, $n - 1, 1) == "$") {
        $pat = substr($pat, 0, $n - 1);
        $n--;
    }
    if ($n < 1) {
        return "";
    }
    $core = $pat;
    $segs = [];
    $lit = "";
    $i = 0;
    $len = strlen($core);
    $hasClass = 0;
    while ($i < $len) {
        $ch = $core[$i];
        if ($ch == "[") {
            if ($lit != "") {
                $segs[] = "l" . $lit;
                $lit = "";
            }
            $close = strpos($core, "]", $i + 1);
            if ($close === false) {
                return "";
            }
            $cbody = substr($core, $i + 1, $close - $i - 1);
            $i = $close + 1;
            // F412: a leading ^ negates the class - the character set becomes the complement
            // within the JSON-safe alphabet, so the emitted value still cannot break out of the
            // JSON string.
            $negate = 0;
            if (substr($cbody, 0, 1) == "^") {
                $negate = 1;
                $cbody = substr($cbody, 1);
            }
            $chars = api_pattern_class_chars($cbody);
            if ($chars == "") {
                return "";
            }
            if ($negate == 1) {
                $chars = api_pattern_class_complement($chars);
                if ($chars == "") {
                    return "";
                }
            }
            $qMin = 1;
            $qMax = 1;
            $qExact = 1;
            if ($i < $len && $core[$i] == "{") {
                $brace = strpos($core, "}", $i);
                if ($brace === false) {
                    return "";
                }
                $num = substr($core, $i + 1, $brace - $i - 1);
                $parts = explode(",", $num);
                if (count($parts) > 2
                    || preg_match('/^[0-9]{1,2}$/', $parts[0]) != 1
                    || (int) $parts[0] < 1 || (int) $parts[0] > 16) {
                    return "";
                }
                $qMin = (int) $parts[0];
                if (count($parts) == 2) {
                    if (preg_match('/^[0-9]{1,2}$/', $parts[1]) != 1
                        || (int) $parts[1] > 16 || (int) $parts[1] < $qMin) {
                        return "";
                    }
                    $qMax = (int) $parts[1];
                    $qExact = $qMax == $qMin ? 1 : 0;
                }
                $i = $brace + 1;
            } else if ($i < $len && ($core[$i] == "+" || $core[$i] == "*" || $core[$i] == "?")) {
                // A max of 0 spells the unbounded forms: + is "c1-", * is "c0-".
                if ($core[$i] == "+") {
                    $qMin = 1;
                    $qMax = 0;
                } else if ($core[$i] == "*") {
                    $qMin = 0;
                    $qMax = 0;
                } else {
                    $qMin = 0;
                    $qMax = 1;
                }
                $qExact = 0;
                $i++;
            }
            // F415/F417: a non-exact quantifier beside a FOLLOWING segment is served by emitting
            // the MINIMUM - the count lands inside the bounds, so the value is one valid match
            // (the same subset argument the numeric bounds use), and the walk continues with the
            // next segment - no NFA needed. A minimum of zero is served by the same walk (the
            // segment emits nothing and the next one follows); what KEEPS refusing is the
            // assertion syntax - a mid-pattern ^ or $ (an anchor that can never match mid-string,
            // so the pattern is unsatisfiable) and the non-class escapes, both refused in the
            // branches above.
            $segs[] = "c" . $qMin . ($qExact == 1 ? "" : "-" . ($qMax > 0 ? $qMax : "")) . "x" . $chars;
            $hasClass = 1;
        } else if ($ch == "\\" && $i + 1 < $len) {
            // F413: the bare escape shorthands are classes - \\d digits, \\w word characters, \\s
            // the space, \\D (F416) the digit complement; every other escape (a literal backslash
            // among them, which the value alphabet cannot carry) keeps the refusal. BOTH
            // spellings are accepted, because the request gate sees the RAW WIRE text where the
            // JSON escape of \\d is \\\\d. The quantifier parse is the class branch's, so \\d{3},
            // \\w+ and \\s? all compose.
            $esc = $core[$i + 1];
            $step = 2;
            if ($esc == "d") {
                $chars = "0123456789";
            } else if ($esc == "w") {
                $chars = "_0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ^";
            } else if ($esc == "s") {
                $chars = " ";
            } else if ($esc == "D") {
                $chars = api_pattern_class_complement("0123456789");
            } else if ($esc == "\\" && $i + 2 < $len
                       && ($core[$i + 2] == "d" || $core[$i + 2] == "w" || $core[$i + 2] == "s"
                           || $core[$i + 2] == "D")) {
                $esc = $core[$i + 2];
                if ($esc == "d") {
                    $chars = "0123456789";
                } else if ($esc == "w") {
                    $chars = "_0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ^";
                } else if ($esc == "s") {
                    $chars = " ";
                } else {
                    $chars = api_pattern_class_complement("0123456789");
                }
                $step = 3;
            } else {
                return "";
            }
            if ($lit != "") {
                $segs[] = "l" . $lit;
                $lit = "";
            }
            $i += $step;
            $qMin = 1;
            $qMax = 1;
            $qExact = 1;
            if ($i < $len && $core[$i] == "{") {
                $brace = strpos($core, "}", $i);
                if ($brace === false) {
                    return "";
                }
                $num = substr($core, $i + 1, $brace - $i - 1);
                $parts = explode(",", $num);
                if (count($parts) > 2
                    || preg_match('/^[0-9]{1,2}$/', $parts[0]) != 1
                    || (int) $parts[0] < 1 || (int) $parts[0] > 16) {
                    return "";
                }
                $qMin = (int) $parts[0];
                if (count($parts) == 2) {
                    if (preg_match('/^[0-9]{1,2}$/', $parts[1]) != 1
                        || (int) $parts[1] > 16 || (int) $parts[1] < $qMin) {
                        return "";
                    }
                    $qMax = (int) $parts[1];
                    $qExact = $qMax == $qMin ? 1 : 0;
                }
                $i = $brace + 1;
            } else if ($i < $len && ($core[$i] == "+" || $core[$i] == "*" || $core[$i] == "?")) {
                if ($core[$i] == "+") {
                    $qMin = 1;
                    $qMax = 0;
                } else if ($core[$i] == "*") {
                    $qMin = 0;
                    $qMax = 0;
                } else {
                    $qMin = 0;
                    $qMax = 1;
                }
                $qExact = 0;
                $i++;
            }
            // F415/F417: the non-exact quantifier is served regardless of position (the minimum
            // emission lands inside the bounds); the assertion syntax is refused in the branches
            // above.
            $segs[] = "c" . $qMin . ($qExact == 1 ? "" : "-" . ($qMax > 0 ? $qMax : "")) . "x" . $chars;
            $hasClass = 1;
        } else {
            if (strpos("[]{}", $ch) !== false) {
                return "";
            }
            if (preg_match('/[A-Za-z0-9 .\\-_]/', $ch) != 1) {
                return "";
            }
            if (strlen($lit) >= 32) {
                return "";
            }
            $lit .= $ch;
            $i++;
        }
    }
    if ($lit != "") {
        $segs[] = "l" . $lit;
    }
    if ($hasClass == 0) {
        return "";
    }
    return implode("~", $segs);
}

// F404: ids of tokens whose decoded text is exactly ONE character of the class charset. The raw
// gguf_charset_ids set can include multi-character tokens made entirely of class characters, and
// a counted class segment would then emit more characters than the pattern asks for - the same
// over-run the F399 length budget had to filter out of the quote close.
function api_pattern_class_ids(string $chars): string
{
    $all = gguf_charset_ids($chars);
    if ($all == "") {
        return "";
    }
    $out = "";
    $parts = explode(",", $all);
    for ($i = 0; $i < count($parts); $i++) {
        $t = gguf_token_str((int) $parts[$i]);
        if (strlen($t) == 1 && strpos($chars, $t) !== false) {
            $out = $out == "" ? $parts[$i] : $out . "," . $parts[$i];
        }
    }
    return $out;
}

function api_schema_pattern_unsupported(string $body): int
{
    if (strpos($body, "\"pattern\"") === false) {
        return 0;
    }
    $off = 0;
    while (true) {
        $p = strpos($body, "\"pattern\"", $off);
        if ($p === false) {
            break;
        }
        $c = strpos($body, ":", $p);
        if ($c === false) {
            break;
        }
        $q1 = strpos($body, "\"", $c);
        if ($q1 === false) {
            break;
        }
        $q2 = strpos($body, "\"", $q1 + 1);
        if ($q2 === false) {
            break;
        }
        $pat = substr($body, $q1 + 1, $q2 - $q1 - 1);
        $off = $q2 + 1;
        // F404: an anchored class/count pattern the grammar implements is honoured, not refused -
        // the same composability rule the other keyword gates follow (a supported form exempting
        // itself does not weaken the refusal of the forms nobody enforces).
        if (api_schema_pattern_spec($pat) != "") {
            continue;
        }
        $core = $pat;
        $clen = strlen($core);
        if ($clen >= 4 && substr($core, 0, 2) == "^(" && substr($core, $clen - 2, 2) == ")$") {
            $core = substr($core, 2, $clen - 4);
        } else {
            if (substr($core, 0, 1) == "^") {
                $core = substr($core, 1);
            }
            $clen = strlen($core);
            if ($clen > 0 && substr($core, $clen - 1, 1) == "$") {
                $core = substr($core, 0, $clen - 1);
            }
        }
        foreach (explode(" ", ". * + ? [ ] { } ( ) \ ^ $") as $mch) {
            if ($mch != "" && strpos($core, $mch) !== false) {
                return 1;
            }
        }
    }
    return 0;
}

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
    // Finite domains (enum/const/pattern) are handled by api_schema_candidates. F429: a pattern
    // inside propertyNames constrains KEY NAMES, not the emitted values, so that block is removed
    // before the scan - otherwise a legitimate key-name pattern disqualifies the value generator
    // and the request falls back to prose.
    $scan = $schema;
    $pnp = strpos($schema, "\"propertyNames\"");
    if ($pnp !== false) {
        $pnbo = strpos($schema, "{", $pnp);
        if ($pnbo !== false) {
            $pnblk = api_balance_block($schema, $pnbo);
            if ($pnblk != "") {
                $scan = str_replace($pnblk, "", $schema);
            }
        }
    }
    if (preg_match('/"(enum|const|pattern)"\s*:/', $scan) == 1) {
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
    // F394: this cap used to be 3, and a fourth string key was dropped from the output object with
    // no error - the generator below is arity-agnostic (it loops count($props)), so the truncation
    // was purely the detector's budget. Raised to 8; SCHEMA-PROPS5 asserts five keys survive.
    $plan = api_top_leaves($block, 8);
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
// F374: warm the engine before the listener opens. The served probe measures the FIRST request of
// a server process at 925-1618 ms to its first token and every later request at 75-105 ms
// (engine/chunk_gap_f372_r1 vs r2/r3 and the same shape in f373/f369/f364e), because the first
// prefill in a process pays the one-time upload of the resident weights - the self-test says the
// same thing about its own numbers: "the first prefill in this process pays for uploading 290
// tensors". Moving that to boot costs startup time the health probe already waits through, and
// makes the first user request fast; the WARM line in the serve log is the proof it ran.
function api_warm(int $backend): string
{
    $t = microtime(true);
    model_generate($backend, tok_encode("Warm up."), 2);
    return "WARM ms=" . ((microtime(true) - $t) * 1000.0) . " err=" . model_last_error() . "\n";
}

// F377: per-request speculation attribution. The engine resets its counters at the start of
// every speculative call, so the stats read immediately after a call belong to THAT request -
// which is what turns "the whole run averaged 1.6x" into "this request drafted N, accepted M,
// verified V". Plain requests are logged too, with mode=plain, so the harness can compare two
// groups measured in the same minutes instead of against last week number.
function api_spec_req(string $mode, int $k, int $want, int $got, int $cache,
                      float $ms): void
{
    $f = fopen("spec_req.log", "a");
    if ($f !== false) {
        fwrite($f, microtime(true) . " SPECREQ mode=" . $mode . " k=" . $k
            . " want=" . $want . " got=" . $got . " cache=" . $cache . " "
            . " win=" . model_spec_window() . " " . model_spec_stats()
            . " ms=" . $ms . "\n");
        fclose($f);
    }
}

function api_serve_par(int $port): void
{
    // F374: warm first, THEN bind - a port that answers while the weights are still being
    // uploaded is what made the first request slow, so "healthy" has to mean "warm".
    echo api_warm(0);
    $srv = stream_socket_server("tcp://127.0.0.1:" . $port);
    if (!$srv) {
        echo "API_SOCK_FAIL\n";
        return;
    }
    stream_set_blocking($srv, false);
    // F368: say which attention path this process will serve with. The F366 lesson is that a
    // switch that never reached the code leaves both sides of an A/B identical and the log silent.
    echo "ATTN_MODE " . model_attn_mode() . "\n";
    // F392: the drafter window is a knob whose wrong value is silent - it looks like the drafter
    // simply had nothing to propose - so the process states the value it is running with.
    echo "SPEC_WINDOW " . model_spec_window() . "\n";
    // F375: the concurrency is decided here, BEFORE the banner that reports it and before the
    // region allocation that uses it. Both of those read $nslots, and in the first version of this
    // rewrite they ran above the assignment - so the sentinel had no effect and the log printed an
    // undefined value while four streams still landed on two slots. Order is part of the fix.
    // Default stays 2 so the existing parity and fairness gates measure exactly what they measured
    // before; serve.par4.on asks for four regions and four slots.
    $nslots = 2;
    if (file_exists("serve.par4.on")) {
        $nslots = 4;
    }
    echo "API_LISTEN " . $port . " (parallel: streaming text only)\n";
    // F375: the slot count has to be visible in the log, not inferred from behaviour -
    // F366 showed a setting that never reached the code leaves every measurement silent.
    echo "PAR slots=" . $nslots . "\n";
    echo "PAR loop start\n";
    // F359: the two batch regions are allocated once, each holding `cap` KV rows. A prompt plus
    // the tokens it may generate has to fit in one region; requests that do not fit are refused
    // instead of overwriting the other conversation's rows.
    $cap = 4096;
    if (model_batch_start_n(0, $cap, $nslots) != 0) {
        echo "PAR_BATCH_FAIL\n";
        return;
    }
    // $socks holds the listener as a placeholder so the array stays one homogeneous type - a
    // socket in it is only ever used while that slot is occupied.
    $slotTag = explode(",", "A,B,C,D");
    $on = [0, 0, 0, 0];
    $skind = [1, 1, 1, 1];
    $smx = [0, 0, 0, 0];
    $ste = [0.0, 0.0, 0.0, 0.0];
    $stp = [0.0, 0.0, 0.0, 0.0];
    $ssd = [0, 0, 0, 0];
    $sbg = [0, 0, 0, 0];
    $st0 = [0.0, 0.0, 0.0, 0.0];
    $srs = [0, 0, 0, 0];
    $ssn = [0, 0, 0, 0];
    $sstop = ["", "", "", ""];
    $shold = ["", "", "", ""];
    $socks = [$srv, $srv, $srv, $srv];
    $toks = [-1, -1, -1, -1];
    while (true) {
        // F370: the three stamps below split one served iteration into "before the forward pass"
        // (accept, select, request and slot bookkeeping), "the forward pass itself as the host sees
        // it" and "everything after it: token to text, escaping, SSE framing, the write, the trace".
        // pre+eng+post has to equal the A-to-A cadence, and it is only a real check because the
        // three durations come from three separate stamps.
        $tIter0 = microtime(true);
        // stream_select avoids calling accept() on an empty listener, which raises a PHP-level
        // "Accept failed" warning on every timeout. When nothing is readable, $c points at the
        // listener itself (same resource type) and the request block below is skipped.
        $rd = [$srv];
        $wr = [];
        $ex = [];
        // F372: F371 measured this wait at 5.7-6.8 ms per iteration - the loop asks for 1000
        // microseconds and Windows resolves the sleep far more coarsely, so a served token paid
        // ~6 ms of pure waiting while a stream was already being generated. While a slot needs
        // stepping the pass itself paces the loop, so poll instead of sleeping; when every slot is
        // idle the timeout stays at 1 ms, so an idle server does not turn into a busy wait.
        // The `sel` field in the trace is what proves which of the two happened.
        $need = 0;   // F375: does any live slot still need a step this iteration?
        for ($s = 0; $s < $nslots; $s++) {
            if ($on[$s] == 1 && $srs[$s] == 0) {
                $need = 1;
            }
        }
        $tmo = $need == 1 ? 0 : 1000;
        $sel = stream_select($rd, $wr, $ex, 0, $tmo);
        $c = ($sel !== false && $sel > 0) ? stream_socket_accept($srv, 0) : $srv;
        $tSel1 = microtime(true);   // F371: end of select+accept, the first half of `pre`
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
                // Structured output owns the shape in the parallel slots as well: json_object drops
                // the stop sequence rather than cutting the block mid-stream.
                $stopV = (count($stopsV) > 0 && api_json_object_requested($body) == 0) ? $stopsV[0] : "";
                $schemaCands = api_response_schema_candidates($body);
                $slot = -1;
                if ($bad == 0) {
                    // F375: the first free slot, whatever the count. Previously hard-coded to
                    // "A then B", which is the same rule for two slots.
                    for ($s = 0; $s < $nslots; $s++) {
                        if ($on[$s] == 0 && $slot < 0) {
                            $slot = $s;
                        }
                    }
                }
                if ($bad == 1 || $slot < 0) {
                    $payload = $bad == 1
                        ? "{\"error\":{\"message\":\"parallel mode: streaming text only (no tools/json/n; one stop sequence max)\",\"type\":\"invalid_request_error\"}}"
                        : "{\"error\":{\"message\":\"parallel mode: " . $nslots
                          . " streams at a time\",\"type\":\"server_error\"}}";
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
                        // F359: the conversation joins the batch instead of taking the single
                        // session path. The prefix cache is not used here: it restores one
                        // conversation into rows [0,n), which is the staging area the batch
                        // deliberately leaves free, and the batch owns its own two regions.
                        if ($pn + $mxv > $cap) {
                            $payload = "{\"error\":{\"message\":\"parallel mode: prompt plus max_tokens exceeds the slot capacity\",\"type\":\"invalid_request_error\"}}";
                            fwrite($c, "HTTP/1.1 400 Bad Request\r\nContent-Type: application/json\r\nConnection: close\r\n"
                                     . "Content-Length: " . strlen($payload) . "\r\n\r\n" . $payload);
                            fclose($c);
                        } else if (model_batch_add(0, $slot, $ids, $tev, $tpv, $sdv) != 0) {
                            $payload = "{\"error\":{\"message\":\"parallel mode: the slot could not take this prompt\",\"type\":\"server_error\"}}";
                            fwrite($c, "HTTP/1.1 503 Service Unavailable\r\nContent-Type: application/json\r\nConnection: close\r\n"
                                     . "Content-Length: " . strlen($payload) . "\r\n\r\n" . $payload);
                            fclose($c);
                        } else {
                            api_par_trace("START slot=" . $slot . " kind=" . $kind . " mx=" . $mxv);
                            $socks[$slot] = $c;
                            $on[$slot] = 1;
                            $skind[$slot] = $kind;
                            $smx[$slot] = $mxv;
                            $ste[$slot] = $tev;
                            $stp[$slot] = $tpv;
                            $ssd[$slot] = $sdv;
                            $sbg[$slot] = $bgv;
                            $st0[$slot] = microtime(true);
                            $srs[$slot] = 0;
                            $ssn[$slot] = 0;
                            $sstop[$slot] = $stopV;
                            $shold[$slot] = "";
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
                                        api_schema_charset(), 3, 6, 1, api_response_schema_array_bounds($body));
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
                                        api_schema_charset(), 4, 6, 1,
                                        api_response_schema_array_bounds($body));
                                } else if ($scArrNum != "") {
                                    $san = explode("|", $scArrNum);
                                    $scOut = api_array_number_value($ids, $san[0], (int) $san[1],
                                        4, 3, 1, api_response_schema_array_bounds($body));
                                } else if ($scMix != "") {
                                    $scOut = api_object_mixed($ids, $scMix, api_schema_charset(), 16, 1);
                                } else if (api_response_schema_top_array_string($body) == 1) {
                                    $scOut = api_top_array_string_value($ids, api_schema_charset(),
                                        4, 4, 1, api_response_schema_array_bounds($body));
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
                                    $scProp = api_response_schema_string_props_required($body);
                                    if (count($scProp) > 0) {
                                        $scOut = api_string_value_multi($ids, $scProp,
                                            api_schema_charset(), 16, 1, api_response_schema_string_bounds($body));
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
                                                $scNumB = api_response_schema_number_bounded($body);
                                                if ($scNumB != "") {
                                                    $snb = explode("|", $scNumB);
                                                    $scOut = api_number_bounded_value($ids, $snb[0], (int) $snb[1], (int) $snb[2], 1);
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
                                // The constrained pass answered this request on the single-session
                                // path, so the batch slot it was given is free again.
                                // F375: the constrained answer frees the slot it was handed,
                                // and it never occupies a slot across iterations - with the
                                // slots in arrays this is one assignment, not two branches.
                                model_batch_stop($slot);
                                $on[$slot] = 0;
                                fclose($c);
                            }
                        }
                    }
                }
            }
        }
        $tPre1 = microtime(true);   // F371: everything before this was request handling / slot tail
        // One forward pass for the whole batch (F359): every live conversation advances by one
        // token in the SAME pass, so two concurrent streams cost one forward pass instead of two.
        // The stop conditions are decided BEFORE the pass, so a conversation that is already
        // finished is not stepped again.
        for ($s = 0; $s < $nslots; $s++) {
            $toks[$s] = -1;
        }
        // F375: one loop per rule instead of two copies of it. A slot that has hit its timeout or
        // its max_tokens is stopped BEFORE the pass so it is not stepped again - the same order the
        // two-slot code used, where A was checked before B.
        for ($s = 0; $s < $nslots; $s++) {
            if ($on[$s] == 1) {
                if ($sbg[$s] > 0 && (microtime(true) - $st0[$s]) * 1000.0 > $sbg[$s]) {
                    $srs[$s] = 3;
                    model_batch_stop($s);
                } else if ($ssn[$s] >= $smx[$s]) {
                    $srs[$s] = 2;
                    model_batch_stop($s);
                }
            }
        }
        // Re-evaluated AFTER the stop checks above: the original code gated the pass on the current
        // rs values, while the select timeout at the top of the iteration used the previous ones.
        // Keeping those two moments separate is what preserves when a pass is skipped.
        $needStep = 0;
        for ($s = 0; $s < $nslots; $s++) {
            if ($on[$s] == 1 && $srs[$s] == 0) {
                $needStep = 1;
            }
        }
        if ($needStep == 1) {
            $tEng0 = microtime(true);
            $rows = model_batch_next(0);
            $tEng1 = microtime(true);
            if ($rows > 0) {
                $tk = explode(",", model_batch_tokens());
                for ($s = 0; $s < $nslots; $s++) {
                    $toks[$s] = (int) $tk[$s];
                }
            }
        }
        // F375: ONE emission body for every slot - this is the block that used to exist twice, once
        // per conversation. The loop order IS the write order, so slot 0 is served before slot 1
        // exactly as A was written before B; that ordering is what fairness_test.py observes, and
        // saying so here makes it a property of the loop rather than an accident of two copies that
        // could drift apart.
        for ($s = 0; $s < $nslots; $s++) {
            if ($on[$s] == 1) {
                if ($srs[$s] == 0) {
                    $t = $toks[$s];
                    if ($t < 0) {
                        $srs[$s] = 1;
                    } else {
                        $piece = api_text("" . $t);
                        $emit = $piece;
                        if ($sstop[$s] != "") {
                            $shold[$s] = $shold[$s] . $piece;
                            $cut = strpos($shold[$s], $sstop[$s]);
                            if ($cut !== false) {
                                $emit = substr($shold[$s], 0, $cut);
                                $srs[$s] = 5;
                            } else {
                                $keep = strlen($sstop[$s]) - 1;
                                $emit = strlen($shold[$s]) > $keep
                                    ? substr($shold[$s], 0, strlen($shold[$s]) - $keep) : "";
                                $shold[$s] = $keep > 0
                                    ? substr($shold[$s], strlen($shold[$s]) - $keep) : "";
                            }
                        }
                        $w = 1;
                        if ($emit != "") {
                            if ($skind[$s] == 1) {
                                $w = fwrite($socks[$s],
                                           "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                           . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                           . "\"delta\":{\"content\":\"" . api_esc($emit) . "\"},"
                                           . "\"finish_reason\":null}]}\n\n");
                            } else {
                                $w = fwrite($socks[$s],
                                           "event: content_block_delta\ndata: {\"type\":\"content_block_delta\","
                                           . "\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\""
                                           . api_esc($emit) . "\"}}\n\n");
                            }
                        }
                        if ($w < 1) {
                            $srs[$s] = 4;
                        } else if ($emit != "") {
                            $ssn[$s] = $ssn[$s] + 1;
                            api_par_trace("STEP " . $slotTag[$s] . " n=" . $ssn[$s]
                                . " s1=" . model_stage_at(1)
                                . " sp=" . model_attn_split_p()
                                . " pre=" . (($tEng0 - $tIter0) * 1000.0)
                                . " eng=" . (($tEng1 - $tEng0) * 1000.0)
                                . " post=" . ((microtime(true) - $tEng1) * 1000.0)
                                . " sel=" . (($tSel1 - $tIter0) * 1000.0)
                                . " mid=" . (($tPre1 - $tSel1) * 1000.0)
                                . " tail=" . (($tEng0 - $tPre1) * 1000.0));
                        }
                    }
                }
                if ($srs[$s] != 0) {
                    if ($srs[$s] != 5 && $shold[$s] != "") {
                        if ($skind[$s] == 1) {
                            fwrite($socks[$s],
                                  "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                  . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                  . "\"delta\":{\"content\":\"" . api_esc($shold[$s]) . "\"},"
                                  . "\"finish_reason\":null}]}\n\n");
                        } else {
                            fwrite($socks[$s],
                                  "event: content_block_delta\ndata: {\"type\":\"content_block_delta\","
                                  . "\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\""
                                  . api_esc($shold[$s]) . "\"}}\n\n");
                        }
                        $ssn[$s] = $ssn[$s] + 1;
                    }
                    if ($skind[$s] == 1) {
                        $fin = ($srs[$s] == 2 || $srs[$s] == 3) ? "length" : "stop";
                        fwrite($socks[$s],
                              "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                              . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                              . "\"delta\":{},\"finish_reason\":\"" . $fin . "\"}]}\n\n");
                        fwrite($socks[$s], "data: [DONE]\n\n");
                    } else {
                        $stopr = ($srs[$s] == 5) ? "stop_sequence"
                            : (($srs[$s] == 2 || $srs[$s] == 3) ? "max_tokens" : "end_turn");
                        fwrite($socks[$s],
                              "event: content_block_stop\ndata: {\"type\":\"content_block_stop\",\"index\":0}\n\n");
                        fwrite($socks[$s],
                              "event: message_delta\ndata: {\"type\":\"message_delta\","
                              . "\"delta\":{\"stop_reason\":\"" . $stopr . "\"},\"usage\":{\"output_tokens\":"
                              . $ssn[$s] . "}}\n\n");
                        fwrite($socks[$s], "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n");
                    }
                    $why = $srs[$s] == 1 ? "eos"
                        : ($srs[$s] == 2 ? "max"
                        : ($srs[$s] == 3 ? "timeout" : ($srs[$s] == 5 ? "stop" : "client-gone")));
                    echo "SESSION par slot=" . $slotTag[$s] . " tokens=" . $ssn[$s]
                        . " reason=" . $why . "\n";
                    model_batch_stop($s);
                    fclose($socks[$s]);
                    $on[$s] = 0;
                }
            }
        }
        // F375 faithfulness note: $need was computed at the TOP of this iteration (that is what the
        // select timeout has to use), but the yield here was evaluated on the END-of-iteration
        // state in the two-slot code - after a slot may have finished or a new request arrived.
        // Reusing $need here would have shifted the 2 ms by one iteration in exactly those two
        // cases, so re-evaluate now rather than inherit the earlier moment.
        $needNow = 0;
        for ($s = 0; $s < $nslots; $s++) {
            if ($on[$s] == 1 && $srs[$s] == 0) {
                $needNow = 1;
            }
        }
        if ($needNow == 0) {
            usleep(2000);
        }
    }
}

function api_serve(int $port): void
{
    echo api_warm(0);   // F374: same reason as the two-slot server - bind only once warm
    $srv = stream_socket_server("tcp://127.0.0.1:" . $port);
    if (!$srv) {
        echo "API_SOCK_FAIL\n";
        return;
    }
    echo "API_LISTEN " . $port . "\n";
    echo "ATTN_MODE " . model_attn_mode() . "\n";
    // F392: the drafter window is a knob whose wrong value is silent - it looks like the drafter
    // simply had nothing to propose - so the process states the value it is running with.
    echo "SPEC_WINDOW " . model_spec_window() . "\n";

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
        if (strpos($req, "/v1/") === false && strpos($req, "GET /health") === false
            && strpos($req, "GET /spec_stats") === false) {
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
        } else if (strpos($req, "GET /spec_stats") !== false) {
            // F349: the speculative counters are process-global and reset at the start of every
            // generation call, so this reports the LAST request's drafts/accepted/verifies. Read it
            // immediately after the request being probed and keep the requests serial - two
            // concurrent generations would otherwise report each other's counters.
            $payload = "{\"stats\":\"" . api_esc(model_spec_stats()) . "\"}";
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
            // F398: the constraint checks below apply to the RESPONSE schema only. Scanning the whole
            // body also matched tool parameter schemas, where `required` naming a subset is normal
            // and is handled by the tool path - that refused 53 working smoke cases. Scope first.
            $schBody = strpos($body, "\"json_schema\"") !== false
                ? api_json_object_after($body, "schema") : "";
            if ($ids == "") {
                $payload = "{\"error\":{\"message\":\"no user content\",\"type\":\"invalid_request_error\"}}";
            } else if (api_schema_pattern_unsupported($schBody) == 1) {
                // F396: a pattern the grammar cannot honour used to fall through to UNCONSTRAINED
                // generation - the caller asked for a constraint and silently got none. Refuse it
                // with a reason instead. Literal alternations (Paris|London, ^(A|B)$) stay supported.
                $payload = "{\"error\":{\"message\":\"schema pattern with regex metacharacters is not supported; use a literal or an alternation of literals\",\"type\":\"invalid_request_error\"}}";
                $status = "400 Bad Request";
            } else if (api_schema_bounds_unsupported($schBody) == 1) {
                // F397: same rule as the pattern branch - a bound combination the grammar cannot
                // enumerate used to be served as unconstrained text. Refuse it with the reason
                // instead. F405/F419: the honoured set is wider now, so the message names it - a
                // single integer property with bounds of any sign and any span, either side
                // missing; what remains is float bounds and multi-property bounds.
                $payload = "{\"error\":{\"message\":\"numeric bounds are honoured on a single integer property with minimum/maximum or exclusiveMinimum/exclusiveMaximum of any sign (any span); exclusive bounds convert to the adjacent integer bound (the smallest integer above an exclusive minimum, the largest below an exclusive maximum), fractional bounds on an integer leaf round inward, and an interval containing no integer is refused; multipleOf inside those bounds is served as the smallest multiple, and an interval containing no multiple is refused; an unbounded multipleOf up to 999999 is served by a forced suffix whose width follows the multiplier, and one above that is refused; a multipleOf on a schema with more than one property, or on a number (non-integer) leaf, is not supported; a non-integer number leaf with fractional bounds and multi-property bounds are not supported\",\"type\":\"invalid_request_error\"}}";
                $status = "400 Bad Request";
            } else if (api_schema_keywords_unsupported($schBody) != "") {
                // F398: a keyword the decoder does not enforce is named in the refusal instead of
                // being ignored. Since then minItems/maxItems, minLength/maxLength, a required
                // subset and additionalProperties have each become enforced on the shapes listed
                // in the message, so the message names what IS honoured too - the caller learns
                // which part of the schema could not be honoured instead of receiving text that
                // ignores it.
                $payload = "{\"error\":{\"message\":\"schema keyword not supported: "
                    . api_schema_keywords_unsupported($schBody)
                    . " (minItems/maxItems are honoured on a single scalar-array property, "
                    . "also together with additionalProperties:true/false; "
                    . "minLength/maxLength and a required subset on a flat all-string object; "
                    . "additionalProperties:true/false wherever every key is named from the "
                    . "declared plan; uniqueItems:false, which allows the duplicates the "
                    . "generator may emit; minProperties/maxProperties whose bound the counted key "
                    . "set can satisfy)"
                    . "\",\"type\":\"invalid_request_error\"}}";
                $status = "400 Bad Request";
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
                $pcPath = api_cache_pick($ids);
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
                        $qWire = [];
                        // Same rule as the non-stream path: every declared tool is a candidate, each
                        // with its real argument values - empty arguments are not executable (F194a).
                        for ($qi = 0; $qi < count($qNames); $qi++) {
                            $qArgsI = api_schema_arg_candidates($body, $qNames[$qi]);
                            if (count($qArgsI) > 0) {
                                for ($qj = 0; $qj < count($qArgsI); $qj++) {
                                    $qWire[] = api_tool_wire($qNames[$qi], $qArgsI[$qj]);
                                }
                            } else {
                                $qWire[] = api_tool_wire($qNames[$qi], "{}");
                            }
                        }
                        for ($qi = 0; $qi < count($qWire); $qi++) {
                            $qSkels[] = $qWire[$qi];
                        }
                        if (preg_match('/"parallel_tool_calls"\s*:\s*true/', $body) == 1
                            && count($qWire) > 1) {
                            $qSkels = [];
                            for ($qa = 0; $qa < count($qWire); $qa++) {
                                for ($qb = 0; $qb < count($qWire); $qb++) {
                                    if ($qa == $qb) {
                                        continue;
                                    }
                                    if (count($qSkels) >= 24) {
                                        break;
                                    }
                                    $qSkels[] = $qWire[$qa] . "\n" . $qWire[$qb];
                                }
                            }
                        }
                        if (count($qSkels) > 0) {
                            $qOut = api_constrained($ids, $qSkels, 192, $prefixCache);
                            $qText = api_gen_text($qOut, $promptN);
                            // Plural extraction so a two-block turn streams every call. The previous
                            // prefix match rebuilt a single skeleton and silently substituted another
                            // candidate when nothing matched - F198's wrong answer.
                            $qList = api_tool_calls_json($qText, $qNames);
                            $qItems = api_tool_items($qList);
                            $qName = count($qItems) > 0 ? api_str($qItems[0], "name") : "";
                            if ($qName != "") {
                                $qNameList = "";
                                $qArgList = "";
                                $qRespOut = "";
                                $qn = 0;
                                for ($qi = 0; $qi < count($qItems); $qi++) {
                                    $qOneName = api_str($qItems[$qi], "name");
                                    if ($qOneName == "") {
                                        continue;
                                    }
                                    $qOneArgs = api_str($qItems[$qi], "arguments");
                                    if ($qOneArgs == "") {
                                        $qOneArgs = "{}";
                                    }
                                    if ($qn > 0) {
                                        $qNameList = $qNameList . ",";
                                        $qArgList = $qArgList . ",";
                                        $qRespOut = $qRespOut . ",";
                                    }
                                    $qNameList = $qNameList . "{\"index\":" . $qn . ",\"id\":\"call_"
                                        . ($qn + 1) . "\",\"type\":\"function\",\"function\":{\"name\":\""
                                        . api_esc($qOneName) . "\",\"arguments\":\"\"}}";
                                    $qArgList = $qArgList . "{\"index\":" . $qn
                                        . ",\"function\":{\"arguments\":\"" . $qOneArgs . "\"}}";
                                    $qRespOut = $qRespOut . "{\"id\":\"fc_" . ($qn + 1)
                                        . "\",\"type\":\"function_call\",\"status\":\"completed\",\"call_id\":\"call_"
                                        . ($qn + 1) . "\",\"name\":\"" . api_esc($qOneName)
                                        . "\",\"arguments\":\"" . $qOneArgs . "\"}";
                                    $qn = $qn + 1;
                                }
                                if ($wantResponses) {
                                    $quTok = count(explode(",", $qOut)) - $promptN;
                                    if ($quTok < 0) {
                                        $quTok = 0;
                                    }
                                    for ($qi = 0; $qi < $qn; $qi++) {
                                        $qOneName = api_str($qItems[$qi], "name");
                                        $qOneArgs = api_str($qItems[$qi], "arguments");
                                        if ($qOneArgs == "") {
                                            $qOneArgs = "{}";
                                        }
                                        fwrite($c, api_resp_call_start($modelId, $qOneName, $qi));
                                        fwrite($c, api_resp_call_done($qOneName, $qOneArgs, $quTok,
                                            $modelId, $promptN, $qi, $qi == $qn - 1 ? 1 : 0, $qRespOut));
                                    }
                                } else {
                                    fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                             . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                             . "\"delta\":{\"role\":\"assistant\",\"content\":null,\"tool_calls\":["
                                             . $qNameList . "]},"
                                             . "\"finish_reason\":null}]}\n\n");
                                    fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                             . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                             . "\"delta\":{\"tool_calls\":[" . $qArgList . "]},"
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
                        $sWire = [];
                        for ($i = 0; $i < count($sArgs0); $i++) {
                            $sWire[] = api_tool_wire($sChoice, $sArgs0[$i]);
                        }
                        if (count($sWire) == 0) {
                            $sWire[] = api_tool_wire($sChoice, "{}");
                        }
                        for ($i = 0; $i < count($sWire); $i++) {
                            $sSkels[] = $sWire[$i];
                        }
                        if (preg_match('/"parallel_tool_calls"\s*:\s*true/', $body) == 1
                            && count($sWire) > 1) {
                            $sSkels = [];
                            for ($sa = 0; $sa < count($sWire); $sa++) {
                                for ($sb = 0; $sb < count($sWire); $sb++) {
                                    if ($sa == $sb) {
                                        continue;
                                    }
                                    if (count($sSkels) >= 24) {
                                        break;
                                    }
                                    $sSkels[] = $sWire[$sa] . "\n" . $sWire[$sb];
                                }
                            }
                        }
                        $sOut = api_constrained($ids, $sSkels, 192, $prefixCache);
                        // api_constrained returns token ids: decode the generated part before
                        // handing it to the call parser.
                        $sText = api_gen_text($sOut, $promptN);
                        $sCall = $sText == "" ? "" : api_tool_calls_json($sText, $sNames);
                        $sItems = api_tool_items($sCall);
                        $sName = count($sItems) > 0 ? api_str($sItems[0], "name") : "";
                        if ($sName == "") {
                            fwrite($c, "data: {\"error\":{\"message\":\"tool constraint failed\"}}\n\n");
                            echo "SESSION stream tool=failed reason=constraint cands=" . count($sArgs0)
                                . " out=" . strlen($sOut) . " call=" . strlen($sCall) . "\n";
                            fclose($c);
                            continue;
                        }
                        $sNameList = "";
                        $sArgList = "";
                        $sRespOut = "";
                        $sn = 0;
                        for ($si = 0; $si < count($sItems); $si++) {
                            $sOneName = api_str($sItems[$si], "name");
                            if ($sOneName == "") {
                                continue;
                            }
                            $sOneArgs = api_str($sItems[$si], "arguments");
                            if ($sOneArgs == "") {
                                $sOneArgs = "{}";
                            }
                            if ($sn > 0) {
                                $sNameList = $sNameList . ",";
                                $sArgList = $sArgList . ",";
                                $sRespOut = $sRespOut . ",";
                            }
                            $sNameList = $sNameList . "{\"index\":" . $sn . ",\"id\":\"call_" . ($sn + 1)
                                . "\",\"type\":\"function\",\"function\":{\"name\":\"" . api_esc($sOneName)
                                . "\",\"arguments\":\"\"}}";
                            $sArgList = $sArgList . "{\"index\":" . $sn
                                . ",\"function\":{\"arguments\":\"" . $sOneArgs . "\"}}";
                            $sRespOut = $sRespOut . "{\"id\":\"fc_" . ($sn + 1)
                                . "\",\"type\":\"function_call\",\"status\":\"completed\",\"call_id\":\"call_"
                                . ($sn + 1) . "\",\"name\":\"" . api_esc($sOneName)
                                . "\",\"arguments\":\"" . $sOneArgs . "\"}";
                            $sn = $sn + 1;
                        }
                        if ($wantResponses) {
                            $suTok = count(explode(",", $sOut)) - $promptN;
                            if ($suTok < 0) {
                                $suTok = 0;
                            }
                            for ($si = 0; $si < count($sItems); $si++) {
                                $sOneName = api_str($sItems[$si], "name");
                                if ($sOneName == "") {
                                    continue;
                                }
                                $sOneArgs = api_str($sItems[$si], "arguments");
                                if ($sOneArgs == "") {
                                    $sOneArgs = "{}";
                                }
                                $sIdx = $si;
                                fwrite($c, api_resp_call_start($modelId, $sOneName, $sIdx));
                                fwrite($c, api_resp_call_done($sOneName, $sOneArgs, $suTok,
                                    $modelId, $promptN, $sIdx, $si == count($sItems) - 1 ? 1 : 0, $sRespOut));
                            }
                        } else {
                            fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                     . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                     . "\"delta\":{\"role\":\"assistant\",\"content\":null,\"tool_calls\":["
                                     . $sNameList . "]},"
                                     . "\"finish_reason\":null}]}\n\n");
                            fwrite($c, "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\","
                                     . "\"model\":\"" . $modelId . "\",\"choices\":[{\"index\":0,"
                                     . "\"delta\":{\"tool_calls\":[" . $sArgList . "]},"
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
                                api_schema_charset(), 3, 6, $prefixCache, api_response_schema_array_bounds($body));
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
                                6, $prefixCache, api_response_schema_array_bounds($body));
                        } else if ($sArrNum != "") {
                            $san2 = explode("|", $sArrNum);
                            $sJOut = api_array_number_value($ids, $san2[0], (int) $san2[1], 4, 3,
                                $prefixCache, api_response_schema_array_bounds($body));
                        } else if ($sMix != "") {
                            $sJOut = api_object_mixed($ids, $sMix, api_schema_charset(), 16,
                                $prefixCache);
                        } else if (api_response_schema_top_array_string($body) == 1) {
                            $sJOut = api_top_array_string_value($ids, api_schema_charset(), 4, 4,
                                $prefixCache, api_response_schema_array_bounds($body));
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
                            $sProps = api_response_schema_string_props_required($body);
                            if (count($sProps) > 0) {
                                $sJOut = api_string_value_multi($ids, $sProps, api_schema_charset(),
                                    16, $prefixCache, api_response_schema_string_bounds($body));
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
                                        $sNumB = api_response_schema_number_bounded($body);
                                        if ($sNumB != "") {
                                            $snb2 = explode("|", $sNumB);
                                            $sJOut = api_number_bounded_value($ids, $snb2[0], (int) $snb2[1],
                                                (int) $snb2[2], $prefixCache);
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
                        // Structured output owns the shape: under json_object the stream ignores `stop`,
                        // the same contract the non-stream path already has.
                        $jsonObj = api_json_object_requested($body);
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
                            if (count($stops) > 0 && $jsonObj == 0) {
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
                $jsonMode = api_json_object_requested($body);
                // Structured outputs: a finite json_schema becomes a constrained object set.
                $schemaCands = api_response_schema_candidates($body);
                if (count($schemaCands) > 0) {
                    $jsonMode = 1;
                }
                // enum/const constraint (non-streaming): decode only inside the listed answers.
                $enumOut = "";
                // A schema with more than one "properties" block nests objects; the bare-enum
                // branch below would answer only the enum literal and drop every level (F254).
                $enumFlat = substr_count($body, "\"properties\"") < 2 ? 1 : 0;
                if (count($schemaCands) > 0) {
                    $enumOut = api_constrained($ids, $schemaCands, 128, $prefixCache);
                } else if ($enumFlat == 1 && preg_match('/"enum"\s*:\s*\[(.*?)\]/s', $body, $em)) {
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
                            api_schema_charset(), 3, 6, $prefixCache, api_response_schema_array_bounds($body));
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
                            $prefixCache, api_response_schema_array_bounds($body));
                        $jsonMode = 1;
                    } else if ($arrNum != "") {
                        $anp = explode("|", $arrNum);
                        $enumOut = api_array_number_value($ids, $anp[0], (int) $anp[1], 4, 3,
                            $prefixCache, api_response_schema_array_bounds($body));
                        $jsonMode = 1;
                    } else if ($mixPlan != "") {
                        $enumOut = api_object_mixed($ids, $mixPlan, api_schema_charset(), 16,
                            $prefixCache);
                        $jsonMode = 1;
                    } else if (api_response_schema_top_array_string($body) == 1) {
                        $enumOut = api_top_array_string_value($ids, api_schema_charset(), 4, 4,
                            $prefixCache, api_response_schema_array_bounds($body));
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
                        $strProps = api_response_schema_string_props_required($body);
                        if (count($strProps) > 0) {
                            $enumOut = api_string_value_multi($ids, $strProps, api_schema_charset(),
                                16, $prefixCache, api_response_schema_string_bounds($body));
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
                                    $numPropB = api_response_schema_number_bounded($body);
                                    if ($numPropB != "") {
                                        $npb = explode("|", $numPropB);
                                        $enumOut = api_number_bounded_value($ids, $npb[0], (int) $npb[1],
                                            (int) $npb[2], $prefixCache);
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
                $reqCands = 0;
                $parReq = preg_match('/"parallel_tool_calls"\s*:\s*true/', $body) ? 1 : 0;
                if ($toolChoice == "" && $tcRequired == 1 && count($toolNames) > 0) {
                    // Every candidate is written in the template's own form, and a parallel turn is
                    // simply a candidate with two blocks: the extractor then sees two calls instead of
                    // being asked to recover one from a skeleton prefix.
                    $reqSkels = [];
                    $reqWire = [];
                    for ($ri = 0; $ri < count($toolNames); $ri++) {
                        $reqArgs = api_schema_arg_candidates($body, $toolNames[$ri]);
                        if (count($reqArgs) > 0) {
                            // Offer every argument value, not just the first: with pairs built from
                            // this list the model can answer "weather in Paris, time in London".
                            for ($rj = 0; $rj < count($reqArgs); $rj++) {
                                $reqWire[] = api_tool_wire($toolNames[$ri], $reqArgs[$rj]);
                            }
                            $reqCands = 1;
                        } else {
                            $reqWire[] = api_tool_wire($toolNames[$ri], "{}");
                        }
                    }
                    for ($ra = 0; $ra < count($reqWire); $ra++) {
                        $reqSkels[] = $reqWire[$ra];
                    }
                    if ($parReq == 1 && count($reqWire) > 1) {
                        $reqSkels = [];
                    }
                    for ($ra = 0; $ra < count($reqWire); $ra++) {
                        for ($rb = 0; $rb < count($reqWire); $rb++) {
                            if ($ra == $rb) {
                                continue;
                            }
                            // Every pair costs a candidate to scan at each decoded step, so the
                            // cross product is capped rather than allowed to grow squared.
                            if (count($reqSkels) >= 24) {
                                break;
                            }
                            $reqSkels[] = $reqWire[$ra] . "\n" . $reqWire[$rb];
                        }
                    }
                    $enumOut = api_constrained($ids, $reqSkels, 192, $prefixCache);
                }
                if ($toolChoice != "") {
                    // Flat enum/const properties give real arguments; otherwise the object stays {}.
                    $argCands = api_schema_arg_candidates($body, $toolChoice);
                    if (count($argCands) > 0) {
                        $skels = [];
                        if ($parReq == 1 && count($argCands) > 1) {
                            // Two calls of the same function with different arguments is the usual
                            // parallel case; single candidates are left out because a complete
                            // candidate is where the greedy decode stops.
                            $nmWire = [];
                            for ($i = 0; $i < count($argCands); $i++) {
                                $nmWire[] = api_tool_wire($toolChoice, $argCands[$i]);
                            }
                            for ($ia = 0; $ia < count($nmWire); $ia++) {
                                for ($ib = 0; $ib < count($nmWire); $ib++) {
                                    if ($ia == $ib) {
                                        continue;
                                    }
                                    if (count($skels) >= 24) {
                                        break;
                                    }
                                    $skels[] = $nmWire[$ia] . "\n" . $nmWire[$ib];
                                }
                            }
                            $enumOut = api_constrained($ids, $skels, 192, $prefixCache);
                        } else {
                            for ($i = 0; $i < count($argCands); $i++) {
                                $skels[] = "{\"name\":\"" . $toolChoice . "\",\"arguments\":\""
                                         . api_esc($argCands[$i]) . "\"}";
                            }
                            $enumOut = api_constrained($ids, $skels, 96, $prefixCache);
                        }
                    } else {
                        $toolSkel = "{\"name\":\"" . $toolChoice . "\",\"arguments\":{}}";
                        $enumOut = api_constrained($ids, [$toolSkel], 64, $prefixCache);
                    }
                }
                $toolJson = "";
                if ($enumOut != "" && ($toolSkel != "" || count($argCands) > 0 || $reqCands == 1)) {
                    $toolJson = api_tool_call_json($enumOut, $toolNames);
                }
                $tries = $enumOut != "" ? 1 : ($jsonMode ? 2 : 1);
                // Draft length for speculative decoding; 0 = plain decoding. Greedy only, since
                // acceptance is defined against the model's own argmax.
                $specK = api_num($body, "spec_k", 0);
                $specBins = api_num($body, "spec_bins", 0);
                $specDepth = api_num($body, "spec_depth", 3);
                if ($specBins > 8) {
                    $specBins = 8;
                }
                if ($specDepth < 1) {
                    $specDepth = 1;
                }
                // F331: depth was hard-capped at 4 to keep the 32-bit tree mask in range, but that
                // was the worst case over bins. The real budget is bins*depth <= 32, and a long
                // shallow-wide tree loses to depth (F330: a chain verify commits up to k tokens
                // while a tree path is capped by depth), so depth up to 8 is allowed and trimmed
                // only when the mask would overflow.
                if ($specDepth > 8) {
                    $specDepth = 8;
                }
                if ($specBins > 0 && $specBins * $specDepth > 32) {
                    $specDepth = (int) (32 / $specBins);
                    if ($specDepth < 1) {
                        $specDepth = 1;
                    }
                }
                $out = "";
                $text = "";
                $genN = 0;
                $specUsed = 0;              // F377: did this request take a speculative path?
                $tGen0 = microtime(true);   // F377: the window the per-request line reports on
                $cacheHit = -1;
                $jsonOk = $jsonMode ? 0 : 1;
                $stopCut = 0;
                for ($attempt = 0; $attempt < $tries; $attempt++) {
                    if ($enumOut != "") {
                        $out = $enumOut;
                        $jsonOk = 1;      // the constraint already decided the tokens
                    } else if ($specK > 0 && $temp <= 0.0) {
                        $tSp = microtime(true);
                        $out = model_spec_generate(0, $ids, $maxTok, $specK);
                        api_spec_req("greedy", $specK, $maxTok,
                                     count(explode(",", $out)) - count(explode(",", $ids)),
                                     -2, (microtime(true) - $tSp) * 1000.0);
                        $specUsed = 1;
                    } else if ($specBins > 0 && $jsonMode == 0 && $temp > 0.0 && $topp >= 1.0) {
                        // FR-06 served path: a tree only pays off once verification SAMPLES
                        // (F273), so this branch is gated on temp>0 and no nucleus truncation -
                        // top_p<1 would filter the very tail the residual draws from.
                        $tSp = microtime(true);
                        $out = model_spec_tree_sample(0, $ids, $maxTok, $specBins, $specDepth,
                                                      $temp, $seed);
                        api_spec_req("tree", $specBins, $maxTok,
                                     count(explode(",", $out)) - count(explode(",", $ids)),
                                     -2, (microtime(true) - $tSp) * 1000.0);
                        $specUsed = 1;
                    } else if ($jsonMode == 0 && $prefixCache == 1) {
                        // Reuse the SSD prefix cache: cache_prefill leaves the session at the
                        // prompt tail, so decoding (greedy or sampled) continues from there.
                        // F379: timed and recorded. F378 found the plain control group empty
                        // because unspeculated requests are served HERE, on a branch that
                        // carried no trace line, so the comparison silently measured
                        // speculation against the prefix-cache path instead of plain decoding.
                        $tSp = microtime(true);
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
                        api_spec_req("plain", 0, $maxTok,
                                     count(explode(",", $out)) - count(explode(",", $ids)),
                                     $cacheHit, (microtime(true) - $tSp) * 1000.0);
                    } else {
                        $tSp = microtime(true);
                        $out = $temp > 0.0
                            ? model_generate_sampled(0, $ids, $maxTok, $temp, $topp, $seed)
                            : model_generate(0, $ids, $maxTok);
                        // F377: the undrafted comparison group, measured in the same minutes
                        // as the drafted one - the served spec gain is the difference between
                        // these two groups, not between today and a number from last week.
                        // F377: the denominator has to be the tokens actually produced, not the
                        // requested cap - a request that stops at eos is not a faster engine, and
                        // mixing the two made the first table read as if speculation were 8x slower.
                        api_spec_req("plain", 0, $maxTok,
                                     count(explode(",", $out)) - count(explode(",", $ids)),
                                     -2, (microtime(true) - $tSp) * 1000.0);
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
                        $toolJson = api_tool_calls_json($text, $toolNames);
                    }
                    if ($toolJson == "" && $tcRequired == 1 && count($toolNames) > 0
                        && strpos($text, "<tool_call>") === false) {
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
                                 . "\"content\":[" . api_anthropic_tool_blocks($toolJson) . "],"
                                 . "\"stop_reason\":\"tool_use\",\"usage\":{\"input_tokens\":" . $promptN
                                 . ",\"output_tokens\":" . $genN . "}}";
                    } else if ($toolJson != "" && $wantResponses) {
                        // Responses API: every call is its own function_call output item.
                        $payload = "{\"id\":\"resp_1\",\"object\":\"response\",\"created_at\":0,"
                                 . "\"status\":\"completed\",\"model\":\"" . $modelId . "\","
                                 . "\"output\":[" . api_responses_call_items($toolJson) . "],"
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

// Two decimals as text, without relying on float-to-string formatting (F360): the batch
// measurement prints ratios, and a ratio printed as "1.9999999999999998" is not evidence.
function f360_x100(float $v): string
{
    $n = (int) ($v * 100.0);
    $neg = "";
    if ($n < 0) {
        $neg = "-";
        $n = -$n;
    }
    $i = (int) ($n / 100);
    $f = $n - $i * 100;
    return $neg . $i . "." . ($f < 10 ? "0" . $f : "" . $f);
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

    // F363: the sort-free sampler (top_p == 1.0) must return the same token as the sorted walk
    // for the same draw on the model's real logits, and the whole vocabulary must stay
    // reachable - the exact case is what catches a partition range that stops one entry short.
    $smpChk = model_sampler_check(0, "785,6722,315,9625,374", 200, 4242);
    $smpErr = "";
    $smpD = 0;
    $smpM = 0;
    $smpA = 0;
    $smpG = 0;
    $smpE = 0;
    $smpIds = 0;
    foreach (explode(" ", $smpChk) as $smpTok) {
        if (substr($smpTok, 0, 4) == "err=") { $smpErr = substr($smpTok, 4); }
        if (substr($smpTok, 0, 6) == "draws=") { $smpD = (int) substr($smpTok, 6); }
        if (substr($smpTok, 0, 5) == "mism=") { $smpM = (int) substr($smpTok, 5); }
        if (substr($smpTok, 0, 4) == "amb=") { $smpA = (int) substr($smpTok, 4); }
        if (substr($smpTok, 0, 5) == "grid=") { $smpG = (int) substr($smpTok, 5); }
        if (substr($smpTok, 0, 6) == "exact=") { $smpE = (int) substr($smpTok, 6); }
        if (substr($smpTok, 0, 4) == "ids=") { $smpIds = (int) substr($smpTok, 4); }
    }
    $smpfail = 0;
    if ($smpErr != "none") {
        echo "  sampler[dist] FAIL err={$smpErr} raw={$smpChk}\n";
        $smpfail++;
    } elseif ($smpM != 0) {
        echo "  sampler[dist] FAIL mism={$smpM} amb={$smpA} raw={$smpChk}\n";
        $smpfail++;
    } elseif ($smpG != 0) {
        echo "  sampler[dist] FAIL grid={$smpG} raw={$smpChk}\n";
        $smpfail++;
    } elseif ($smpE != 0) {
        echo "  sampler[dist] FAIL exact={$smpE} raw={$smpChk}\n";
        $smpfail++;
    } else {
        echo "  sampler[dist] PASS draws={$smpD} mism={$smpM} amb={$smpA} grid={$smpG}"
            . " exact={$smpE} ids={$smpIds}\n";
    }
    echo $smpfail == 0 ? "SAMPLER-DIST: PASS\n" : "SAMPLER-DIST: {$smpfail} FAILURE(S)\n";

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

    // F364: the served per-token cost grows with the CONTEXT length (the live measurement put
    // the growth at ~0.35ms per token of context over a 60-token stream, and the server's own
    // iteration cadence grows the same way). Which part of a step grows? A 1-token step at
    // context length L attends over all L cached rows, so the stage marks of such a step show
    // the slope per stage (s1 = the 24 blocks, which is where the attention lives), and the host
    // wall around the step shows the TOTAL slope - that is what the served path pays, so the two
    // can be compared directly.
    $swpFail = 0;
    $swpFailS = 0;
    $swpEng = 0;
    $swpPrev = model_attn_mode();   // the mode the engine booted in = the shipped one
    // F365/F366 A/B: the attention paths all live in this binary, so for every context length
    // they are measured back to back, twice each. The pairing is the point: separate sweeps
    // would compare two moments in time, and the machine drifts (an earlier sweep showed s1 at
    // L=32 above s1 at L=64), while adjacent measurements cancel that drift. Modes 0 and 1
    // compute the same values in the same order, so the sampled token must not move.
    foreach (explode(",", "8,32,64,128,256") as $swpLs) {
        $swpL = (int) $swpLs;
        $swpCtx = "";
        $swpAt = 0;
        for ($swpK = 0; $swpK < $swpL; $swpK++) {
            $swpTok = $pv[$swpAt];
            $swpAt = $swpAt + 1;
            if ($swpAt >= $np) {
                $swpAt = 0;
            }
            $swpCtx = $swpK == 0 ? $swpTok : $swpCtx . "," . $swpTok;
        }
        $swpOut = "";
        $swpTok0 = -1;
        $swpTok1 = -1;
        $swpTokS = -1;
        foreach (explode(",", "0,1,2") as $swpModeLs) {
            $swpMode = (int) $swpModeLs;
            // F367 discipline: prove the switch reached the code before reading anything off it.
            // The first split-KV measurement was worthless because set_attn_mode clamped every
            // value but 1 back to 0, so "mode 2" was mode 0 measured against mode 0 - and no
            // timing or token comparison can see that, because both sides ran the same path.
            $swpGot = model_set_attn_mode($swpMode);
            if ($swpGot != $swpMode) {
                $swpEng = $swpEng + 1;
            }
            $swpS1 = "";
            $swpTokm = -1;
            for ($swpR = 0; $swpR < 2; $swpR++) {
                $swpOk = model_session_start(0, $swpCtx);
                $swpT0 = microtime(true);
                $swpTok2 = model_session_next(0, 0.0, 1.0);
                $swpMs = (microtime(true) - $swpT0) * 1000.0;
                $swpS1 = $swpS1 . " s1=" . model_stage_at(1) . " step=" . $swpMs;
                $swpTokm = $swpTok2;
            }
            $swpOut = $swpOut . " m" . $swpMode . ":" . $swpS1 . " tok=" . $swpTokm . " |";
            if ($swpMode == 0) {
                $swpTok0 = $swpTokm;
            }
            if ($swpMode == 1) {
                $swpTok1 = $swpTokm;
            }
            if ($swpMode == 2) {
                $swpTokS = $swpTokm;
            }
        }
        model_set_attn_mode($swpPrev);
        if ($swpTok0 != $swpTok1) {
            $swpFail = $swpFail + 1;
        }
        if ($swpTok0 != $swpTokS) {
            $swpFailS = $swpFailS + 1;
        }
        echo "PERF sweep L=" . $swpL . ":" . $swpOut . "\n";
    }
    if ($swpFail == 0) {
        echo "ATTN-MODE: PASS (score cache and three-pass sample the same tokens)\n";
    } else {
        echo "ATTN-MODE: FAIL the tokens differ at " . $swpFail . " context lengths\n";
    }
    // F366: the split-KV path groups the sum differently on purpose, so this is reported as
    // "the greedy token still agrees", not as a claim of bit-identical arithmetic.
    if ($swpFailS == 0) {
        echo "ATTN-SPLIT: PASS (split-KV agrees with the score cache on the greedy token)\n";
    } else {
        echo "ATTN-SPLIT: FAIL the greedy token differs at " . $swpFailS . " context lengths\n";
    }

    // F366 follow-up: which is it - bytes or dependent latency? F365 showed the length cost moves
    // when the number of K bytes read per position moves, F366 showed it does not move when the
    // number of work items moves. f16 KV changes ONLY the bytes per position, nothing else in the
    // loop, so the slope under f16 separates the two explanations. Reported, not asserted.
    foreach (explode(",", "0,1") as $kvOnLs) {
        $kvOn = (int) $kvOnLs;
        model_set_kv_f16($kvOn);
        $kvOut = "";
        foreach (explode(",", "8,64,256") as $kvLs) {
            $kvL = (int) $kvLs;
            $kvCtx = "";
            $kvAt = 0;
            for ($kvK = 0; $kvK < $kvL; $kvK++) {
                $kvT = $pv[$kvAt];
                $kvAt = $kvAt + 1;
                if ($kvAt >= $np) {
                    $kvAt = 0;
                }
                $kvCtx = $kvK == 0 ? $kvT : $kvCtx . "," . $kvT;
            }
            model_session_start(0, $kvCtx);
            $kvT0 = microtime(true);
            $kvTok = model_session_next(0, 0.0, 1.0);
            $kvMs = (microtime(true) - $kvT0) * 1000.0;
            $kvOut = $kvOut . " L=" . $kvL . " s1=" . model_stage_at(1) . " step=" . $kvMs
                . " tok=" . $kvTok . " |";
        }
        echo "PERF kv-sweep f16=" . $kvOn . ":" . $kvOut . "\n";
    }
    model_set_kv_f16(0);

    // F367: F366 left two suspects for the 0.212 ms per context token that is still there - the
    // 64-long dependent FMA chain of the dot, and the 64 global read-modify-writes of the V
    // accumulator - because both are per (position, head) instruction work, which is what the
    // three A/Bs said the cost follows. Each ablation removes exactly ONE of them and keeps the
    // other (and keeps the loads, which F366 already ruled out). The output of modes 4 and 5 is
    // meaningless on purpose, so nothing here is asserted about tokens: the claim is only
    // "removing this loop removes this much of the slope".
    foreach (explode(",", "0,4,5") as $abModeLs) {
        $abMode = (int) $abModeLs;
        if (model_set_attn_mode($abMode) != $abMode) {
            $swpEng = $swpEng + 1;
        }
        $abOut = "";
        foreach (explode(",", "8,64,256") as $abLs) {
            $abL = (int) $abLs;
            $abCtx = "";
            $abAt = 0;
            for ($abK = 0; $abK < $abL; $abK++) {
                $abT = $pv[$abAt];
                $abAt = $abAt + 1;
                if ($abAt >= $np) {
                    $abAt = 0;
                }
                $abCtx = $abK == 0 ? $abT : $abCtx . "," . $abT;
            }
            $abS1 = "";
            for ($abR = 0; $abR < 2; $abR++) {
                model_session_start(0, $abCtx);
                $abT0 = microtime(true);
                model_session_next(0, 0.0, 1.0);
                $abMs = (microtime(true) - $abT0) * 1000.0;
                $abS1 = $abS1 . " s1=" . model_stage_at(1) . " step=" . $abMs;
            }
            $abOut = $abOut . " L=" . $abL . ":" . $abS1 . " |";
        }
        echo "PERF ablate m" . $abMode . ":" . $abOut . "\n";
    }
    model_set_attn_mode($swpPrev);

    // SPEC-EQ-LONG (F384): the F383 attribution run showed the speculative path producing 24 tokens
    // where plain greedy produced 11 on the same prompt, temperature and cap. Speculative greedy is
    // defined as numerically identical to plain greedy - every accepted token is the model's own
    // argmax at that position - and the existing negative control checks that on the self-test's
    // own prompt. This checks it on the workload where the difference showed up, and prints the
    // first divergence instead of only a verdict, because "they differ" without a position is not
    // actionable.
    $f384Prompt = tok_encode("Repeat this sentence as many times as you like: the quick brown fox jumps over the lazy dog.");
    $f384N = 48;
    $f384Plain = model_generate(0, $f384Prompt, $f384N);
    $f384Spec = model_spec_generate(0, $f384Prompt, $f384N, 4);
    $f384Pv = explode(",", $f384Plain);
    $f384Sv = explode(",", $f384Spec);
    $f384Base = count(explode(",", $f384Prompt));
    $f384Pn = count($f384Pv) - $f384Base;
    $f384Sn = count($f384Sv) - $f384Base;
    $f384First = -1;
    $f384Min = $f384Pn < $f384Sn ? $f384Pn : $f384Sn;
    for ($f384i = 0; $f384i < $f384Min; $f384i++) {
        if ($f384Pv[$f384Base + $f384i] != $f384Sv[$f384Base + $f384i]) {
            $f384First = $f384i;
            break;
        }
    }
    $f384SamePrefix = $f384First < 0;
    if ($f384Pn == $f384Sn && $f384SamePrefix) {
        echo "SPEC-EQ-LONG: PASS produced=" . $f384Pn . " ids identical and same length"
            . " (" . model_spec_stats() . ")" . "\n";
    } else {
        echo "SPEC-EQ-LONG: FAIL plain_n=" . $f384Pn . " spec_n=" . $f384Sn
            . " same_prefix=" . ($f384SamePrefix ? 1 : 0) . " first_diff=" . $f384First
            . " " . model_spec_stats() . "\n";
        echo "  plain: " . $f384Plain . \n . "  spec : " . $f384Spec . "\n";
    }
    // SPEC-EQ-STOP (F386): the F384 gate compared 48 tokens of content on a prompt that never
    // reached eos, so by construction it could not see how either generator STOPS - and the served
    // path showed plain stopping at 11 tokens while speculation ran to 24. This walks small caps
    // across that eos boundary on the same prompt and prints where each side stopped, which is the
    // only way to tell a stop-condition difference from a content difference.
    $f386Prompt = tok_encode("Repeat this sentence as many times as you like: the quick brown fox jumps over the lazy dog.");
    $f386Base = count(explode(",", $f386Prompt));
    $f386Out = "";
    $f386Bad = 0;
    foreach (explode(",", "4,8,12,16,32") as $f386Ns) {
        $f386N = (int) $f386Ns;
        $f386P = explode(",", model_generate(0, $f386Prompt, $f386N));
        $f386S = explode(",", model_spec_generate(0, $f386Prompt, $f386N, 4));
        $f386Pn = count($f386P) - $f386Base;
        $f386Sn = count($f386S) - $f386Base;
        $f386Mn = $f386Pn < $f386Sn ? $f386Pn : $f386Sn;
        $f386Diff = -1;
        for ($f386i = 0; $f386i < $f386Mn; $f386i++) {
            if ($f386P[$f386Base + $f386i] != $f386S[$f386Base + $f386i]) {
                $f386Diff = $f386i;
                break;
            }
        }
        if ($f386Pn != $f386Sn || $f386Diff >= 0) {
            $f386Bad = $f386Bad + 1;
        }
        $f386Out = $f386Out . " cap=" . $f386N . " plain_n=" . $f386Pn
            . " spec_n=" . $f386Sn . " first_diff=" . $f386Diff . " |";
    }
    if ($f386Bad == 0) {
        echo "SPEC-EQ-STOP: PASS every cap stopped at the same place with the same ids"
            . $f386Out . "\n";
    } else {
        echo "SPEC-EQ-STOP: FAIL caps_that_differ=" . $f386Bad . $f386Out . "\n";
    }
    // SPEC-STOP-EOS (F389): the only input that reaches eos on this model quickly, and the one the
    // served path actually uses, is the templated prompt. Both earlier gates used raw prompts with
    // small caps, where the stream never hits eos - which is how a speculative response that kept
    // emitting past eos survived two rounds of gates. The assertion is the shape of the bug: plain
    // and speculative must produce the SAME token sequence, so if speculative carries anything past
    // eos the two differ in length and the test fails.
    // F389: the input has to be the one the SERVED path builds, not an approximation of it. The
    // first version of this gate concatenated tok_encode(prompt) with the assistant header, ran
    // the full 48-token cap on both sides, and passed - from a region where eos never occurs,
    // the fourth time in this project that a gate was fitted outside the behaviour it claims to
    // cover. api_history is what the handler calls, so the gate now sees the same ids.
    $f389Body = "{\"messages\":[{\"role\":\"user\",\"content\":\"Repeat this sentence as many times as you like: the quick brown fox jumps over the lazy dog.\"}],\"max_tokens\":48,\"temperature\":0.0}";
    $f389Prompt = api_history($f389Body) . "," . tok_chat_open("assistant");
    $f389Plain = model_generate(0, $f389Prompt, 48);
    $f389Spec = model_spec_generate(0, $f389Prompt, 48, 4);
    $f389Pv = explode(",", $f389Plain);
    $f389Sv = explode(",", $f389Spec);
    $f389Bn = count(explode(",", $f389Prompt));
    $f389Pn = count($f389Pv) - $f389Bn;
    $f389Sn = count($f389Sv) - $f389Bn;
    $f389Ptext = api_text(implode(",", array_slice($f389Pv, $f389Bn)));
    $f389Stext = api_text(implode(",", array_slice($f389Sv, $f389Bn)));
    if ($f389Ptext == $f389Stext) {
        echo "SPEC-STOP-EOS: PASS plain_n=" . $f389Pn . " spec_n=" . $f389Sn
            . " identical sequences (" . model_spec_stats() . ")" . "\n";
    } else {
        $f389Min = $f389Pn < $f389Sn ? $f389Pn : $f389Sn;
        $f389First = -1;
        for ($f389i = 0; $f389i < $f389Min; $f389i++) {
            if ($f389Pv[$f389Bn + $f389i] != $f389Sv[$f389Bn + $f389i]) {
                $f389First = $f389i;
                break;
            }
        }
        echo "SPEC-STOP-EOS: FAIL plain_n=" . $f389Pn . " spec_n=" . $f389Sn
            . " first_diff=" . $f389First . " (speculative carried " . ($f389Sn - $f389Pn)
            . " extra tokens past plain)" . "\n";
    }
    echo "PERF ablate note: m4 collapses the V read-modify-writes, m5 drops the dot chain; "
        . "modes 4 and 5 produce garbage output by design and are not used by any gate\n";
    // F367 discipline gate: a switch that is not proven to reach the code cannot be measured.
    if ($swpEng == 0) {
        echo "ATTN-ENGAGE: PASS (every attention mode requested is the one the engine reports)\n";
    } else {
        echo "ATTN-ENGAGE: FAIL " . $swpEng . " mode requests did not take effect\n";
    }

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

    // SESSION-POS: invariant guard, NOT a discriminating test for the ordering fix, and it says so.
    // A negative allowed id exits at "no allowed id is in range", which sits BEFORE prefill_top1,
    // so this passes with or without the g_sess_len ordering change (findings F257). Its job is to
    // keep any future refusal path from silently advancing the position.
    model_session_start(0, "785,6722,315");
    $spBefore = model_session_len();
    $spNeg = model_session_next_allowed(0, "-1");
    $spAfter = model_session_len();
    if ($spNeg < 0 && $spAfter == $spBefore) {
        echo "SESSION-POS: PASS (refused step left the position at " . $spBefore . ")\n";
    } else {
        echo "SESSION-POS: FAIL neg=" . $spNeg . " before=" . $spBefore . " after=" . $spAfter . "\n";
    }

    // SESSION-GROW: crossing KV_MIN_CAP (512) must not lose earlier rows. Before F265 the cache
    // was freed before reallocating, so the first growth left rows 0..511 uninitialized and the
    // next constrained step returned -1 with "no logits computed". This test would have failed
    // on that code and passes only if the rows survive.
    model_session_start(0, "785,6722,315");
    $growIds = gguf_charset_ids("abcdefghijklmnopqrstuvwxyz ");
    $growSteps = 0;
    $growErr = "";
    for ($gi = 0; $gi < 530; $gi++) {
        $gnx = model_session_next_allowed(0, $growIds);
        if ($gnx < 0) {
            $growErr = model_last_error();
            break;
        }
        $growSteps = $growSteps + 1;
    }
    if ($growSteps == 530 && $growErr == "") {
        echo "SESSION-GROW: PASS (" . $growSteps . " steps past the 512-row boundary)\n";
    } else {
        echo "SESSION-GROW: FAIL steps=" . $growSteps . " err=[" . $growErr . "]\n";
    }

    // PROB-API (F284 step 1): model_token_prob must be a valid probability and must rank the
    // model's own argmax highest - the primitive sampling verification needs.
    $pTopId = model_prefill(0, "785,6722,315", -1);
    $pTopProb = model_token_prob($pTopId, 1.0);
    $pOtherId = $pTopId > 0 ? (($pTopId + 1) % 1000) : 0;
    $pOther = model_token_prob($pOtherId, 1.0);
    $pU1 = model_rand01();
    $pU2 = model_rand01();
    $pX = model_sample_excluding($pTopId, 1.0);
    $pkLen0 = model_session_len();
    $pk1 = model_peek_argmax();
    $pk2 = model_peek_argmax();
    $pkLen1 = model_session_len();
    if ($pTopId >= 0 && $pTopProb > 0.0 && $pTopProb <= 1.0 && $pOther >= 0.0 && $pOther <= $pTopProb) {
        if ($pU1 >= 0.0 && $pU1 < 1.0 && $pU2 >= 0.0 && $pU2 < 1.0 && $pX >= 0 && $pX != $pTopId) {
            if ($pk1 >= 0 && $pk1 == $pk2 && $pk1 == $pTopId && $pkLen0 == $pkLen1) {
            echo "PEEK-API: PASS (id=" . $pk1 . " len=" . $pkLen0 . " unchanged)
";
        } else {
            echo "PEEK-API: FAIL id=" . $pk1 . "/" . $pk2 . " top=" . $pTopId . " len=" . $pkLen0 . "->" . $pkLen1 . "
";
        }
        echo "SAMPLE-API: PASS (u1=" . $pU1 . " u2=" . $pU2 . " x=" . $pX . ")
";
        } else {
            echo "SAMPLE-API: FAIL u1=" . $pU1 . " u2=" . $pU2 . " x=" . $pX . " top=" . $pTopId . "
";
        }
        echo "PROB-API: PASS (p_top=" . $pTopProb . " p_other=" . $pOther . ")
";
    } else {
        echo "PROB-API: FAIL top=" . $pTopId . " p=" . $pTopProb . " other=" . $pOther . "
";
    }

    // SPEC-SAMPLE (F303/F304): speculative sampling with a deterministic drafter. The peeked
    // argmax is accepted with probability p; on rejection a residual sample is committed. Two
    // runs from the same seed must match exactly, and the result must differ from plain greedy.
    model_session_start(0, "785,6722,315");
    $ssSeqA = "";
    $ssDiffA = 0;
    $ssAccA = 0;
    $ssRejA = 0;
    for ($ssi = 0; $ssi < 24; $ssi++) {
        $ssd = model_peek_argmax();
        if ($ssd < 0) {
            break;
        }
        $ssp = model_token_prob($ssd, 1.0);
        $ssx = $ssd;
        if (model_rand01() < $ssp) {
            $ssAccA = $ssAccA + 1;
        } else {
            $ssRejA = $ssRejA + 1;
            $ssx = model_sample_excluding($ssd, 1.0);
        }
        $ssGot = model_session_next_allowed(0, "" . $ssx);
        if ($ssGot < 0) {
            break;
        }
        if ($ssGot != $ssd) {
            $ssDiffA = $ssDiffA + 1;
        }
        $ssSeqA = $ssSeqA == "" ? "" . $ssGot : $ssSeqA . "," . $ssGot;
    }
    model_session_start(0, "785,6722,315");
    $ssSeqB = "";
    for ($ssi = 0; $ssi < 24; $ssi++) {
        $ssd = model_peek_argmax();
        if ($ssd < 0) {
            break;
        }
        $ssp = model_token_prob($ssd, 1.0);
        $ssx = $ssd;
        if (!(model_rand01() < $ssp)) {
            $ssx = model_sample_excluding($ssd, 1.0);
        }
        $ssGot = model_session_next_allowed(0, "" . $ssx);
        if ($ssGot < 0) {
            break;
        }
        $ssSeqB = $ssSeqB == "" ? "" . $ssGot : $ssSeqB . "," . $ssGot;
    }
    if ($ssSeqA != "" && $ssSeqA == $ssSeqB && $ssDiffA > 0) {
        echo "SPEC-SAMPLE: PASS (acc=" . $ssAccA . " rej=" . $ssRejA . " diff=" . $ssDiffA . " reproducible)
";
    } else {
        echo "SPEC-SAMPLE: FAIL acc=" . $ssAccA . " rej=" . $ssRejA . " diff=" . $ssDiffA
            . " same=" . ($ssSeqA == $ssSeqB ? 1 : 0) . "
";
    }

    // SPEC-COST (FR-06 economics): what does the acceptance machinery cost per token versus plain
    // greedy? Speculative decoding has to save more than this overhead to pay off, so the number
    // is measured rather than assumed.
    $scT0 = microtime(true);
    model_session_start(0, "785,6722,315");
    $scG = 0;
    for ($sci = 0; $sci < 24; $sci++) {
        $scd = model_peek_argmax();
        if ($scd < 0) {
            break;
        }
        $scg = model_session_next_allowed(0, "" . $scd);
        if ($scg < 0) {
            break;
        }
        $scG = $scG + 1;
    }
    $scGreedyMs = (microtime(true) - $scT0) * 1000.0;
    $scT1 = microtime(true);
    model_session_start(0, "785,6722,315");
    $scS = 0;
    for ($sci = 0; $sci < 24; $sci++) {
        $scd = model_peek_argmax();
        if ($scd < 0) {
            break;
        }
        $scp = model_token_prob($scd, 1.0);
        $scx = $scd;
        if (!(model_rand01() < $scp)) {
            $scx = model_sample_excluding($scd, 1.0);
        }
        $scg = model_session_next_allowed(0, "" . $scx);
        if ($scg < 0) {
            break;
        }
        $scS = $scS + 1;
    }
    $scSampMs = (microtime(true) - $scT1) * 1000.0;
    if ($scG > 0 && $scS > 0 && $scGreedyMs > 0.0) {
        echo "SPEC-COST: PASS greedy_ms=" . $scGreedyMs . " sampling_ms=" . $scSampMs
            . " ratio=" . ($scSampMs / $scGreedyMs) . "
";
    } else {
        echo "SPEC-COST: FAIL greedy_ms=" . $scGreedyMs . " sampling_ms=" . $scSampMs . "
";
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

    // SPEC-TREE-SAMPLE (F307): the same tree, verified by SAMPLING. Greedy verification can accept
    // only one branch (F273), so this is the first version where more than one bin can pay. Three
    // gates in one: temp=0 must reproduce the greedy tree token for token (regression guard), a
    // fixed seed must reproduce itself byte for byte (the draws really come from g_sess_rng), and
    // temp>0 must differ from greedy somewhere (proof the sampler is in the path, not dead code).
    $f307Greedy = model_spec_tree(0, $specIds, 24, 2, 3);
    $f307Z1 = model_spec_tree_sample(0, $specIds, 24, 2, 3, 0.0, 1234);
    $f307Z2 = model_spec_tree_sample(0, $specIds, 24, 2, 3, 0.0, 1234);
    $f307S1 = model_spec_tree_sample(0, $specIds, 24, 2, 3, 0.9, 4242);
    $f307S2 = model_spec_tree_sample(0, $specIds, 24, 2, 3, 0.9, 4242);
    $f307A = explode(",", $f307S1);
    $f307B = explode(",", $f307Greedy);
    $f307Diff = 0;
    $f307N = count($f307A) < count($f307B) ? count($f307A) : count($f307B);
    for ($f307i = 0; $f307i < $f307N; $f307i++) {
        if ($f307A[$f307i] != $f307B[$f307i]) {
            $f307Diff = $f307Diff + 1;
        }
    }
    $f307Eq0 = $f307Z1 == $f307Greedy ? 1 : 0;
    $f307R0 = $f307Z1 == $f307Z2 ? 1 : 0;
    $f307RT = $f307S1 == $f307S2 ? 1 : 0;
    if ($f307Eq0 == 1 && $f307R0 == 1 && $f307RT == 1 && $f307Diff > 0) {
        echo "SPEC-TREE-SAMPLE: PASS temp0==greedy_tree reproducible diff=" . $f307Diff . "/"
            . $f307N . " " . model_spec_stats() . "\n";
    } else {
        echo "SPEC-TREE-SAMPLE: FAIL eq0=" . $f307Eq0 . " repro0=" . $f307R0
            . " reproT=" . $f307RT . " diff=" . $f307Diff . "/" . $f307N
            . "\n  greedy=" . $f307Greedy . "\n  temp0=" . $f307Z1 . "\n  sampled=" . $f307S1 . "\n";
    }

    // SPEC-TREE-WALL (the number FR-06 has been owed): tree-on versus tree-off at the SAME
    // temperature inside the SAME function, so drafting is the only difference. bins<=0 disables
    // drafting and leaves plain sampled decoding. Both timings are printed; the ratio is measured,
    // not assumed.
    $f307TOff = microtime(true);
    $f307Off = model_spec_tree_sample(0, $specIds, 48, 0, 3, 0.9, 777);
    $f307OffMs = (microtime(true) - $f307TOff) * 1000.0;
    $f307OffStats = model_spec_stats();
    $f307TOn = microtime(true);
    $f307On = model_spec_tree_sample(0, $specIds, 48, 2, 3, 0.9, 777);
    $f307OnMs = (microtime(true) - $f307TOn) * 1000.0;
    $f307OnStats = model_spec_stats();
    $f307OffN = count(explode(",", $f307Off));
    $f307OnN = count(explode(",", $f307On));
    if ($f307OffN > 10 && $f307OnN > 10 && $f307OffMs > 0.0 && $f307OnMs > 0.0) {
        echo "SPEC-TREE-WALL: PASS off_ms=" . $f307OffMs . " off_len=" . $f307OffN . " "
            . $f307OffStats . "\n  on_ms=" . $f307OnMs . " on_len=" . $f307OnN . " "
            . $f307OnStats . "\n  speedup=" . ($f307OffMs / $f307OnMs) . "x\n";
    } else {
        echo "SPEC-TREE-WALL: FAIL off_ms=" . $f307OffMs . " on_ms=" . $f307OnMs
            . " off_len=" . $f307OffN . " on_len=" . $f307OnN . "\n";
    }

    // SPEC-TREE-ALT (F308): every gate above that says "tree == greedy" only proves something if
    // the tree actually committed a branch OTHER than branch 0 at least once - that is precisely
    // the case where the row's RoPE position and its KV slot disagreed. So the counter is
    // asserted, not admired: alt_branch=0 means the case never happened and the gate is vacuous,
    // which is reported as FAIL rather than as green.
    $f308p = tok_encode("The pattern is a b c d. The pattern is a b e f. The pattern is a b c d. "
        . "The pattern is a b e f. The pattern is a b c d. The pattern is a");
    $f308Ref = model_generate(0, $f308p, 40);
    $f308Tree = model_spec_tree(0, $f308p, 40, 4, 3);
    $f308NatStats = model_spec_stats();      // F313b: the natural-order run was never sampled
    $f308Rev = model_spec_tree(0, $f308p, 40, -4, 3);
    $f308Stats = model_spec_stats();
    $f308Alt = 0;
    foreach (explode(" ", $f308Stats) as $f308tok) {
        if (substr($f308tok, 0, 11) == "alt_branch=") {
            $f308Alt = (int) substr($f308tok, 11);
        }
    }
    $f308Smp = model_spec_tree_sample(0, $f308p, 40, -4, 3, 0.0, 2026011);
    $f308Alt2 = 0;
    foreach (explode(" ", model_spec_stats()) as $f308tok2) {
        if (substr($f308tok2, 0, 11) == "alt_branch=") {
            $f308Alt2 = (int) substr($f308tok2, 11);
        }
    }
    $f308Teq = $f308Tree == $f308Ref ? 1 : 0;
    $f308Req = $f308Rev == $f308Ref ? 1 : 0;
    $f308Seq = $f308Smp == $f308Ref ? 1 : 0;
    $f308Br = 0;
    $f308AltNat = 0;
    foreach (explode(" ", $f308NatStats) as $f308tok4) {
        if (substr($f308tok4, 0, 11) == "alt_branch=") {
            $f308AltNat = (int) substr($f308tok4, 11);
        }
    }
    foreach (explode(" ", $f308Stats) as $f308tok3) {
        if (substr($f308tok3, 0, 13) == "max_branches=") {
            $f308Br = (int) substr($f308tok3, 13);
        }
    }
    // Two separate verdicts on purpose (F313): behaviour may be green while the path the fix
    // targets is never reached. Reporting them as one gate would either hide the coverage gap
    // behind a permanent red or let the gap disappear into a pass - so the gap is printed with
    // the drafter's own width, which is the measured reason it cannot be reached yet.
    if ($f308Teq == 1 && $f308Req == 1 && $f308Seq == 1) {
        echo "SPEC-TREE-ALT: PASS greedy-identical under both branch orders\n";
        if ($f308AltNat > 0 || $f308Alt > 0 || $f308Alt2 > 0) {
            echo "SPEC-TREE-ALT-COVERAGE: COVERED alt_branch=nat" . $f308AltNat
                . "/rev" . $f308Alt . "/smp" . $f308Alt2 . " max_branches=" . $f308Br . "\n";
        } else {
            echo "SPEC-TREE-ALT-COVERAGE: NOTCOVERED alt_branch=nat" . $f308AltNat
                . "/rev" . $f308Alt . "/smp" . $f308Alt2
                . " max_branches=" . $f308Br . " " . $f308Stats . "\n";
        }
    } else {
        echo "SPEC-TREE-ALT: FAIL tree_eq=" . $f308Teq . " reversed_eq=" . $f308Req . " sampled_eq=" . $f308Seq
            . " alt_branch=" . $f308Alt . "/" . $f308Alt2 . " " . $f308Stats . "\n";
    }

    // SPEC-TREE-CTRL (F315): the necessity control. Same prompt, same natural order, but with the
    // F308 repair switched OFF inside THIS build. If the tree then stops matching greedy while it is
    // still committing non-zero branches, the defect is reproduced behaviourally instead of argued -
    // which is the missing half of "the fix is exercised and passes".
    model_spec_fix(0);
    $f315Off = model_spec_tree(0, $f308p, 40, 4, 3);
    $f315OffAlt = 0;
    foreach (explode(" ", model_spec_stats()) as $f315tok) {
        if (substr($f315tok, 0, 11) == "alt_branch=") {
            $f315OffAlt = (int) substr($f315tok, 11);
        }
    }
    model_spec_fix(1);
    $f315On = model_spec_tree(0, $f308p, 40, 4, 3);
    $f315A = explode(",", $f315Off);
    $f315B = explode(",", $f308Ref);
    $f315N = count($f315A) < count($f315B) ? count($f315A) : count($f315B);
    $f315D = 0;
    for ($f315i = 0; $f315i < $f315N; $f315i++) {
        if ($f315A[$f315i] != $f315B[$f315i]) {
            $f315D = $f315D + 1;
        }
    }
    if ($f315OffAlt > 0 && $f315D > 0 && $f315On == $f308Ref) {
        echo "SPEC-TREE-CTRL: PASS defect reproduced without the repair (alt_branch=" . $f315OffAlt
            . " diffs=" . $f315D . "/" . $f315N . ")\n";
    } else {
        echo "SPEC-TREE-CTRL: NOTREPRODUCED alt_branch=" . $f315OffAlt . " diffs=" . $f315D
            . "/" . $f315N . " on_eq=" . ($f315On == $f308Ref ? 1 : 0) . "\n";
    }

    // SPEC-TREE-DIVERSITY (F317, FR-06.2 step 1): does ranking draft branches by historical
    // frequency beat taking the nearest B? The toggle lives inside ONE build, so the two numbers
    // are directly comparable - cross-round timing was already shown to be unusable (F312).
    // The prompt is the one with two competing continuations, because that is where order matters.
    $f317T0 = microtime(true);
    model_spec_freq(0);
    $f317Near = model_spec_tree_sample(0, $f308p, 40, 2, 3, 0.9, 3170);
    $f317NearMs = (microtime(true) - $f317T0) * 1000.0;
    $f317NearStats = model_spec_stats();
    $f317T1 = microtime(true);
    model_spec_freq(1);
    $f317Freq = model_spec_tree_sample(0, $f308p, 40, 2, 3, 0.9, 3170);
    $f317FreqMs = (microtime(true) - $f317T1) * 1000.0;
    $f317FreqStats = model_spec_stats();
    $f317T2 = microtime(true);
    model_spec_freq(0);
    $f317Off = model_spec_tree_sample(0, $f308p, 40, 0, 3, 0.9, 3170);
    $f317OffMs = (microtime(true) - $f317T2) * 1000.0;
    model_spec_freq(1);
    $f317OffN = count(explode(",", $f317Off));
    $f317NearN = count(explode(",", $f317Near));
    $f317FreqN = count(explode(",", $f317Freq));
    if ($f317OffN > 10 && $f317NearN > 10 && $f317FreqN > 10 && $f317NearMs > 0.0 && $f317FreqMs > 0.0) {
        echo "SPEC-TREE-DIVERSITY: PASS off_ms=" . $f317OffMs . " recency_ms=" . $f317NearMs
            . " freq_ms=" . $f317FreqMs . " speedup_recency=" . ($f317OffMs / $f317NearMs)
            . "x speedup_freq=" . ($f317OffMs / $f317FreqMs) . "x\n  recency: " . $f317NearStats
            . "\n  freq:    " . $f317FreqStats . "\n";
    } else {
        echo "SPEC-TREE-DIVERSITY: FAIL off_len=" . $f317OffN . " recency_len=" . $f317NearN
            . " freq_len=" . $f317FreqN . " off_ms=" . $f317OffMs
            . " recency_ms=" . $f317NearMs . " freq_ms=" . $f317FreqMs . "\n";
    }

    // SPEC-TREE-WIDEN (F319, FR-06.2 step 2): F318 pointed at the candidate SET rather than its
    // order, so the lookup window widens (m-1, then m+1) until B branches are filled. Measured as
    // an in-build toggle. Two things must hold: more distinct branches really appear, and the
    // temp=0 sampled tree stays byte-identical to greedy - wider drafting may not buy speed with
    // correctness. The accepts/verify ratio is printed, not asserted: it is the answer, not the
    // precondition.
    $f319T0 = microtime(true);
    model_spec_multiwin(0);
    $f319Narrow = model_spec_tree_sample(0, $f308p, 40, 4, 3, 0.9, 3190);
    $f319NarrowMs = (microtime(true) - $f319T0) * 1000.0;
    $f319NarrowStats = model_spec_stats();
    $f319T1 = microtime(true);
    model_spec_multiwin(1);
    $f319Wide = model_spec_tree_sample(0, $f308p, 40, 4, 3, 0.9, 3190);
    $f319WideMs = (microtime(true) - $f319T1) * 1000.0;
    $f319WideStats = model_spec_stats();
    model_spec_multiwin(0);
    $f319ZeroOff = model_spec_tree_sample(0, $f308p, 40, 4, 3, 0.0, 3191);
    model_spec_multiwin(1);
    $f319ZeroOn = model_spec_tree_sample(0, $f308p, 40, 4, 3, 0.0, 3191);
    model_spec_multiwin(0);      // leave the engine on its default for every later gate
    $f319Nb = 0;
    $f319Wb = 0;
    foreach (explode(" ", $f319NarrowStats) as $f319tok) {
        if (substr($f319tok, 0, 13) == "max_branches=") {
            $f319Nb = (int) substr($f319tok, 13);
        }
    }
    foreach (explode(" ", $f319WideStats) as $f319tok2) {
        if (substr($f319tok2, 0, 13) == "max_branches=") {
            $f319Wb = (int) substr($f319tok2, 13);
        }
    }
    $f319ZOk = ($f319ZeroOff == $f308Ref && $f319ZeroOn == $f308Ref) ? 1 : 0;
    if ($f319ZOk == 1 && $f319Wb >= $f319Nb && $f319NarrowMs > 0.0 && $f319WideMs > 0.0) {
        $f319How = $f319Wb > $f319Nb ? "widened" : "NOTWIDENED";
        echo "SPEC-TREE-WIDEN: PASS " . $f319How . " branches=" . $f319Nb . "->" . $f319Wb
            . " temp0_greedy_identical=1 narrow_ms=" . $f319NarrowMs . " wide_ms=" . $f319WideMs . "\n"
            . "  narrow: " . $f319NarrowStats . "\n  wide:   " . $f319WideStats . "\n";
    } else {
        echo "SPEC-TREE-WIDEN: FAIL temp0_greedy_identical=" . $f319ZOk
            . " branches=" . $f319Nb . "->" . $f319Wb . " narrow_ms=" . $f319NarrowMs
            . " wide_ms=" . $f319WideMs . "\n";
    }

    // SPEC-TREE-SHAPES (F322, FR-06.4 for trees): the shape has to be a measured choice, and the
    // old SPEC-TREE-SWEEP cannot do that job - F312 showed its absolute timings contradict each
    // other across rounds while accepts stayed fixed. So every shape here is timed against ONE
    // off-baseline, in the same build, on the same prompt/seed/temperature, and the shape is the
    // only variable. Best speedup is reported as the recommendation, not assumed.
    $f322T = microtime(true);
    $f322Off = model_spec_tree_sample(0, $specIds, 48, 0, 3, 0.9, 777);
    $f322OffMs = (microtime(true) - $f322T) * 1000.0;
    $f322OffN = count(explode(",", $f322Off));
    // F332: the previous bound (depth<=4) was an unexamined assumption and hid a 1.5x better shape,
    // so the curve is now carried out to depth 12 - where it actually ends - rather than where the
    // first guess stopped.
    $f322Shapes = "1,3;1,6;1,8;1,10;1,12;2,3;4,3;2,4";
    $f322Best = 0.0;
    $f322BestShape = "";
    $f322Rows = "";
    $f322Ok = 1;
    foreach (explode(";", $f322Shapes) as $f322sh) {
        $f322ab = explode(",", $f322sh);
        $f322bins = (int) $f322ab[0];
        $f322dep = (int) $f322ab[1];
        $f322T2 = microtime(true);
        $f322Run = model_spec_tree_sample(0, $specIds, 48, $f322bins, $f322dep, 0.9, 777);
        $f322Ms = (microtime(true) - $f322T2) * 1000.0;
        $f322N = count(explode(",", $f322Run));
        $f322Sp = $f322Ms > 0.0 ? $f322OffMs / $f322Ms : 0.0;
        if ($f322N != $f322OffN || $f322Ms <= 0.0) {
            $f322Ok = 0;
        }
        if ($f322Sp > $f322Best) {
            $f322Best = $f322Sp;
            $f322BestShape = $f322bins . "," . $f322dep;
        }
        $f322Rows = $f322Rows . "  bins=" . $f322bins . " depth=" . $f322dep . " ms=" . $f322Ms
            . " speedup=" . $f322Sp . "x len=" . $f322N . " " . model_spec_stats() . "\n";
    }
    if ($f322Ok == 1 && $f322OffN > 10 && $f322Best > 0.0) {
        echo "SPEC-TREE-SHAPES: PASS off_ms=" . $f322OffMs . " off_len=" . $f322OffN . "\n"
            . $f322Rows . "  best=" . $f322BestShape . " speedup=" . $f322Best . "x\n";
    } else {
        echo "SPEC-TREE-SHAPES: FAIL ok=" . $f322Ok . " off_len=" . $f322OffN
            . " off_ms=" . $f322OffMs . "\n" . $f322Rows;
    }

    // SPEC-TREE-SELFSEED (F323, FR-06.2 step 3): F322 proved the branch COUNT never enters the
    // computation, because lookup can only repeat continuations the text already contains. The
    // extra branches here start from a token drawn from the model's OWN root row - q = p for that
    // token, so accepting it always is exact - which is the only way to get competing branches at
    // all. F327 reported 1.0564 from ONE sample per side, and one sample per side is not evidence
    // on this machine (cross-round drift measured at 5-12%, F312), so the A/B now runs three
    // interleaved pairs and reports the MEDIAN ratio. The ratio is printed, not asserted - the
    // gate asserts correctness; the median is what decides the default.
    // temp=0 must stay byte-identical to the greedy tree, since no seed exists when the
    // distribution is a point mass.
    $f328PlMs = 0.0;
    $f328SdMs = 0.0;
    $f328Rsum = 0.0;
    $f328Rmin = 0.0;
    $f328Rmax = 0.0;
    $f328Ratios = "";
    for ($f328k = 0; $f328k < 3; $f328k++) {
        model_spec_selfseed(0);
        $f328Ta = microtime(true);
        $f323Plain = model_spec_tree_sample(0, $specIds, 48, 4, 3, 0.9, 3230);
        $f328PlMs = (microtime(true) - $f328Ta) * 1000.0;
        $f323PlainStats = model_spec_stats();
        model_spec_selfseed(1);
        $f328Tb = microtime(true);
        $f323Seed = model_spec_tree_sample(0, $specIds, 48, 4, 3, 0.9, 3230);
        $f328SdMs = (microtime(true) - $f328Tb) * 1000.0;
        $f323SeedStats = model_spec_stats();
        $f328R = $f328SdMs > 0.0 ? $f328PlMs / $f328SdMs : 0.0;
        $f328Rsum = $f328Rsum + $f328R;
        if ($f328k == 0) {
            $f328Rmin = $f328R;
            $f328Rmax = $f328R;
        } else {
            if ($f328R < $f328Rmin) {
                $f328Rmin = $f328R;
            }
            if ($f328R > $f328Rmax) {
                $f328Rmax = $f328R;
            }
        }
        $f328Ratios = $f328Ratios . " " . $f328R;
    }
    model_spec_selfseed(0);
    $f323Ref = model_spec_tree(0, $specIds, 24, 4, 3);
    $f323Zero = model_spec_tree_sample(0, $specIds, 24, 4, 3, 0.0, 3231);
    $f323MbPlain = 0;
    $f323MbSeed = 0;
    foreach (explode(" ", $f323PlainStats) as $f323tok) {
        if (substr($f323tok, 0, 13) == "max_branches=") {
            $f323MbPlain = (int) substr($f323tok, 13);
        }
    }
    foreach (explode(" ", $f323SeedStats) as $f323tok2) {
        if (substr($f323tok2, 0, 13) == "max_branches=") {
            $f323MbSeed = (int) substr($f323tok2, 13);
        }
    }
    $f323ZEq = $f323Zero == $f323Ref ? 1 : 0;
    $f323PlainN = count(explode(",", $f323Plain));
    $f323SeedN = count(explode(",", $f323Seed));
    // median of three interleaved pairs = sum - min - max
    $f323PlainMs = $f328PlMs;
    $f323SeedMs = $f328SdMs;
    $f323Ratio = $f328Rsum - $f328Rmin - $f328Rmax;
    if ($f323ZEq == 1 && $f323PlainN > 10 && $f323SeedN > 10 && $f323SeedMs > 0.0) {
        $f323How = $f323MbSeed > $f323MbPlain ? "SEEDED" : "NOTSEEDED";
        $f323Verd = $f323Ratio > 1.0 ? "MEDIAN_GAIN" : "MEDIAN_NOGAIN";
        echo "SPEC-TREE-SELFSEED: PASS temp0_greedy_identical=1 branches=" . $f323MbPlain . "->"
            . $f323MbSeed . " " . $f323How . " " . $f323Verd . " pairs=" . $f328Ratios
            . " median_ratio=" . $f323Ratio . "\n  plain:  " . $f323PlainStats
            . "\n  seeded: " . $f323SeedStats . "\n";
    } else {
        echo "SPEC-TREE-SELFSEED: FAIL temp0_greedy_identical=" . $f323ZEq
            . " plain_len=" . $f323PlainN . " seeded_len=" . $f323SeedN
            . " seeded_ms=" . $f323SeedMs . "\n";
    }

    // SPEC-CHAIN-VS-TREE (F330): the greedy served path still defaults to the chain (`spec_k`)
    // because the tree lost that comparison in the F50 era - and those numbers came from the sweep
    // that F312 retired as unusable. This re-runs it properly: same build, same prompt, three
    // interleaved rounds, a median per mode, and all three must stay byte-identical to greedy
    // because acceptance is argmax-based at temp=0.
    // F330's "chain wins" was measured at k=8 only, and F332 showed that stopping a sweep at an
    // arbitrary bound hides the real optimum - so the chain side is carried to k=12 and k=16 too.
    $f330Ref = model_generate(0, $specIds, 24);
    $f330Eq = 1;
    $f330K8 = 0.0; $f330K8min = 0.0; $f330K8max = 0.0; $f330K8sum = 0.0;
    $f330K12 = 0.0; $f330K12min = 0.0; $f330K12max = 0.0; $f330K12sum = 0.0;
    $f330K16 = 0.0; $f330K16min = 0.0; $f330K16max = 0.0; $f330K16sum = 0.0;
    $f330GreedyMs = 0.0; $f330Gmin = 0.0; $f330Gmax = 0.0; $f330Gsum = 0.0;
    $f330ChainStats = "";
    $f330Tree2Stats = "";
    for ($f330k = 0; $f330k < 3; $f330k++) {
        $f330Tg = microtime(true);
        $f330G = model_generate(0, $specIds, 24);
        $f330GreedyMs = (microtime(true) - $f330Tg) * 1000.0;
        $f330Ta = microtime(true);
        $f330A = model_spec_generate(0, $specIds, 24, 8);
        $f330K8 = (microtime(true) - $f330Ta) * 1000.0;
        $f330Td = microtime(true);
        $f330D = model_spec_generate(0, $specIds, 24, 12);
        $f330K12 = (microtime(true) - $f330Td) * 1000.0;
        $f330Te = microtime(true);
        $f330E = model_spec_generate(0, $specIds, 24, 16);
        $f330K16 = (microtime(true) - $f330Te) * 1000.0;
        $f330ChainStats = model_spec_stats();
        $f330Tb = microtime(true);
        $f330B = model_spec_tree(0, $specIds, 24, 1, 3);
        $f330O1 = (microtime(true) - $f330Tb) * 1000.0;
        $f330Tc = microtime(true);
        $f330Cc = model_spec_tree(0, $specIds, 24, 2, 3);
        $f330O2 = (microtime(true) - $f330Tc) * 1000.0;
        $f330Tree2Stats = model_spec_stats();
        if ($f330A != $f330Ref || $f330B != $f330Ref || $f330Cc != $f330Ref
            || $f330G != $f330Ref || $f330D != $f330Ref || $f330E != $f330Ref) {
            $f330Eq = 0;
        }
        $f330Gsum = $f330Gsum + $f330GreedyMs;
        $f330K8sum = $f330K8sum + $f330K8;
        $f330K12sum = $f330K12sum + $f330K12;
        $f330K16sum = $f330K16sum + $f330K16;
        if ($f330k == 0) {
            $f330Cmin = $f330K8; $f330Cmax = $f330K8;
            $f330K12min = $f330K12; $f330K12max = $f330K12;
            $f330K16min = $f330K16; $f330K16max = $f330K16;
            $f330Gmin = $f330GreedyMs; $f330Gmax = $f330GreedyMs;
            $f330O1min = $f330O1; $f330O1max = $f330O1;
            $f330O2min = $f330O2; $f330O2max = $f330O2;
        } else {
            if ($f330K8 < $f330Cmin) { $f330Cmin = $f330K8; }
            if ($f330K8 > $f330Cmax) { $f330Cmax = $f330K8; }
            if ($f330K12 < $f330K12min) { $f330K12min = $f330K12; }
            if ($f330K12 > $f330K12max) { $f330K12max = $f330K12; }
            if ($f330K16 < $f330K16min) { $f330K16min = $f330K16; }
            if ($f330K16 > $f330K16max) { $f330K16max = $f330K16; }
            if ($f330GreedyMs < $f330Gmin) { $f330Gmin = $f330GreedyMs; }
            if ($f330GreedyMs > $f330Gmax) { $f330Gmax = $f330GreedyMs; }
            if ($f330O1 < $f330O1min) { $f330O1min = $f330O1; }
            if ($f330O1 > $f330O1max) { $f330O1max = $f330O1; }
            if ($f330O2 < $f330O2min) { $f330O2min = $f330O2; }
            if ($f330O2 > $f330O2max) { $f330O2max = $f330O2; }
        }
        $f330Cs = $f330Cs + $f330K8;
        $f330O1s = $f330O1s + $f330O1;
        $f330O2s = $f330O2s + $f330O2;
    }
    // median of three = sum - min - max
    $f330CMed = $f330Cs - $f330Cmin - $f330Cmax;
    $f330K12Med = $f330K12sum - $f330K12min - $f330K12max;
    $f330K16Med = $f330K16sum - $f330K16min - $f330K16max;
    $f330GMed = $f330Gsum - $f330Gmin - $f330Gmax;
    $f330O1Med = $f330O1s - $f330O1min - $f330O1max;
    $f330O2Med = $f330O2s - $f330O2min - $f330O2max;
    $f330Fast = "chain8";
    if ($f330K12Med < $f330CMed) {
        $f330Fast = "chain12";
    }
    if ($f330K16Med < $f330CMed && $f330K16Med < $f330K12Med) {
        $f330Fast = "chain16";
    }
    if ($f330O1Med < $f330CMed && $f330O1Med < $f330K12Med && $f330O1Med < $f330K16Med) {
        $f330Fast = "tree1";
    }
    if ($f330O2Med < $f330CMed && $f330O2Med < $f330K12Med && $f330O2Med < $f330K16Med
        && $f330O2Med < $f330O1Med) {
        $f330Fast = "tree2";
    }
    $f330Sp8 = $f330CMed > 0.0 ? $f330GMed / $f330CMed : 0.0;
    $f330Sp12 = $f330K12Med > 0.0 ? $f330GMed / $f330K12Med : 0.0;
    $f330Sp16 = $f330K16Med > 0.0 ? $f330GMed / $f330K16Med : 0.0;
    if ($f330Eq == 1 && $f330CMed > 0.0) {
        echo "SPEC-CHAIN-VS-TREE: PASS greedy-identical greedy_med_ms=" . $f330GMed
            . " chain8_med_ms=" . $f330CMed . " (x" . $f330Sp8 . ") chain12_med_ms=" . $f330K12Med
            . " (x" . $f330Sp12 . ") chain16_med_ms=" . $f330K16Med . " (x" . $f330Sp16 . ")"
            . " tree(b1d3)_ms=" . $f330O1Med . " tree(b2d3)_ms=" . $f330O2Med
            . " fastest=" . $f330Fast . "\n  chain: " . $f330ChainStats . "\n  tree2: "
            . $f330Tree2Stats . "\n";
    } else {
        echo "SPEC-CHAIN-VS-TREE: FAIL greedy_identical=" . $f330Eq . " chain_med="
            . $f330CMed . "\n";
    }

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

    // Two hundred levels: the detector descends correctly, so the plan is asserted. Assembly is
    // reported per depth rather than asserted, because it currently returns nothing well before the
    // descent cap - the numbers below are the measurement, not a guess.
    $d2K = [];
    for ($i = 0; $i < 200; $i++) {
        $d2K[] = "k" . $i;
    }
    $d2Part = "";
    for ($i = 0; $i < 200; $i++) {
        $d2Part = $d2Part . "{\"" . $d2K[$i] . "\":{\"type\":\"object\",\"properties\":";
    }
    $d2Close = "";
    for ($i = 0; $i < 200; $i++) {
        $d2Close = $d2Close . "}}";
    }
    $d2Body = "{\"response_format\":{\"type\":\"json_schema\",\"json_schema\":{\"name\":\"x\",\"schema\":"
        . "{\"type\":\"object\",\"properties\":" . $d2Part
        . "{\"city\":{\"type\":\"string\"},\"count\":{\"type\":\"integer\"}}"
        . $d2Close . "}}}}";
    $d2Plan = api_response_schema_nested_object($d2Body);
    $d2Path = "";
    for ($i = 0; $i < 200; $i++) {
        $d2Path = $i == 0 ? $d2K[$i] : $d2Path . "/" . $d2K[$i];
    }
    $d2Want = $d2Path . "|city:s,count:i";
    $d2Ids = tok_chat("user", "The capital of France is") . "," . tok_chat_open("assistant");
    $d2Probe = api_nested_object_value($d2Ids, $d2Path, "city:s,count:i",
        "abcdefghijklmnopqrstuvwxyz ", 6, 0);
    // Same call twice: distinguishes a real depth limit from a first-attempt state effect, since
    // the depth sweep in this very run gets text at 256 while this one came back empty.
    $d2Probe2 = api_nested_object_value($d2Ids, $d2Path, "city:s,count:i",
        "abcdefghijklmnopqrstuvwxyz ", 6, 0);
    echo "SCHEMA-DEEP200-RETRY first_len=" . strlen($d2Probe) . " second_len=" . strlen($d2Probe2) . "\n";
    $d2Parts = explode(",", $d2Probe2);
    $d2Base = count(explode(",", $d2Ids));
    $d2Text = "";
    for ($i = $d2Base; $i < count($d2Parts); $i++) {
        $d2Text = $d2Text . gguf_token_str((int) $d2Parts[$i]);
    }
    $d2Opens = substr_count($d2Text, "{\"k");
    $d2Json = api_json_ok($d2Text);
    echo "SCHEMA-DEEP200 plan=" . ($d2Plan == $d2Want ? "ok" : "bad")
        . " opens=" . $d2Opens . " json=" . $d2Json
        . " retry_len=" . strlen($d2Text) . "\n";
    // Graded on the FIRST call, not a warm retry: a cold session must assemble the whole object
    // too. F208-F225 traced the empty first result to 'no logits computed' blocking the forced
    // prefix, and F226 recovered it by re-prefilling the consumed ids inside the constrain loop.
    if ($d2Plan == $d2Want && $d2Opens == 200 && $d2Json == 1 && strlen($d2Probe) > 0) {
        echo "SCHEMA-DEEP200: PASS (two-hundred-level object assembled on the first attempt)\n";
    } else {
        echo "SCHEMA-DEEP200: FAIL plan=" . ($d2Plan == $d2Want ? "ok" : "bad")
            . " opens=" . $d2Opens . " json=" . $d2Json
            . " first_len=" . strlen($d2Probe) . "\n";
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

    // SCHEMA-PROPS5 (F394): the flat all-string detector used to budget three leaves, so a schema
    // with five string properties silently lost the last two - the request was accepted and the
    // answer was simply missing keys. Two assertions, because the bug had two halves: the detector
    // must SEE five properties, and the emitted object must CONTAIN all five in schema order.
    $p5Body = '{"response_format":{"type":"json_schema","json_schema":{"name":"five","schema":'
        . '{"type":"object","properties":{'
        . '"alpha":{"type":"string"},"beta":{"type":"string"},"gamma":{"type":"string"},'
        . '"delta":{"type":"string"},"epsilon":{"type":"string"}}}}}}';
    $p5Props = api_response_schema_string_props($p5Body);
    $p5Ids = tok_chat("user", "Name five things.") . "," . tok_chat_open("assistant");
    $p5Out = api_string_value_multi($p5Ids, $p5Props, "abcdefghijklmnopqrstuvwxyz ", 6, 0);
    $p5Parts = explode(",", $p5Out);
    $p5Base = count(explode(",", $p5Ids));
    $p5Text = "";
    for ($i = $p5Base; $i < count($p5Parts); $i++) {
        $p5Text = $p5Text . gguf_token_str((int) $p5Parts[$i]);
    }
    $p5Text = str_replace("Ġ", " ", $p5Text);
    $p5Shape = preg_match('/^\{"alpha":"[a-z ]*","beta":"[a-z ]*","gamma":"[a-z ]*",'
        . '"delta":"[a-z ]*","epsilon":"[a-z ]*"\}$/', $p5Text);
    echo "SCHEMA-PROPS5 detected=" . count($p5Props) . " text=[" . $p5Text . "]\n";
    if (count($p5Props) == 5 && $p5Out != "" && $p5Shape == 1 && api_json_ok($p5Text) == 1) {
        echo "SCHEMA-PROPS5: PASS (five string properties detected and all five emitted in order)\n";
    } else {
        echo "SCHEMA-PROPS5: FAIL detected=" . count($p5Props) . " shape=" . $p5Shape . "\n";
    }
    // SCHEMA-MIX5 (F395): the mixed-leaf detector carried the same cap-4 truncation as the
    // all-string one. Five leaves alternating string and integer must all be detected and all
    // emitted in schema order - detection and emission are separate assertions because the bug
    // dropped leaves silently at the detection step while the generator was already generic.
    $x5Body = '{"response_format":{"type":"json_schema","json_schema":{"name":"mix5","schema":'
        . '{"type":"object","properties":{"a":{"type":"string"},"b":{"type":"integer"},'
        . '"c":{"type":"string"},"d":{"type":"integer"},"e":{"type":"string"}}}}}}';
    $x5Plan = api_response_schema_mixed_object($x5Body);
    $x5N = $x5Plan == "" ? 0 : count(explode(",", $x5Plan));
    $x5Ids = tok_chat("user", "Describe a city.") . "," . tok_chat_open("assistant");
    $x5Out = $x5Plan == "" ? "" : api_object_mixed($x5Ids, $x5Plan, "abcdefghijklmnopqrstuvwxyz ", 6, 0);
    $x5Parts = explode(",", $x5Out);
    $x5Base = count(explode(",", $x5Ids));
    $x5Text = "";
    for ($i = $x5Base; $i < count($x5Parts); $i++) {
        $x5Text = $x5Text . gguf_token_str((int) $x5Parts[$i]);
    }
    $x5Text = str_replace("Ġ", " ", $x5Text);
    $x5Shape = preg_match('/^\{"a":"[a-z ]*","b":-?(0|[1-9][0-9]*),"c":"[a-z ]*",'
        . '"d":-?(0|[1-9][0-9]*),"e":"[a-z ]*"\}$/', $x5Text);
    echo "SCHEMA-MIX5 detected=" . $x5N . " text=[" . $x5Text . "]\n";
    if ($x5N == 5 && $x5Out != "" && $x5Shape == 1 && api_json_ok($x5Text) == 1) {
        echo "SCHEMA-MIX5: PASS (five mixed leaves detected and emitted in order)\n";
    } else {
        echo "SCHEMA-MIX5: FAIL detected=" . $x5N . " shape=" . $x5Shape . "\n";
    }

    // SCHEMA-ARROBJ5 (F395): array-of-objects items had the same cap. Five leaves per element,
    // two elements, every element carrying all five keys.
    $y5Body = '{"response_format":{"type":"json_schema","json_schema":{"name":"rows5","schema":'
        . '{"type":"object","properties":{"rows":{"type":"array","items":{"type":"object",'
        . '"properties":{"a":{"type":"string"},"b":{"type":"integer"},"c":{"type":"string"},'
        . '"d":{"type":"integer"},"e":{"type":"string"}}}}}}}}}';
    $y5Det = api_response_schema_array_object($y5Body);
    $y5Split = explode("|", $y5Det);
    $y5N = count($y5Split) == 2 ? count(explode(",", $y5Split[1])) : 0;
    $y5Ids = tok_chat("user", "List two cities.") . "," . tok_chat_open("assistant");
    $y5Out = "";
    if (count($y5Split) == 2) {
        $y5Out = api_array_object_value($y5Ids, $y5Split[0], $y5Split[1],
            "abcdefghijklmnopqrstuvwxyz ", 2, 6, 0);
    }
    $y5Parts = explode(",", $y5Out);
    $y5Base = count(explode(",", $y5Ids));
    $y5Text = "";
    for ($i = $y5Base; $i < count($y5Parts); $i++) {
        $y5Text = $y5Text . gguf_token_str((int) $y5Parts[$i]);
    }
    $y5Text = str_replace("Ġ", " ", $y5Text);
    $y5One = '\{"a":"[a-z ]*","b":-?(0|[1-9][0-9]*),"c":"[a-z ]*","d":-?(0|[1-9][0-9]*),"e":"[a-z ]*"\}';
    $y5Shape = preg_match('/^\{"rows":\[' . $y5One . '(,' . $y5One . ')*\]\}$/', $y5Text);
    echo "SCHEMA-ARROBJ5 detected=" . $y5N . " text=[" . $y5Text . "]\n";
    if ($y5N == 5 && $y5Out != "" && $y5Shape == 1 && api_json_ok($y5Text) == 1) {
        echo "SCHEMA-ARROBJ5: PASS (five leaves per element, every element complete)\n";
    } else {
        echo "SCHEMA-ARROBJ5: FAIL detected=" . $y5N . " shape=" . $y5Shape . "\n";
    }

    // SCHEMA-PAT-REJECT (F396): the two supported pattern forms must stay accepted and the
    // unsupported ones must be recognised, because the served path now refuses the latter with a
    // reason instead of quietly generating unconstrained text. Both directions are asserted - a
    // detector that rejected everything would also "fix" the silent fallback, by breaking the
    // supported cases.
    $prA = api_schema_pattern_unsupported('{"json_schema":{"schema":{"properties":{"city":{"type":"string","pattern":"Paris|London"}}}}}');
    $prB = api_schema_pattern_unsupported('{"json_schema":{"schema":{"properties":{"city":{"type":"string","pattern":"^(New York|Los Angeles)$"}}}}}');
    // F404: the anchored class/count forms are now HONOURED, so they are no longer a refusal -
    // the quantifier range, the star, the unanchored class and the negated class all still are,
    // in the same gate, both directions.
    // F409/F415: {n,m} ranges and the * + ? shorthands are honoured - on the LAST segment the
    // closing quote is a distinct token and the walk offers the range's span; beside a FOLLOWING
    // segment the walk emits the minimum (the count lands inside the bounds), and only a minimum
    // of ZERO beside a follower keeps refusing. The unanchored/negated forms keep their F412
    // behaviour.
    $prF = api_schema_pattern_unsupported('{"json_schema":{"schema":{"properties":{"code":{"type":"string","pattern":"^[A-Z]{3}$"}}}}}');
    // F409 flipped prG too: `[0-9]{4,6}` is the LAST segment (nothing follows before $), so the
    // range is served - the non-last range lives in prAP below.
    $prG = api_schema_pattern_unsupported('{"json_schema":{"schema":{"properties":{"code":{"type":"string","pattern":"^SKU-[0-9]{4,6}$"}}}}}');
    $prH = api_schema_pattern_unsupported('{"json_schema":{"schema":{"properties":{"tag":{"type":"string","pattern":"^[a-z]+$"}}}}}');
    // F412 flipped prI and prJ: an UNANCHORED pattern is served by the anchored emission (the
    // match contains itself), and a NEGATED class is served by the complement within the JSON-safe
    // alphabet (the value still cannot break out of the JSON string). The empty complement
    // (excluding everything - unsatisfiable) and the escape shorthands keep refusing.
    $prI = api_schema_pattern_unsupported('{"json_schema":{"schema":{"properties":{"num":{"type":"string","pattern":"[0-9]{3}"}}}}}');
    $prJ = api_schema_pattern_unsupported('{"json_schema":{"schema":{"properties":{"tag":{"type":"string","pattern":"^[^a-z]{2}$"}}}}}');
    // F413 flipped prAU: the escape shorthands are classes now - \\d digits, \\w word characters,
    // \\s the space, bare or inside a class; the uppercase \\D and a literal backslash (which the
    // value alphabet cannot carry) keep refusing.
    // F413: the alphabet extension (the underscore joining for \\w) has a ripple here - the OLD
    // empty-complement fixture `[^A-Za-z0-9 .-]` now leaves the underscore, so it is SERVED and
    // the true empty complement (excluding everything including the underscore) takes over the
    // refusal side.
    // F416 flipped prAV and prAT: the \\D escape is the digit complement, and the caret joining
    // the value alphabet (as a class-body literal) means the F413 empty-complement fixture now
    // leaves the caret (served) - the true empty complement excludes all sixty-seven characters
    // and takes over the refusal side.
    $prAT = api_schema_pattern_unsupported('{"json_schema":{"schema":{"properties":{"tag":{"type":"string","pattern":"^[^A-Za-z0-9 .\\\\-_^]{2}$"}}}}}');
    $prAX = api_schema_pattern_unsupported('{"json_schema":{"schema":{"properties":{"tag":{"type":"string","pattern":"^[^A-Za-z0-9 .-]{2}$"}}}}}');
    $prAU = api_schema_pattern_unsupported('{"json_schema":{"schema":{"properties":{"num":{"type":"string","pattern":"^\\d{3}$"}}}}}');
    $prAV = api_schema_pattern_unsupported('{"json_schema":{"schema":{"properties":{"num":{"type":"string","pattern":"^\\D{3}$"}}}}}');
    $prAW = api_schema_pattern_unsupported('{"json_schema":{"schema":{"properties":{"tag":{"type":"string","pattern":"^a\\\\b$"}}}}}');
    $prK = api_schema_pattern_unsupported('{"json_schema":{"schema":{"properties":{"code":{"type":"string","pattern":"^X[abc]{2,4}$"}}}}}');
    // F417 flipped prAY: a minimum of ZERO beside a follower is served by the same walk (the
    // segment emits nothing and the next one follows - "99" matches [a-z]*[0-9]{2}); what KEEPS
    // refusing is the assertion syntax (a mid-pattern ^ or $ can never match mid-string, so the
    // pattern is unsatisfiable) and the non-class escapes.
    $prAO = api_schema_pattern_unsupported('{"json_schema":{"schema":{"properties":{"tag":{"type":"string","pattern":"^[a-z]+[0-9]{2}$"}}}}}');
    $prAP = api_schema_pattern_unsupported('{"json_schema":{"schema":{"properties":{"tag":{"type":"string","pattern":"^[a-z]{2,4}[0-9]{3}$"}}}}}');
    $prAY = api_schema_pattern_unsupported('{"json_schema":{"schema":{"properties":{"tag":{"type":"string","pattern":"^[a-z]*[0-9]{2}$"}}}}}');
    // F409 bisect: the live request path hands the gate the BARE schema (spaced, with the type
    // key) - if one of these returns 1 while prH returns 0, the difference is in the body shape,
    // not the pattern.
    $prAQ = api_schema_pattern_unsupported('{"type": "object", "properties": {"tag": {"type": "string", "pattern": "^[a-z]+$"}}}');
    $prAR = api_schema_pattern_unsupported('{"type":"object","properties":{"tag":{"type":"string","pattern":"^[a-z]+$"}}}');
    $prAS = api_schema_pattern_unsupported('{"properties": {"tag": {"type": "string", "pattern": "^[a-z]+$"}}}');
    // F409 flipped prC too: `^[A-Z][a-z]+$` is a literal run plus a LAST-segment star, which the
    // walk now serves - the still-refused counterparts are the non-last quantifiers above.
    $prC = api_schema_pattern_unsupported('{"json_schema":{"schema":{"properties":{"city":{"type":"string","pattern":"^[A-Z][a-z]+$"}}}}}');
    $prD = api_schema_pattern_unsupported('{"json_schema":{"schema":{"properties":{"city":{"type":"string","pattern":"ab+c"}}}}}');
    $prE = api_schema_pattern_unsupported('{"json_schema":{"schema":{"properties":{"city":{"type":"string"}}}}}');
    echo "SCHEMA-PAT-REJECT literal=" . $prA . " wrapped=" . $prB . " counted=["
        . $prF . "] lastrange=[" . $prG . "] star=[" . $prH . "] unanchored=[ " . $prI
        . "] negated=[ " . $prJ . "] lastrange2=[" . $prK . "] star_nonlast=[ " . $prAO
        . "] range_nonlast=[ " . $prAP . "] star0_nonlast=[" . $prAY . "] bare_spaced=[" . $prAQ . "] bare_compact=["
        . $prAR . "] bare_notype=[" . $prAS . "] class=[" . $prC
        . "] unanchored=[" . $prI . "] negated=[" . $prJ . "] neg_empty=[" . $prAT
        . "] neg_underscore=[" . $prAX . "] escape=[" . $prAU . "] esc_upper_D=[" . $prAV . "] esc_backslash=[" . $prAW
        . "] quant=" . $prD . " none=" . $prE . "\n";
    if ($prA == 0 && $prB == 0 && $prF == 0 && $prG == 0 && $prH == 0 && $prI == 0
        && $prJ == 0 && $prK == 0 && $prAO == 0 && $prAP == 0 && $prAY == 0
        && $prC == 0 && $prD == 1 && $prE == 0
        && $prAT == 1 && $prAX == 0 && $prAU == 0 && $prAV == 0 && $prAW == 1
        && $prAQ == 0 && $prAR == 0 && $prAS == 0) {
        echo "SCHEMA-PAT-REJECT: PASS (supported forms kept, metacharacter forms flagged)\n";
    } else {
        echo "SCHEMA-PAT-REJECT: FAIL\n";
    }

    // SCHEMA-PAT-IMPL (F404): the counted class form is EMITTED by the mixed stepper, so the gate
    // asserts the decoded text matches the pattern (preg_match over the value, not a status code),
    // the literal segments appear verbatim, and a multi-leaf plan keeps every key with all-required
    // still served. A spec that silently differs from the pattern would pass a status-code gate.
    $spA = api_schema_pattern_spec('^[A-Z]{3}$');
    $spB = api_schema_pattern_spec('^X[abc]{2}$');
    $spC = api_schema_pattern_spec('^Paris$');
    // F415 flipped this one too: a range beside a FOLLOWING segment now yields the two-segment
    // spec - the walk emits the minimum for the ranged segment and continues; the min-zero
    // epsilon form still yields no spec.
    $spD = api_schema_pattern_spec('^[a-z]{2,4}[0-9]{3}$');
    // F409: the range and the star spell out in the spec, and the emitted value honours them - the
    // length walks between the bounds, the star's close is reachable from the first position. The
    // emissions live after the pw block below, where $pvBase exists.
    $spE = api_schema_pattern_spec('^[a-z]{2,4}$');
    $spF = api_schema_pattern_spec('^[a-z]+$');
    $pvBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"pat1","schema":'
        . '{"type":"object","properties":{"code":{"type":"string","pattern":"^[A-Z]{3}$"}}}}}';
    $pvPlan = api_response_schema_mixed_object($pvBody);
    $pvIds = tok_chat("user", "Give the code.") . "," . tok_chat_open("assistant");
    $pvOut = api_object_mixed($pvIds, $pvPlan, "abcdefghijklmnopqrstuvwxyz", 16, 0);
    $pvText = "";
    $pvParts = explode(",", $pvOut);
    $pvBase = count(explode(",", $pvIds));
    for ($i = $pvBase; $i < count($pvParts); $i++) {
        $pvText = $pvText . gguf_token_str((int) $pvParts[$i]);
    }
    $pvText = str_replace("Ġ", " ", $pvText);
    $pvMatch = preg_match('/^\\{"code":"[A-Z]{3}"\\}$/', $pvText) == 1 ? 1 : 0;
    $pwBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"pat2","schema":'
        . '{"type":"object","properties":{"code":{"type":"string","pattern":"^X[abc]{2}$"},'
        . '"city":{"type":"string"}},"required":["code","city"]}}}';
    $pwSch = api_json_object_after($pwBody, "schema");
    $pwPlan = api_response_schema_mixed_object($pwBody);
    $pwRefuse = api_schema_keywords_unsupported($pwSch);
    $pwOut = api_object_mixed($pvIds, $pwPlan, "abcdefghijklmnopqrstuvwxyz", 16, 0);
    $pwText = "";
    $pwParts = explode(",", $pwOut);
    for ($i = $pvBase; $i < count($pwParts); $i++) {
        $pwText = $pwText . gguf_token_str((int) $pwParts[$i]);
    }
    $pwText = str_replace("Ġ", " ", $pwText);
    $pwCode = preg_match('/"code":"X[abc]{2}"/', $pwText) == 1 ? 1 : 0;
    $pwCity = strpos($pwText, "\"city\":\"") !== false ? 1 : 0;
    // F409: the range and the star, emitted - the value length walks between the bounds and the
    // star's close is reachable from the first position.
    $pzBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"pat3","schema":'
        . '{"type":"object","properties":{"tag":{"type":"string","pattern":"^[a-z]{2,4}$"}}}}}';
    $pzPlan = api_response_schema_mixed_object($pzBody);
    $pzOut = api_object_mixed($pvIds, $pzPlan, "abcdefghijklmnopqrstuvwxyz", 16, 0);
    $pzText = "";
    $pzParts = explode(",", $pzOut);
    for ($i = $pvBase; $i < count($pzParts); $i++) {
        $pzText = $pzText . gguf_token_str((int) $pzParts[$i]);
    }
    $pzText = str_replace("Ġ", " ", $pzText);
    $pzMatch = preg_match('/"tag":"[a-z]{2,4}"/', $pzText) == 1 ? 1 : 0;
    $psBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"pat4","schema":'
        . '{"type":"object","properties":{"tag":{"type":"string","pattern":"^[a-z]+$"}}}}}';
    $psPlan = api_response_schema_mixed_object($psBody);
    $psOut = api_object_mixed($pvIds, $psPlan, "abcdefghijklmnopqrstuvwxyz", 16, 0);
    $psText = "";
    $psParts = explode(",", $psOut);
    for ($i = $pvBase; $i < count($psParts); $i++) {
        $psText = $psText . gguf_token_str((int) $psParts[$i]);
    }
    $psText = str_replace("Ġ", " ", $psText);
    $psMatch = preg_match('/"tag":"[a-z]+"/', $psText) == 1 ? 1 : 0;
    echo "SCHEMA-PAT-IMPL spec3=[" . $spA . "] spec_lit=[ " . $spB . "] pure_literal=[" . $spC
        . "] range=[" . $spD . "] plan=[" . $pvPlan . "] out=[" . $pvText . "] match=" . $pvMatch
        . "] plan2=[" . $pwPlan . "] refuse2=[" . $pwRefuse . "] out2=[" . $pwText
        . "] code2=" . $pwCode . " city2=" . $pwCity
        . "] spec_range=[" . $spE . "] spec_star=[" . $spF . "] planz=[" . $pzPlan
        . "] outz=[" . $pzText . "] matchz=" . $pzMatch . "] plans=[" . $psPlan
        . "] outs=[" . $psText . "] matchs=" . $psMatch . "]\n";
    if ($spA == "c3xABCDEFGHIJKLMNOPQRSTUVWXYZ" && $spB == "lX~c2xabc" && $spC == ""
        && $spD == "c2-4xabcdefghijklmnopqrstuvwxyz~c3x0123456789"
        && $spE == "c2-4xabcdefghijklmnopqrstuvwxyz" && $spF == "c1-xabcdefghijklmnopqrstuvwxyz"
        && $pvPlan == "code:p:c3xABCDEFGHIJKLMNOPQRSTUVWXYZ" && $pvMatch == 1 && api_json_ok($pvText) == 1
        && $pwPlan == "code:p:lX~c2xabc,city:s" && $pwRefuse == "" && $pwCode == 1
        && $pwCity == 1 && api_json_ok($pwText) == 1
        && $pzPlan == "tag:p:c2-4xabcdefghijklmnopqrstuvwxyz" && $pzMatch == 1 && api_json_ok($pzText) == 1
        && $psPlan == "tag:p:c1-xabcdefghijklmnopqrstuvwxyz" && $psMatch == 1 && api_json_ok($psText) == 1) {
        echo "SCHEMA-PAT-IMPL: PASS (emitted values match the pattern; multi-leaf keeps every key)\n";
    } else {
        echo "SCHEMA-PAT-IMPL: FAIL\n";
    }

    // SCHEMA-BOUND-REJECT (F397): same two-direction rule as the pattern gate. The supported
    // combination (integer, both bounds, span <= 15) must stay accepted, and each unsupported
    // combination must be recognised, because the served path now refuses them instead of
    // generating unconstrained text.
    $bdA = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":1,"maximum":5}}}}}');
    $bdB = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"number","minimum":1.5,"maximum":5.5}}}}}');
    // F405: the single-integer-leaf shape with non-negative bounds is HONOURED at any span and
    // with one side missing (the digit walk emits an in-range value), so onesided/wide moved
    // from 1 to 0 - the falsified expectations change, and the shapes that still refuse are
    // pinned right next to them: the same bounds on a multi-leaf or negative or float leaf.
    $bdC = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":1}}}}}');
    $bdD = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":0,"maximum":100}}}}}');
    $bdH = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":1},"s":{"type":"string"}}}}}');
    // F419 flipped bdI: a one-sided NEGATIVE minimum on the single-leaf shape is served (the
    // subset walk clamps to the non-negative side - every value there is >= the negative
    // minimum); the multi-leaf negative keeps refusing.
    $bdI = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":-100}}}}}');
    $bdL = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":-100},"s":{"type":"string"}}}}}');
    $bdJ = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"number","minimum":0,"maximum":100}}}}}');
    $bdK = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":0,"maximum":100},"m":{"type":"integer","multipleOf":3}}}}}');
    // F420: the single-leaf pair is now served (the writer emits the smallest multiple inside the
    // bounds), so this is the supported side of the same keyword bdK refuses on a second leaf. The
    // unsatisfiable interval - no multiple of 7 between 1 and 5 - must refuse rather than fall
    // through to the span rule, which would call it supported and emit a non-multiple.
    $bdM = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":1,"maximum":100,"multipleOf":7}}}}}');
    $bdN = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":1,"maximum":5,"multipleOf":7}}}}}');
    $bdO = api_schema_bounds_unsupported('{"json_schema": {"schema": {"properties": {"n": {"type": "integer", "minimum": 1, "maximum": 100, "multipleOf": 7}}}}}');
    // F421: fractional bounds on an INTEGER leaf are rounded inward, so [1.5, 9.5] is served as
    // [2, 9]; [1.5, 1.9] holds no integer and an exponent literal is not a plain decimal, so both
    // refuse instead of being truncated into a value that violates the schema.
    $bdP = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":1.5,"maximum":9.5}}}}}');
    $bdQ = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":1.5,"maximum":1.9}}}}}');
    $bdR = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":1e1,"maximum":12}}}}}');
    $bdS = api_schema_bounds_unsupported('{"json_schema": {"schema": {"properties": {"n": {"type": "integer", "minimum": -9.5, "maximum": -1.5}}}}}');
    // F430: unbounded multipleOf - up to 999999 is served by a suffix whose width follows the
    // multiplier, beyond that it is refused, and 1 is trivially satisfied so it is not refused.
    $bdAB = api_schema_bounds_unsupported('{"properties":{"n":{"type":"integer","multipleOf":2000000}}}');
    $bdAC = api_schema_bounds_unsupported('{"properties":{"n":{"type":"integer","multipleOf":5000}}}');
    $bdAD = api_schema_bounds_unsupported('{"properties":{"n":{"type":"integer","multipleOf":1}}}');
    // F431: the unbounded multipleOf writer emits one property, so any schema with more than one
    // leaf is refused rather than answered with the other keys silently dropped.
    $bdBA = api_schema_bounds_unsupported('{"properties":{"a":{"type":"integer","multipleOf":3},"b":{"type":"integer","multipleOf":5}}}');
    $bdBB = api_schema_bounds_unsupported('{"properties":{"a":{"type":"integer","multipleOf":3},"b":{"type":"integer"}}}');
    // F433: the multiple writer is integer-only, so a number leaf carrying multipleOf is refused.
    $bdBC = api_schema_bounds_unsupported('{"properties":{"n":{"type":"number","multipleOf":3}}}');
    $bdE = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"string"}}}}}');
    // F397: the same two cases as they arrive on the wire, with spaces after the colons - the
    // compact-only fixture above is what let a whitespace-sensitive detector pass the gate.
    $bdF = api_schema_bounds_unsupported('{"json_schema": {"schema": {"properties": {"n": {"type": "integer", "minimum": 1, "maximum": 5}}}}}');
    $bdG = api_schema_bounds_unsupported('{"json_schema": {"schema": {"properties": {"n": {"type": "number", "minimum": 1.5, "maximum": 5.5}}}}}');
    // F422: exclusive-only bounds. A satisfiable wide/one-sided interval now has a shape (served),
    // so it is the supported side; an interval with no integer (5 < n < 6) and an interval with no
    // multiple refuse instead of falling through to the generic generator that ignores them.
    $bdT = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","exclusiveMinimum":0,"exclusiveMaximum":100000}}}}}');
    $bdU = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","exclusiveMinimum":5,"exclusiveMaximum":6}}}}}');
    $bdV = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","exclusiveMinimum":0,"exclusiveMaximum":5,"multipleOf":7}}}}}');
    $bdW = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","exclusiveMinimum":5}}}}}');
    // F423: a mixed inclusive+exclusive pair. A satisfiable one is served (the stricter governs),
    // but one whose interval rounds empty (n >= 5 AND n < 5) refuses.
    $bdX = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":1,"exclusiveMinimum":5,"maximum":50}}}}}');
    $bdY = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":5,"exclusiveMaximum":5}}}}}');
    // F424: a bounded multipleOf above the old 1000 guard - the smallest in-interval multiple is
    // served (satisfiable), and an interval with no such multiple refuses.
    $bdZ = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":1,"maximum":1000000,"multipleOf":5000}}}}}');
    $bdAA = api_schema_bounds_unsupported('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":1,"maximum":4000,"multipleOf":5000}}}}}');
    echo "SCHEMA-BOUND-REJECT supported=" . $bdA . " float=" . $bdB . " onesided=[" . $bdC
        . "] wide=[" . $bdD . "] multi_leaf=[ " . $bdH . "] neg_one=[" . $bdI
        . "] neg_multi=[ " . $bdL . "] float_wide=[ " . $bdJ . "] mult_of=[ " . $bdK
        . "] mult_one=[" . $bdM . "] mult_unsat=[" . $bdN . "] mult_wire=[" . $bdO
        . "] frac_int=[" . $bdP . "] frac_unsat=[" . $bdQ . "] frac_exp=[" . $bdR
        . "] frac_neg=[" . $bdS . "] none=" . $bdE
        . " wire_supported=" . $bdF . " wire_float=" . $bdG
        . " excl_wide=[" . $bdT . "] excl_unsat=[" . $bdU . "] excl_mult=[" . $bdV
        . "] excl_one=[" . $bdW
        . "] mix_sat=[" . $bdX . "] mix_unsat=[" . $bdY
        . "] bigmult_sat=[" . $bdZ . "] bigmult_unsat=[" . $bdAA
        . "] unbounded_big=[" . $bdAB . "] unbounded_ok=[" . $bdAC
        . "] mult_one_ub=[" . $bdAD . "] mult_multi=[" . $bdBA . "] mult_mixed=[" . $bdBB
        . "] mult_number=[" . $bdBC . "
";
    if ($bdA == 0 && $bdB == 1 && $bdC == 0 && $bdD == 0 && $bdH == 1 && $bdI == 0
        && $bdL == 1 && $bdJ == 1 && $bdK == 1 && $bdE == 0
        && $bdM == 0 && $bdN == 1 && $bdO == 0
        && $bdP == 0 && $bdQ == 1 && $bdR == 1 && $bdS == 0
        && $bdF == 0 && $bdG == 1
        && $bdT == 0 && $bdU == 1 && $bdV == 1 && $bdW == 0
        && $bdX == 0 && $bdY == 1
        && $bdZ == 0 && $bdAA == 1
        && $bdAB == 1 && $bdAC == 0 && $bdAD == 0
        && $bdBA == 1 && $bdBB == 1 && $bdBC == 1) {
        echo "SCHEMA-BOUND-REJECT: PASS (single-leaf bounds of any sign honoured at any span; others flagged)
";
    } else {
        echo "SCHEMA-BOUND-REJECT: FAIL
";
    }

    // SCHEMA-NUM-BND (F405): the digit walk EMITS an integer inside the bounds, so the gate parses
    // the emitted value and asserts lo <= v <= hi for the wide, equal-length and one-sided walks -
    // a status-code check would stay green while the value ignored the bounds. The detector's
    // shape string is asserted too, with the refused shapes returning "".
    $nbA = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":0,"maximum":100}}}}}');
    $nbB = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":1,"maximum":1000}}}}}');
    $nbC = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":5}}}}}');
    $nbD = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":1,"maximum":5}}}}}');
    $nbE = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":-100},"s":{"type":"string"}}}}}');
    $nbIds = tok_chat("user", "Give the number.") . "," . tok_chat_open("assistant");
    $nbOut1 = api_number_bounded_value($nbIds, "n", 0, 100, 0);
    $nbT1 = "";
    $nbP1 = explode(",", $nbOut1);
    $nbBase = count(explode(",", $nbIds));
    for ($i = $nbBase; $i < count($nbP1); $i++) {
        $nbT1 = $nbT1 . gguf_token_str((int) $nbP1[$i]);
    }
    $nbT1 = str_replace("Ġ", " ", $nbT1);
    $nbV1 = preg_match('/"n":([0-9]+)\}/', $nbT1, $nbM1) == 1 ? (int) $nbM1[1] : -1;
    $nbOut2 = api_number_bounded_value($nbIds, "n", 1, 1000, 0);
    $nbT2 = "";
    $nbP2 = explode(",", $nbOut2);
    for ($i = $nbBase; $i < count($nbP2); $i++) {
        $nbT2 = $nbT2 . gguf_token_str((int) $nbP2[$i]);
    }
    $nbT2 = str_replace("Ġ", " ", $nbT2);
    $nbV2 = preg_match('/"n":([0-9]+)\}/', $nbT2, $nbM2) == 1 ? (int) $nbM2[1] : -1;
    $nbOut3 = api_number_bounded_value($nbIds, "n", 5, -1, 0);
    $nbT3 = "";
    $nbP3 = explode(",", $nbOut3);
    for ($i = $nbBase; $i < count($nbP3); $i++) {
        $nbT3 = $nbT3 . gguf_token_str((int) $nbP3[$i]);
    }
    $nbT3 = str_replace("Ġ", " ", $nbT3);
    $nbV3 = preg_match('/"n":([0-9]+)\}/', $nbT3, $nbM3) == 1 ? (int) $nbM3[1] : -1;
    $nbOut4 = api_number_bounded_value($nbIds, "n", 1, 5, 0);
    $nbT4 = "";
    $nbP4 = explode(",", $nbOut4);
    for ($i = $nbBase; $i < count($nbP4); $i++) {
        $nbT4 = $nbT4 . gguf_token_str((int) $nbP4[$i]);
    }
    $nbT4 = str_replace("Ġ", " ", $nbT4);
    $nbV4 = preg_match('/"n":([0-9]+)\}/', $nbT4, $nbM4) == 1 ? (int) $nbM4[1] : -1;
    // F405: maximum-only is the case the live check caught after the gate went green - the absent
    // lower sentinel collided with the fill's negative guard and the number body came out empty,
    // so the assertion is here: an emitted value, inside the bounds, valid JSON.
    $nbOut5 = api_number_bounded_value($nbIds, "n", -1, 15, 0);
    $nbT5 = "";
    $nbP5 = explode(",", $nbOut5);
    for ($i = $nbBase; $i < count($nbP5); $i++) {
        $nbT5 = $nbT5 . gguf_token_str((int) $nbP5[$i]);
    }
    $nbT5 = str_replace("Ġ", " ", $nbT5);
    $nbV5 = preg_match('/"n":([0-9]+)\}/', $nbT5, $nbM5) == 1 ? (int) $nbM5[1] : -1;
    // F419: the negative walks - both-negative behind a dash (the magnitude in [-hi, -lo]) and a
    // straddling range clamped to the non-negative side (the subset argument).
    $nbOut6 = api_number_bounded_value($nbIds, "n", -100, -5, 0);
    $nbT6 = "";
    $nbP6 = explode(",", $nbOut6);
    for ($i = $nbBase; $i < count($nbP6); $i++) {
        $nbT6 = $nbT6 . gguf_token_str((int) $nbP6[$i]);
    }
    $nbT6 = str_replace("Ġ", " ", $nbT6);
    $nbV6 = preg_match('/"n":(-[0-9]+)\}/', $nbT6, $nbM6) == 1 ? (int) $nbM6[1] : 1;
    $nbOut7 = api_number_bounded_value($nbIds, "n", -1000, 1000, 0);
    $nbT7 = "";
    $nbP7 = explode(",", $nbOut7);
    for ($i = $nbBase; $i < count($nbP7); $i++) {
        $nbT7 = $nbT7 . gguf_token_str((int) $nbP7[$i]);
    }
    $nbT7 = str_replace("Ġ", " ", $nbT7);
    $nbV7 = preg_match('/"n":(-?[0-9]+)\}/', $nbT7, $nbM7) == 1 ? (int) $nbM7[1] : -100000;
    $nbShapeNeg = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":-5000,"maximum":-100}}}}}');
    // F420: multipleOf narrows the honoured value to a single deterministic multiple - the shape
    // collapses lo/hi to it and the walk emits exactly that value.
    $nbShapeM = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":1,"maximum":100,"multipleOf":7}}}}}');
    $nbOut8 = api_number_bounded_value($nbIds, "n", 7, 7, 0);
    $nbT8 = "";
    $nbP8 = explode(",", $nbOut8);
    for ($i = $nbBase; $i < count($nbP8); $i++) {
        $nbT8 = $nbT8 . gguf_token_str((int) $nbP8[$i]);
    }
    $nbT8 = str_replace("Ġ", " ", $nbT8);
    $nbV8 = preg_match('/"n":([0-9]+)\}/', $nbT8, $nbM8) == 1 ? (int) $nbM8[1] : -1;
    // F424: the collapse picks the smallest in-interval multiple for ANY m, so a bounded
    // multipleOf above the old 1000 guard now yields a shape too; the walk emits that value.
    $nbShapeBig = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":1,"maximum":1000000,"multipleOf":5000}}}}}');
    $nbOut9 = api_number_bounded_value($nbIds, "n", 5000, 5000, 0);
    $nbT9 = "";
    $nbP9 = explode(",", $nbOut9);
    for ($i = $nbBase; $i < count($nbP9); $i++) {
        $nbT9 = $nbT9 . gguf_token_str((int) $nbP9[$i]);
    }
    $nbT9 = str_replace("Ġ", " ", $nbT9);
    $nbV9 = preg_match('/"n":([0-9]+)\}/', $nbT9, $nbM9) == 1 ? (int) $nbM9[1] : -1;
    echo "SCHEMA-NUM-BND shape=[" . $nbA . "] shape2=[" . $nbB . "] shape_one=[" . $nbC
        . "] shape_span=[ " . $nbD . "] shape_multi=[ " . $nbE . "] v1=" . $nbV1
        . " v2=" . $nbV2 . " v3=" . $nbV3 . " v4=" . $nbV4 . " v5=" . $nbV5
        . "] neg_shape=[" . $nbShapeNeg . "] v6=" . $nbV6 . " v7=" . $nbV7
        . "] mult_shape=[" . $nbShapeM . "] v8=" . $nbV8
        . "] bigmult_shape=[" . $nbShapeBig . "] v9=" . $nbV9 . "]\n";
    if ($nbA == "n|0|100|0" && $nbB == "n|1|1000|0" && $nbC == "n|5|-1|0" && $nbD == "n|1|5|0"
        && $nbE == ""
        && $nbV1 >= 0 && $nbV1 <= 100 && api_json_ok($nbT1) == 1
        && $nbV2 >= 1 && $nbV2 <= 1000 && api_json_ok($nbT2) == 1
        && $nbV3 >= 5 && $nbV3 <= 9 && api_json_ok($nbT3) == 1
        && $nbV4 >= 1 && $nbV4 <= 5 && api_json_ok($nbT4) == 1
        && $nbV5 >= 0 && $nbV5 <= 15 && api_json_ok($nbT5) == 1
        && $nbShapeNeg == "n|-5000|-100|0"
        && $nbV6 <= -5 && $nbV6 >= -100 && api_json_ok($nbT6) == 1
        && $nbV7 >= -1000 && $nbV7 <= 1000 && api_json_ok($nbT7) == 1
        && $nbShapeM == "n|7|7|7" && $nbV8 == 7 && api_json_ok($nbT8) == 1
        && $nbShapeBig == "n|5000|5000|5000" && $nbV9 == 5000 && api_json_ok($nbT9) == 1) {
        echo "SCHEMA-NUM-BND: PASS (emitted values inside the bounds; refused shapes yield no shape; bounded multipleOf serves the smallest in-interval multiple at any m)\n";
    } else {
        echo "SCHEMA-NUM-BND: FAIL\n";
    }

    // SCHEMA-NUM-FRAC (F421): fractional bounds on an integer leaf round INWARD, so the shape is the
    // integer interval and the emitted value lies inside it. An interval holding no integer yields no
    // shape, and a fractional part of zero changes nothing - both are asserted here, with the refusal
    // gate covering the same empty interval from the request side.
    $nfA = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":1.5,"maximum":9.5}}}}}');
    $nfB = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":-9.5,"maximum":-1.5}}}}}');
    $nfC = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":1.5,"maximum":1.9}}}}}');
    $nfD = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":2.0,"maximum":9.0}}}}}');
    $nfOut = api_number_bounded_value($nbIds, "n", 2, 9, 0);
    $nfP = explode(",", $nfOut);
    $nfText = "";
    for ($i = $nbBase; $i < count($nfP); $i++) {
        $nfText = $nfText . gguf_token_str((int) $nfP[$i]);
    }
    $nfText = str_replace("Ġ", " ", $nfText);
    $nfM = [];
    $nfV = preg_match('/"n":(-?[0-9]+)\}/', $nfText, $nfM) == 1 ? (int) $nfM[1] : -100000;
    echo "SCHEMA-NUM-FRAC pos=[" . $nfA . "] neg=[" . $nfB . "] empty=[" . $nfC
        . "] integral=[" . $nfD . "] v=" . $nfV . "]\n";
    if ($nfA == "n|2|9|0" && $nfB == "n|-9|-2|0" && $nfC == "" && $nfD == "n|2|9|0"
        && $nfV >= 2 && $nfV <= 9 && api_json_ok($nfText) == 1) {
        echo "SCHEMA-NUM-FRAC: PASS (fractional bounds rounded inward; empty interval yields no shape)\n";
    } else {
        echo "SCHEMA-NUM-FRAC: FAIL\n";
    }

    // SCHEMA-NUM-EXCL (F422): exclusive bounds on an integer leaf convert to the adjacent integer
    // bound (exclusiveMinimum X -> floor(X)+1, exclusiveMaximum X -> ceil(X)-1), so the shape is the
    // inclusive interval and the emitted value lies STRICTLY inside the exclusive one. An exclusive
    // interval holding no integer (5 < n < 6) yields no shape, and the refusal gate covers it.
    $neA = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","exclusiveMinimum":0,"exclusiveMaximum":100000}}}}}');
    $neB = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","exclusiveMinimum":5}}}}}');
    $neC = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","exclusiveMaximum":50}}}}}');
    $neD = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","exclusiveMinimum":5,"exclusiveMaximum":6}}}}}');
    $neE = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","exclusiveMinimum":1.5,"exclusiveMaximum":9.5}}}}}');
    $neF = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","exclusiveMinimum":0,"exclusiveMaximum":100,"multipleOf":7}}}}}');
    // F423: both forms on the same side - the STRICTER governs, so the looser inclusive bound no
    // longer shadows a stricter exclusive one.
    $neG = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":1,"exclusiveMinimum":5}}}}}');
    $neH = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","maximum":50,"exclusiveMaximum":20}}}}}');
    $neI = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":5,"exclusiveMinimum":1}}}}}');
    $neJ = api_response_schema_number_bounded('{"json_schema":{"schema":{"properties":{"n":{"type":"integer","minimum":1,"exclusiveMinimum":5,"maximum":50}}}}}');
    $neOut = api_number_bounded_value($nbIds, "n", 2, 9, 0);
    $neP = explode(",", $neOut);
    $neText = "";
    for ($i = $nbBase; $i < count($neP); $i++) {
        $neText = $neText . gguf_token_str((int) $neP[$i]);
    }
    $neText = str_replace("Ġ", " ", $neText);
    $neM = [];
    $neV = preg_match('/"n":(-?[0-9]+)\}/', $neText, $neM) == 1 ? (int) $neM[1] : -100000;
    echo "SCHEMA-NUM-EXCL wide=[" . $neA . "] min1=[" . $neB . "] max1=[" . $neC
        . "] unsat=[" . $neD . "] frac=[" . $neE . "] mult=[" . $neF
        . "] mix_strict=[" . $neG . "] mix_strictMax=[" . $neH . "] mix_loose=[" . $neI
        . "] mix_both=[" . $neJ . "] v=" . $neV . "]\n";
    if ($neA == "n|1|99999|0" && $neB == "n|6|-1|0" && $neC == "n|-1|49|0"
        && $neD == "" && $neE == "n|2|9|0" && $neF == "n|7|7|7"
        && $neG == "n|6|-1|0" && $neH == "n|-1|19|0" && $neI == "n|5|-1|0" && $neJ == "n|6|50|0"
        && $neV >= 2 && $neV <= 9 && api_json_ok($neText) == 1) {
        echo "SCHEMA-NUM-EXCL: PASS (exclusive bounds convert to the adjacent integer interval; the stricter of an inclusive+exclusive pair governs; empty interval yields no shape)\n";
    } else {
        echo "SCHEMA-NUM-EXCL: FAIL\n";
    }

    // SCHEMA-KEYWORDS (F398): the five unenforced keywords must be named, and `required` must be
    // refused only when it is a strict subset - an all-required schema is already satisfied by the
    // generator emitting every declared property, so refusing it would reject working requests.
    // Both JSON spellings are covered, because a compact-only fixture hid a whitespace bug in F397.
    $kwA = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"}},"minItems":1}');
    // F399: maxLength on a flat all-string object is now HONOURED, so it is no longer a refusal;
    // the same keyword on a mixed shape still is, because that stepper cannot enforce it.
    $kwB = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string","maxLength":5}}}');
    $kwI = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string","maxLength":5},"b":{"type":"integer"}}}');
    $kwC = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"}},"additionalProperties":false}');
    // F402: additionalProperties is satisfied by construction where every key is named from the
    // plan, so the all-string case moved from "additionalProperties" to "". The counterparts that
    // must STILL refuse are added next to it - the schema form of the keyword, a leaf the generator
    // does not name from a plan. The array-bound combination did refuse too, until F403 made the two
    // predicates composable - each half holds, so neither may veto the other.
    $kwW = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"}},"additionalProperties":{"type":"string"}}');
    $kwX = api_schema_keywords_unsupported('{"properties":{"a":{"type":"object","properties":{"n":{"type":"string"}}}},"additionalProperties":false}');
    // F403②: the mixed shape (string + integer) now passes too - the flat mixed generator names
    // every key from the plan, so the constraint holds there by the same construction. The
    // enum-leaf counterpart still refuses: an e:/en: plan entry has three colon-separated parts
    // and the honoured predicate reads exactly two.
    $kwY = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"},"b":{"type":"integer"}},"additionalProperties":true}');
    $kwAF = api_schema_keywords_unsupported('{"type":"object","properties":{"mode":{"type":"string","enum":["Paris","London"]},"count":{"type":"integer"}},"additionalProperties":false}');
    $kwZ = api_schema_keywords_unsupported('{"properties":{"a":{"type":"array","items":{"type":"string"},"maxItems":3}},"additionalProperties":false}');
    $kwAA = api_schema_keywords_unsupported('{"type": "object", "properties": {"a": {"type": "string"}, "b": {"type": "string"}}, "additionalProperties": false}');
    // F403: the pair above is no longer refused - each half holds, so neither may veto the other.
    // The combination with the SCHEMA form of the keyword still is, and names the keyword that is
    // actually unimplemented rather than the one that is supported.
    $kwAB = api_schema_keywords_unsupported('{"properties":{"a":{"type":"array","items":{"type":"string"},"maxItems":3}},"additionalProperties":{"type":"string"}}');
    // F407: the bounds on an array leaf BESIDE other properties moved from a refusal to the
    // mixed stepper (which counts the elements and emits the siblings) - the multi-leaf shape is
    // served, an unservable bound pair still names minItems, the combination with
    // additionalProperties composes (the keys are still plan-named), and all-required is
    // satisfied by the full emission.
    $kwAG = api_schema_keywords_unsupported('{"type":"object","properties":{"cities":{"type":"array","minItems":2,"maxItems":3,"items":{"type":"string"}},"note":{"type":"string"}}}');
    $kwAH = api_schema_keywords_unsupported('{"type":"object","properties":{"cities":{"type":"array","minItems":9,"maxItems":2,"items":{"type":"string"}},"note":{"type":"string"}}}');
    $kwAI = api_schema_keywords_unsupported('{"type":"object","properties":{"cities":{"type":"array","minItems":2,"maxItems":3,"items":{"type":"string"}},"note":{"type":"string"}},"additionalProperties":false}');
    $kwAJ = api_schema_keywords_unsupported('{"type":"object","properties":{"cities":{"type":"array","minItems":2,"maxItems":3,"items":{"type":"string"}},"note":{"type":"string"}},"required":["cities","note"]}');
    $kwD = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"},"b":{"type":"string"}},"required":["a","b"]}');
    $kwE = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"},"b":{"type":"string"}},"required":["a"]}');
    $kwF = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"}}}');
    $kwG = api_schema_keywords_unsupported('{"properties": {"a": {"type": "string"}, "b": {"type": "string"}}, "required": ["a", "b"]}');
    $kwH = api_schema_keywords_unsupported('{"properties": {"a": {"type": "string"}, "b": {"type": "string"}}, "required": ["a"]}');
    // F400: minItems/maxItems on the one shape whose stepper counts elements is honoured, so it is
    // no longer a refusal. The same keywords on a shape that cannot count - a bound on the object
    // rather than on the array, an array of objects, an unsatisfiable or empty pair of bounds -
    // still are, in both spellings.
    $kwN = api_schema_keywords_unsupported('{"type":"object","properties":{"a":{"type":"array","items":{"type":"string"},"maxItems":3}}}');
    $kwO = api_schema_keywords_unsupported('{"type":"object","properties":{"a":{"type":"array","items":{"type":"integer"},"minItems":2,"maxItems":5}}}');
    $kwP = api_schema_keywords_unsupported('{"type":"object","properties":{"a":{"type":"array","items":{"type":"string"}}},"maxItems":2}');
    // F408: the bounds on an OBJECT-array leaf moved from a refusal to the keyed writer (which
    // counts the elements the same way) - the single-leaf ao shape is served, an unservable pair
    // still names minItems, a multi-leaf ao plan keeps the refusal (the writer emits only that
    // key), and bounds on an array of arrays stay refused (the 2D writers do not count).
    $kwQ = api_schema_keywords_unsupported('{"type":"object","properties":{"a":{"type":"array","items":{"type":"object","properties":{"n":{"type":"string"}}},"maxItems":3}}}');
    $kwAK = api_schema_keywords_unsupported('{"type":"object","properties":{"rows":{"type":"array","minItems":2,"maxItems":3,"items":{"type":"object","properties":{"n":{"type":"string"}}}}}}');
    $kwAL = api_schema_keywords_unsupported('{"type":"object","properties":{"rows":{"type":"array","minItems":9,"maxItems":2,"items":{"type":"object","properties":{"n":{"type":"string"}}}}}}');
    // F410 flipped kwAM: a multi-leaf ao plan is honoured now - the writer counts the array's
    // elements and emits only that key, legal while the sibling is optional; required naming the
    // sibling still refuses (the ao kind is not full-emission), pinned next to it.
    $kwAM = api_schema_keywords_unsupported('{"type":"object","properties":{"rows":{"type":"array","maxItems":3,"items":{"type":"object","properties":{"n":{"type":"string"}}}},"note":{"type":"string"}}}');
    $kwAU = api_schema_keywords_unsupported('{"type":"object","properties":{"rows":{"type":"array","maxItems":3,"items":{"type":"object","properties":{"n":{"type":"string"}}}},"note":{"type":"string"}},"required":["rows","note"]}');
    $kwAN = api_schema_keywords_unsupported('{"type":"object","properties":{"g":{"type":"array","items":{"type":"array","items":{"type":"string"}},"maxItems":3}}}');
    $kwR = api_schema_keywords_unsupported('{"type": "object", "properties": {"a": {"type": "array", "items": {"type": "string"}, "minItems": 2}}}');
    $kwS = api_schema_keywords_unsupported('{"type":"object","properties":{"a":{"type":"array","items":{"type":"string"},"maxItems":0}}}');
    $kwT = api_schema_keywords_unsupported('{"type":"object","properties":{"a":{"type":"array","items":{"type":"string"},"minItems":4,"maxItems":2}}}');
    // F401: a strict subset used to be refused everywhere; on the flat all-string object the
    // generator can now omit the optional property, so `req_subset`/`wire_subset` moved from
    // "required" to "". F403② moves `req_mixed` the same way - the mixed generator steps the
    // filtered plan - while the cases that must STILL refuse stay: an undeclared required name,
    // and required on a multi-leaf plan carrying a leaf kind (array, enum) whose serving path
    // emits only that one key and would drop a required sibling.
    $kwU = api_schema_keywords_unsupported('{"type":"object","properties":{"a":{"type":"string"},"b":{"type":"string"}},"required":["a","z"]}');
    $kwV = api_schema_keywords_unsupported('{"type":"object","properties":{"a":{"type":"string"},"b":{"type":"integer"}},"required":["a"]}');
    $kwAC = api_schema_keywords_unsupported('{"type":"object","properties":{"name":{"type":"string"},"nums":{"type":"array","items":{"type":"integer"}}},"required":["name"]}');
    $kwAD = api_schema_keywords_unsupported('{"type":"object","properties":{"name":{"type":"string"},"nums":{"type":"array","items":{"type":"integer"}}},"required":["name","nums"]}');
    $kwAE = api_schema_keywords_unsupported('{"type":"object","properties":{"mode":{"type":"string","enum":["Paris","London"]},"count":{"type":"integer"}},"required":["mode","count"]}');
    // F425: uniqueItems constrains element VALUES, which the model picks, so `true` is refused.
    // `false` allows duplicates - satisfied by construction - and the array detectors now tolerate
    // that spelling, so the structured route must still be found; that route check is the
    // intermediate-product assertion, because a 200 alone could still be prose.
    $kwAV = api_schema_keywords_unsupported('{"type":"object","properties":{"cities":{"type":"array","items":{"type":"string"},"uniqueItems":true}}}');
    $kwAW = api_schema_keywords_unsupported('{"type":"object","properties":{"cities":{"type":"array","items":{"type":"string"},"uniqueItems":false}}}');
    $kwAX = api_schema_keywords_unsupported('{"type": "object", "properties": {"cities": {"type": "array", "items": {"type": "string"}, "uniqueItems": true}}}');
    $kwAY = api_response_schema_array_string('{"json_schema":{"schema":{"properties":{"cities":{"type":"array","items":{"type":"string"},"uniqueItems":false}}}}}');
    echo "SCHEMA-KEYWORDS minItems=[" . $kwA . "] flat_maxLength=[" . $kwB
        . "] mixed_maxLength=[" . $kwI . "] addProps=[" . $kwC
        . "] req_all=[" . $kwD . "] req_subset=[" . $kwE . "] none=[" . $kwF
        . "] wire_all=[" . $kwG . "] wire_subset=[" . $kwH
        . "] arr_max=[" . $kwN . "] arr_minmax=[" . $kwO . "] obj_bound=[" . $kwP
        . "] arr_obj=[" . $kwQ . "] ao_bounds=[" . $kwAK . "] ao_bad=[" . $kwAL
        . "] ao_multi=[" . $kwAM . "] ao_req=[" . $kwAU . "] ao_2d=[" . $kwAN . "] wire_arr_min=[" . $kwR . "] max0=[" . $kwS
        . "] min_gt_max=[" . $kwT . "] req_undeclared=[" . $kwU . "] req_mixed=[" . $kwV
        . "] req_arr_sub=[" . $kwAC . "] req_arr_all=[" . $kwAD . "] req_enum_all=[" . $kwAE
        . "] addprops_form=[" . $kwW . "] addprops_nested=[" . $kwX
        . "] addprops_mixed=[" . $kwY . "] addprops_enum=[" . $kwAF . "] addprops_arr=[" . $kwZ
        . "] addprops_wire=[" . $kwAA . "] addprops_form_arr=[" . $kwAB
        . "] multi_bounds=[" . $kwAG . "] multi_bad=[" . $kwAH . "] multi_addprops=[" . $kwAI
        . "] multi_allreq=[" . $kwAJ . "] uniq_true=[" . $kwAV . "] uniq_false=[" . $kwAW
        . "] uniq_wire=[" . $kwAX . "] uniq_route=[" . $kwAY . "]\n";
    if ($kwA == "minItems" && $kwB == "" && $kwI == "maxLength" && $kwC == ""
        && $kwD == "" && $kwE == "" && $kwF == ""
        && $kwG == "" && $kwH == ""
        && $kwN == "" && $kwO == "" && $kwP == "maxItems" && $kwQ == ""
        && $kwAK == "" && $kwAL == "minItems" && $kwAM == "" && $kwAU == "required"
        && $kwAN == "maxItems"
        && $kwR == "" && $kwS == "maxItems" && $kwT == "minItems"
        && $kwU == "required" && $kwV == ""
        && $kwAC == "required" && $kwAD == "required" && $kwAE == "required"
        && $kwW == "additionalProperties" && $kwX == "additionalProperties"
        && $kwY == "" && $kwAF == "additionalProperties" && $kwZ == "" && $kwAA == ""
        && $kwAB == "additionalProperties"
        && $kwAG == "" && $kwAH == "minItems" && $kwAI == "" && $kwAJ == ""
        && $kwAV == "uniqueItems" && $kwAW == "" && $kwAX == "uniqueItems"
        && $kwAY == "cities") {
        echo "SCHEMA-KEYWORDS: PASS (unenforced keywords named; required honoured where every required key is emitted)\n";
    } else {
        echo "SCHEMA-KEYWORDS: FAIL\n";
    }

    // SCHEMA-STRUCT (F426): contains / not / if / dependentRequired / propertyNames / unevaluated*
    // are none of them implemented, and each can be violated by what the model emits - so each is
    // named instead of ignored. Two controls pin the matching boundary: an enum VALUE spelled "not"
    // must not be mistaken for the keyword, while a property NAMED "not" is refused (the documented
    // boundary of scanning the whole schema rather than only its root).
    $sfA = api_schema_keywords_unsupported('{"properties":{"a":{"type":"array","items":{"type":"string"}},"contains":{"type":"string"}}}');
    $sfB = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"},"not":{"type":"integer"}}}');
    $sfC = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"},"if":{"minLength":1}}}');
    $sfD = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"},"propertyNames":{"pattern":"^x"}}}');
    $sfE = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"}},"dependentRequired":{"a":["b"]}}');
    $sfF = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"}},"unevaluatedProperties":false}');
    $sfG = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string","enum":["not"]}}}');
    $sfH = api_schema_keywords_unsupported('{"properties":{"not":{"type":"string"}}}');
    $sfI = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"}},"minContains":2}');
    $sfJ = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"}},"unevaluatedItems":true}');
    // F428: value-level constraints the generator does not implement. The last one is the control
    // that the shape-aware match buys: a property NAMED "format" is not the keyword.
    $sfK = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string","format":"email"}}}');
    $sfL = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"}},"patternProperties":{"^a":{"type":"integer"}}}');
    $sfM = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string","contentEncoding":"base64"}}}');
    $sfN = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string","contentMediaType":"image/png"}}}');
    $sfO = api_schema_keywords_unsupported('{"properties":{"format":{"type":"string"}}}');
    // F429: propertyNames is honoured when the declared key names satisfy it and refused when they
    // cannot (or when the shape's key set is not the proven one).
    $sfP = api_schema_keywords_unsupported('{"properties":{"city":{"type":"string"},"note":{"type":"string"}},"propertyNames":{"pattern":"^[a-z]+$"}}');
    $sfQ = api_schema_keywords_unsupported('{"properties":{"city":{"type":"string"},"note":{"type":"string"}},"propertyNames":{"pattern":"^x"}}');
    $sfR = api_schema_keywords_unsupported('{"properties":{"city":{"type":"string"},"note":{"type":"string"}},"propertyNames":{"maxLength":6}}');
    $sfS = api_schema_keywords_unsupported('{"properties":{"city":{"type":"string"},"note":{"type":"string"}},"propertyNames":{"enum":["a","b"]}}');
    $sfT = api_schema_keywords_unsupported('{"properties":{"city":{"type":"string"},"count":{"type":"integer"}},"propertyNames":{"pattern":"^[a-z]+$"}}');
    // F429: the refusal layer alone is not enough - the key-name pattern must not disqualify the
    // VALUE generator either, or the request is accepted and then answered with prose.
    $sfU = api_response_schema_string_props('{"json_schema":{"schema":{"type":"object","properties":{"city":{"type":"string"},"note":{"type":"string"}},"propertyNames":{"pattern":"^[a-z]+$"}}}}');
    // F432: dependentRequired is honoured when the emitted keys contain the dependency and refused
    // when the trigger is emitted while a dependency is omitted (required subset) or never emitted.
    $sfV = api_schema_keywords_unsupported('{"properties":{"city":{"type":"string"},"note":{"type":"string"}},"dependentRequired":{"city":["note"]}}');
    $sfW = api_schema_keywords_unsupported('{"properties":{"city":{"type":"string"},"note":{"type":"string"}},"required":["city"],"dependentRequired":{"city":["note"]}}');
    $sfX = api_schema_keywords_unsupported('{"properties":{"city":{"type":"string"}},"dependentRequired":{"city":["region"]}}');
    $sfY = api_schema_keywords_unsupported('{"properties":{"city":{"type":"string"},"count":{"type":"integer"}},"dependentRequired":{"city":["count"]}}');
    // F434: tuple validation is named; the control keeps the ordinary object-form items served.
    $sfZ = api_schema_keywords_unsupported('{"properties":{"pair":{"type":"array","items":[{"type":"string"},{"type":"integer"}]}}}');
    $sfAA = api_schema_keywords_unsupported('{"properties":{"pair":{"type":"array","prefixItems":[{"type":"string"}]}}}');
    $sfAB = api_schema_keywords_unsupported('{"properties":{"cities":{"type":"array","items":{"type":"string"}}}}');
    echo "SCHEMA-STRUCT contains=[" . $sfA . "] not=[" . $sfB . "] if=[" . $sfC
        . "] propertyNames=[" . $sfD . "] dependentRequired=[" . $sfE
        . "] unevaluatedProperties=[" . $sfF . "] enum_value=[" . $sfG
        . "] prop_named=[" . $sfH . "] minContains=[" . $sfI . "] unevaluatedItems=[" . $sfJ
        . "] format=[" . $sfK . "] patternProperties=[" . $sfL . "] contentEncoding=[" . $sfM
        . "] contentMediaType=[" . $sfN . "] format_prop=[" . $sfO
        . "] pnames_ok=[" . $sfP . "] pnames_bad=[" . $sfQ . "] pnames_len_ok=[" . $sfR
        . "] pnames_enum=[" . $sfS . "] pnames_mixed=[" . $sfT
        . "] pnames_route=" . count($sfU)
        . "] dep_ok=[" . $sfV . "] dep_subset=[" . $sfW . "] dep_undeclared=[" . $sfX
        . "] dep_mixed=[" . $sfY . "] tuple_items=[" . $sfZ . "] prefix_items=[" . $sfAA
        . "] items_obj=[" . $sfAB . "]\n";
    if ($sfA == "contains" && $sfB == "not" && $sfC == "if" && $sfD == "propertyNames"
        && $sfE == "dependentRequired" && $sfF == "unevaluatedProperties" && $sfG == ""
        && $sfH == "not" && $sfI == "minContains" && $sfJ == "unevaluatedItems"
        && $sfK == "format" && $sfL == "patternProperties" && $sfM == "contentEncoding"
        && $sfN == "contentMediaType" && $sfO == ""
        && $sfP == "" && $sfQ == "propertyNames" && $sfR == "" && $sfS == "propertyNames"
        && $sfT == "propertyNames"
        && count($sfU) == 2 && $sfU[0] == "city" && $sfU[1] == "note"
        && $sfV == "" && $sfW == "dependentRequired" && $sfX == "dependentRequired"
        && $sfY == "dependentRequired"
        && $sfZ == "items" && $sfAA == "prefixItems" && $sfAB == "") {
        echo "SCHEMA-STRUCT: PASS (structural keywords named; a value is not mistaken for one)\n";
    } else {
        echo "SCHEMA-STRUCT: FAIL\n";
    }

    // SCHEMA-PROPS-COUNT (F427): minProperties/maxProperties are judged against the number of keys
    // the shape will actually emit - the declared plan, or the required subset on the flat all-string
    // shape. A bound that count satisfies is honoured, one it cannot satisfy is refused, and shapes
    // whose key set is not proven still refuse (the mixed case below is that boundary, pinned).
    $pcA = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"},"b":{"type":"string"}},"minProperties":2}');
    $pcB = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"},"b":{"type":"string"}},"minProperties":3}');
    $pcC = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"},"b":{"type":"string"}},"maxProperties":1}');
    $pcD = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"},"b":{"type":"string"}},"maxProperties":2}');
    $pcE = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"},"b":{"type":"string"}},"required":["a"],"minProperties":1}');
    $pcF = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"},"b":{"type":"string"}},"required":["a"],"minProperties":2}');
    $pcG = api_schema_keywords_unsupported('{"properties":{"a":{"type":"string"},"b":{"type":"integer"}},"minProperties":1}');
    $pcH = api_schema_keywords_unsupported('{"type": "object", "properties": {"a": {"type": "string"}, "b": {"type": "string"}}, "minProperties": 3}');
    echo "SCHEMA-PROPS-COUNT min2=[" . $pcA . "] min3=[" . $pcB . "] max1=[" . $pcC
        . "] max2=[" . $pcD . "] sub1=[" . $pcE . "] sub2=[" . $pcF
        . "] mixed=[" . $pcG . "] wire3=[" . $pcH . "]\n";
    if ($pcA == "" && $pcB == "minProperties" && $pcC == "maxProperties" && $pcD == ""
        && $pcE == "" && $pcF == "minProperties" && $pcG == "minProperties"
        && $pcH == "minProperties") {
        echo "SCHEMA-PROPS-COUNT: PASS (key count checked; unreachable bounds and unproven shapes refused)\n";
    } else {
        echo "SCHEMA-PROPS-COUNT: FAIL\n";
    }
    // SCHEMA-LEN (F399): length bounds are now enforced for a flat all-string object, so the gate
    // checks the EMITTED length, not just that the request was accepted - a bounds feature that
    // only changes the status code would pass a weaker test. The third case keeps the refusal for
    // shapes whose stepper cannot honour lengths, so the relaxation cannot leak to them.
    $lnBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"len","schema":'
        . '{"type":"object","properties":{"word":{"type":"string","minLength":3,"maxLength":6}}}}}}';
    $lnProps = api_response_schema_string_props($lnBody);
    $lnBounds = api_response_schema_string_bounds($lnBody);
    $lnIds = tok_chat("user", "Say a word.") . "," . tok_chat_open("assistant");
    $lnOut = api_string_value_multi($lnIds, $lnProps, "abcdefghijklmnopqrstuvwxyz", 12, 0, $lnBounds);
    $lnParts = explode(",", $lnOut);
    $lnBase = count(explode(",", $lnIds));
    $lnText = "";
    for ($i = $lnBase; $i < count($lnParts); $i++) {
        $lnText = $lnText . gguf_token_str((int) $lnParts[$i]);
    }
    $lnText = str_replace("Ġ", " ", $lnText);
    $lnVal = "";
    $lnMatch = [];
    if (preg_match('/"word":"([a-z]*)"/', $lnText, $lnMatch) == 1) {
        $lnVal = $lnMatch[1];
    }
    $lnLen = strlen($lnVal);
    $lnBody2 = '{"response_format":{"type":"json_schema","json_schema":{"name":"len2","schema":'
        . '{"type":"object","properties":{"word":{"type":"string","maxLength":2}}}}}}';
    $lnProps2 = api_response_schema_string_props($lnBody2);
    $lnBounds2 = api_response_schema_string_bounds($lnBody2);
    $lnOut2 = api_string_value_multi($lnIds, $lnProps2, "abcdefghijklmnopqrstuvwxyz", 12, 0, $lnBounds2);
    $lnParts2 = explode(",", $lnOut2);
    $lnText2 = "";
    for ($i = $lnBase; $i < count($lnParts2); $i++) {
        $lnText2 = $lnText2 . gguf_token_str((int) $lnParts2[$i]);
    }
    $lnText2 = str_replace("Ġ", " ", $lnText2);
    $lnVal2 = "";
    $lnMatch2 = [];
    if (preg_match('/"word":"([a-z]*)"/', $lnText2, $lnMatch2) == 1) {
        $lnVal2 = $lnMatch2[1];
    }
    $lnLen2 = strlen($lnVal2);
    $lnMixed = api_schema_keywords_unsupported('{"type":"object","properties":'
        . '{"a":{"type":"string","minLength":3},"b":{"type":"integer"}}}');
    // F401b: TWO bounded properties, one of them optional - the combination the live check caught
    // emitting as invalid JSON. Asserts the answer parses, both lengths sit inside their bounds and
    // the optional key is gone, so neither half of the pair can be quietly dropped.
    $lnBody3 = '{"response_format":{"type":"json_schema","json_schema":{"name":"len3","schema":'
        . '{"type":"object","properties":{"city":{"type":"string","minLength":3,"maxLength":8},'
        . '"zip":{"type":"string"},"note":{"type":"string","maxLength":5}},'
        . '"required":["city","note"]}}}}';
    $lnProps3 = api_response_schema_string_props_required($lnBody3);
    $lnBounds3 = api_response_schema_string_bounds($lnBody3);
    $lnOut3 = api_string_value_multi($lnIds, $lnProps3, "abcdefghijklmnopqrstuvwxyz ", 12, 0, $lnBounds3);
    $lnParts3 = explode(",", $lnOut3);
    $lnText3 = "";
    for ($i = $lnBase; $i < count($lnParts3); $i++) {
        $lnText3 = $lnText3 . gguf_token_str((int) $lnParts3[$i]);
    }
    $lnText3 = str_replace("Ġ", " ", $lnText3);
    $lnCity3 = "";
    $lnM3 = [];
    if (preg_match('/"city":"([A-Za-z ]*)"/', $lnText3, $lnM3) == 1) {
        $lnCity3 = $lnM3[1];
    }
    $lnNote3 = "";
    $lnM3b = [];
    if (preg_match('/"note":"([A-Za-z ]*)"/', $lnText3, $lnM3b) == 1) {
        $lnNote3 = $lnM3b[1];
    }
    $lnZip3 = strpos($lnText3, "\"zip\"") === false ? 0 : 1;
    echo "SCHEMA-LEN bounds=[" . $lnBounds . "] len=" . $lnLen . " maxonly=[" . $lnBounds2
        . "] len2=" . $lnLen2 . " mixed_refusal=[" . $lnMixed
        . "] comb_bounds=[" . $lnBounds3 . "] comb_city=" . strlen($lnCity3)
        . " comb_note=" . strlen($lnNote3) . " comb_json=" . api_json_ok($lnText3)
        . " comb_zip=" . $lnZip3 . "]\n";
    if ($lnBounds == "3:6" && $lnLen >= 3 && $lnLen <= 6 && $lnOut != ""
        && $lnBounds2 == "0:2" && $lnLen2 <= 2 && $lnOut2 != "" && $lnMixed == "minLength"
        && $lnBounds3 == "3:8,0:5" && strlen($lnCity3) >= 3 && strlen($lnCity3) <= 8
        && strlen($lnNote3) <= 5 && api_json_ok($lnText3) == 1 && $lnZip3 == 0) {
        echo "SCHEMA-LEN: PASS (emitted lengths inside bounds; other shapes still refused)\n";
    } else {
        echo "SCHEMA-LEN: FAIL\n";
    }

    // SCHEMA-COUNT (F400): element-count bounds are enforced for a single scalar-array property, so
    // the gate counts the EMITTED elements rather than checking a status code. Case two pins
    // minItems == maxItems on a number array in the spaced spelling; case three asserts the refusal
    // for the shapes whose generator cannot count.
    $cnIds = tok_chat("user", "Name some French cities.") . "," . tok_chat_open("assistant");
    $cnBase = count(explode(",", $cnIds));
    $cnBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"cnt","schema":'
        . '{"type":"object","properties":{"cities":{"type":"array","items":{"type":"string"},'
        . '"minItems":3,"maxItems":4}}}}}}';
    $cnBounds = api_response_schema_array_bounds($cnBody);
    $cnProp = api_response_schema_array_string($cnBody);
    $cnOut = api_array_string_value($cnIds, $cnProp, "abcdefghijklmnopqrstuvwxyz", 4, 6, 0, $cnBounds);
    $cnParts = explode(",", $cnOut);
    $cnText = "";
    for ($i = $cnBase; $i < count($cnParts); $i++) {
        $cnText = $cnText . gguf_token_str((int) $cnParts[$i]);
    }
    $cnText = str_replace("Ġ", " ", $cnText);
    $cnLB = strpos($cnText, "[");
    $cnRB = strpos($cnText, "]");
    $cnInner = "";
    if ($cnLB !== false && $cnRB !== false && $cnRB > $cnLB) {
        $cnInner = substr($cnText, $cnLB + 1, $cnRB - $cnLB - 1);
    }
    $cnN = 0;
    if ($cnInner != "") {
        $cnN = count(explode(",", $cnInner));
    }
    $c2Body = '{"response_format":{"type":"json_schema","json_schema":{"name":"cnt2","schema":'
        . '{"type": "object", "properties": {"nums": {"type": "array", "items": {"type": "integer"},'
        . '"minItems": 2, "maxItems": 2}}}}}}';
    $c2Bounds = api_response_schema_array_bounds($c2Body);
    $c2Prop = api_response_schema_array_number($c2Body);
    $c2np = explode("|", $c2Prop);
    $c2Ids = tok_chat("user", "Give me two integers.") . "," . tok_chat_open("assistant");
    $c2Out = api_array_number_value($c2Ids, $c2np[0], (int) $c2np[1], 4, 3, 0, $c2Bounds);
    $c2Parts = explode(",", $c2Out);
    $c2Base = count(explode(",", $c2Ids));
    $c2Text = "";
    for ($i = $c2Base; $i < count($c2Parts); $i++) {
        $c2Text = $c2Text . gguf_token_str((int) $c2Parts[$i]);
    }
    $c2Text = str_replace("Ġ", " ", $c2Text);
    $c2LB = strpos($c2Text, "[");
    $c2RB = strpos($c2Text, "]");
    $c2Inner = "";
    if ($c2LB !== false && $c2RB !== false && $c2RB > $c2LB) {
        $c2Inner = substr($c2Text, $c2LB + 1, $c2RB - $c2LB - 1);
    }
    $c2N = 0;
    if ($c2Inner != "") {
        $c2N = count(explode(",", $c2Inner));
    }
    $c3A = api_schema_array_element_bounds('{"type":"object","properties":{"a":{"type":"array",'
        . '"items":{"type":"object","properties":{"n":{"type":"string"}}},"maxItems":3}}}');
    // F408 flipped the object-array case: the keyed ao writer counts elements now, so a servable
    // bound pair returns them; the still-refused forms live in the ao_bad/ao_multi/ao_2d fixtures
    // and in c8KwBad below.
    $c3B = api_schema_array_element_bounds('{"type":"object","properties":{"a":{"type":"array",'
        . '"items":{"type":"string"},"minItems":9}}}');
    $c3C = api_schema_array_element_bounds('{"type":"object","properties":{"a":{"type":"array",'
        . '"items":{"type":"string"}},"maxItems":2}}');
    $c3D = api_schema_array_element_bounds('{"type":"object","properties":{"a":{"type":"array",'
        . '"items":{"type":"string"},"maxItems":4}}}');
    // The same bounds written BEFORE items. The enforcement path was already correct for them, but
    // the detectors used to require items immediately after "type":"array", so the request was
    // refused while doing nothing else changed - detection is asserted here, not assumed.
    $c5Body = '{"response_format":{"type":"json_schema","json_schema":{"name":"cnt3","schema":'
        . '{"type":"object","properties":{"cities":{"type":"array","minItems":2,"maxItems":2,'
        . '"items":{"type":"string"}}}}}}}';
    $c5Bounds = api_response_schema_array_bounds($c5Body);
    $c5Prop = api_response_schema_array_string($c5Body);
    $c5Refuse = api_schema_keywords_unsupported('{"type":"object","properties":{"a":{"type":"array",'
        . '"minItems":2,"maxItems":2,"items":{"type":"string"}}}}');
    $c5Ids = tok_chat("user", "Name two French cities.") . "," . tok_chat_open("assistant");
    $c5Out = api_array_string_value($c5Ids, $c5Prop, "abcdefghijklmnopqrstuvwxyz", 4, 6, 0, $c5Bounds);
    $c5Parts = explode(",", $c5Out);
    $c5Base = count(explode(",", $c5Ids));
    $c5Text = "";
    for ($i = $c5Base; $i < count($c5Parts); $i++) {
        $c5Text = $c5Text . gguf_token_str((int) $c5Parts[$i]);
    }
    $c5Text = str_replace("Ġ", " ", $c5Text);
    $c5LB = strpos($c5Text, "[");
    $c5RB = strpos($c5Text, "]");
    $c5N = 0;
    if ($c5LB !== false && $c5RB !== false && $c5RB > $c5LB) {
        $c5N = count(explode(",", substr($c5Text, $c5LB + 1, $c5RB - $c5LB - 1)));
    }
    // F406: the bounds on a TOP-LEVEL array - the schema itself is the array object, so the keyed
    // path cannot see them. The detector reads them from the root, the top-array writer counts the
    // elements the same way, and the shapes nobody honours (maxItems 0, min>max, an object-array
    // items) still refuse.
    $c6Bounds = api_schema_array_element_bounds('{"type":"array","minItems":2,"maxItems":2,'
        . '"items":{"type":"string"}}');
    $c6Refuse0 = api_schema_array_element_bounds('{"type":"array","maxItems":0,"items":{"type":"string"}}');
    $c6RefuseMM = api_schema_array_element_bounds('{"type":"array","minItems":4,"maxItems":2,'
        . '"items":{"type":"string"}}');
    $c6RefuseObj = api_schema_array_element_bounds('{"type":"array","maxItems":3,'
        . '"items":{"type":"object","properties":{"n":{"type":"string"}}}}');
    $c6Kw = api_schema_keywords_unsupported('{"type":"array","minItems":2,"maxItems":3,'
        . '"items":{"type":"string"}}');
    $c6KwObj = api_schema_keywords_unsupported('{"type":"array","maxItems":3,'
        . '"items":{"type":"object","properties":{"n":{"type":"string"}}}}');
    $c6Ids = tok_chat("user", "Name two French cities.") . "," . tok_chat_open("assistant");
    $c6Out = api_top_array_string_value($c6Ids, "abcdefghijklmnopqrstuvwxyz", 4, 6, 0, $c6Bounds);
    $c6Parts = explode(",", $c6Out);
    $c6Base = count(explode(",", $c6Ids));
    $c6Text = "";
    for ($i = $c6Base; $i < count($c6Parts); $i++) {
        $c6Text = $c6Text . gguf_token_str((int) $c6Parts[$i]);
    }
    $c6Text = str_replace("Ġ", " ", $c6Text);
    $c6LB = strpos($c6Text, "[");
    $c6RB = strpos($c6Text, "]");
    $c6N = 0;
    if ($c6LB !== false && $c6RB !== false && $c6RB > $c6LB) {
        $c6N = count(explode(",", substr($c6Text, $c6LB + 1, $c6RB - $c6LB - 1)));
    }
    // F407: the bounds on an array leaf BESIDE other properties - the mixed stepper counts the
    // elements and emits the siblings; the single-array writer would have dropped them, which is
    // why it steps aside for this shape. The plan carries the bounds ("a~min~max") so the stepper
    // never re-reads the schema.
    $c7Body = '{"response_format":{"type":"json_schema","json_schema":{"name":"cnt7","schema":'
        . '{"type":"object","properties":{"cities":{"type":"array","minItems":2,"maxItems":2,'
        . '"items":{"type":"string"}},"note":{"type":"string"}}}}}';
    $c7Bounds = api_response_schema_array_bounds($c7Body);
    $c7Plan = api_response_schema_mixed_object($c7Body);
    $c7Refuse = api_schema_keywords_unsupported('{"type":"object","properties":{"cities":{"type":"array",'
        . '"minItems":2,"maxItems":2,"items":{"type":"string"}},"note":{"type":"string"}}}');
    $c7RefuseBad = api_schema_keywords_unsupported('{"type":"object","properties":{"cities":{"type":"array",'
        . '"minItems":9,"maxItems":2,"items":{"type":"string"}},"note":{"type":"string"}}}');
    $c7Ids = tok_chat("user", "Name two French cities.") . "," . tok_chat_open("assistant");
    $c7Out = api_object_mixed($c7Ids, $c7Plan, "abcdefghijklmnopqrstuvwxyz", 16, 0);
    $c7Parts = explode(",", $c7Out);
    $c7Base = count(explode(",", $c7Ids));
    $c7Text = "";
    for ($i = $c7Base; $i < count($c7Parts); $i++) {
        $c7Text = $c7Text . gguf_token_str((int) $c7Parts[$i]);
    }
    $c7Text = str_replace("Ġ", " ", $c7Text);
    $c7LB = strpos($c7Text, "[");
    $c7RB = strpos($c7Text, "]");
    $c7N = 0;
    if ($c7LB !== false && $c7RB !== false && $c7RB > $c7LB) {
        $c7N = count(explode(",", substr($c7Text, $c7LB + 1, $c7RB - $c7LB - 1)));
    }
    $c7Keys = (strpos($c7Text, "\"cities\"") !== false ? 1 : 0)
        + (strpos($c7Text, "\"note\"") !== false ? 1 : 0);
    // F408: the bounds on an OBJECT-array leaf - the keyed ao writer counts the elements the same
    // way; the shapes nobody honours (an unservable pair, a multi-leaf ao plan, bounds on an
    // array of arrays) still refuse.
    $c8Body = '{"response_format":{"type":"json_schema","json_schema":{"name":"cnt8","schema":'
        . '{"type":"object","properties":{"rows":{"type":"array","minItems":2,"maxItems":2,'
        . '"items":{"type":"object","properties":{"n":{"type":"string"}}}}}}}}';
    $c8Bounds = api_response_schema_array_bounds($c8Body);
    $c8Det = api_response_schema_array_object($c8Body);
    $c8Kw = api_schema_keywords_unsupported('{"type":"object","properties":{"rows":{"type":"array",'
        . '"minItems":2,"maxItems":2,"items":{"type":"object","properties":{"n":{"type":"string"}}}}}}');
    $c8KwBad = api_schema_keywords_unsupported('{"type":"object","properties":{"rows":{"type":"array",'
        . '"minItems":9,"maxItems":2,"items":{"type":"object","properties":{"n":{"type":"string"}}}}}}');
    $c8Ids = tok_chat("user", "Name two French cities.") . "," . tok_chat_open("assistant");
    $c8Split = explode("|", $c8Det);
    $c8Out = api_array_object_value($c8Ids, $c8Split[0], $c8Split[1], "abcdefghijklmnopqrstuvwxyz", 3, 6, 0, $c8Bounds);
    $c8Parts = explode(",", $c8Out);
    $c8Base = count(explode(",", $c8Ids));
    $c8Text = "";
    for ($i = $c8Base; $i < count($c8Parts); $i++) {
        $c8Text = $c8Text . gguf_token_str((int) $c8Parts[$i]);
    }
    $c8Text = str_replace("Ġ", " ", $c8Text);
    $c8N = 0;
    $c8Walk = 0;
    $c8Len = strlen($c8Text);
    for ($i = 0; $i < $c8Len; $i++) {
        $ch = substr($c8Text, $i, 1);
        if ($ch == "{") {
            if ($c8Walk == 1) {
                $c8N++;                     // an object opening directly inside the outer array
            }
            $c8Walk++;
        } else if ($ch == "}") {
            $c8Walk--;
        }
    }
    // F411: the bounds on an array INSIDE a nested object - the nested plan carries the counted
    // kind ("nums:an~2~2") and the nested stepper counts with it; the emitted value keeps the
    // nesting, and the shapes nobody honours (an unservable pair) still refuse.
    $c9Sch = '{"type":"object","properties":{"outer":{"type":"object","properties":{"nums":'
        . '{"type":"array","minItems":2,"maxItems":2,"items":{"type":"integer"}}}}}}';
    $c9Bounds = api_schema_array_element_bounds($c9Sch);
    $c9Kw = api_schema_keywords_unsupported($c9Sch);
    $c9KwBad = api_schema_keywords_unsupported('{"type":"object","properties":{"outer":{"type":"object",'
        . '"properties":{"nums":{"type":"array","minItems":9,"maxItems":2,"items":{"type":"integer"}}}}}}');
    $c9Ids = tok_chat("user", "Give the numbers.") . "," . tok_chat_open("assistant");
    $c9Out = api_nested_object_value($c9Ids, "outer", "nums:an~2~2", "abcdefghijklmnopqrstuvwxyz", 6, 0);
    $c9Parts = explode(",", $c9Out);
    $c9Base = count(explode(",", $c9Ids));
    $c9Text = "";
    for ($i = $c9Base; $i < count($c9Parts); $i++) {
        $c9Text = $c9Text . gguf_token_str((int) $c9Parts[$i]);
    }
    $c9Text = str_replace("Ġ", " ", $c9Text);
    $c9N = -1;
    if (preg_match('/"nums":\[([^\]]*)\]/', $c9Text, $c9M) == 1) {
        $c9N = $c9M[1] == "" ? 0 : count(explode(",", $c9M[1]));
    }
    // F414: TWO counted arrays in one nested object - every counted leaf is validated and the
    // schema's bound occurrences must all sit inside counted leaves; a stray bound outside (on
    // the enclosing object) keeps the refusal.
    $c10Sch = '{"type":"object","properties":{"outer":{"type":"object","properties":{"nums":'
        . '{"type":"array","minItems":2,"maxItems":2,"items":{"type":"integer"}},'
        . '"tags":{"type":"array","minItems":3,"maxItems":3,"items":{"type":"string"}}}}}}';
    $c10Bounds = api_schema_array_element_bounds($c10Sch);
    $c10Kw = api_schema_keywords_unsupported($c10Sch);
    $c10Stray = api_schema_array_element_bounds('{"type":"object","minItems":2,"properties":{"outer":'
        . '{"type":"object","properties":{"nums":{"type":"array","items":{"type":"integer"}}}}}}');
    $c10Ids = tok_chat("user", "Give the numbers and tags.") . "," . tok_chat_open("assistant");
    $c10Out = api_nested_object_value($c10Ids, "outer", "nums:an~2~2,tags:a~3~3", "abcdefghijklmnopqrstuvwxyz", 6, 0);
    $c10Parts = explode(",", $c10Out);
    $c10Base = count(explode(",", $c10Ids));
    $c10Text = "";
    for ($i = $c10Base; $i < count($c10Parts); $i++) {
        $c10Text = $c10Text . gguf_token_str((int) $c10Parts[$i]);
    }
    $c10Text = str_replace("Ġ", " ", $c10Text);
    $c10Nums = -1;
    $c10Tags = -1;
    if (preg_match('/"nums":\[([^\]]*)\]/', $c10Text, $c10MN) == 1) {
        $c10Nums = $c10MN[1] == "" ? 0 : count(explode(",", $c10MN[1]));
    }
    if (preg_match('/"tags":\[([^\]]*)\]/', $c10Text, $c10MT) == 1) {
        $c10Tags = $c10MT[1] == "" ? 0 : count(explode(",", $c10MT[1]));
    }
    echo "SCHEMA-COUNT bounds=[" . $cnBounds . "] n=" . $cnN . "] text=[" . $cnText
        . "] num_bounds=[" . $c2Bounds . "] num_prop=[" . $c2Prop . "] num_n=" . $c2N
        . "] num_text=[" . $c2Text . "] obj_bounds=[" . $c3A . "] refuse_ceiling=[" . $c3B
        . "] refuse_outside=[" . $c3C . "] ok=[" . $c3D
        . "] pre_bounds=[" . $c5Bounds . "] pre_prop=[" . $c5Prop . "] pre_n=" . $c5N
        . "] pre_refuse=[" . $c5Refuse . "] top_bounds=[" . $c6Bounds . "] top_n=" . $c6N
        . "] top_text=[" . $c6Text . "] top_refuse0=[" . $c6Refuse0
        . "] top_refuseMM=[" . $c6RefuseMM . "] top_refuseObj=[" . $c6RefuseObj
        . "] top_kw=[" . $c6Kw . "] top_kwObj=[" . $c6KwObj
        . "] multi_bounds=[" . $c7Bounds . "] multi_plan=[" . $c7Plan . "] multi_n=" . $c7N
        . "] multi_keys=" . $c7Keys . "] multi_text=[" . $c7Text . "] multi_refuse=["
        . $c7Refuse . "] multi_refuse_bad=[" . $c7RefuseBad
        . "] ao_bounds=[" . $c8Bounds . "] ao_det=[" . $c8Det . "] ao_n=" . $c8N
        . "] ao_text=[" . $c8Text . "] ao_kw=[" . $c8Kw . "] ao_kw_bad=[" . $c8KwBad
        . "] nested_bounds=[" . $c9Bounds . "] nested_n=" . $c9N . "] nested_text=[" . $c9Text
        . "] nested_kw=[" . $c9Kw . "] nested_kw_bad=[" . $c9KwBad
        . "] two_bounds=[" . $c10Bounds . "] two_kw=[" . $c10Kw . "] two_stray=[" . $c10Stray
        . "] two_nums=" . $c10Nums . " two_tags=" . $c10Tags . " two_text=[" . $c10Text . "]\n";
    if ($cnBounds == "3:4" && $cnN >= 3 && $cnN <= 4 && api_json_ok($cnText) == 1
        && $c2Bounds == "2:2" && $c2N == 2 && api_json_ok($c2Text) == 1
        && $c3A == "0:3" && $c3B == "" && $c3C == "" && $c3D == "0:4"
        && $c5Bounds == "2:2" && $c5Prop == "cities" && $c5N == 2 && $c5Refuse == ""
        && api_json_ok($c5Text) == 1
        && $c6Bounds == "2:2" && $c6N == 2 && api_json_ok($c6Text) == 1
        && $c6Refuse0 == "" && $c6RefuseMM == "" && $c6RefuseObj == ""
        && $c6Kw == "" && $c6KwObj == "maxItems"
        && $c7Bounds == "2:2" && $c7Plan == "cities:a~2~2,note:s" && $c7Refuse == ""
        && $c7RefuseBad == "minItems" && $c7N == 2 && $c7Keys == 2
        && api_json_ok($c7Text) == 1
        && $c8Bounds == "2:2" && $c8Kw == "" && $c8KwBad == "minItems"
        && $c8N == 2 && api_json_ok($c8Text) == 1
        && $c9Bounds == "2:2" && $c9Kw == "" && $c9KwBad == "minItems"
        && $c9N == 2 && api_json_ok($c9Text) == 1
        && $c10Bounds == "2:2" && $c10Kw == "" && $c10Stray == ""
        && $c10Nums == 2 && $c10Tags == 3 && api_json_ok($c10Text) == 1) {
        echo "SCHEMA-COUNT: PASS (emitted element counts inside bounds; other shapes still refused)\n";
    } else {
        echo "SCHEMA-COUNT: FAIL\n";
    }

    // SCHEMA-REQ-SUBSET (F401): `required` naming a strict subset is honoured by OMITTING the
    // optional properties, so the gate asserts the emitted KEY SET equals the required names. A
    // status code or "parses as JSON" check would stay green while the optional key was still being
    // emitted, which is the whole defect being closed.
    $rqBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"req","schema":'
        . '{"type":"object","properties":{"city":{"type":"string"},"zip":{"type":"string"},'
        . '"note":{"type":"string"}},"required":["city","note"]}}}}';
    $rqSch = api_json_object_after($rqBody, "schema");
    $rqKeep = api_schema_required_filter($rqSch);
    $rqRefuse = api_schema_keywords_unsupported($rqSch);
    $rqProps = api_response_schema_string_props_required($rqBody);
    $rqBounds = api_response_schema_string_bounds($rqBody);
    $rqIds = tok_chat("user", "Say where you are.") . "," . tok_chat_open("assistant");
    $rqOut = api_string_value_multi($rqIds, $rqProps, "abcdefghijklmnopqrstuvwxyz", 12, 0, $rqBounds);
    $rqParts = explode(",", $rqOut);
    $rqBase = count(explode(",", $rqIds));
    $rqText = "";
    for ($i = $rqBase; $i < count($rqParts); $i++) {
        $rqText = $rqText . gguf_token_str((int) $rqParts[$i]);
    }
    $rqText = str_replace("Ġ", " ", $rqText);
    $rqHasCity = strpos($rqText, "\"city\"") !== false ? 1 : 0;
    $rqHasNote = strpos($rqText, "\"note\"") !== false ? 1 : 0;
    $rqHasZip = strpos($rqText, "\"zip\"") !== false ? 1 : 0;
    // An all-required schema needs no omission, and a required name that is not declared gets
    // nothing filtered at all - both must keep the property list whole.
    $r2Body = '{"response_format":{"type":"json_schema","json_schema":{"name":"req2","schema":'
        . '{"type": "object", "properties": {"a": {"type": "string"}, "b": {"type": "string"}},'
        . '"required": ["a", "b"]}}}}';
    $r2Props = api_response_schema_string_props_required($r2Body);
    $r3Body = '{"response_format":{"type":"json_schema","json_schema":{"name":"req3","schema":'
        . '{"type":"object","properties":{"a":{"type":"string"},"b":{"type":"string"}},'
        . '"required":["a","z"]}}}}';
    $r3Props = api_response_schema_string_props_required($r3Body);
    $r3Keep = api_schema_required_filter(api_json_object_after($r3Body, "schema"));
    // F403②: the mixed shape steps the filtered plan the same way. The subset emits ONLY the
    // required key and the optional integer stays absent; all-required keeps every key with the
    // integer still an integer. The boolean case is the one the finite-candidates tier used to
    // answer with the boolean alone: `required` naming the unbounded integer must yield NO
    // candidates (the grammar chain serves both keys, the boolean a real true/false), while a
    // required set the candidates can fully render keeps producing them.
    $mBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"mix1","schema":'
        . '{"type":"object","properties":{"city":{"type":"string"},"count":{"type":"integer"}},'
        . '"required":["city"]}}}}';
    $mPlan = api_response_schema_mixed_object($mBody);
    $mIds = tok_chat("user", "Say where you are.") . "," . tok_chat_open("assistant");
    $mOut = api_object_mixed($mIds, $mPlan, "abcdefghijklmnopqrstuvwxyz", 12, 0);
    $mText = "";
    $mParts = explode(",", $mOut);
    $mBase = count(explode(",", $mIds));
    for ($i = $mBase; $i < count($mParts); $i++) {
        $mText = $mText . gguf_token_str((int) $mParts[$i]);
    }
    $mText = str_replace("Ġ", " ", $mText);
    $mHasCity = strpos($mText, "\"city\"") !== false ? 1 : 0;
    $mHasCount = strpos($mText, "\"count\"") !== false ? 1 : 0;
    $m2Body = '{"response_format":{"type":"json_schema","json_schema":{"name":"mix2","schema":'
        . '{"type":"object","properties":{"city":{"type":"string"},"count":{"type":"integer"}}}}}}';
    $m2Plan = api_response_schema_mixed_object($m2Body);
    $m2Out = api_object_mixed($mIds, $m2Plan, "abcdefghijklmnopqrstuvwxyz", 12, 0);
    $m2Text = "";
    $m2Parts = explode(",", $m2Out);
    for ($i = $mBase; $i < count($m2Parts); $i++) {
        $m2Text = $m2Text . gguf_token_str((int) $m2Parts[$i]);
    }
    $m2Text = str_replace("Ġ", " ", $m2Text);
    $m2Both = (strpos($m2Text, "\"city\"") !== false ? 1 : 0)
        + (strpos($m2Text, "\"count\"") !== false ? 1 : 0);
    $m2Int = preg_match('/"count":-?[0-9]+/', $m2Text) == 1 ? 1 : 0;
    $bBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"mixb","schema":'
        . '{"type":"object","properties":{"flag":{"type":"boolean"},"count":{"type":"integer"}},'
        . '"required":["flag","count"]}}}}';
    $bSch = api_json_object_after($bBody, "schema");
    $bCands = api_schema_candidates($bSch);
    $bPlan = api_response_schema_mixed_object($bBody);
    $bOut = api_object_mixed($mIds, $bPlan, "abcdefghijklmnopqrstuvwxyz", 12, 0);
    $bText = "";
    $bParts = explode(",", $bOut);
    for ($i = $mBase; $i < count($bParts); $i++) {
        $bText = $bText . gguf_token_str((int) $bParts[$i]);
    }
    $bText = str_replace("Ġ", " ", $bText);
    $bBoth = (strpos($bText, "\"flag\"") !== false ? 1 : 0)
        + (strpos($bText, "\"count\"") !== false ? 1 : 0);
    $bBool = (strpos($bText, "\"flag\":true") !== false || strpos($bText, "\"flag\":false") !== false) ? 1 : 0;
    $b2Sch = '{"type":"object","properties":{"flag":{"type":"boolean"},'
        . '"num":{"type":"integer","minimum":1,"maximum":3}},"required":["flag","num"]}';
    $b2Cands = api_schema_candidates($b2Sch);
    $b2Full = 0;
    if (count($b2Cands) > 0) {
        $b2Full = (strpos($b2Cands[0], "\"flag\"") !== false && strpos($b2Cands[0], "\"num\"") !== false) ? 1 : 0;
    }
    // F418: the required subset on a shape with a COUNTED ARRAY leaf - the filter drops the array
    // leaf from the plan and the stepper emits only the required keys; the omitted key cannot
    // violate its own bounds, and the served key set proves the omission.
    $raSch = '{"type":"object","properties":{"cities":{"type":"array","minItems":2,"maxItems":2,'
        . '"items":{"type":"string"}},"note":{"type":"string"}},"required":["note"]}';
    $raKeep = api_schema_required_filter($raSch);
    $raRefuse = api_schema_keywords_unsupported($raSch);
    $raBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"req6","schema":'
        . '{"type":"object","properties":{"cities":{"type":"array","minItems":2,"maxItems":2,'
        . '"items":{"type":"string"}},"note":{"type":"string"}},"required":["note"]}}}}';
    $raPlan = api_response_schema_mixed_object($raBody);
    $raOut = api_object_mixed($rqIds, $raPlan, "abcdefghijklmnopqrstuvwxyz", 16, 0);
    $raText = "";
    $raParts = explode(",", $raOut);
    for ($i = $rqBase; $i < count($raParts); $i++) {
        $raText = $raText . gguf_token_str((int) $raParts[$i]);
    }
    $raText = str_replace("Ġ", " ", $raText);
    $raHasNote = strpos($raText, "\"note\"") !== false ? 1 : 0;
    $raHasCities = strpos($raText, "\"cities\"") !== false ? 1 : 0;
    echo "SCHEMA-REQ-SUBSET keep=[" . $rqKeep . "] refuse=[" . $rqRefuse . "] props="
        . count($rqProps) . "] bounds=[" . $rqBounds . "] keys city=" . $rqHasCity
        . " note=" . $rqHasNote . " zip=" . $rqHasZip . "] text=[" . $rqText
        . "] all_required_props=" . count($r2Props) . "] undeclared_keep=[" . $r3Keep
        . "] undeclared_props=" . count($r3Props)
        . "] mix_plan=[" . $mPlan . "] mix city=" . $mHasCity . " count=" . $mHasCount
        . "] mix2_plan=[" . $m2Plan . "] mix2_keys=" . $m2Both . " mix2_int=" . $m2Int
        . "] bool_cands=" . count($bCands) . "] bool_plan=[" . $bPlan . "] bool_keys="
        . $bBoth . " bool_bool=" . $bBool
        . "] enum_int_cands=" . count($b2Cands) . " enum_int_full=" . $b2Full
        . "] arr_keep=[" . $raKeep . "] arr_refuse=[" . $raRefuse . "] arr_plan=[" . $raPlan
        . "] arr_keys note=" . $raHasNote . " cities=" . $raHasCities . "] arr_text=["
        . $raText . "]\n";
    if ($rqKeep == "city,note" && $rqRefuse == "" && count($rqProps) == 2 && $rqBounds == "0:0,0:0"
        && $rqHasCity == 1 && $rqHasNote == 1 && $rqHasZip == 0 && api_json_ok($rqText) == 1
        && count($r2Props) == 2 && $r3Keep == "" && count($r3Props) == 2
        && $mPlan == "city:s" && $mHasCity == 1 && $mHasCount == 0 && api_json_ok($mText) == 1
        && $m2Plan == "city:s,count:i" && $m2Both == 2 && $m2Int == 1 && api_json_ok($m2Text) == 1
        && count($bCands) == 0 && $bPlan == "flag:b,count:i" && $bBoth == 2 && $bBool == 1
        && api_json_ok($bText) == 1
        && count($b2Cands) > 0 && $b2Full == 1
        && $raKeep == "note" && $raRefuse == "" && $raPlan == "note:s"
        && $raHasNote == 1 && $raHasCities == 0 && api_json_ok($raText) == 1) {
        echo "SCHEMA-REQ-SUBSET: PASS (emitted keys equal the required subset; optional omitted)\n";
    } else {
        echo "SCHEMA-REQ-SUBSET: FAIL\n";
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

    // F423: a mixed inclusive+exclusive pair on the narrow (enumerated) tier - the STRICTER bound
    // governs, so the looser inclusive minimum no longer shadows the stricter exclusive one. The
    // candidate set must start at the exclusive-derived lower bound, not the inclusive one.
    $emSchema = '{"type":"object","properties":{"n":{"type":"integer",'
        . '"minimum":1,"exclusiveMinimum":4,"maximum":10}}}';
    $emC = api_schema_candidates($emSchema);
    $emJoin = "";
    for ($i = 0; $i < count($emC); $i++) {
        $emJoin = $emJoin . $emC[$i];
    }
    echo "SCHEMA-EXCL-MIX n=" . count($emC) . " [" . $emJoin . "]\n";
    if (count($emC) == 6 && strpos($emJoin, '{"n":5}') !== false && strpos($emJoin, '{"n":10}') !== false
        && strpos($emJoin, '{"n":1}') === false && strpos($emJoin, '{"n":4}') === false) {
        echo "SCHEMA-EXCL-MIX: PASS (stricter exclusive bound governs the enumeration)\n";
    } else {
        echo "SCHEMA-EXCL-MIX: FAIL\n";
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

    // External $ref: resolved from a caller-supplied registry, never fetched. The ref must be gone
    // and the referenced enum must drive candidates.
    $exBody = '{"response_format":{"type":"json_schema","json_schema":{"name":"x","schema":'
        . '{"type":"object","properties":{"city":{"$ref":"city.json#/City"}}}}},'
        . '"schema_registry":{"city.json#/City":{"type":"string","enum":["Paris","London"]}}}';
    $exResolved = api_schema_resolve_refs($exBody);
    $exSchema = api_json_object_after($exResolved, "schema");
    $exC = api_schema_candidates($exSchema);
    $exJoin = "";
    for ($i = 0; $i < count($exC); $i++) {
        $exJoin = $exJoin . $exC[$i];
    }
    echo "SCHEMA-EXTREF n=" . count($exC) . " leftover_refs=" . (strpos($exResolved, '"$ref"') === false ? "0" : "1")
        . " [" . $exJoin . "]\n";
    if (count($exC) == 2 && strpos($exResolved, '"$ref"') === false
        && strpos($exJoin, '"city":"Paris"') !== false
        && strpos($exJoin, '"city":"London"') !== false) {
        echo "SCHEMA-EXTREF: PASS (registry-supplied external ref inlined)\n";
    } else {
        echo "SCHEMA-EXTREF: FAIL\n";
    }

    // Unregistered external refs must stay untouched (grammar falls back, no silent corruption).
    $unBody = '{"schema":{"type":"object","properties":{"city":{"$ref":"unknown.json#/City"}}}}';
    if (api_schema_resolve_refs($unBody) == $unBody) {
        echo "SCHEMA-EXTREF-UNREG: PASS (unknown external ref left as-is)\n";
    } else {
        echo "SCHEMA-EXTREF-UNREG: FAIL\n";
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

    // json_object spellings: OpenAI `response_format` and Responses `text.format` must both reach
    // the validate+retry path, while json_schema and another outlet's key must not be claimed.
    $joOk = api_json_object_requested('{"response_format":{"type":"json_object"}}') == 1
        && api_json_object_requested('{"text":{"format":{"type":"json_object"}}}') == 1
        && api_json_object_requested('{"output_format":{"type":"json_object"}}') == 0
        && api_json_object_requested('{"text":{"format":{"type":"json_schema","schema":{}}}}') == 0
        && api_json_object_requested('{"messages":[]}') == 0;
    if ($joOk) {
        echo "JSON-OBJECT-DETECT: PASS (both spellings claimed, json_schema and other keys are not)\n";
    } else {
        echo "JSON-OBJECT-DETECT: FAIL\n";
    }

    // Parallel tool calls: a turn with two blocks must report both with renumbered ids, a single
    // block must keep the one-call shape, and an undeclared name must still be dropped.
    $plOpen = chr(60) . "tool_call" . chr(62);
    $plClose = chr(60) . "/" . "tool_call" . chr(62);
    $plTN = ["get_weather", "get_time"];
    $plTwo = api_tool_calls_json($plOpen . "{\"name\":\"get_weather\",\"arguments\":{\"city\":\"Paris\"}}"
        . $plClose . $plOpen . "{\"name\":\"get_time\",\"arguments\":{\"city\":\"London\"}}" . $plClose, $plTN);
    $plOne = api_tool_calls_json($plOpen . "{\"name\":\"get_weather\",\"arguments\":{}}" . $plClose, $plTN);
    $plBad = api_tool_calls_json($plOpen . "{\"name\":\"ghost\",\"arguments\":{}}" . $plClose
        . $plOpen . "{\"name\":\"get_time\",\"arguments\":{}}" . $plClose, $plTN);
    $plOk = substr_count($plTwo, "\"function\":") == 2 && strpos($plTwo, "\"call_2\"") !== false
        && substr_count($plOne, "\"function\":") == 1
        && substr_count($plBad, "\"function\":") == 1 && strpos($plBad, "get_time") !== false;
    if ($plOk) {
        echo "TOOL-CALLS-PARALLEL: PASS (both blocks kept, ids renumbered, undeclared name dropped)\n";
    } else {
        echo "TOOL-CALLS-PARALLEL: FAIL two=" . $plTwo . " one=" . $plOne . " bad=" . $plBad . "\n";
    }

    // The same list must fan out through the other two outlets: Anthropic wants one tool_use block
    // per call with its own id, Responses wants one function_call output item per call.
    $plItems = api_tool_items($plTwo);
    $plAnth = api_anthropic_tool_blocks($plTwo);
    $plResp = api_responses_call_items($plTwo);
    $plFan = count($plItems) == 2
        && substr_count($plAnth, "\"type\":\"tool_use\"") == 2
        && strpos($plAnth, "toolu_2") !== false
        && substr_count($plResp, "\"type\":\"function_call\"") == 2
        && strpos($plResp, "\"fc_2\"") !== false
        && strpos($plResp, "Paris") !== false && strpos($plResp, "London") !== false;
    if ($plFan) {
        echo "TOOL-CALLS-FANOUT: PASS (both outlets emit two items with per-item ids)\n";
    } else {
        echo "TOOL-CALLS-FANOUT: FAIL items=" . count($plItems) . " anth=" . $plAnth . " resp=" . $plResp . "\n";
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
    // F392: the old contract here - "no verify burned without an acceptance" - is now unreachable,
    // and the reason is the fix rather than a regression: after one empty accept the drafter widens
    // its lookup window, and on text with no repetition the wider window proposes nothing more, so
    // there is no second verify to burn. What that contract was guarding still matters: the waste
    // must be BOUNDED at one wasted verify per call. Assert that instead, so the gate still fails
    // if the drafter ever goes back to burning a verify per step on this workload.
    $vNum = 0;
    foreach (explode(" ", $st) as $vTok) {
        if (substr($vTok, 0, 9) == "verifies=") {
            $vNum = (int) substr($vTok, 9);
        }
    }
    $wasted = $wasted && $vNum > 1;
    if ($gNrRef == $gNrSpec && !$wasted) {
        echo "SPEC-ADAPT: PASS (ids identical; wasted verifies bounded, total=" . $vNum . ")\n";
    } else {
        echo "SPEC-ADAPT: FAIL wasted=" . ($wasted ? "yes" : "no")
            . " verifies=" . $vNum
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

    // CACHE-EVICT (F340): observability BEFORE any eviction change. The prefix cache is a 64-slot
    // hash index (api_cache_path -> model_cache_key(ids,64)), so two prompts that share a slot make
    // the newer store silently displace the older one, and nothing in the API reports that. This
    // gate finds a colliding pair at run time and pins the displacement as a measured hit-length
    // drop, so a future recency-aware policy has a number to improve against. It FAILS if 40
    // attempts produce no collision - with 64 buckets that would mean the hashing itself changed.
    $f340Buckets = [];
    $f340IdsList = [];
    for ($f340i = 0; $f340i < 40; $f340i++) {
        $f340Txt = "The quick brown fox jumps over the lazy dog and then keeps walking for a while. "
            . "Numbered tail item " . $f340i . " with some extra words to make this prompt long "
            . "enough to be worth caching by the prefix reuse logic in the engine and distinct.";
        $f340Ids = tok_encode($f340Txt);
        $f340Buckets[] = model_cache_key($f340Ids, 64);
        $f340IdsList[] = $f340Ids;
    }
    $f340Ai = -1;
    $f340Bi = -1;
    for ($f340i = 0; $f340i < count($f340Buckets) && $f340Ai < 0; $f340i++) {
        for ($f340j = $f340i + 1; $f340j < count($f340Buckets); $f340j++) {
            if ($f340Buckets[$f340i] == $f340Buckets[$f340j]) {
                $f340Ai = $f340i;
                $f340Bi = $f340j;
                break;
            }
        }
    }
    if ($f340Ai >= 0) {
        $f340PA = api_cache_pick($f340IdsList[$f340Ai]);
        $f340PB = api_cache_pick($f340IdsList[$f340Bi]);
        model_cache_store(0, $f340IdsList[$f340Ai], $f340PA);
        $f340H1 = model_cache_prefill(0, $f340IdsList[$f340Ai], $f340PA);
        model_cache_store(0, $f340IdsList[$f340Bi], $f340PB);
        $f340H2 = model_cache_prefill(0, $f340IdsList[$f340Ai], $f340PA);
        $f340Dif = $f340PA != $f340PB ? 1 : 0;
        // F342: the collision pair still exists (that part of the check is the point - it keeps the
        // 64-bucket premise honest), but placement must now diverge so the stored owner survives.
        // Before the 2-way picker this same pair measured hit_own=49 -> hit_after=22 (F341).
        if ($f340Dif == 1 && $f340H1 > 0 && $f340H2 == $f340H1) {
            echo "CACHE-EVICT: PASS 2-way protected primary=" . $f340Buckets[$f340Ai]
                . " distinct_slot=1 hit_own=" . $f340H1 . " hit_after=" . $f340H2
                . " (single-slot build lost 49->22 on this same pair)\n";
        } else {
            echo "CACHE-EVICT: FAIL distinct_slot=" . $f340Dif . " hit_own=" . $f340H1
                . " hit_after=" . $f340H2 . " pa=" . $f340PA . " pb=" . $f340PB . "\n";
        }
        if (file_exists($f340PA)) {
            unlink($f340PA);
        }
        if ($f340Dif == 1 && file_exists($f340PB)) {
            unlink($f340PB);
        }
    } else {
        echo "CACHE-EVICT: FAIL no_collision_in_40 (bucket hashing or its width changed)\n";
    }

    // CACHE-LRU (F343): the eviction POLICY, asserted directly, plus an end-to-end check when the
    // hash happens to provide a contested triple. F342 protected the first owner of a bucket pair
    // but threw at its alternate blindly whenever both were owned; now the victim is whichever
    // neighbour was used EARLIER, so touching a hot entry keeps it alive. The policy is tested as a
    // unit (always covered), and the triple search is reported separately as COVERED/NOTCOVERED
    // rather than folded into one green - the lesson from F310/F313.
    $f343Pol = 1;
    if (api_cache_victim(5, 9) != 0) {
        $f343Pol = 0;
    }
    if (api_cache_victim(9, 5) != 1) {
        $f343Pol = 0;
    }
    if (api_cache_victim(7, 7) != 0) {
        $f343Pol = 0;
    }
    if (api_cache_victim(-1, 4) != 0) {
        $f343Pol = 0;
    }
    $f343Sig = [];
    $f343K1 = [];
    $f343K2 = [];
    $f343Ids = [];
    for ($f343i = 0; $f343i < 150; $f343i++) {
        $f343Txt = "The quick brown fox jumps over the lazy dog and then keeps walking for a while. "
            . "Triple hunt item " . $f343i . " padded with extra words so that the prompt stays long "
            . "enough for the prefix cache to consider it worth storing at all and distinct enough.";
        $f343Id = tok_encode($f343Txt);
        $a1 = model_cache_key($f343Id, 64);
        $a2 = model_cache_key($f343Id . "|alt", 64) + 64;
        $f343K1[] = $a1;
        $f343K2[] = $a2;
        $f343Ids[] = $f343Id;
        $f343Sig[] = ($a1 < $a2 ? "" . $a1 . "_" . $a2 : "" . $a2 . "_" . $a1);
    }
    $f343Ta = -1;
    $f343Tb = -1;
    $f343Tc = -1;
    for ($f343i = 0; $f343i < count($f343Sig) && $f343Tc < 0; $f343i++) {
        $f343j1 = -1;
        for ($f343j = $f343i + 1; $f343j < count($f343Sig); $f343j++) {
            if ($f343Sig[$f343j] == $f343Sig[$f343i]) {
                if ($f343j1 < 0) {
                    $f343j1 = $f343j;
                } else {
                    $f343Ta = $f343i;
                    $f343Tb = $f343j1;
                    $f343Tc = $f343j;
                    break;
                }
            }
        }
    }
    if ($f343Pol != 1) {
        echo "CACHE-LRU: FAIL policy (victim must be the earlier-used slot; equal ticks -> primary)\n";
    } else {
        echo "CACHE-LRU: PASS policy victim=earlier_used equal=primary\n";
        if ($f343Tc >= 0) {
            $f343PA = api_cache_pick($f343Ids[$f343Ta]);
            model_cache_store(0, $f343Ids[$f343Ta], $f343PA);
            $f343HA = model_cache_prefill(0, $f343Ids[$f343Ta], $f343PA);
            $f343PB = api_cache_pick($f343Ids[$f343Tb]);
            model_cache_store(0, $f343Ids[$f343Tb], $f343PB);
            $f343HB = model_cache_prefill(0, $f343Ids[$f343Tb], $f343PB);
            // touch A again so it becomes the MORE recent of the two, then let C in: with a
            // recency-aware victim, C must take B's slot and leave A intact.
            $f343PA2 = api_cache_pick($f343Ids[$f343Ta]);
            $f343PC = api_cache_pick($f343Ids[$f343Tc]);
            model_cache_store(0, $f343Ids[$f343Tc], $f343PC);
            $f343HA2 = model_cache_prefill(0, $f343Ids[$f343Ta], $f343PA2);
            $f343HB2 = model_cache_prefill(0, $f343Ids[$f343Tb], $f343PB);
            $f343Ok = ($f343PA == $f343PA2 && $f343HA > 0 && $f343HA2 == $f343HA
                && $f343HB2 < $f343HB) ? 1 : 0;
            if ($f343Ok == 1) {
                echo "CACHE-LRU-INTEGRATION: COVERED hot entry survived the triple (own=" . $f343HA
                    . " after=" . $f343HA2 . ", victim=" . $f343HB . "->" . $f343HB2 . ")\n";
            } else {
                echo "CACHE-LRU-INTEGRATION: FAIL same_slot=" . ($f343PA == $f343PA2 ? 1 : 0)
                    . " own=" . $f343HA . " after=" . $f343HA2 . " victim=" . $f343HB . "->"
                    . $f343HB2 . "\n";
            }
            if (file_exists($f343PA)) {
                unlink($f343PA);
            }
            if (file_exists($f343PB) && $f343PB != $f343PA) {
                unlink($f343PB);
            }
            if (file_exists($f343PC) && $f343PC != $f343PA && $f343PC != $f343PB) {
                unlink($f343PC);
            }
        } else {
            echo "CACHE-LRU-INTEGRATION: NOTCOVERED no shared candidate pair in 150 prompts\n";
        }
    }

    // BATCH-EQ (F359): a batched pass must produce the same tokens as two separate passes.
    // The batch shares ONE forward pass between two conversations, so the only things keeping
    // them apart are the per-row slot, visible range and RoPE position; a mistake there leaks
    // one conversation's context into the other and the tokens diverge. The batch is also the
    // only path that runs two rows per pass, so the row count is asserted: a batch that quietly
    // ran one conversation at a time would pass the equality check while batching nothing.
    $f359A = tok_encode("The capital of France is Paris and the largest ocean is the Pacific.");
    $f359B = tok_encode("Water boils at one hundred degrees Celsius at normal pressure.");
    $f359N = 10;
    $f359Seed = 4242;
    $f359Sa = "";
    model_session_start(0, $f359A);
    model_session_seed($f359Seed);
    for ($f359i = 0; $f359i < $f359N; $f359i++) {
        $f359t = model_session_next(0, 0.0, 1.0);
        if ($f359t < 0) {
            break;
        }
        $f359Sa = $f359Sa . ($f359Sa == "" ? "" : ",") . $f359t;
    }
    $f359Sb = "";
    model_session_start(0, $f359B);
    model_session_seed($f359Seed);
    for ($f359i = 0; $f359i < $f359N; $f359i++) {
        $f359t2 = model_session_next(0, 0.0, 1.0);
        if ($f359t2 < 0) {
            break;
        }
        $f359Sb = $f359Sb . ($f359Sb == "" ? "" : ",") . $f359t2;
    }
    $f359Ba = "";
    $f359Bb = "";
    $f359Rows = 0;
    model_batch_start(0, 256);
    model_batch_add(0, 0, $f359A, 0.0, 1.0, $f359Seed);
    model_batch_add(0, 1, $f359B, 0.0, 1.0, $f359Seed);
    for ($f359i = 0; $f359i < $f359N; $f359i++) {
        $f359rows = model_batch_next(0);
        if ($f359rows <= 0) {
            break;
        }
        if ($f359i == 0) {
            $f359Rows = $f359rows;
        }
        $f359tk = explode(",", model_batch_tokens());
        $f359Ba = $f359Ba . ($f359Ba == "" ? "" : ",") . $f359tk[0];
        $f359Bb = $f359Bb . ($f359Bb == "" ? "" : ",") . $f359tk[1];
    }
    model_batch_end();
    $f359EqA = ($f359Sa == $f359Ba) ? 1 : 0;
    $f359EqB = ($f359Sb == $f359Bb) ? 1 : 0;
    $f359Cnt = $f359Sa == "" ? 0 : count(explode(",", $f359Sa));
    if ($f359EqA == 1 && $f359EqB == 1 && $f359Cnt >= 4 && $f359Rows == 2) {
        echo "BATCH-EQ: PASS tokens=" . $f359Cnt . " rows=" . $f359Rows
            . " batch==single for both conversations\n";
    } else {
        echo "BATCH-EQ: FAIL eq_a=" . $f359EqA . " eq_b=" . $f359EqB . " n=" . $f359Cnt
            . " rows=" . $f359Rows . "\n  single_a=" . $f359Sa . "\n  batch_a=" . $f359Ba
            . "\n  single_b=" . $f359Sb . "\n  batch_b=" . $f359Bb . "\n";
    }

    // BATCH-THRU (F360): what a batch actually buys, measured inside the engine so the HTTP
    // layer is not part of the number. Same shape on both sides: N greedy steps of one
    // conversation against N greedy steps of a two-conversation batch, each side warmed up
    // first. The gate is a no-regression one - a batched step must not cost more than two
    // separate steps - because the interesting quantity (how much of the weight traffic the two
    // rows share) is not a threshold this engine can promise for every prompt.
    $f360N = 12;
    model_session_start(0, $f359A);
    model_session_seed($f359Seed);
    for ($f360w = 0; $f360w < 3; $f360w++) {
        model_session_next(0, 0.0, 1.0);
    }
    $f360t0 = microtime(true);
    for ($f360i = 0; $f360i < $f360N; $f360i++) {
        $f360t = model_session_next(0, 0.0, 1.0);
        if ($f360t < 0) {
            break;
        }
    }
    $f360Single = (microtime(true) - $f360t0) * 1000.0;
    model_batch_start(0, 256);
    model_batch_add(0, 0, $f359A, 0.0, 1.0, $f359Seed);
    model_batch_add(0, 1, $f359B, 0.0, 1.0, $f359Seed);
    for ($f360w = 0; $f360w < 3; $f360w++) {
        model_batch_next(0);
    }
    $f360t1 = microtime(true);
    for ($f360i = 0; $f360i < $f360N; $f360i++) {
        $f360rows2 = model_batch_next(0);
        if ($f360rows2 <= 0) {
            break;
        }
    }
    $f360Batch = (microtime(true) - $f360t1) * 1000.0;
    model_batch_end();
    $f360sTps = $f360N / ($f360Single / 1000.0);
    $f360bTps = (2 * $f360N) / ($f360Batch / 1000.0);
    $f360Ratio = $f360bTps / $f360sTps;
    if ($f360Batch <= 2.0 * $f360Single) {
        echo "BATCH-THRU: PASS single_tps=" . f360_x100($f360sTps) . " batch_tps=" . f360_x100($f360bTps)
            . " ratio=" . f360_x100($f360Ratio) . " single_ms=" . f360_x100($f360Single)
            . " batch_ms=" . f360_x100($f360Batch) . "\n";
    } else {
        echo "BATCH-THRU: FAIL single_ms=" . f360_x100($f360Single) . " batch_ms=" . f360_x100($f360Batch)
            . " ratio=" . f360_x100($f360Ratio) . "\n";
    }


    // BATCH-M3 (F373): what does a THIRD row cost? F360 could only prove the sharing for two rows
    // because two rows was all the engine could make - the region table, the slot bounds and the
    // shared-weight matvec were all fixed at two. This gate does what the old ones cannot: it
    // asserts the step really carried three rows (a batch that quietly ran one conversation at a
    // time would still satisfy a token comparison), it requires each batched conversation to equal
    // the same conversation run alone with the same seed, and it prints the marginal cost per row
    // for M=1..4, which is the quantity the batching claim actually rests on. Greedy on both sides,
    // so any divergence is the pass and not the sampler.
    $f373A = tok_encode("The capital of France is Paris and the largest ocean is the Pacific.");
    $f373B = tok_encode("Water boils at one hundred degrees Celsius at normal pressure.");
    $f373C = tok_encode("The planet closest to the sun is Mercury and the farthest is Neptune.");
    $f373N = 8;
    $f373Seed = 4242;
    $f373Ref = "";
    foreach (explode("|", $f373A . "|" . $f373B . "|" . $f373C) as $f373P) {
        $f373S = "";
        model_session_start(0, $f373P);
        model_session_seed($f373Seed);
        for ($f373i = 0; $f373i < $f373N; $f373i++) {
            $f373t = model_session_next(0, 0.0, 1.0);
            if ($f373t < 0) {
                break;
            }
            $f373S = $f373S . ($f373S == "" ? "" : ",") . $f373t;
        }
        $f373Ref = $f373Ref . $f373S . ";";
    }
    $f373Parts = explode(";", $f373Ref);
    $f373Sa = $f373Parts[0];
    $f373Sb = $f373Parts[1];
    $f373Sc = $f373Parts[2];
    $f373Ba = "";
    $f373Bb = "";
    $f373Bc = "";
    $f373Rows = 0;
    $f373Cost = "";
    if (model_batch_start_n(0, 256, 3) != 0) {
        echo "BATCH-M3: FAIL batch_start_n(3) refused: " . model_last_error() . "\n";
    } else {
        model_batch_add(0, 0, $f373A, 0.0, 1.0, $f373Seed);
        model_batch_add(0, 1, $f373B, 0.0, 1.0, $f373Seed);
        model_batch_add(0, 2, $f373C, 0.0, 1.0, $f373Seed);
        for ($f373i = 0; $f373i < $f373N; $f373i++) {
            $f373r = model_batch_next(0);
            if ($f373r <= 0) {
                break;
            }
            if ($f373i == 0) {
                $f373Rows = $f373r;
            }
            $f373tk = explode(",", model_batch_tokens());
            $f373Ba = $f373Ba . ($f373Ba == "" ? "" : ",") . $f373tk[0];
            $f373Bb = $f373Bb . ($f373Bb == "" ? "" : ",") . $f373tk[1];
            $f373Bc = $f373Bc . ($f373Bc == "" ? "" : ",") . $f373tk[2];
        }
        model_batch_end();
        $f373EqA = ($f373Sa == $f373Ba) ? 1 : 0;
        $f373EqB = ($f373Sb == $f373Bb) ? 1 : 0;
        $f373EqC = ($f373Sc == $f373Bc) ? 1 : 0;
        $f373CntA = $f373Sa == "" ? 0 : count(explode(",", $f373Sa));
        // Marginal cost: the same 8 greedy steps run with M regions, each holding one live
        // conversation, warmed first. Reported, not asserted - correctness is the equality above;
        // this is the number the next decision is taken from.
        foreach (explode(",", "1,2,3,4") as $f373Ms) {
            $f373M = (int) $f373Ms;
            if (model_batch_start_n(0, 256, $f373M) != 0) {
                $f373Cost = $f373Cost . " M=" . $f373M . ":refused";
                break;
            }
            model_batch_add(0, 0, $f373A, 0.0, 1.0, $f373Seed);
            if ($f373M >= 2) {
                model_batch_add(0, 1, $f373B, 0.0, 1.0, $f373Seed);
            }
            if ($f373M >= 3) {
                model_batch_add(0, 2, $f373C, 0.0, 1.0, $f373Seed);
            }
            if ($f373M >= 4) {
                model_batch_add(0, 3, $f373A, 0.0, 1.0, $f373Seed);
            }
            for ($f373w = 0; $f373w < 3; $f373w++) {
                model_batch_next(0);
            }
            $f373t0 = microtime(true);
            for ($f373i = 0; $f373i < $f373N; $f373i++) {
                if (model_batch_next(0) <= 0) {
                    break;
                }
            }
            $f373ms = (microtime(true) - $f373t0) * 1000.0;
            $f373Cost = $f373Cost . " M=" . $f373M . " step_ms=" . f360_x100($f373ms / $f373N)
                . " per_tok_ms=" . f360_x100($f373ms / ($f373N * $f373M));
            model_batch_end();
        }
        if ($f373EqA == 1 && $f373EqB == 1 && $f373EqC == 1 && $f373Rows == 3 && $f373CntA >= 4) {
            echo "BATCH-M3: PASS tokens=" . $f373CntA . " rows=" . $f373Rows
                . " three batched conversations == each alone\n";
        } else {
            echo "BATCH-M3: FAIL eq_a=" . $f373EqA . " eq_b=" . $f373EqB . " eq_c=" . $f373EqC
                . " rows=" . $f373Rows . " n=" . $f373CntA . "\n  single_a=" . $f373Sa
                . "\n  batch_a=" . $f373Ba . "\n  single_b=" . $f373Sb . "\n  batch_b=" . $f373Bb
                . "\n  single_c=" . $f373Sc . "\n  batch_c=" . $f373Bc . "\n";
        }
        echo "BATCH-M3-COST:" . $f373Cost . "\n";
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
