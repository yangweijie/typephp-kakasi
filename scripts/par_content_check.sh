#!/bin/bash
# par_content_check.sh - does a flow served concurrently say the same thing as that flow alone?
#
# F375 made the served slot count configurable and proved four flows really advance (shape), but
# it did NOT prove content: a batched pass could leak one conversation's context into another, or
# the row planning could pick the wrong visible range, and every throughput number would still look
# healthy. F373 asserted per-id equality inside the engine; this is the same claim one level up, at
# the HTTP text, where the served path actually lives.
#
# usage: par_content_check.sh <tag> <slots> [tokens]
# Same prompts on both sides, same seed (the probe pins seed=12345), four distinct texts so a
# cross-flow leak cannot hide behind identical output.
set -u
R=/c/git/c/typephp-kakasi
E=$R/engine
TAG=${1:-f376}
SLOTS=${2:-4}
TOKENS=${3:-24}
export PATH="/c/Program Files (x86)/Intel/oneAPI/compiler/latest/bin:/c/Program Files (x86)/Intel/oneAPI/tbb/latest/bin:$PATH"

P0="List English names of colors, comma separated."
P1="List English names of planets, comma separated."
P2="List English names of large rivers, comma separated."
P3="List English names of common tools, comma separated."

start_server() {
  powershell -NoProfile -Command 'Get-Process kakasi_engine_sycl -ErrorAction SilentlyContinue | Stop-Process -Force' >/dev/null 2>&1
  sleep 2
  rm -f "$E/serve.on" "$E/serve.par.on" "$E/serve.par4.on"
  printf 'on' > "$E/serve.par.on"
  if [ "$SLOTS" -gt 2 ]; then printf 'on' > "$E/serve.par4.on"; fi
  ( cd "$E" && exec ./build/kakasi_engine_sycl.exe ) > "$E/serve_content_$TAG.log" 2>&1 &
  local i=0
  while [ $i -lt 40 ]; do
    if curl -s -m 3 "http://127.0.0.1:8001/health" | grep -q status; then return 0; fi
    sleep 5; i=$((i+1))
  done
  echo "HEALTH_TIMEOUT port=8001"; tail -5 "$E/serve_content_$TAG.log"; return 2
}
stop_server() {
  powershell -NoProfile -Command 'Get-Process kakasi_engine_sycl -ErrorAction SilentlyContinue | Stop-Process -Force' >/dev/null 2>&1
  sleep 2
  rm -f "$E/serve.on" "$E/serve.par.on" "$E/serve.par4.on"
}

start_server || exit 2
echo "BANNER: $(grep -E 'PAR slots|ATTN_MODE' "$E/serve_content_$TAG.log" | tr '\n' ' ')"

# Concurrent pass: all $SLOTS flows in flight at once, each in its own process.
N=$((SLOTS))
for s in $(seq 0 $((N-1))); do
  case $s in
    0) PR="$P0";; 1) PR="$P1";; 2) PR="$P2";; 3) PR="$P3";;
    *) PR="$P0 $s";;
  esac
  python "$E/tests/chunk_gap_probe.py" 8001 "$TOKENS" 0.0 "$PR" \
    > "$E/content_${TAG}_par${s}.json" 2>&1 &
done
wait

stop_server
start_server || exit 2

# Serial reference: the SAME prompts, one flow at a time, so nothing is batched with them.
for s in $(seq 0 $((N-1))); do
  case $s in
    0) PR="$P0";; 1) PR="$P1";; 2) PR="$P2";; 3) PR="$P3";;
    *) PR="$P0 $s";;
  esac
  python "$E/tests/chunk_gap_probe.py" 8001 "$TOKENS" 0.0 "$PR" \
    > "$E/content_${TAG}_ser${s}.json" 2>&1
done

stop_server
echo "SENTINELS_LEFT=$(ls "$E"/*.on 2>/dev/null | wc -l)"

# Per-flow verdict, printed as bytes and a boolean - never a bare "PASS" for the whole run, because
# one differing flow out of four has to be visible in the output and not averaged away.
python - "$E" "$TAG" "$N" <<'PYEOF'
import json, sys
E, tag, n = sys.argv[1], sys.argv[2], int(sys.argv[3])
bad = 0
for s in range(n):
    try:
        a = json.load(open("%s/content_%s_par%d.json" % (E, tag, s)))["text"]
        b = json.load(open("%s/content_%s_ser%d.json" % (E, tag, s)))["text"]
    except Exception as exc:
        print("flow %d: UNREADABLE %s" % (s, exc)); bad += 1; continue
    same = a == b
    if not same:
        bad += 1
    print("flow %d: concurrent_bytes=%d serial_bytes=%d identical=%d%s"
          % (s, len(a), len(b), 1 if same else 0,
             "" if same else "  first_diff_at=%d" % (
                 next((i for i in range(min(len(a), len(b))) if a[i] != b[i]),
                      min(len(a), len(b))))))
print("PAR-CONTENT: %s flows=%d differing=%d" % ("PASS" if bad == 0 else "FAIL", n, bad))
PYEOF
echo "PAR_CONTENT_DONE tag=$TAG slots=$SLOTS"
