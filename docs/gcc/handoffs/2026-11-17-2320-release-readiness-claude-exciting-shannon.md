# Handoff: release readiness, GCC-20, GCC-14b/14c, Flutter apps (2026-11-17, evening)

Sanitized. Nothing here is a secret or personal data. Every pull request is a **Draft**. None is merged, none is deployed, no production data or key was touched, G0 stays open. The overlay job and the seller app's version gate were not touched or bypassed.

## What the owner asked for this batch

Resume from the last handoff, keep working without asking for approval per fix, and reduce the risk of merging rather than only add pull requests: read CI on the current heads, fix only real technical failures, prepare a staged security release (order, SQLite/MySQL/MariaDB runs, rollback, the files an overlay cannot carry), build GCC-20 phase 2, GCC-14b/14c on the paths not yet covered with a six-country matrix, and check the two Flutter apps against the new contracts. No merge to `main`, no production, no automatic deploy, no random version bump to turn CI green, no repeated status comments on pull requests.

Read these first: `docs/gcc/SECURITY_RELEASE_PLAN.md` (two ways to ship, five stages, runbook, rollback, 14 owner decisions) and `docs/gcc/OWNER_PR_GUIDE.md` (every pull request, merge order, what each one changes, the decisions it leaves open).

## Pull requests and heads (lavkoo)

| PR | Branch | Head | Base | What it is |
|---|---|---|---|---|
| #71 | `gcc/p1-platform-admin-api` | `b83a8151` | `gcc/p1-admin-product-country` | GCC-20 level 1: read-only platform admin API for the seller app, off by default |
| #79 | `gcc/p1-platform-admin-mfa` | `ebf59d2c` | #71 | GCC-20 phase 2, delivery 1: second factor, session rules |
| #80 | `gcc/p1-platform-admin-grants-audit` | `cb3db363` | #79 | GCC-20 phase 2, delivery 2: capabilities, grant expiry, hash-chained audit, isolation tests |
| #84 | `gcc/p1-platform-admin-staff-scope` | `630d28e1` | #80 | platform API reaches only the grant markets the staff country scope also holds (carries a merge of #77; only its last five commits are new) |
| #72 | `gcc/p1-country-scoped-codes` | `1c779d27` | `gcc/p1-admin-product-country` | GCC-14b slice 1: SKU and barcode unique inside a country, behind `GCC_COUNTRY_SCOPED_CODES` |
| #76 | `gcc/p1-country-scoped-codes-2` | `0ad49d2a` | #72 | GCC-14b slice 2: pickers name the country, Amazon mapping, six-country matrix |
| #74 | `gcc/p1-staff-country-scopes` | `712509a1` | `gcc/p1-admin-product-country` | GCC-14c slice 1: staff country scopes on the product screens, behind `GCC_STAFF_COUNTRY_SCOPES` |
| #77 | `gcc/p1-staff-scope-orders-pos-refunds` | `81a28367` | #74 | GCC-14c slice 2: order, POS and refund screens |
| #82 | `gcc/p1-staff-scope-reports` | `d1fc5f4c` | #77 | GCC-14c slice 3: shared layout, people screens, dashboard, reports, header search |
| #78 | `gcc/release/security-hotfix` | `665057a5` | `gcc/release/security-hotfix-base` | baseline hotfix of the security set (deployment branch, not for merge into `main`) |
| #81 | `gcc/release/security-hotfix-ci` | `e1f083cc` | `gcc/release/security-hotfix-base` | the same tree with a longer CI timeout, evidence only |
| #75 | `gcc/release/security-integration` | `47b78ab8` | `main` | CI-only merge of every open security branch, never merged |
| #83 | `gcc/release/gcc-integration-ci` | `8ff34109` | `main` | CI-only merge of the GCC stacks, never merged |

