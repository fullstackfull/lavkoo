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

Apps: `lavkooapp#7` (`gcc/p1-customer-storefront-caches`, `56deea72`, on `gcc/p1-customer-storefront-cache-key`); `lavkooappseller#4` (`gcc/p1-seller-platform-admin-shell`, `716f84d9`, on `main`), `#5` (`gcc/seller-pos-ambiguous-code`, `90b9d334`, on `gcc/p1-seller-market-choice`) and `#6` (`gcc/p1-seller-platform-admin-mfa`, `e4d7d029`, on #4's branch).

Older pull requests touched today, each by one fast-forward push with the leak guard clean: `#13` (`516881c5`) and `#35` (`7c75b7f4`), `#20` (`75afe858`), `#21` (`af862ff3`), `#22` (`20a99a50`), `#23` (`59aa9789`), `#24` (`eebb5884`). The handoff branch carries the guide and plan.

## CI, as read on 2026-11-17

- `Deployment overlay (artifact, deploys nothing)` is red on every branch cut from `main`: the server runs `698aa1e` and `main` is 395 commits ahead. Not bypassed. The 17 files an overlay cannot carry are in the plan, appendix B.
- Every lavkoo head above is green except that job: #71, #72, #74, #76, #77, #79, #80, #82, #84, #13, #75, #81, and #83 at its final head. #78 shows one MySQL job cancelled by the baseline's 25-minute timeout; #81 is the same tree with a 45-minute timeout and is green.
- The CI-only integration #83 found one real cross-pull-request failure that no single pull request shows (#76 pins the callers of `ProductCountry::newProductAttributes()`, #74 adds a fourth) and it is fixed on #76. At the final head the Catalog (329 tests), StaffScope (458) and Storefront with platform admin (1,179) groups pass on MySQL 8 and MariaDB 11.4.2.
- The long job `Sales-channel and cost tests, MySQL 8.0 (sales channels A-M)` takes 50 to 85 minutes on shared runners; the ceiling on current branches is 110 minutes. It was cancelled at the ceiling once (#77) and passed on re-run.
- `main` (`c5c04b7b`) is itself red: `TorodShipmentDiscoveryTest` uses a fixed purchase time that is now more than 36 hours old. The one-line repair is `7f5c4906` (in #1). It is carried in the older pull requests that needed it (#2 to #6, #8, #9 and #11 among them) and, today, in #20 to #24, whose logs showed the same single failure.
- Real reds found today by a sweep of all open pull requests and fixed: #13 and #35 (`ConcurrentOperationsAreRegisteredTest` did not list `wallet.pay_edit_due`, which `Operations::run()` answers); #20 (`SellerApiOrderScopingTest` never emptied the database, so on MySQL and MariaDB every test after the first failed with "Table already exists"; it now empties a throwaway test database first, with the same name and environment guard the schema builders use).
- Not fixed, one flake: #36, MariaDB concurrency group, `WalletBalanceCurrencyConcurrencyTest::test_two_simultaneous_wallet_orders_for_the_whole_balance_both_pass_the_check_and_can_currently_overdraw_the_wallet` failed once on 2026-11-15 and passed on re-run. It pins the outcome of a race, so it depends on timing. Making it deterministic is a follow-up.
- Seller app: `analyze-and-test` is red on #1, #3 and #4 for one test only, `test/app_version_gate_test.dart` (it pins a backend minimum of 2.0.1 against the shipped 1.0.0+3). Untouched, see the guide's section on the version gate. #5 and #6 are stacked, so the workflow does not run for them (it runs on pull requests to `main` and on pushes to `claude/**`); their evidence is the clean-export run in each body.
- Customer app: the workflow runs a scoped analyze and the storefront tests on pushes to `gcc/**`; `lavkooapp#7` is green. `lavkooapp#4` has no workflow run at all.

## Read on 2026-11-17 at about 23:15Z, still running

The pushes of #20, #21, #22, #23, #24 and #35 started full CI runs that were queued behind the integration runs. Read them before trusting them: the expected result is green except the overlay job. Any other red is a real failure of that head.

## Owner decisions that are still open

The plan's section 6 (14 decisions: the verification codes and `APP_MODE`, route A or B, the delivery-man app before stage 1, the roles that lose access, finance review of #52, and the rest) and the guide's section 7. New since the last handoff: the staff scopes must be switched on before or with the platform API when employees hold grants (#84, decisions 1 to 7); slice 3 hides what cannot be told apart by country, such as the admin wallet (decisions 12 to 17 of #82); GCC-20 needs the MFA policy, recovery-code custody, lockout and audit retention decisions and a security review before any merge; the seller app steps need a native Arabic reader for 43 strings and answers to eleven backend questions.

## What the next session should do

1. Read CI for #20 to #24 and #35 on their current heads; fix a real red, never skip a test, never touch the overlay job or the version gate.
2. Do not report any pending job as green.
3. Keep the stack in sync by merge commits (a change to #71 goes to #79, #80, #84; to #74 to #77, #82, #84).
4. When a base of a seller or customer app pull request merges, retarget it so its CI runs.
5. No merge, deploy, migration or production access without the owner's separate authorization.
