"""Numeric parity: engine log-probabilities vs llama.cpp's saved logits.

Usage: python logp_parity.py <engine_log.txt> [llama_logits.bin]
  where engine_log.txt contains lines:  PARITY <pos> <target_id> <logp>
  Both sides must tokenize the same text (ppl.txt / ppl_long.txt).

llama_logits.bin layout, read off tools/perplexity/perplexity.cpp rather than guessed:
  "_logits_" | u32 n_ctx | i32 n_vocab | i32 n_chunk | i32 tokens[n_ctx*n_chunk]
  then rows of nv = 2*((n_vocab+1)/2)+4 uint16:
     floats d[0]=scale, d[1]=min_log_prob, then q[n_vocab] = nearest((logit-min_logit)/scale)
     => log_prob(i) = min_log_prob + q[i]*scale

Row order (perplexity.cpp:444-645, the non-strided path):
  first = n_ctx/2; each chunk contributes block = n_ctx-1-first rows, because only the
  second half of a window is scored. Rows are emitted chunk by chunk in the order the
  seq's appear in the outer batch, so row r -> seq = r/block, k = r%block, and
  log_softmax() pairs row k with tokens[start + seq*n_ctx + first + k + 1]
  (process_logits: logits[i] is scored against tokens[i+1]).
"""
import struct, sys, numpy as np

PATH = "llama_logits.bin"
# A confident teacher-forced row must predict the token at its own position. llama.cpp
# legitimately mispredicts on hard text, so the gate is a floor, not an expectation of 1.
MIN_ARGMAX_RATE = 0.5


