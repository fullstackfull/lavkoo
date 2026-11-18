# R4: Security, the staged hotfix

As of 2026-11-18. **Not requested. No reviewer is named. No outcome is recorded.** The register is [REVIEW_GATES.md](../REVIEW_GATES.md). Deferred risks DR-1 to DR-5 ([DEFERRED_RISKS.md](../DEFERRED_RISKS.md)) are open and are not changed by this review: no key was rotated.

## What the reviewer decides

Whether each stage of the hotfix, cut from the server baseline `698aa1e`, may be deployed by the owner, in the order of the release plan.

## Pull requests (all drafts, deployment branches never merged to `main`)

| PR | Head | What it is | CI of the last head that was read |
|---|---|---|---|
| [lavkoo 85](https://github.com/fullstackfull/lavkoo/pull/85) | `b83fd189` | the first stage: removes the open maintenance routes and stops logging one-time codes (named "stage 0" in its title) | 7 of 7 green at that head |
| [lavkoo 78](https://github.com/fullstackfull/lavkoo/pull/78) | `fafa1a2e` | the whole hotfix from the baseline: 24 pull requests as one net change each | head moved after the last read (`665057a`: 5 green, 1 cancelled, overlay red by design); not read for `fafa1a2e` |
| [lavkoo 81](https://github.com/fullstackfull/lavkoo/pull/81) | `0f512114` | CI evidence for the hotfix with a 45-minute MySQL job (do not merge) | head moved after the last read (`e1f083c`: 6 green, overlay red by design) |
| [lavkoo 86](https://github.com/fullstackfull/lavkoo/pull/86) | `49e170fe` | CI evidence only: the baseline hotfix tree on MariaDB 11.4.2 and MySQL 8 (do not merge) | head moved after the last read (`57900cc`: 7 green, overlay red by design) |

## Read in this order

The release plan and the owner guide for the stage, then **the stage's net diff against the server baseline**, never the CI evidence branches (#81 and #86 deploy nothing). `SERVER_PREFLIGHT.md` and `MERGE_ORDER.md` say what the owner checks on the server first.

## Questions for the reviewer

1. Does the net diff of a stage contain only what its title says, and does it apply to the server's tree as it is?
2. Is any route or log line a stage removes still reachable through another path?
3. Is the order of stages safe if only the first is deployed?

## Outcome (written by the reviewer, in the pull request and here)

| Reviewer | Date | Head read | Outcome | Link |
|---|---|---|---|---|
| | | | | |
