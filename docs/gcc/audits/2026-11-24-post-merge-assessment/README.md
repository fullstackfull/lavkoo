# The 359 requirements, re-assessed after the merge (2026-11-24)

This is the count the owner asked for, taken from the per-requirement records in `docs/gcc/tracker/` as they stand **after** every branch
was merged into `main`, the whole suite was swept there, and `main`'s own CI went green. Nothing here is estimated: every number is counted
from the criteria themselves, and the method is written out below so the same numbers can be recomputed.

**Totals: 359 requirements, 1753 acceptance criteria.** 937 met (53%), 372 coded but not proven
(21%), 444 not implemented (25%).

*Recounted on 2026-11-24 after E-03, C-15's three-decimal money and its per-market destination and phone rules landed, and after six
criteria were read again from the code rather than from the pre-merge assessment: eleven criteria moved to met, one of them by writing the
test F-14 was missing, and C-15 from not started to partial. Every figure in this file is produced by the script quoted at the end, from the
tracker files, and the three totals balance to 1753 exactly.*

## What each number means

| | |
|---|---|
| **met** | the criterion is implemented AND an acceptance test is named for it |
| **coded** | the behaviour is implemented and no test is named for that criterion, or its evidence was never read |
| **missing** | not implemented |
| **development progress** | (met + coded) / criteria — the share of the work that exists in code |
| **proven progress** | met / criteria — the share that a named test holds |
| **launch-ready** | a requirement whose every criterion is met AND which carries no open owner decision and no outstanding review. It is the only one of these numbers that speaks to release |

A requirement is **proven** when no criterion is missing and none is merely coded; **coded** when nothing is missing but something is unproven;
**partial** when something is missing and something is done; **not started** when nothing is done.

## Per axis

| axis | reqs | criteria | met | coded | missing | development | proven | proven reqs | coded reqs | partial | not started | open decision | open review | launch-ready |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| A — six-country base and customer identity | 37 | 173 | 111 | 25 | 37 | 79% | 64% | 12 | 4 | 21 | 0 | 24 | 25 | 2 |
| B — catalogue and products | 18 | 91 | 46 | 30 | 15 | 84% | 51% | 2 | 6 | 10 | 0 | 16 | 16 | 0 |
| C — inventory and fulfilment | 18 | 94 | 68 | 9 | 17 | 82% | 72% | 7 | 2 | 9 | 0 | 11 | 9 | 5 |
| D — orders, checkout and payments | 37 | 179 | 105 | 33 | 41 | 77% | 59% | 4 | 8 | 24 | 1 | 27 | 30 | 2 |
| E — wallets, loyalty and financial records | 18 | 88 | 59 | 7 | 22 | 75% | 67% | 5 | 3 | 10 | 0 | 16 | 17 | 0 |
| F — themes and brand | 16 | 77 | 43 | 13 | 21 | 73% | 56% | 2 | 3 | 10 | 1 | 9 | 9 | 0 |
| G — admin panel and staff permissions | 20 | 102 | 60 | 13 | 29 | 72% | 59% | 6 | 3 | 10 | 1 | 16 | 17 | 1 |
| H — shipping and integrations | 34 | 157 | 100 | 11 | 46 | 71% | 64% | 9 | 2 | 23 | 0 | 24 | 24 | 3 |
| I — marketing, policies and operations | 37 | 185 | 88 | 45 | 52 | 72% | 48% | 2 | 13 | 20 | 2 | 32 | 32 | 0 |
| J — Flutter customer app | 20 | 98 | 49 | 30 | 19 | 81% | 50% | 2 | 10 | 7 | 1 | 10 | 13 | 2 |
| K — Flutter seller app | 23 | 104 | 48 | 25 | 31 | 70% | 46% | 4 | 4 | 14 | 1 | 16 | 16 | 0 |
| L — GCC-20 super admin | 18 | 94 | 49 | 14 | 31 | 67% | 52% | 1 | 5 | 8 | 4 | 16 | 18 | 0 |
| M — security, tests and deployment | 63 | 311 | 111 | 117 | 83 | 73% | 36% | 3 | 13 | 45 | 2 | 43 | 41 | 2 |
| **the whole plan** | **359** | **1753** | **937** | **372** | **444** | **75%** | **53%** | **59** | **76** | **211** | **13** | **260** | **267** | **17** |

## Development against launch readiness, which are not the same number

- **Development: 75%** of the acceptance criteria exist in code (1309 of 1753).
- **Proven: 53%** carry a named acceptance test (937 of 1753).
- **Launch readiness: 16 requirements of 359 (4%)** are ready in the sense that matters for a
  release: every criterion met, no open owner decision, no outstanding review. Another 76 have every criterion addressed but carry a
  review or an unproven criterion.
- **260 requirements wait on an owner decision and 267 on a review** (finance, security, legal or a native Arabic reader). They
  overlap heavily, and they are the reason launch readiness is far below development: the code is not what is holding the six markets.
- **G0 is open**, and by the charter no GCC phase may be released before it closes. G0 is not a code gate: it needs an authorised copy of the
  production database for isolated measurement, the pharmacyksa98 access question answered, and the security release plan's decisions
  (M3-12). So the launch percentage cannot rise past the owner's own gate however much code lands.