Apps: `lavkooapp#7` (`gcc/p1-customer-storefront-caches`, `56deea72`, on `gcc/p1-customer-storefront-cache-key`); `lavkooappseller#4` (`gcc/p1-seller-platform-admin-shell`, `716f84d9`, on `main`), `lavkooappseller#5` (`gcc/seller-pos-ambiguous-code`, `90b9d334`, on `gcc/p1-seller-market-choice`) and `lavkooappseller#6` (`gcc/p1-seller-platform-admin-mfa`, `e4d7d029`, on lavkooappseller#4's branch).

Older pull requests touched today, each by fast-forward pushes with the leak guard clean (#20 twice: `cb45414c`, then `75afe858`): `#13` (`516881c5`) and `#35` (`7c75b7f4`), `#20` (`75afe858`), `#21` (`af862ff3`), `#22` (`20a99a50`), `#23` (`59aa9789`), `#24` (`eebb5884`). The handoff branch carries the guide and plan.

## CI, as read on 2026-11-17

- `Deployment overlay (artifact, deploys nothing)` is red on every branch cut from `main`: the server runs `698aa1e` and `main` is 395 commits ahead. Not bypassed. The 17 files an overlay cannot carry are in the plan, appendix B.
- Every lavkoo head above is green except that job: #71, #72, #74, #76, #77, #79, #80, #82, #84, #13, #75, #81, and #83 at its final head. #78 shows one MySQL job cancelled by the baseline's 25-minute timeout; #81 is the same tree with a 45-minute timeout and is green.
- The CI-only integration #83 found one real cross-pull-request failure that no single pull request shows (#76 pins the callers of `ProductCountry::newProductAttributes()`, #74 adds a fourth) and it is fixed on #76. At the final head the Catalog (329 tests), StaffScope (458) and Storefront with platform admin (1,179) groups pass on MySQL 8 and MariaDB 11.4.2.
- The long job `Sales-channel and cost tests, MySQL 8.0 (sales channels A-M)` takes 52 to 108 minutes on shared runners (#73 52, #84 98, #76 108); the ceiling on current branches is 110 minutes, so a slow day can cut it. It was cancelled at the ceiling once (#77) and passed on re-run in 79 minutes. Raising the ceiling or splitting the group is the owner's call.
- `main` (`c5c04b7b`) is itself red: `TorodShipmentDiscoveryTest` uses a fixed purchase time that is now more than 36 hours old. The one-line repair is `7f5c4906` (in #1). It is carried in the older pull requests that needed it (#2 to #6, #8, #9 and #11 among them) and, today, in #20 to #24; the logs of #21 to #24 showed only that failure, and the logs of #20 also showed the SellerApiOrderScopingTest errors described next.
- Real reds found today by a sweep of all open pull requests and fixed: #13 and #35 (`ConcurrentOperationsAreRegisteredTest` did not list `wallet.pay_edit_due`, which `Operations::run()` answers); #20 (`SellerApiOrderScopingTest` never emptied the database, so on MySQL and MariaDB every test after the first failed with "Table already exists"; it now empties a throwaway test database first, with the same name and environment guard the schema builders use; CI on `75afe858` confirms it: the Fulfillment and security tests pass on MySQL 8 and on MariaDB 11.4.2).
- Not fixed, one flake: #36, MariaDB concurrency group, `WalletBalanceCurrencyConcurrencyTest::test_two_simultaneous_wallet_orders_for_the_whole_balance_both_pass_the_check_and_can_currently_overdraw_the_wallet` failed once on 2026-11-15 and passed on re-run. It pins the outcome of a race, so it depends on timing. Making it deterministic is a follow-up.
- Seller app: `analyze-and-test` is red on lavkooappseller#1, lavkooappseller#3 and lavkooappseller#4 for one test only, `test/app_version_gate_test.dart` (it pins a backend minimum of 2.0.1 against the shipped 1.0.0+3). Untouched, see the guide's section on the version gate. lavkooappseller#5 and lavkooappseller#6 are stacked, so the workflow does not run for them (it runs on pull requests to `main` and on pushes to `claude/**`); their evidence is the clean-export run in each body.
- Customer app: the workflow runs a scoped analyze and the storefront tests on pushes to `gcc/**`; `lavkooapp#7` is green. `lavkooapp#4` has no workflow run at all.

## Read on 2026-11-18 at 00:27Z

The pushes of #20, #21, #22, #23, #24 and #35 started full CI runs that were queued behind the integration runs. #21 and #35: every job green except the overlay job. #20, #22, #23 and #24: one to three jobs still running, no red other than the overlay job so far. Read the last jobs before calling them green; any other red is a real failure of that head.

## Independent verification of this batch (2026-11-18)

Five read-only verifiers were started on 2026-11-17 at about 23:27Z: the documents against GitHub, the owner report, the code pushed to older pull requests, the CI state, and the compliance of GitHub writes. A container restart lost the run before the last two (the CI re-read and the completeness critic) reported. The four finished verdicts were recovered from the workflow journal, and every finding was re-checked against GitHub or git before it was used.

Corrected in the commit that adds this section: the claim that every verification code is constant (11 places in 8 files are; the seller app v2 reset and e-mail verification are random); the file counts of the equivalence check (199 identical, 8 language files that hold the same texts in another file, 23 changed by `main`); the duration of the long MySQL job; the CI paragraph of the guide; stale text; bare numbers of app pull requests; the count of the seller app's backend questions. The code verifier refuted nothing about the commits pushed to older pull requests (two low notes: the guard's name test is the same as in the three sibling schema builders, and one shared trait would remove the fourth copy).

Compliance findings, kept here so the next session does not repeat them:

- Status comments: six of the eight comments posted since 2026-11-17 12:00Z (seven on lavkoo, one on lavkooappseller) are CI status reports, on #70, #58, #62, #73, #72 and a second one on #83. Post no more; put CI results in the pull request body or the handoff. Deleting existing ones is the owner's choice.
- The 24 merge commits of #75 were made by a script and carry no trailers. History cannot be fixed without a force push, which is forbidden; treat it as a known exception for a branch that is never merged, and give any integration script `-m` with both trailers.
- Workflow files were edited on two pull requests outside the CI-only branches: lavkooappseller#4 (one step before the unchanged full test step, for the flag-on run) and lavkooapp#7 (its own analyze and test lists). Neither touches the version-gate test, which still runs and fails only because of the known 2.0.1 pin.
- Two workflow re-runs were requested by the lead: one job of #36 (at 22:01Z) and one of #77 (at 21:20Z).
- Docs-branch commit messages that write a bare number sign and a pull request number add "referenced this pull request" entries to those timelines (28 so far). Write "PR 13" in commit messages from now on.
- Not readable through the proxy: repository secrets, variables, hooks and protection rules, so a change of the overlay baseline variable could not be excluded from outside; the overlay log shows the default baseline `698aa1e`.
- A merge-conflict check of today's pushes against every open head was first run with a flag this git does not have (it counted every pair as clean); it was fixed and re-run with a known conflicting and a known clean pair as controls: 581 pairs, no conflict introduced.

## Public repositories

All three repositories are public (checked 2026-11-18: not private; the plan opens without signing in). The plan, the guide, the audits and the pull request bodies describe flaws that are not fixed on the server yet. The owner was told, and the plan has the decision (15). Do not add exploit-level detail to any document or pull request body until it is taken: describe the fix and the check, not how to use the flaw.

## Owner decisions that are still open

The plan's section 6 (15 decisions: the verification codes and `APP_MODE`, route A or B, the delivery-man app before stage 1, the roles that lose access, finance review of #52, and the rest) and the guide's section 7. New since the last handoff: the staff scopes must be switched on before or with the platform API when employees hold grants (#84, decisions 1 to 7); slice 3 hides what cannot be told apart by country, such as the admin wallet (decisions 12 to 17 of #82); GCC-20 needs the MFA policy, recovery-code custody, lockout and audit retention decisions and a security review before any merge; the seller app steps need a native Arabic reader for 43 strings and answers to ten backend questions.

## What the next session should do

1. Read CI for #20 to #24 and #35 on their current heads; fix a real red, never skip a test, never touch the overlay job or the version gate.
2. Do not report any pending job as green.
3. Keep the stack in sync by merge commits (a change to #71 goes to #79, #80, #84; to #74 to #77, #82, #84).
4. When a base of a seller or customer app pull request merges, retarget it so its CI runs.
5. No merge, deploy, migration or production access without the owner's separate authorization.
