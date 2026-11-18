#!/usr/bin/env python3
"""Builds docs/gcc/MASTER_IMPLEMENTATION_TRACKER.md (and the per-axis detail files) from the assessor and verifier results of the
master-tracker workflows. Reads only: results from the workflow journals, git blobs for the evidence check. Writes to the output directory.

usage: gen_tracker.py <out-dir> [--check-only]
"""
import collections
import json
import os
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__)) + '/'
# The assessor and verifier results (journals), the pull request lists and context.json are in data/ (unpack data.tar.xz next to this file); GCC_TRACKER_DATA overrides the place.
DATA = os.environ.get('GCC_TRACKER_DATA', HERE + 'data/')
WF_ROOT = DATA + 'journals/'
MASTER = DATA
# The three clones that the evidence check reads with git show; GCC_REPOS_ROOT is the directory that holds them.
REPOS_ROOT = os.environ.get('GCC_REPOS_ROOT', '/home/user')
WORKFLOWS = ['wf_1fcc4c26-0ce', 'wf_c4f67fa8-8d5', 'wf_b064af3f-ea7', 'wf_7e79e108-ec3', 'wf_84c1bffe-007', 'wf_3f7327e8-5df', 'wf_b30d2556-ef8']
REPOS = {name: REPOS_ROOT + '/' + name for name in ('lavkoo', 'lavkooapp', 'lavkooappseller')}

STATES = ['NOT STARTED', 'IN PROGRESS', 'IMPLEMENTED IN DRAFT', 'INTEGRATED AND TESTED', 'READY FOR OWNER REVIEW', 'MERGED', 'DEPLOYED AND VERIFIED']
GATE = {s: i for i, s in enumerate(['NOT STARTED', 'IN PROGRESS', 'IMPLEMENTED IN DRAFT', 'INTEGRATED AND TESTED', 'READY FOR OWNER REVIEW', 'MERGED', 'DEPLOYED AND VERIFIED'])}
# release readiness: how far a requirement has travelled along the gates, 0 to 5 (in progress counts as not yet in a draft)
POINTS = {'NOT STARTED': 0, 'IN PROGRESS': 0, 'IMPLEMENTED IN DRAFT': 1, 'INTEGRATED AND TESTED': 2, 'READY FOR OWNER REVIEW': 3, 'MERGED': 4, 'DEPLOYED AND VERIFIED': 5}

AXES = {
    'A': 'Six-country base and customer identity',
    'B': 'Catalogue and products',
    'C': 'Inventory and fulfillment',
    'D': 'Orders, checkout and payments',
    'E': 'Wallets, loyalty and financial records',
    'F': 'Themes and brand',
    'G': 'Admin panel and staff permissions',
    'H': 'Shipping and integrations',
    'I': 'Marketing, policies and operations',
    'J': 'Flutter customer app',
    'K': 'Flutter seller app',
    'L': 'GCC-20 Super Admin',
    'M': 'Security, tests and deployment',
}
UNIT_AXIS = {'A1': 'A', 'A2': 'A', 'B': 'B', 'C': 'C', 'D1': 'D', 'D2': 'D', 'E': 'E', 'F': 'F', 'G': 'G', 'H1': 'H', 'H2': 'H', 'I1': 'I', 'I2': 'I', 'J': 'J', 'K': 'K', 'L': 'L', 'M1': 'M', 'M2': 'M', 'M3': 'M'}


def load_results(workflows):
    """unit -> {'assess': dict, 'verify': dict}, from the journals (the last result of a label wins)."""
    units = collections.defaultdict(dict)
    for wf in workflows:
        path = WF_ROOT + wf + '/journal.jsonl'
        if not os.path.exists(path):
            continue
        labels = {}
        for line in open(path):
            try:
                row = json.loads(line)
            except ValueError:
                continue
            if row.get('type') == 'started':
                labels[row['key']] = row.get('label', '')
            elif row.get('type') == 'result':
                label = labels.get(row['key'], '')
                result = row.get('result')
                if isinstance(result, str):
                    try:
                        result = json.loads(result)
                    except ValueError:
                        continue
                if ':' not in label or not isinstance(result, dict):
                    continue
                kind, unit = label.split(':', 1)
                units[unit]['assess' if kind == 'assess' else 'verify'] = result
    return units


_blobs = {}


