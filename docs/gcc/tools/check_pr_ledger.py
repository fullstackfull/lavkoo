#!/usr/bin/env python3
"""Compares the heads in docs/gcc/PR_LEDGER.md with the remote's pull request refs. Read-only: one `git ls-remote`, no write, no token.

usage: python3 docs/gcc/tools/check_pr_ledger.py [path-to-PR_LEDGER.md] [remote]

Exit 0: every pull request of the remote is in the ledger with its current head. Exit 1: a head moved, a pull request is missing from the ledger, or the ledger names one
that no longer exists. The ledger is regenerated, not edited by hand.
"""
import re
import subprocess
import sys

ledger = sys.argv[1] if len(sys.argv) > 1 else 'docs/gcc/PR_LEDGER.md'
remote = sys.argv[2] if len(sys.argv) > 2 else 'origin'

recorded = {}
for line in open(ledger, encoding='utf-8'):
    match = re.match(r'\| \[(\d+)\]\([^)]*\) \|.*\[`([0-9a-f]{8})`\]\([^)]*/commits/([0-9a-f]{40})\)', line)
    if match:
        recorded[int(match.group(1))] = match.group(3)

listing = subprocess.run(['git', 'ls-remote', remote, 'refs/pull/*/head'], capture_output=True, text=True, check=True).stdout
current = {int(row.split('\t')[1].split('/')[2]): row.split('\t')[0] for row in listing.splitlines() if row}

moved = sorted(number for number in recorded if number in current and recorded[number] != current[number])
missing = sorted(number for number in current if number not in recorded)
gone = sorted(number for number in recorded if number not in current)

for number in moved:
    print('moved: #%d was %s, is %s' % (number, recorded[number][:8], current[number][:8]))
for number in missing:
    print('missing from the ledger: #%d (%s)' % (number, current[number][:8]))
for number in gone:
    print('in the ledger but not on the remote: #%d' % number)

print('%d recorded, %d on the remote, %d moved, %d missing, %d gone' % (len(recorded), len(current), len(moved), len(missing), len(gone)))
sys.exit(1 if moved or missing or gone else 0)
