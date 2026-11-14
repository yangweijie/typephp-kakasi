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


def obj_of(text):
    try:
        d = json.loads(text)
        return d if isinstance(d, dict) else None
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
    # F493: per-property length bounds on a MIXED shape - the mixed writer counts characters per
    # string leaf the same way the flat writer does; the integer leaf is unaffected.
    ("mixed_len_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string", "minLength": 3, "maxLength": 8},
                                       "count": {"type": "integer"}},
      "required": ["city", "count"]},
     lambda code, txt: (code == 200 and keys_of(txt) == ["city", "count"]
                        and 3 <= len(json.loads(txt)["city"]) <= 8
                        and isinstance(json.loads(txt)["count"], int))),
    # F495: this used to be refused (the budget extractor bailed on any `pattern`, so the s leaf
    # had nothing to hold it). Now the extractor lists one entry per s leaf and the mixed writer
    # enforces the city length; the p leaf writes its own fixed 3-letter code.
    ("mixed_len_pattern_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string", "minLength": 3},
                                       "code": {"type": "string", "pattern": "^[A-Z]{3}$"}}},
     lambda code, txt: (code == 200 and keys_of(txt) == ["city", "code"]
                        and len(json.loads(txt)["city"]) >= 3
                        and len(json.loads(txt)["code"]) == 3
                        and json.loads(txt)["code"].isupper())),
    # F494: a FIXED-length pattern leaf - exact spec segments make the emitted length known, so
    # minLength/maxLength that contain it hold by construction; a bound excluding it, or a ranged
    # pattern (no fixed length), keeps the refusal.
    ("pattern_len_ok", "Say the code.",
     {"type": "object", "properties": {"code": {"type": "string", "pattern": "^[A-Z]{3}$",
                                                "minLength": 3, "maxLength": 8}}},
     lambda code, txt: code == 200 and isinstance(json.loads(txt).get("code"), str)
     and len(json.loads(txt)["code"]) == 3),
    ("pattern_len_bad_refuse", "Say the code.",
     {"type": "object", "properties": {"code": {"type": "string", "pattern": "^[A-Z]{3}$",
                                                "minLength": 4}}},
     lambda code, txt: code == 400 and "minLength" in txt),
    ("pattern_len_range_refuse", "Say the code.",
     {"type": "object", "properties": {"code": {"type": "string", "pattern": "^[A-Z]{2,4}$",
                                                "minLength": 3}}},
     lambda code, txt: code == 400 and "minLength" in txt),
    # F495: a MULTI-LEAF mixed shape - the s leaf's length is enforced by the mixed writer, the p
    # leaf is fixed-length and its bound contains that length, so the pair is served; a p leaf whose
    # bound excludes the fixed length keeps the refusal.
    ("mixed_len_p_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string", "minLength": 3, "maxLength": 8},
                                       "code": {"type": "string", "pattern": "^[A-Z]{3}$",
                                                "minLength": 3},
                                       "count": {"type": "integer"}},
      "required": ["city", "code", "count"]},
     lambda code, txt: (code == 200 and keys_of(txt) == ["city", "code", "count"]
                        and 3 <= len(json.loads(txt)["city"]) <= 8
                        and len(json.loads(txt)["code"]) == 3
                        and isinstance(json.loads(txt)["count"], int))),
    ("mixed_len_p_bad_refuse", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string", "minLength": 3, "maxLength": 8},
                                       "code": {"type": "string", "pattern": "^[A-Z]{3}$",
                                                "minLength": 4},
                                       "count": {"type": "integer"}}},
     lambda code, txt: code == 400 and "minLength" in txt),
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
    # F492: the SCHEMA form of additionalProperties also names no key of its own - the writer emits
    # only the declared "cities" key, so the keyword judges nothing and the subschema is vacuously
    # satisfied (this case used to expect the refusal).
    ("addprops_schema_served", "Name some French cities.",
     {"type": "object", "properties": {"cities": {"type": "array", "items": {"type": "string"},
                                                  "maxItems": 3}},
      "additionalProperties": {"type": "string"}},
     lambda code, txt: (code == 200 and (n_items(txt, "cities") or 0) <= 3
                        and keys_of(txt) == ["cities"])),
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
    # F418 flipped this one: the required subset on a shape with a COUNTED ARRAY leaf - the filter
    # drops the array leaf from the plan and the stepper emits only the required keys; the omitted
    # key cannot violate its own bounds, and the served key set proves the omission.
    ("array_leaf_subset", "Say where you are.",
     {"type": "object", "properties": {"cities": {"type": "array", "minItems": 2, "maxItems": 2,
                                                  "items": {"type": "string"}},
                                       "note": {"type": "string"}},
      "required": ["note"]},
     lambda code, txt: code == 200 and keys_of(txt) == ["note"]),
    # F427: minProperties/maxProperties count the keys the generator emits, and those keys come from
    # the declared plan - so a bound the plan already satisfies must be honoured, and one it cannot
    # satisfy must be refused rather than answered with the wrong number of keys.
    ("minproperties_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "minProperties": 2},
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    ("minproperties_bad", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "minProperties": 3},
     lambda code, txt: code == 400 and "minProperties" in txt),
    ("maxproperties_bad", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "maxProperties": 1},
     lambda code, txt: code == 400 and "maxProperties" in txt),
    # F428: format / patternProperties / contentEncoding constrain VALUES (or keys that map to value
    # constraints) which the generator does not implement - each must be refused by name instead of
    # answered with text that violates it.
    ("format_refuse", "Say where you are.",
     {"type": "object", "properties": {"email": {"type": "string", "format": "email"}}},
     lambda code, txt: code == 400 and "format" in txt),
    ("patternprops_refuse", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}},
      "patternProperties": {"^c": {"type": "integer"}}},
     lambda code, txt: code == 400 and "patternProperties" in txt),
    # F451: the VACUOUS form is served - the pattern matches none of the keys the answer will
    # contain, so the block constrains no property. The matching form above is its control.
    ("patternprops_vacuous_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "patternProperties": {"^x": {"type": "integer"}}},
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    # F455: a MATCHING pattern is served when the bare type is what the matched key's declared kind
    # guarantees; the integer form over a string key below/above stays refused.
    ("patternprops_match_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "patternProperties": {"^c": {"type": "string"}}},
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    # F457: the enum kind exposes its values, so an enum/const/pattern subschema over a matching key
    # is provable - and the served value must come from that enum. A subschema that admits fewer
    # values than the enum stays refused (the control).
    ("patternprops_enum_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string", "enum": ["paris", "lyon"]}},
      "patternProperties": {"^c": {"enum": ["paris", "lyon"]}}},
     lambda code, txt: code == 200 and json.loads(txt).get("city") in ["paris", "lyon"]),
    ("patternprops_enum_sub_refuse", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string", "enum": ["paris", "lyon"]}},
      "patternProperties": {"^c": {"enum": ["paris"]}}},
     lambda code, txt: code == 400 and "patternProperties" in txt),
    # F476: a NUMERIC enum/const leaf kind exposes its values too - a bare integer/number, an enum
    # superset and a matching const are provable; a superset that misses an enumerant keeps refused.
    ("patternprops_num_type_ok", "Say the count.",
     {"type": "object", "properties": {"count": {"type": "integer", "enum": [1, 2, 3]}},
      "patternProperties": {"^c": {"type": "integer"}}},
     lambda code, txt: code == 200 and json.loads(txt).get("count") in [1, 2, 3]),
    ("patternprops_num_enum_ok", "Say the count.",
     {"type": "object", "properties": {"count": {"type": "integer", "enum": [1, 2, 3]}},
      "patternProperties": {"^c": {"enum": [1, 2, 3, 4]}}},
     lambda code, txt: code == 200 and json.loads(txt).get("count") in [1, 2, 3]),
    ("patternprops_num_miss_refuse", "Say the count.",
     {"type": "object", "properties": {"count": {"type": "integer", "enum": [1, 2, 3]}},
      "patternProperties": {"^c": {"enum": [5, 6]}}},
     lambda code, txt: code == 400 and "patternProperties" in txt),
    # F480: a property that is itself pattern-constrained (kind p:spec) emits a value built from
    # that spec, so a patternProperties pattern with the SAME spec is satisfied by the same
    # construction; a different spec stays refused (the control).
    ("patternprops_pattern_same_ok", "Give the code.",
     {"type": "object", "properties": {"code": {"type": "string", "pattern": "^[A-Z]{3}$"}},
      "patternProperties": {"^c": {"pattern": "^[A-Z]{3}$"}}},
     lambda code, txt: code == 200 and isinstance(json.loads(txt).get("code"), str)
     and len(json.loads(txt)["code"]) == 3 and json.loads(txt)["code"].isalpha()
     and json.loads(txt)["code"].isupper()),
    ("patternprops_pattern_diff_refuse", "Give the code.",
     {"type": "object", "properties": {"code": {"type": "string", "pattern": "^[A-Z]{3}$"}},
      "patternProperties": {"^c": {"pattern": "^[a-z]+$"}}},
     lambda code, txt: code == 400 and "patternProperties" in txt),
    # F484: an object-array property (plan kind ao:) IS an array, so a patternProperties bare
    # {"type":"array"} over its matching key is satisfied by construction; a mismatched bare type
    # stays refused (the control).
    ("patternprops_ao_type_ok", "Give the rows.",
     {"type": "object", "properties": {"rows": {"type": "array", "items": {"type": "object", "properties": {"n": {"type": "string"}}}}},
      "patternProperties": {"^r": {"type": "array"}}},
     lambda code, txt: code == 200 and isinstance(json.loads(txt).get("rows"), list)
     and len(json.loads(txt)["rows"]) >= 1
     and all(isinstance(x, dict) for x in json.loads(txt)["rows"])),
    ("patternprops_ao_str_refuse", "Give the rows.",
     {"type": "object", "properties": {"rows": {"type": "array", "items": {"type": "object", "properties": {"n": {"type": "string"}}}}},
      "patternProperties": {"^r": {"type": "string"}}},
     lambda code, txt: code == 400 and "patternProperties" in txt),
    # F458: items carrying a value constraint is outside every array writer - the before-probe
    # answered {"items":"paris"} / {"items":5}, which is not even the requested shape. Refused by
    # name; the plain-typed items control keeps being served as an array.
    # F459: the finite string-ENUM items form now has its own writer, so it is served with every
    # element drawn from the enum; pattern and numeric item constraints stay refused.
    # F470: a string CONST items form is a one-value enum - every element equals the constant.
    ("items_enum_ok", "Name some French cities.",
     {"type": "object", "properties": {"cities": {"type": "array",
                                                  "items": {"type": "string", "enum": ["paris", "lyon"]}}}},
     lambda code, txt: code == 200 and isinstance((obj_of(txt) or {}).get("cities"), list)
     and len((obj_of(txt) or {}).get("cities", [])) >= 1
     and all(x in ["paris", "lyon"] for x in (obj_of(txt) or {}).get("cities", []))),
    ("items_const_ok", "Name some French cities.",
     {"type": "object", "properties": {"cities": {"type": "array",
                                                  "items": {"type": "string", "const": "paris"}}}},
     lambda code, txt: code == 200 and isinstance((obj_of(txt) or {}).get("cities"), list)
     and len((obj_of(txt) or {}).get("cities", [])) >= 1
     and all(x == "paris" for x in (obj_of(txt) or {}).get("cities", []))),
    # F471: a string PATTERN items form the class/count stepper honours - every element matches
    # ^[A-Z]{3}$ (three uppercase letters).
    ("items_pattern_ok", "Give the codes.",
     {"type": "object", "properties": {"codes": {"type": "array",
                                                 "items": {"type": "string", "pattern": "^[A-Z]{3}$"}}}},
     lambda code, txt: code == 200 and isinstance((obj_of(txt) or {}).get("codes"), list)
     and len((obj_of(txt) or {}).get("codes", [])) >= 1
     and all(isinstance(x, str) and len(x) == 3 and x.isalpha() and x.isupper()
             for x in (obj_of(txt) or {}).get("codes", []))),
    # F477: a fully anchored plain-literal alternation is a finite set, so every element is one of
    # its members (this case used to expect the refusal). A metacharacter member is not its own
    # literal match and keeps the refusal (control).
    ("items_pattern_alt_ok", "Name some French cities.",
     {"type": "object", "properties": {"cities": {"type": "array",
                                                  "items": {"type": "string", "pattern": "^(paris|lyon)$"}}}},
     lambda code, txt: code == 200 and isinstance((obj_of(txt) or {}).get("cities"), list)
     and len((obj_of(txt) or {}).get("cities", [])) >= 1
     and all(x in ["paris", "lyon"] for x in (obj_of(txt) or {}).get("cities", []))),
    ("items_pattern_metachar_refuse", "Give the codes.",
     {"type": "object", "properties": {"codes": {"type": "array",
                                                 "items": {"type": "string", "pattern": "^a.c$"}}}},
     lambda code, txt: code == 400 and ("metachar" in txt or "items" in txt or "pattern" in txt)),
    # F460: both-bounds integer items are served element by element; a one-sided bound is not
    # (the writer's sentinel conventions are not reused here) and stays refused by name.
    ("items_bounded_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "minimum": 5, "maximum": 9}}}},
     lambda code, txt: code == 200 and isinstance((obj_of(txt) or {}).get("nums"), list)
     and len((obj_of(txt) or {}).get("nums", [])) >= 1
     and all(isinstance(x, int) and 5 <= x <= 9 for x in (obj_of(txt) or {}).get("nums", []))),
    # F461: one-sided integer items are served - the missing side rides the -1 sentinel the
    # bounded fill already uses. F463: a NEGATIVE one-sided maximum is served too, via the
    # one-sided magnitude walk (dash, then the magnitude from |hi| upward unbounded).
    ("items_one_sided_min_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "minimum": 5}}}},
     lambda code, txt: code == 200 and isinstance((obj_of(txt) or {}).get("nums"), list)
     and len((obj_of(txt) or {}).get("nums", [])) >= 1
     and all(isinstance(x, int) and x >= 5 for x in (obj_of(txt) or {}).get("nums", []))),
    ("items_one_sided_max_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "maximum": 9}}}},
     lambda code, txt: code == 200 and isinstance((obj_of(txt) or {}).get("nums"), list)
     and len((obj_of(txt) or {}).get("nums", [])) >= 1
     and all(isinstance(x, int) and x <= 9 for x in (obj_of(txt) or {}).get("nums", []))),
    ("items_int_negmax_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "maximum": -3}}}},
     lambda code, txt: code == 200 and isinstance((obj_of(txt) or {}).get("nums"), list)
     and len((obj_of(txt) or {}).get("nums", [])) >= 1
     and all(isinstance(x, int) and x <= -3 for x in (obj_of(txt) or {}).get("nums", []))),
    # F464: a one-sided NEGATIVE minimum is served too - the magnitude walk [1, |lo|] behind the
    # dash gives values in [lo, -1], all >= lo (the subset argument; the no-max side is free).
    ("items_int_negmin_ok", "Give the numbers.",
     {"type": "object", "properties": {"nums": {"type": "array",
                                                "items": {"type": "integer", "minimum": -5}}}},
     lambda code, txt: code == 200 and isinstance((obj_of(txt) or {}).get("nums"), list)
     and len((obj_of(txt) or {}).get("nums", [])) >= 1
     and all(isinstance(x, int) and x >= -5 for x in (obj_of(txt) or {}).get("nums", []))),
    ("items_plain_ok", "Name some French cities.",
     {"type": "object", "properties": {"cities": {"type": "array", "items": {"type": "string"}}}},
     lambda code, txt: code == 200 and isinstance(json.loads(txt).get("cities"), list)),
    # F452: if/then/else is decided by the bare type: object is true, every other type is false, and
    # the selected branch is the trivial object one. The first case also pins the key set: the
    # `required` nested in the IGNORED else must not be read as a root requirement and shrink the
    # answer to {z}. A richer SELECTED branch still needs evaluation and stays refused.
    ("if_true_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "if": {"type": "object"}, "then": {"type": "object"}, "else": {"required": ["z"]}},
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    ("if_false_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "if": {"type": "string"}, "else": {"type": "object"}},
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    # F475: the selected branch is now judged against the proven emitted key set, so a bare-required
    # else whose keys are emitted is satisfied (this case used to expect the refusal).
    ("if_rich_served", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "if": {"type": "string"}, "else": {"required": ["city"]}},
     lambda code, txt: code == 200 and "city" in keys_of(txt)),
    # F475: a bare-required condition is decided by the emitted key set (city is always emitted), and
    # a bare-required selected branch whose keys are emitted is satisfied; a branch demanding an
    # unemitted key is unsatisfiable and stays refused.
    ("if_req_true_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "if": {"required": ["city"]}, "then": {"type": "object"}},
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    ("if_req_then_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "if": {"required": ["city"]}, "then": {"required": ["note"]}},
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    ("if_req_then_miss_refuse", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "if": {"required": ["city"]}, "then": {"required": ["z"]}},
     lambda code, txt: code == 400 and "if" in txt),
    # F453: unevaluated* judges only what root `properties`/`items` did NOT evaluate. Every emitted
    # key is declared and every array element is item-typed, so the keyword judges nothing and any
    # value is satisfied - including a schema that the values would otherwise violate. A shape the
    # keyword really would judge (bool/object on a non-array shape) stays refused.
    ("uneval_props_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "unevaluatedProperties": False},
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    ("uneval_props_schema_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "unevaluatedProperties": {"type": "integer"}},
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    ("uneval_items_ok", "Name some French cities.",
     {"type": "object", "properties": {"cities": {"type": "array", "items": {"type": "string"}}},
      "unevaluatedItems": False},
     lambda code, txt: code == 200 and isinstance(json.loads(txt).get("cities"), list)
     and len(json.loads(txt)["cities"]) >= 1),
    ("uneval_items_wrong_refuse", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "unevaluatedItems": False},
     lambda code, txt: code == 400 and "unevaluatedItems" in txt),
    # F454: minContains/maxContains on the contains form are element-count bounds (every element
    # matches, so k matches is k elements). The served cases assert the COUNT, not the status; the
    # unservable pairs (past the element ceiling, min above max) stay refused by name.
    ("cont_min_ok", "Name some French cities.",
     {"type": "object", "properties": {"cities": {"type": "array", "items": {"type": "string"}}},
      "contains": {"type": "string"}, "minContains": 3},
     lambda code, txt: code == 200 and len(json.loads(txt).get("cities", [])) >= 3),
    ("cont_max_ok", "Name some French cities.",
     {"type": "object", "properties": {"cities": {"type": "array", "items": {"type": "string"}}},
      "contains": {"type": "string"}, "maxContains": 2},
     lambda code, txt: code == 200 and 1 <= len(json.loads(txt).get("cities", [])) <= 2),
    ("cont_min_big_refuse", "Name some French cities.",
     {"type": "object", "properties": {"cities": {"type": "array", "items": {"type": "string"}}},
      "contains": {"type": "string"}, "minContains": 9},
     lambda code, txt: code == 400 and "minContains" in txt),
    ("cont_min_over_refuse", "Name some French cities.",
     {"type": "object", "properties": {"cities": {"type": "array", "items": {"type": "string"}}},
      "contains": {"type": "string"}, "minContains": 3, "maxContains": 2},
     lambda code, txt: code == 400 and "minContains" in txt),
    ("contentenc_refuse", "Say where you are.",
     {"type": "object", "properties": {"blob": {"type": "string", "contentEncoding": "base64"}}},
     lambda code, txt: code == 400 and "contentEncoding" in txt),
    # F429: propertyNames constrains KEY NAMES, and those names come from the declared plan - so a
    # constraint the keys already satisfy must be honoured, while one they cannot satisfy is refused.
    ("pnames_pattern_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "propertyNames": {"pattern": "^[a-z]+$"}},
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    ("pnames_pattern_bad", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "propertyNames": {"pattern": "^x"}},
     lambda code, txt: code == 400 and "propertyNames" in txt),
    ("pnames_len_bad", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "propertyNames": {"maxLength": 3}},
     lambda code, txt: code == 400 and "propertyNames" in txt),
    # F432: dependentRequired is satisfied when every declared key is emitted, and violated when the
    # trigger is emitted but its dependency was omitted by a required subset - so it must be judged,
    # not blanket-refused.
    ("dep_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "dependentRequired": {"city": ["note"]}},
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    ("dep_bad_subset", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "required": ["city"], "dependentRequired": {"city": ["note"]}},
     lambda code, txt: code == 400 and "dependentRequired" in txt),
    ("dep_undeclared", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}},
      "dependentRequired": {"city": ["region"]}},
     lambda code, txt: code == 400 and "dependentRequired" in txt),
    # F446: dependentSchemas with a bare `required` body is judged by the emitted key set; a
    # dependency omitted by a required subset and any richer subschema body stay refused.
    ("depsch_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "dependentSchemas": {"city": {"required": ["note"]}}},
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    ("depsch_bad_subset", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "required": ["city"], "dependentSchemas": {"city": {"required": ["note"]}}},
     lambda code, txt: code == 400 and "dependentSchemas" in txt),
    # F557 flipped this expectation: a dependent subschema whose `properties` names only keys the
    # answer never emits is vacuously true (the old name was depsch_other_body and it expected 400).
    ("depsch_vacuous_props", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "dependentSchemas": {"city": {"properties": {"x": {"type": "string"}}}}},
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    ("depsch_emitted_key_refuse", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "dependentSchemas": {"city": {"properties": {"note": {"const": "q"}}}}},
     lambda code, txt: code == 400 and "dependentSchemas" in txt),
    ("depsch_addprops_refuse", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "dependentSchemas": {"city": {"properties": {"x": {"type": "string"}},
                                    "additionalProperties": False}}},
     lambda code, txt: code == 400 and "dependentSchemas" in txt),
    # F558: if/then/else whose condition and selected branch only name keys the answer never emits
    # is vacuously satisfied; conditioning on an emitted key or constraining one in the branch
    # still needs evaluation and refuses.
    ("if_vacuous_then_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "if": {"properties": {"x": {"const": "q"}}},
      "then": {"properties": {"y": {"type": "string"}}}},
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    ("if_vacuous_else_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "if": {"required": ["z"]},
      "else": {"properties": {"y": {"type": "string"}}}},
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    ("if_cond_emitted_refuse", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "if": {"properties": {"city": {"const": "paris"}}},
      "then": {"type": "object"}},
     lambda code, txt: code == 400 and "if" in txt),
    ("if_then_emitted_refuse", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "if": {"type": "string"},
      "else": {"properties": {"note": {"const": "q"}}}},
     lambda code, txt: code == 400 and "if" in txt),
    # F559: the draft-04 dependencies mirror - a properties subschema for keys the answer never
    # emits is vacuous; one constraining an emitted key still needs evaluation and refuses.
    ("deps_vacuous_props", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "dependencies": {"city": {"properties": {"x": {"type": "string"}}}}},
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    ("deps_emitted_key_refuse", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "dependencies": {"city": {"properties": {"note": {"const": "q"}}}}},
     lambda code, txt: code == 400 and "dependencies" in txt),
    # F447: `dependencies` in both spellings (array, bare-required object) is judged by the emitted
    # key set; an omitted dependency and a richer subschema body stay refused.
    ("deps_arr_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "dependencies": {"city": ["note"]}},
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    ("deps_obj_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "dependencies": {"city": {"required": ["note"]}}},
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    ("deps_bad_subset", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "required": ["city"], "dependencies": {"city": ["note"]}},
     lambda code, txt: code == 400 and "dependencies" in txt),
    # F434: tuple validation - `items` as an ARRAY of schemas, or prefixItems - is a shape no
    # generator claims, so the request must be refused by name instead of answered with prose.
    # F444: the 2-element scalar tuple is served - a list of exactly two elements whose types follow
    # the positions (string then integer) - while prefixItems has no writer and stays refused.
    ("tuple_served", "Give two values.",
     {"type": "object", "properties": {"pair": {"type": "array",
                                                "items": [{"type": "string"}, {"type": "integer"}]}}},
     lambda code, txt: code == 200 and isinstance(json.loads(txt).get("pair"), list)
     and len(json.loads(txt)["pair"]) == 2
     and isinstance(json.loads(txt)["pair"][0], str)
     and isinstance(json.loads(txt)["pair"][1], int)),
    ("prefixitems_refuse", "Give two values.",
     {"type": "object", "properties": {"pair": {"type": "array",
                                                "prefixItems": [{"type": "string"}, {"type": "integer"}]}}},
     lambda code, txt: code == 400 and "prefixItems" in txt),
    # F448: `not` is honoured when its subschema is just a type the flat all-string answer cannot
    # have; a value rule inside it (pattern) or a shape whose leaves are not all strings stays
    # refused, because either needs the value evaluated rather than reasoned about up front.
    ("not_type_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "not": {"type": "integer"}},
     lambda code, txt: code == 200 and keys_of(txt) == ["city", "note"]),
    ("not_pattern_refuse", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "note": {"type": "string"}},
      "not": {"pattern": "^x"}},
     lambda code, txt: code == 400 and "not" in txt),
    ("not_mixed_refuse", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"}, "count": {"type": "integer"}},
      "not": {"type": "integer"}},
     lambda code, txt: code == 400 and "not" in txt),
    # F449: `contains` is honoured when it is just the item type of the single array property - the
    # writer always emits the first element and it is that type. A different type would be a value
    # the writer never promises, so it stays refused.
    ("contains_type_ok", "Name some French cities.",
     {"type": "object", "properties": {"cities": {"type": "array", "items": {"type": "string"}}},
      "contains": {"type": "string"}},
     lambda code, txt: code == 200 and isinstance(json.loads(txt).get("cities"), list)
     and len(json.loads(txt)["cities"]) >= 1
     and all(isinstance(x, str) for x in json.loads(txt)["cities"])),
    ("contains_mismatch_refuse", "Name some French cities.",
     {"type": "object", "properties": {"cities": {"type": "array", "items": {"type": "string"}}},
      "contains": {"type": "integer"}},
     lambda code, txt: code == 400 and "contains" in txt),
    # F566: the per-key value plan - one shared source read by the candidate tier (which defers),
    # the required guard and the mixed writer: a pinned const leaf beside a free sibling is served
    # with EVERY declared key (the pinned literal plus a free value), and a required subset or
    # full set filters that same plan. Value-level if/then/else is judged against the pin values,
    # never merged into the schema (F562's no-fixed-point result).
    ("const_leaf_optional_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"const": "paris"},
                                        "note": {"type": "string"}}},
     lambda code, txt: (code == 200 and keys_of(txt) == ["city", "note"]
                        and (obj_of(txt) or {}).get("city") == "paris")),
    ("const_leaf_all_required_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"const": "paris"},
                                        "note": {"type": "string"}},
      "required": ["city", "note"]},
     lambda code, txt: (code == 200 and keys_of(txt) == ["city", "note"]
                        and (obj_of(txt) or {}).get("city") == "paris")),
    ("const_leaf_subset_required_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"const": "paris"},
                                        "note": {"type": "string"}},
      "required": ["note"]},
     lambda code, txt: keys_of(txt) == ["note"]),
    ("const_leaf_missing_required_refuse", "Say where you are.",
     {"type": "object", "properties": {"city": {"const": "paris"},
                                        "note": {"type": "string"}},
      "required": ["note", "zzz"]},
     lambda code, txt: code == 400 and "required" in txt),
    ("const_num_pin_ok", "Give the code and the count.",
     {"type": "object", "properties": {"city": {"const": "paris"},
                                        "count": {"const": 5}}},
     lambda code, txt: (code == 200 and keys_of(txt) == ["city", "count"]
                        and (obj_of(txt) or {}).get("city") == "paris"
                        and (obj_of(txt) or {}).get("count") == 5)),
    # F567: a typeless boolean const is a pinned leaf too (the literal decides the kind).
    ("const_bool_typeless_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"const": "paris"},
                                        "flag": {"const": True}}},
     lambda code, txt: (code == 200 and keys_of(txt) == ["city", "flag"]
                        and (obj_of(txt) or {}).get("city") == "paris"
                        and (obj_of(txt) or {}).get("flag") is True)),
    ("if_const_true_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"const": "paris"},
                                        "note": {"type": "string"}},
      "if": {"properties": {"city": {"const": "paris"}}},
      "then": {"required": ["note"]}},
     lambda code, txt: (code == 200 and keys_of(txt) == ["city", "note"]
                        and (obj_of(txt) or {}).get("city") == "paris")),
    ("if_const_false_else_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"const": "paris"},
                                        "note": {"type": "string"}},
      "if": {"properties": {"city": {"const": "london"}}},
      "else": {"required": ["note"]}},
     lambda code, txt: (code == 200 and keys_of(txt) == ["city", "note"]
                        and (obj_of(txt) or {}).get("city") == "paris")),
    ("if_enum_member_true_ok", "Say where you are.",
     {"type": "object", "properties": {"city": {"const": "paris"},
                                        "note": {"type": "string"}},
      "if": {"properties": {"city": {"enum": ["paris", "london"]}}},
      "then": {"required": ["note"]}},
     lambda code, txt: (code == 200 and keys_of(txt) == ["city", "note"]
                        and (obj_of(txt) or {}).get("city") == "paris")),
    ("if_const_true_then_miss_refuse", "Say where you are.",
     {"type": "object", "properties": {"city": {"const": "paris"},
                                        "note": {"type": "string"}},
      "if": {"properties": {"city": {"const": "paris"}}},
      "then": {"required": ["missing"]}},
     lambda code, txt: code == 400 and ("if" in txt or "required" in txt)),
    ("if_const_true_required_merge", "Say where you are.",
     {"type": "object", "properties": {"city": {"const": "paris"},
                                        "note": {"type": "string"}},
      "required": ["city", "note"],
      "if": {"properties": {"city": {"const": "paris"}}},
      "then": {"required": ["note"]}},
     lambda code, txt: (code == 200 and keys_of(txt) == ["city", "note"]
                        and (obj_of(txt) or {}).get("city") == "paris")),
    # The condition key emitted but NOT pinned (a free string): the truth is value-dependent, so
    # the value-level judge must keep the refusal (the F558 vacuous rule does not cover it).
    ("if_free_key_cond_refuse", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string"},
                                        "note": {"type": "string"}},
      "if": {"properties": {"city": {"const": "paris"}}},
      "then": {"required": ["note"]}},
     lambda code, txt: code == 400 and "if" in txt),
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
