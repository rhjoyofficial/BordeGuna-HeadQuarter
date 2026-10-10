"""Merge-conflict helper for parallel stream branches (10-11 Oct 2026).

Run from the repo root with paths relative to apps/hq. For a *.json dictionary it unions both sides and drops
duplicate keys, printing any key the two sides translate differently (choose one word by hand). For any other
file it keeps BOTH sides of every hunk in order, which is right only for pure list additions (permission lists,
allow-lists, screen registries). Read the hunks first: route groups and test pins need a hand merge.
"""
import re, json, sys
import os
root = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'apps', 'hq')
KEY = re.compile(r'^\s*"((?:[^"\\]|\\.)*)"\s*:\s*("(?:[^"\\]|\\.)*")', re.M)
CONF = re.compile(r'<<<<<<< [^\n]*\n(.*?)=======\n(.*?)>>>>>>> [^\n]*\n', re.S)

def resolve_json(path):
    s = open(path, encoding='utf-8').read()
    clashes = []
    def fix(m):
        head, theirs = m.group(1), m.group(2)
        hk = dict(KEY.findall(head))
        kept = []
        for line in theirs.splitlines(True):
            k = KEY.match(line)
            if k and k.group(1) in hk:
                if hk[k.group(1)] != k.group(2):
                    clashes.append(k.group(1))
                continue
            kept.append(line)
        h = head.rstrip('\n')
        if h and not h.endswith(',') and kept:
            h += ','
        body = ''.join(kept).rstrip('\n')
        return h + ('\n' + body if body else '') + '\n'
    s = CONF.sub(fix, s)
    # a trailing comma before the closing brace is invalid JSON
    s = re.sub(r',(\s*\n\s*})\s*$', r'\1\n', s)
    json.loads(s)
    open(path, 'w', encoding='utf-8', newline='\n').write(s)
    return clashes

def resolve_union(path):
    s = open(path, encoding='utf-8').read()
    s = CONF.sub(lambda m: m.group(1) + m.group(2), s)
    open(path, 'w', encoding='utf-8', newline='\n').write(s)
    return '<<<<<<<' not in s

for rel in sys.argv[1:]:
    p = os.path.join(root, *rel.split('/'))
    if rel.endswith('.json'):
        c = resolve_json(p)
        print(rel, 'json ok, keys', len(json.load(open(p, encoding='utf-8'))), 'CLASHES', c)
    else:
        print(rel, 'union ok', resolve_union(p))
