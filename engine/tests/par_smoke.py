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

    # F621: the cooperative server takes the same sampling knobs as 8000, so it must refuse the
    # same unimplemented ones instead of streaming under a different distribution.
    sp_ok = False
    sp_detail = ""
    try:
        sp_raw = post("/v1/chat/completions", {"messages": [{"role": "user", "content": "Hi"}],
                                               "max_tokens": 4, "stream": True, "top_k": 5})
        sp_detail = sp_raw[:120]
    except urllib.error.HTTPError as e:
        sp_body = e.read().decode()
        sp_ok = e.code == 400 and "top_k" in sp_body
        sp_detail = str(e.code) + " " + sp_body[:120]
    check("par-sampling-refuse", sp_ok, sp_detail)

    # F629: count_tokens is stateless, so the cooperative server answers it too; the count must be
    # positive and grow when a system prompt is added (same builder as the message turn).
    c1 = json.loads(post("/v1/messages/count_tokens",
                         {"messages": [{"role": "user", "content": "The capital of France is"}]}))
    c2 = json.loads(post("/v1/messages/count_tokens",
                         {"system": "You are a terse assistant who always answers in one word.",
                          "messages": [{"role": "user", "content": "The capital of France is"}]}))
    check("par-count-tokens", isinstance(c1.get("input_tokens"), int) and c1.get("input_tokens") > 0
          and c2.get("input_tokens", 0) > c1.get("input_tokens", 0), "%r %r" % (c1, c2))

    # F631: the cooperative server applies the same vendor profile at start and reports it.
    with urllib.request.urlopen(BASE + "/plugins", timeout=30) as r:
        pj = json.loads(r.read().decode())
    check("par-plugins", pj.get("applied") == 1 and pj.get("active") not in (None, "", "none"),
          str(pj))

    # F649: residency state on the cooperative server must match 8000's three-state contract.
    # The cold (null) state is not observable from a live server because startup warms residency
    # before any /plugins read can observe it. The three-state contract is proven by the
    # selftest orch[frac] gate which reads the report before any generation.
    check("par-kv-cache-naming", "kv_cache_gib" in pj and "budget_gib" not in pj, str(pj))
    # F653 parity: the resident share's value must be a fraction in (0, 1] and the intent a string.
    rf = pj.get("residency_frac")
    check("par-residency-frac", isinstance(rf, (int, float)) and 0 < rf <= 1
          and isinstance(pj.get("frac_intent"), str), str(pj))
    # F654 parity: honest split reporting on the cooperative server too.
    sp = pj.get("attn_split_p")
    check("par-split-fields", isinstance(sp, int) and sp >= 1
          and isinstance(pj.get("split_state"), str)
          and pj.get("split_state", "").startswith("split")
          and "split_backend" not in pj, str(pj))

    # F636: the cooperative server applies the same parameter set and schema-keyword rules as 8000.
    def par_refusal(body):
        try:
            raw = post("/v1/chat/completions", body)
            return "", "accepted " + raw[:100]
        except urllib.error.HTTPError as e:
            return e.read().decode(), str(e.code)

    pmsg, pdet = par_refusal({"messages": [{"role": "user", "content": "Hi"}], "max_tokens": 4,
                              "stream": True, "logit_bias": {"1": -100}})
    check("par-param-refuse", pmsg.find("logit_bias") >= 0, pdet + " " + pmsg[:120])
    pmsg, pdet = par_refusal({"messages": [{"role": "user", "content": "List tags."}],
                              "max_tokens": 48, "stream": True,
                              "response_format": {"type": "json_schema", "json_schema": {
                                  "name": "x", "schema": {
                                      "type": "object", "properties": {
                                          "tags": {"type": "array", "items": {"type": "string"},
                                                   "uniqueItems": True}}}}}})
    check("par-schema-keyword-refuse", pmsg.find("uniqueItems") >= 0, pdet + " " + pmsg[:120])

    # F640: par errors follow the outlet envelope too - its own writers bypassed F633's transform,
    # so /v1/messages got the OpenAI-shaped body here.
    def par_err_shape(path, extra):
        body = {"messages": [{"role": "user", "content": "Hi"}], "max_tokens": 4}
        body.update(extra)
        try:
            raw = post(path, body)
            return None, "accepted " + raw[:80]
        except urllib.error.HTTPError as e:
            try:
                return json.loads(e.read().decode()), str(e.code)
            except Exception:
                return {}, "unparsed"

    aerr, adet = par_err_shape("/v1/messages", {"stream": True, "top_k": 5})
    check("par-anth-error-shape", isinstance(aerr, dict) and aerr.get("type") == "error"
          and isinstance(aerr.get("error"), dict), adet + " " + str(aerr)[:100])
    oerr, odet = par_err_shape("/v1/chat/completions", {"stream": True, "logit_bias": {"1": -100}})
    check("par-json-error-shape", isinstance(oerr, dict) and oerr.get("type") != "error"
          and isinstance(oerr.get("error"), dict), odet + " " + str(oerr)[:100])

    # F642: plugins endpoint reports weight_prec
    with urllib.request.urlopen(BASE + "/plugins", timeout=30) as r:
        pl = json.loads(r.read().decode())
    check("par-plugins-weight_prec", pl.get("weight_prec") in (0, 1)
          and pl.get("wprec_intent") in (-1, 0, 1)
          and pl.get("profiles", 0) >= 3, str(pl))

    # F649 parity: warm residency on the cooperative server too. Earlier passes in this run have
    # already warmed residency state, so the budget must be a number and it must agree with the
    # profile's intent - same three-state contract as port 8000.
    with urllib.request.urlopen(BASE + "/plugins", timeout=30) as pr2:
        pw = json.loads(pr2.read().decode())
    check("par-residency-warm", pw.get("residency_enabled") == 1
          and isinstance(pw.get("residency_budget_gib"), (int, float)), str(pw))
    dev = pw.get("device_gib")
    if isinstance(dev, (int, float)):
        expected = float(pw.get("budget_intent") or 0)
        if not expected and isinstance(pw.get("frac_intent"), (int, float)):
            expected = float(pw["frac_intent"]) * dev
        if not expected:
            expected = 0.55 * dev
        check("par-budget-same-source",
              abs(float(pw["residency_budget_gib"]) - expected) < 0.05, str(pw))

    print("PAR SMOKE: " + ("PASS" if not fails else "%d FAILURE(S)" % len(fails)))
    return 0 if not fails else 1


if __name__ == "__main__":
    sys.exit(main())
