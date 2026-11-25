# Go / No-Go, as of 2026-11-25

Report 12 of Discovery-01 (`audits/2026-11-12-0115-discovery-01/12-go-no-go-recommendation.md`, Arabic) answered the
owner's seven questions and set ten conditions, C1 to C10, between the project and the G0 gate. That was thirteen days and
several thousand tests ago, and nothing since has restated it: the charter and the handoffs say what may be DONE, not
where the conditions stand.

This file does two things and nothing else. **Section 1** answers the seven questions in order, in one place, from the
tree as it is today. **Section 2** re-evaluates C1 to C10 with today's evidence. It changes no verdict of report 12 that
it cannot show evidence for, and it grants no permission: G0 is the owner's gate and is still open.

`tests/Unit/Architecture/GoNoGoTodayTest` reads this file: the seven answers must be here, the ten conditions must each
carry a verdict from a fixed vocabulary, and every test cited must exist in the tree.

---

## 1. The seven questions

### Q1. Can the structure grow to six markets without a radical rebuild?

**Yes, and it is now being done rather than predicted.** Report 04 said no rewrite was needed; what has happened since is
the evidence. The six-market work is additive: 98 migrations, every one of them expand-only, each adding a table or a
nullable or defaulted column, and all 98 can be re-run after a run that stopped half way
(`GccMigrationsContractTest`, `MigrationsRunTwiceTest`). The one engine that had to be opened up — the closed
`ProviderCode` enum, which 827 places name — was opened without touching those 827 places, by resolving a carrier through
a registry. Every new capability ships behind a switch that is OFF, and the first test of each asserts that with it off
nothing changed.

What that answer does NOT cover: three systems have no market dimension at all rather than a switched-off one — the
analytics rollup, the search index and the monitoring module — so a figure an operator reads from them is a platform
total. Adding one later is a change to a reporting path, not a rebuild, but it is work nobody has scheduled.

### Q2. Which systems should be preserved most carefully?

In this order, by what breaking one costs:

1. **The checkout and `generateOrder`.** Seven callers with seven habits, one of which passes an ARRAY where the key says
   Request. A new typed call site inside it produced 39 errors and 12 failures in a feature two layers away.
2. **The money columns.** `users.wallet_balance` and `users.loyalty_point` have five and two writers; the drift between
   the ledger and the column is KNOWN and pinned, not fixed (`WalletLedgerCharacterizationTest`).
3. **`CartManager`'s shipping defaults.** A shop with no `shipping_method` setting leaves the type as `''`, and
   "correcting" that to `order_wise` made the shipping charge ZERO for those shops. Thirty-nine tests said so.
4. **The Saudi storefront's cache keys.** The default market keeps the unscoped key it has always had, so its cache is
   not cold after the deploy.
5. **The seller app's version gate**, which is never touched by any of this work and whose test stays as it is.

### Q3. Where are the most dangerous change points?

The nine classes of `THREAT_MODEL.md`, each with the control that stops it today and the gap that is left. The four that
would go wrong first are named there: the storefronts switch cannot be turned off again once two markets have data;
neither Flutter repository scans for a committed secret and nothing has been rotated; no shared cache store or real
worker was exercised by any test until `SharedStoreAndRealWorkerTest`; and no queued job carries a market of its own, so
every queued message is correct only because the order it reads carries one.

### Q4. What does the first phase require?

Nothing that is not already written: the migrations of this programme applied on MySQL 8 and MariaDB 11.4.2 (they are, in
every engine job of CI), every switch shipped off (`EverythingStartsOffTest`, `StorefrontDefaultsTest`), and the Saudi
golden answers unchanged — which is a named CI job that refuses a pull request changing one
(`.github/scripts/saudi-baseline-guard.sh`, the `Saudi baseline expectations unchanged` job, and `SaudiBaselineJobTest`
over its own configuration). What phase 1 still lacks is C5's DDL snapshot of the real schema, which is the owner's to
authorise and not an engineer's to produce.

