# R2: Security, GCC-20 Super Admin (server)

As of 2026-11-18. **Not requested. No reviewer is named. No outcome is recorded.** The register is [REVIEW_GATES.md](../REVIEW_GATES.md). The GCC-20 pull requests state that they are not release ready before this review.

## What the reviewer decides

Whether a platform-owner API with its own authentication, second factor, grants and audit trail is safe to ship, and whether it can reach or change anything a staff member or seller must not.

## Pull requests (all drafts, a stack)

| PR | Head | Base | What it is | CI of that head |
|---|---|---|---|---|
| [lavkoo 71](https://github.com/fullstackfull/lavkoo/pull/71) | `b83a8151` | #64 | level 1: a read-only monitor for the platform owner (off by default) | 22 of 23 green, overlay red by design |
| [lavkoo 79](https://github.com/fullstackfull/lavkoo/pull/79) | `ebf59d2c` | #71 | second factor and session rules (default off) | 22 of 23, same |
| [lavkoo 80](https://github.com/fullstackfull/lavkoo/pull/80) | `cb3db363` | #79 | grants that can end and be narrowed, a tamper-evident audit trail, isolation tests | 22 of 23, same |
| [lavkoo 84](https://github.com/fullstackfull/lavkoo/pull/84) | `630d28e1` | #80 | the API reaches the markets of the grant that the staff country scope leaves out | 22 of 23, same |

## Read in this order

`GCC-20-PLATFORM-ADMIN-API.md` (the rule, the seam, the owner decisions), then the middleware and controllers of #71, the second-factor and session code of #79, the grant and audit code of #80, and the scope intersection of #84. Everything is off by default: read what happens with the switches on.

## Behaviour that changes when it is switched on

A separate administrator guard with its own credentials; a second factor and session limits; grants with an end date and a narrowed scope; an audit chain that detects tampering; a read of every market that the staff country scope would otherwise hide.

## Questions for the reviewer

1. Can a grant reach a market, an entity or an endpoint it does not name? (the isolation tests of #80 say not; read them against the code.)
2. Is the audit chain's tamper evidence what the owner needs, and where is it kept?
3. Is the second factor enforced on every route, including the ones added after #79?

## Outcome (written by the reviewer, in the pull request and here)

| Reviewer | Date | Head read | Outcome | Link |
|---|---|---|---|---|
| | | | | |
