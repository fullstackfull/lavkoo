# Continuous development handoff — LAVKOO GCC (2026-11-12, Asia/Kuwait)

Status is written as of 13:58Z. Nothing here is merged, deployed, or run against production. CI results are stated only for the head SHA named, and pending runs are not reported as green.

Governing text: `docs/gcc/AUTONOMOUS_DEVELOPMENT_CHARTER.md` on `main` (c5c04b7b). Standing permission covers coding and draft PRs on isolated feature branches. It does not cover merging, production access, migrations on real data, secret or version changes, or deployment.

## Work items

| Item | Branch | Head | PR | State |
|---|---|---|---|---|
| Saudi baseline: Torod fixture repair and routing characterization | `gcc/p0-saudi-baseline-ci` | `d664ab4b` | #1 (draft) | CI on `d664ab4b` at 13:52Z: 14 passed, 1 failed by design (deployment overlay gate), 6 still running. Not green yet. |
| Coupon minimum boundary, customer channels (`>=`) | `gcc/p0-coupon-minimum-boundary` | pending | pending | Test written: 10 tests, pass in sandbox, mutation-checked. Independent review running; fixes from the standards review are in a revised file, not yet committed. |
| Admin coupon message uses the defined translation key | `gcc/p0-admin-coupon-translation-key` | `590ed03c` | #2 (draft) | Defect reproduced on `main`; fix passes 4/4 in sandbox. CI queued at 13:50Z. |
| Remove two dead duplicate class declarations and guard against new ones | `gcc/p0-remove-duplicate-payment-routing` | `1a4d8730` | #3 (draft) | Guard fails on `main`, passes after; classmap unchanged; Unit suite 1116 tests OK. Payment-provider file: needs security or financial owner review before merge. CI pending. |
| Seller app version and gate verification (read-only) | `claude/exciting-shannon-4mh2ok` (audit) | `1cd43a17` | none | Report committed as docs only. No version, gate or backend change. Published versions and live minimum are UNKNOWN. |

## Decisions the owner must make (not blocking other work)

1. **Seller version and force-update gate.** The live `seller_app_version_control` value, published Play and App Store versions and build numbers, and rollout state are needed. Options A, B and C are set out in the audit report.
2. **Deployment overlay gate on PR #1.** The diff from baseline `698aa1e` includes `robots.txt`, `firebase-messaging-sw.js` and `public/firebase-messaging-sw.js`. The gate says these need a manual, deliberate deployment.
3. **Payment-provider duplicate removal (PR #3).** A security or financial owner should confirm the discarded `Payment/PaymeraController.php` copy has no logic worth keeping. The kept copy is the one the live routes use.
4. **Copy decision for the admin minimum message.** The English text says "greater than"; the code accepts equality. The wording is stricter than the rule. Unchanged in PR #2.

## Known defects not yet fixed (each needs a translation or copy decision)

- The percentage refusal key `when_discount_type percentage,discount_amount_will_be_less_than_100` (`app/Services/CouponService.php:126`) is undefined in every locale. Each refusal writes it into the language files at runtime.
- The empty-cart key `Please_add_item_to_cart` (`app/Utils/OrderManager.php:551`) is undefined in every locale. The same write happens for an empty checked cart on both channels.

Both are the same defect class as PR #2. Fixing them means adding entries to seven locale files, and the Arabic text is a translator decision.

## Evidence summary

- **Torod test:** fails at `TorodShipmentDiscoveryTest.php:198` (`needs_review` vs `unlinked`) on the real clock. Passes 12/12 with the fixture stamped 10 hours ago, both on the real clock and pinned to 2030.
- **Routing characterization:** 7/7 in the sandbox. Reversing the gateway order fails 3 tests; removing de-duplication fails 1.
- **Coupon boundary:** 10/10 in the sandbox. Changing `min_purchase >` to `>=` fails exactly the three equal-to-minimum tests. The language-file digest guard fires when a key is missing.
- **Admin message:** on `main`, the test run wrote `resources/lang/en/new-messages.php` and `sa/new-messages.php`, and the Arabic admin received English. After PR #2, 4/4 pass and no language file changes.
- **Duplicates:** the guard fails on `main` with two duplicated names. Composer's optimized classmap maps both names to the kept files before and after. The full Unit suite passes after removal.

## How another Claude account resumes

1. Fetch `origin`. Read the charter and `docs/gcc/DECISIONS.md` on `main`.
2. Check PR states and heads with the table above. Do not rely on memory of CI.
3. For any branch, run tests in an isolated copy: `git archive <branch> | tar -x` into a directory that has a Composer `vendor/`, then `vendor/bin/phpunit <file>`. The leak-guard test needs a git work tree, so run `git init` in the copy first. Do not use the production tree or any production secret.
4. Push one reviewed checkpoint at a time, as a fast-forward, with the remote SHA verified. Do not force-push.
5. Open the next independent task. Candidates, in the order the charter lists them: wallet and loyalty characterization (report 09, B3, currently zero coverage); payment callback idempotency with fake providers; the missing-key entries listed above, once the translation decision is made.
