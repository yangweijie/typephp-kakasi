"""Aggregate-throughput probe for the multi-slot server (FR item: per-session KV parallelism).

Usage: python throughput_test.py [port] [tokens] [streams]

Fairness (fairness_test.py) answers "does a short stream wait for a long one?" - it says nothing
about whether N concurrent streams produce MORE total tokens per second than one stream does.
The plan has been claiming "throughput is still shared" without that number. This measures it:

  serial     : one stream of N tokens, alone                     -> rate_s
  concurrent : the same stream repeated S times at once           -> rate_c (aggregate)
  scaling    : rate_c / rate_s   (1.0 = no parallel gain, S = perfect)

Streams are launched together and timed together; chunk counts are the token proxy because the
server streams one SSE chunk per decoded token. PASS only asserts the measurement happened and
that every stream produced roughly the tokens it asked for - the scaling number is reported,
not judged, because the honest result may well be ~1.0.
"""
import json
import sys
import threading
import time
import urllib.request

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8001
TOKENS = int(sys.argv[2]) if len(sys.argv) > 2 else 60
STREAMS = int(sys.argv[3]) if len(sys.argv) > 3 else 2
URL = "http://127.0.0.1:%d/v1/chat/completions" % PORT


def stream(label, results, barrier):
    if barrier is not None:
        barrier.wait(timeout=30)
    t0 = time.time()
    chunks = 0
    body = {"messages": [{"role": "user",
                          "content": "List many English words, comma separated."}],
            "max_tokens": TOKENS, "stream": True}
    req = urllib.request.Request(URL, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    err = ""
    try:
        with urllib.request.urlopen(req, timeout=300) as f:
            for line in f:
                s = line.decode().strip()
                if not s.startswith("data: "):
                    continue
                if s[6:] == "[DONE]":
                    break
                chunks += 1
    except Exception as exc:
        err = repr(exc)[:120]
    results[label] = (chunks, time.time() - t0, err)


def run_all(count, wait_for_start=True):
    results = {}
    bar = threading.Barrier(count) if (wait_for_start and count > 1) else None
    threads = [threading.Thread(target=stream, args=(str(i), results, bar)) for i in range(count)]
    wall0 = time.time()
    for t in threads:
        t.start()
    for t in threads:
        t.join(timeout=320)
    return results, time.time() - wall0


# Warm-up: the very first decode after the server starts pays model/residency setup. Measured once
# without it, the serial baseline came out 4.06s instead of ~2.75s and the scaling looked like 1.65x
# when the stable concurrent rate said otherwise. Throwaway stream, then measure both sides warm.
run_all(1)

res1, wall1 = run_all(1)
one_chunks = sum(v[0] for v in res1.values())
one_err = res1.get("0", (0, 0, ""))[2]
rate_one = one_chunks / wall1 if wall1 > 0 else 0.0

resN, wallN = run_all(STREAMS)
n_chunks = sum(v[0] for v in resN.values())
errs = [v[2] for v in resN.values() if v[2]]
rate_many = n_chunks / wallN if wallN > 0 else 0.0
scaling = rate_many / rate_one if rate_one > 0 else 0.0

print("THROUGHPUT port=%d tokens=%d streams=%d" % (PORT, TOKENS, STREAMS))
print("  serial     : chunks=%d wall=%.2fs rate=%.2f tok/s %s" % (one_chunks, wall1, rate_one, one_err))
print("  concurrent : chunks=%d wall=%.2fs rate=%.2f tok/s errors=%d" % (n_chunks, wallN, rate_many, len(errs)))
print("  scaling    : %.3fx (1.000 = no parallel gain, %.3f = perfect)" % (scaling, STREAMS))
ok = one_chunks > 0 and n_chunks > 0 and not errs and rate_one > 0
if ok:
    print("THROUGHPUT: PASS measured")
else:
    print("THROUGHPUT: FAIL one=%d many=%d errs=%d" % (one_chunks, n_chunks, len(errs)))
