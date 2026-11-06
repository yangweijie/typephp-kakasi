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
EW="C:/git/c/typephp-kakasi/engine"   # F378: the POSIX mount blips here; Windows paths keep working
TAG=${1:-f377}
PAIRS=${2:-6}
MAXTOK=${3:-24}
K=${4:-4}
export PATH="/c/Program Files (x86)/Intel/oneAPI/compiler/latest/bin:/c/Program Files (x86)/Intel/oneAPI/tbb/latest/bin:$PATH"

LOCK="$EW/spec_attrib.lock"
if [ -e "$LOCK" ]; then
  echo "LOCKED by $(cat "$LOCK" 2>/dev/null) - another spec_attrib_run is running, refusing"
  exit 4
fi
echo "$$" > "$LOCK"
trap 'rm -f "$LOCK" "$EW/serve.on"' EXIT

powershell -NoProfile -Command 'Get-Process kakasi_engine_sycl -ErrorAction SilentlyContinue | Stop-Process -Force' >/dev/null 2>&1
sleep 2
rm -f "$EW/serve.on" "$EW/serve.par.on" "$EW/serve.par4.on"
# F378: the first version of this script reported HEALTH_TIMEOUT with no explanation. Two
# things made that silent: the sentinel write was never checked (a flaky mount makes it fail
# quietly), and nothing looked for a listener that was already there. Both are checked now,
# and every poll says why it failed.
if curl -s -m 2 "http://127.0.0.1:8000/health" | grep -q status; then
  echo "PORT_BUSY 8000 already answers /health before this script started"; exit 5
fi
# F378: this host s POSIX mount blips - the same write that works a second ago can fail with
# "No such file or directory" for a directory that exists. Retry through the Windows path form
# before giving up, and say which attempt succeeded so the next reader is not surprised.
EW="C:/git/c/typephp-kakasi/engine"
for try in 1 2 3 4 5; do
  printf 'on' > "$EW/serve.on" 2>/dev/null
  if [ -f "$EW/serve.on" ]; then echo "SENTINEL_OK attempt=$try"; break; fi
  echo "SENTINEL_RETRY attempt=$try"; sleep 2
done
if [ ! -f "$EW/serve.on" ]; then
  echo "SENTINEL_WRITE_FAILED $EW/serve.on"; exit 6
fi
( cd "$EW" && exec ./build/kakasi_engine_sycl.exe ) > "$EW/serve_spec_$TAG.log" 2>&1 &

i=0
while [ $i -lt 40 ]; do
  if curl -s -m 3 "http://127.0.0.1:8000/health" | grep -q status; then break; fi
  echo "HEALTH_POLL $i: no answer (engine alive: $(powershell -NoProfile -Command "(Get-Process kakasi_engine_sycl -ErrorAction SilentlyContinue | Measure-Object).Count" 2>/dev/null))"
  sleep 5; i=$((i+1))
done
if ! curl -s -m 3 "http://127.0.0.1:8000/health" | grep -q status; then
  echo "HEALTH_TIMEOUT port=8000"; tail -5 "$EW/serve_spec_$TAG.log"; exit 2
fi

BEFORE=$(wc -l < "$EW/spec_req.log" 2>/dev/null || echo 0)
PROMPT="List many English words, comma separated. Words: apple, banana, cherry, apple, banana, cherry, apple, banana, cherry."
for n in $(seq 1 "$PAIRS"); do
  for MODE in plain spec; do
    # F381: the plain control must decode from scratch. With the prefix cache on by default, the
    # second plain request of a pair is served from the cache while the speculative one is not,
    # so the pair would differ in two things instead of one and the measured gain would be a
    # lower bound on speculation rather than its effect. prefix_cache=0 pins the control.
    if [ "$MODE" = "spec" ]; then EXTRA=",\"spec_k\":$K"; else EXTRA=",\"prefix_cache\":0"; fi
    echo "SPEC_ATTRIB_DONE tag=$TAG"
