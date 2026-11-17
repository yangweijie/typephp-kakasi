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
#
# Runtime knobs the engine reads from the environment (both logged at startup, so a run can be read
# back without guessing):
#   KAKASI_ATTN_MODE    attention path: 0 = score cache, 1 = three-pass recompute, 2 = split-KV
#                       (default 2, the shipped path). Logged as "ATTN_MODE <n>".
#   KAKASI_SPEC_WINDOW  prompt-lookup drafter window, 1..6 (default 2). One empty accept widens it
#                       to 3 for the rest of the call, which is what makes the short window safe on
#                       text that does not repeat. Logged as "SPEC_WINDOW <n>". Measured across
#                       three workloads: 1.865x vs 1.758x repetitive, 1.012x vs neutral
#                       non-repetitive, 1.064x vs 1.035x semi-repetitive (F391-F393).
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
  rm -f "$E/serve.on" "$E/serve.par.on" "$E/serve.par4.on"
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
SMOKE_RC=$?
echo "SMOKE_EXIT=$SMOKE_RC" >> "$E/api_smoke_$TAG.log"
echo "SMOKE: PASS=$(grep -c 'PASS' "$E/api_smoke_$TAG.log") FAIL=$(grep -c 'FAIL' "$E/api_smoke_$TAG.log")"
# F596: the same suite with pretty-printed request bodies. Whitespace outside strings must not
# change routing - before the ingestion compaction this run failed 17 schema checks that the
# compact run never touched.
SMOKE_PRETTY=1 python "$E/tests/api_smoke.py" > "$E/api_smoke_pretty_$TAG.log" 2>&1
SMOKE_PRETTY_RC=$?
echo "SMOKE_PRETTY_EXIT=$SMOKE_PRETTY_RC" >> "$E/api_smoke_pretty_$TAG.log"
echo "SMOKE-PRETTY: PASS=$(grep -c 'PASS' "$E/api_smoke_pretty_$TAG.log") FAIL=$(grep -c 'FAIL' "$E/api_smoke_pretty_$TAG.log")"
# F659: the 8000 and 8001 handlers are independent functions, so a schema guard added to one does
# not reach the other - that is how F658b hid (8001 answered an unsatisfiable bounds schema as free
# text while 8000 refused it). The same matrix runs on each server and the two collections are
# compared, so drift between them is a first-class failure instead of an unnoticed hole.
python "$E/tests/schema_parity.py" collect 8000 "$E/schema_parity_8000_$TAG.json" > "$E/schema_parity_8000_$TAG.log" 2>&1
PARITY8_RC=$?
echo "PARITY8_EXIT=$PARITY8_RC" >> "$E/schema_parity_8000_$TAG.log"
# F351: the served speculation claim becomes a standing observation instead of a one-off
# measurement - spec off vs spec on at equal temperature, plus the engine's own counters. Reported,
# not asserted: the honest result so far is "runs, accepts, does not speed this workload up", and a
# future change should show up here as a delta rather than as a new investigation.
python "$E/tests/throughput_test.py" 8000 60 2 "" > "$E/thru_plain_$TAG.log" 2>&1
python "$E/tests/throughput_test.py" 8000 60 2 "2,6" > "$E/thru_spec_$TAG.log" 2>&1
curl -s -m 5 http://127.0.0.1:8000/spec_stats > "$E/spec_stats_$TAG.log" 2>&1
echo "SERVED-SPEC plain: $(grep concurrent "$E/thru_plain_$TAG.log")"
echo "SERVED-SPEC spec : $(grep concurrent "$E/thru_spec_$TAG.log")"
echo "SERVED-SPEC stats: $(cat "$E/spec_stats_$TAG.log")"
# F357: standing single-request speculation reports (greedy chain + sampled tree). REPORT ONLY -
# never an assertion: the probe decides whether its own workload was valid (a run that stops early
# or returns no tokens must not turn into a red gate), and speculation speed is workload-dependent
# by design (F355/F356: greedy 3.31x, sampled ~1.0x on the same engine).
python "$E/tests/spec_greedy_probe.py" 8000 192 - greedy > "$E/spec_greedy_$TAG.log" 2>&1
python "$E/tests/spec_greedy_probe.py" 8000 128 - sampled > "$E/spec_sampled_$TAG.log" 2>&1
echo "SPEC-PROBE greedy  : $(grep speedup "$E/spec_greedy_$TAG.log" | head -1) | $(grep 'SPEC-GREEDY:' "$E/spec_greedy_$TAG.log")"
echo "SPEC-PROBE sampled : $(grep speedup "$E/spec_sampled_$TAG.log" | head -1) | $(grep 'SPEC-GREEDY:' "$E/spec_sampled_$TAG.log")"
stop_all
# F660: the SSD prefix cache had no live gate at all - the self-test only proves reuse inside one
# process, while the promise is that a restart reuses the same KV. This runs its own two engine
# lives on 8000 (cold store, warm hit, restart hit) and compares the three answers.
python "$E/tests/cache_persist.py" > "$E/cache_persist_$TAG.log" 2>&1
CACHEP_RC=$?
echo "CACHEP_EXIT=$CACHEP_RC" >> "$E/cache_persist_$TAG.log"
echo "CACHE-PERSIST: $(grep -m1 '^CACHE-PERSIST:' "$E/cache_persist_$TAG.log")"

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
PAR_RC=$?
echo "PAR_EXIT=$PAR_RC" >> "$E/par_smoke_$TAG.log"
python "$E/tests/fairness_test.py" 8001 > "$E/fairness_$TAG.log" 2>&1
FAIR_RC=$?
echo "FAIR_EXIT=$FAIR_RC" >> "$E/fairness_$TAG.log"
# Aggregate throughput of N concurrent streams vs one - the number the plan has been asserting
# ("throughput is still shared") without ever measuring. Scales with the slots in the 8001 server.
python "$E/tests/throughput_test.py" 8001 "${THRU_TOKENS:-60}" "${THRU_STREAMS:-2}" > "$E/throughput_$TAG.log" 2>&1
echo "THRU_EXIT=$?" >> "$E/throughput_$TAG.log"
# F659: the 8001 side of the parity matrix, then the comparison against the 8000 collection.
python "$E/tests/schema_parity.py" collect 8001 "$E/schema_parity_8001_$TAG.json" > "$E/schema_parity_8001_$TAG.log" 2>&1
PARITY1_RC=$?
echo "PARITY1_EXIT=$PARITY1_RC" >> "$E/schema_parity_8001_$TAG.log"
python "$E/tests/schema_parity.py" compare "$E/schema_parity_8000_$TAG.json" "$E/schema_parity_8001_$TAG.json" > "$E/schema_parity_compare_$TAG.log" 2>&1
PARITYC_RC=$?
echo "PARITYC_EXIT=$PARITYC_RC" >> "$E/schema_parity_compare_$TAG.log"
echo "PARITY: $(grep '^PARITY:' "$E/schema_parity_compare_$TAG.log")"
stop_all
echo "PAR: $(tail -1 "$E/par_smoke_$TAG.log")"
echo "FAIR: $(tail -1 "$E/fairness_$TAG.log")"
echo "THRU: $(grep -E 'scaling|THROUGHPUT:' "$E/throughput_$TAG.log" | tr '\n' ' ')"
echo "SENTINELS_LEFT=$(ls "$E"/*.on 2>/dev/null | wc -l)"
# F596: a failing smoke suite must fail the gate - the FAIL counts were previously printed while
# the script still exited 0, which let red ladders look green.
# F658b: the same held for the 8001 trio. PAR_EXIT=1 was printed on f658 - the cooperative server
# had answered an unsatisfiable bounds schema - and this script still exited 0 because only the
# 8000 exits were checked. The three 8001 results gate here now: PAR and FAIR on their exit codes,
# THRU on its verdict line (that script has no exit status to trust).
THRU_OK=$(grep -c 'THROUGHPUT: PASS' "$E/throughput_$TAG.log" 2>/dev/null)
if [ "${SMOKE_RC:-0}" != "0" ] || [ "${SMOKE_PRETTY_RC:-0}" != "0" ] \
   || [ "${PAR_RC:-0}" != "0" ] || [ "${FAIR_RC:-0}" != "0" ] || [ "${THRU_OK:-0}" -lt 1 ] \
   || [ "${PARITY8_RC:-1}" != "0" ] || [ "${PARITY1_RC:-1}" != "0" ] || [ "${PARITYC_RC:-1}" != "0" ] \
   || [ "${CACHEP_RC:-1}" != "0" ]; then
  echo "GATE_FAIL smoke_rc=${SMOKE_RC:-0} smoke_pretty_rc=${SMOKE_PRETTY_RC:-0} par_rc=${PAR_RC:-0} fair_rc=${FAIR_RC:-0} thru_ok=${THRU_OK:-0} parity8=${PARITY8_RC:-1} parity1=${PARITY1_RC:-1} parityc=${PARITYC_RC:-1} cachep=${CACHEP_RC:-1}"
  exit 1
fi
echo GATE_LIVE_DONE tag=$TAG
