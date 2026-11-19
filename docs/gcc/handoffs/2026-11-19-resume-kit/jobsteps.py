import sys, json, re, urllib.request
repo, run = sys.argv[1], sys.argv[2]
def get(url):
    with urllib.request.urlopen(url, timeout=30) as r:
        return r.read().decode('utf-8', 'replace')
jobs = json.loads(get(f'https://api.github.com/repos/{repo}/actions/runs/{run}/jobs?per_page=100'))['jobs']
print('run', run, 'jobs', len(jobs))
for j in jobs:
    print(f"JOB {j['name'][:90]:90s} {j['status']} {j.get('conclusion')} head={j['head_sha'][:8]}")
    if len(sys.argv) > 3 and sys.argv[3] == 'steps':
        for s in j.get('steps', []):
            if s['name'].startswith(('Post ', 'Set up', 'Complete', 'Run actions')):
                continue
            print(f"     step {s['number']:2d} {s['name'][:100]:100s} {s['conclusion']}")
