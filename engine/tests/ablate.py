import sys, numpy as np, runpy

BOS = 151643
prefix = [BOS, 785, 6722]          # "! The"-style short case -> "The capital" (3 tokens)

def load(path):
    out = []
    for l in open(path, encoding='utf-8', errors='replace'):
        if l.startswith('embedding '):
            out.append(np.array([float(v) for v in l.split(':', 1)[1].split()], dtype=np.float32))
    return out

ref = load('llama_emb_multi.log')[1]

def cos(a, b):
    return float(a @ b / (np.linalg.norm(a) * np.linalg.norm(b)))

for variant in ("base", "nope", "selfonly"):
    sys.argv = ['np_ref.py', variant]
    g = runpy.run_path('np_ref.py')
    g['RETURN_XN'][0] = True
    xn = g['forward'](prefix)[0]
    print(f"{variant:9s} cos={cos(xn[:ref.size], ref):+.5f} max|xn|={np.abs(xn).max():8.3f}")
