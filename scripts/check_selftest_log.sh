#!/bin/bash
# check_selftest_log.sh - is a self-test log evidence at all?
#
# "grep -c FAIL == 0" has two meanings: the run finished and passed, or the run never got there.
# An aborted self-test stops mid-file, so it contains no FAIL text and looks green. F644 was
# committed on exactly such a log (selftest_f644g.log: RUN_EXIT=-1, no PROFILES section, 0 FAIL).
#
# A log counts as evidence only when all three hold:
#   1. it ends with the bat's completion marker "==RUN_EXIT=0==";
#   2. it is at least as long as the shortest known-complete log (dynamic floor, not a constant);
#   3. it contains every section named on the command line (--sections "A B").
#
#   bash scripts/check_selftest_log.sh                 # classify every selftest_*.log
#   bash scripts/check_selftest_log.sh --summary       # counts only
#   bash scripts/check_selftest_log.sh LOGFILE ...     # judge specific logs
set -u
R=$(cd "$(dirname "$0")/.." && pwd) || exit 2
cd "$R" || exit 2

SUMMARY=0
SECTIONS=""
FILES=""
TERMINAL="INT8-REPRO"
IGNORE_FAILS=0
while [ $# -gt 0 ]; do
  case "$1" in
    --summary) SUMMARY=1 ;;
    --sections) shift; SECTIONS="${1:-}" ;;
    --terminal) shift; TERMINAL="${1:-}" ;;
    --ignore-fails) IGNORE_FAILS=1 ;;
    *) FILES="$FILES $1" ;;
  esac
  shift
done

# Completeness comes from the self-test's own last section, not from the wrapper marker: the
# 883-line logs of F494/F513/F514 ran to the end but predate "==RUN_EXIT=0==". The marker is still
# honoured when present, so a run aborted after printing it cannot pass.
complete_lines=""
for f in engine/selftest_*.log; do
  [ -f "$f" ] || continue
  if grep -q "$TERMINAL" "$f"; then
    n=$(grep -c '' "$f")
    complete_lines="$complete_lines $n"
  fi
done
FLOOR=$(for v in $complete_lines; do echo "$v"; done | sort -n | head -1)
[ -z "$FLOOR" ] && FLOOR=0

judge() {
  local f="$1"
  [ -f "$f" ] || { echo "MISSING $f"; return 1; }
  local n fails marker term sect_ok
  n=$(grep -c '' "$f")
  fails=$(grep -c 'FAIL' "$f")
  term=0
  grep -q "$TERMINAL" "$f" && term=1
  marker=1
  if grep -q '==RUN_EXIT=' "$f"; then
    grep -q '==RUN_EXIT=0==' <(tail -n 5 "$f") || marker=0
  fi
  sect_ok=1
  local s
  for s in $SECTIONS; do
    grep -q "$s" "$f" || sect_ok=0
  done
  if [ "$term" = "1" ] && [ "$marker" = "1" ] && [ "$n" -ge "$FLOOR" ] && [ "$sect_ok" = "1" ] \
     && { [ "$IGNORE_FAILS" = "1" ] || [ "$fails" = "0" ]; }; then
    [ "$SUMMARY" = "1" ] || echo "EVIDENCE $(basename "$f") lines=$n"
    return 0
  fi
  local why=""
  [ "$term" = "0" ] && why="$why no_terminal_section"
  [ "$marker" = "0" ] && why="$why run_exit_not_0"
  [ "$n" -lt "$FLOOR" ] && why="$why short($n<$FLOOR)"
  [ "$sect_ok" = "0" ] && why="$why missing_section"
  [ "$fails" != "0" ] && why="$why fail_lines=$fails"
  [ "$SUMMARY" = "1" ] || echo "NOT_EVIDENCE $(basename "$f") lines=$n$why"
  return 1
}

GOOD=0
BAD=0
if [ -z "$FILES" ]; then
  for f in engine/selftest_*.log; do
    [ -f "$f" ] || continue
    if judge "$f"; then GOOD=$((GOOD + 1)); else BAD=$((BAD + 1)); fi
  done
else
  for f in $FILES; do
    if judge "$f"; then GOOD=$((GOOD + 1)); else BAD=$((BAD + 1)); fi
  done
fi
echo "floor_lines=$FLOOR evidence=$GOOD not_evidence=$BAD"
# Named files are a verdict (used as a hard gate by check_gates.sh); a full-history scan is a
# report, where incomplete old logs are expected and must not fail the caller.
if [ -n "$FILES" ] && [ "$BAD" -gt 0 ]; then
  exit 1
fi
exit 0
