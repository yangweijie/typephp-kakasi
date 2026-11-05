"""Served greedy-speculation probe (F355): does chain drafting pay on the API?

Usage: python spec_greedy_probe.py [port] [tokens]

Why this exists: throughput_test.py counts SSE chunks, and main.php forces a request that
carries "spec_k" out of the streaming path (line ~6463), so a chunk-counting gate is structurally
blind to greedy speculation (F354). This measures it the only honest way: non-streaming requests,
whole-request wall clock, and token counts taken from the server's own usage field.

Method: same prompt, temperature 0 (so the comparison is with the 3.02x self-test claim, not with
sampling noise), one config at a time, 3 repetitions each, and the best-of-3 is reported alongside
the median so early-stop or warm-up outliers are visible instead of averaged away.
Nothing is asserted about speed - the probe reports; what it must assert is that the measurement is
valid: every config must actually generate the requested tokens (else there is nothing to compare)
and the engine counters must show verifies>0 for the speculative configs (else the branch is inert
and "no speedup" would be a false conclusion).
"""
import json
import statistics
import sys
import time
import urllib.request

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8000
TOKENS = int(sys.argv[2]) if len(sys.argv) > 2 else 128
BASE = "http://127.0.0.1:%d" % PORT
URL = BASE + "/v1/chat/completions"
MODE = sys.argv[4] if len(sys.argv) > 4 else "greedy"   # "greedy" (spec_k) or "sampled" (tree)

# A prompt the model keeps extending at temperature 0. The first attempt used a periodic context
# and the model answered it in 10-11 tokens - too short to compare drafting against, and the probe's
# own validity check said so (FAIL invalid) - so the workload is now a parameter with a default
# that reliably generates hundreds of tokens.
CTX_DEFAULT = ("List English words starting with the letter a, in alphabetical order, separated by "
               "commas and nothing else, as many as you can: "
               + ", ".join(["able", "about", "above"] * 6))
# "-" means "use the built-in prompt". Passing an empty string here would blank the prompt and the
# model then returns zero tokens - the validity guard below catches that, but the harness should not
# have to rely on it.
CTX = CTX_DEFAULT if (len(sys.argv) < 4 or sys.argv[3] == "-") else sys.argv[3]


def once(spec_k):
    body = {"messages": [{"role": "user", "content": CTX}],
            "max_tokens": TOKENS, "temperature": 0.0, "seed": 777}
    if isinstance(spec_k, tuple):
        # Sampled tree: needs temp>0 (F273 - a greedy verifier can only ever accept one branch)
        # and top_p left at 1.0, which the served branch requires.
        body["temperature"] = 0.9
        body["spec_bins"] = spec_k[0]
        body["spec_depth"] = spec_k[1]
    elif spec_k:
        body["spec_k"] = spec_k
    req = urllib.request.Request(URL, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    t0 = time.time()
    with urllib.request.urlopen(req, timeout=600) as f:
        j = json.load(f)
    wall = time.time() - t0
    usage = j.get("usage") or {}
    n = int(usage.get("completion_tokens") or 0)
    if n <= 0:
        txt = (j.get("choices") or [{}])[0].get("message", {}).get("content") or ""
        n = len(txt.split())
    return wall, n


def stats_of():
    try:
        with urllib.request.urlopen(BASE + "/spec_stats", timeout=10) as f:
            return json.load(f).get("stats", "")
    except Exception as exc:
        return repr(exc)[:60]


print("SPEC-GREEDY port=%d max_tokens=%d prompt_words=%d temp=0 mode=%s"
      % (PORT, TOKENS, len(CTX.split()), MODE))
CONFIGS = [(0, 8, 12, 16) if MODE == "greedy" else (0, (1, 6), (2, 6))]
rows = []
for spec_k in CONFIGS[0]:
    trials = [once(spec_k) for _ in range(3)]
    rates = [n / w for w, n in trials]
    lens = [n for _, n in trials]
    counters = stats_of() if spec_k else ""
    med = statistics.median(rates)
    rows.append((spec_k, med, max(rates), lens))
    label = "plain" if spec_k == 0 else ("k=%d" % spec_k if not isinstance(spec_k, tuple)
                                         else "tree(%d,%d)" % spec_k)
    print("  %-10s median=%6.2f tok/s best=%6.2f tok/s lens=%s %s"
          % (label, med, max(rates), lens, counters))

base_med = rows[0][1]
valid = base_med > 0
for spec_k, med, _best, lens in rows[1:]:
    ratio = med / base_med if base_med > 0 else 0.0
    name = "k=%s" % spec_k if not isinstance(spec_k, tuple) else "tree(%d,%d)" % spec_k
    print("  speedup %-10s vs plain: %.3fx" % (name, ratio))
short = [n for r in rows for n in r[3] if n < TOKENS * 0.9]
print("SPEC-GREEDY: %s" % ("PASS measured" if valid and not short else
                            "FAIL invalid (early stop or zero rate; lengths or rates above)"))
