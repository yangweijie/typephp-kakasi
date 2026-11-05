#!/usr/bin/env python3
"""chunk_gap_probe.py - when a streamed chunk arrives, and when the server says it made it.

F364: the served path had no timing at all, so the per-token wall could not be split into
"the server was busy" and "the bytes were late". The 8001 server already stamps every token
it produces (par_trace.log, "STEP A n=" lines, microtime(true) at write time), so this probe
stamps the CLIENT side of the same stream and the two timelines can be differenced:

  server cadence = gap between consecutive STEP A timestamps
  client cadence = gap between consecutive chunk arrivals
  transport lag  = client arrival - server write, per token

Both clocks are the same machine's Unix epoch, so the difference is directly meaningful.

Usage: python chunk_gap_probe.py [port] [tokens] [temp] [prompt]
Prints a JSON line so the numbers can be diffed against the server log.
"""
import json
import sys
import time
import urllib.request

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8001
TOKENS = int(sys.argv[2]) if len(sys.argv) > 2 else 60
TEMP = float(sys.argv[3]) if len(sys.argv) > 3 else 0.9
PROMPT = sys.argv[4] if len(sys.argv) > 4 else "List many English words, comma separated."

body = json.dumps({
    "messages": [{"role": "user", "content": PROMPT}],
    "max_tokens": TOKENS,
    "stream": True,
    "temperature": TEMP,
}).encode()

req = urllib.request.Request("http://127.0.0.1:%d/v1/chat/completions" % PORT,
                             data=body, headers={"Content-Type": "application/json"})
t_open = time.time()
resp = urllib.request.urlopen(req, timeout=300)
arrivals = []
for raw in resp:
    line = raw.decode("utf-8", "replace").strip()
    if not line.startswith("data:"):
        continue
    payload = line[5:].strip()
    if payload == "[DONE]":
        break
    try:
        chunk = json.loads(payload)
    except Exception:
        continue
    if not chunk.get("choices"):
        continue
    piece = chunk["choices"][0].get("delta", {}).get("content", "")
    arrivals.append((time.time(), piece))
t_end = time.time()


def stats(xs):
    if not xs:
        return {}
    ys = sorted(xs)
    n = len(ys)
    return {
        "n": n,
        "min": round(ys[0], 4),
        "median": round(ys[n // 2], 4),
        "mean": round(sum(ys) / n, 4),
        "p90": round(ys[min(n - 1, int(n * 0.9))], 4),
        "max": round(ys[-1], 4),
        "sum": round(sum(ys), 4),
    }


gaps = [arrivals[i + 1][0] - arrivals[i][0] for i in range(len(arrivals) - 1)]
out = {
    "port": PORT,
    "tokens": TOKENS,
    "temp": TEMP,
    "chunks": len(arrivals),
    "wall_s": round(t_end - t_open, 4),
    "first_arrival_epoch": round(arrivals[0][0], 6) if arrivals else 0.0,
    "last_arrival_epoch": round(arrivals[-1][0], 6) if arrivals else 0.0,
    "gap_s": stats(gaps),
    # A streamed response ends with a finish chunk that carries no text; only the chunks with
    # content are tokens, and only tokens have a server-side STEP row to pair with.
    "token_arrival_epochs": [round(a[0], 6) for a in arrivals if a[1]],
    "arrival_epochs": [round(a[0], 6) for a in arrivals],
}
print(json.dumps(out))
