#!/bin/bash
# chunk_gap_run.sh - live measurement of the served per-token split (F364).
# Starts the 8001 two-slot server from engine/ (the sentinel is read relative to the CWD, so a
# server started elsewhere silently runs the FULL SELF-TEST), runs the client-side arrival probe
# for each requested temperature, then the two-stream case (which is decomposed from the server's
# own trace, no client probe needed), then stops the server.
#
# usage: chunk_gap_run.sh <tag> <temp1> [temp2 ...]
set -u
R=/c/git/c/typephp-kakasi
E=$R/engine
TAG=${1:-f364}
shift
TEMPS=${*:-"0.9"}
# Same PATH the live gate uses: the engine's DLLs (libmmd.dll etc.) live in the oneAPI dirs.
export PATH="/c/Program Files (x86)/Intel/oneAPI/compiler/latest/bin:/c/Program Files (x86)/Intel/oneAPI/tbb/latest/bin:$PATH"

powershell -NoProfile -Command 'Get-Process kakasi_engine_sycl -ErrorAction SilentlyContinue | Stop-Process -Force' >/dev/null 2>&1
sleep 2
rm -f "$E/serve.on" "$E/serve.par.on" "$E/serve.par4.on"
printf 'on' > "$E/serve.par.on"
( cd "$E" && exec ./build/kakasi_engine_sycl.exe ) > "$E/serve_$TAG.log" 2>&1 &

i=0
OK=0
while [ $i -lt 40 ]; do
  if curl -s -m 3 "http://127.0.0.1:8001/health" | grep -q status; then
    echo "HEALTH_OK polls=$i"; OK=1; break
  fi
  sleep 5; i=$((i+1))
done
if [ $OK -ne 1 ]; then
  echo "HEALTH_TIMEOUT port=8001"; tail -5 "$E/serve_$TAG.log"; exit 2
fi

RUN=0
for T in $TEMPS; do
  RUN=$((RUN+1))
  TS=$(echo "$T" | tr '.' '_')
  BEFORE=$(wc -l < "$E/par_trace.log" 2>/dev/null || echo 0)
  python "$E/tests/chunk_gap_probe.py" 8001 60 "$T" > "$E/chunk_gap_${TAG}_r${RUN}_t${TS}.json" 2>&1
  echo "PROBE_EXIT temp=$T run=$RUN $?"
  python "$E/tests/chunk_gap_analyze.py" "$E/chunk_gap_${TAG}_r${RUN}_t${TS}.json" "$E/par_trace.log" A "$BEFORE" \
    > "$E/chunk_gap_split_${TAG}_r${RUN}_t${TS}.json" 2>&1
  echo "ANALYZE_EXIT temp=$T run=$RUN $?"
  cat "$E/chunk_gap_split_${TAG}_r${RUN}_t${TS}.json"
done

# Two-stream case: the scaling numbers are about TWO streams, and the server traces both slots
# in file order, so the iteration splits into (engine step + slot A's write) and (slot B's write).
BEFORE2=$(wc -l < "$E/par_trace.log" 2>/dev/null || echo 0)
python "$E/tests/throughput_test.py" 8001 60 2 > "$E/chunk_gap_two_${TAG}.log" 2>&1
echo "THRU_EXIT $?"
python "$E/tests/chunk_gap_ab.py" "$E/par_trace.log" "$BEFORE2" 60 > "$E/chunk_gap_ab_${TAG}.json" 2>&1
echo "AB_EXIT $?"
cat "$E/chunk_gap_ab_${TAG}.json"
grep -E "scaling|THROUGHPUT" "$E/chunk_gap_two_${TAG}.log" | tail -3

powershell -NoProfile -Command 'Get-Process kakasi_engine_sycl -ErrorAction SilentlyContinue | Stop-Process -Force' >/dev/null 2>&1
sleep 2
rm -f "$E/serve.on" "$E/serve.par.on"
echo "SENTINELS_LEFT=$(ls "$E"/*.on 2>/dev/null | wc -l)"
echo "CHUNK_GAP_DONE tag=$TAG"