### Q5. What must be reviewed before implementation is allowed?

`REVIEW_GATES.md` names the gates and records that **no review in it has been requested or done, and nobody is named for
the security role.** Reviews that the work has already earned: the security review of GCC-20 (the platform-admin API,
which is read-only and ships off), a finance review of the wallet and loyalty ledger attribution, and a native-Arabic
reading of the strings in `ARABIC_TEXT_FOR_REVIEW.md` — which covers `resources/lang` of this repository only, and not
the two apps' own translation files.

### Q6. Which branches go first?

One branch per repository, `gcc/development`, and that has not changed. What has changed is that the question report 12
asked — in which order do 99 open pull requests merge — is history: every set is merged into `gcc/development`, and
`MERGE_ORDER.md` is a record of how it was done rather than a plan. The order that matters now is a release order, which
is `SECURITY_RELEASE_PLAN.md` stages 0 to 5, and no stage has been released.

### Q7. What information is missing that blocks approval?

Four things, and three of them need the owner rather than an engineer:

1. **The production server's facts** (step H-0: the code, the engine and `APP_MODE` the server runs). H-0 is written and
   has never been run, so every statement about "what production runs" in this programme is unverified. Needs the
   authorised administrator.
2. **A structure-only copy of the real Saudi schema** (C5/P0-C). Blocks the migration rehearsal, the ALTER measurement on
   the pilot's engine, and any claim that Saudi behaviour is preserved on the real schema rather than on an inferred one.
3. **The restricted security annex**, which nobody is recorded as having read, for 23 explicit P0 findings whose detail
   is only there.
4. **Twenty-two D-decisions** (D-01 to D-22), of which the ones recorded as taken are listed in `EXECUTION_STATE.md`.

---

## 2. C1 to C10, re-evaluated

The verdict vocabulary is fixed, so the table can be read at a glance and so a test can check it: **MET**,
**PARTLY MET**, **NOT MET**, **UNKNOWN** (nobody here can see the answer) and **BLOCKED** (waiting on the owner or on
access, not on work).

### C1 — a written owner decision taking the programme out of DISCOVERY ONLY

**MET.** `AUTONOMOUS_DEVELOPMENT_CHARTER.md` is the standing delegation, quoted at the head of `CLAUDE.md` in all three
repositories: continuous development on isolated branches, without per-feature approval. Report 12's own words were that
the report did not grant it; the charter does. It grants coding, not merging or releasing: merge to `main`, production
deploy, production data, real-balance migration and activating a market are all still forbidden without separate
approval.

### C2 — an automated scan for secrets in tracked files and history, extended to the five other markets

**PARTLY MET.** This repository: the `No secret or personal data added` job runs `scripts/ci/leak-guard.php` against the
lines a change ADDS, by the form the value takes rather than by a list of names, and refuses files that must never be
committed; `LeakGuardTest` pins it, including that a JWT is recognised and that only the example environment files are
allowed. What is NOT met: neither Flutter repository has a scan on its default branch, and the customer app has no CI
there at all (M1-V2) — the two repositories a leaked key is most likely to be committed to. The scan reads the lines a
change adds, so the HISTORY of this repository has not been swept, and nothing has been rotated (DR-1).

### C3 — the ancestry of pull requests 1 to 18, the production commit and the applied migrations

**BLOCKED.** Unchanged since report 12: closing it needs access to `pharmacyksa98`, which the charter forbids (section 4).
The part that could be done here has been: `PR_LEDGER.md` records every pull request with its base, head and state, and
`docs/gcc/tools/check_pr_ledger.py` exits 1 when a head has moved.

### C4 — a restore environment: staging, or an anonymised reproduction

