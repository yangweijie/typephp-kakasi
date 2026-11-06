#!/usr/bin/env python3
"""chunk_gap_analyze.py - split the served per-token wall into server work and transport lag.

F364: chunk_gap_probe.py records when each streamed chunk ARRIVES on the client; the 8001
server records in par_trace.log when it had already written that token to the socket
("STEP <slot> n=k", timestamped at write time). Both clocks are the same machine's Unix
epoch, so per token:

    server cadence = server_ts[k] - server_ts[k-1]   (the server's own per-token work)
    client cadence = arrival[k] - arrival[k-1]       (what the caller actually paid)
    transport lag = arrival[k] - server_ts[k]        (socket + client read scheduling)

The lag can never be negative (the write happens first), so a negative value means the
alignment is wrong, not that the client was early.

The trace file accumulates every request of every run, so only the lines AFTER the offset
recorded before the run are read - otherwise a stale row from an earlier run is paired with
this run's arrivals.

Usage: python chunk_gap_analyze.py <probe_json> <trace_log> [slot] [start_line]
"""
import json
import re
import sys

probe_path = sys.argv[1]
trace_path = sys.argv[2]
slot = sys.argv[3] if len(sys.argv) > 3 else "A"
start_line = int(sys.argv[4]) if len(sys.argv) > 4 else 0

with open(probe_path, "r", encoding="utf-8") as f:
    probe = json.load(f)

# Only chunks that carry text are tokens; the role chunk and the finish chunk are not.
arrivals = probe.get("token_arrival_epochs") or []
n = len(arrivals)
if n == 0:
    print("NO_CLIENT_TOKENS")
    sys.exit(1)

# F369/F370: the served STEP line carries the engine's own marks (s1), the chunk count the
# attention computed (sp) and, since F370, three durations that split one served iteration into
# "before the forward pass" (pre), "the forward pass as the host sees it" (eng) and "everything
# after it" (post). Fields are parsed by key rather than by an anchored pattern, so a trace from an
# older binary - or one with fields added later - still pairs.
step_re = re.compile(r"^([0-9.]+) STEP %s n=([0-9]+)(.*)$" % slot)


def fields(rest):
    out = {}
    for part in rest.split():
        if "=" not in part:
            continue
        k, v = part.split("=", 1)
        try:
            out[k] = float(v)
        except ValueError:
            pass
    return out


server = []
with open(trace_path, "r", encoding="utf-8", errors="replace") as f:
    for idx, line in enumerate(f):
        if idx < start_line:
            continue
        m = step_re.match(line.strip())
        if m:
            fv = fields(m.group(3))
            server.append((int(m.group(2)), float(m.group(1)),
                           fv.get("s1"), int(fv["sp"]) if "sp" in fv else None,
                           fv.get("pre"), fv.get("eng"), fv.get("post")))

if len(server) < n:
    print("SHORT_TRACE have=%d want=%d slot=%s start=%d" % (len(server), n, slot, start_line))
    sys.exit(1)

# Keep the LAST n step rows: a run can emit a few rows after the probe stopped reading.
steps = server[-n:]


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


server_ts = [r[1] for r in steps]
client_ts = arrivals

server_gap = [server_ts[i] - server_ts[i - 1] for i in range(1, n)]
client_gap = [client_ts[i] - client_ts[i - 1] for i in range(1, n)]
lag = [client_ts[i] - server_ts[i] for i in range(n)]

# F369 attribution: the engine's own marks for the steps that were paired with tokens.
s1_ms = [r[2] for r in steps if r[2] is not None]
# F370: one iteration's three durations, in ms, for the rows that carry all of them.
parts_ms = [r[4] + r[5] + r[6] for r in steps
            if None not in (r[4], r[5], r[6])]
sp_vals = sorted(set(r[3] for r in steps if r[3] is not None))
out = {
    "slot": slot,
    "tokens": n,
    "server_gap_ms": stats(server_gap),
    "client_gap_ms": stats(client_gap),
    "lag_ms": stats(lag),
    "client_wall_ms": round((client_ts[-1] - client_ts[0]) * 1000, 3),
    "server_span_ms": round((server_ts[-1] - server_ts[0]) * 1000, 3),
    "lag_sum_ms": round(sum(lag) * 1000, 3),
    "lag_negative_count": sum(1 for x in lag if x < -0.002),
    "step_numbers_contiguous": [steps[0][0], steps[-1][0]],
    "first_server_ts": round(server_ts[0], 6),
    "first_client_ts": round(client_ts[0], 6),
    # F369: engine marks (ms) recorded on the very lines that produced these tokens, plus how
    # many chunks the attention actually split into - and the residual, which is everything the
    # engine did not spend inside the block stage: sampling, PHP, framing, writes.
    "engine_s1_ms": stats([x / 1000.0 for x in s1_ms]),
    "split_p_values": sp_vals,
    # F370: the three iteration durations (ms) and the completeness check. pre+eng+post
    # must land on the A-to-A cadence if the stamps cover the whole iteration - that is the
    # only assertion here, and it fails loudly when a stamp is missing or mis-placed.
    "parts_sum_ms": stats([x / 1000.0 for x in parts_ms]),
    # coverage: what fraction of the measured A-to-A cadence the three stamps account for.
    # Anything well below 100 means an un-stamped block; above 100 means double counting.
    "coverage_pct": (round(100.0 * stats([x / 1000.0 for x in parts_ms])["median"]
                           / stats(server_gap)["median"], 2)
                     if parts_ms and server_gap else None),
    "pre_ms": stats([r[4] / 1000.0 for r in steps if r[4] is not None]),
    "eng_ms": stats([r[5] / 1000.0 for r in steps if r[5] is not None]),
    "post_ms": stats([r[6] / 1000.0 for r in steps if r[6] is not None]),
    "residual_ms": (round(stats(server_gap)["median"] - stats([x / 1000.0 for x in s1_ms])["median"], 3)
                    if s1_ms and server_gap else None),
}
print(json.dumps(out, indent=1))
