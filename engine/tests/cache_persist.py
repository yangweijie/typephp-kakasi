"""F660: the SSD prefix cache is an API-facing promise with no live gate until now.

The self-test proves store/reuse inside ONE process. The cache is persisted to the state dir, so the
promise users actually rely on is that a second PROCESS reuses the same KV and answers identically.
The plain decoding branch records `cache=<matched tokens>` into spec_req.log for every request, so
the check does not depend on timing: cold store reads 0, a warm request reads >32, and after a full
engine restart the same prompt must still read the same matched count with byte-identical output.

Cache slot files for the dev layout are cleared first - a leftover entry from an earlier run would
make the cold phase warm and the gate would be measuring the wrong step.
"""
import glob
import json
import os
import subprocess
import sys
import time
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
E = os.path.join(ROOT, "engine")
EXE = os.path.join(E, "build", "kakasi_engine_sycl.exe")
PORT = 8000
URL = "http://127.0.0.1:%d/v1/chat/completions" % PORT
SENTINELS = ("serve.on", "serve.par.on", "serve.par4.on")


def healthy():
    try:
        with urllib.request.urlopen("http://127.0.0.1:%d/health" % PORT, timeout=3) as f:
            return "status" in f.read().decode()
    except Exception:
        return False


def clear_cache():
    for p in glob.glob(os.path.join(E, "api_prefix_b*.kv")) + [os.path.join(E, "api_prefix_index.txt")]:
        try:
            os.remove(p)
        except OSError:
            pass


