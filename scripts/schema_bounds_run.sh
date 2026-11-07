#!/bin/bash
# schema_bounds_run.sh - F405 live two-direction check for integer bounds, against the CURRENT
# build: a single integer property with non-negative minimum and/or maximum (any span) must come
# back with a value inside the bounds, and the shapes nobody honours (multi-leaf, negative, float)
# must still be refused with 400.
#
# Same two rules as gate_live.sh: the server starts with cwd=engine (the serve sentinel is read
# relative to CWD, so starting from the repo root silently runs the self-test instead), and a failed
# health probe aborts instead of testing a port nobody listens on.
set -u
R=/c/git/c/typephp-kakasi
E=$R/engine
TAG=${1:-bounds}
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
  rm -f "$E/serve.on" "$E/serve.par.on" "$E/serve.par4.on"
}

stop_all
printf 'on' > "$E/serve.on"
( cd "$E" && exec ./build/kakasi_engine_sycl.exe ) > "$E/serve_$TAG.log" 2>&1 &
if ! wait_health 8000 40; then
  echo "ABORT: server on 8000 never became healthy - the check below would be meaningless"
  tail -5 "$E/serve_$TAG.log"
  stop_all
  exit 2
fi
python "$E/tests/schema_bounds_check.py" 8000 > "$E/schema_bounds_$TAG.log" 2>&1
echo "CHECK_EXIT=$?" >> "$E/schema_bounds_$TAG.log"
cat "$E/schema_bounds_$TAG.log"
stop_all
echo "SENTINELS_LEFT=$(ls "$E"/*.on 2>/dev/null | wc -l)"
echo SCHEMA_BOUNDS_RUN_DONE tag=$TAG
