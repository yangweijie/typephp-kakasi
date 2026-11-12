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


def is_mult(x, m):
    # F469: a fractional multiplier needs a tolerance test - x/m must be an integer to within
    # float noise (0.3 is not exact in binary, so x % m would lie).
    try:
        return abs(x / m - round(x / m)) < 1e-6
    except Exception:
        return False

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
    # F443: the suffix width follows the multiplier up to nine digits, so 2,000,000 (formerly the
    # "beyond the mechanism" case) and a nine-digit multiplier are both served as exact multiples.
    ("multipleof_unbounded_2000000", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "multipleOf": 2000000}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and val_of(txt, "n") % 2000000 == 0),
    ("multipleof_unbounded_987654321", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "multipleOf": 987654321}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and val_of(txt, "n") % 987654321 == 0),
    # F508: fractional multipliers on a multi-leaf all-integer plan fold per leaf (0.3 -> 3).
    ("multi_mult_frac_ok", "Give the numbers.",
     {"type": "object", "properties": {"a": {"type": "integer", "multipleOf": 0.3},
                                       "b": {"type": "integer", "multipleOf": 0.5}}},
     lambda code, txt: (code == 200 and isinstance(val_of(txt, "a"), int)
                        and isinstance(val_of(txt, "b"), int)
                        and val_of(txt, "a") % 3 == 0)),
    # F507: a multi-leaf all-integer plan with multipliers beyond the suffix width - zero per leaf.
    ("multi_mult_huge_ok", "Give the numbers.",
     {"type": "object", "properties": {"a": {"type": "integer", "multipleOf": 2000000000},
                                       "b": {"type": "integer", "multipleOf": 2000000001}}},
     lambda code, txt: (code == 200 and val_of(txt, "a") == 0 and val_of(txt, "b") == 0)),
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
    # F509: one integer leaf with multipleOf beside a plain integer leaf - the mixed plan rewrites
    # the constrained leaf to z (constant 0, a multiple of 3) and emits the other normally. This
    # used to be refused because no writer covered the combination.
    ("multi_leaf_mixed_ok", "Give the numbers.",
     {"type": "object", "properties": {"a": {"type": "integer", "multipleOf": 3},
                                       "b": {"type": "integer"}}},
     lambda code, txt: code == 200 and isinstance(json.loads(txt).get("a"), int)
     and isinstance(json.loads(txt).get("b"), int)
     and json.loads(txt)["a"] % 3 == 0),
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
    # F439: a NUMBER leaf is served when its interval holds an integer (an integer is a valid
    # number), so [1.5, 100.5] now answers an integer in [2,100] where it used to be refused. The
    # interval with no integer still needs a decimal writer and stays refused.
    ("float_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 1.5, "maximum": 100.5}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and 2 <= val_of(txt, "n") <= 100),
    # F440: an interval with no integer but a tenths grid is served with ONE decimal place (1.5),
    # which is what this used to refuse; finer fractions have no tenths grid and stay refused.
    ("float_dec_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 1.5, "maximum": 1.9}}},
     lambda code, txt: code == 200 and txt.strip() == '{"n":1.5}'),
    # F441: two fraction digits are served on the hundredths grid (1.51); a three-digit interval has
    # no grid at this width and stays refused.
    ("float_dec2_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 1.51, "maximum": 1.59}}},
     lambda code, txt: code == 200 and txt.strip() == '{"n":1.51}'),
    # F445/F450/F456: three fraction digits on the thousandths grid, four on ten-thousandths and
    # five on hundred-thousandths; six have no grid at this width and stay refused. (The five-digit
    # refusal from F450 was flipped here; the six-digit case is its control.)
    ("float_dec3_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 1.511, "maximum": 1.519}}},
     lambda code, txt: code == 200 and txt.strip() == '{"n":1.511}'),
    ("float_dec4_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 1.5111, "maximum": 1.5119}}},
     lambda code, txt: code == 200 and txt.strip() == '{"n":1.5111}'),
    ("float_dec5_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 1.51111, "maximum": 1.51119}}},
     lambda code, txt: code == 200 and txt.strip() == '{"n":1.51111}'),
    # F473: six fraction digits ride the millionths grid (marker -994) - the deterministic scaled
    # minimum is emitted; seven digits have no grid and keep the refusal.
    ("float_dec6_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 1.511111, "maximum": 1.511119}}},
     lambda code, txt: code == 200 and txt.strip() == '{"n":1.511111}'),
    ("float_dec7_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 1.5111111, "maximum": 1.5111119}}},
     lambda code, txt: code == 200 and txt.strip() == '{"n":1.5111111}'),
    # F512: seven fraction places on the multiplier too (0.0001234 x 2 = 0.0002468).
    ("num_mult_frac_d7_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 0.0002, "maximum": 0.001, "multipleOf": 0.0001234}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "n"), (int, float))
     and 0.0002 <= val_of(txt, "n") <= 0.001 and is_mult(val_of(txt, "n"), 0.0001234)),
    # F513: eight fraction places on the bounds grid and the multiplier (-992 marker).
    ("float_dec8_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 1.51111111, "maximum": 1.51111119}}},
     lambda code, txt: code == 200 and txt.strip() == '{"n":1.51111111}'),
    ("float_dec9_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 1.511111111, "maximum": 1.511111119}}},
     lambda code, txt: code == 200 and txt.strip() == '{"n":1.511111111}'),
    # F516: nine fraction places on the multiplier (0.000123456 x 2 = 0.000246912).
    ("num_mult_frac_d9_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 0.0002, "maximum": 0.001, "multipleOf": 0.000123456}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "n"), (int, float))
     and 0.0002 <= val_of(txt, "n") <= 0.001 and is_mult(val_of(txt, "n"), 0.000123456)),
    ("marker_window_nine_refuse", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "maximum": -991}}},
     lambda code, txt: code == 400 and "numeric bounds" in txt),
    ("num_mult_frac_d8_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 0.0002, "maximum": 0.001, "multipleOf": 0.00012345}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "n"), (int, float))
     and 0.0002 <= val_of(txt, "n") <= 0.001 and is_mult(val_of(txt, "n"), 0.00012345)),
    ("items_num_dec8_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array", "items": {"type": "number", "minimum": 1.51111111, "maximum": 1.51111119}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list) and len(val_of(txt, "nums")) > 0
     and all(isinstance(v, (int, float)) and 1.51111111 <= v <= 1.51111119 for v in val_of(txt, "nums"))),
    # F514: an integer maximum inside the decimal marker window (-999..-992) is refused instead of
    # answered with a scaled decimal (the F513 binary returned 200 {"n":-0.02000000} for the wide
    # case); just outside the window the magnitude walk keeps serving, and a narrow span stays
    # served through the candidates tier. The narrow control keeps every enumerated value at three
    # digits or fewer: a four-digit NEGATIVE candidate (e.g. -1000) currently comes back as
    # truncated JSON ({"n":-1000, 200, json=bad) - a separate engine-level defect banked for the
    # next increment, unrelated to the marker window (positive 1000 completes).
    ("marker_window_wide_refuse", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": -2000000, "maximum": -992}}},
     lambda code, txt: code == 400 and "numeric bounds" in txt),
    ("marker_window_one_refuse", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "maximum": -992}}},
     lambda code, txt: code == 400 and "numeric bounds" in txt),
    ("marker_window_mult_refuse", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": -2000000, "maximum": -992, "multipleOf": 992}}},
     lambda code, txt: code == 400 and "numeric bounds" in txt),
    ("marker_window_narrow_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": -998, "maximum": -992}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "n"), int)
     and -998 <= val_of(txt, "n") <= -992),
    ("marker_window_below_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": -2000000, "maximum": -1000}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "n"), int)
     and -2000000 <= val_of(txt, "n") <= -1000),
    # F442: negative decimal bounds ride the same grid; the sign is prefixed after the magnitude.
    ("float_dec_neg", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": -1.9, "maximum": -1.5}}},
     lambda code, txt: code == 200 and txt.strip() == '{"n":-1.9}'),
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
    # F505: a multiplier beyond the forced-suffix width is served by the constant 0 - a multiple
    # of every M - instead of being refused.
    ("num_mult_unb_huge_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "multipleOf": 2000000000}}},
     lambda code, txt: code == 200 and val_of(txt, "n") == 0),
    ("num_mult_unb_huge_num_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "multipleOf": 2000000000}}},
     lambda code, txt: code == 200 and val_of(txt, "n") == 0),
    # F506: an unbounded items multiplier beyond the suffix width is served by 0 per element
    # (0 is a multiple of every M).
    ("items_mult_unb_huge_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "multipleOf": 2000000000}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(x == 0 for x in val_of(txt, "nums"))),
    # F517: uniqueItems:true on a FINITE enum-item domain - the writer forces the distinct values in
    # declaration order, so uniqueness holds by construction; minItems must fit and pattern items
    # (an unbounded value space) keep the refusal.
    ("uniq_enum_ok", "Name some cities.",
     {"type": "object", "properties": {"cities": {"type": "array",
                                                  "items": {"type": "string", "enum": ["paris", "lyon", "nice"]},
                                                  "uniqueItems": True, "minItems": 2}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "cities"), list)
     and 2 <= len(val_of(txt, "cities")) <= 3
     and len(set(val_of(txt, "cities"))) == len(val_of(txt, "cities"))
     and all(x in ("paris", "lyon", "nice") for x in val_of(txt, "cities"))),
    ("uniq_enum_unsat_refuse", "Name some cities.",
     {"type": "object", "properties": {"cities": {"type": "array",
                                                  "items": {"type": "string", "enum": ["paris", "lyon", "nice"]},
                                                  "uniqueItems": True, "minItems": 4}}},
     lambda code, txt: code == 400 and "uniqueItems" in txt),
    ("uniq_enum_pat_refuse", "Name some codes.",
     {"type": "object", "properties": {"cities": {"type": "array",
                                                  "items": {"type": "string", "pattern": "^[A-Z]{3}$"},
                                                  "uniqueItems": True}}},
     lambda code, txt: code == 400 and "uniqueItems" in txt),
    # F518 probe: element-count bounds on a multipleOf-items array - the item writer already takes
    # the bounds argument, so the count should hold element by element.
    ("items_mult_min_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "minimum": 1, "maximum": 1000000, "multipleOf": 5000},
                                                "minItems": 3, "maxItems": 3}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) == 3
     and all(isinstance(x, int) and x % 5000 == 0 and 1 <= x <= 1000000 for x in val_of(txt, "nums"))),
    ("items_mult_unique_refuse", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "minimum": 1, "maximum": 1000000, "multipleOf": 5000},
                                                "uniqueItems": True}}},
     lambda code, txt: code == 400 and "uniqueItems" in txt),
    # F519 probe: a one-value domain (const) and a finite literal-alternation pattern are enum
    # plans too, so uniqueItems:
    ("uniq_const_ok", "Name a code.",
     {"type": "object", "properties": {"codes": {"type": "array",
                                                 "items": {"type": "string", "const": "paris"},
                                                 "uniqueItems": True}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "codes"), list)
     and len(val_of(txt, "codes")) == 1 and val_of(txt, "codes")[0] == "paris"),
    ("uniq_const_min2_refuse", "Name a code.",
     {"type": "object", "properties": {"codes": {"type": "array",
                                                 "items": {"type": "string", "const": "paris"},
                                                 "uniqueItems": True, "minItems": 2}}},
     lambda code, txt: code == 400 and "uniqueItems" in txt),
    ("uniq_alt_ok", "Name some cities.",
     {"type": "object", "properties": {"cities": {"type": "array",
                                                  "items": {"type": "string", "pattern": "^(paris|lyon)$"},
                                                  "uniqueItems": True, "minItems": 2}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "cities"), list)
     and len(val_of(txt, "cities")) == 2 and len(set(val_of(txt, "cities"))) == 2
     and all(x in ("paris", "lyon") for x in val_of(txt, "cities"))),
    # F520: numeric enum/const items are a finite value table too - the writer emits the bare
    # literals (the plan carries a "#" prefix), and uniqueItems forces distinct members.
    ("items_num_enum_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "enum": [2, 5, 9]}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1 and all(x in (2, 5, 9) for x in val_of(txt, "nums"))),
    ("items_num_enum_unique_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "enum": [2, 5, 9]},
                                                "uniqueItems": True, "minItems": 2}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 2 and len(set(val_of(txt, "nums"))) == len(val_of(txt, "nums"))
     and all(x in (2, 5, 9) for x in val_of(txt, "nums"))),
    ("items_num_const_ok", "Give the number.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "number", "const": 7}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1 and all(x == 7 for x in val_of(txt, "nums"))),
    ("items_num_const_unique_min2_refuse", "Give the number.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "number", "const": 7},
                                                "uniqueItems": True, "minItems": 2}}},
     lambda code, txt: code == 400 and "uniqueItems" in txt),
    # F521: boolean enum/const items are the same finite table (bare true/false), with uniqueItems
    # forcing both members when the minimum asks for two.
    ("items_bool_enum_ok", "Give the flags.",
     {"type": "object", "properties": {"flags": {"type": "array",
                                                 "items": {"type": "boolean", "enum": [True, False]}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "flags"), list)
     and len(val_of(txt, "flags")) >= 1 and all(isinstance(x, bool) for x in val_of(txt, "flags"))),
    ("items_bool_enum_unique_ok", "Give the flags.",
     {"type": "object", "properties": {"flags": {"type": "array",
                                                 "items": {"type": "boolean", "enum": [True, False]},
                                                 "uniqueItems": True, "minItems": 2}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "flags"), list)
     and sorted(val_of(txt, "flags")) == [False, True]),
    ("items_bool_const_ok", "Give the flags.",
     {"type": "object", "properties": {"flags": {"type": "array",
                                                 "items": {"type": "boolean", "const": True}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "flags"), list)
     and len(val_of(txt, "flags")) >= 1 and all(x is True for x in val_of(txt, "flags"))),
    # served deterministically (the subset argument); the value must be exactly that multiple.
    ("bigmult_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": 1, "maximum": 1000000, "multipleOf": 5000}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and val_of(txt, "n") == 5000),
    # No multiple of 5000 lies in [1, 4000]: unsatisfiable, so it must be refused.
    ("bigmult_unsatisfiable", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": 1, "maximum": 4000, "multipleOf": 5000}}},
     lambda code, txt: code == 400 and "numeric bounds" in txt),
    # F462: bounded NUMBER items - an interval holding no integer is served deterministically on
    # the F440-F456 decimal grid (every element equals the scaled minimum, inside the interval).
    ("items_num_dec_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "number", "minimum": 1.5, "maximum": 1.9}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, (int, float)) and 1.5 <= x <= 1.9 for x in val_of(txt, "nums"))),
    # Integer-valued number items (an integer is a valid number): each element walks the digit grid.
    ("items_num_int_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "number", "minimum": 1, "maximum": 9}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, (int, float)) and 1 <= x <= 9 for x in val_of(txt, "nums"))),
    # One-sided number items: the absent side rides the -1 sentinel, values stay <= 9.5.
    ("items_num_oneside_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "number", "maximum": 9.5}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, (int, float)) and x <= 9.5 for x in val_of(txt, "nums"))),
    # F473: six fraction places now ride the millionths grid, so the number-ITEMS route serves the
    # same interval through the wrapped shape judgement (0.000123 is the scaled minimum). Seven
    # places still have no grid and stay refused (see float_dec7_refuse).
    ("items_num_frac6_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "number", "minimum": 0.000123, "maximum": 0.000129}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, (int, float)) and abs(x - 0.000123) < 1e-12 for x in val_of(txt, "nums"))),
    # multipleOf inside items is queued (the multiple writer is property-level): stays refused.
    # F466: the old refusal of a number leaf with multipleOf was over-refusal - an integer
    # multiple of M is a legal number and a multiple of M, so the bounded fold serves it.
    # (The former items_mult_num_refuse case, same schema, was flipped into items_mult_num_ok.)
    ("items_num_multi_refuse", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "number", "minimum": 1, "multipleOf": 3}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, (int, float)) and x % 3 == 0 and x >= 1 for x in val_of(txt, "nums"))),
    # F463: a one-sided NEGATIVE maximum on a single property - the magnitude walks from |hi|
    # upward behind a forced dash, so every value is <= hi. (Before F463 this silently fell back
    # to unconstrained generation, ignoring the bound - the violation this case pins.)
    ("negmax_single_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "maximum": -3}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and val_of(txt, "n") <= -3),
    # F463: the same one-sided negative maximum on number items - each element <= -3.
    ("items_num_negmax_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "number", "maximum": -3}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, (int, float)) and x <= -3 for x in val_of(txt, "nums"))),
    # F464: a one-sided NEGATIVE minimum on a single property - the magnitude walk [1, |lo|] gives
    # values in [lo, -1], all >= lo (subset; the no-max side is free). Pinned on the number-items
    # route too, which was already serving this shape without a gate.
    ("negmin_single_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": -5}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), int) and val_of(txt, "n") >= -5),
    ("items_num_negmin_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "number", "minimum": -5}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, (int, float)) and x >= -5 for x in val_of(txt, "nums"))),
    # F465: unbounded multipleOf items - the suffix writer emits a multiple of M per element.
    ("items_mult_unb_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "multipleOf": 3}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, int) and x % 3 == 0 for x in val_of(txt, "nums"))),
    # Bounded multipleOf items - the interval collapses to its smallest multiple, one value.
    ("items_mult_bnd_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "minimum": 1, "maximum": 1000000, "multipleOf": 5000}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, int) and x == 5000 for x in val_of(txt, "nums"))),
    # No multiple of 5000 lies in [1, 4000]: unsatisfiable, stays refused.
    ("items_mult_unsat_refuse", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "minimum": 1, "maximum": 4000, "multipleOf": 5000}}}},
     lambda code, txt: code == 400 and ("items" in txt or "numeric bounds" in txt)),
    # F466: an INTEGER multipleOf on a number leaf is served through the integer-multiple subset
    # (an integer is a legal number and a multiple of M) - a fractional multiplier still refused.
    ("items_mult_num_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "number", "multipleOf": 3}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, (int, float)) and x % 3 == 0 for x in val_of(txt, "nums"))),
    ("num_mult_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 1.5, "maximum": 9.5, "multipleOf": 3}}},
     lambda code, txt: code == 200 and val_of(txt, "n") is not None
     and isinstance(val_of(txt, "n"), (int, float)) and val_of(txt, "n") % 3 == 0
     and 1.5 <= val_of(txt, "n") <= 9.5),
    # F503: an UNBOUNDED number leaf is served by the suffix writer - the emitted integer is a
    # legal number and a multiple of the same reduced numerator p.
    ("num_mult_frac_unb_num_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "multipleOf": 0.3}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "n"), int)
     and val_of(txt, "n") % 3 == 0),
    ("num_mult_unb_num_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "multipleOf": 3}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "n"), int)
     and val_of(txt, "n") % 3 == 0),
    # F467: multipleOf plus EXCLUSIVE bounds on items. The detector now wraps the items block and
    # asks the same shape judgement the single-property route uses, so the numeric exclusive form,
    # the draft-4 boolean form and the number leaf all fold to the interval's smallest multiple
    # (3 here) element by element.
    ("items_mult_excl_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "exclusiveMinimum": 0, "maximum": 9, "multipleOf": 3}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, int) and x % 3 == 0 and 0 < x <= 9 for x in val_of(txt, "nums"))),
    ("items_mult_excl_bool_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "minimum": 0, "exclusiveMinimum": True, "multipleOf": 3}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, int) and x % 3 == 0 and x > 0 for x in val_of(txt, "nums"))),
    ("items_mult_num_excl_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "number", "exclusiveMinimum": 2.5, "maximum": 9.5, "multipleOf": 3}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, (int, float)) and x % 3 == 0 and 2.5 < x <= 9.5 for x in val_of(txt, "nums"))),
    # No multiple of 3 lies strictly above 0 and at most 2: unsatisfiable, stays refused.
    ("items_mult_excl_unsat_refuse", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "exclusiveMinimum": 0, "maximum": 2, "multipleOf": 3}}}},
     lambda code, txt: code == 400 and ("items" in txt or "numeric bounds" in txt)),
    # F497: an INTEGER item folds a fractional multiplier through its reduced numerator - integer
    # multiples of 0.3 (=3) are 3/6/9. Unbounded fractional and number-typed fractional items keep
    # their refusals (controls below).
    ("items_mult_excl_frac_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "exclusiveMinimum": 0, "maximum": 9, "multipleOf": 0.3}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, int) and x % 3 == 0 and 0 < x <= 9 for x in val_of(txt, "nums"))),
    # A NUMBER item is already served by the number-items writer (F462/F469) on the decimal grid -
    # its values are multiples of 0.3 as decimals, not integers. Control for the integer route.
    ("items_mult_frac_num_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "number", "minimum": 1, "maximum": 9, "multipleOf": 0.3}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, (int, float)) and 1 <= x <= 9 and is_mult(x, 0.3)
             for x in val_of(txt, "nums"))),
    # F498: UNBOUNDED fractional integer items - every multiple of the reduced numerator p is a
    # multiple of p/q, and the suffix writer emits a multiple of its argument.
    ("items_mult_frac_unb_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "multipleOf": 0.3}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, int) and x % 3 == 0 for x in val_of(txt, "nums"))),
    # F468: exclusive bounds on integer items WITHOUT a multipleOf - the same wrap-to-shape
    # judgement as the single-property route, so the numeric exclusive and draft-4 boolean
    # spellings are honoured element by element.
    ("items_excl_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "exclusiveMinimum": 0, "maximum": 9}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, int) and 0 < x <= 9 for x in val_of(txt, "nums"))),
    ("items_excl_bool_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "minimum": 0, "exclusiveMinimum": True, "maximum": 9}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, int) and 0 < x <= 9 for x in val_of(txt, "nums"))),
    # No integer is both > 2 and <= 2: unsatisfiable, stays refused.
    ("items_excl_unsat_refuse", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "exclusiveMinimum": 2, "maximum": 2}}}},
     lambda code, txt: code == 400 and ("items" in txt or "numeric bounds" in txt)),
    # Control: the number leaf already took this shape through the F462 detector - pinned here so
    # the two item routes are read together.
    ("items_num_excl_nomult_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "number", "exclusiveMinimum": 2.5, "maximum": 9.5}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, (int, float)) and 2.5 < x <= 9.5 for x in val_of(txt, "nums"))),
    # F469: a FRACTIONAL multipleOf on a bounded number leaf - the bounds and M scale to a decimal
    # grid and fold to the interval's smallest multiple (1.2 here), emitted through the existing
    # decimal writer. The value must be a multiple of 0.3 (tolerance test) and inside the bounds.
    ("num_mult_frac_served", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 1, "maximum": 2, "multipleOf": 0.3}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "n"), (int, float))
     and 1 <= val_of(txt, "n") <= 2 and is_mult(val_of(txt, "n"), 0.3)),
    ("num_mult_frac_neg_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": -2, "maximum": -1, "multipleOf": 0.3}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "n"), (int, float))
     and -2 <= val_of(txt, "n") <= -1 and is_mult(val_of(txt, "n"), 0.3)),
    # No integer lies in [1.5, 1.9], but 1.5 and 1.8 are multiples of 0.3 - the lo>hi grid path.
    ("num_mult_frac_noint_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 1.5, "maximum": 1.9, "multipleOf": 0.3}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "n"), (int, float))
     and 1.5 <= val_of(txt, "n") <= 1.9 and is_mult(val_of(txt, "n"), 0.3)),
    ("items_mult_frac_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "number", "minimum": 1, "maximum": 2, "multipleOf": 0.3}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, (int, float)) and 1 <= x <= 2 and is_mult(x, 0.3) for x in val_of(txt, "nums"))),
    # No multiple of 0.3 lies in [1, 1.1]: unsatisfiable, stays refused.
    ("num_mult_frac_unsat_refuse", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 1, "maximum": 1.1, "multipleOf": 0.3}}},
     lambda code, txt: code == 400 and "numeric bounds" in txt),
    # F496: an INTEGER leaf folds a fractional multiplier through its reduced numerator - integer
    # multiples of p/q are exactly the multiples of p. 0.3 -> multiples of 3, 1.5 -> multiples of 3.
    # The candidates tier enumerates the same filtered set (its old literal prefix read 0.3 as 0).
    ("num_mult_frac_intleaf_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": 1, "maximum": 9, "multipleOf": 0.3}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "n"), int)
     and 1 <= val_of(txt, "n") <= 9 and val_of(txt, "n") % 3 == 0),
    ("num_mult_frac_intleaf_15_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": 1, "maximum": 9, "multipleOf": 1.5}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "n"), int)
     and 1 <= val_of(txt, "n") <= 9 and val_of(txt, "n") % 3 == 0),
    # F499: UNBOUNDED integer leaf with a fractional multiplier - the suffix writer receives the
    # reduced numerator p (multiples of p are multiples of p/q); 0.25 reduces to p=1, which is
    # satisfied by every integer and falls through to the plain integer generator.
    ("num_mult_frac_unb_int_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "multipleOf": 0.3}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "n"), int)
     and val_of(txt, "n") % 3 == 0),
    # F500: 0.25 reduces to p=1, satisfied by every integer; the suffix writer now accepts 1, so
    # the shape is served with a wrapped integer instead of F499's explicit refusal.
    ("num_mult_frac_unb_quarter_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "multipleOf": 0.25}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "n"), int)),
    # No integer multiple of 0.3 (=3) lies in [1,2]: still unsatisfiable, stays refused.
    ("num_mult_frac_intleaf_unsat_refuse", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "integer", "minimum": 1, "maximum": 2, "multipleOf": 0.3}}},
     lambda code, txt: code == 400 and "numeric bounds" in txt),
    # F501: fractional multiplier with EXCLUSIVE bounds on a number leaf - the fold steps past a
    # strict literal on the decimal grid; no multiple lies strictly between 1.2 and 1.5.
    ("num_mult_frac_excl_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "exclusiveMinimum": 1.2, "maximum": 9, "multipleOf": 0.3}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "n"), (int, float))
     and 1.2 < val_of(txt, "n") <= 9 and is_mult(val_of(txt, "n"), 0.3)),
    ("num_mult_frac_exclmax_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 1, "exclusiveMaximum": 1.5, "multipleOf": 0.3}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "n"), (int, float))
     and 1 <= val_of(txt, "n") < 1.5 and is_mult(val_of(txt, "n"), 0.3)),
    ("num_mult_frac_excl_unsat_refuse", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "exclusiveMinimum": 1.2, "exclusiveMaximum": 1.5, "multipleOf": 0.3}}},
     lambda code, txt: code == 400 and "numeric bounds" in txt),
    # F502: six fraction places on the multiplier and the bounds (the writer's -994 marker).
    # 0.000123 is used instead of 0.000001: json.dumps writes very small floats in exponent form
    # ("1e-06"), which the schema surface refuses by design, so it would not exercise the 6-place
    # decimal path.
    ("num_mult_frac_d6_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 0.0002, "maximum": 0.001, "multipleOf": 0.000123}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "n"), (int, float))
     and 0.0002 <= val_of(txt, "n") <= 0.001 and is_mult(val_of(txt, "n"), 0.000123)),
    ("num_mult_frac_d6_unsat_refuse", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 0.0002, "maximum": 0.0002, "multipleOf": 0.000123}}},
     lambda code, txt: code == 400 and "numeric bounds" in txt),
    # F501/F504: a NUMBER items leaf with a fractional multiplier and an EXCLUSIVE bound - the
    # number-items detector wraps the items block into the same shape helper, so the strict-side
    # grid fold applies element by element (smallest multiple above 1.2 is 1.5).
    ("items_mult_frac_num_excl_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "number", "exclusiveMinimum": 1.2, "maximum": 9, "multipleOf": 0.3}}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "nums"), list)
     and len(val_of(txt, "nums")) >= 1
     and all(isinstance(x, (int, float)) and 1.2 < x <= 9 and is_mult(x, 0.3)
             for x in val_of(txt, "nums"))),
    # F478: a ONE-SIDED inclusive bound with a fractional multiplier - min-only folds to the
    # smallest multiple at or above it; max-only is 0 when the bound is non-negative (0 is a
    # multiple of every M) or the largest negative multiple otherwise.
    ("num_mult_frac_minonly_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "minimum": 1, "multipleOf": 0.3}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "n"), (int, float))
     and val_of(txt, "n") >= 1 and is_mult(val_of(txt, "n"), 0.3)),
    ("num_mult_frac_maxonly_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "maximum": 0.2, "multipleOf": 0.3}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "n"), (int, float))
     and val_of(txt, "n") <= 0.2 and is_mult(val_of(txt, "n"), 0.3)),
    ("num_mult_frac_maxneg_ok", "Give the number.",
     {"type": "object", "properties": {"n": {"type": "number", "maximum": -1, "multipleOf": 0.3}}},
     lambda code, txt: code == 200 and isinstance(val_of(txt, "n"), (int, float))
     and val_of(txt, "n") <= -1 and is_mult(val_of(txt, "n"), 0.3)),
    # F509: a mixed object whose UNBOUNDED integer leaf carries multipleOf - that leaf is the
    # constant 0 (a multiple of every M); a bounded leaf and a strict required subset keep refusing.
    ("mixed_mult_zero_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"},
                                       "count": {"type": "integer", "multipleOf": 0.3}}},
     lambda code, txt: code == 200 and isinstance(json.loads(txt).get("city"), str)
     and json.loads(txt).get("count") == 0),
    ("mixed_mult_allreq_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"},
                                       "count": {"type": "integer", "multipleOf": 0.3}},
      "required": ["city", "count"]},
     lambda code, txt: code == 200 and isinstance(json.loads(txt).get("city"), str)
     and json.loads(txt).get("count") == 0),
    # F510: a BOUNDED integer leaf in a mixed object folds to one constant (3 here) and is emitted
    # as that constant; a decimal-grid fold (number leaf, fractional M) and a bound without a
    # multiplier keep the refusal.
    ("mixed_mult_bounded_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"},
                                       "count": {"type": "integer", "minimum": 1, "maximum": 9, "multipleOf": 0.3}}},
     lambda code, txt: code == 200 and json.loads(txt).get("count") == 3
     and ("city" not in json.loads(txt) or isinstance(json.loads(txt)["city"], str))),
    # With both keys required the candidates tier cannot render city, so the mixed writer serves
    # the plan and the q leaf emits its folded constant - this is the case that exercises q.
    ("mixed_mult_bounded_allreq_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"},
                                       "count": {"type": "integer", "minimum": 1, "maximum": 9, "multipleOf": 0.3}},
      "required": ["city", "count"]},
     lambda code, txt: code == 200 and isinstance(json.loads(txt).get("city"), str)
     and json.loads(txt).get("count") == 3),
    # F511: a NUMBER leaf with a fractional multiplier folds on the decimal grid; the plan carries
    # the reconstructed literal (1.2) and the mixed writer emits it.
    ("mixed_mult_frac_bounded_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"},
                                       "count": {"type": "number", "minimum": 1, "maximum": 9, "multipleOf": 0.3}}},
     lambda code, txt: code == 200 and isinstance(json.loads(txt).get("count"), (int, float))
     and abs(json.loads(txt)["count"] - 1.2) < 1e-9),
    ("mixed_mult_frac_d2_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"},
                                       "count": {"type": "number", "minimum": 1.1, "maximum": 9, "multipleOf": 0.25}}},
     lambda code, txt: code == 200 and isinstance(json.loads(txt).get("count"), (int, float))
     and abs(json.loads(txt)["count"] - 1.25) < 1e-9),
    ("mixed_mult_frac_unsat_refuse", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"},
                                       "count": {"type": "number", "minimum": 1, "maximum": 1.1, "multipleOf": 0.3}}},
     lambda code, txt: code == 400 and "numeric bounds" in txt),
    ("mixed_excl_nofold_refuse", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"},
                                       "count": {"type": "integer", "exclusiveMinimum": 5}}},
     lambda code, txt: code == 400 and "numeric bounds" in txt),
    ("mixed_mult_subset_refuse", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"},
                                       "count": {"type": "integer", "multipleOf": 0.3}},
      "required": ["city"]},
     lambda code, txt: code == 400 and "required" in txt),
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
