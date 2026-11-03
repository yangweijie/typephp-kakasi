import sys, numpy as np, runpy

sys.argv = ['np_ref.py', 'base']
g = runpy.run_path('np_ref.py')
W, rms, forward, RETURN_XN = g['W'], g['rms'], g['forward'], g['RETURN_XN']
RETURN_XN[0] = True

def load_ref(path):
    line = [l for l in open(path, encoding='utf-8', errors='replace') if l.startswith('embedding 0:')][0]
    return np.array([float(v) for v in line.split('embedding 0:')[1].split()], dtype=np.float32)

ref = load_ref('llama_emb_p5.log')
BOS, base_ids = 151643, [785, 6722, 315, 9625, 374]

def cos(a, b):
    return float(a @ b / (np.linalg.norm(a) * np.linalg.norm(b)))

for label, ids in (("5 tokens", base_ids), ("BOS+5 tokens", [BOS] + base_ids)):
    xn = forward(ids)[0]
    print(f"{label:16s} cos={cos(xn, ref):+.6f} max|xn|={np.abs(xn).max():8.3f} (ref max|xn|={np.abs(ref).max():.3f})")
