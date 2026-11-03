"""Measure how the single-device server handles concurrent clients.

The engine holds one KV cache and generates synchronously, so the honest expectation is FIFO
queueing: every response correct, wall time ~= N x single-request time. This script proves or
refutes that instead of assuming it.

Usage: python load_test.py [n_clients] [port] [--stream]
"""
import json
import sys
import threading
import time
import urllib.request

ARGS = [a for a in sys.argv[1:] if not a.startswith("--")]
STREAM = "--stream" in sys.argv
N = int(ARGS[0]) if len(ARGS) > 0 else 4
PORT = int(ARGS[1]) if len(ARGS) > 1 else 8000
URL = f"http://127.0.0.1:{PORT}/v1/chat/completions"
BODY = {
    "messages": [{"role": "user", "content": "The capital of France is"}],
    "max_tokens": 8,
}
if STREAM:
    BODY["stream"] = True

results = [None] * N


def one(i):
    t0 = time.time()
    req = urllib.request.Request(URL, data=json.dumps(BODY).encode(),
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=120) as r:
        if STREAM:
            content = ""
            done = False
            for raw in r:
                line = raw.decode().strip()
                if not line.startswith("data: "):
                    continue
                data = line[6:]
                if data == "[DONE]":
                    done = True
                    break
                content += json.loads(data)["choices"][0]["delta"].get("content", "")
            results[i] = (time.time() - t0, content, done)
        else:
            payload = json.loads(r.read().decode())
            results[i] = (time.time() - t0, payload["choices"][0]["message"]["content"], True)


t0 = time.time()
threads = [threading.Thread(target=one, args=(i,)) for i in range(N)]
for t in threads:
    t.start()
for t in threads:
    t.join()
total = time.time() - t0

oks = [r for r in results if r is not None]
texts = [r[1] for r in oks]
times = [r[0] for r in oks]
done = sum(1 for r in oks if r[2])
print(f"clients={N} stream={int(STREAM)} ok={done}/{N} wall={total:.2f}s")
for i, r in enumerate(results):
    print(f"  client {i}: {r[0]:.2f}s  content={r[1]!r}" if r else f"  client {i}: FAILED")
if oks:
    print(f"  sum of individual={sum(times):.2f}s  serialized_ratio={total / min(times):.2f}x")
    print(f"  all_identical={len(set(texts)) == 1}")
