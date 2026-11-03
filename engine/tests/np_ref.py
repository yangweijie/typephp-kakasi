import struct, math, sys
import numpy as np

VARIANT = (sys.argv[1] if len(sys.argv) > 1 else "base")
P = r"C:\Users\ASUS\.lmstudio\models\arzaan789\qwen2.5-0.5b-uncensored\qwen2.5_0.5b_uncensored.Q4_K_M.gguf"

TLEN = {0:1,1:1,2:2,3:2,4:4,5:4,6:4,7:1,10:8,11:8,12:8}
SC = {0:'B',1:'b',2:'H',3:'h',4:'I',5:'i',6:'f',7:'?',10:'Q',11:'q',12:'d'}
DTYPE_NAME = {0:'f32',1:'f16',6:'q5_0',8:'q8_0',12:'q4_k',14:'q6_k'}

def rd_str(f):
    n = struct.unpack('<Q', f.read(8))[0]
    return f.read(n)

def load_header(f):
    assert f.read(4) == b'GGUF'
    ver = struct.unpack('<I', f.read(4))[0]
    nt = struct.unpack('<Q', f.read(8))[0]
    nkv = struct.unpack('<Q', f.read(8))[0]
    meta = {}
    def scalar(t):
        if t == 8: return rd_str(f).decode('utf-8','replace')
        return struct.unpack('<'+SC[t], f.read(TLEN[t]))[0]
    for _ in range(nkv):
        k = rd_str(f).decode()
        t = struct.unpack('<I', f.read(4))[0]
        if t == 9:
            et = struct.unpack('<I', f.read(4))[0]
            n = struct.unpack('<Q', f.read(8))[0]
            if et == 8:
                meta[k] = [rd_str(f).decode('utf-8','replace') for _ in range(n)]
            else:
                f.read(n*TLEN[et]); meta[k] = None
        else:
            meta[k] = scalar(t)
    tens = {}
    for _ in range(nt):
        name = rd_str(f).decode()
        nd = struct.unpack('<I', f.read(4))[0]
        dims = [struct.unpack('<Q', f.read(8))[0] for _ in range(nd)]
        dt = struct.unpack('<I', f.read(4))[0]
        off = struct.unpack('<Q', f.read(8))[0]
        tens[name] = (dims, dt, off)
    ds = (f.tell() + 31)//32*32
    return meta, tens, ds

def nblocks(numel, dt):
    if dt in (0,1): return numel
    if dt in (12,14): return numel//256
    return numel//32

def nbytes(numel, dt):
    if dt == 0: return numel*4
    if dt == 1: return numel*2
    bs = {6:22, 8:34, 12:144, 14:210}
    if dt in bs:
        per = 32 if dt in (6,8) else 256
        return numel//per*bs[dt]
    raise SystemExit(f"unsupported dtype {dt}")

