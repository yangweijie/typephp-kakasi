#!/bin/bash
# build-check.sh - build the engine and FAIL LOUDLY if the binary was not actually rebuilt.
#
# Why this exists (F482): tpc can exit 0 right after `convert: main.php` WITHOUT compiling (a
# silent abort on the large main.php). build-kakasi.bat then relinks the OLD objects and still
# prints "Build successful", so a stale binary runs and gates look green. This script forces the
# main translation unit to be regenerated and asserts main.obj's mtime advanced.
#
# Usage: bash scripts/build-check.sh [log-name]
#   exit 0 = built and main.obj advanced; 1 = build failed; 2 = stale (silent abort suspected).
set -u
R=$(cd "$(dirname "$0")/.." && pwd) || exit 3
E=$R/engine
G=$E/build/gen/cache
OBJ=$G/objects/generated/main.obj
TAG=${1:-build_check}
LOG=$R/${TAG}.log

rm -rf "$G"
T0=$(date +%s)
( cd "$R" && cmd //c "scripts\\build-kakasi.bat norun" ) > "$LOG" 2>&1
RC=$?
if [ $RC -ne 0 ]; then
  echo "BUILD_FAILED rc=$RC (see $(basename "$LOG"))"
  exit 1
fi
if [ ! -f "$OBJ" ]; then
  echo "BUILD_STALE: main.obj not produced - tpc silent abort (see $(basename "$LOG"))"
  exit 2
fi
M=$(date -r "$OBJ" +%s)
if [ "$M" -lt "$T0" ]; then
  echo "BUILD_STALE: main.obj mtime $M < build start $T0 - stale binary (see $(basename "$LOG"))"
  exit 2
fi
echo "BUILD_OK main.obj_mtime=$M (see $(basename "$LOG"))"
exit 0
