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
        # F431: the refusal message now names several supported surfaces, so a 200-character window
        # can end before the clause under test - keep enough of the body to assert on the reason.
        return e.code, e.read().decode()[:900]


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
    # F430: an UNBOUNDED multipleOf above 999 - the suffix width is chosen from the multiplier's
    # digit count, so 5000 needs four digits and 250000 six. The value must be a multiple.
    ("multipleof_unbounded_5000", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "multipleOf": 5000}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and val_of(txt, "n") % 5000 == 0),
    ("multipleof_unbounded_250000", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "multipleOf": 250000}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and val_of(txt, "n") % 250000 == 0),
    # F431: two leaves each carrying multipleOf. Either both keys come back each satisfying its own
    # multiplier, or the request is refused - what must not happen is a served object that silently
    # drops the second key while claiming to honour the schema.
    # F438: two integer leaves with their own multipleOf are now WRITTEN (each value a multiple of
    # its own multiplier); a leaf without multipleOf beside one with it still has no writer and stays
    # refused. Before F438 the first case answered only {"a":...}, dropping b.
    ("multi_leaf_multipleof", "Give the numbers.",
     {"type": "object", "properties": {"a": {"type": "integer", "multipleOf": 3},
                                       "b": {"type": "integer", "multipleOf": 5}}},
     lambda code, txt: code == 200 and isinstance(json.loads(txt).get("a"), int)
     and isinstance(json.loads(txt).get("b"), int)
     and json.loads(txt)["a"] % 3 == 0 and json.loads(txt)["b"] % 5 == 0),
    ("multi_leaf_mixed_refuse", "Give the numbers.",
     {"type": "object", "properties": {"a": {"type": "integer", "multipleOf": 3},
                                       "b": {"type": "integer"}}},
     lambda code, txt: code == 400 and "multipleOf" in txt),
    # F433: a NUMBER leaf with multipleOf. The suffix writer only serves integer leaves, so either
    # the served number is a multiple or the request is refused - an arbitrary number that happens
    # not to be one is exactly the silent violation this surface keeps closing.
    ("number_leaf_multipleof", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "multipleOf": 3}}},
     lambda code, txt: (code == 400 and "multipleOf" in txt)
     or (code == 200 and val_of(txt, "n") is not None
         and val_of(txt, "n") % 3 == 0)),
    # F437: the draft-4 BOOLEAN form is now honoured - it tightens the inclusive literal it sits
    # beside, so minimum 5 with exclusiveMinimum true must serve a value ABOVE 5 (F436 refused the
    # request instead; before that it served 5). The isolated boolean stays refused.
    ("exclusive_bool_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": 5,
                                             "exclusiveMinimum": True}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and val_of(txt, "n") > 5),
    ("exclusive_bool_alone_refuse", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "exclusiveMinimum": True}}},
     lambda code, txt: code == 400 and "exclusive" in txt),
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
    # F423: both an inclusive and an exclusive bound on the same side - the STRICTER governs, so the
    # looser inclusive bound no longer shadows a stricter exclusive one.
    ("mix_strict_min", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": 1, "exclusiveMinimum": 5}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and val_of(txt, "n") >= 6),
    ("mix_strict_max", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "maximum": 50, "exclusiveMaximum": 20}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and val_of(txt, "n") <= 19),
    # Here the inclusive bound is the stricter one, so it governs (n >= 5, not n >= 2).
    ("mix_incl_stricter", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": 5, "exclusiveMinimum": 1}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and val_of(txt, "n") >= 5),
    # n >= 5 AND n < 5 is empty: unsatisfiable, so it must be refused.
    ("mix_unsatisfiable", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": 5, "exclusiveMaximum": 5}}},
     lambda code, txt: code == 400 and "numeric bounds" in txt),
    # F424: a bounded multipleOf above the old 1000 guard - the smallest multiple in the interval is
    # served deterministically (the subset argument); the value must be exactly that multiple.
    ("bigmult_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": 1, "maximum": 1000000, "multipleOf": 5000}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and val_of(txt, "n") == 5000),
    # No multiple of 5000 lies in [1, 4000]: unsatisfiable, so it must be refused.
    ("bigmult_unsatisfiable", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": 1, "maximum": 4000, "multipleOf": 5000}}},
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
