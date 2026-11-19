#!/usr/bin/env bash
# usage: jobsum.sh <owner/repo> <run id>... -> per run: counts and the jobs not yet successful
repo=$1; shift
for id in "$@"; do
  curl -s -m 40 "https://api.github.com/repos/$repo/actions/runs/$id/jobs?per_page=100" | python3 -c "
import sys, json, collections
d = json.load(sys.stdin)
jobs = d.get('jobs', [])
c = collections.Counter((j['status'], j.get('conclusion')) for j in jobs)
print('run $id:', len(jobs), 'jobs', dict(c))
for j in jobs:
    if not (j['status'] == 'completed' and j.get('conclusion') == 'success'):
        print('   ', j['status'], j.get('conclusion'), '|', j['name'])
"
done
