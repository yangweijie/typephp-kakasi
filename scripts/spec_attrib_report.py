#!/usr/bin/env python3
"""spec_attrib_report.py - per-request speculation attribution (F377/F379).

Reads engine/spec_req.log, which the server appends to once per served request:

    <epoch> SPECREQ mode=<plain|greedy|tree> k=<draft len> want=<max_tokens> got=<produced>
    cache=<hit rows or -1/-2> drafts=.. accepted=.. verifies=.. alt_branch=.. max_branches=..
    drafting=<on|off> ms=<generation wall>

Why the denominator is `got`: the first version of this accounting divided by the requested
max_tokens, which compared a 48-token request against a 192-token one and claimed speculation was
eight times slower. Per produced token is the only rate that can be compared across request shapes.

Why mode and cache are both reported: F378 found the plain control group empty because unspeculated
requests with the prefix cache enabled are served by the cache branch. A comparison between
"speculative" and "plain" is only honest once both groups carry a record, and a plain request served
from the cache is not the same control as one that decoded from scratch - so `cache` is printed
rather than folded away.

Usage: python spec_attrib_report.py [logpath] [--since-line N] [--only-last N]
Exit code 0 when it printed distributions, 2 when there was nothing to report (never a verdict
about the engine in that case).
"""
import os
import statistics
import sys


def parse(path, since_line=0):
    rows = []
    for i, line in enumerate(open(path, "r", encoding="utf-8", errors="replace")):
        if i < since_line:
            continue
        parts = line.split()
        if len(parts) < 2:
            continue
        fv = {"ts": parts[0]}
        for p in parts[1:]:
            if "=" in p:
                k, v = p.split("=", 1)
                fv[k] = v
        if "mode" in fv:
            rows.append(fv)
    return rows


def num(r, k, d=0.0):
    try:
        return float(r.get(k, d))
    except (TypeError, ValueError):
        return d


def dist(vals, unit="ms"):
    if not vals:
        return "none"
    ys = sorted(vals)
    n = len(ys)
    return "%s n=%d min=%.2f med=%.2f p75=%.2f max=%.2f" % (
        unit, n, ys[0], ys[n // 2], ys[min(n - 1, int(n * 0.75))], ys[-1])


def per_token(group):
    return [num(r, "ms") / num(r, "got") for r in group if num(r, "got") > 0]


def main():
    path = sys.argv[1] if len(sys.argv) > 1 and not sys.argv[1].startswith("--") \
        else "C:/git/c/typephp-kakasi/engine/spec_req.log"
    since = 0
    if "--since-line" in sys.argv:
        since = int(sys.argv[sys.argv.index("--since-line") + 1])
    if not os.path.exists(path):
        print("NO_LOG %s" % path)
        return 2
    rows = parse(path, since)
    print("SPEC-ATTRIB rows=%d since=%d" % (len(rows), since))
    if not rows:
        print("SPEC-ATTRIB NOSUITE no records in the window")
        return 2

    total_drafts = sum(num(r, "drafts") for r in rows)
    if total_drafts == 0:
        print("SPEC-ATTRIB NO_DRAFTS the drafter proposed nothing on this workload, so there is no "
              "speculative path to compare against and no gain is reported (a prompt-lookup drafter "
              "needs a repeated tail to have candidates)")
        return 0

    groups = {}
    for r in rows:
        key = r["mode"] if r["mode"] != "plain" else ("plain/cache=%s" % r.get("cache", "?"))
        groups.setdefault(key, []).append(r)

    for key in sorted(groups):
        g = groups[key]
        d = sum(num(r, "drafts") for r in g)
        a = sum(num(r, "accepted") for r in g)
        got = sum(num(r, "got") for r in g)
        # Only drafted groups have meaningful counters. The engine resets them at the start of
        # every speculative call and never for plain ones, so a plain row repeats the last
        # speculative request s numbers - F379 read acc=38.9% off exactly that, which is
        # arithmetic on stale values. Accept rate is printed for drafted groups only.
        drafted_group = not key.startswith("plain")
        print("SPEC-ATTRIB %-14s req=%2d tokens=%4d %s  %s"
              % (key, len(g), got,
                 ("drafts=%4d accepted=%4d acc=%6.1f%%" % (d, a, 100.0 * a / d))
                 if (drafted_group and d) else "drafts=n/a (counters are per speculative call)",
                 dist(per_token(g), "ms/token")))

    # The gain is the ratio of medians of the two groups in the same window - never a ratio of
    # whole-run averages, and never across differently shaped requests.
    # Report the gain per plain variant rather than against one pooled plain median: F379 showed
    # the prefix-cache hit path and the from-scratch decode path differ by more than the effect
    # being measured, so pooling them would hide which control the ratio came from.
    plains = [(k, per_token(g)) for k, g in groups.items() if k.startswith("plain") and per_token(g)]
    drafted = [(k, per_token(g)) for k, g in groups.items()
               if not k.startswith("plain") and per_token(g)]
    if plains and drafted:
        for pk, pvs in sorted(plains):
            pm = statistics.median(pvs)
            for k, vs in sorted(drafted):
                dm = statistics.median(vs)
                print("SPEC-ATTRIB gain %s_vs_%s=%.3fx (%.2f vs %.2f ms/token)"
                      % (k, pk, pm / dm if dm else 0.0, dm, pm))
    else:
        print("SPEC-ATTRIB NOSUITE need both a plain group and a drafted group in this window")
    return 0


if __name__ == "__main__":
    sys.exit(main())
