#!/bin/bash
# par_slots_run.sh - measure the served path at a given slot count (F375).
#
# Why this exists: F373 showed the engine can step 3 or 4 conversations in one pass for nearly the
# cost of one (17.1 ms -> 21.4 ms for 1 -> 3 rows), but the 8001 server could not ask for it, so the
# headroom was an engine fact and not a served one. This script starts the server with N slots,
# PROVES the process came up with N (the banner line, not an inference), runs N concurrent streams
# through the existing throughput harness, and leaves the two-slot behaviour untouched when N=2.
#
# usage: par_slots_run.sh <tag> <slots> <streams> [tokens]
set -u
R=/c/git/c/typephp-kakasi
E=$R/engine
TAG=${1:-f375}
SLOTS=${2:-2}
STREAMS=${3:-2}
TOKENS=${4:-60}
export PATH="/c/Program Files (x86)/Intel/oneAPI/compiler/latest/bin:/c/Program Files (x86)/Intel/oneAPI/tbb/latest/bin:$PATH"

powershell -NoProfile -Command 'Get-Process kakasi_engine_sycl -ErrorAction SilentlyContinue | Stop-Process -Force' >/dev/null 2>&1
sleep 2
rm -f "$E/serve.on" "$E/serve.par.on" "$E/serve.par4.on"
printf 'on' > "$E/serve.par.on"
if [ "$SLOTS" -gt 2 ]; then
  printf 'on' > "$E/serve.par4.on"
fi
( cd "$E" && exec ./build/kakasi_engine_sycl.exe ) > "$E/serve_$TAG.log" 2>&1 &

i=0
OK=0
while [ $i -lt 40 ]; do
  if curl -s -m 3 "http://127.0.0.1:8001/health" | grep -q status; then
    OK=1; break
  fi
  sleep 5; i=$((i+1))
done
if [ $OK -ne 1 ]; then
  echo "HEALTH_TIMEOUT port=8001"; tail -5 "$E/serve_$TAG.log"; exit 2
fi

# Shape assertion: the process itself states the concurrency it came up with. Without this line a
# slot count that never took effect would look identical to one that did (the F366 failure).
echo "BANNER: $(grep -E 'PAR slots|ATTN_MODE' "$E/serve_$TAG.log" | tr '\n' ' ')"

python "$E/tests/throughput_test.py" 8001 "$TOKENS" "$STREAMS" > "$E/par_slots_${TAG}_s${STREAMS}.log" 2>&1
echo "THRU_EXIT=$? streams=$STREAMS slots=$SLOTS"
grep -E "serial|concurrent|scaling|THROUGHPUT|error" "$E/par_slots_${TAG}_s${STREAMS}.log" | head -8

# Which slots actually advanced, and how many tokens each produced. The trace line is
# "<epoch> STEP <slot> n=<k> ...", so the slot is field 3 - field 2 is the literal "STEP", and a
# probe reading it would report the same words for every run whatever the server did.
echo "STEP_SLOTS: $(awk '$2=="STEP"{print $3}' "$E/par_trace.log" 2>/dev/null | sort -u | tr '\n' ' ')"
echo "STEP_COUNTS: $(awk '$2=="STEP"{print $3}' "$E/par_trace.log" 2>/dev/null | sort | uniq -c | tr '\n' ' ')"

powershell -NoProfile -Command 'Get-Process kakasi_engine_sycl -ErrorAction SilentlyContinue | Stop-Process -Force' >/dev/null 2>&1
sleep 2
rm -f "$E/serve.on" "$E/serve.par.on" "$E/serve.par4.on"
echo "SENTINELS_LEFT=$(ls "$E"/*.on 2>/dev/null | wc -l)"
echo "PAR_SLOTS_DONE tag=$TAG slots=$SLOTS streams=$STREAMS"
