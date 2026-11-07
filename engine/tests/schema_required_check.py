"""F401/F402/F403 live check: does `required` come back with exactly the keys it names?

The refusal used to fire for every subset. Now the flat all-string object honours it by omitting the
optional properties, and F403② extends that to the mixed shape (string + integer/number/boolean),
whose generator steps the filtered plan. The assertion is about the KEY SET of the served answer,
not the status code. Thirteen cases, both directions, all bodies built with json.dumps (the spaced
wire spelling):
  1. subset of three strings           -> 200, keys == required, optional absent
  2. all three required               -> 200, every key present (no regression)
  3. required names an undeclared key  -> 400 naming required
  4. subset on a mixed shape           -> 200, keys == required, integer key absent
  5. subset combined with maxLength    -> 200, keys subset AND lengths inside bounds
  6. additionalProperties false (F402) -> 200, keys == the two required names
  7. additionalProperties on a mixed shape -> 200, keys == the declared plan (F403②)
  8. additionalProperties beside an enum leaf -> 400 naming additionalProperties
  9. additionalProperties + array bounds -> 200, element count inside bounds, key set exact
 10. the SCHEMA form of the keyword beside array bounds -> 400 naming additionalProperties
 11. all-required on a mixed shape    -> 200, every key present, integer still an integer
 12. all-required with a boolean leaf -> 200, every key present (the candidates tier used to
     answer this with the boolean alone), boolean still a boolean
 13. required on a shape with an array leaf -> 400 naming required (the single-array path emits
     only the array key and would drop the required sibling)
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


def n_items(text, key):
    # Same rule as the F400 live check: count via json.loads - the served charset contains
    # commas, so splitting the raw text would over-count.
    try:
        return len(json.loads(text)[key])
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
    # F403②: the mixed generator steps the filtered plan, so the subset is honoured by omitting
    # the optional integer - and the integer must stay absent, not merely tolerated.
    ("mixed_shape_subset", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "count": {"type": "integer"}},
      "required": ["city"]},
     lambda code, txt: code == 200 and keys_of(txt) == ["city"]),
    ("subset_with_length", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string", "minLength": 3, "maxLength": 8},
                                       "zip": {"type": "string"},
                                       "note": {"type": "string", "maxLength": 5}},
      "required": ["city", "note"]},
     lambda code, txt: (code == 200 and keys_of(txt) == ["city", "note"]
                        and 3 <= len(json.loads(txt)["city"]) <= 8
                        and len(json.loads(txt)["note"]) <= 5)),
    # F402: additionalProperties names no key of its own - the generators emit the declared plan and
    # nothing else - so refusing it rejected a request that was already satisfied.
    ("addprops_all_string", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "additionalProperties": False, "required": ["city", "note"]},
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    # F403②: the mixed shape passes too, and the integer leaf must still come back an integer.
    ("addprops_mixed_served", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "count": {"type": "integer"}},
      "additionalProperties": False},
     lambda code, txt: (code == 200 and keys_of(txt) == ["city", "count"]
                        and isinstance(json.loads(txt)["count"], int))),
    # ...while an enum leaf still refuses: its plan entry has three colon-separated parts and the
    # honoured predicate reads exactly two.
    ("addprops_enum_refuse", "Say where you are.",
     {"type": "object", "properties": {"mode": {"type": "string", "enum": ["Paris", "London"]},
                                       "count": {"type": "integer"}},
      "additionalProperties": False},
     lambda code, txt: code == 400 and "additionalProperties" in txt),
    # F403: two constraints that each hold must not refuse each other. The array bound is enforced by
    # element counting, the key constraint by the plan, so the pair is served.
    ("addprops_with_arr_bounds", "Name some French cities.",
     {"type": "object", "properties": {"cities": {"type": "array", "items": {"type": "string"},
                                                  "minItems": 2, "maxItems": 3}},
      "additionalProperties": False},
     lambda code, txt: (code == 200 and (n_items(txt, "cities") or 0) in (2, 3)
                        and keys_of(txt) == ["cities"])),
    # ...while a keyword that really is unimplemented still gets named, next to a supported one.
    ("addprops_form_with_arr_bounds", "Name some French cities.",
     {"type": "object", "properties": {"cities": {"type": "array", "items": {"type": "string"},
                                                  "maxItems": 3}},
      "additionalProperties": {"type": "string"}},
     lambda code, txt: code == 400 and "additionalProperties" in txt),
    # F403②: all-required on the mixed shape keeps every key, and the integer is still an integer.
    ("mixed_all_required", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "count": {"type": "integer"}},
      "required": ["city", "count"]},
     lambda code, txt: (code == 200 and keys_of(txt) == ["city", "count"]
                        and isinstance(json.loads(txt)["count"], int))),
    # The case the finite-candidates tier used to answer with the boolean alone: required naming
    # the unbounded integer must now come back with BOTH keys, the boolean still a boolean.
    ("bool_all_required", "Say where you are.",
     {"type": "object", "properties": {"flag": {"type": "boolean"}, "count": {"type": "integer"}},
      "required": ["flag", "count"]},
     lambda code, txt: (code == 200 and keys_of(txt) == ["count", "flag"]
                        and isinstance(json.loads(txt)["flag"], bool)
                        and isinstance(json.loads(txt)["count"], int))),
    # A shape with an array leaf is served by the single-array path, which emits only that key -
    # a required sibling would be dropped, so the request is refused instead of half-served.
    ("arr_leaf_required_refuse", "Say where you are.",
     {"type": "object", "properties": {"name": {"type": "string"},
                                       "nums": {"type": "array", "items": {"type": "integer"}}},
      "required": ["name"]},
     lambda code, txt: code == 400 and "required" in txt),
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
