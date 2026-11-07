"""F401 live check: does `required` naming a strict subset come back with exactly those keys?

The refusal used to fire for every subset. Now the flat all-string object honours it by omitting the
optional properties, so the assertion is about the KEY SET of the served answer, not the status
code. Five cases, both directions, all bodies built with json.dumps (the spaced wire spelling):
  1. subset of three strings           -> 200, keys == required, optional absent
  2. all three required               -> 200, every key present (no regression)
  3. required names an undeclared key  -> 400 naming required
  4. subset on a mixed shape           -> 400 naming required (that generator cannot omit)
  5. subset combined with maxLength    -> 200, keys subset AND lengths inside bounds
"""
import json
import sys
import urllib.error
import urllib.request

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8000
URL = "http://127.0.0.1:%d/v1/chat/completions" % PORT


def ask(prompt, schema, max_tokens=64):
    body = {"messages": [{"role": "user", "content": prompt}],
            "response_format": {"type": "json_schema",
                                "json_schema": {"name": "req", "schema": schema}},
            "max_tokens": max_tokens, "temperature": 0}
    req = urllib.request.Request(URL, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=180) as f:
            return f.getcode(), json.loads(f.read().decode())["choices"][0]["message"]["content"]
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()[:200]


def keys_of(text):
    try:
        return sorted(json.loads(text).keys())
    except Exception:
        return None


THREE = {"type": "object", "properties": {"city": {"type": "string"},
                                          "zip": {"type": "string"},
                                          "note": {"type": "string"}}}

cases = [
    ("subset_two_of_three", "Say where you are.",
     dict(list(THREE.items()) + [("required", ["city", "note"])]),
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    ("all_three_required", "Say where you are.",
     dict(list(THREE.items()) + [("required", ["city", "note", "zip"])]),
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note", "zip"]),
    ("undeclared_name", "Say where you are.",
     dict(list(THREE.items()) + [("required", ["city", "region"])]),
     lambda code, txt: code == 400 and "required" in txt),
    ("mixed_shape_subset", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "count": {"type": "integer"}},
      "required": ["city"]},
     lambda code, txt: code == 400 and "required" in txt),
    ("subset_with_length", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string", "minLength": 3, "maxLength": 8},
                                       "zip": {"type": "string"},
                                       "note": {"type": "string", "maxLength": 5}},
      "required": ["city", "note"]},
     lambda code, txt: (code == 200 and keys_of(txt) == ["city", "note"]
                        and 3 <= len(json.loads(txt)["city"]) <= 8
                        and len(json.loads(txt)["note"]) <= 5)),
]

bad = 0
for label, prompt, schema, ok in cases:
    code, txt = ask(prompt, schema)
    good = ok(code, txt)
    if not good:
        bad += 1
    print("%-22s status=%s ok=%s text=%s" % (label, code, good, txt[:140]))
print("VERDICT: " + ("PASS - served key set equals the required subset; other shapes still refused"
                     if bad == 0 else "FAIL cases=%d" % bad))
sys.exit(1 if bad else 0)
