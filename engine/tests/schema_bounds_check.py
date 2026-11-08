"""F405 live check: are non-negative integer bounds honoured at any span and with a side missing?

The bounds gate used to refuse anything beyond an integer with BOTH bounds and a span of at most
15 (the candidates tier enumerates those). Now the digit walk serves a single integer property
with non-negative minimum and/or maximum at ANY span, so the assertion is the VALUE inside the
bounds, not the status code. Eight cases:
  1. wide span [0, 100000]       -> 200, value inside the bounds
  2. wide span [1000, 999999]    -> 200, value inside the bounds
  3. one-sided minimum 5         -> 200, value >= 5
  4. one-sided maximum 15        -> 200, value <= 15
  5. narrow span [1, 5]          -> 200, value inside (candidates tier, no regression)
  6. the same wide bounds on a multi-leaf schema -> 400 (only the single-leaf shape is honoured)
  7. negative bounds             -> 400
  8. float bounds                -> 400
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
                                "json_schema": {"name": "bnd", "schema": schema}},
            "max_tokens": max_tokens, "temperature": 0}
    req = urllib.request.Request(URL, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=180) as f:
            return f.getcode(), json.loads(f.read().decode())["choices"][0]["message"]["content"]
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()[:200]


def val_of(text, key):
    try:
        return json.loads(text)[key]
    except Exception:
        return None

cases = [
    ("wide_0_100000", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": 0, "maximum": 100000}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and 0 <= val_of(txt, "n") <= 100000),
    ("wide_1000_999999", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": 1000, "maximum": 999999}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and 1000 <= val_of(txt, "n") <= 999999),
    ("onesided_min_5", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": 5}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and val_of(txt, "n") >= 5),
    ("onesided_max_15", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "maximum": 15}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and 0 <= val_of(txt, "n") <= 15),
    ("narrow_1_5", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": 1, "maximum": 5}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and 1 <= val_of(txt, "n") <= 5),
    ("multi_leaf_refuse", "Give the number and the city.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": 0, "maximum": 100000},
                                       "city": {"type": "string"}}},
     lambda code, txt: code == 400 and "numeric bounds" in txt),
    # F419 flipped this one: BOTH-NEGATIVE bounds are served by the magnitude walk behind a dash
    # (the value -(mag) with the magnitude in [-hi, -lo]).
    ("negative_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": -5000, "maximum": -100}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and -5000 <= val_of(txt, "n") <= -100),
    # F419: a range STRADDLING zero clamps to the non-negative side (the subset argument) - the
    # served values stay inside the schema's bounds.
    ("straddling_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": -1000, "maximum": 1000}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and -1000 <= val_of(txt, "n") <= 1000),
    # F420: multipleOf narrows the honoured value to a single deterministic multiple inside the
    # bounds (the subset argument).
    ("multipleof_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": 1, "maximum": 100,
                                             "multipleOf": 7}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and 1 <= val_of(txt, "n") <= 100
     and val_of(txt, "n") % 7 == 0),
    # Both bounds negative: the magnitude walk picks the smallest multiple of 7 at or above 10, so
    # the served value is -14 - inside [-100, -10] and still a multiple.
    ("multipleof_negative", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": -100, "maximum": -10,
                                             "multipleOf": 7}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and -100 <= val_of(txt, "n") <= -10
     and val_of(txt, "n") % 7 == 0),
    # No multiple of 7 lies in [1, 5]: the constraint cannot be met, so it must be refused rather
    # than answered with a non-multiple.
    ("multipleof_unsatisfiable", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": 1, "maximum": 5,
                                             "multipleOf": 7}}},
     lambda code, txt: code == 400 and "numeric bounds" in txt),
    ("float_refuse", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 1.5, "maximum": 100.5}}},
     lambda code, txt: code == 400 and "numeric bounds" in txt),
    # F421: an INTEGER leaf with fractional bounds. The value must be an integer inside the real
    # interval, so 1.5..9.5 means 2..9 - truncating the bound to 1 would serve a violating value.
    ("int_leaf_float_bounds", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": 1.5, "maximum": 9.5}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and 2 <= val_of(txt, "n") <= 9),
    # No integer lies in [1.5, 1.9]: unsatisfiable for an integer leaf, so it must be refused.
    ("int_leaf_float_unsatisfiable", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": 1.5, "maximum": 1.9}}},
     lambda code, txt: code == 400 and "numeric bounds" in txt),
    # F422: exclusive bounds on an integer leaf convert to the adjacent integer interval (the
    # smallest integer above an exclusive minimum, the largest below an exclusive maximum) and are
    # served at any span by the same digit walk as inclusive bounds - the value lies STRICTLY inside
    # the exclusive interval.
    ("excl_wide_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "exclusiveMinimum": 0, "exclusiveMaximum": 100000}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and 1 <= val_of(txt, "n") <= 99999),
    ("excl_onesided_min", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "exclusiveMinimum": 5}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and val_of(txt, "n") >= 6),
    ("excl_onesided_max", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "exclusiveMaximum": 50}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and val_of(txt, "n") <= 49),
    # No integer satisfies 5 < n < 6: unsatisfiable for an integer leaf, so it must be refused
    # rather than served by a generator that ignores the exclusive bounds.
    ("excl_unsatisfiable", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "exclusiveMinimum": 5, "exclusiveMaximum": 6}}},
     lambda code, txt: code == 400 and "numeric bounds" in txt),
]

bad = 0
for label, prompt, schema, ok in cases:
    code, txt = ask(prompt, schema)
    good = ok(code, txt)
    if not good:
        bad += 1
    print("%-20s status=%s ok=%s text=%s" % (label, code, good, txt[:140]))
print("VERDICT: " + ("PASS - emitted values inside the bounds; refused shapes still 400"
                     if bad == 0 else "FAIL cases=%d" % bad))
sys.exit(1 if bad else 0)
