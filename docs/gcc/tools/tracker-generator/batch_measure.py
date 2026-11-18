#!/usr/bin/env python3
"""Compares the criteria of the tracker published at git HEAD with the regenerated working copy: what moved MISSING->CODED, CODED->MET, MISSING->MET, and which cards changed state.
usage: batch_measure.py <worktree root>"""
import collections
import glob
import os
import re
import subprocess
import sys

root = sys.argv[1]
CARD = re.compile(r'^#### (\S+?)\. (.*)$')
CRIT = re.compile(r'^  - \[(MET|CODED|MISSING)\] (.*)$')
STATE = re.compile(r'^- \*\*State: ([^*]+)\*\*')


def parse(text):
    cards = {}
    current = None
    for line in text.splitlines():
        match = CARD.match(line)
        if match:
            current = match.group(1)
            cards[current] = {'name': match.group(2), 'state': None, 'crit': []}
            continue
        if current is None:
            continue
        state = STATE.match(line)
        if state and cards[current]['state'] is None:
            cards[current]['state'] = state.group(1).strip()
        crit = CRIT.match(line)
        if crit:
            cards[current]['crit'].append((crit.group(1), crit.group(2)))
    return cards


before, after = {}, {}
for path in sorted(glob.glob(os.path.join(root, 'docs/gcc/tracker/axis-*.md'))):
    rel = os.path.relpath(path, root)
    after.update(parse(open(path).read()))
    old = subprocess.run(['git', '-C', root, 'show', (sys.argv[2] if len(sys.argv) > 2 else 'HEAD') + ':' + rel], capture_output=True, text=True)
    before.update(parse(old.stdout))

moves = collections.Counter()
detail = collections.defaultdict(list)
added = collections.Counter()
state_changes = []
for rid in sorted(after):
    b, a = before.get(rid), after[rid]
    if b is None:
        added.update(s for s, _ in a['crit'])
        detail['new requirement'].append(rid)
        continue
    if b['state'] != a['state']:
        state_changes.append((rid, b['state'], a['state']))
    if len(b['crit']) == len(a['crit']):
        for index, ((old_status, _), (new_status, text)) in enumerate(zip(b['crit'], a['crit'])):
            if old_status != new_status:
                moves[(old_status, new_status)] += 1
                detail[(old_status, new_status)].append('%s.%d %s' % (rid, index, text[:90]))
    else:
        old_counts = collections.Counter(s for s, _ in b['crit'])
        new_counts = collections.Counter(s for s, _ in a['crit'])
        delta = {s: new_counts[s] - old_counts[s] for s in ('MET', 'CODED', 'MISSING') if new_counts[s] != old_counts[s]}
        detail['split'].append('%s: %d -> %d criteria, status change %s' % (rid, len(b['crit']), len(a['crit']), delta))
        added.update(new_counts - old_counts)

print('requirements before/after:', len(before), len(after))
print('single-criterion moves:', dict(moves))
for key in sorted(detail, key=str):
    print('\n==', key)
    for item in detail[key]:
        print('  ', item)
print('\nstatus added by splits (net):', dict(added))
print('\nstate changes:')
for item in state_changes:
    print('  ', item)