def start():
    open(os.path.join(E, "serve.on"), "w").write("on")
    p = subprocess.Popen([EXE], cwd=E, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(60):
        if healthy():
            return p
        time.sleep(3)
    stop(p)
    raise SystemExit("CACHE-PERSIST: FAIL server never became healthy")


def stop(p):
    try:
        p.kill()
        p.wait(timeout=30)
    except Exception:
        pass
    for s in SENTINELS:
        try:
            os.remove(os.path.join(E, s))
        except OSError:
            pass
    time.sleep(2)


def log_lines():
    # Real lines only: a file ending in a newline splits to N+1 elements, and counting that trailing
    # empty string made the slice in last_cache skip the newest record.
    path = os.path.join(E, "spec_req.log")
    if not os.path.exists(path):
        return 0
    with open(path, "r", errors="replace") as f:
        return max(0, len(f.read().split("\n")) - 1)


def last_cache(since):
    path = os.path.join(E, "spec_req.log")
    if not os.path.exists(path):
        return None
    with open(path, "r", errors="replace") as f:
        lines = f.read().split("\n")[since:]
    for ln in reversed(lines):
        if "SPECREQ mode=plain" in ln:
            for tok in ln.split():
                if tok.startswith("cache="):
                    return int(tok[6:])
    return None


def ask_anth(prompt):
    """F665: /v1/messages has its own cache call site (a different branch from chat), so reuse there
    is a separate claim - it is measured, not assumed."""
    body = {"messages": [{"role": "user", "content": prompt}], "max_tokens": 24}
    req = urllib.request.Request("http://127.0.0.1:%d/v1/messages" % PORT,
                                 data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=300) as f:
        resp = json.loads(f.read().decode())
    return resp["content"][0]["text"]


def ask_anth_stream(prompt):
    """F666: the streaming Anthropic branch has its own cache call site; it now records a trace line,
    so reuse there can be measured like everywhere else."""
    body = {"messages": [{"role": "user", "content": prompt}], "max_tokens": 24, "stream": True}
    req = urllib.request.Request("http://127.0.0.1:%d/v1/messages" % PORT,
                                 data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=300) as f:
        raw = f.read().decode()
    out = []
    for line in raw.splitlines():
        if not line.startswith("data: "):
            continue
        try:
            ev = json.loads(line[6:])
        except Exception:
            continue
        d = ev.get("delta")
        if isinstance(d, dict) and isinstance(d.get("text"), str):
            out.append(d["text"])
    return "".join(out)


def ask(prompt, use_cache=None):
    body = {"messages": [{"role": "user", "content": prompt}], "max_tokens": 24, "temperature": 0}
    if use_cache is not None:
        body["prefix_cache"] = use_cache
    req = urllib.request.Request(URL, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=300) as f:
        resp = json.loads(f.read().decode())
    return resp["choices"][0]["message"]["content"]


def main():
    prompt = ("Notebook entry %d: the recorder keeps a page for every shift, listing the temperature "
              "and the pressure reading beside the initials of whoever signed it off. " % int(time.time()))
    prompt = prompt * 5 + "Summarize the entry above in one sentence."
    clear_cache()
    since = log_lines()

    p = start()
    a = ask(prompt)
    c1 = last_cache(since)
    print("CACHE-PERSIST debug phase1_new=%d" % (log_lines() - since))
    b = ask(prompt)
    c2 = last_cache(since)
    print("CACHE-PERSIST debug phase2_new=%d" % (log_lines() - since))
    stop(p)

    since = log_lines()
    p = start()
    c = ask(prompt)
    c3 = last_cache(since)
    # F661: a PARTIAL hit must answer exactly like the uncached path. The longer prompt shares the
    # whole stored prefix, so the cache serves part of it and the engine prefills the rest; any
    # off-by-one in the reused rows would change the continuation. prefix_cache:0 is the control
    # and records cache=-2 ("not consulted"), which keeps the two runs distinguishable.
    longer = prompt + " Also name the supervisor on duty."
    x = ask(longer)
    cx = last_cache(since)
    y = ask(longer, use_cache=0)
    cy = last_cache(since)
    # F665: the Anthropic exit has its own cache call site; a cold call there stores, the repeat
    # must reuse and answer identically.
    anth_prompt = ("Ledger sheet %d: the quarter closes with a column for freight, a column for "
                   "duty paid, and a remark naming whoever countersigned the entry. " % int(time.time() + 1))
    anth_prompt = anth_prompt * 5 + "Summarize the ledger sheet in one sentence."
    d1 = ask_anth(anth_prompt)
    cd1 = last_cache(since)
    d2 = ask_anth(anth_prompt)
    cd2 = last_cache(since)
    sprompt = ("Stream ledger %d: the harbour office files one card per vessel, carrying the "
               "tonnage, the flag and the date of the last inspection. " % int(time.time() + 2))
    sprompt = sprompt * 5 + "Summarize the card in one sentence."
    e1 = ask_anth_stream(sprompt)
    ce1 = last_cache(since)
    e2 = ask_anth_stream(sprompt)
    ce2 = last_cache(since)
    stop(p)

    same_ab = a == b
    same_ac = a == c
    same_xy = x == y
    ok = (c1 == 0 and c2 is not None and c2 > 32 and c3 is not None and c3 >= 32
          and same_ab and same_ac
          and cx is not None and cx > 32 and cy == -2 and same_xy
          and cd1 is not None and cd1 < 32 and cd2 is not None and cd2 > 32 and d1 == d2
          and ce1 is not None and ce1 < 32 and ce2 is not None and ce2 > 32 and e1 == e2)
    print("CACHE-PERSIST cold=%s warm=%s restart=%s partial=%s uncached=%s chars=%d"
          % (c1, c2, c3, cx, cy, len(prompt)))
    print("CACHE-PERSIST anth_cold=%s anth_warm=%s anth_same=%d anth_stream=(%s,%s,%d)"
          % (cd1, cd2, d1 == d2, ce1, ce2, e1 == e2))
    print("CACHE-PERSIST same_process=%d after_restart=%d partial_matches_uncached=%d"
          % (same_ab, same_ac, same_xy))
    if ok:
        print("CACHE-PERSIST: PASS (cold store, warm reuse, the same reuse after a restart, a "
              "partial hit answering exactly like the uncached path, and the Anthropic exit "
              "reusing too)")
        return 0
    print("CACHE-PERSIST: FAIL cold=%r warm=%r restart=%r partial=%r uncached=%r anth=(%r,%r) "
          "same_process=%d after_restart=%d partial_matches_uncached=%d"
          % (c1, c2, c3, cx, cy, cd1, cd2, same_ab, same_ac, same_xy))
    return 1


if __name__ == "__main__":
    sys.exit(main())
