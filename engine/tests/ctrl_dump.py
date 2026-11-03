import struct

P = "C:/Users/ASUS/.lmstudio/models/arzaan789/qwen2.5-0.5b-uncensored/qwen2.5_0.5b_uncensored.Q4_K_M.gguf"
d = open(P, "rb").read()
pos = 8
nt = struct.unpack_from("<Q", d, pos)[0]; pos += 8
nkv = struct.unpack_from("<Q", d, pos)[0]; pos += 8
FMT = {0: "B", 1: "b", 2: "H", 3: "h", 4: "I", 5: "i", 6: "f", 7: "B", 10: "Q", 11: "q", 12: "d"}


def rstr():
    global pos
    n = struct.unpack_from("<Q", d, pos)[0]; pos += 8
    s = d[pos:pos + n]; pos += n
    return s


def rv(t):
    global pos
    if t == 8:
        return rstr()
    if t == 9:
        et = struct.unpack_from("<I", d, pos)[0]; pos += 4
        n = struct.unpack_from("<Q", d, pos)[0]; pos += 8
        return [rv(et) for _ in range(n)]
    f = FMT[t]
    v = struct.unpack_from(f, d, pos)[0]; pos += struct.calcsize(f)
    return v


kv = {}
for _ in range(nkv):
    k = rstr().decode()
    t = struct.unpack_from("<I", d, pos)[0]; pos += 4
    kv[k] = rv(t)

toks = kv["tokenizer.ggml.tokens"]
types = kv["tokenizer.ggml.token_type"]
print("token_type len", len(types), "distinct", sorted(set(types)))
for i, (t, ty) in enumerate(zip(toks, types)):
    if ty != 1:
        print(f"  id={i:7d} type={ty} bytes={t!r}")
