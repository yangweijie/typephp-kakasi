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


def ask(prompt):
    body = {"messages": [{"role": "user", "content": prompt}], "max_tokens": 24, "temperature": 0}
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
    stop(p)

    same_ab = a == b
    same_ac = a == c
    ok = (c1 == 0 and c2 is not None and c2 > 32 and c3 is not None and c3 >= 32
          and same_ab and same_ac)
    print("CACHE-PERSIST cold=%s warm=%s restart=%s chars=%d" % (c1, c2, c3, len(prompt)))
    print("CACHE-PERSIST same_process=%d after_restart=%d" % (same_ab, same_ac))
    if ok:
        print("CACHE-PERSIST: PASS (cold store, warm reuse, and the same reuse after a restart "
              "with identical output)")
        return 0
    print("CACHE-PERSIST: FAIL cold=%r warm=%r restart=%r same_process=%d after_restart=%d"
          % (c1, c2, c3, same_ab, same_ac))
    return 1


if __name__ == "__main__":
    sys.exit(main())
