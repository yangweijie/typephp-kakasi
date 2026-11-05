#!/usr/bin/env python3
"""chunk_gap_ab.py - decompose the 8001 TWO-stream iteration from the server's own trace.

F364 (addendum): with one stream, the client-side lag was ~0 and the whole per-token cost
was server-side. The two-stream case is the one the scaling numbers are about, and it can be
decomposed WITHOUT a client probe, because the server traces both slots in file order and the
per-iteration order is fixed by the loop:

    [engine step] -> slot A: text/esc/write -> STEP A n=k
                  -> slot B: text/esc/write -> STEP B n=k

So, reading the trace in FILE order, each adjacent pair of rows is one segment:
    B(k) -> A(k+1)  =  the engine step plus slot A's write
    A(k) -> B(k)    =  slot B's write alone

If the CLIENT were the bottleneck, slot A's fwrite would block waiting for a reader and the
engine+writeA segment would be much larger than the writeB segment. If the client is not the
bottleneck, both segments are server-side work of comparable size.

The trace holds every run's rows, and throughput_test.py runs a SERIAL stream before the
CONCURRENT one, so the phase boundary shows up as a multi-second segment; those are dropped
and only the last (concurrent) phase is kept.

Usage: python chunk_gap_ab.py <trace_log> [start_line] [want_pairs]
"""
import json
import re
import sys

trace_path = sys.argv[1]
start_line = int(sys.argv[2]) if len(sys.argv) > 2 else 0
want = int(sys.argv[3]) if len(sys.argv) > 3 else 0

step_re = re.compile(r"^([0-9.]+) STEP ([AB]) n=([0-9]+)$")
rows = []
with open(trace_path, "r", encoding="utf-8", errors="replace") as f:
    for idx, line in enumerate(f):
        if idx < start_line:
            continue
        m = step_re.match(line.strip())
        if m:
            rows.append((float(m.group(1)), m.group(2), int(m.group(3))))

if len(rows) < 4:
    print("NO_ROWS have=%d" % len(rows))
    sys.exit(1)

seg_engine_write_a = []
seg_write_b = []
iter_cadence = []
dropped = 0
for i in range(len(rows) - 1):
    t0, s0, n0 = rows[i]
    t1, s1, n1 = rows[i + 1]
    d = t1 - t0
    if d <= 0 or d > 2.0:            # phase boundary or a clock glitch
        dropped += 1
        continue
    if s0 == "B" and s1 == "A":
        seg_engine_write_a.append(d)
    elif s0 == "A" and s1 == "B":
        seg_write_b.append(d)
    else:
        dropped += 1
        continue

if want:
    seg_engine_write_a = seg_engine_write_a[-want:]
    seg_write_b = seg_write_b[-want:]
    iter_cadence = [seg_engine_write_a[k] + seg_write_b[k] for k in range(min(len(seg_engine_write_a), len(seg_write_b)))]


def stats(xs):
    if not xs:
        return {}
    ys = sorted(xs)
    m = len(ys)
    return {
        "n": m,
        "min": round(ys[0] * 1000, 3),
        "median": round(ys[m // 2] * 1000, 3),
        "mean": round(sum(ys) / m * 1000, 3),
        "p90": round(ys[min(m - 1, int(m * 0.9))] * 1000, 3),
        "max": round(ys[-1] * 1000, 3),
    }


out = {
    "rows": len(rows),
    "dropped_segments": dropped,
    "seg_engine_plus_writeA_ms": stats(seg_engine_write_a),
    "seg_writeB_ms": stats(seg_write_b),
    "iteration_cadence_ms": stats(iter_cadence),
}
print(json.dumps(out, indent=1))
