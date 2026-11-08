#!/bin/bash
# check_citations.sh - every <name>.log cited by the planning docs should exist as a tracked file.
#
# Why this exists: a batch deletion of ignored logs left citations dangling (the build_f1xx ones in
# findings and the audit document), and a rule that lives only in prose did not prevent it. The
# output is a REPORT, not a gate: historical citations to files that were never committed are
# expected, and the point is to see them before deleting anything else, not to fail on them.
#
#   bash scripts/check_citations.sh          # summarize
#   bash scripts/check_citations.sh --list   # also list each missing name
set -u
R=$(cd "$(dirname "$0")/.." && pwd) || exit 2
cd "$R" || exit 2

LIST=0
[ "${1:-}" = "--list" ] && LIST=1

names=$(grep -rhoE "[A-Za-z0-9_./-]+\.log" findings.md progress.md task_plan.md docs specs 2>/dev/null \
        | sed 's|.*/||' | sort -u)
total=0
missing=0
missing_list=""
for b in $names; do
  [ -z "$b" ] && continue
  total=$((total + 1))
  if [ -z "$(git ls-files | grep -E "/${b}$" | head -1)" ]; then
    missing=$((missing + 1))
    missing_list="$missing_list $b"
    [ "$LIST" = "1" ] && echo "MISSING $b"
  fi
done
echo "cited_logs=$total tracked=$((total - missing)) missing=$missing"
if [ "$missing" -gt 0 ]; then
  echo "note: missing names are reported, not asserted - a citation can predate the file being tracked,"
  echo "      and a current run's log is not tracked until it is committed."
fi
exit 0
