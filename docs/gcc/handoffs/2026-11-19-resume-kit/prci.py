import json, sys, urllib.request
def get(url):
    with urllib.request.urlopen(url, timeout=40) as r:
        return json.load(r)
repo = sys.argv[1] if len(sys.argv) > 1 else 'fullstackfull/lavkoo'
lo = int(sys.argv[2]) if len(sys.argv) > 2 else 0
pulls = []
page = 1
while True:
    d = get(f'https://api.github.com/repos/{repo}/pulls?state=open&per_page=100&page={page}')
    if not d: break
    pulls += d; page += 1
    if len(d) < 100: break
for p in sorted(pulls, key=lambda x: x['number']):
    if p['number'] < lo: continue
    sha = p['head']['sha']
    runs = get(f'https://api.github.com/repos/{repo}/actions/runs?head_sha={sha}&per_page=5').get('workflow_runs', [])
    if not runs:
        print(f"#{p['number']:<4} {sha[:8]} no run   | {p['head']['ref'][:46]}")
        continue
    r = runs[0]
    print(f"#{p['number']:<4} {sha[:8]} run {r['run_number']} {r['status']} {r.get('conclusion')} | {p['head']['ref'][:46]}")