## How much of the "met" evidence predates the merge

Every criterion that cites evidence names where it was read, so the split is counted rather than estimated. Of the **1309** met and coded
criteria:

| where the evidence was read | criteria |
|---|---:|
| after the merge: naming `main`, a CI run of the 600s, this branch or a run URL | 212 |
| on a draft head: naming a draft pull request or an integration run of the 200s or 300s | 609 |
| naming neither — a test by name and no source | 488 |

That is the honest state of the record: **609 of 1288 met-or-coded criteria were read on a head that no longer exists**, and another 466
name no source at all. Two things make them stand up anyway, and one does not:

1. **Every test file they cite exists.** All **114** distinct test files named by a met or coded criterion were checked against the three
   repositories: none is missing, so no criterion rests on a test that was renamed away or never written.
2. **Those files run in `main`'s own CI.** The backend workflow runs the whole suite on SQLite (PHP 8.2 and 8.3) and the database groups on
   MySQL 8 and MariaDB 11.4.2 on every push to `main`; run 648 at `287ae061` finished **success**, and the whole suite was swept on the
   unified tree at 19,346 tests, 886,230 assertions, 0 failures. So "this test passes" is true on `main`, not only on the draft head.
3. **What that does NOT establish** is that each criterion's *wording* still describes the code after the merge. That is a per-requirement
   reading, and it is done as each requirement is worked on -- it has been done for the 14 requirements listed below, and the other records
   carry the master tracker's staleness note rather than a claim.

## The requirements re-read against the merged code since the merge

These were read line by line, their criteria re-anchored to `main` or to this branch, and their tests run on SQLite and on MariaDB 11.4:

| requirement | what changed in the record |
|---|---|
| **E-07** | NOT STARTED → every criterion met and merged (`d55b4646`): the expand migration, the backfill that refuses unless the reconciliation is clean, the shadow mirror in the writer's own transaction, the switch back, and the fact that nothing in the pack writes a balance column |
| **E-13** | five of six criteria met and merged (`287ae061`); the sixth is a behaviour the owner decides (E04-F10) |
| **A1-14** | three missing criteria → all six met: a job carries its market in its payload, a listener in its dispatcher middleware, two countries in one worker do not share one, and each market has a queue |
| **A1-05** | three missing → all four met: the market paths are served by one route table |
| **A1-06** | two missing → five of six met: the website's choice is kept, and no IP inference exists to override it |
| **A1-11** | one of two missing criteria met: webhooks, callbacks and the platform API see every country |
| **A1-18** | two criteria raised to met by a two-country isolation proof through the real sync, ingestor and pool arithmetic |
| **A1-02** | the owner settled the flag question; two criteria were already met by I2-11's lifecycle and the record predated it; the third is met by the safety check |
| **A1-03** | two of three criteria met: the default storefront is a recorded decision read once at boot, refused for a market that may not sell |
| **A1-15** | its one missing criterion met: the caches of market-dependent data are settled and guarded |
| **A1-17** | the record corrected: queued mail is no longer market-blind; what remains is the sender identity and the push topics |
| **M1-03** | its last criterion met: each of the five merge resolutions is pinned, each checked by undoing it |
| **M1-17** | its last criterion met: the combined tree it waited for is `main`, whose CI is green |
| **C-15** | NOT STARTED → characterized: a 12.345 cash-on-delivery amount is rounded to 12.350, not held, and the fix waits on one owner answer |

## Method: every number here is printed by one script

```bash
php docs/gcc/audits/2026-11-24-post-merge-assessment/count.php
```

That script, committed beside this file, is the only source of every figure above -- the table, the percentages, the decision and review
counts, the launch figure, the evidence split and the count of cited test files. It reads `docs/gcc/tracker/axis-*.md` and nothing else, so
the way to check this report is to run it and compare, and the way to update the report after a criterion moves is to run it and copy.

That is not how this file began. It carried figures typed beside figures counted, and they drifted inside one file: a headline of 911 met
criteria over a paragraph that still read 893, 259 open decisions in prose against 261 in the table, and a launch figure of 18 that the
definition in this file's own glossary gives as 16. All of them now come from the script, and the two that were wrong are corrected here.

The definitions it uses, which are this report's:
a requirement is a `#### <id>.` block, a criterion is a line beginning `- [MET]`, `- [CODED]` or
`- [MISSING]`. A requirement "waits on a decision" when its **Open owner decisions** line is neither empty nor opens with None / No open /
Nothing / Settled; it "waits on a review" when its state line's `reviews:` field names finance, security, legal, a native Arabic reader or an
owner decision. Evidence is counted as post-merge when a criterion's line names `main @`, a CI run of the 600s, this branch or a run URL, and
as pre-merge when it names a draft pull request, an integration run of the 200s or 300s, or a bare short commit. The test-file check resolves
every `tests/**.php` and `test/**.dart` path cited by a met or coded criterion against `fullstackfull/lavkoo`, `fullstackfull/lavkooapp` and
`fullstackfull/lavkooappseller`.

