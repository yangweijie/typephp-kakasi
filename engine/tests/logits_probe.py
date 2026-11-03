"""Determine the true row -> position mapping of llama_logits.bin empirically.

The file stores no explicit index, so candidate mappings are tested by asking:
does this row's argmax equal the token the model should be predicting there?
Teacher forcing means a confident model should hit the next token often.
"""
import struct, sys, numpy as np

PATH = "llama_logits.bin"

raw = open(PATH, "rb").read()
assert raw[:8] == b"_logits_"
off = 8
n_ctx = struct.unpack("<I", raw[off:off + 4])[0]; off += 4
n_vocab = struct.unpack("<i", raw[off:off + 4])[0]; off += 4
n_chunk = struct.unpack("<i", raw[off:off + 4])[0]; off += 4
tn = n_ctx * n_chunk
tokens = list(struct.unpack("<%di" % tn, raw[off:off + 4 * tn])); off += 4 * tn
nv = 2 * ((n_vocab + 1) // 2) + 4
rows = (len(raw) - off) // (nv * 2)
print(f"n_ctx={n_ctx} n_vocab={n_vocab} n_chunk={n_chunk} tokens={tn} rows={rows} nv={nv} "
      f"leftover={len(raw) - off - rows * nv * 2}")

body = np.frombuffer(raw[off:off + rows * nv * 2], dtype=np.uint16).reshape(rows, nv)
meta = body[:, :4].copy().view(np.float32)
q = body[:, 4:4 + n_vocab].astype(np.float32)
scale, floor = meta[:, 0], meta[:, 1]
logp = floor[:, None] + q * scale[:, None]
argmax = logp.argmax(axis=1)

# per-row confidence: how peaked is this row? top1 - top2 in nats
top2 = np.partition(logp, -2, axis=1)[:, -2:]
conf = top2[:, 1] - top2[:, 0]
print(f"rows with floor logp (clamp) = {np.isclose(floor, logp[np.arange(rows)].max() - 0).sum()}")
print(f"median top1-top2 gap = {np.median(conf):.3f} nats; rows with gap < 0.01 = {(conf < 0.01).sum()}")

nz = sum(1 for t in tokens if t != 0)
print(f"nonzero tokens in the {tn} slots = {nz}")
print("tokens[0:20] =", tokens[:20])
print("tokens around chunk boundary:",
      tokens[n_ctx - 3:n_ctx + 3])

# Candidate mapping A: row r (chunk-major, per-chunk K predictions) -> global index.
# If llama emits, for each chunk, predictions for its positions, row order is
# chunk-major with a per-chunk count. Test: for the flat walk, does argmax[r] == tokens[r+1]?
def frac(cmp):
    n = ok = 0
    for r in range(rows):
        t = cmp(r)
        if t is None:
            continue
        n += 1
        ok += int(argmax[r] == t)
    return ok, n

for name, cmp in [
    ("argmax[r]==tokens[r+1] (flat, no chunking)", lambda r: tokens[r + 1] if r + 1 < tn else None),
    ("argmax[r]==tokens[r]   (flat)",              lambda r: tokens[r] if r < tn else None),
    ("argmax[r]==tokens[r-1]",                     lambda r: tokens[r - 1] if r >= 1 else None),
]:
    ok, n = frac(cmp)
    print(f"{name}: {ok}/{n}")

# Candidate B: chunk-major with n_ctx-1 predictions per chunk.
per = rows // n_chunk if n_chunk else 0
print(f"rows / n_chunk = {rows / max(n_chunk,1):.3f}  (n_ctx-1 = {n_ctx - 1})")
for delta in (-2, -1, 0, 1, 2):
    ok = 0
    for r in range(rows):
        c, j = divmod(r, max(per, 1)) if per else (0, r)
        g = c * n_ctx + j + delta
        if 0 <= g < tn and per and j < n_ctx:
            ok += int(argmax[r] == tokens[g])
    print(f"chunk-major per={per} offset={delta}: {ok}/{rows}")
