"""F404 live check: are anchored class/count patterns honoured and everything else still refused?

The pattern gate used to 400 every pattern containing a metacharacter. Now the anchored
^...$ core made of literal runs and exact-count character classes is EMITTED by the mixed
stepper (every segment a fixed shape, class characters picked from the class charset alone),
so the assertion is the VALUE matching the pattern, not the status code. Ten cases:
  1. ^[A-Z]{3}$                -> 200, three uppercase letters
  2. ^SKU-[0-9]{4}$            -> 200, literal prefix plus four digits
  3. ^X[abc]{2}$               -> 200, literal X plus two lowercase letters
  4. multi-leaf with a pattern -> 200, pattern key matches AND the free string key present
  5. all-required multi-leaf   -> 200, both keys (the pattern key is steppable, so required
     is honoured by construction)
  6. {4,6} range quantifier    -> 400 naming pattern
  7. ^[a-z]+$ star             -> 400 naming pattern
  8. unanchored [0-9]{3}       -> 400 naming pattern
  9. negated class [^a-z]      -> 400 naming pattern
 10. literal alternation (F282) -> 200, value in the finite set (no regression)
"""
import json
import re
import sys
import urllib.error
import urllib.request

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8000
URL = "http://127.0.0.1:%d/v1/chat/completions" % PORT


