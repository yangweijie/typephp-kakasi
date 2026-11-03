"""Fairness probe: does a long streaming request starve a short one?

Usage: python fairness_test.py [port] [long_tokens]

Starts a long stream, then 0.4 s later a short stream. Under a serialized server the short
one finishes right after the long one; under cooperative interleaving it should finish much
earlier. Prints both latencies plus time-to-first-chunk.
"""
import json
import sys
import threading
import time
import urllib.request

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8001
LONG = int(sys.argv[2]) if len(sys.argv) > 2 else 160
URL = "http://127.0.0.1:%d/v1/chat/completions" % PORT
res = {}


def run(label, max_tokens, delay):
    if delay:
        time.sleep(delay)
    t0 = time.time()
    ttft = None
    chunks = 0
    done = False
    body = {"messages": [{"role": "user", "content": "List many English words, comma separated."}],
            "max_tokens": max_tokens, "stream": True}
    req = urllib.request.Request(URL, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=180) as f:
            for line in f:
                s = line.decode().strip()
                if not s.startswith("data: "):
                    continue
                if s[6:] == "[DONE]":
                    done = True
                    break
                o = json.loads(s[6:])["choices"][0]["delta"]
                if o.get("content"):
                    if ttft is None:
                        ttft = round(time.time() - t0, 2)
                    chunks += 1
    except Exception as e:
        res[label] = ("ERR", str(e), 0)
        return
    res[label] = (round(time.time() - t0, 2), ttft, chunks) if done else ("NODONE", ttft, chunks)


# Warm the engine first: the very first request pays multi-second kernel warmup and would
# otherwise be blamed on scheduling. Run a tiny stream, then measure the pair.
run("warmup", 2, 0.0)
time.sleep(0.3)
res.clear()

tL = threading.Thread(target=run, args=("long", LONG, 0.0))
tS = threading.Thread(target=run, args=("short", 4, 0.4))
tL.start()
tS.start()
tL.join()
tS.join()

longr = res.get("long", ("?", "?", 0))
shortr = res.get("short", ("?", "?", 0))
print("port=%d long=%s short=%s" % (PORT, longr, shortr))
ok = (isinstance(longr[0], float) and isinstance(shortr[0], float)
      and shortr[0] < 1.2 and shortr[0] < longr[0] - 0.5)
print("FAIRNESS: " + ("PASS (short stream finished well before the long one)"
                      if ok else "FAIL (short waited for the long stream)"))
sys.exit(0 if ok else 1)
