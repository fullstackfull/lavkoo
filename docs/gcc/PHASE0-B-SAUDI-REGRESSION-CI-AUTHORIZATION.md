# LAVKOO GCC — Owner Authorization: Limited Phase 0-B Coding (Saudi Regression + CI)

**Owner approval:** 2026-11-12, Asia/Kuwait.  
**Status:** APPROVED FOR THIS SPECIFIC WORKSTREAM ONLY.  
**Supersedes:** Earlier `DISCOVERY ONLY` instructions **solely within this exact scope**. All unrelated restrictions remain in force.  
**This is NOT approval of G0**, of GCC phases 1–17, of GCC-20 implementation, or of production changes.

## 1. Authorized objective

Begin the **first bounded coding PR** that protects the **existing Saudi Arabia storefront and backend behavior** through characterization/regression tests, then diagnose and minimally repair identified **CI failures**, only after checking their root causes.

Work in independent branches, commit and push each internally reviewed checkpoint **immediately**, and open **draft PR(s) for owner review only**. A PR is specific to a single GitHub repository; never pretend one PR can atomically change Laravel and Flutter repositories.

- **Primary workstream / first PR:** `fullstackfull/lavkoo` (Laravel/backend), branch `gcc/p0-saudi-baseline-ci` or another collision-free `gcc/p0-saudi-baseline-ci-*` branch, based **directly on the current verified main**, not on the history-bearing `claude/exciting-shannon-4mh2ok` audit branch.
- **Optional separate PR, only after isolating its failure:** `fullstackfull/lavkooappseller`, branch `gcc/p0-seller-ci-gate`, covering version-gate test reproducibility and safe test/CI-only fixes. Do **not** change `pubspec.yaml` versions, release identities, backend force-update minimums or live settings unless owner separately approves them with verified live-release data.
- **`fullstackfull/lavkooapp` customer Flutter:** read-only for API compatibility context, **no edits** in this authorization.

Previous audit/handoff documents remain on `fullstackfull/lavkoo` audit branch. Code branches must start at the verified `main` SHA to prevent accidental inclusion of dozens of audit-only commits in the code PR.

## 2. Current evidence pointers — reverify HEAD before coding

At the time of authorization:
- `lavkoo/main` = `55e3dd0ea41069f64b0657fcbb4f8953710d2cd9`
- `lavkooapp/main` = `eedbbb8fd0303aa9fed5d4ff0ac45b3a409abf7a`
- `lavkooappseller/main` = `9ea64232683a6b415fb504e3d0a740a2e5a53306`
- Audit branch `claude/exciting-shannon-4mh2ok` contains Discovery-01/02 and Phase0 limited read-only reports.

Observed existing GitHub Actions run on `lavkoo/main`: **43122772278**, five failed jobs out of 21: SQLite PHP 8.2, SQLite PHP 8.3, MySQL sales channel N-Z, MariaDB sales channel N-Z, MariaDB strict-mode. Observed seller run: **43137204619**, `analyze-and-test` failed in **Test**; Android build skipped. These are **job-level symptoms**, not verified root causes. Inspect redacted step logs and reproduce only on sandbox runners before making changes.

Known seller version conflict: `lavkooappseller/pubspec.yaml` declares `1.0.0+3`, while `test/app_version_gate_test.dart` hard-codes `kBackendPublishedMinimumVersion = '2.0.1'`. Actual production minimum, published versions and store distribution are **UNKNOWN**; do not guess or blindly bump/lower versions or change tests merely to make them green.

## 3. What may be changed

**Authorized once root cause is evidenced and reviewed:**
- New or refined backend characterization / regression tests covering existing Saudi flow, such as cart/checkout splitting, payment callback verification with mocks, wallet/loyalty accounting, refunds, coupons, authorization/scoping, category/catalog, stock reservation/fulfillment, themes, and legacy API compatibility, prioritized by P0 impact.
- Small fixes to **test fixtures, test-only helpers, isolated CI configuration or test environment setup** that are demonstrated to be causing false failures or non-determinism **without altering any production runtime behavior**.
- Optional narrow seller app **test-only** changes after a version-gate diagnosis; avoid changing version rules or pinning a fictional production minimum.
- Documentation, sanitized test results, PR description, reproducible failure/after evidence and unique GitHub handoffs.

**Not approved:**
- Changes to production PHP/Dart business logic, application APIs, auth, role guards, wallets, pricing, payment, coupons, fulfillment, theme runtime, seller version / minimum, or deployed settings. If a test exposes a real runtime bug, **write a failing reproducer, document and stop for an additional owner decision** before editing runtime code.
- Any new, edited, applied or deployed migrations/schema changes, or manual `php artisan migrate`.
- Production access, reading production DB/credentials/logs, writes to production or live provider APIs, deployment, release builds, real emails/SMS/push, job sync, shipping/payments, application store release.
- Force-push, branch reset/rebase that overwrites others, direct push/merge into `main`, changing branch protection, disabling workflow gates, weakening security validation, deleting/skipping failing tests just to pass, bypassing secrets scan, changing test expected outputs without regression evidence.
- Changes to GCC-20 platform Super Admin, countries/storefront context, FX/ledger behavior, seller authorization, customer Flutter, or any Phase 1–17 tasks.
- Fixing, retrieving, republishing, or validating secrets related to `SEC-E21-04`; security fixes for `SEC-E05-03` require independent authorization. If suspected live secret exposure is encountered in CI logs, **stop and alert owner privately without printing it**.

Existing automated test suites may create disposable in-memory / isolated CI test tables as part of ordinary tests; this authorization does **not** permit standalone migration commands, any persistent DB writes, or using real customer/production data. Keep tests offline using mock providers and throwaway DBs only. If isolation cannot be proved, **do not run** and report BLOCKED.

