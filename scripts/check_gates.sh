#!/bin/bash
# check_gates.sh - every gate or field name the support table cites must appear in the newest
# self-test log. The table is the public contract, so a citation it cannot resolve is a drift signal:
# either the gate was renamed, or the row was written from intent rather than from a run.
#
#   bash scripts/check_gates.sh            # summarize
#   bash scripts/check_gates.sh --list     # also print each unresolved name
#
# Report only, never a gate: a name can be added to the table before the log that prints it exists.
set -u
R=$(cd "$(dirname "$0")/.." && pwd) || exit 2
cd "$R" || exit 2

LIST=0
[ "${1:-}" = "--list" ] && LIST=1

LOG=$(ls -t engine/selftest_*.log 2>/dev/null | head -1)
if [ -z "$LOG" ]; then
  echo "NO_LOG: no engine/selftest_*.log found"
  exit 0
fi
# A row may cite a self-test gate/field OR a served case, so the haystack is the newest self-test
# plus the newest live harness logs.
HAY="$LOG $(ls engine/schema_*.log 2>/dev/null) $(ls engine/api_smoke_*.log 2>/dev/null)"

# The support table is the block between its heading and the "仍未实现" line.
awk '/约束支持表/{on=1} on{print} /仍未实现/{if(on) exit}' findings.md > /tmp/kakasi_table.txt
names=$(grep -oE '`[A-Za-z_][A-Za-z0-9_*-]*`' /tmp/kakasi_table.txt | tr -d '`' | sort -u)

total=0
missing=0
missing_list=""
for n in $names; do
  # Skip prose fragments and log names; keep gate-ish names and field names.
  case "$n" in
    *SCHEMA*|*PREFIX*|*BATCH*|*CACHE*|*ONEOF*|*REF*|*ALLOF*|*CONST*|*BOOL*|*DEEP*|*ARR*|*TOPARR*|*NEST*|*PROPS*|*LEN*|*COUNT*|*KEYWORDS*|*REQ*|*EXCL*|*LEN*|*ROUTE*|*uniq_*|*pnames_*|*dep_*|*mult_*|*addprops*|*ao_*|*multi_*|*tuple_*|*prefix_*|*items_*|*req_*|*unbounded*|*bigmult*|*frac_*|*excl_*|*mix_*|*wire_*|*route*|*obj_*|*max0*|*min_gt*|*arr_*|*flat_*|*mixed_*|*none*|*all_required*|*tool_*)
      ;;
    *) continue ;;
  esac
  total=$((total + 1))
  if ! grep -q -- "$n" $HAY; then
    missing=$((missing + 1))
    missing_list="$missing_list $n"
    [ "$LIST" = "1" ] && echo "UNRESOLVED $n"
  fi
done
echo "log=$(basename "$LOG") cited_names=$total unresolved=$missing"
[ "$missing" -gt 0 ] && echo "note: unresolved rows were written from intent, not from the log - fix the table or the gate."
exit 0
