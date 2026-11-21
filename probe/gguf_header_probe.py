# -*- coding: utf-8 -*-
# F689: minimal GGUF header reader (data only - never executes anything from the model dirs).
# Prints the KV pairs the engine's compatibility decision needs. Large arrays are sampled and
# skipped by byte arithmetic, never materialized.
import struct
import sys

sys.stdout.reconfigure(encoding='utf-8', errors='replace')

ESIZE = {0: 1, 1: 1, 2: 2, 3: 2, 4: 4, 5: 4, 6: 4, 7: 1, 10: 8, 11: 8, 12: 8}

def read_str(f):
    (n,) = struct.unpack('<Q', f.read(8))
    return f.read(n).decode('utf-8', 'replace')

def skip_str(f):
    (n,) = struct.unpack('<Q', f.read(8))
    f.seek(n, 1)

def read_val(f, t, keep=3):
    if t in ESIZE:
        raw = f.read(ESIZE[t])
        fmt = {0: '<B', 1: '<b', 2: '<H', 3: '<h', 4: '<I', 5: '<i', 6: '<f', 7: '<B', 10: '<Q', 11: '<q', 12: '<d'}[t]
        return struct.unpack(fmt, raw)[0]
    if t == 8:
        return read_str(f)
    if t == 9:
        (etype, n) = struct.unpack('<IQ', f.read(12))
        if etype == 8:
            head = [read_str(f) for _ in range(min(n, keep))]
            for _ in range(n - len(head)):
                skip_str(f)
            return '<%d strings>' % n, head
        if etype in ESIZE:
            head = list(struct.unpack('<' + {0: 'B', 1: 'b', 2: 'H', 3: 'h', 4: 'I', 5: 'i', 6: 'f', 7: 'B', 10: 'Q', 11: 'q', 12: 'd'}[etype] * min(n, keep),
                                      f.read(ESIZE[etype] * min(n, keep))))
            f.seek(ESIZE[etype] * (n - len(head)), 1)
            return '<%d arr t%d>' % (n, etype), head
        if etype == 9:
            raise ValueError('nested arrays not needed')
    raise ValueError('bad gguf kv type %d' % t)

def header(path):
    out = []
    with open(path, 'rb') as f:
        magic = f.read(4)
        assert magic == b'GGUF', 'not GGUF: ' + path
        (ver, n_tensors, n_kv) = struct.unpack('<IQQ', f.read(20))
        out.append('version=%d tensors=%d kv=%d' % (ver, n_tensors, n_kv))
        for _ in range(n_kv):
            k = read_str(f)
            (t,) = struct.unpack('<I', f.read(4))
            v = read_val(f, t)
            out.append('  %s = %s' % (k, v))
    return out

for p in sys.argv[1:]:
    print('==', p)
    try:
        for line in header(p):
            print(line)
    except Exception as e:
        print('ERR', repr(e))