## 4. Strict development sequence (review before commit)

1. Read `CLAUDE.md` and the canonical `docs/gcc` decisions/collaboration rules plus **this explicit owner approval** from the audit branch. Explain that the previous Discovery Only stage is superseded **for this narrow PR only**, not globally.
2. Verify remote HEADs and branch protections, current Git status, existing PRs and other Claude sessions. Record exact SHA; do not modify another agent's branch. Branch from the repository's actual `main`, never from audit branch. If branch name already exists, do not overwrite; choose a new suffix.
3. **Diagnose CI first.** Review GitHub Actions job/step status and sanitized failure logs for `lavkoo` run 43122772278 and seller run 43137204619. Record failing test names/stack traces with any private data redacted; classify each as real regression, test fixture/environment fault, timing/flakiness, missing prod baseline, or UNKNOWN.
4. Reproduce relevant tests in a **sandbox/ephemeral environment** with no production services. Do not install/build against live servers or use production secrets.
5. Write **characterization tests that reflect documented current Saudi behavior**, using source references; avoid inventing golden balances, fake production schemas, or legal conclusions. Where the historical baseline requires real/unknown schema or funds, mark BLOCKED instead of hard-coding assumptions.
6. For CI/environment-only faults, propose the **smallest test/fixture/CI-only patch** preserving current application semantics and existing security gates. Verify before/after in isolation; do not edit runtime source if the root cause is a real application bug—prepare minimal reproducer and ask owner.
7. Enforce **PRE-PUSH QUALITY GATE** on every checkpoint: local review of full diff and changed paths; confirm no application runtime edits, no migrations, no credential material, no PII/dumps, no skipped/relaxed CI tests, evidence grounded, tests executed only in sandbox; only then commit and **push immediately** to the dedicated remote feature branch. Fix-forward if a defect is discovered; no force push.
8. Use a **draft PR targeting `main`**, with no auto-merge; PR body includes approval link, source SHA, affected files, root cause evidence, tests and outcomes, residual red statuses, compatibility/rollback, and explicitly the outstanding G0/production/security constraints. Open PR only when there is a concrete reviewed test/CI diff; otherwise publish a handoff and stop.
9. Only after backend branch checkpoint and owner visibility, start the optional separate seller CI branch/PR if compatible with this exact scope. Never mix seller and backend code in one repository or claim a cross-repo PR exists.
10. Leave all non-approved changes untouched. A green CI is evidence, **not** owner permission to merge/deploy or a declaration G0 closed.

## 5. Test selection and safety

Begin with a narrow subset of the highest-risk **Saudi** paths from Discovery-01 report 09 and the P0-B roadmap. Prioritize characterization of monetary precision/accounting, checkout, wallet/refund boundaries, seller roles, current payment callbacks with fake providers, and idempotency. Keep backward compatibility of legacy IDs, seller/shop semantics, and external APIs. Use existing test factories and isolated fixtures where safe; do not auto-rewrite baseline expectations.

CI triage must preserve, not disable:
- PHP 8.2 / 8.3 test matrices, MySQL 8 and MariaDB 11.4.2 coverage where available.
- Seller Flutter analysis and version-gate protection.
- Secrets/leak-guard, permissions tests, endpoint auth and provider-mock rules.

No blanket `@skip`/reduced coverage, retries that mask deterministic bugs, force-pushing, dependency major-version changes, or version downgrades to silence failures.

## 6. Files and remote reporting

Record owner authorization on the audit branch and a copy/referenced link in the backend work branch; the backend work branch must be a **descendant of current `main`**, not the audit branch. Since `main/CLAUDE.md` still says DISCOVERY ONLY, the authorization file **explicitly narrows and overrides** that stage for this one workstream. Do not alter CLAUDE.md on `main`.

Push workstream code checkpoints only to their dedicated feature branches. Save sanitized handoffs to `docs/gcc/handoffs/` on the work branch (or audit branch if more convenient), with exact repository, branch and SHA; never push audit-only report history into a code branch.

Each completed checkpoint: **review → commit → push immediately → verify remote SHA → share URL**. A draft PR can remain pending owner review; no merge.

## 7. Acceptance criteria and STOP

The first bounded workstream is complete when:
- CI failure causes are listed with evidence and classified, including those **not fixed** due to unapproved runtime changes.
- At least one meaningful Saudi regression/characterization test is created and executed **on a proven isolated test environment**, or accurately marked BLOCKED if prerequisites are not available.
- Any CI-only fixes are minimal, independently reproducible and not misleading; remaining failures clearly reported.
- One **draft PR** is opened in `lavkoo` with reviewed diff, its exact branch/commit and test evidence; seller has a **separate** draft PR only if diagnostic and fixes are within the same approved scope.
- No changes to `main`, production, migration history, accounts, provider settings or GCC app logic.
- `P0-C` / `P0-E` remain BLOCKED if the isolated KSA DB copy was not verified, and G0 remains OPEN.
- No claimed closure of `SEC-E21-04` or `SEC-E05-03`.

If a failure can only be resolved by a runtime change, an actual seller version choice, schema/migration work, production access or any other unapproved action: **STOP THAT SUBTASK and ask owner for a new narrow approval**. Continue only independent test/CI tasks within the permitted scope.

## 8. Current status and next instruction

**APPROVED:** A bounded first coding workstream for Saudi regression tests and CI-only repairs in a dedicated PR.  
**NOT APPROVED:** G0, Phases 1–17, GCC-20 implementation, production access/change, mergers, release builds, application runtime changes, migrations or security remediation.

At completion: provide repo/branch/PR URLs, remote SHAs, changed files, tests run/not run, exact verified CI status and remaining blockers. **STOP for explicit owner PR review and merge decision.**