def ask(prompt, schema, max_tokens=64):
    body = {"messages": [{"role": "user", "content": prompt}],
            "response_format": {"type": "json_schema",
                                "json_schema": {"name": "pat", "schema": schema}},
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


CODE = {"type": "string", "pattern": "^[A-Z]{3}$"}

cases = [
    ("code_upper3", "Give the code.",
     {"type": "object", "properties": {"code": CODE}},
     lambda code, txt: code == 200 and val_of(txt, "code") is not None
     and re.match(r"^[A-Z]{3}$", val_of(txt, "code")) is not None),
    ("sku_digits", "Give the SKU.",
     {"type": "object", "properties": {"sku": {"type": "string", "pattern": "^SKU-[0-9]{4}$"}}},
     lambda code, txt: code == 200 and val_of(txt, "sku") is not None
     and re.match(r"^SKU-[0-9]{4}$", val_of(txt, "sku")) is not None),
    ("x_then_two_lower", "Give the tag.",
     {"type": "object", "properties": {"tag": {"type": "string", "pattern": "^X[abc]{2}$"}}},
     lambda code, txt: code == 200 and val_of(txt, "tag") is not None
     and re.match(r"^X[abc]{2}$", val_of(txt, "tag")) is not None),
    ("multi_leaf_pattern", "Give the code and the city.",
     {"type": "object", "properties": {"code": CODE, "city": {"type": "string"}}},
     lambda code, txt: (code == 200 and val_of(txt, "code") is not None
                        and re.match(r"^[A-Z]{3}$", val_of(txt, "code")) is not None
                        and isinstance(val_of(txt, "city"), str))),
    ("multi_leaf_all_required", "Give the code and the city.",
     {"type": "object", "properties": {"code": CODE, "city": {"type": "string"}},
      "required": ["code", "city"]},
     lambda code, txt: (code == 200 and val_of(txt, "code") is not None
                        and re.match(r"^[A-Z]{3}$", val_of(txt, "code")) is not None
                        and isinstance(val_of(txt, "city"), str))),
    # F409 flipped this one too: {4,6} is the LAST segment (nothing follows before $), so the
    # range is served and the value honours it.
    ("range_sku_served", "Give the SKU.",
     {"type": "object", "properties": {"sku": {"type": "string", "pattern": "^SKU-[0-9]{4,6}$"}}},
     lambda code, txt: code == 200 and val_of(txt, "sku") is not None
     and re.match(r"^SKU-[0-9]{4,6}$", val_of(txt, "sku")) is not None),
    # F409 flipped this one: the star on the LAST segment is served - the close is reachable from
    # the first position, so the walk stays unambiguous.
    ("star_served", "Give the tag.",
     {"type": "object", "properties": {"tag": {"type": "string", "pattern": "^[a-z]+$"}}},
     lambda code, txt: code == 200 and val_of(txt, "tag") is not None
     and re.match(r"^[a-z]+$", val_of(txt, "tag")) is not None),
    # F412 flipped these two: an UNANCHORED pattern is served by the anchored emission (the match
    # contains itself), and a NEGATED class by the complement within the JSON-safe alphabet.
    ("unanchored_served", "Give the number.",
     {"type": "object", "properties": {"num": {"type": "string", "pattern": "[0-9]{3}"}}},
     lambda code, txt: code == 200 and val_of(txt, "num") is not None
     and re.match(r"^[0-9]{3}$", val_of(txt, "num")) is not None),
    ("negated_class_served", "Give the tag.",
     {"type": "object", "properties": {"tag": {"type": "string", "pattern": "^[^a-z]{2}$"}}},
     lambda code, txt: code == 200 and val_of(txt, "tag") is not None
     and re.match(r"^[^a-z]{2}$", val_of(txt, "tag")) is not None),
    # Still refused: the empty complement (unsatisfiable) and the escape shorthands.
    # F413: the alphabet extension (the underscore joining for \w) ripples into the negation - the
    # OLD empty complement now leaves the underscore (served), and the TRUE empty complement
    # (excluding everything including the underscore) takes over the refusal side.
    ("neg_underscore_served", "Give the tag.",
     {"type": "object", "properties": {"tag": {"type": "string",
                                               "pattern": "^[^A-Za-z0-9 .-]{2}$"}}},
     lambda code, txt: code == 200 and val_of(txt, "tag") is not None
     and re.match(r"^[^A-Za-z0-9 .-]{2}$", val_of(txt, "tag")) is not None),
    ("neg_empty_refuse", "Give the tag.",
     {"type": "object", "properties": {"tag": {"type": "string",
                                               "pattern": "^[^A-Za-z0-9 .\\-_]{2}$"}}},
     lambda code, txt: code == 400 and "pattern" in txt),
    # F413 flipped this one: the escape shorthands are classes now - \d digits, \w word characters,
    # \s the space; the uppercase \D and a literal backslash keep refusing.
    ("escape_served", "Give the number.",
     {"type": "object", "properties": {"num": {"type": "string", "pattern": "^\\d{3}$"}}},
     lambda code, txt: code == 200 and val_of(txt, "num") is not None
     and re.match(r"^[0-9]{3}$", val_of(txt, "num")) is not None),
    ("escape_w_served", "Give the tag.",
     {"type": "object", "properties": {"tag": {"type": "string", "pattern": "^\\w+$"}}},
     lambda code, txt: code == 200 and val_of(txt, "tag") is not None
     and re.match(r"^\w+$", val_of(txt, "tag")) is not None),
    ("escape_upper_D_refuse", "Give the number.",
     {"type": "object", "properties": {"num": {"type": "string", "pattern": "^\\D{3}$"}}},
     lambda code, txt: code == 400 and "pattern" in txt),
    ("escape_backslash_refuse", "Give the tag.",
     {"type": "object", "properties": {"tag": {"type": "string", "pattern": "^a\\\\b$"}}},
     lambda code, txt: code == 400 and "pattern" in txt),
    # F409: the {n,m} range and the plus on the LAST segment - the value length walks between the
    # bounds; a quantifier beside a FOLLOWING segment still refuses (that walk needs a real NFA).
    ("range_last_served", "Give the tag.",
     {"type": "object", "properties": {"tag": {"type": "string", "pattern": "^X[abc]{2,4}$"}}},
     lambda code, txt: code == 200 and val_of(txt, "tag") is not None
     and re.match(r"^X[abc]{2,4}$", val_of(txt, "tag")) is not None),
    ("plus_digits_served", "Give the number.",
     {"type": "object", "properties": {"num": {"type": "string", "pattern": "^[0-9]+$"}}},
     lambda code, txt: code == 200 and val_of(txt, "num") is not None
     and re.match(r"^[0-9]+$", val_of(txt, "num")) is not None),
    ("nonlast_range_refuse", "Give the tag.",
     {"type": "object", "properties": {"tag": {"type": "string", "pattern": "^[a-z]{2,4}[0-9]{3}$"}}},
     lambda code, txt: code == 400 and "pattern" in txt),
    ("nonlast_star_refuse", "Give the tag.",
     {"type": "object", "properties": {"tag": {"type": "string", "pattern": "^[a-z]+[0-9]{2}$"}}},
     lambda code, txt: code == 400 and "pattern" in txt),
    ("literal_alt_regression", "Say where you are.",
     {"type": "object", "properties": {"city": {"type": "string", "pattern": "^(Paris|London)$"}}},
     lambda code, txt: code == 200 and val_of(txt, "city") in ("Paris", "London")),
]

bad = 0
for label, prompt, schema, ok in cases:
    code, txt = ask(prompt, schema)
    good = ok(code, txt)
    if not good:
        bad += 1
    print("%-24s status=%s ok=%s text=%s" % (label, code, good, txt[:140]))
print("VERDICT: " + ("PASS - counted-class values match the pattern; other forms still refused"
                     if bad == 0 else "FAIL cases=%d" % bad))
sys.exit(1 if bad else 0)
