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

# F376: these harnesses kill every kakasi_engine_sycl process, by design - so two of them running
# at once sabotage each other, and that is not hypothetical: an overlapping run took this one's
# server away between the concurrent pass and the reference pass, and the first version of the
# comparison reported it as "4 flows differ". A lock makes the interference impossible instead of
# merely detectable.
LOCK="$E/par_content.lock"
if [ -e "$LOCK" ]; then
  echo "LOCKED by $(cat "$LOCK" 2>/dev/null) - another par_content_check is running, refusing"
  exit 4
fi
echo "$$" > "$LOCK"
trap 'rm -f "$LOCK" "$E/serve.on" "$E/serve.par.on" "$E/serve.par4.on"' EXIT

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

# F376: liveness check BEFORE the reference pass. The engine process is a machine-wide singleton -
# every harness here kills all kakasi_engine_sycl processes - so a second run started elsewhere can
# take the server away mid-run, and that happened: the four serial probes got connection-refused and
# the comparison still printed "4 flows differ". Missing reference data is not a content mismatch,
# and saying so is what stops an outage being recorded as a defect.
if ! curl -s -m 5 "http://127.0.0.1:8001/health" | grep -q status; then
  echo "SERVER_GONE before the serial pass - no verdict is issued"
  stop_server
  echo "SENTINELS_LEFT=$(ls "$E"/*.on 2>/dev/null | wc -l)"
  echo "PAR_CONTENT_DONE tag=$TAG slots=$SLOTS NOREFERENCE"
  exit 3
fi
# F376: the serial reference runs against the SAME server process. It never needed a restart -
# "one flow at a time" is just a client that does not overlap its own requests - and restarting is
# exactly what stalled last round: the second start never came up (no second WARM line in the serve
# log) because this host's Stop-Process/WMI call can succeed once and then hang, which turned a
# judgement run into twenty silent minutes.
echo "CONCURRENT_DONE - same server, now one flow at a time"

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
missing = 0
for s in range(n):
    try:
        a = json.load(open("%s/content_%s_par%d.json" % (E, tag, s)))["text"]
        b = json.load(open("%s/content_%s_ser%d.json" % (E, tag, s)))["text"]
    except Exception as exc:
        # F376: no data is NOT a mismatch. Bail out without a verdict rather than turning an
        # outage or a killed server into a FAIL that reads like an engine defect.
        print("flow %d: NO_DATA %s" % (s, exc)); missing += 1; continue
    same = a == b
    if not same:
        bad += 1
    print("flow %d: concurrent_bytes=%d serial_bytes=%d identical=%d%s"
          % (s, len(a), len(b), 1 if same else 0,
             "" if same else "  first_diff_at=%d" % (
                 next((i for i in range(min(len(a), len(b))) if a[i] != b[i]),
                      min(len(a), len(b))))))
if missing:
    print("PAR-CONTENT: NOSUITE flows=%d missing_reference=%d differing=%d "
          "(no verdict issued)" % (n, missing, bad))
else:
    print("PAR-CONTENT: %s flows=%d differing=%d"
          % ("PASS" if bad == 0 else "FAIL", n, bad))
PYEOF
echo "PAR_CONTENT_DONE tag=$TAG slots=$SLOTS"
