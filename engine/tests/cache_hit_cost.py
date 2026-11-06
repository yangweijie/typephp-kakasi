#!/usr/bin/env python3
"""cache_hit_cost.py - where does a prefix-cache HIT spend its time (F380)?

F379 measured a cache-hit request at ~60 ms per produced token against ~22 for a miss on the same
prompt, i.e. a hit was about three times more expensive per token. A hit does two things a miss does
not: it restores KV rows from disk (model_cache_prefill) and it then decodes token by token through a
PHP loop instead of one batched model_generate call. This probe separates them from the client side:

  ttft  = first chunk - request start        (dominated by prefill/restore, not by decoding)
  total = last chunk - request start          (ttft + decode)

Send the SAME prompt twice - the first request misses and stores, the second hits - and compare at
two prompt lengths. If ttft(hit) >> ttft(miss) while the decode cadence is the same, the restore is
the cost and the fix is a decision rule (use the cache only when the prompt is long enough to earn
it back). If ttft is similar and the cadence is slower, the decode loop is the cost and the fix is in
the serving code.

Usage: python cache_hit_cost.py [port] [tokens]
"""
import json
import sys
import time
import urllib.request

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8000
TOKENS = int(sys.argv[2]) if len(sys.argv) > 2 else 24
URL = "http://127.0.0.1:%d/v1/chat/completions" % PORT

SHORT = "List many English words, comma separated."
LONG = ("The following is a technical paragraph about attention mechanisms, repeated so the prompt "
        "is long enough that a prefix cache could plausibly pay for itself. ") * 12


def once(label, prompt):
    body = json.dumps({"messages": [{"role": "user", "content": prompt}],
                       "max_tokens": TOKENS, "stream": True, "temperature": 0.0}).encode()
    req = urllib.request.Request(URL, data=body, headers={"Content-Type": "application/json"})
    t0 = time.time()
    ttft = None
    chunks = 0
    with urllib.request.urlopen(req, timeout=180) as f:
        for raw in f:
            line = raw.decode("utf-8", "replace").strip()
            if not line.startswith("data:"):
                continue
            payload = line[5:].strip()
            if payload == "[DONE]":
                break
            try:
                ch = json.loads(payload)
            except Exception:
                continue
            if not ch.get("choices"):
                continue
            if ch["choices"][0].get("delta", {}).get("content"):
                if ttft is None:
                    ttft = time.time() - t0
                chunks += 1
    total = time.time() - t0
    print("%-12s prompt_tokens~%-4d chunks=%-3d ttft_ms=%7.1f total_ms=%8.1f  decode_ms=%8.1f  "
          "ms_per_token=%6.2f"
          % (label, len(prompt) // 4, chunks,
             (ttft or 0) * 1000.0, total * 1000.0,
             (total - (ttft or 0)) * 1000.0,
             ((total - (ttft or 0)) * 1000.0 / chunks) if chunks else 0.0))
    return ttft or 0.0, total


print("cache_hit_cost port=%d tokens=%d" % (PORT, TOKENS))
for name, prompt in (("short", SHORT), ("long", LONG)):
    once(name + "/miss", prompt)   # first sight of this prompt: miss, then stored
    time.sleep(0.3)
    once(name + "/hit", prompt)    # second sight: served from the cache
    time.sleep(0.3)