def blob(repo, ref, path):
    key = (repo, ref, path)
    if key not in _blobs:
        out = subprocess.run(['git', '-C', REPOS[repo], 'show', '%s:%s' % (ref, path)], capture_output=True)
        _blobs[key] = out.stdout.decode('utf-8', 'replace') if out.returncode == 0 else None
    return _blobs[key]


def check_evidence(req):
    failures = []
    for ev in req.get('evidence', []):
        text = blob(ev['repo'], ev['ref'], ev['path'])
        if text is None:
            failures.append('%s %s:%s (file or ref not found)' % (ev['repo'], ev['ref'], ev['path']))
        elif ev['pattern'] not in text:
            failures.append('%s %s:%s (pattern not found: %s)' % (ev['repo'], ev['ref'], ev['path'], ev['pattern'][:60]))
    return failures


def apply_verification(unit, assess, verify):
    """Returns (requirements, notes). Notes are the corrections applied, so the document can say what the verifier changed."""
    reqs = [dict(r) for r in assess.get('requirements', [])]
    by_id = {r['id']: r for r in reqs}
    notes = []
    if not verify:
        return reqs, ['no verifier result for this unit']
    for ref in verify.get('state_refutations', []):
        r = by_id.get(ref['id'])
        if r and ref['should_be'] in STATES:
            notes.append('%s: state %s -> %s (%s)' % (ref['id'], r['state'], ref['should_be'], ref['why'][:160]))
            r['state'] = ref['should_be']
            r['verifier'] = True
    for cor in verify.get('field_corrections', []):
        r = by_id.get(cor['id'])
        if r and cor['field'] in ('functional', 'exists_now', 'done_in_drafts', 'remaining', 'deps_blockers', 'next_task', 'owner_decisions', 'name'):
            notes.append('%s: %s corrected (%s)' % (cor['id'], cor['field'], cor['problem'][:160]))
            r[cor['field']] = cor['replacement']
            r['verifier'] = True
    for cor in verify.get('ac_corrections', []):
        r = by_id.get(cor['id'])
        if not r:
            continue
        for ac in r.get('ac', []):
            if ac['t'].startswith(cor['ac_text_start'][:40]):
                notes.append('%s: criterion "%s" %s -> %s' % (cor['id'], ac['t'][:50], ac['s'], cor['should_be']))
                ac['s'] = cor['should_be']
                r['verifier'] = True
    for cor in verify.get('test_claims_refuted', []):
        r = by_id.get(cor['id'])
        if r:
            for t in r.get('tests', []):
                if t['what'][:40] == cor['what'][:40]:
                    notes.append('%s: test claim refuted: %s (%s)' % (cor['id'], t['what'][:60], cor['why'][:120]))
                    t['kind'] = 'none'
                    t['where'] = 'refuted by the verifier: ' + cor['why'][:160]
                    r['verifier'] = True
    return reqs, notes


def metrics(reqs):
    ac_total = sum(len(r.get('ac', [])) for r in reqs)
    met = sum(1 for r in reqs for a in r.get('ac', []) if a['s'] == 'MET')
    coded = sum(1 for r in reqs for a in r.get('ac', []) if a['s'] == 'CODED')
    states = collections.Counter(r['state'] for r in reqs)
    release = (sum(POINTS[r['state']] for r in reqs) / (5.0 * len(reqs)) * 100.0) if reqs else 0.0
    return {
        'n': len(reqs), 'ac': ac_total, 'met': met, 'coded': coded,
        'implemented_pct': ((met + coded) / ac_total * 100.0) if ac_total else 0.0,
        'proven_pct': (met / ac_total * 100.0) if ac_total else 0.0,
        'release_pct': release, 'states': states,
    }


REPO_URL = {'lavkoo': 'https://github.com/fullstackfull/lavkoo/pull/%d', 'lavkooapp': 'https://github.com/fullstackfull/lavkooapp/pull/%d', 'lavkooappseller': 'https://github.com/fullstackfull/lavkooappseller/pull/%d'}
AXIS_FILE = {a: 'axis-%s-%s.md' % (a.lower(), n.lower().replace(',', '').replace(' ', '-').replace('/', '-')) for a, n in AXES.items()}
MARK = {'MET': '[MET]', 'CODED': '[CODED]', 'MISSING': '[MISSING]'}


def pr_links(links):
    out = []
    for l in links:
        url = REPO_URL.get(l['repo'], '%s') % l['pr']
        out.append('[%s#%d](%s)%s' % (l['repo'], l['pr'], url, (' `%s`' % l['sha']) if l.get('sha') else ''))
    return '; '.join(out) if out else 'none'


