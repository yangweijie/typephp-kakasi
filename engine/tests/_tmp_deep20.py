import json

line = [l for l in open('run_f165.log', encoding='utf-8', errors='replace')
        if 'SCHEMA-DEEP20 plan' in l][0]
text = line.split('text=[', 1)[1].rsplit(']', 1)[0]
print('len', len(text))
print('head', repr(text[:60]))
print('opens', text.count('{'), 'closes', text.count('}'))
print('tail', repr(text[-30:]))
try:
    d = json.loads(text)
    print('JSON_OK')
    depth = 0
    cur = d
    while isinstance(cur, dict) and len(cur) == 1:
        cur = list(cur.values())[0]
        depth += 1
    print('depth', depth, 'leaf', cur)
except Exception as e:
    print('JSON_FAIL', e)
