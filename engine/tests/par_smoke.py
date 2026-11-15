"""Smoke for the experimental cooperative server on 8001 (streaming text + finite json_schema).

Usage: python par_smoke.py [port]
Every check states what must be true; a shape the server claims to reject must really reject.
"""
import json
import sys
import threading
import time
import urllib.error
import urllib.request

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8001
BASE = "http://127.0.0.1:%d" % PORT
fails = []


def post(path, body):
    req = urllib.request.Request(BASE + path, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=120) as r:
        return r.read().decode()


def sse(raw):
    return [ln[6:] for ln in raw.splitlines() if ln.startswith("data: ")]


def events(raw):
    return [ln[7:] for ln in raw.splitlines() if ln.startswith("event: ")]


def check(name, cond, detail=""):
    if cond:
        print("  PASS %s" % name)
    else:
        print("  FAIL %s %s" % (name, detail))
        fails.append(name)


def main():
    print("PAR SMOKE port=%d" % PORT)
    with urllib.request.urlopen(BASE + "/health", timeout=30) as r:
        h = json.loads(r.read().decode())
    check("health", h.get("status") == "ok", str(h))

    raw = post("/v1/chat/completions", {"messages": [{"role": "user", "content": "The capital of France is"}],
                                        "max_tokens": 6, "stream": True})
    text = "".join(json.loads(d)["choices"][0]["delta"].get("content", "")
                   for d in sse(raw) if d != "[DONE]")
    check("oai-stream", "Paris" in text and sse(raw)[-1] == "[DONE]", text)

    raw = post("/v1/chat/completions", {"messages": [{"role": "user", "content": "The capital of France is"}],
                                        "max_tokens": 16, "stream": True, "stop": "ari"})
    text = "".join(json.loads(d)["choices"][0]["delta"].get("content", "")
                   for d in sse(raw) if d != "[DONE]")
    check("oai-stop", text == "P", text)

    # json_object owns the shape in the parallel slots too: the stop sequence is dropped, so this
    # reply is not cut to "P" the way the identical request above is.
    raw = post("/v1/chat/completions", {"messages": [{"role": "user", "content": "The capital of France is"}],
                                        "max_tokens": 16, "stream": True, "stop": "ari",
                                        "response_format": {"type": "json_object"}})
    jtext = "".join(json.loads(d)["choices"][0]["delta"].get("content", "")
                    for d in sse(raw) if d != "[DONE]")
    check("oai-json-object-keeps-stream", jtext != "" and jtext != "P", jtext)

    raw = post("/v1/messages", {"messages": [{"role": "user", "content": "The capital of France is"}],
                                "max_tokens": 6, "stream": True})
    ev = events(raw)
    check("anth-stream", ev[0] == "message_start" and ev[-1] == "message_stop", str(ev))

    schema = {"messages": [{"role": "user", "content": "I live in New York."}], "max_tokens": 32,
              "stream": True,
              "response_format": {"type": "json_schema", "json_schema": {"name": "pick", "schema": {
                  "type": "object", "properties": {
                      "city": {"type": "string", "pattern": "^(New York|Los Angeles)$"}}}}}}
    raw = post("/v1/chat/completions", schema)
    text = "".join(json.loads(d)["choices"][0]["delta"].get("content", "")
                   for d in sse(raw) if d != "[DONE]")
    check("json-schema", json.loads(text) == {"city": "New York"}, text)

    try:
        post("/v1/chat/completions", {"messages": [{"role": "user", "content": "hi"}], "stream": True,
                                      "tools": [{"type": "function", "function": {"name": "f"}}]})
        check("tools-rejected", False, "tools were accepted")
    except urllib.error.HTTPError as e:
        check("tools-rejected", e.code == 400, str(e.code))

    # Two long streams occupy both slots; a third concurrent request must be refused with 503.
    done = []

    def long_stream():
        try:
            post("/v1/chat/completions", {"messages": [{"role": "user", "content": "List words."}],
                                          "max_tokens": 120, "stream": True})
            done.append(1)
        except Exception:
            done.append(0)

    ts = [threading.Thread(target=long_stream) for _ in range(2)]
    for t in ts:
        t.start()
    time.sleep(0.4)
    try:
        post("/v1/chat/completions", {"messages": [{"role": "user", "content": "hi"}],
                                      "max_tokens": 2, "stream": True})
        check("third-rejected", False, "third stream accepted")
    except urllib.error.HTTPError as e:
        check("third-rejected", e.code == 503, str(e.code))
    for t in ts:
        t.join()

    # F607: the cooperative server needs the same fail-closed guard - an unclaimed response
    # schema must refuse instead of streaming free text.
    unc = {"messages": [{"role": "user", "content": "Give the data."}], "max_tokens": 48,
           "response_format": {"type": "json_schema", "json_schema": {"name": "x", "schema": {
               "type": "object", "properties": {"m": {"type": "object", "properties": {
                   "x": {"type": "array", "items": {"type": "array", "items": {
                       "type": "object", "properties": {"y": {"type": "string"}}}}}}}}}}}}
    unc_ok = False
    unc_detail = ""
    try:
        unc_raw = post("/v1/chat/completions", unc)
        unc_detail = unc_raw[:160]
    except urllib.error.HTTPError as e:
        unc_ok = e.code == 400
        unc_detail = str(e.code)
    check("par-unclaimed-refuse", unc_ok, unc_detail)

    print("PAR SMOKE: " + ("PASS" if not fails else "%d FAILURE(S)" % len(fails)))
    return 0 if not fails else 1


if __name__ == "__main__":
    sys.exit(main())
