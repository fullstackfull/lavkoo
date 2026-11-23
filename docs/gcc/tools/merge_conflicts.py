#!/usr/bin/env python3
"""Which open pull requests conflict textually with which, by dry-run merge.

It checks out nothing, writes to no branch and changes no pull request: `git merge-tree` does the three-way merge in
memory and prints the files that collide, so the answer costs nothing but time. Textual conflict only -- a pair that
merges cleanly here can still break a guard the other one wrote, and those semantic conflicts live in the audits.

Usage
  python3 merge_conflicts.py <repo> <pr>[,<pr>...] [--against all|<pr>,...] [--json <path>]

  <repo>      a clone of fullstackfull/lavkoo whose remote refs are fetched
  <pr>        the pull requests to report on, by number
  --against   what to try each of them against; "all" (the default) means every other open pull request

It reads the open pull requests from `gh`, so the person running it needs the same GitHub access the session has.
Both heads of a pair are taken from the remote, never from a working tree, so the answer is about what is published.
"""

import argparse
import json
import subprocess
import sys
from itertools import combinations


def run(args: list[str], cwd: str | None = None) -> tuple[int, str]:
    done = subprocess.run(args, cwd=cwd, capture_output=True, text=True)
    return done.returncode, done.stdout


def open_pull_requests() -> dict[int, dict]:
    code, out = run(['gh', 'api', 'repos/fullstackfull/lavkoo/pulls?state=open&per_page=100', '--paginate',
                     '-q', '.[] | {number, head: .head.ref, sha: .head.sha, base: .base.ref, title}'])
    if code != 0:
        sys.exit('could not read the open pull requests from GitHub')

    return {row['number']: row for row in (json.loads(line) for line in out.splitlines() if line.strip())}


def depth_of(number: int, pulls: dict[int, dict], branch_owner: dict[str, int], seen: frozenset = frozenset()) -> int:
    """How many pull requests a pull request is stacked on. 0 means it is based on a branch no open pull request owns."""
    base = pulls[number]['base']
    under = branch_owner.get(base)

    if under is None or under in seen or under == number:
        return 0

    return 1 + depth_of(number=under, pulls=pulls, branch_owner=branch_owner, seen=seen | {number})


def conflicting_files(repo: str, left: str, right: str) -> list[str]:
    """The files a three-way merge of two commits cannot settle by itself, or [] when it merges cleanly.

    With --write-tree --name-only git prints the oid of the merged tree, then one conflicted path per line, then a
    BLANK LINE and its own running commentary ("Auto-merging ...", "CONFLICT (content): ..."). Only the lines before
    that blank line are paths, so reading to the end would report the commentary as files.
    """
    code, out = run(['git', 'merge-tree', '--write-tree', '--name-only', left, right], cwd=repo)

    if code == 0:
        return []

    files = []
    for line in out.splitlines()[1:]:
        if not line.strip():
            break
        files.append(line)

    return sorted(set(files))


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('repo')
    parser.add_argument('pulls')
    parser.add_argument('--against', default='all')
    parser.add_argument('--json', default=None)
    options = parser.parse_args()

    pulls = open_pull_requests()
    branch_owner = {row['head']: number for number, row in pulls.items()}

    asked = [int(n) for n in options.pulls.split(',')]
    missing = [n for n in asked if n not in pulls]
    if missing:
        sys.exit('not open pull requests: ' + ', '.join(str(n) for n in missing))

    against = sorted(pulls) if options.against == 'all' else [int(n) for n in options.against.split(',')]

    for number in asked:
        run(['git', 'fetch', '--quiet', 'origin', pulls[number]['sha']], cwd=options.repo)

    report = {'pulls': {}, 'pairs': []}

    for number in asked:
        row = pulls[number]
        report['pulls'][str(number)] = {
            'branch': row['head'],
            'sha': row['sha'],
            'base': row['base'],
            'depth': depth_of(number=number, pulls=pulls, branch_owner=branch_owner),
            'stacked_on': branch_owner.get(row['base']),
            'title': row['title'],
        }

    seen_pairs = set()

    for number in asked:
        for other in against:
            if other == number:
                continue

            pair = tuple(sorted((number, other)))
            if pair in seen_pairs:
                continue
            seen_pairs.add(pair)

            files = conflicting_files(repo=options.repo, left=pulls[pair[0]]['sha'], right=pulls[pair[1]]['sha'])
            if files:
                report['pairs'].append({'left': pair[0], 'right': pair[1], 'files': files})

    report['pairs'].sort(key=lambda p: (p['left'], p['right']))

    for number, row in sorted(report['pulls'].items(), key=lambda kv: int(kv[0])):
        stacked = ('stacked on #%d' % row['stacked_on']) if row['stacked_on'] else 'based on ' + row['base']
        print('#%s  depth %d  %s  (%s)' % (number, row['depth'], row['branch'], stacked))

    print()
    print('%d pairs tried, %d conflict' % (len(seen_pairs), len(report['pairs'])))

    for pair in report['pairs']:
        print('#%d with #%d: %s' % (pair['left'], pair['right'], ', '.join(pair['files'])))

    if options.json:
        with open(options.json, 'w', encoding='utf-8') as out:
            json.dump(report, out, indent=1, ensure_ascii=False)
            out.write('\n')


if __name__ == '__main__':
    main()
