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

step_re = re.compile(r"^([0-9.]+) STEP %s n=([0-9]+)$" % slot)
server = []
with open(trace_path, "r", encoding="utf-8", errors="replace") as f:
    for idx, line in enumerate(f):
        if idx < start_line:
            continue
        m = step_re.match(line.strip())
        if m:
            server.append((int(m.group(2)), float(m.group(1))))

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


server_ts = [t for _, t in steps]
client_ts = arrivals

server_gap = [server_ts[i] - server_ts[i - 1] for i in range(1, n)]
client_gap = [client_ts[i] - client_ts[i - 1] for i in range(1, n)]
lag = [client_ts[i] - server_ts[i] for i in range(n)]

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
}
print(json.dumps(out, indent=1))