def card(r):
    lines = []
    lines.append('#### %s. %s' % (r['id'], r['name']))
    lines.append('')
    lines.append('- **State: %s** | plan lines %s%s | reviews: %s' % (r['state'], r['plan_lines'], (' | ' + r['refs']) if r.get('refs') else '', ', '.join(r.get('reviews_required', [])) or 'none'))
    lines.append('- **(2) Functional requirement.** %s' % r['functional'])
    lines.append('- **(3) Exists now in code (main).** %s' % r['exists_now'])
    lines.append('- **(4) Done in the draft PRs.** %s' % r['done_in_drafts'])
    lines.append('- **(5) What remains.** %s' % r['remaining'])
    lines.append('- **(6) PRs and commits.** %s' % pr_links(r.get('links', [])))
    tests = r.get('tests', [])
    if tests:
        lines.append('- **(7) Tests.**')
        for t in tests:
            lines.append('  - `%s` %s%s' % (t['kind'], t['what'], (' (' + t['where'] + ')') if t.get('where') else ''))
    else:
        lines.append('- **(7) Tests.** none')
    lines.append('- **(8) Dependencies and blockers.** %s' % r['deps_blockers'])
    lines.append('- **(9) Acceptance criteria.**')
    for a in r.get('ac', []):
        lines.append('  - %s %s%s' % (MARK[a['s']], a['t'], (' (' + a['e'] + ')') if a.get('e') else ''))
    lines.append('- **(10) Next coding task.** %s' % r['next_task'])
    if r.get('owner_decisions') and r['owner_decisions'].strip().lower() not in ('none', 'none.'):
        lines.append('- **Open owner decisions.** %s' % r['owner_decisions'])
    if r.get('evidence_failures'):
        lines.append('- **Evidence not confirmed by the script:** %s' % '; '.join(r['evidence_failures'])[:600])
    lines.append('')
    return '\n'.join(lines)


def pct(x):
    return '%.0f%%' % x


def render(all_reqs, out_dir, asof):
    by_axis = collections.defaultdict(list)
    for unit, d in all_reqs.items():
        for r in d['reqs']:
            by_axis[r['axis']].append(r)
    os.makedirs(out_dir + '/tracker', exist_ok=True)
    # axis files
    for axis in sorted(by_axis):
        reqs = sorted(by_axis[axis], key=lambda r: r['id'])
        m = metrics(reqs)
        body = ['# Axis %s: %s' % (axis, AXES[axis]), '', 'Detail cards of the master tracker (`docs/gcc/MASTER_IMPLEMENTATION_TRACKER.md`), as of %s. Requirements: %d. Acceptance criteria: %d (MET %d, CODED %d, MISSING %d). Code completion %s (implemented), %s (proven by a passing test); release readiness %s.' % (asof, m['n'], m['ac'], m['met'], m['coded'], m['ac'] - m['met'] - m['coded'], pct(m['implemented_pct']), pct(m['proven_pct']), pct(m['release_pct'])), '']
        for r in reqs:
            body.append(card(r))
        open(out_dir + '/tracker/' + AXIS_FILE[axis], 'w').write('\n'.join(body))
    return by_axis


def main():
    out = sys.argv[1]
    units = load_results(WORKFLOWS)
    print('units loaded:', sorted(units))
    all_reqs = {}
    for unit, data in units.items():
        if 'assess' not in data:
            continue
        reqs, notes = apply_verification(unit, data['assess'], data.get('verify'))
        for r in reqs:
            r['unit'] = unit
            r['axis'] = UNIT_AXIS.get(unit, unit[0])
            r['evidence_failures'] = check_evidence(r)
        all_reqs[unit] = {'reqs': reqs, 'notes': notes, 'assess': data['assess'], 'verify': data.get('verify')}
    json.dump(all_reqs, open(MASTER + 'merged-results.json', 'w'), ensure_ascii=False, indent=1)
    fails = [(u, r['id'], f) for u, d in all_reqs.items() for r in d['reqs'] for f in r['evidence_failures']]
    print('requirements:', sum(len(d['reqs']) for d in all_reqs.values()), 'evidence failures:', len(fails))
    for u, i, f in fails[:60]:
        print('  FAIL', u, i, f)
    if '--check-only' in sys.argv:
        return


if __name__ == '__main__':
    main()
