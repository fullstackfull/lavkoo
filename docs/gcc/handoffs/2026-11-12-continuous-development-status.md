# Continuous development handoff — LAVKOO GCC (2026-11-12, status at 14:11Z)

Nothing is merged, deployed or run against production. CI readings are for the head SHA named, at the time shown. A pending run is not reported as green.

Governing text: `docs/gcc/AUTONOMOUS_DEVELOPMENT_CHARTER.md` on `main`. The standing permission covers coding and draft PRs on isolated feature branches. It does not cover merging, production access, migrations on real data, secret or version changes, or deployment.

## Draft PRs (all into `main`, none merged)

| PR | Branch | Head | Scope | CI at read time |
|---|---|---|---|---|
| #1 | `gcc/p0-saudi-baseline-ci` | `d664ab4b` | Torod fixture repair; routing characterization | 18 of 21 passed. 1 failed by design (deployment overlay gate). 2 still running: MySQL A–M and MariaDB strict. |
| #2 | `gcc/p0-admin-coupon-translation-key` | `590ed03c` | Admin minimum message uses the defined key | Queued since 13:50Z |
| #3 | `gcc/p0-remove-duplicate-payment-routing` | `1a4d8730` | Removes two dead duplicate class declarations and adds a guard test. Payment-provider file: needs a security or financial owner review before merge. | Queued since 13:56Z |
| #4 | `gcc/p0-coupon-minimum-boundary` | `e2898afa` | Customer minimum boundary, 12 tests, including product discounts. Review record on the PR. | Queued since 14:03Z for `d4bcea03`; re-queued for `e2898afa` |
| #5 | `gcc/p0-missing-message-keys` | `ecef6647` | Defines two missing messages in seven locales, with no wording change | Queued since 14:06Z |

Audit branch (docs only): the seller verification report at `docs/gcc/audits/2026-11-12-seller-version-verification.md`, and this handoff.

## Decisions for the owner (none blocks the other work)

1. **Seller version and force-update gate.** Needed: the live `seller_app_version_control` value, the published Play and App Store versions and build numbers, and the rollout state. Options A, B and C are set out in the audit report. No version change has been made.
2. **Deployment overlay gate on PR #1.** The diff from baseline `698aa1e` includes `robots.txt`, `firebase-messaging-sw.js` and `public/firebase-messaging-sw.js`. The gate asks for a deliberate manual deployment of those files.
3. **PR #3, payment-provider file.** A security or financial owner should confirm that the discarded `Payment/PaymeraController.php` copy has no logic worth keeping. The kept copy is the one the live routes use.
4. **Admin minimum copy.** The English text says "greater than", but the code accepts a discount equal to the minimum. The wording is stricter than the rule. Unchanged in PR #2.
5. **Translations for the two keys in PR #5.** They are defined with the English text the app already shows. Arabic and other translations are a translator decision.

## Known gaps, not fixed

- The `coupons` table in those tests is inferred. The live schema is not in the repository.
- The empty-cart path (PR #5) is covered for its message and for the no-write guard. Its wording is not reviewed.

## Evidence summary

- **Torod test:** fails at `TorodShipmentDiscoveryTest.php:198` on the real clock. Passes 12 of 12 with the fixture stamped 10 hours ago, both on the real clock and pinned to 2030.
- **Routing characterization:** 7 of 7. Reversing the order fails 3 tests. Removing de-duplication fails 1.
- **Coupon boundary (PR #4):** 12 of 12. Changing `min_purchase >` to `>=` fails four tests, including the discounted equal case. Ignoring the product discount fails both discount tests. Each endpoint test asserts one analytics record.
- **Admin key (PR #2):** on `main`, the test run wrote into `en` and `sa` `new-messages.php`, and the Arabic admin received English. After the fix, 4 of 4 pass.
- **Missing messages (PR #5):** on `main`, all three tests fail. With the entries, all three pass.
- **Duplicate classes (PR #3):** the guard fails on `main` with two duplicated names, and passes after the change. The optimized classmap is unchanged. The full Unit suite passes, 1116 tests.
- **Independent review of PR #4's first version:** 3 of 11 findings survived verification: a teardown without `finally`, an inert recorder stub, and product discounts never exercised. All three are addressed, in `d4bcea03` and `e2898afa`. The record is on the PR.

## How another Claude account resumes

1. Fetch `origin`. Read the charter and `docs/gcc/DECISIONS.md` on `main`.
2. Check PR states and heads from the table above. Do not rely on memory of CI.
3. To test a branch, run it in an isolated copy: `git archive <branch> | tar -x` into a directory that has a Composer `vendor/`, then `vendor/bin/phpunit <file>`. The leak-guard test needs a git work tree, so run `git init` in the copy first. Do not use the production tree or any production secret.
4. Push one reviewed checkpoint at a time, as a fast-forward, with the remote SHA verified. Never force-push.
5. Next candidates, in the charter's order: wallet and loyalty characterization (report 09, B3, which has zero coverage); payment callback idempotency with fake providers; Arabic translations for the two keys in PR #5 (a translator decision).
