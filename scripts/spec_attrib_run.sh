#!/bin/bash
# spec_attrib_run.sh - per-request speculation attribution (F377).
#
# The engine now writes one line per served request to engine/spec_req.log:
#   <epoch> SPECREQ mode=<plain|greedy|tree> k=<draft length> want=<max_tokens> drafts=.. accepted=..
#   verifies=.. alt_branch=.. max_branches=.. drafting=on|off ms=<generation wall>
# because the counters are reset at the start of every speculative call, so the stats read right
# after a call belong to THAT request. This script drives both groups in the same minutes - the
# same prompt, the same max_tokens, one group with spec_k=0 and one with spec_k>0 - and reports the
# two distributions, which is what the served spec gain has to be quoted from.
#
# usage: spec_attrib_run.sh <tag> [pairs] [max_tokens] [k]
set -u
R=/c/git/typephp-kakasi
E=$R/engine
TAG=${1:-f377}
PAIRS=${2:-6}
MAXTOK=${3:-24}
K=${4:-4}
export PATH="/c/Program Files (x86)/Intel/oneAPI/compiler/latest/bin:/c/Program Files (x86)/Intel/oneAPI/tbb/latest/bin:$PATH"

LOCK="$E/spec_attrib.lock"
if [ -e "$LOCK" ]; then
  echo "LOCKED by $(cat "$LOCK" 2>/dev/null) - another spec_attrib_run is running, refusing"
  exit 4
fi
echo "$$" > "$LOCK"
trap 'rm -f "$LOCK" "$E/serve.on"' EXIT

powershell -NoProfile -Command 'Get-Process kakasi_engine_sycl -ErrorAction SilentlyContinue | Stop-Process -Force' >/dev/null 2>&1
sleep 2
rm -f "$E/serve.on" "$E/serve.par.on" "$E/serve.par4.on"
printf 'on' > "$E/serve.on"
( cd "$E" && exec ./build/kakasi_engine_sycl.exe ) > "$E/serve_spec_$TAG.log" 2>&1 &

i=0
while [ $i -lt 40 ]; do
  if curl -s -m 3 "http://127.0.0.1:8000/health" | grep -q status; then break; fi
  sleep 5; i=$((i+1))
done
if ! curl -s -m 3 "http://127.0.0.1:8000/health" | grep -q status; then
  echo "HEALTH_TIMEOUT port=8000"; tail -5 "$E/serve_spec_$TAG.log"; exit 2
fi

BEFORE=$(wc -l < "$E/spec_req.log" 2>/dev/null || echo 0)
PROMPT="List many English words, comma separated."
for n in $(seq 1 "$PAIRS"); do
  for MODE in plain spec; do
    if [ "$MODE" = "spec" ]; then EXTRA=",\"spec_k\":$K"; else EXTRA=""; fi
    python - "$PROMPT" "$MAXTOK" "$EXTRA" <<'PYEOF' > /dev/null 2>&1
import json, sys, urllib.request
prompt, mt, extra = sys.argv[1], int(sys.argv[2]), sys.argv[3]
body = ('{"messages":[{"role":"user","content":' + json.dumps(prompt) + '}],'
        '"max_tokens":' + str(mt) + ',"temperature":0.0' + extra + '}')
req = urllib.request.Request("http://127.0.0.1:8000/v1/chat/completions",
                             data=body.encode(), headers={"Content-Type": "application/json"})
urllib.request.urlopen(req, timeout=120).read()
PYEOF
    echo "REQ done n=$n mode=$MODE"
  done
done

powershell -NoProfile -Command 'Get-Process kakasi_engine_sycl -ErrorAction SilentlyContinue | Stop-Process -Force' >/dev/null 2>&1
sleep 2
rm -f "$E/serve.on"

python - "$E" "$BEFORE" "$TAG" <<'PYEOF'
import sys, statistics
E, before, tag = sys.argv[1], int(sys.argv[2]), sys.argv[3]
rows = []
with open(E + "/spec_req.log", "r", encoding="utf-8", errors="replace") as f:
    for i, line in enumerate(f):
        if i < before:
            continue
        fv = {}
        for part in line.split():
            if "=" in part:
                k, v = part.split("=", 1)
                fv[k] = v
        if fv.get("mode"):
            rows.append(fv)


def num(v):
    try:
        return float(v)
    except Exception:
        return 0.0


def stats(vals):
    if not vals:
        return "none"
    ys = sorted(vals)
    n = len(ys)
    return "n=%d min=%.1f med=%.1f p75=%.1f max=%.1f" % (
        n, ys[0], ys[n // 2], ys[min(n - 1, int(n * 0.75))], ys[-1])


groups = {}
for r in rows:
    groups.setdefault(r["mode"], []).append(r)

print("SPEC-ATTRIB rows=%d modes=%s" % (len(rows), ",".join(sorted(groups))))
for mode in sorted(groups):
    g = groups[mode]
    d = [num(r.get("drafts")) for r in g]
    a = [num(r.get("accepted")) for r in g]
    v = [num(r.get("verifies")) for r in g]
    ms = [num(r.get("ms")) for r in g]
    want = [num(r.get("want")) for r in g]
    got = [num(r.get("got")) for r in g]
    acc = 100.0 * sum(a) / sum(d) if sum(d) else 0.0
    # F377: divide by the tokens the request ACTUALLY produced. The first version used the
    # requested cap, which made a request that stopped at eos look cheap and produced a table
    # claiming speculation was 8x slower - the two groups had different caps.
    per_tok = [m / g for m, g in zip(ms, got) if g]
    print("SPEC-ATTRIB mode=%-6s requests=%d drafts=%d accepted=%d verifies=%d acc=%.1f%%"
          % (mode, len(g), int(sum(d)), int(sum(a)), int(sum(v)), acc))
    print("SPEC-ATTRIB mode=%-6s gen_ms: %s" % (mode, stats(ms)))
    print("SPEC-ATTRIB mode=%-6s tokens=%d ms_per_token: %s"
          % (mode, int(sum(got)), stats(per_tok)))
    for r in g:
        print("  %s k=%s want=%s drafts=%s accepted=%s verifies=%s ms=%s"
              % (r["mode"], r.get("k"), r.get("want"), r.get("drafts"),
                 r.get("accepted"), r.get("verifies"), r.get("ms")))

# The gain is quoted per token, medians on both sides, same run: two groups with different
# request shapes are not comparable any other way (F377).
def per_token_median(g):
    vals = [num(r["ms"]) / num(r.get("got")) for r in g if num(r.get("got"))]
    return statistics.median(vals) if vals else 0.0

if "plain" in groups and "greedy" in groups:
    pv, gv = per_token_median(groups["plain"]), per_token_median(groups["greedy"])
    print("SPEC-ATTRIB served_gain greedy_vs_plain=%.3fx (ms/token %.2f vs %.2f)"
          % (pv / gv if gv else 0.0, gv, pv))
elif "plain" in groups and "tree" in groups:
    pv, tv = per_token_median(groups["plain"]), per_token_median(groups["tree"])
    print("SPEC-ATTRIB served_gain tree_vs_plain=%.3fx (ms/token %.2f vs %.2f)"
          % (pv / tv if tv else 0.0, tv, pv))
else:
    print("SPEC-ATTRIB NOSUITE - need a plain group and a drafted group in the same run")
PYEOF
echo "SPEC_ATTRIB_DONE tag=$TAG"
