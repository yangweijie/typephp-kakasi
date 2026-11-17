#!/bin/bash
# check_gates.sh - every gate or field name the support table cites must appear in the newest
# self-test log. The table is the public contract, so a citation it cannot resolve is a drift signal:
# either the gate was renamed, or the row was written from intent rather than from a run.
#
#   bash scripts/check_gates.sh            # summarize
#   bash scripts/check_gates.sh --list     # also print each unresolved name
#
# Report only, never a gate: a name can be added to the table before the log that prints it exists.
# EXCEPT the redness check below - RUN_EXIT does not reflect self-test assertions (F580 found four
# FAIL lines committed under "0 FAIL" claims), so a non-zero FAIL count in the newest log is a hard
# failure here.
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
# Completeness comes first: a self-test that aborts mid-file contains no FAIL text at all, so
# judging redness alone certifies a truncated log as green. That is how F644 got committed on
# selftest_f644g.log (741 lines, RUN_EXIT=-1, no PROFILES section, FAIL count 0).
if ! bash "$R/scripts/check_selftest_log.sh" --ignore-fails --sections "PROFILES PARAM-GUARDS" "$LOG" > /tmp/kakasi_cpl.txt 2>&1; then
  echo "SELFTEST_INCOMPLETE log=$(basename "$LOG")"
  grep -E '^(NOT_EVIDENCE|EVIDENCE|floor_lines)' /tmp/kakasi_cpl.txt | head -3
  exit 1
fi
FAILS=$(grep -c 'FAIL' "$LOG")
if [ "$FAILS" -gt 0 ]; then
  echo "SELFTEST_RED log=$(basename "$LOG") fail_lines=$FAILS"
  grep -n 'FAIL' "$LOG" | head -5
  exit 1
fi
# Harness verdicts are gated exactly like the self-test: newest log per family, completeness first
# (a VERDICT line must exist), then redness. Before this check_gates never read a harness log, so
# the bounds harness stayed "VERDICT: FAIL" from F638 through F645 while every commit message
# claimed four harnesses green - the scripts themselves always exit 0, so nothing but this line
# could notice.
for fam in required count pattern bounds; do
  HLOG=$(ls -t "engine/schema_${fam}_"*.log 2>/dev/null | head -1)
  if [ -z "$HLOG" ]; then
    echo "HARNESS_NO_LOG fam=$fam"
    exit 1
  fi
  if ! grep -q 'VERDICT: ' "$HLOG"; then
    echo "HARNESS_INCOMPLETE fam=$fam log=$(basename "$HLOG")"
    exit 1
  fi
  if ! grep -q 'VERDICT: PASS' "$HLOG" || ! grep -q 'CHECK_EXIT=0' "$HLOG"; then
    echo "HARNESS_RED fam=$fam log=$(basename "$HLOG")"
    grep -m 3 'VERDICT:\|CHECK_EXIT' "$HLOG"
    exit 1
  fi
done
# F658b: the cooperative server's results are gates too. It shares the writers, so its smoke says
# whether a shape the engine cannot honour is refused there as well - until F658b PAR_EXIT=1 was
# printed and ignored and gate_live still exited 0. Newest logs only, same rule as the harnesses.
PLOG=$(ls -t engine/par_smoke_*.log 2>/dev/null | head -1)
if [ -z "$PLOG" ] || ! grep -q '^PAR SMOKE: PASS' "$PLOG" || ! grep -q 'PAR_EXIT=0' "$PLOG"; then
  echo "PAR_RED log=$(basename "${PLOG:-none}")"
  [ -n "$PLOG" ] && grep -m 3 'FAIL\|PAR SMOKE\|PAR_EXIT' "$PLOG"
  exit 1
fi
BLBLOG=$(ls -t engine/body_limit_*.log 2>/dev/null | head -1)
if [ -z "$BLBLOG" ] || ! grep -q '^BODY-LIMIT: PASS' "$BLBLOG"; then
  echo "BODYL_RED log=$(basename "${BLBLOG:-none}")"
  [ -n "$BLBLOG" ] && grep -m 4 'BODY-LIMIT' "$BLBLOG"
  exit 1
