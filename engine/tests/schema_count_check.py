"""F400/F406 live check: are array minItems/maxItems honoured on the served API, and do the shapes
whose generator cannot count elements still get refused?

Eleven cases, both directions. The request bodies are built with json.dumps, so they arrive in the
spaced wire spelling - the one that hid a whitespace bug in F397.
  1. string array,  minItems 3 / maxItems 4  -> 200 and 3 <= len <= 4
  2. integer array, minItems 2 / maxItems 2  -> 200 and len == 2
  3. array of objects, maxItems 3             -> 400 naming maxItems
  4. maxItems on the enclosing object         -> 400 naming maxItems
  5. string array through the STREAM path     -> 200 and the count inside the bounds
  6. minItems 6 (above the default budget)    -> 200 and 6 or 7 elements
  7. minItems 9 (past the element ceiling)    -> 400 naming minItems
  8. bounds spelled before "items"            -> 200 and the count inside the bounds
  9. F406: TOP-LEVEL array, min 2 / max 2     -> 200 and len == 2
 10. F406: top-level array, min 6 / max 7     -> 200 and 6 or 7 elements (the raise branch)
 11. F406: top-level array, maxItems 0        -> 400 naming maxItems
Case 4 is the leak test: a bound outside the array would be relaxed by the shape check and enforced
by nothing.
"""
import json
import sys
import urllib.error
import urllib.request

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8000
URL = "http://127.0.0.1:%d/v1/chat/completions" % PORT


def ask(prompt, schema, max_tokens=96):
    body = {"messages": [{"role": "user", "content": prompt}],
            "response_format": {"type": "json_schema",
                                "json_schema": {"name": "cnt", "schema": schema}},
            "max_tokens": max_tokens, "temperature": 0}
    req = urllib.request.Request(URL, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=180) as f:
            return f.getcode(), json.loads(f.read().decode())["choices"][0]["message"]["content"]
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()[:160]


def n_items(text, key):
    try:
        return len(json.loads(text)[key])
    except Exception:
        return None


def n_top_items(text):
    # The top-level array schema serves a bare JSON array - count its elements.
    try:
        return len(json.loads(text))
    except Exception:
        return None


def ask_stream(prompt, schema, max_tokens=96):
    """The served loop has its own schema path, so the streamed answer is checked separately."""
    body = {"messages": [{"role": "user", "content": prompt}],
            "response_format": {"type": "json_schema",
                                "json_schema": {"name": "cnt", "schema": schema}},
            "max_tokens": max_tokens, "temperature": 0, "stream": True}
    req = urllib.request.Request(URL, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    text = ""
    code = 0
    try:
        with urllib.request.urlopen(req, timeout=180) as f:
            code = f.getcode()
            for line in f:
                s = line.decode().strip()
                if not s.startswith("data: ") or s[6:] == "[DONE]":
                    continue
                o = json.loads(s[6:])["choices"][0]["delta"]
                text += o.get("content", "")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()[:160]
    return code, text


cases = [
    ("str_min3_max4", "Name some French cities.",
     {"type": "object", "properties": {"cities": {"type": "array", "items": {"type": "string"},
                                                  "minItems": 3, "maxItems": 4}}},
     lambda code, txt: code == 200 and (n_items(txt, "cities") or 0) in (3, 4), ask),
    ("int_min2_max2", "Give me two integers.",
     {"type": "object", "properties": {"nums": {"type": "array", "items": {"type": "integer"},
                                                "minItems": 2, "maxItems": 2}}},
     lambda code, txt: code == 200 and n_items(txt, "nums") == 2, ask),
    ("obj_items_refuse", "List some people.",
     {"type": "object", "properties": {"rows": {"type": "array", "items": {"type": "object",
                                                                           "properties": {"name": {"type": "string"}}},
                                                "maxItems": 3}}},
     lambda code, txt: code == 400 and "maxItems" in txt, ask),
    ("outer_bound_refuse", "Name some French cities.",
     {"type": "object", "properties": {"cities": {"type": "array", "items": {"type": "string"}}},
      "maxItems": 2},
     lambda code, txt: code == 400 and "maxItems" in txt, ask),
    ("str_min2_max3_stream", "Name some French cities.",
     {"type": "object", "properties": {"cities": {"type": "array", "items": {"type": "string"},
                                                  "minItems": 2, "maxItems": 3}}},
     lambda code, txt: code == 200 and (n_items(txt, "cities") or 0) in (2, 3), ask_stream),
    # The minimum is above the generator's default budget of 4, so this is the path where the
    # element budget grows to reach it - a branch no other case exercises.
    ("str_min6_max7_raise", "Name some French cities.",
     {"type": "object", "properties": {"cities": {"type": "array", "items": {"type": "string"},
                                                  "minItems": 6, "maxItems": 7}}},
     lambda code, txt: code == 200 and (n_items(txt, "cities") or 0) in (6, 7), ask),
    # Past the ceiling the generator cannot reach the minimum, so the bound stays refused.
    ("str_min9_refuse", "Name some French cities.",
     {"type": "object", "properties": {"cities": {"type": "array", "items": {"type": "string"},
                                                  "minItems": 9}}},
     lambda code, txt: code == 400 and "minItems" in txt, ask),
    # The same bounds spelled before "items" - the order json.dumps does not force and a hand-written
    # schema often uses.
    ("str_bounds_before_items", "Name some French cities.",
     {"type": "object", "properties": {"cities": {"type": "array", "minItems": 2, "maxItems": 3,
                                                  "items": {"type": "string"}}}},
     lambda code, txt: code == 200 and (n_items(txt, "cities") or 0) in (2, 3), ask),
    # F406: the bounds on a TOP-LEVEL array - the schema itself is the array object, the elements
    # are counted the same way, and the still-refused forms are pinned next to it.
    ("top_min2_max2", "Name some French cities.",
     {"type": "array", "minItems": 2, "maxItems": 2, "items": {"type": "string"}},
     lambda code, txt: code == 200 and (n_top_items(txt) or 0) == 2, ask),
    ("top_min6_max7_raise", "Name some French cities.",
     {"type": "array", "minItems": 6, "maxItems": 7, "items": {"type": "string"}},
     lambda code, txt: code == 200 and (n_top_items(txt) or 0) in (6, 7), ask),
    ("top_max0_refuse", "Name some French cities.",
     {"type": "array", "maxItems": 0, "items": {"type": "string"}},
     lambda code, txt: code == 400 and "maxItems" in txt, ask),
]

bad = 0
for label, prompt, schema, ok, sender in cases:
    code, txt = sender(prompt, schema)
    good = ok(code, txt)
    if not good:
        bad += 1
    print("%-22s status=%s ok=%s text=%s" % (label, code, good, txt[:120]))
print("VERDICT: " + ("PASS - served element counts inside bounds; other shapes still refused"
                     if bad == 0 else "FAIL cases=%d" % bad))
sys.exit(1 if bad else 0)
