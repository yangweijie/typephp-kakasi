"""F659: the two servers must answer the same schema the same way.

F658b found the 8001 cooperative server answering an unsatisfiable bounds schema as free text while
8000 refused it. The two handlers are independent functions, so a guard added to one does not reach
the other - a parity matrix over both is the only thing that notices. Each case states the expected
outcome (200, or 400 naming a keyword) and BOTH servers must meet it; the two collected files are
then compared field by field so even a case whose expectation is wrong still cannot hide a
divergence between the servers.

    python schema_parity.py collect <port> <out.json>
    python schema_parity.py compare <a.json> <b.json>
"""
import json
import sys
import urllib.error
import urllib.request

# Each case: (name, schema, expect, keyword). expect is 200 or 400; keyword must appear in a 400
# body and is unused for 200.
CASES = [
    ("enum_ok", {"type": "object", "properties": {"c": {"type": "string", "enum": ["paris", "lyon"]}},
                 "required": ["c"]}, 200, ""),
    ("bounds_ok", {"type": "object", "properties": {"n": {"type": "integer", "minimum": 1, "maximum": 5}},
                   "required": ["n"]}, 200, ""),
    ("bounds_mult_unsat", {"type": "object", "properties": {"n": {"type": "integer", "minimum": 1,
                                                                  "maximum": 5, "multipleOf": 7}}}, 400,
     "multipleOf"),
    ("bounds_frac_unsat", {"type": "object", "properties": {"n": {"type": "number", "minimum": 1,
                                                                  "maximum": 1.1, "multipleOf": 0.3}}}, 400,
     "multipleOf"),
    ("excl_unsat", {"type": "object", "properties": {"n": {"type": "integer", "exclusiveMinimum": 5,
                                                           "exclusiveMaximum": 6}}}, 400,
     "exclusiveMinimum/exclusiveMaximum"),
    ("excl_bool_alone", {"type": "object", "properties": {"n": {"type": "integer",
                                                                "exclusiveMinimum": True}}}, 400,
     "exclusiveMinimum"),
    ("excl_bool_served", {"type": "object", "properties": {"n": {"type": "integer", "minimum": 5,
                                                                 "exclusiveMinimum": True}}}, 200, ""),
    ("unique_items", {"type": "object", "properties": {"t": {"type": "array",
                                                             "items": {"type": "string"},
                                                             "uniqueItems": True}}}, 400, "uniqueItems"),
    ("contains_kw", {"type": "object", "properties": {"t": {"type": "array", "items": {"type": "string"},
                                                            "contains": {"const": "x"}}}}, 400, "contains"),
    ("pattern_meta", {"type": "object", "properties": {"s": {"type": "string",
                                                             "pattern": "^(a|b)+c$"}}}, 400, "pattern"),
    ("deep_obj_array", {"type": "object", "properties": {"a": {"type": "array", "items": {"type": "array",
                                                              "items": {"type": "array", "items": {
                                                                  "type": "object", "properties": {"y": {"type": "string"}}}}}}}},
     400, "three or more levels"),
    ("mult_unb_ok", {"type": "object", "properties": {"n": {"type": "integer", "multipleOf": 3}}},
     200, ""),
    ("onedf_bounds_only", {"type": "object", "properties": {"n": {"type": "integer", "minimum": 1}}},
     200, ""),
]


def post(port, name, schema):
    body = {"messages": [{"role": "user", "content": "Answer."}], "max_tokens": 32,
            "temperature": 0, "stream": True,
            "response_format": {"type": "json_schema", "json_schema": {"name": "sp_" + name,
                                                                       "schema": schema}}}
    req = urllib.request.Request("http://127.0.0.1:%d/v1/chat/completions" % port,
                                 data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=180) as f:
            text = f.read().decode()
            return f.getcode(), text
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()


def collect(port, out):
    rows = []
    bad = 0
    for name, schema, expect, kw in CASES:
        code, text = post(port, name, schema)
        named = (kw in text) if kw else True
        ok = (code == expect) and named
        if not ok:
            bad += 1
        rows.append({"name": name, "code": code, "expect": expect, "keyword": kw,
                     "named": named, "ok": ok, "text": text[:400]})
        print("%-20s port=%d code=%s expect=%s named=%s ok=%s text=%s"
              % (name, port, code, expect, named, ok, text[:110].replace("\n", " ")))
    json.dump(rows, open(out, "w"), indent=1)
    print("PARITY-COLLECT port=%d ok=%d bad=%d out=%s" % (port, len(CASES) - bad, bad, out))
    return 0 if bad == 0 else 1


def compare(a, b):
    ra = json.load(open(a))
    rb = json.load(open(b))
    if len(ra) != len(rb):
        print("PARITY: FAIL case_count %d != %d" % (len(ra), len(rb)))
        return 1
    bad = 0
    for x, y in zip(ra, rb):
        # Compare the CLASS and the naming, not the text: the two servers wrap refusals in their own
        # envelopes ("request schema not supported: ..." vs "request not supported: ..."), and the
        # text itself may mention supported surfaces differently.
        if x["name"] != y["name"] or x["code"] != y["code"] or x["named"] != y["named"]:
            bad += 1
            print("PARITY-DIFF %-20s a=(%s named=%s) b=(%s named=%s)"
                  % (x["name"], x["code"], x["named"], y["code"], y["named"]))
    print("PARITY: " + ("PASS (%d cases agree across servers)" % len(ra)
                        if bad == 0 else "FAIL diverged=%d" % bad))
    return 0 if bad == 0 else 1


def main(argv):
    if len(argv) >= 4 and argv[1] == "collect":
        return collect(int(argv[2]), argv[3])
    if len(argv) >= 4 and argv[1] == "compare":
        return compare(argv[2], argv[3])
    print("usage: schema_parity.py collect <port> <out.json> | compare <a.json> <b.json>")
    return 2


if __name__ == "__main__":
    sys.exit(main(sys.argv))
