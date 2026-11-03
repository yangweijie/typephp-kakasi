"""API smoke test: one pass over every response shape the engine promises.

Run against a live server:
    python engine/tests/api_smoke.py [port]

Kept dependency-free (urllib) so it runs on a bare Python. Each check states what
must be true, not just "got 200" - a wrong shape is a failure.
"""
import json
import re
import sys
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

    # stop / stop_sequences cut the text and report the right reason (baseline answer is "Paris").
    cap = {"messages": [{"role": "user", "content": "The capital of France is"}], "max_tokens": 16}
    r = post("/v1/chat/completions", dict(cap, stop=["ari"]))
    check("chat-stop", r["choices"][0]["message"]["content"] == "P", r["choices"][0]["message"]["content"])

    # Hitting max_tokens must be reported as such, not as a natural stop.
    r = post("/v1/chat/completions", {"messages": [{"role": "user", "content": "The capital of France is"}],
                                      "max_tokens": 1})
    check("chat-maxlen", r["choices"][0]["finish_reason"] == "length",
          str(r["choices"][0])[:120])
    r = post("/v1/messages", {"messages": [{"role": "user", "content": "The capital of France is"}],
                              "max_tokens": 1})
    check("anth-maxlen", r["stop_reason"] == "max_tokens", r["stop_reason"])

    raw = post("/v1/chat/completions", dict(cap, stop="ari", stream=True), stream=True)
    got = "".join(json.loads(d)["choices"][0]["delta"].get("content", "")
                  for d in sse_data(raw) if d != "[DONE]")
    check("chat-stop-stream", got == "P", got)

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
