"""API smoke test: one pass over every response shape the engine promises.

Run against a live server:
    python engine/tests/api_smoke.py [port]

Kept dependency-free (urllib) so it runs on a bare Python. Each check states what
must be true, not just "got 200" - a wrong shape is a failure.
"""
import json
import re
import socket
import sys
import time
import urllib.error
import urllib.request

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8000
BASE = "http://127.0.0.1:%d" % PORT
fails = []


def post(path, body, stream=False):
    data = json.dumps(body).encode()
    req = urllib.request.Request(BASE + path, data=data, headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=300) as r:
        raw = r.read().decode()
    if stream:
        return raw
    return json.loads(raw)


def check(name, cond, detail=""):
    if cond:
        print("  PASS %s" % name)
    else:
        print("  FAIL %s %s" % (name, detail))
        fails.append(name)


def events(raw):
    return [ln[7:] for ln in raw.splitlines() if ln.startswith("event: ")]


def sse_data(raw):
    return [ln[6:] for ln in raw.splitlines() if ln.startswith("data: ")]


def main():
    print("API SMOKE port=%d" % PORT)

    with urllib.request.urlopen(BASE + "/health", timeout=60) as r:
        health = json.loads(r.read().decode())
    check("health", health.get("status") == "ok" and health.get("model") == "qwen2", str(health))

    with urllib.request.urlopen(BASE + "/v1/models", timeout=60) as r:
        models = json.loads(r.read().decode())
    check("models", models.get("object") == "list" and len(models.get("data", [])) > 0)
    with urllib.request.urlopen(BASE + "/v1/models/qwen2", timeout=60) as r:
        one_model = json.loads(r.read().decode())
    check("models-get", one_model.get("id") == "qwen2", str(one_model)[:120])
    try:
        urllib.request.urlopen(BASE + "/v1/models/does-not-exist", timeout=60)
        check("models-404", False, "expected HTTPError")
    except urllib.error.HTTPError as e:
        check("models-404", e.code == 404, str(e.code))

    chat = {"messages": [{"role": "user", "content": "The capital of France is"}], "max_tokens": 8}
    r = post("/v1/chat/completions", chat)
    check("chat-text", "Paris" in r["choices"][0]["message"]["content"], str(r)[:200])

    r = post("/v1/chat/completions", {"messages": [
        {"role": "developer", "content": "Answer with one word."},
        {"role": "user", "content": "The capital of France is"}], "max_tokens": 8})
    check("chat-developer", "Paris" in r["choices"][0]["message"]["content"], str(r)[:160])

    raw = post("/v1/chat/completions", dict(chat, stream=True), stream=True)
    content = "".join(json.loads(d)["choices"][0]["delta"].get("content", "")
                      for d in sse_data(raw) if d != "[DONE]")
    raw = post("/v1/chat/completions", {"messages": chat["messages"], "max_tokens": 8, "stream": True,
                                        "stream_options": {"include_usage": True}}, stream=True)
    chunks = [json.loads(d) for d in sse_data(raw) if d != "[DONE]"]
    usage = chunks[-1].get("usage") or {}
    check("chat-stream-usage",
          chunks[-1]["choices"] == [] and usage.get("completion_tokens", 0) > 0
          and usage.get("total_tokens") == usage.get("prompt_tokens", 0) + usage.get("completion_tokens", 0),
          str(chunks[-1])[:160])
    check("chat-stream", "Paris" in content and sse_data(raw)[-1] == "[DONE]", content)

    # Legacy text completion endpoint (non-streaming).
    r = post("/v1/completions", {"prompt": "The capital of France is", "max_tokens": 8})
    check("completions", r.get("object") == "text_completion" and "Paris" in r["choices"][0]["text"],
          str(r)[:160])
    try:
        post("/v1/completions", {"prompt": "x", "stream": True})
        check("completions-stream-rejected", False, "stream accepted")
    except urllib.error.HTTPError as e:
        check("completions-stream-rejected", e.code == 400, str(e.code))

    # Responses API takes a plain "input" string OR an array of message objects.
    r = post("/v1/responses", {"input": "The capital of France is", "max_output_tokens": 8})
    check("responses-text", "Paris" in r["output"][0]["content"][0]["text"], str(r)[:160])
    r = post("/v1/responses", {"input": [{"role": "user", "content": "The capital of France is"}],
                               "max_output_tokens": 8})
    check("responses-array", "Paris" in r["output"][0]["content"][0]["text"], str(r)[:160])

    # A Responses tool call is a function_call output item (not a chat choice).
    r = post("/v1/responses", {"input": "Weather in London?", "max_output_tokens": 64,
                               "tools": [{"type": "function", "function": {"name": "get_weather", "parameters": {
                                   "type": "object",
                                   "properties": {"city": {"type": "string", "enum": ["Paris", "London"]}}}}}],
                               "tool_choice": {"type": "function", "function": {"name": "get_weather"}}})
    o = r["output"][0]
    check("responses-tool", o["type"] == "function_call" and o["name"] == "get_weather"
          and json.loads(o["arguments"]).get("city") == "London", str(o)[:160])
    r = post("/v1/responses", {"input": "The capital of France is", "max_output_tokens": 8,
                               "instructions": [{"type": "text", "text": "Answer with one word."}]})
    check("responses-instructions-array", "Paris" in r["output"][0]["content"][0]["text"],
          str(r)[:160])

    # Responses + json_schema string: the same grammar must run in this exit (the guard now
    # accepts Responses' "type":"json_schema","schema":… shape, not only "json_schema":{…}).
    r = post("/v1/responses", {"input": "The capital of France is", "max_output_tokens": 64,
                               "text": {"format": {"type": "json_schema", "name": "city", "schema": {
                                   "type": "object",
                                   "properties": {"city": {"type": "string"}}}}}})
    rtext = r["output"][0]["content"][0]["text"]
    try:
        rcity = json.loads(rtext)["city"]
        rok = isinstance(rcity, str) and bool(re.fullmatch(r"[A-Za-z0-9 .,'-]*", rcity))
    except Exception:
        rok = False
    check("responses-json-schema-string", rok, rtext)

    # F191 guard: `text.format: json_object` must reach the validate+retry path, whose observable
    # side is that `stop` is skipped. Same prompt without text.format gets cut by that stop, so the
    # fenced block survives only in the json_object reply (a half-block means the guard regressed).
    jreq = {"input": "Return one JSON object with key city, value Paris.", "max_output_tokens": 48,
            "seed": 7, "stop": ["```"]}
    r = post("/v1/responses", dict(jreq, text={"format": {"type": "json_object"}}))
    robj = r["output"][0]["content"][0]["text"]
    r = post("/v1/responses", dict(jreq))
    rcut = r["output"][0]["content"][0]["text"]
    check("responses-json-object", "{" in robj and "}" in robj and robj.count("```") != 1
          and len(robj) >= len(rcut)
          and (rcut == "" or robj.count("```") == 2 or ("```" not in robj and "```" not in rcut)),
          "json_object=%r control=%r" % (robj[:60], rcut[:60]))

    # F207: an out-of-document $ref resolves from a caller-supplied schema_registry (nothing is
    # fetched), so the referenced enum still drives constrained decoding over the real API.
    r = post("/v1/chat/completions", {"messages": [{"role": "user", "content": "The capital of France is"}],
                                      "max_tokens": 64, "seed": 3,
                                      "response_format": {"type": "json_schema", "json_schema": {
                                          "name": "city", "schema": {
                                              "type": "object", "properties": {
                                                  "city": {"$ref": "city.json#/City"}}}}},
                                      "schema_registry": {"city.json#/City": {
                                          "type": "string", "enum": ["Paris", "London"]}}})
    ext = r["choices"][0]["message"]["content"]
    try:
        ec = json.loads(ext).get("city")
    except Exception:
        ec = None
    check("chat-json-schema-extref", ec in ("Paris", "London"), str(ext)[:120])

    # F226: a long forced nested assembly must arrive complete on the FIRST served request. The
    # cold-session defect (findings F208-F225) showed up as empty text on attempt one and a correct
    # body only on an identical retry, so grading a retry here would prove nothing.
    # F237: the leaf is deliberately NOT enum-constrained. With "enum":["Paris","London"] on the
    # leaf the served path returned a FLAT {"city":"Paris"} and dropped all 40 levels (findings
    # F238) - a separate routing defect. This shape's job is structural completeness on attempt one,
    # so it asserts the walk + leaf types and leaves the enum case filed, not asserted here.
    deep = {"type": "object", "properties": {"city": {"type": "string"},
                                             "count": {"type": "integer"}}}
    for i in range(40):
        deep = {"type": "object", "properties": {"k%d" % i: deep}}
    r = post("/v1/chat/completions", {"messages": [{"role": "user", "content": "The capital of France is"}],
                                      "max_tokens": 1500, "seed": 5,
                                      "response_format": {"type": "json_schema", "json_schema": {
                                          "name": "deep", "schema": deep, "strict": True}}})
    dtxt = r["choices"][0]["message"]["content"]
    dleaf = None
    try:
        dcur = json.loads(dtxt)
        for i in range(39, -1, -1):
            dcur = dcur["k%d" % i]
        dleaf = dcur
    except Exception:
        dleaf = None
    check("chat-json-schema-deep40-first-call",
          isinstance(dleaf, dict) and isinstance(dleaf.get("city"), str)
          and len(dleaf.get("city")) > 0 and isinstance(dleaf.get("count"), int),
          "len=%d closes=%d tail=%r" % (len(dtxt), dtxt.count("}"), dtxt[-40:]))

    # F238: the same 40 levels with an ENUM leaf must keep every level AND honour the enum. The
    # defect was that pass-1's enum regex claimed the deep leaf and answered a flat
    # {"city":"Paris"}; this shape is the guard against that regression.
    deepE = {"type": "object", "properties": {"city": {"type": "string",
                                                       "enum": ["Paris", "London"]},
                                              "count": {"type": "integer"}}}
    for i in range(40):
        deepE = {"type": "object", "properties": {"k%d" % i: deepE}}
    r = post("/v1/chat/completions", {"messages": [{"role": "user", "content": "The capital of France is"}],
                                      "max_tokens": 1500, "seed": 5,
                                      "response_format": {"type": "json_schema", "json_schema": {
                                          "name": "deepe", "schema": deepE, "strict": True}}})
    etxt = r["choices"][0]["message"]["content"]
    eleaf = None
    try:
        ecur = json.loads(etxt)
        for i in range(39, -1, -1):
            ecur = ecur["k%d" % i]
        eleaf = ecur
    except Exception:
        eleaf = None
    check("chat-json-schema-deep40-enum-leaf",
          isinstance(eleaf, dict) and eleaf.get("city") in ("Paris", "London")
          and isinstance(eleaf.get("count"), int),
          "len=%d closes=%d tail=%r" % (len(etxt), etxt.count("}"), etxt[-40:]))

    # F258: the same shape with a NUMERIC enum leaf. Structure must survive and the value must
    # come from the listed set; an unconstrained integer would pass the structural part only.
    deepN = {"type": "object", "properties": {"n": {"type": "integer", "enum": [1000, 2000]},
                                              "city": {"type": "string"}}}
    for i in range(40):
        deepN = {"type": "object", "properties": {"k%d" % i: deepN}}
    r = post("/v1/chat/completions", {"messages": [{"role": "user", "content": "The capital of France is"}],
                                      "max_tokens": 1500, "seed": 5,
                                      "response_format": {"type": "json_schema", "json_schema": {
                                          "name": "deepn", "schema": deepN, "strict": True}}})
    ntxt = r["choices"][0]["message"]["content"]
    nleaf = None
    try:
        ncur = json.loads(ntxt)
        for i in range(39, -1, -1):
            ncur = ncur["k%d" % i]
        nleaf = ncur
    except Exception:
        nleaf = None
    check("chat-json-schema-deep40-enum-num",
          isinstance(nleaf, dict) and nleaf.get("n") in (1000, 2000)
          and isinstance(nleaf.get("city"), str),
          "len=%d closes=%d tail=%r" % (len(ntxt), ntxt.count("}"), ntxt[-40:]))

    # F194a: `tool_choice:"required"` names no function, but whichever call wins must still carry
    # real arguments - an empty object is not executable by the client.
    r = post("/v1/chat/completions", {"messages": [{"role": "user", "content": "Weather in Paris?"}],
                                      "max_tokens": 64, "seed": 3, "tool_choice": "required",
                                      "tools": [{"type": "function", "function": {
                                          "name": "get_weather",
                                          "parameters": {"type": "object", "properties": {
                                              "city": {"type": "string",
                                                       "enum": ["Paris", "London"]}}}}}]})
    tc = r["choices"][0]["message"].get("tool_calls") or []
    rargs = json.loads(tc[0]["function"]["arguments"]) if tc else {}
    check("chat-tool-required-args", bool(tc) and rargs.get("city") in ("Paris", "London"),
          str([[t["function"]["name"] for t in tc], rargs]))

    # F199: parallel_tool_calls is the caller's ask for more than one call; without it the forced
    # choice stays single. Both calls must carry their own id and their own arguments.
    par = {"messages": [{"role": "user", "content": "Call both get_weather and get_time for Paris."}],
           "max_tokens": 256, "seed": 3, "tool_choice": "required", "parallel_tool_calls": True,
           "tools": [{"type": "function", "function": {
               "name": "get_weather", "parameters": {"type": "object", "properties": {
                   "city": {"type": "string", "enum": ["Paris", "London"]}}}}},
               {"type": "function", "function": {
                   "name": "get_time", "parameters": {"type": "object", "properties": {
                       "city": {"type": "string", "enum": ["Paris", "London"]}}}}}]}
    r = post("/v1/chat/completions", par)
    pt = r["choices"][0]["message"].get("tool_calls") or []
    r = post("/v1/chat/completions", {k: v for k, v in par.items() if k != "parallel_tool_calls"})
    st = r["choices"][0]["message"].get("tool_calls") or []
    names = set(t["function"]["name"] for t in pt)
    check("chat-tool-parallel-calls", len(pt) == 2 and len(set(t["id"] for t in pt)) == 2
          and len(names) == 2 and all(json.loads(t["function"]["arguments"]).get("city")
                                      in ("Paris", "London") for t in pt) and len(st) == 1,
          "parallel=%d single=%d %s" % (len(pt), len(st), str(pt)[:160]))

    # The pair candidates carry each tool's own argument values, so the two cities in one prompt
    # must land on the two calls respectively - that is the point of offering the cross product.
    sem = dict(par)
    sem["messages"] = [{"role": "user",
                        "content": "Call get_weather for Paris and get_time for London in one turn."}]
    r = post("/v1/chat/completions", sem)
    st2 = r["choices"][0]["message"].get("tool_calls") or []
    got = dict((t["function"]["name"], json.loads(t["function"]["arguments"]).get("city"))
               for t in st2)
    check("chat-tool-parallel-args", got.get("get_weather") == "Paris"
          and got.get("get_time") == "London", str(st2)[:200])

    # F201: a named tool_choice with parallel_tool_calls answers with the same function twice, one
    # call per argument value - the pair candidates are built from that function's own candidates.
    r = post("/v1/chat/completions", {"messages": [{"role": "user",
                                                    "content": "Weather in Paris and London, "
                                                               "two calls in one turn."}],
                                      "max_tokens": 64, "seed": 5,
                                      "tool_choice": {"type": "function",
                                                      "function": {"name": "get_weather"}},
                                      "parallel_tool_calls": True,
                                      "tools": [{"type": "function", "function": {
                                          "name": "get_weather",
                                          "parameters": {"type": "object", "properties": {
                                              "city": {"type": "string",
                                                       "enum": ["Paris", "London"]}}}}}]})
    nt = r["choices"][0]["message"].get("tool_calls") or []
    ncity = sorted(json.loads(t["function"]["arguments"]).get("city", "") for t in nt)
    check("chat-tool-named-parallel", len(nt) == 2 and ncity == ["London", "Paris"]
          and all(t["function"]["name"] == "get_weather" for t in nt)
          and len(set(t["id"] for t in nt)) == 2, str(nt)[:200])

    # The same parallel turn on the Anthropic outlet must fan out into two tool_use blocks
    # (measured live: Paris and London, stop_reason tool_use) - the fan-out is F196's, the
    # two-block candidates are F201's, and neither was previously proven on this outlet.
    r = post("/v1/messages", {"messages": [{"role": "user",
                                            "content": "Weather in Paris and London, "
                                                       "two calls in one turn."}],
                              "max_tokens": 64, "seed": 5, "parallel_tool_calls": True,
                              "tool_choice": {"type": "any", "name": "get_weather"},
                              "tools": [{"name": "get_weather", "input_schema": {
                                  "type": "object", "properties": {
                                      "city": {"type": "string",
                                               "enum": ["Paris", "London"]}}}}]})
    ab = r.get("content") or []
    acities = sorted(str(x.get("input", {}).get("city")) for x in ab if x.get("type") == "tool_use")
    check("anth-tool-named-parallel", len(ab) == 2 and acities == ["London", "Paris"]
          and r.get("stop_reason") == "tool_use", "%s %s" % (str(ab)[:120], r.get("stop_reason")))

    # F205: the same parallel turn must survive streaming. OpenAI deltas carry one entry per call
    # with its own index and id; Responses streams one output item per call and completes once.
    stream_par = {"messages": [{"role": "user",
                                "content": "Weather in Paris and London, two calls in one turn."}],
                  "max_tokens": 64, "stream": True, "seed": 5, "parallel_tool_calls": True,
                  "tool_choice": {"type": "function", "function": {"name": "get_weather"}},
                  "tools": [{"type": "function", "function": {
                      "name": "get_weather", "parameters": {"type": "object", "properties": {
                          "city": {"type": "string", "enum": ["Paris", "London"]}}}}}]}
    r = post("/v1/chat/completions", stream_par, stream=True)
    ids, idxs, args = set(), set(), []
    for d in sse_data(r):
        if d == "[DONE]":
            continue
        o = json.loads(d)
        for tc in (o.get("choices") or [{}])[0].get("delta", {}).get("tool_calls") or []:
            if "id" in tc:
                ids.add(tc["id"])
                idxs.add(tc.get("index"))
            fa = tc.get("function", {}).get("arguments")
            if fa:
                args.append(fa)
    r = post("/v1/responses", dict(stream_par, input="Weather in Paris and London, "
                                                     "two calls in one turn.",
                                   max_output_tokens=64), stream=True)
    pev = events(r)
    check("chat-tool-parallel-stream", ids == {"call_1", "call_2"} and idxs == {0, 1}
          and "".join(args).count("city") == 2, "%s %s %s" % (sorted(ids), sorted(idxs), args[:2]))
    check("responses-tool-parallel-stream",
          pev.count("response.output_item.added") == 2
          and pev.count("response.output_item.done") == 2
          and pev.count("response.completed") == 1, str(pev[:12]))

    # The same contract must hold on the stream: under json_object the stream ignores `stop`, so the
    # block arrives whole, while the control stream is cut at the leading fence and ends early.
    jstream = {"input": "Return one JSON object with key city, value Paris.",
               "max_output_tokens": 48, "seed": 7, "stream": True, "stop": ["```"]}
    r = post("/v1/responses", dict(jstream, text={"format": {"type": "json_object"}}), stream=True)
    sobj = ""
    for d in sse_data(r):
        o = json.loads(d)
        if o.get("type") == "response.output_text.delta":
            sobj = sobj + o.get("delta", "")
    r = post("/v1/responses", dict(jstream), stream=True)
    scut = ""
    for d in sse_data(r):
        o = json.loads(d)
        if o.get("type") == "response.output_text.delta":
            scut = scut + o.get("delta", "")
    check("responses-json-object-stream", "{" in sobj and "}" in sobj and sobj.count("```") != 1
          and len(sobj) >= len(scut) and (scut == "" or "```" not in scut),
          "json_object_stream=%r control_stream=%r" % (sobj[:60], scut[:60]))

    # Responses streaming must emit the typed event sequence, not Chat chunks.
    r = post("/v1/responses", {"input": "The capital of France is", "max_output_tokens": 8,
                               "stream": True}, stream=True)
    rev = events(r)
    rstream = ""
    for d in sse_data(r):
        o = json.loads(d)
        if o.get("type") == "response.output_text.delta":
            rstream = rstream + o.get("delta", "")
    check("responses-stream",
          ("response.created" in rev and "response.output_text.delta" in rev
           and "response.completed" in rev and "Paris" in rstream
           and "chat.completion.chunk" not in r),
          "%s %s" % (rev[:4], rstream))

    # Responses streaming + json_schema: the schema exit must also use typed events.
    r = post("/v1/responses", {"input": "The capital of France is", "max_output_tokens": 64,
                               "stream": True,
                               "text": {"format": {"type": "json_schema", "name": "city", "schema": {
                                   "type": "object",
                                   "properties": {"city": {"type": "string"}}}}}}, stream=True)
    rev2 = events(r)
    rjtext = ""
    for d in sse_data(r):
        o = json.loads(d)
        if o.get("type") == "response.output_text.delta":
            rjtext = rjtext + o.get("delta", "")
    try:
        rjok = isinstance(json.loads(rjtext)["city"], str)
    except Exception:
        rjok = False
    check("responses-stream-json-schema",
          rjok and "response.created" in rev2 and "response.completed" in rev2
          and "chat.completion.chunk" not in r,
          "%s %s" % (rev2[:4], rjtext))

    # Responses streaming + tool call: function_call item with argument events.
    r = post("/v1/responses", {"input": "Weather in London?", "max_output_tokens": 64,
                               "stream": True,
                               "tools": [{"type": "function", "function": {"name": "get_weather",
                                          "parameters": {"type": "object", "properties": {
                                              "city": {"type": "string",
                                                       "enum": ["Paris", "London"]}}}}}],
                               "tool_choice": {"type": "function",
                                               "function": {"name": "get_weather"}}}, stream=True)
    rev3 = events(r)
    fname, fargs = "", ""
    for d in sse_data(r):
        o = json.loads(d)
        if o.get("type") == "response.output_item.done":
            item = o.get("item", {})
            fname = item.get("name", "")
            fargs = item.get("arguments", "")
    try:
        fcity = json.loads(fargs).get("city")
    except Exception:
        fcity = None
    check("responses-tool-stream",
          fname == "get_weather" and fcity == "London"
          and "response.output_item.added" in rev3
          and "response.function_call_arguments.delta" in rev3
          and "response.completed" in rev3
          and "chat.completion.chunk" not in r,
          "%s %s %s" % (rev3[:4], fname, fargs))

    # Element 2-D array through the Responses exit (same grammar, third dispatch site).
    r2d_schema = {"type": "object", "properties": {"items": {
        "type": "array", "items": {"type": "object", "properties": {
            "m": {"type": "array",
                  "items": {"type": "array", "items": {"type": "string"}}}}}}}}
    r = post("/v1/responses", {"input": "List two records, each with a list of lists of colors.",
                               "max_output_tokens": 64,
                               "text": {"format": {"type": "json_schema", "name": "rows",
                                                   "schema": r2d_schema}}})
    r2text = r["output"][0]["content"][0]["text"]
    try:
        r2val = json.loads(r2text)["items"]
        r2ok = (isinstance(r2val, list) and 1 <= len(r2val) <= 2
                and all(isinstance(e, dict) and set(e.keys()) == {"m"}
                        and isinstance(e["m"], list) and len(e["m"]) >= 1
                        and all(isinstance(inner, list) for inner in e["m"])
                        for e in r2val))
    except Exception:
        r2ok = False
    check("responses-json-schema-array-obj-arr2d", r2ok, r2text)

    # n > 1 returns independent choices (sampled with seed+i), and usage counts every choice.
    r = post("/v1/chat/completions", {"messages": [{"role": "user", "content": "Tell me a short sentence about cats."}],
                                      "max_tokens": 10, "n": 2, "temperature": 1.0, "seed": 7})
    texts = [c["message"]["content"] for c in r["choices"]]
    check("chat-n", len(texts) == 2 and texts[0] != texts[1]
          and r["usage"]["completion_tokens"] == 20, "%s %s" % (len(texts), r["usage"]))

    tools = [{"type": "function", "function": {"name": "get_weather", "parameters": {
        "type": "object",
        "properties": {"city": {"type": "string", "enum": ["Paris", "London"]},
                       "unit": {"type": "string", "enum": ["celsius", "fahrenheit"]}}}}}]
    tool_body = {"messages": [{"role": "user", "content": "What is the weather in London?"}],
                 "max_tokens": 64, "tools": tools,
                 "tool_choice": {"type": "function", "function": {"name": "get_weather"}}}
    r = post("/v1/chat/completions", tool_body)
    ch = r["choices"][0]
    args = json.loads(ch["message"]["tool_calls"][0]["function"]["arguments"])
    check("chat-tool", ch["finish_reason"] == "tool_calls" and args.get("city") == "London", str(args))

    raw = post("/v1/chat/completions", dict(tool_body, stream=True), stream=True)
    deltas = [json.loads(d) for d in sse_data(raw) if d != "[DONE]"]
    streamed = "".join(tc["function"].get("arguments", "")
                       for d in deltas for tc in d["choices"][0]["delta"].get("tool_calls", []))
    check("chat-tool-stream",
          json.loads(streamed).get("city") == "London"
          and deltas[-1]["choices"][0]["finish_reason"] == "tool_calls", streamed)
    raw = post("/v1/chat/completions", dict(tool_body, stream=True,
                                            stream_options={"include_usage": True}), stream=True)
    tchunks = [json.loads(d) for d in sse_data(raw) if d != "[DONE]"]
    tusage = tchunks[-1].get("usage") or {}
    check("chat-tool-usage", tchunks[-1]["choices"] == [] and tusage.get("completion_tokens", 0) > 0,
          str(tchunks[-1])[:160])

    # A completed tool round trip: the assistant call and the tool result must both reach the
    # prompt (the renderer is checked by API-UNIT ids); here we only require a usable answer.
    history = {"messages": [
        {"role": "user", "content": "What is the weather in Paris?"},
        {"role": "assistant", "content": None, "tool_calls": [{"id": "call_1", "type": "function",
            "function": {"name": "get_weather", "arguments": '{"city":"Paris"}'}}]},
        {"role": "tool", "content": "18C and sunny"},
        {"role": "user", "content": "Should I wear a coat?"}], "max_tokens": 24}
    r = post("/v1/chat/completions", history)
    check("chat-tool-history", len(r["choices"][0]["message"]["content"] or "") > 0,
          str(r)[:200])

    anth = {"messages": [{"role": "user", "content": "The capital of France is"}], "max_tokens": 8}
    r = post("/v1/messages", anth)
    check("anth-text", "Paris" in r["content"][0]["text"] and r["stop_reason"] == "end_turn", str(r)[:200])

    # Anthropic structured outputs: output_format is translated into the json_schema marker, so
    # the Messages exit reuses the whole constrained-decoding chain.
    ath_schema = {"messages": [{"role": "user",
                                "content": "List two records, each with a list of lists of colors."}],
                  "max_tokens": 64,
                  "output_format": {"type": "json_schema", "schema": {
                      "type": "object",
                      "properties": {"items": {
                          "type": "array",
                          "items": {"type": "object", "properties": {
                              "m": {"type": "array",
                                    "items": {"type": "array",
                                              "items": {"type": "string"}}}}}}}}}}
    r = post("/v1/messages", ath_schema)
    atcontent = r["content"][0]["text"]
    try:
        atval = json.loads(atcontent)["items"]
        atok = (isinstance(atval, list) and 1 <= len(atval) <= 2
                and all(isinstance(e, dict) and set(e.keys()) == {"m"} for e in atval))
    except Exception:
        atok = False
    check("anth-json-schema-array-obj-arr2d", atok, atcontent)

    # Flat string schema through output_format: exercises the simple grammar path as well.
    ath_str = {"messages": [{"role": "user", "content": "The capital of France is"}],
               "max_tokens": 32,
               "output_format": {"type": "json_schema", "schema": {
                   "type": "object", "properties": {"city": {"type": "string"}}}}}
    r = post("/v1/messages", ath_str)
    atscontent = r["content"][0]["text"]
    try:
        atsval = json.loads(atscontent)["city"]
        atsok = isinstance(atsval, str) and bool(re.fullmatch(r"[A-Za-z0-9 .,'-]*", atsval))
    except Exception:
        atsok = False
    check("anth-json-schema-string", atsok, atscontent)

    # Local $ref inside output_format: the translated copy must still go through $ref resolution.
    ath_ref = {"messages": [{"role": "user", "content": "The capital of France is"}],
               "max_tokens": 32,
               "output_format": {"type": "json_schema", "schema": {
                   "type": "object",
                   "properties": {"city": {"$ref": "#/definitions/city"}},
                   "definitions": {"city": {"type": "string"}}}}}
    r = post("/v1/messages", ath_ref)
    arcontent = r["content"][0]["text"]
    try:
        arval = json.loads(arcontent)["city"]
        arok = isinstance(arval, str)
    except Exception:
        arok = False
    check("anth-json-schema-ref", arok, arcontent)

    # const via output_format: finite candidate path must survive the translation.
    ath_const = {"messages": [{"role": "user", "content": "Pick a color."}],
                 "max_tokens": 32,
                 "output_format": {"type": "json_schema", "schema": {
                     "type": "object",
                     "properties": {"color": {"const": "red"}}}}}
    r = post("/v1/messages", ath_const)
    accontent = r["content"][0]["text"]
    try:
        accval = json.loads(accontent).get("color")
    except Exception:
        accval = None
    check("anth-json-schema-const", accval == "red", accontent)

    # allOf via output_format: member properties merge before detection.
    ath_allof = {"messages": [{"role": "user", "content": "The capital of France is"}],
                 "max_tokens": 32,
                 "output_format": {"type": "json_schema", "schema": {
                     "allOf": [{"type": "object",
                                "properties": {"city": {"type": "string"}}}]}}}
    r = post("/v1/messages", ath_allof)
    aacontent = r["content"][0]["text"]
    try:
        aaval = json.loads(aacontent).get("city")
        aaok = isinstance(aaval, str)
    except Exception:
        aaok = False
    check("anth-json-schema-allof", aaok, aacontent)

    # Same flat schema streamed: the Messages SSE exit replays the constrained value as deltas.
    raw = post("/v1/messages", dict(ath_str, stream=True), stream=True)
    astream = ""
    for d in sse_data(raw):
        o = json.loads(d)
        if o.get("type") == "content_block_delta":
            astream = astream + o["delta"].get("text", "")
    try:
        asval = json.loads(astream)["city"]
        asok = isinstance(asval, str) and bool(re.fullmatch(r"[A-Za-z0-9 .,'-]*", asval))
    except Exception:
        asok = False
    check("anth-json-schema-string-stream", asok, astream)

    # Same 2-D element schema streamed: exercises the grammar dispatch through the replay path.
    raw = post("/v1/messages", dict(ath_schema, stream=True), stream=True)
    a2stream = ""
    a2deltas = 0
    for d in sse_data(raw):
        o = json.loads(d)
        if o.get("type") == "content_block_delta":
            a2deltas += 1
            a2stream = a2stream + o["delta"].get("text", "")
    try:
        a2sval = json.loads(a2stream)["items"]
        a2sok = (isinstance(a2sval, list) and 1 <= len(a2sval) <= 2
                 and all(isinstance(e, dict) and set(e.keys()) == {"m"} for e in a2sval))
    except Exception:
        a2sok = False
    check("anth-json-schema-array-obj-arr2d-stream", a2sok and a2deltas >= 2,
          "%s deltas=%d" % (a2stream, a2deltas))

    ath_tool = {"messages": [{"role": "user", "content": "What is the weather in Paris?"}],
                "max_tokens": 64,
                "tools": [{"name": "get_weather", "input_schema": {
                    "type": "object",
                    "properties": {"city": {"type": "string", "enum": ["Paris", "London"]}}}}],
                "tool_choice": {"type": "tool", "name": "get_weather"}}
    r = post("/v1/messages", ath_tool)
    blk = r["content"][0]
    check("anth-tool", blk["type"] == "tool_use" and blk["name"] == "get_weather"
          and blk["input"].get("city") == "Paris" and r["stop_reason"] == "tool_use", str(blk))

    raw = post("/v1/messages", dict(ath_tool, stream=True), stream=True)
    ev = events(raw)
    partial = ""
    for d in sse_data(raw):
        o = json.loads(d)
        if o.get("type") == "content_block_delta":
            partial = o["delta"].get("partial_json", "")
    stop = ""
    for d in sse_data(raw):
        o = json.loads(d)
        if o.get("type") == "message_delta":
            stop = o["delta"].get("stop_reason", "")
    check("anth-tool-stream",
          ev[0] == "message_start" and ev[-1] == "message_stop" and stop == "tool_use"
          and json.loads(partial).get("city") == "Paris", "%s %s" % (ev, partial))

    # "required" forces a call in the OpenAI stream; Anthropic {"type":"any"} does the same.
    raw = post("/v1/chat/completions", dict(tool_body, tool_choice="required", stream=True), stream=True)
    names = []
    for d in sse_data(raw):
        if d == "[DONE]":
            continue
        for tc in json.loads(d)["choices"][0]["delta"].get("tool_calls", []):
            if tc["function"].get("name"):
                names.append(tc["function"]["name"])
    check("chat-tool-required-stream", names == ["get_weather"], str(names))

    raw = post("/v1/messages", dict(ath_tool, tool_choice={"type": "any"}, stream=True), stream=True)
    any_name = ""
    any_stop = ""
    for d in sse_data(raw):
        o = json.loads(d)
        if o.get("type") == "content_block_start":
            any_name = o["content_block"].get("name", "")
        if o.get("type") == "message_delta":
            any_stop = o["delta"].get("stop_reason", "")
    check("anth-tool-any-stream", any_name == "get_weather" and any_stop == "tool_use",
          "%s %s" % (any_name, any_stop))

    # Anthropic array-shaped history: text block, tool_use block, tool_result block.
    anth_hist = {"messages": [
        {"role": "user", "content": [{"type": "text", "text": "What is the weather in Paris?"}]},
        {"role": "assistant", "content": [{"type": "tool_use", "id": "toolu_1",
            "name": "get_weather", "input": {"city": "Paris"}}]},
        {"role": "user", "content": [{"type": "tool_result", "tool_use_id": "toolu_1",
            "content": "18C and sunny"}]},
        {"role": "user", "content": [{"type": "text", "text": "One word: warm or cold?"}]}],
        "max_tokens": 8}
    r = post("/v1/messages", anth_hist)
    check("anth-tool-history", len(r["content"][0]["text"]) > 0, str(r)[:200])

    # Structured outputs: the answer must be one of the enumerated objects, never free text.
    schema_body = {"messages": [{"role": "user",
                                 "content": "Weather in London. Pick the city and units."}],
                   "max_tokens": 32,
                   "response_format": {"type": "json_schema", "json_schema": {
                       "name": "pick", "schema": {
                           "type": "object",
                           "properties": {"city": {"type": "string", "enum": ["Paris", "London"]},
                                          "units": {"type": "string", "enum": ["celsius", "fahrenheit"]}}}}}}
    r = post("/v1/chat/completions", schema_body)
    content = r["choices"][0]["message"]["content"]
    allowed = {json.dumps({"city": c, "units": u}, separators=(",", ":"))
               for c in ("Paris", "London") for u in ("celsius", "fahrenheit")}
    check("chat-json-schema", content in allowed, content)

    r = post("/v1/chat/completions", dict(schema_body, stream=True), stream=True)
    chunks = [json.loads(d) for d in sse_data(r) if d != "[DONE]"]
    streamed = "".join(ch["choices"][0]["delta"].get("content", "") for ch in chunks)
    check("chat-json-schema-stream",
          streamed in allowed and chunks[-1]["choices"][0]["finish_reason"] == "stop", streamed)
    r = post("/v1/chat/completions", dict(schema_body, stream=True,
                                          stream_options={"include_usage": True}), stream=True)
    chunks = [json.loads(d) for d in sse_data(r) if d != "[DONE]"]
    jusage = chunks[-1].get("usage") or {}
    check("chat-json-schema-usage",
          chunks[-1]["choices"] == [] and jusage.get("completion_tokens", 0) > 0, str(chunks[-1])[:160])

    # Free-form string value: the charset grammar fills it inside the JSON object. The answer
    # must parse, be non-empty, and use only charset bytes (letters, digits, space, . , ' -);
    # an embedded quote or any control byte would mean the grammar leaked.
    str_body = {"messages": [{"role": "user", "content": "The capital of France is"}],
                "max_tokens": 32,
                "response_format": {"type": "json_schema", "json_schema": {
                    "name": "city", "schema": {
                        "type": "object",
                        "properties": {"city": {"type": "string"}}}}}}
    r = post("/v1/chat/completions", str_body)
    scontent = r["choices"][0]["message"]["content"]
    try:
        sval = json.loads(scontent)["city"]
        sok = len(sval) > 0 and bool(re.fullmatch(r"[A-Za-z0-9 .,'-]+", sval))
    except Exception:
        sok = False
    check("chat-json-schema-string", sok, scontent)

    # Same free-form string, streamed on 8000: the grammar must run in the SSE path too.
    r = post("/v1/chat/completions", dict(str_body, stream=True), stream=True)
    chunks = [json.loads(d) for d in sse_data(r) if d != "[DONE]"]
    sstream = "".join(ch["choices"][0]["delta"].get("content", "") for ch in chunks)
    try:
        ssv = json.loads(sstream)["city"]
        ssok = (bool(re.fullmatch(r"[A-Za-z0-9 .,'-]*", ssv))
                and chunks[-1]["choices"][0]["finish_reason"] == "stop")
    except Exception:
        ssok = False
    check("chat-json-schema-string-stream", ssok, sstream)

    # Two string properties: the grammar chains value, forced separator, value, then closes.
    multi_body = {"messages": [{"role": "user", "content": "Name a city and its country."}],
                  "max_tokens": 32,
                  "response_format": {"type": "json_schema", "json_schema": {
                      "name": "place", "schema": {
                          "type": "object",
                          "properties": {"city": {"type": "string"},
                                         "country": {"type": "string"}}}}}}
    r = post("/v1/chat/completions", multi_body)
    mcontent = r["choices"][0]["message"]["content"]
    try:
        mobj = json.loads(mcontent)
        mok = (set(mobj.keys()) == {"city", "country"}
               and all(isinstance(v, str) and re.fullmatch(r"[A-Za-z0-9 .,'-]*", v)
                       for v in mobj.values()))
    except Exception:
        mok = False
    check("chat-json-schema-multi-string", mok, mcontent)

    # Bare integer property: digits only, so the answer must parse as a non-negative int.
    int_body = {"messages": [{"role": "user", "content": "What is 5 minus 10? The answer is"}],
                "max_tokens": 32,
                "response_format": {"type": "json_schema", "json_schema": {
                    "name": "answer", "schema": {
                        "type": "object",
                        "properties": {"answer": {"type": "integer"}}}}}}
    r = post("/v1/chat/completions", int_body)
    icontent = r["choices"][0]["message"]["content"]
    try:
        ival = json.loads(icontent)["answer"]
        iok = isinstance(ival, int) and not isinstance(ival, bool)
    except Exception:
        iok = False
    check("chat-json-schema-integer", iok, icontent)

    # Number property: a fraction is allowed, but whatever is emitted must parse as a JSON number
    # (no bare ".", no leading zero, no double dots).
    num_body = {"messages": [{"role": "user", "content": "What is 1 divided by 2? The answer is"}],
                "max_tokens": 32,
                "response_format": {"type": "json_schema", "json_schema": {
                    "name": "answer", "schema": {
                        "type": "object",
                        "properties": {"answer": {"type": "number"}}}}}}
    r = post("/v1/chat/completions", num_body)
    fcontent = r["choices"][0]["message"]["content"]
    try:
        fval = json.loads(fcontent)["answer"]
        fok = isinstance(fval, (int, float)) and not isinstance(fval, bool)
    except Exception:
        fok = False
    check("chat-json-schema-number", fok, fcontent)

    # One level of nesting: the grammar must keep both braces and the inner key intact.
    nest_body = {"messages": [{"role": "user", "content": "The capital of France is"}],
                 "max_tokens": 32,
                 "response_format": {"type": "json_schema", "json_schema": {
                     "name": "place", "schema": {
                         "type": "object",
                         "properties": {"capital": {"type": "object", "properties": {
                             "city": {"type": "string"}}}}}}}}
    r = post("/v1/chat/completions", nest_body)
    ncontent = r["choices"][0]["message"]["content"]
    try:
        nval = json.loads(ncontent)["capital"]["city"]
        nok = isinstance(nval, str) and bool(re.fullmatch(r"[A-Za-z0-9 .,'-]*", nval))
    except Exception:
        nok = False
    check("chat-json-schema-nested-string", nok, ncontent)

    # Nested object with two leaves: both inner keys must survive (plan-driven assembly).
    nestobj_body = {"messages": [{"role": "user", "content": "The capital of France is"}],
                    "max_tokens": 48,
                    "response_format": {"type": "json_schema", "json_schema": {
                        "name": "stats", "schema": {
                            "type": "object",
                            "properties": {"stats": {"type": "object", "properties": {
                                "city": {"type": "string"},
                                "count": {"type": "integer"}}}}}}}}
    r = post("/v1/chat/completions", nestobj_body)
    nocontent = r["choices"][0]["message"]["content"]
    try:
        noobj = json.loads(nocontent)["stats"]
        nook = (set(noobj.keys()) == {"city", "count"}
                and isinstance(noobj["city"], str)
                and bool(re.fullmatch(r"[A-Za-z0-9 .,'-]*", noobj["city"]))
                and isinstance(noobj["count"], int) and not isinstance(noobj["count"], bool))
    except Exception:
        nook = False
    check("chat-json-schema-nested-object", nook, nocontent)

    # Two-level nesting: the same grammar must keep both wrappers.
    deep_body = {"messages": [{"role": "user", "content": "The capital of France is"}],
                 "max_tokens": 64,
                 "response_format": {"type": "json_schema", "json_schema": {
                     "name": "deep", "schema": {
                         "type": "object",
                         "properties": {"a": {"type": "object", "properties": {
                             "b": {"type": "object", "properties": {
                                 "city": {"type": "string"},
                                 "count": {"type": "integer"}}}}}}}}}}
    r = post("/v1/chat/completions", deep_body)
    dcontent = r["choices"][0]["message"]["content"]
    try:
        dobj = json.loads(dcontent)["a"]["b"]
        dok = (set(dobj.keys()) == {"city", "count"}
               and isinstance(dobj["city"], str)
               and bool(re.fullmatch(r"[A-Za-z0-9 .,'-]*", dobj["city"]))
               and isinstance(dobj["count"], int) and not isinstance(dobj["count"], bool))
    except Exception:
        dok = False
    check("chat-json-schema-deep", dok, dcontent)

    # One level of nesting with a number leaf: the outer object must survive, not just the inner
    # property that the flat number detector would otherwise claim.
    nestnum_body = {"messages": [{"role": "user", "content": "The answer is"}],
                    "max_tokens": 32,
                    "response_format": {"type": "json_schema", "json_schema": {
                        "name": "stats", "schema": {
                            "type": "object",
                            "properties": {"stats": {"type": "object", "properties": {
                                "answer": {"type": "integer"}}}}}}}}
    r = post("/v1/chat/completions", nestnum_body)
    nncontent = r["choices"][0]["message"]["content"]
    try:
        nnval = json.loads(nncontent)["stats"]["answer"]
        nnok = isinstance(nnval, int) and not isinstance(nnval, bool)
    except Exception:
        nnok = False
    check("chat-json-schema-nested-number", nnok, nncontent)

    # Mixed leaves at one level: one string, one integer. Both keys must survive in order.
    mix_body = {"messages": [{"role": "user", "content": "The capital of France is"}],
                "max_tokens": 32,
                "response_format": {"type": "json_schema", "json_schema": {
                    "name": "place", "schema": {
                        "type": "object",
                        "properties": {"city": {"type": "string"},
                                       "population": {"type": "integer"}}}}}}
    r = post("/v1/chat/completions", mix_body)
    mixcontent = r["choices"][0]["message"]["content"]
    try:
        mixobj = json.loads(mixcontent)
        mixok = (isinstance(mixobj["city"], str)
                 and bool(re.fullmatch(r"[A-Za-z0-9 .,'-]*", mixobj["city"]))
                 and isinstance(mixobj["population"], int)
                 and not isinstance(mixobj["population"], bool))
    except Exception:
        mixok = False
    check("chat-json-schema-mixed", mixok, mixcontent)

    # Array of strings: each element comes from the charset, and the list closes with "]"
    # (chosen by the model) or is force-closed at the item budget.
    arr_body = {"messages": [{"role": "user", "content": "Name two French cities."}],
                "max_tokens": 48,
                "response_format": {"type": "json_schema", "json_schema": {
                    "name": "cities", "schema": {
                        "type": "object",
                        "properties": {"cities": {"type": "array",
                                                  "items": {"type": "string"}}}}}}}
    r = post("/v1/chat/completions", arr_body)
    arrcontent = r["choices"][0]["message"]["content"]
    try:
        arrval = json.loads(arrcontent)["cities"]
        arrok = (isinstance(arrval, list) and 1 <= len(arrval) <= 4
                 and all(isinstance(v, str) and re.fullmatch(r"[A-Za-z0-9 .,'-]*", v) for v in arrval))
    except Exception:
        arrok = False
    check("chat-json-schema-array", arrok, arrcontent)

    # Nested arrays: a list of lists of strings, both levels closed by the grammar.
    arr2_body = {"messages": [{"role": "user", "content": "Name two lists of two colors."}],
                 "max_tokens": 64,
                 "response_format": {"type": "json_schema", "json_schema": {
                     "name": "m", "schema": {
                         "type": "object",
                         "properties": {"m": {"type": "array", "items": {
                             "type": "array", "items": {"type": "string"}}}}}}}}
    r = post("/v1/chat/completions", arr2_body)
    a2content = r["choices"][0]["message"]["content"]
    try:
        a2val = json.loads(a2content)["m"]
        a2ok = (isinstance(a2val, list) and 1 <= len(a2val) <= 2
                and all(isinstance(row, list) and 1 <= len(row) <= 2 for row in a2val)
                and all(isinstance(v, str) and re.fullmatch(r"[A-Za-z0-9 .,'-]*", v)
                        for row in a2val for v in row))
    except Exception:
        a2ok = False
    check("chat-json-schema-array2", a2ok, a2content)

    # Same nested array over SSE: the grammar must run in that exit too.
    r = post("/v1/chat/completions", dict(arr2_body, stream=True), stream=True)
    chunks = [json.loads(d) for d in sse_data(r) if d != "[DONE]"]
    a2s = "".join(ch["choices"][0]["delta"].get("content", "") for ch in chunks)
    try:
        a2sv = json.loads(a2s)["m"]
        a2sok = (isinstance(a2sv, list) and 1 <= len(a2sv) <= 2
                 and all(isinstance(row, list) and 1 <= len(row) <= 2 for row in a2sv)
                 and all(isinstance(v, str) and re.fullmatch(r"[A-Za-z0-9 .,'-]*", v)
                         for row in a2sv for v in row)
                 and chunks[-1]["choices"][0]["finish_reason"] == "stop")
    except Exception:
        a2sok = False
    check("chat-json-schema-array2-stream", a2sok, a2s)

    # Three levels of string arrays: list of list of list of strings.
    arr3_body = {"messages": [{"role": "user", "content": "Name two lists of two lists of colors."}],
                 "max_tokens": 96,
                 "response_format": {"type": "json_schema", "json_schema": {
                     "name": "m", "schema": {
                         "type": "object",
                         "properties": {"m": {"type": "array", "items": {
                             "type": "array", "items": {
                                 "type": "array", "items": {"type": "string"}}}}}}}}}
    r = post("/v1/chat/completions", arr3_body)
    a3content = r["choices"][0]["message"]["content"]
    try:
        a3val = json.loads(a3content)["m"]
        a3ok = (isinstance(a3val, list) and 1 <= len(a3val) <= 2
                and all(isinstance(mid, list) and 1 <= len(mid) <= 2 for mid in a3val)
                and all(isinstance(row, list) and 1 <= len(row) <= 2
                        for mid in a3val for row in mid)
                and all(isinstance(v, str) and re.fullmatch(r"[A-Za-z0-9 .,'-]*", v)
                        for mid in a3val for row in mid for v in row))
    except Exception:
        a3ok = False
    check("chat-json-schema-array3", a3ok, a3content)

    # Four levels of string arrays.
    arr4_body = {"messages": [{"role": "user",
                               "content": "Name two lists of two lists of two lists of colors."}],
                 "max_tokens": 128,
                 "response_format": {"type": "json_schema", "json_schema": {
                     "name": "m", "schema": {
                         "type": "object",
                         "properties": {"m": {"type": "array", "items": {
                             "type": "array", "items": {
                                 "type": "array", "items": {
                                     "type": "array",
                                     "items": {"type": "string"}}}}}}}}}}
    r = post("/v1/chat/completions", arr4_body)
    a4content = r["choices"][0]["message"]["content"]

    def depth_ok(v, d):
        if not (isinstance(v, list) and 1 <= len(v) <= 2):
            return False
        if d == 1:
            return all(isinstance(s, str) and re.fullmatch(r"[A-Za-z0-9 .,'-]*", s)
                       for s in v)
        return all(depth_ok(x, d - 1) for x in v)

    try:
        a4ok = depth_ok(json.loads(a4content)["m"], 4)
    except Exception:
        a4ok = False
    check("chat-json-schema-array4", a4ok, a4content)

    # Top-level array schema: the response body itself is the JSON array (no object wrapper).
    top_body = {"messages": [{"role": "user", "content": "Name two colors."}],
                "max_tokens": 32,
                "response_format": {"type": "json_schema", "json_schema": {
                    "name": "x", "schema": {"type": "array",
                                            "items": {"type": "string"}}}}}
    r = post("/v1/chat/completions", top_body)
    tcontent = r["choices"][0]["message"]["content"]
    try:
        tval = json.loads(tcontent)
        tok = (isinstance(tval, list) and 1 <= len(tval) <= 4
               and all(isinstance(v, str) and re.fullmatch(r"[A-Za-z0-9 .,'-]*", v)
                       for v in tval))
    except Exception:
        tok = False
    check("chat-json-schema-top-array", tok, tcontent)

    # Top-level integer array: the body itself is the array of ints.
    topnum_body = {"messages": [{"role": "user", "content": "Name three numbers."}],
                   "max_tokens": 32,
                   "response_format": {"type": "json_schema", "json_schema": {
                       "name": "x", "schema": {"type": "array",
                                               "items": {"type": "integer"}}}}}
    r = post("/v1/chat/completions", topnum_body)
    tncontent = r["choices"][0]["message"]["content"]
    try:
        tnval = json.loads(tncontent)
        tnok = (isinstance(tnval, list) and 1 <= len(tnval) <= 3
                and all(isinstance(v, int) and not isinstance(v, bool) for v in tnval))
    except Exception:
        tnok = False
    check("chat-json-schema-top-array-number", tnok, tncontent)

    # Top-level boolean array: finite domain (lists of 1..3 booleans).
    topbool_body = {"messages": [{"role": "user", "content": "Answer yes or no as a list."}],
                    "max_tokens": 16,
                    "response_format": {"type": "json_schema", "json_schema": {
                        "name": "x", "schema": {"type": "array",
                                                "items": {"type": "boolean"}}}}}
    r = post("/v1/chat/completions", topbool_body)
    tbcontent = r["choices"][0]["message"]["content"]
    try:
        tbval = json.loads(tbcontent)
        tbok = (isinstance(tbval, list) and 1 <= len(tbval) <= 3
                and all(isinstance(v, bool) for v in tbval))
    except Exception:
        tbok = False
    check("chat-json-schema-top-array-bool", tbok, tbcontent)

    # Top-level array of objects: each element keeps exactly name+age.
    topobj_body = {"messages": [{"role": "user", "content": "Name two people with their age."}],
                   "max_tokens": 96,
                   "response_format": {"type": "json_schema", "json_schema": {
                       "name": "x", "schema": {
                           "type": "array",
                           "items": {"type": "object", "properties": {
                               "name": {"type": "string"},
                               "age": {"type": "integer"}}}}}}}
    r = post("/v1/chat/completions", topobj_body)
    tocontent = r["choices"][0]["message"]["content"]
    try:
        toval = json.loads(tocontent)
        took = (isinstance(toval, list) and 1 <= len(toval) <= 2
                and all(isinstance(p, dict) and set(p.keys()) == {"name", "age"}
                        and isinstance(p["name"], str)
                        and re.fullmatch(r"[A-Za-z0-9 .,'-]*", p["name"])
                        and isinstance(p["age"], int) and not isinstance(p["age"], bool)
                        for p in toval))
    except Exception:
        took = False
    check("chat-json-schema-top-array-object", took, tocontent)

    # Top-level array whose elements each hold one nested object.
    topnested_body = {"messages": [{"role": "user", "content": "Name two people with their age."}],
                      "max_tokens": 96,
                      "response_format": {"type": "json_schema", "json_schema": {
                          "name": "x", "schema": {
                              "type": "array",
                              "items": {"type": "object", "properties": {
                                  "meta": {"type": "object", "properties": {
                                      "name": {"type": "string"},
                                      "age": {"type": "integer"}}}}}}}}}
    r = post("/v1/chat/completions", topnested_body)
    txncontent = r["choices"][0]["message"]["content"]
    try:
        txval = json.loads(txncontent)
        txok = (isinstance(txval, list) and 1 <= len(txval) <= 2
                and all(isinstance(e, dict) and set(e.keys()) == {"meta"}
                        and isinstance(e["meta"], dict)
                        and set(e["meta"].keys()) == {"name", "age"}
                        and isinstance(e["meta"]["name"], str)
                        and re.fullmatch(r"[A-Za-z0-9 .,'-]*", e["meta"]["name"])
                        and isinstance(e["meta"]["age"], int)
                        and not isinstance(e["meta"]["age"], bool)
                        for e in txval))
    except Exception:
        txok = False
    check("chat-json-schema-top-array-nested-object", txok, txncontent)

    # Local $ref: the definition is inlined before constrained decoding.
    ref_body = {"messages": [{"role": "user", "content": "Weather in London?"}],
                "max_tokens": 16,
                "response_format": {"type": "json_schema", "json_schema": {
                    "name": "x", "schema": {
                        "type": "object",
                        "definitions": {"City": {"type": "string",
                                                 "enum": ["Paris", "London"]}},
                        "properties": {"city": {"$ref": "#/definitions/City"}}}}}}
    r = post("/v1/chat/completions", ref_body)
    refcontent = r["choices"][0]["message"]["content"]
    check("chat-json-schema-ref", refcontent in ('{"city":"Paris"}', '{"city":"London"}'),
          refcontent)

    # allOf: the members' properties are merged before constrained decoding.
    allof_body = {"messages": [{"role": "user", "content": "Weather in London?"}],
                  "max_tokens": 32,
                  "response_format": {"type": "json_schema", "json_schema": {
                      "name": "x", "schema": {"allOf": [
                          {"type": "object", "properties": {
                              "city": {"type": "string", "enum": ["Paris", "London"]}}},
                          {"type": "object", "properties": {
                              "units": {"type": "string", "enum": ["celsius"]}}}]}}}}
    r = post("/v1/chat/completions", allof_body)
    aocontent = r["choices"][0]["message"]["content"]
    allowed_ao = ('{"city":"Paris","units":"celsius"}',
                  '{"city":"London","units":"celsius"}')
    check("chat-json-schema-allof", aocontent in allowed_ao, aocontent)

    # oneOf of enum branches: the union is the constrained candidate set.
    oneof_body = {"messages": [{"role": "user", "content": "Weather in London?"}],
                  "max_tokens": 16,
                  "response_format": {"type": "json_schema", "json_schema": {
                      "name": "x", "schema": {
                          "type": "object",
                          "properties": {"city": {"oneOf": [
                              {"type": "string", "enum": ["Paris"]},
                              {"type": "string", "enum": ["London"]}]}}}}}}
    r = post("/v1/chat/completions", oneof_body)
    oocontent = r["choices"][0]["message"]["content"]
    check("chat-json-schema-oneof", oocontent in ('{"city":"Paris"}', '{"city":"London"}'),
          oocontent)

    # Root-level oneOf: the union of the branches constrains the answer.
    rootone_body = {"messages": [{"role": "user", "content": "Weather in London?"}],
                    "max_tokens": 16,
                    "response_format": {"type": "json_schema", "json_schema": {
                        "name": "x", "schema": {"oneOf": [
                            {"type": "object", "properties": {
                                "city": {"type": "string", "enum": ["Paris"]}}},
                            {"type": "object", "properties": {
                                "city": {"type": "string", "enum": ["London"]}}}]}}}}
    r = post("/v1/chat/completions", rootone_body)
    rocontent = r["choices"][0]["message"]["content"]
    check("chat-json-schema-root-oneof",
          rocontent in ('{"city":"Paris"}', '{"city":"London"}'), rocontent)

    # Array of integers: every element must parse as an int, and the list must close.
    arrnum_body = {"messages": [{"role": "user", "content": "Name three numbers."}],
                   "max_tokens": 48,
                   "response_format": {"type": "json_schema", "json_schema": {
                       "name": "nums", "schema": {
                           "type": "object",
                           "properties": {"nums": {"type": "array",
                                                   "items": {"type": "integer"}}}}}}}
    r = post("/v1/chat/completions", arrnum_body)
    ancontent = r["choices"][0]["message"]["content"]
    try:
        anvals = json.loads(ancontent)["nums"]
        anok = (isinstance(anvals, list) and 1 <= len(anvals) <= 4
                and all(isinstance(v, int) and not isinstance(v, bool) for v in anvals))
    except Exception:
        anok = False
    check("chat-json-schema-array-number", anok, ancontent)

    # Array of objects: every element must keep both its keys, and the list must close.
    arrobj_body = {"messages": [{"role": "user", "content": "Name two people with their age."}],
                   "max_tokens": 64,
                   "response_format": {"type": "json_schema", "json_schema": {
                       "name": "people", "schema": {
                           "type": "object",
                           "properties": {"people": {
                               "type": "array",
                               "items": {"type": "object", "properties": {
                                   "name": {"type": "string"},
                                   "age": {"type": "integer"}}}}}}}}}
    r = post("/v1/chat/completions", arrobj_body)
    aocontent = r["choices"][0]["message"]["content"]
    try:
        aoval = json.loads(aocontent)["people"]
        aook = (isinstance(aoval, list) and 1 <= len(aoval) <= 3
                and all(isinstance(p, dict)
                        and set(p.keys()) == {"name", "age"}
                        and isinstance(p["name"], str)
                        and re.fullmatch(r"[A-Za-z0-9 .,'-]*", p["name"])
                        and isinstance(p["age"], int) and not isinstance(p["age"], bool)
                        for p in aoval))
    except Exception:
        aook = False
    check("chat-json-schema-array-object", aook, aocontent)

    # Object-array element holding a two-level string array (F163).
    arr2d_body = {"messages": [{"role": "user",
                                "content": "List two records, each with a list of lists of colors."}],
                  "max_tokens": 64,
                  "response_format": {"type": "json_schema", "json_schema": {
                      "name": "rows", "schema": {
                          "type": "object",
                          "properties": {"items": {
                              "type": "array",
                              "items": {"type": "object", "properties": {
                                  "m": {"type": "array",
                                        "items": {"type": "array",
                                                  "items": {"type": "string"}}}}}}}}}}}
    r = post("/v1/chat/completions", arr2d_body)
    a2content = r["choices"][0]["message"]["content"]
    try:
        a2val = json.loads(a2content)["items"]
        a2ok = (isinstance(a2val, list) and 1 <= len(a2val) <= 2
                and all(isinstance(e, dict) and set(e.keys()) == {"m"}
                        and isinstance(e["m"], list) and len(e["m"]) >= 1
                        and all(isinstance(inner, list)
                                and all(isinstance(s, str) for s in inner)
                                for inner in e["m"])
                        for e in a2val))
    except Exception:
        a2ok = False
    check("chat-json-schema-array-obj-arr2d", a2ok, a2content)

    # Same element 2-D array streamed: the SSE exit must dispatch it as well.
    r = post("/v1/chat/completions", dict(arr2d_body, stream=True), stream=True)
    chunks = [json.loads(d) for d in sse_data(r) if d != "[DONE]"]
    a2stream = "".join(ch["choices"][0]["delta"].get("content", "") for ch in chunks)
    try:
        a2sval = json.loads(a2stream)["items"]
        a2sok = (isinstance(a2sval, list) and 1 <= len(a2sval) <= 2
                 and all(isinstance(e, dict) and set(e.keys()) == {"m"}
                         and isinstance(e["m"], list) and len(e["m"]) >= 1
                         for e in a2sval))
    except Exception:
        a2sok = False
    check("chat-json-schema-array-obj-arr2d-stream", a2sok, a2stream)

    # Seven-level string array through the generic N-dimensional builder (F166).
    a7schema = {"type": "string"}
    for _ in range(7):
        a7schema = {"type": "array", "items": a7schema}
    arr7_body = {"messages": [{"role": "user", "content": "Name a color."}],
                 "max_tokens": 64,
                 "response_format": {"type": "json_schema", "json_schema": {
                     "name": "deep", "schema": {"type": "object",
                                                "properties": {"m": a7schema}}}}}
    r = post("/v1/chat/completions", arr7_body)
    a7content = r["choices"][0]["message"]["content"]
    try:
        a7val = json.loads(a7content)["m"]
        for _ in range(7):
            a7val = a7val[0]
        a7ok = isinstance(a7val, str)
    except Exception:
        a7ok = False
    check("chat-json-schema-array7", a7ok, a7content)

    # Seven-level integer array through the generic integer builder (F178).
    i7schema = {"type": "integer"}
    for _ in range(7):
        i7schema = {"type": "array", "items": i7schema}
    arr7i_body = {"messages": [{"role": "user", "content": "Pick a number."}],
                  "max_tokens": 64,
                  "response_format": {"type": "json_schema", "json_schema": {
                      "name": "nums7", "schema": {"type": "object",
                                                  "properties": {"m": i7schema}}}}}
    r = post("/v1/chat/completions", arr7i_body)
    i7content = r["choices"][0]["message"]["content"]
    try:
        i7val = json.loads(i7content)["m"]
        for _ in range(7):
            i7val = i7val[0]
        i7ok = isinstance(i7val, int) and not isinstance(i7val, bool)
    except Exception:
        i7ok = False
    check("chat-json-schema-array7-int", i7ok, i7content)

    # Twenty-level object chain through the live path (the closer token budget is sized by
    # depth; a fixed budget used to truncate 20-level closes).
    deep_leaf = {"type": "object",
                 "properties": {"city": {"type": "string"}, "count": {"type": "integer"}}}
    deep_schema = deep_leaf
    deep_keys = "abcdefghijklmnopqrst"
    for k in reversed(deep_keys):
        deep_schema = {"type": "object", "properties": {k: deep_schema}}
    deep_body = {"messages": [{"role": "user", "content": "The capital of France is"}],
                 "max_tokens": 192,
                 "response_format": {"type": "json_schema", "json_schema": {
                     "name": "deep", "schema": deep_schema}}}
    r = post("/v1/chat/completions", deep_body)
    dcontent = r["choices"][0]["message"]["content"]
    try:
        dval = json.loads(dcontent)
        for k in deep_keys:
            dval = dval[k]
        dok = (isinstance(dval.get("city"), str)
               and isinstance(dval.get("count"), int) and not isinstance(dval.get("count"), bool))
    except Exception:
        dok = False
    check("chat-json-schema-deep20", dok, dcontent)

    # stop / stop_sequences cut the text and report the right reason (baseline answer is "Paris").
    cap = {"messages": [{"role": "user", "content": "The capital of France is"}], "max_tokens": 16}
    r = post("/v1/chat/completions", dict(cap, stop=["ari"]))
    check("chat-stop", r["choices"][0]["message"]["content"] == "P", r["choices"][0]["message"]["content"])

    # Hitting max_tokens must be reported as such, not as a natural stop.
    r = post("/v1/chat/completions", {"messages": [{"role": "user", "content": "The capital of France is"}],
                                      "max_tokens": 1})
    check("chat-maxlen", r["choices"][0]["finish_reason"] == "length",
          str(r["choices"][0])[:120])

    # OpenAI's newer max_completion_tokens is an alias for max_tokens.
    r = post("/v1/chat/completions", {"messages": [{"role": "user", "content": "Count from one to ten."}],
                                      "max_completion_tokens": 1})
    check("chat-max-completion-tokens",
          r["usage"]["completion_tokens"] == 1
          and r["choices"][0]["finish_reason"] == "length",
          str(r["usage"]) + " " + str(r["choices"][0]["finish_reason"]))
    r = post("/v1/messages", {"messages": [{"role": "user", "content": "The capital of France is"}],
                              "max_tokens": 1})
    check("anth-maxlen", r["stop_reason"] == "max_tokens", r["stop_reason"])

    # n>1 works non-streaming; streaming rejects it explicitly instead of silently returning one.
    r = post("/v1/chat/completions", {"messages": [{"role": "user", "content": "The capital of France is"}],
                                      "max_tokens": 8, "n": 2})
    check("chat-n2", len(r["choices"]) == 2
          and all(c["message"]["content"] for c in r["choices"]), str(r["choices"])[:160])
    try:
        nraw = post("/v1/chat/completions", {"messages": [{"role": "user", "content": "The capital of France is"}],
                                             "max_tokens": 8, "n": 2, "stream": True}, stream=True)
        ncode = 200
    except Exception as e:
        body = getattr(e, "read", lambda: b"")()
        nraw = body.decode("utf-8", "replace") if isinstance(body, bytes) else str(body)
        ncode = getattr(e, "code", 0)
    check("chat-n2-stream-rejected",
          ncode == 400 and "streaming supports one choice" in nraw,
          str(ncode) + " " + nraw[:140])

    raw = post("/v1/chat/completions", dict(cap, stop="ari", stream=True), stream=True)
    got = "".join(json.loads(d)["choices"][0]["delta"].get("content", "")
                  for d in sse_data(raw) if d != "[DONE]")
    check("chat-stop-stream", got == "P", got)

    # FR-07.2: the per-request streaming time budget ends the stream promptly as "length".
    t0 = time.time()
    raw = post("/v1/chat/completions", {"messages": [{"role": "user", "content": "Count from one to ten."}],
                                        "max_tokens": 64, "timeout_ms": 1, "stream": True}, stream=True)
    tchunks = [json.loads(d) for d in sse_data(raw) if d != "[DONE]"]
    telapsed = time.time() - t0
    tfin = tchunks[-1]["choices"][0].get("finish_reason") if tchunks else None
    check("chat-stream-timeout", tfin == "length" and telapsed < 5.0,
          "finish=%s %.2fs" % (tfin, telapsed))

    # FR-07.2: a client that drops mid-stream must not wedge the server.
    body = json.dumps({"messages": [{"role": "user", "content": "Count from one to twenty."}],
                       "max_tokens": 64, "stream": True}).encode()
    s = socket.create_connection(("127.0.0.1", PORT), timeout=10)
    s.sendall(b"POST /v1/chat/completions HTTP/1.1\r\nHost: 127.0.0.1\r\n"
              b"Content-Type: application/json\r\nConnection: close\r\n"
              b"Content-Length: " + str(len(body)).encode() + b"\r\n\r\n" + body)
    s.recv(64)
    s.close()
    r = post("/v1/chat/completions", {"messages": [{"role": "user", "content": "The capital of France is"}],
                                      "max_tokens": 8})
    check("chat-stream-client-gone",
          "Paris" in (r["choices"][0]["message"]["content"] or ""),
          str(r)[:160])

    # FR-08: the same seed twice must reproduce the same text.
    sbody = {"messages": [{"role": "user", "content": "The capital of France is"}],
             "max_tokens": 8, "seed": 42}
    s1 = post("/v1/chat/completions", sbody)["choices"][0]["message"]["content"]
    s2 = post("/v1/chat/completions", sbody)["choices"][0]["message"]["content"]
    check("chat-seed-repro", s1 != "" and s1 == s2, "%r vs %r" % (s1, s2))

    # tool_choice "none" on the stream must stay plain text (no tool_calls deltas).
    raw = post("/v1/chat/completions", {"messages": [{"role": "user", "content": "What is the weather in Paris?"}],
                                        "max_tokens": 16, "stream": True, "tool_choice": "none",
                                        "tools": [{"type": "function", "function": {
                                            "name": "get_weather", "parameters": {
                                                "type": "object", "properties": {
                                                    "city": {"type": "string"}}}}}]}, stream=True)
    nchunks = [json.loads(d) for d in sse_data(raw) if d != "[DONE]"]
    ntext = "".join((ch["choices"][0]["delta"].get("content") or "") for ch in nchunks)
    ntool = any(ch["choices"][0]["delta"].get("tool_calls") for ch in nchunks)
    check("chat-tool-none-stream", ntext != "" and not ntool, "%r tool=%s" % (ntext[:80], ntool))

    # tool_choice "auto" must not be mistaken for a named call either: free text is allowed.
    raw = post("/v1/chat/completions", {"messages": [{"role": "user", "content": "The capital of France is"}],
                                        "max_tokens": 16, "stream": True, "tool_choice": "auto",
                                        "tools": [{"type": "function", "function": {
                                            "name": "get_weather", "parameters": {
                                                "type": "object", "properties": {
                                                    "city": {"type": "string"}}}}}]}, stream=True)
    achunks = [json.loads(d) for d in sse_data(raw) if d != "[DONE]"]
    atext = "".join((ch["choices"][0]["delta"].get("content") or "") for ch in achunks)
    check("chat-tool-auto-stream", atext != "", atext[:80])

    # Responses streaming honours stop: the cut text ends at "P".
    raw = post("/v1/responses", {"input": "The capital of France is", "max_output_tokens": 16,
                                 "stream": True, "stop": ["ari"]}, stream=True)
    rev4 = events(raw)
    stext = ""
    for d in sse_data(raw):
        o = json.loads(d)
        if o.get("type") == "response.output_text.delta":
            stext = stext + o.get("delta", "")
    check("responses-stream-stop", stext == "P" and "response.completed" in rev4,
          "%r %s" % (stext, rev4[-2:]))

    r = post("/v1/messages", dict(cap, stop_sequences=["ari"]))
    check("anth-stop", r["content"][0]["text"] == "P" and r["stop_reason"] == "stop_sequence",
          str(r)[:160])

    raw = post("/v1/messages", dict(cap, stop_sequences=["ari"], stream=True), stream=True)
    reason = ""
    for d in sse_data(raw):
        o = json.loads(d)
        if o.get("type") == "message_delta":
            reason = o["delta"].get("stop_reason", "")
    check("anth-stop-stream", reason == "stop_sequence", reason)

    print("API SMOKE: " + ("PASS" if not fails else "%d FAILURE(S)" % len(fails)))
    return 0 if not fails else 1


if __name__ == "__main__":
    sys.exit(main())
