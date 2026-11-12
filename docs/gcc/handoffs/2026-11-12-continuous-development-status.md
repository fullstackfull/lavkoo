# Continuous development handoff — LAVKOO GCC (2026-11-12, status at 16:27Z)

Nothing is merged, deployed or run against production. Each CI reading is for the head SHA named, at the time shown. A run that is still running or queued is not reported as green.

Governing text: `docs/gcc/AUTONOMOUS_DEVELOPMENT_CHARTER.md` on `main`. The standing permission covers coding and draft PRs on isolated feature branches. It does not cover merging, production access, migrations on real data, secret or version changes, or deployment.

## Draft PRs (all into `main`, none merged)

| PR | Branch | Head | Scope | CI, latest reading (run) |
|---|---|---|---|---|
| fullstackfull/lavkoo#1 | `gcc/p0-saudi-baseline-ci` | `d664ab4b` | Torod fixture repair (the only branch that carries it); routing characterization | 20 of 21 passed. 1 failed by design: overlay gate. Complete (last read 15:43Z). (43227034387) |
| fullstackfull/lavkoo#2 | `gcc/p0-admin-coupon-translation-key` | `590ed03c` | Admin minimum message uses the defined key | 15 passed, 6 failed. Torod test in 5 jobs: SQLite 8.2, SQLite 8.3, strict-mode MariaDB, MySQL sales channels N–Z and MariaDB sales channels N–Z. Overlay gate. Complete (read 16:03Z). (43231075495) |
| fullstackfull/lavkoo#3 | `gcc/p0-remove-duplicate-payment-routing` | `1a4d8730` | Removes two dead duplicate classes; adds a guard test | 15 passed, 6 failed. Torod test in the same 5 jobs as fullstackfull/lavkoo#2. Overlay gate, which also refuses this PR's two deletions. Complete (read 16:03Z). (43232156614) |
| fullstackfull/lavkoo#4 | `gcc/p0-coupon-minimum-boundary` | `e2898afa` | Customer minimum boundary: 12 tests, including product discounts | 15 passed, 5 failed, 1 running (read 16:27Z). Torod test in 4 jobs: SQLite 8.2, SQLite 8.3, strict-mode MariaDB and MariaDB sales channels N–Z. Overlay gate. Still running: MySQL 8.0 sales channels N–Z, step started 14:59Z. Its A–M job passed at 16:10Z. (43236431540) |
| fullstackfull/lavkoo#5 | `gcc/p0-missing-message-keys` | `ecef6647` | Defines two missing messages in seven locales; no wording change | 15 passed, 6 failed. Torod test in the same 5 jobs as fullstackfull/lavkoo#2. Overlay gate. Complete (read 16:03Z). (43233763748) |

Audit branch (docs only): the seller verification report at `docs/gcc/audits/2026-11-12-seller-version-verification.md`, and this handoff.

## Failures inherited from `main`

Both failures come from `main`'s state, not from the code in these PRs. The overlay refusal for fullstackfull/lavkoo#3 also includes its own two deletions, which decision 5 covers.

1. **Torod fixture.** `tests/Feature/SalesChannels/TorodShipmentDiscoveryTest.php:198` fails once the fixed purchase time, 2026-11-10 09:00, is more than 36 hours old. That is now true. Main run 43122772278 was red in five jobs, including both SQLite suites. Every draft PR cut from `main` fails it in four or five jobs. Each failing log shows one failure, the same assertion (`'unlinked'` expected, `'needs_review'` actual). Fifteen jobs have failed this way since 14:33Z. The one-line repair is `7f5c4906`, which is only on fullstackfull/lavkoo#1. On that branch the full SQLite suite passes.
2. **Overlay gate.** Every draft PR is refused for `robots.txt`, `firebase-messaging-sw.js` and `public/firebase-messaging-sw.js`. None of the PRs changes those files, so they differ between baseline `698aa1e` and `main`.

## Decisions for the owner (none blocks the other work)

