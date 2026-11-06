"""Fairness probe: does a long streaming request starve a short one?

Usage: python fairness_test.py [port] [long_tokens] [streams]

Starts `streams` - 1 long streams, then 0.4 s later one short stream. Under a serialized server
the short one finishes right after the long ones; under cooperative interleaving it should finish
much earlier. Prints every latency plus time-to-first-chunk.

F376: `streams` defaults to 2, which is exactly the pair this probe has always measured, so the
existing gate keeps its meaning. The N-stream form is what makes "fair" a claim about the served
path rather than about a special case of two: with four slots, three long conversations must not
starve a fourth, short one. It needs a server with at least `streams` slots - otherwise the extra
requests are refused and the run says so instead of quietly measuring a pair.
"""
import json
import sys
import threading
import time
import urllib.request

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8001
LONG = int(sys.argv[2]) if len(sys.argv) > 2 else 160
STREAMS = int(sys.argv[3]) if len(sys.argv) > 3 else 2
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

threads = []
labels = []
for i in range(max(1, STREAMS - 1)):
    lbl = "long" if STREAMS == 2 else "long%d" % i
    labels.append(lbl)
    threads.append(threading.Thread(target=run, args=(lbl, LONG, 0.0)))
labels.append("short")
threads.append(threading.Thread(target=run, args=("short", 4, 0.4)))
for t in threads:
    t.start()
for t in threads:
    t.join()

longs = [res.get(l, ("?", "?", 0)) for l in labels if l.startswith("long")]
shortr = res.get("short", ("?", "?", 0))
print("port=%d streams=%d longs=%s short=%s" % (PORT, STREAMS, longs, shortr))
refused = [l for l in longs if not isinstance(l[0], float)]
if refused:
    # A slot the server does not have is a configuration error, not a fairness result.
    print("FAIRNESS: NOSUITE streams=%d refused_or_failed=%d (needs >= %d slots)"
          % (STREAMS, len(refused), STREAMS))
    sys.exit(3)
ok = (isinstance(shortr[0], float) and shortr[0] < 1.2
      and shortr[0] < min(r[0] for r in longs) - 0.5)
print("FAIRNESS: " + ("PASS (short stream finished well before the long ones)"
                      if ok else "FAIL (short waited for the long streams)"))
sys.exit(0 if ok else 1)