**NOT MET.** There is no staging server (M1-14), no seeder that builds six markets of invented data, and therefore no
rehearsal of the release runbook or of the rollback anywhere but on a developer's throwaway database. The tooling that
would use it exists and is tested: the overlay build, the drift check, the apply and the rollback
(`OverlayDeploymentTest::test_a_rollback_puts_every_file_back_exactly_as_it_was`), and the backup scripts with a restore
test (`BackupScriptsTest`). What is missing is the environment, not the tool.

### C5 — a DDL snapshot without data, and every migration applied on MySQL 8 and MariaDB 11.4.2

**PARTLY MET.** The second half is met and is now the ordinary state of CI: every engine job runs the migrations of this
programme on MySQL 8 and on MariaDB 11.4.2, and no group runs on one engine only
(`CiEveryTestDirectoryRunsOnARealEngineTest::test_each_engine_matrix_holds_two_engines_and_drops_no_cell`). The first
half is **BLOCKED**: there is no structure-only snapshot of the real schema, so every test of this programme runs against
a schema inferred from the models and the code that writes them, and says so.

### C6 — the measurements M-01 to M-16 on a restored copy

**UNKNOWN**, as report 12 said, and for the same reason: there is no restored copy. One of the sixteen has been measured
on a copy nobody restored — the ALTER of `products` with 200,000 rows on MySQL 8.0.46, instant — and the engine the pilot
runs, MariaDB 11.4.2, has still not been measured with it (M2-V4), which is the wrong way round.

### C7 — CI green on HEAD, and the Saudi characterization

**PARTLY MET**, and this is the condition that has moved furthest. CI run 648 on `main` @ `287ae061` finished success:
the whole suite on SQLite under PHP 8.2 and 8.3, and every database group on MySQL 8 and MariaDB 11.4.2. The Saudi
characterization exists as a golden-answer pack with a named CI job that refuses a pull request changing an existing
answer. What keeps it from MET: `gcc/development` is ahead of the SHA that was proved, each push has to earn its own run,
and the owner has not accepted the characterization pack as the baseline in writing.

### C8 — a signed decision for each of D-01 to D-22 that blocks phase 0 or phase 1

**PARTLY MET.** Report 12 recorded all of them open. Six are now recorded as taken in `EXECUTION_STATE.md` (F-13 the
global identity, I2-09 the point-of-sale country rule, A1-17 the per-market push topics, E-02 preserving Saudi loyalty
behaviour, A1-02 legacy mode, A1-03 the recorded default storefront). Six are recorded as open with the mechanism built
dormant beside them (LF-1 what a point is worth, E11-F02 the courier's settlement currency, E04-F10 the admin-cancelled
wallet refund, Q-33 which carrier serves which country, Q-13 who grants destination permissions, and the remainder of
I2-09). The D-numbering of report 11 and the names used in `EXECUTION_STATE.md` are not the same scheme, which is a gap
of its own (M3-11).

### C9 — G0, the owner's gate after C2 to C8

**NOT MET.** C2, C5, C7 and C8 are partly met, C3 and C4 are not, C6 is unknown. No document closes G0 and none claims
to; every card of this programme that would need it says so.

### C10 — the P0 security findings closed with the owner's signature

**NOT MET.** The published triage carries 222 SEC rows and every one of them reads OPEN; 23 are explicit P0. Fixes exist
for whole classes of them — identity, ownership, permissions — but the cards map pull requests and stages and never map
SEC ids, so nobody can say which of the 23 a given stage closed (M1-V1). Nothing is recorded as accepted by the owner
either: `DEFERRED_RISKS.md` holds five rows, DR-1 to DR-5, and none of them is a SEC id.

---

## What this changes

Nothing by itself. It is a reading, and the two conditions that moved (C7's green run, C8's six taken decisions) moved
because of work that was already done and recorded elsewhere. The value is that the ten conditions are in one place
again, with evidence a reader can check, and that the four pieces of missing information in Q7 are named as what they
are: three of them are the owner's to unblock, and none of them is waiting on an engineer.