def dequant(buf, dt, dims):
    ne1 = dims[1] if len(dims) > 1 else 1
    ne0 = dims[0]
    numel = ne0*ne1
    if dt == 0:
        return np.frombuffer(buf, dtype=np.float32, count=numel).reshape(ne1, ne0).copy()
    if dt == 8:
        a = np.frombuffer(buf, dtype=np.uint8).reshape(numel//32, 34)
        d = a[:, :2].copy().view(np.float16).astype(np.float32)
        q = a[:, 2:34].astype(np.int8).astype(np.float32)
        return (d*q).reshape(ne1, ne0)
    if dt == 6:
        a = np.frombuffer(buf, dtype=np.uint8).reshape(numel//32, 22)
        d = a[:, :2].copy().view(np.float16).astype(np.float32)
        qh = a[:, 2:6].copy().view(np.uint32)[:, :]
        qs = a[:, 6:22].astype(np.uint32)
        idx = np.arange(32)
        # upstream: byte (j % 16); low nibble -> element j, high nibble -> element j+16
        nib = np.where(idx < 16, qs[:, idx % 16] & 0xF, qs[:, idx % 16] >> 4)
        extra = ((qh[:, 0][:, None] >> idx[None, :]) & 1).astype(np.uint32) << 4
        q = (nib | extra).astype(np.float32)
        return (d*(q-16.0)).reshape(ne1, ne0)
    if dt == 12:
        a = np.frombuffer(buf, dtype=np.uint8).reshape(numel//256, 144)
        d = a[:, 0:2].copy().view(np.float16).astype(np.float32)
        mn = a[:, 2:4].copy().view(np.float16).astype(np.float32)
        out = np.empty((numel//256, 256), dtype=np.float32)
        for s in range(8):
            if s < 4:
                sc = a[:, 4+s] & 63
                m = a[:, 8+s] & 63
            else:
                sc = (a[:, s+4] & 0xF) | ((a[:, s-4] >> 6) << 4)
                m = (a[:, s+4] >> 4) | ((a[:, s] >> 6) << 4)
            d1 = (d[:, 0]*sc.astype(np.float32))[:, None]
            m1 = (mn[:, 0]*m.astype(np.float32))[:, None]
            qb = a[:, 16+(s//2)*32 : 16+(s//2)*32+32].astype(np.uint8)
            nib = (qb >> 4) if (s & 1) else (qb & 0xF)
            out[:, s*32:(s+1)*32] = d1*nib.astype(np.float32) - m1
        return out.reshape(ne1, ne0)
    if dt == 14:
        a = np.frombuffer(buf, dtype=np.uint8).reshape(numel//256, 210)
        d = a[:, 208:210].copy().view(np.float16).astype(np.float32)
        ql = a[:, 0:128].astype(np.uint32)
        qh = a[:, 128:192].astype(np.uint32)
        sc = a[:, 192:208].astype(np.int8).astype(np.float32)
        out = np.empty((numel//256, 256), dtype=np.float32)
        for c in range(2):
            for j in range(4):
                for l in range(32):
                    qh_l = qh[:, c*32+l]
                    if j == 0: nib = ql[:, c*64+l] & 0xF; two = (qh_l >> 0) & 3
                    elif j == 1: nib = ql[:, c*64+32+l] & 0xF; two = (qh_l >> 2) & 3
                    elif j == 2: nib = ql[:, c*64+l] >> 4; two = (qh_l >> 4) & 3
                    else: nib = ql[:, c*64+32+l] >> 4; two = (qh_l >> 6) & 3
                    q = (nib | (two << 4)).astype(np.float32) - 32.0
                    si = c*8 + j*2 + (l >> 4)
                    out[:, c*128 + j*32 + l] = (d[:, 0]*sc[:, si]*q)
        return out.reshape(ne1, ne0)
    raise SystemExit(f"no dequant for {dt}")

meta, tens, ds = load_header(open(P,'rb'))
D = meta['qwen2.embedding_length']; L = meta['qwen2.block_count']
NH = meta['qwen2.attention.head_count']; NKV = meta['qwen2.attention.head_count_kv']
FFN = meta['qwen2.feed_forward_length']; THETA = meta['qwen2.rope.freq_base']
EPS = meta['qwen2.attention.layer_norm_rms_epsilon']
HD = D//NH; DKV = NKV*HD
print(f"D={D} L={L} NH={NH} NKV={NKV} FFN={FFN} HD={HD} theta={THETA} eps={EPS}")

fh = open(P,'rb')
_cache = {}
def W(name):
    if name not in _cache:
        dims, dt, off = tens[name]
        fh.seek(ds+off); n = nbytes(dims[0]*(dims[1] if len(dims)>1 else 1), dt)
        _cache[name] = dequant(fh.read(n), dt, dims)
        if len(_cache) > 16: _cache.pop(next(iter(_cache)))
    return _cache[name]

def rms(x, w):
    # VARIANT controls two known spec forks: norm scaling (w vs 1+w) and SwiGLU branch order.
    g = 1.0 + w if VARIANT.startswith("oneplus") else w
    return (x / np.sqrt((x*x).mean(axis=-1, keepdims=True) + EPS)) * g

def rope(x, T, nheads):
    # x: [T, nheads, HD]
    inv = np.array([THETA ** (-(2.0*j)/HD) for j in range(HD//2)], dtype=np.float64)
    pos = np.arange(T)[:, None]
    ang = pos*inv[None, :]
    c = np.cos(ang).astype(np.float32); s = np.sin(ang).astype(np.float32)
    x1 = x[:, :, :HD//2].copy(); x2 = x[:, :, HD//2:].copy()
    out = np.empty_like(x)
    out[:, :, :HD//2] = x1*c[:, None, :] - x2*s[:, None, :]
    out[:, :, HD//2:] = x2*c[:, None, :] + x1*s[:, None, :]
    return out

ONLY_SELF = "selfonly"
RETURN_XN = [False]

def forward(ids, trace=False):
    T = len(ids)
    x = W('token_embd.weight')[np.array(ids)].astype(np.float32)
    if trace: print(f"  embd max|x| {np.abs(x).max():.6f}")
    for l in range(L):
        p = f"blk.{l}."
        h = rms(x, W(p+'attn_norm.weight'))
        q = (h @ W(p+'attn_q.weight').T) + W(p+'attn_q.bias').reshape(1,-1)
        k = (h @ W(p+'attn_k.weight').T) + W(p+'attn_k.bias').reshape(1,-1)
        v = (h @ W(p+'attn_v.weight').T) + W(p+'attn_v.bias').reshape(1,-1)
        q = q.reshape(T, NH, HD); k = k.reshape(T, NKV, HD); v = v.reshape(T, NKV, HD)
        if "nope" not in VARIANT:
            q = rope(q, T, NH); k = rope(k, T, NKV)
        if "noatt" in VARIANT:
            ao = np.zeros((T, NH, HD), dtype=np.float32)
        else:
            ao = np.zeros((T, NH, HD), dtype=np.float32)
        for hq in range(NH):
            kv = hq*NKV//NH
            if ONLY_SELF in VARIANT:
                ao[:, hq, :] = v[:, kv, :]
                continue
            sc = (q[:, hq, :] @ k[:, kv, :].T) / math.sqrt(HD)
            for b in range(T):
                row = sc[:b+1, b]
                row = row - row.max()
                e = np.exp(row); w = e/e.sum()
                ao[b, hq, :] = w @ v[:b+1, kv, :]
        x = x + (ao.reshape(T, D) @ W(p+'attn_output.weight').T)
        h2 = rms(x, W(p+'ffn_norm.weight'))
        g = h2 @ W(p+'ffn_gate.weight').T
        u = h2 @ W(p+'ffn_up.weight').T
        if VARIANT.endswith("swap"):
            g, u = u, g
        x = x + ((g/(1+np.exp(-g)))*u) @ W(p+'ffn_down.weight').T
        if trace:
            print(f"  layer {l:2d} max|x| {np.abs(x).max():.4f}")
    xn = rms(x[-1:], W('output_norm.weight'))
    if RETURN_XN[0]:
        return xn
    return xn @ W('token_embd.weight').T


if VARIANT == "xn":
    import re
    line = [l for l in open('llama_emb_ref.log', encoding='utf-8', errors='replace') if l.startswith('embedding 0:')][0]
    ref = np.array([float(v) for v in line.split('embedding 0:')[1].split()], dtype=np.float32)
    print("ref dim", ref.size, "max|ref|", np.abs(ref).max())
    RETURN_XN[0] = True
    mine = forward([785])
    print("mine dim", mine.size, "max|mine|", np.abs(mine).max())
    cos = float((mine[0] @ ref) / (np.linalg.norm(mine[0]) * np.linalg.norm(ref)))
    print(f"COSINE mine_xn vs llama_xn = {cos:.6f}")
    print("first6 mine:", np.round(mine[0][:6], 4), " llama:", np.round(ref[:6], 4))
    E = W('token_embd.weight')
    for name, v in (("llama_xn", ref), ("mine_xn", mine[0])):
        lg = v @ E.T
        print(f"  {name}: argmax={int(np.argmax(lg))} (oracle expects 40) logit@40={lg[40]:.3f}")
