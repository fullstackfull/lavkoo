#!/usr/bin/env python3
"""Builds docs/gcc/tools/tracker-generator/data/flips-audit-2026-11-19.json from the journals of the audit workflows.

usage: build_audit_json.py <out file> <journal dir>...

For every criterion a verifier flagged (decision other than KEEP) the judge's final decision replaces the verifier's. A criterion whose
judge has not answered keeps the verifier's decision and is reported on stderr as PENDING.
"""
import json
import sys

out = sys.argv[1]
journals = sys.argv[2:]
verified = {}
judged = {}
units = {}
for directory in journals:
    for line in open(directory + '/journal.jsonl'):
        entry = json.loads(line)
        result = entry.get('result')
        if entry.get('type') != 'result' or not isinstance(result, dict):
            continue
        if 'decisions' in result:
            for decision in result['decisions']:
                verified[decision['id']] = dict(decision, unit=result['unit'])
                units[decision['id']] = result['unit']
        elif 'final_decision' in result:
            judged[result['id']] = result

final = {}
for criterion, decision in verified.items():
    verdict = judged.get(criterion)
    if verdict is None:
        if decision['decision'] != 'KEEP':
            sys.stderr.write('PENDING %s (verifier says %s)\n' % (criterion, decision['decision']))
        final[criterion] = {'decision': decision['decision'], 'current_state': decision['current_state'], 'reason': decision['reason'], 'replacement': decision['replacement'], 'unit': units[criterion], 'judged': False}
        continue
    final[criterion] = {
        'decision': verdict['final_decision'],
        'current_state': decision['current_state'],
        'reason': verdict['reason'],
        'replacement': verdict['replacement'] or decision['replacement'],
        'unit': units[criterion],
        'judged': True,
        'agrees_with_verifier': verdict['agrees_with_verifier'],
    }

json.dump({
    'audit': 'Independent audit of the criteria flipped on 2026-11-19: one skeptical verifier for each unit, a second agent judging every criterion the verifier would change.',
    'judged': dict(sorted(final.items())),
    'summary': {k: sum(1 for v in final.values() if v['decision'] == k) for k in sorted(set(v['decision'] for v in final.values()))},
}, open(out, 'w', encoding='utf-8'), indent=1, ensure_ascii=False)
print(json.dumps({k: sum(1 for v in final.values() if v['decision'] == k) for k in sorted(set(v['decision'] for v in final.values()))}))
