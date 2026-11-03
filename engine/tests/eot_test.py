import sys, numpy as np, runpy

sys.argv = ['np_ref.py', 'base']
g = runpy.run_path('np_ref.py')
forward = g['forward']
g['RETURN_XN'][0] = True
ref = np.array([float(v) for v in [l for l in open('llama_emb_p5.log', encoding='utf-8', errors='replace')
                                   if l.startswith('embedding 0:')][0].split('embedding 0:')[1].split()],
                dtype=np.float32)

def cos(a, b):
    return float(a @ b / (np.linalg.norm(a) * np.linalg.norm(b)))

cases = {
    "5 ids, no EOT":            [785, 6722, 315, 9625, 374],
    "5 ids + EOT(151643)":      [785, 6722, 315, 9625, 374, 151643],
    "5 ids + EOT(151645)":      [785, 6722, 315, 9625, 374, 151645],
    "BOS + 5 ids":              [151643, 785, 6722, 315, 9625, 374],
}
for label, ids in cases.items():
    xn = forward(ids)[0]
    print(f"{label:22s} cos={cos(xn, ref):+.5f} xn[:4]={np.round(xn[:4], 3)} max|xn|={np.abs(xn).max():7.2f}")
print("ref[:4]                     ", np.round(ref[:4], 3), "max|ref|=", round(float(np.abs(ref).max()), 2))
