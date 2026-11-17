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


PATHS = {"chat": "/v1/chat/completions", "anth": "/v1/messages", "resp": "/v1/responses"}


def sse_text(raw):
    """Concatenate the text deltas of any of the three SSE dialects."""
    out = []
    for line in raw.split("\n"):
        if not line.startswith("data: "):
            continue
        payload = line[6:].strip()
        if payload == "[DONE]":
            continue
        try:
            ev = json.loads(payload)
        except Exception:
            continue
        d = ev.get("delta")
        if isinstance(d, dict):
            if isinstance(d.get("content"), str):
                out.append(d["content"])
            elif isinstance(d.get("text"), str):
                out.append(d["text"])
        elif isinstance(d, str):
            out.append(d)
        ch = ev.get("choices")
        if isinstance(ch, list) and ch and isinstance(ch[0].get("delta"), dict):
            c = ch[0]["delta"].get("content")
            if isinstance(c, str):
                out.append(c)
    return "".join(out)


def value_ok(name, text):
    """F663: a 200 is only honest if the streamed value satisfies the schema. Each served case
    states what "honoured" means; parsing the streamed text is the only way to see it."""
    try:
        obj = json.loads(text)
    except Exception:
        return False, "not-json:" + text[:60]
    if name == "enum_ok":
        return obj.get("c") in ("paris", "lyon"), text[:60]
    if name == "bounds_ok":
        v = obj.get("n")
        return isinstance(v, int) and 1 <= v <= 5, text[:60]
    if name == "excl_bool_served":
        v = obj.get("n")
        return isinstance(v, int) and v >= 5, text[:60]
    if name == "mult_unb_ok":
        v = obj.get("n")
        return isinstance(v, int) and v % 3 == 0, text[:60]
    if name == "onedf_bounds_only":
        v = obj.get("n")
        return isinstance(v, int) and v >= 1, text[:60]
    return True, ""


def post(port, name, schema, transport="chat"):
    # F662: the same schema travels under a different key on each transport (response_format /
    # output_format / text.format). The guards read the translated json_schema marker, so the
    # verdict must be the same everywhere - a transport that answers instead of refusing is the
    # F634-class fail-open.
    if transport == "anth":
        body = {"messages": [{"role": "user", "content": "Answer."}], "max_tokens": 32,
                "stream": True, "output_format": {"type": "json_schema", "schema": schema}}
    elif transport == "resp":
        body = {"input": "Answer.", "max_output_tokens": 32, "stream": True,
                "text": {"format": {"type": "json_schema", "name": "sp_" + name,
                                    "schema": schema}}}
    else:
        body = {"messages": [{"role": "user", "content": "Answer."}], "max_tokens": 32,
                "temperature": 0, "stream": True,
                "response_format": {"type": "json_schema", "json_schema": {
                    "name": "sp_" + name, "schema": schema}}}
    req = urllib.request.Request("http://127.0.0.1:%d%s" % (port, PATHS[transport]),
                                 data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=180) as f:
            text = f.read().decode()
            return f.getcode(), text
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()


def collect(port, out, transport="chat"):
    rows = []
    bad = 0
    for name, schema, expect, kw in CASES:
        code, text = post(port, name, schema, transport)
        named = (kw in text) if kw else True
        vok, vdet = (True, "")
        if code == 200:
            vok, vdet = value_ok(name, sse_text(text))
        ok = (code == expect) and named and vok
        if not ok:
            bad += 1
        rows.append({"name": name, "code": code, "expect": expect, "keyword": kw,
                     "named": named, "value_ok": vok, "ok": ok, "text": text[:400]})
        print("%-20s port=%d code=%s expect=%s named=%s value=%s ok=%s text=%s"
              % (name, port, code, expect, named, vok, ok,
                 (vdet or text[:110]).replace("\n", " ")))
    json.dump(rows, open(out, "w"), indent=1)
    print("PARITY-COLLECT port=%d transport=%s ok=%d bad=%d out=%s"
          % (port, transport, len(CASES) - bad, bad, out))
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
        if (x["name"] != y["name"] or x["code"] != y["code"] or x["named"] != y["named"]
                or x.get("value_ok") != y.get("value_ok")):
            bad += 1
            print("PARITY-DIFF %-20s a=(%s named=%s value=%s) b=(%s named=%s value=%s)"
                  % (x["name"], x["code"], x["named"], x.get("value_ok"),
                     y["code"], y["named"], y.get("value_ok")))
    print("PARITY: " + ("PASS (%d cases agree)" % len(ra)
                        if bad == 0 else "FAIL diverged=%d" % bad))
    return 0 if bad == 0 else 1


def main(argv):
    if len(argv) >= 4 and argv[1] == "collect":
        return collect(int(argv[2]), argv[3], argv[4] if len(argv) > 4 else "chat")
    if len(argv) >= 4 and argv[1] == "compare":
        return compare(argv[2], argv[3])
    print("usage: schema_parity.py collect <port> <out.json> [chat|anth|resp] | compare <a.json> <b.json>")
    return 2


if __name__ == "__main__":
    sys.exit(main(sys.argv))