1. **Overlay gate.** Confirm whether `robots.txt` and the two Firebase service-worker files are already live. If they are, the overlay baseline can move past them. If not, they need a deliberate manual deployment, and the overlay stays red until then. Either way it is a deployment decision, and no branch can clear it alone.
2. **Torod fixture on `main`.** `main` has the time-dependent fixture, so its full suite fails there too. On fullstackfull/lavkoo#2, fullstackfull/lavkoo#3 and fullstackfull/lavkoo#5 it fails in five jobs each, and on fullstackfull/lavkoo#4 in four so far. Options: (A) merge fullstackfull/lavkoo#1 once decision 1 is made; its only red job is the overlay gate. (B) Approve adding the same one-line repair to fullstackfull/lavkoo#2 to fullstackfull/lavkoo#5 as test-only commits. B clears the Torod failure on those branches but adds four full CI matrices to a busy queue. Recommendation: A, so the fix lands on `main` once.
3. **`CLAUDE.md` changes on merge.** Merging fullstackfull/lavkoo#1 or fullstackfull/lavkoo#4 also changes `CLAUDE.md` on `main`. The section in fullstackfull/lavkoo#4 is headed "THIS BRANCH ONLY", and the owner approval limits that exception to the branch. Drop it or scope it before any merge of that PR.
4. **Seller version and force-update gate.** The live `seller_app_version_control` value, the published Play and App Store versions and build numbers, and the rollout state are needed. Options A, B and C are set out in the audit report. No version change has been made.
5. **Payment-provider duplicates (fullstackfull/lavkoo#3).** A security or financial owner should confirm that the discarded `app/Http/Controllers/Payment/PaymeraController.php` copy has nothing worth keeping. The kept copy is the one the live routes use. The overlay lists both `PaymeraController.php` and `app/Services/PaymentRoutingService.php` as deleted, and says both must be removed by hand after a deployment.
6. **Admin minimum copy.** The English text says "greater than", but the code accepts a discount equal to the minimum. Unchanged in fullstackfull/lavkoo#2.
7. **Translations for the two keys in fullstackfull/lavkoo#5.** They use the English text the app already shows. Other languages are a translator decision.

## Known gaps, not fixed

- The `coupons` table in fullstackfull/lavkoo#4's tests is inferred. The live schema is not in the repository.
- The empty-cart path in fullstackfull/lavkoo#5 is covered for its message and its no-write guard. Its wording is not reviewed.
- PHPUnit reports deprecations in the CI runs (1, 8 or 9 per job). The logs print only the totals, so they are not investigated.
- Still running at 16:27Z: fullstackfull/lavkoo#4, MySQL 8.0 sales channels N–Z. That job's test step started at 14:59Z, about 88 minutes ago. The same job took 41 to 48 minutes on the other draft PRs. Re-read before reporting it.

## Evidence summary

- **New CI failures since 14:33Z:** 15 jobs across fullstackfull/lavkoo#2 to fullstackfull/lavkoo#5. Each log was read and its failure block quoted. A critic check confirmed the 14 read by the workflow. The fifteenth, the strict-mode job of fullstackfull/lavkoo#5, was read directly. All 15 show `Failures: 1` and the Torod assertion at `TorodShipmentDiscoveryTest.php:198`.
- **Torod test:** fails at `TorodShipmentDiscoveryTest.php:198` on the real clock. Passes 12 of 12 with the fixture stamped 10 hours ago, both on the real clock and pinned to 2030.
- **Routing characterization (fullstackfull/lavkoo#1):** 7 of 7. Reversing the order fails 3 tests. Removing de-duplication fails 1.
- **Coupon boundary (fullstackfull/lavkoo#4):** 12 of 12 in the sandbox. Changing `min_purchase >` to `>=` fails four tests, including the discounted equal case. Ignoring the product discount fails both discount tests. Each endpoint test asserts one analytics record.
- **Full SQLite suite on fullstackfull/lavkoo#2 (run 43231075495):** 6204 tests, 97 skipped, one failure, the Torod test. The admin-message tests passed in that run.
- **Admin key (fullstackfull/lavkoo#2):** on `main`, the test run wrote into `en` and `sa` `new-messages.php`, and the Arabic admin received English. After the fix, 4 of 4 pass.
- **Missing messages (fullstackfull/lavkoo#5):** on `main`, all three tests fail. With the entries, all three pass.
- **Duplicate classes (fullstackfull/lavkoo#3):** the guard fails on `main` with two duplicated names and passes after the change. The optimized classmap is unchanged. The full Unit suite passes, 1116 tests.
- **Independent review of fullstackfull/lavkoo#4's first version:** 3 of 11 findings survived verification: a teardown without `finally`, an inert recorder stub, and product discounts never exercised. All three are addressed in `d4bcea03` and `e2898afa`. The record is on the PR.

## How another Claude account resumes

1. Fetch `origin`. Read the charter and `docs/gcc/DECISIONS.md` on `main`.
2. Check PR states and heads from the table above. Do not rely on memory of CI.
3. To test a branch, run it in an isolated copy: `git archive <branch> | tar -x` into a directory that has a Composer `vendor/`, then `vendor/bin/phpunit <file>`. The leak-guard test needs a git work tree, so run `git init` in the copy first. Do not use the production tree or any production secret.
4. Push one reviewed checkpoint at a time, as a fast-forward, with the remote SHA verified. Never force-push.
5. Next candidates, in the charter's order: wallet and loyalty characterization (report 09, B3, which has zero coverage); payment callback idempotency with fake providers; Arabic translations for the two keys in fullstackfull/lavkoo#5 (a translator decision).