fi
ABLOG=$(ls -t engine/par_abandon_*.log 2>/dev/null | head -1)
if [ -z "$ABLOG" ] || ! grep -q '^PAR-ABANDON: PASS' "$ABLOG"; then
  echo "PARAB_RED log=$(basename "${ABLOG:-none}")"
  [ -n "$ABLOG" ] && grep -m 3 'PAR-ABANDON' "$ABLOG"
  exit 1
fi
FLOG=$(ls -t engine/fairness_*.log 2>/dev/null | head -1)
if [ -z "$FLOG" ] || ! grep -q 'FAIRNESS: PASS' "$FLOG"; then
  echo "FAIR_RED log=$(basename "${FLOG:-none}")"
  exit 1
fi
TLOG=$(ls -t engine/throughput_*.log 2>/dev/null | head -1)
if [ -z "$TLOG" ] || ! grep -q 'THROUGHPUT: PASS' "$TLOG"; then
  echo "THRU_RED log=$(basename "${TLOG:-none}")"
  exit 1
fi
# F660: the SSD prefix cache must survive a restart with identical output.
CPLOG=$(ls -t engine/cache_persist_*.log 2>/dev/null | head -1)
if [ -z "$CPLOG" ] || ! grep -q '^CACHE-PERSIST: PASS' "$CPLOG"; then
  echo "CACHEP_RED log=$(basename "${CPLOG:-none}")"
  [ -n "$CPLOG" ] && grep -m 4 'CACHE-PERSIST' "$CPLOG"
  exit 1
fi
# F659: both servers must answer the same schema matrix identically.
for fam in anth resp; do
  TLOG2=$(ls -t "engine/schema_parity_tcompare_${fam}_"*.log 2>/dev/null | head -1)
  if [ -z "$TLOG2" ] || ! grep -q '^PARITY: PASS' "$TLOG2"; then
    echo "PARITY_TRANSPORT_RED fam=$fam log=$(basename "${TLOG2:-none}")"
    [ -n "$TLOG2" ] && grep -m 5 'PARITY' "$TLOG2"
    exit 1
  fi
done
SPLOG=$(ls -t engine/schema_parity_compare_*.log 2>/dev/null | head -1)
if [ -z "$SPLOG" ] || ! grep -q '^PARITY: PASS' "$SPLOG"; then
  echo "PARITY_RED log=$(basename "${SPLOG:-none}")"
  [ -n "$SPLOG" ] && grep -m 5 'PARITY' "$SPLOG"
  exit 1
fi

# A row may cite a self-test gate/field OR a served case, so the haystack is the newest self-test
# plus the newest live harness logs. Newest only, deliberately: globbing every historical log
# (~1400 files, 43KB of paths and climbing toward the process argument limit) lets a citation be
# satisfied by a log from weeks ago, which certifies gate names the current build no longer prints.
HAY="$LOG"
# Quoted on purpose: an unquoted word list here is pathname-expanded when the loop is set up, so
# `pat` would iterate over every individual log file and the haystack would stay the full history.
for pat in 'engine/api_smoke_*.log' 'engine/api_smoke_pretty_*.log' \
           'engine/schema_required_*.log' 'engine/schema_count_*.log' \
           'engine/schema_pattern_*.log' 'engine/schema_bounds_*.log' \
           'engine/par_smoke_*.log'; do
  # api_smoke_*.log also matches the pretty logs, so without the filter both patterns can resolve
  # to the same newest file and the compact run silently leaves the haystack.
  if [ "$pat" = 'engine/api_smoke_*.log' ]; then
    f=$(ls -t engine/api_smoke_*.log 2>/dev/null | grep -v pretty | head -1)
  else
    f=$(ls -t $pat 2>/dev/null | head -1)
  fi
  [ -n "$f" ] && HAY="$HAY $f"
done

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
