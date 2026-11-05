#!/bin/bash
# gate_live.sh - run the live API gates (8000 non-streaming+streaming shapes, then the 8001
# two-slot parity/fairness pair) against the CURRENT build, serialized: one engine process at a
# time, device released between the two.
#
# Why this exists (F309): the engine reads its serve sentinel from a path relative to its CWD, so
# starting it from the repo root silently runs the FULL SELF-TEST instead of a server. That produced
# "SMOKE_EXIT=1 / FAIRNESS FAIL" numbers that looked like engine defects but were a harness bug.
# Two rules are now encoded here: the server is always started with cwd=engine, and a health probe
# timeout ABORTS the chain instead of running tests against a port nobody listens on.
set -u
R=/c/git/c/typephp-kakasi
E=$R/engine
TAG=${1:-live}
export PATH="/c/Program Files (x86)/Intel/oneAPI/compiler/latest/bin:/c/Program Files (x86)/Intel/oneAPI/tbb/latest/bin:/c/git/php/tpc_v0.9.5_windows_x64:/c/git/php/tpc_v0.9.5_windows_x64/phpx/lib:/c/git/php/tpc_v0.9.5_windows_x64/SDK/lib:$PATH"

kill_engine() {
  powershell -NoProfile -Command 'Get-Process kakasi_engine_sycl -ErrorAction SilentlyContinue | Stop-Process -Force' >/dev/null 2>&1
  sleep 3
}

wait_health() { # $1=port $2=max polls
  local i=0
  while [ $i -lt "$2" ]; do
    if curl -s -m 3 "http://127.0.0.1:$1/health" | grep -q status; then
      echo "HEALTH_OK port=$1 polls=$i"; return 0
    fi
    sleep 5; i=$((i+1))
  done
  echo "HEALTH_TIMEOUT port=$1 polls=$i"; return 1
}

stop_all() {
  kill_engine
  rm -f "$E/serve.on" "$E/serve.par.on"
}

# ---------- 8000: single-slot API ----------
stop_all
printf 'on' > "$E/serve.on"          # absolute path, but the process must run with cwd=engine
( cd "$E" && exec ./build/kakasi_engine_sycl.exe ) > "$E/serve_$TAG.log" 2>&1 &
SRV=$!
if ! wait_health 8000 40; then
  echo "ABORT: server on 8000 never became healthy - gate results below would be meaningless"
  tail -5 "$E/serve_$TAG.log"
  stop_all
  exit 2
fi
python "$E/tests/api_smoke.py" > "$E/api_smoke_$TAG.log" 2>&1
echo "SMOKE_EXIT=$?" >> "$E/api_smoke_$TAG.log"
echo "SMOKE: PASS=$(grep -c 'PASS' "$E/api_smoke_$TAG.log") FAIL=$(grep -c 'FAIL' "$E/api_smoke_$TAG.log")"
stop_all

# ---------- 8001: two-slot streaming parity + fairness ----------
printf 'on' > "$E/serve.par.on"
( cd "$E" && exec ./build/kakasi_engine_sycl.exe ) > "$E/serve_par_$TAG.log" 2>&1 &
SRV=$!
if ! wait_health 8001 40; then
  echo "ABORT: server on 8001 never became healthy"
  tail -5 "$E/serve_par_$TAG.log"
  stop_all
  exit 3
fi
python "$E/tests/par_smoke.py" 8001 > "$E/par_smoke_$TAG.log" 2>&1
echo "PAR_EXIT=$?" >> "$E/par_smoke_$TAG.log"
python "$E/tests/fairness_test.py" 8001 > "$E/fairness_$TAG.log" 2>&1
echo "FAIR_EXIT=$?" >> "$E/fairness_$TAG.log"
stop_all
echo "PAR: $(tail -1 "$E/par_smoke_$TAG.log")"
echo "FAIR: $(tail -1 "$E/fairness_$TAG.log")"
echo "SENTINELS_LEFT=$(ls "$E"/*.on 2>/dev/null | wc -l)"
echo GATE_LIVE_DONE tag=$TAG
