import sys, numpy as np, runpy

sys.argv = ['np_ref.py', 'base']
g = runpy.run_path('np_ref.py')
W, forward, RETURN_XN = g['W'], g['forward'], g['RETURN_XN']
RETURN_XN[0] = True

def load(path):
    out = []
    for l in open(path, encoding='utf-8', errors='replace'):
        if l.startswith('embedding '):
            out.append(np.array([float(v) for v in l.split(':', 1)[1].split()], dtype=np.float32))
    return out

ref = load('llama_emb_multi.log')
BOS = 151643
prefixes = [
    [BOS, 0, 785],                       # "! The"        -> attn has 2 positions to mix
    [BOS, 785, 6722],                    # "The capital"
    [BOS, 785, 6722, 315],               # "The capital of"
    [BOS, 785, 6722, 315, 9625],         # "The capital of France"
    [BOS, 785, 6722, 315, 9625, 374],    # "The capital of France is"
]
E = W('token_embd.weight')

def cos(a, b):
    return float(a @ b / (np.linalg.norm(a) * np.linalg.norm(b)))

for i, ids in enumerate(prefixes):
    xn = forward(ids)[0]
    k = ref[i].size
    print(f"prefix{i} len={len(ids)} dims={k}: cos={cos(xn[:k], ref[i]):+.5f}  my[:4]={np.round(xn[:4], 3)}  ref[:4]={np.round(ref[i][:4], 3)}")

full = [l for l in open('llama_emb_p5.log', encoding='utf-8', errors='replace') if l.startswith('embedding 0:')][0]
ref_full = np.array([float(v) for v in full.split('embedding 0:')[1].split()], dtype=np.float32)
if ref_full.size == 896:
    xn = forward(prefixes[-1])[0]
    print(f"last prefix vs FULL 896-dim ref: cos={cos(xn, ref_full):+.5f}")
    print(f"  my argmax={int(np.argmax(xn @ E.T))} (oracle 12095) | ref argmax={int(np.argmax(ref_full @ E.T))}")