def load_llama(path=PATH):
    raw = open(path, "rb").read()
    assert raw[:8] == b"_logits_", "not a _logits_ file"
    off = 8
    n_ctx = struct.unpack("<I", raw[off:off + 4])[0]; off += 4
    n_vocab = struct.unpack("<i", raw[off:off + 4])[0]; off += 4
    n_chunk = struct.unpack("<i", raw[off:off + 4])[0]; off += 4
    tn = n_ctx * n_chunk
    tokens = list(struct.unpack("<%di" % tn, raw[off:off + 4 * tn])); off += 4 * tn
    nv = 2 * ((n_vocab + 1) // 2) + 4
    rows = (len(raw) - off) // (nv * 2)
    if len(raw) - off != rows * nv * 2:
        sys.exit(f"file size does not divide into whole rows: leftover {len(raw) - off - rows*nv*2}")

    body = np.frombuffer(raw[off:off + rows * nv * 2], dtype=np.uint16).reshape(rows, nv)
    meta = body[:, :4].copy().view(np.float32)
    qraw = body[:, 4:4 + n_vocab]
    q = qraw.astype(np.float32)
    logp = meta[:, 1][:, None] + q * meta[:, 0][:, None]

    first = n_ctx // 2
    block = n_ctx - 1 - first
    if rows != n_chunk * block:
        sys.exit(f"row count {rows} != n_chunk*block = {n_chunk}*{block}; this file was not "
                 f"produced by the non-strided perplexity path, refuse to guess")

    argmax = logp.argmax(axis=1)
    out, hits = {}, 0
    for r in range(rows):
        seq, k = divmod(r, block)
        pred = seq * n_ctx + first + k + 1
        if pred >= tn:
            sys.exit(f"row {r} maps past the token array (pred={pred} >= {tn})")
        if argmax[r] == tokens[pred]:
            hits += 1
        # q==0 means the target sat at the stored floor (logits clamped to max_logit-16 in
        # log_softmax), so its log-prob is a bound, not a value -> not usable for tolerance.
        clamped = int(qraw[r][tokens[pred]]) == 0
        out[pred] = (tokens[pred], float(logp[r][tokens[pred]]), int(argmax[r]), clamped)
    step = float(np.median(meta[:, 0]))
    return out, hits, rows, n_ctx, n_chunk, first, step


def load_engine(path, prefix="PARITY"):
    recs = []
    for line in open(path, encoding="utf-8", errors="replace"):
        p = line.split()
        if len(p) == 4 and p[0] == prefix:
            recs.append((int(p[1]), int(p[2]), float(p[3])))
    return recs


PREFIX = sys.argv[3] if len(sys.argv) > 3 else "PARITY"
eng = load_engine(sys.argv[1] if len(sys.argv) > 1 else "engine_parity.txt", PREFIX)
llama, hits, rows, n_ctx, n_chunk, first, step = load_llama(sys.argv[2] if len(sys.argv) > 2 else PATH)
print(f"engine records={len(eng)} prefix={PREFIX}")
print(f"llama: n_ctx={n_ctx} n_chunk={n_chunk} first={first} rows={rows}")

rate = hits / rows
print(f"alignment self-test: row argmax == token it must predict -> {hits}/{rows} ({rate:.1%})")
if rate < MIN_ARGMAX_RATE:
    sys.exit(f"REFUSING to report deltas: only {rate:.1%} of rows predict their own target token. "
             f"The row->position mapping is not established, so any delta would measure the "
             f"parser, not the engine.")

d, miss, argmax_diff, clamped_skip = [], 0, 0, 0
per_chunk = {}
for pos, tgt, lp in eng:
    rec = llama.get(pos)
    if rec is None:
        miss += 1
        continue
    tgt_ref, lp_ref, am_ref, was_clamped = rec
    if tgt != tgt_ref:
        # same position, different token id => the two tokenizers disagree
        argmax_diff += 1
        continue
    if was_clamped:
        clamped_skip += 1
        continue
    delta = lp - lp_ref
    d.append(delta)
    per_chunk.setdefault(pos // n_ctx, []).append(delta)

if not d:
    print("token-id disagreements at shared positions:", argmax_diff)
    print("sample llama keys:", sorted(llama)[:8])
    sys.exit("no position+token matched; tokenizer or sequence alignment differs")

ad = np.abs(np.array(d))
print(f"aligned={len(d)}  unmatched_pos={miss}  token_mismatch_at_pos={argmax_diff} "
      f"skipped_clamped_targets={clamped_skip}")
print(f"|delta logp| nats: mean={ad.mean():.5f} p50={np.percentile(ad,50):.5f} "
      f"p90={np.percentile(ad,90):.5f} p99={np.percentile(ad,99):.5f} max={ad.max():.5f}")
print(f"signed mean={np.mean(d):+.5f}   (0.1 nat ~ 10% relative probability error)")

# llama.cpp clears the KV cache per chunk and restarts positions at 0, so a chunk>0 row is
# scored with a truncated context while the engine sees the whole prefix. Those deltas are a
# protocol difference, not an engine error: only chunk 0 shares an identical context.
print("per n_ctx-window (chunk 0 is the only context-identical comparison):")
for c in sorted(per_chunk):
    v = np.abs(np.array(per_chunk[c]))
    print(f"  window {c}: n={len(v):3d} mean={v.mean():.5f} p50={np.median(v):.5f} max={v.max():.5f}")

c0 = np.abs(np.array(per_chunk.get(0, [])))
if not len(c0):
    sys.exit("no window-0 rows: nothing is context-identical, cannot certify")
mean0, max0 = c0.mean(), c0.max()
ok = mean0 < 0.05 and max0 < 0.5
print(f"TOLERANCE CRITERION (window 0 only, n={len(c0)}): mean<0.05 nats and max<0.5 nats -> "
      f"{'PASS' if ok else 'FAIL'} (mean={mean0:.5f}, max={max0:.5f})")
print(f"  oracle's own storage noise is {step:.6f} nats/step ((max_logit-min_logit)/65535, log_softmax()"
      f" at perplexity.cpp:93), so the residual is arithmetic, not format loss")
if not ok:
    sys.exit(1)
for w in np.argsort(ad)[-5:][::-1]:
    print(f"   worst delta={d[w]:+.4f}")

