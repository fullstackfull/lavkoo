# Autonomous development handoff — LAVKOO GCC (2026-11-13, status at 21:14Z)

Scope of this session: resume branch development under `docs/gcc/AUTONOMOUS_DEVELOPMENT_CHARTER.md`. Nothing was merged, deployed or run against production. No production data was read or changed, and no migration ran on real data.

## 1. What changed in this session

| Item | Branch | Commit | What |
|---|---|---|---|
| Torod repair carried | `gcc/p0-admin-coupon-translation-key` | `1ca1c4d8` | One line in `TorodShipmentDiscoveryTest.php`, carried from `7f5c4906` on `gcc/p0-saudi-baseline-ci` |
| Torod repair carried | `gcc/p0-remove-duplicate-payment-routing` | `24cc1033` | Same one-line change |
| Torod repair carried | `gcc/p0-coupon-minimum-boundary` | `1d000dfa` | Same one-line change |
| Torod repair carried | `gcc/p0-missing-message-keys` | `cf7d3e28` | Same one-line change |
| Wallet ledger characterization | `gcc/p0-saudi-wallet-characterization` | `0a4bb27d` | New test file, 7 tests. Draft PR `fullstackfull/lavkoo#6` |
| PR #3 deletion review | comment | — | `fullstackfull/lavkoo#3`, issue comment `6865070200` |

Each Torod commit was a fast-forward push, and the remote head matched the local commit after each push. No force push was used, and no other branch was changed. The Torod repair was carried to these branches because the owner's instruction for this session allows moving the fix safely.

## 2. PR and CI state

- `fullstackfull/lavkoo#1` (`d664ab4b`): 20 of 21 jobs passed. The only red job is the overlay gate, by design. Complete.
- `fullstackfull/lavkoo#2`, `#3`, `#4`, `#5`: each now carries the Torod repair. CI re-runs were triggered by the pushes and are still pending, so they are not reported as green. Before the repair, each had five Torod-failing jobs. The expected result after re-run is that only the overlay gate stays red. Verify when the runs complete.
- `fullstackfull/lavkoo#4` (`e2898afa`): the 16:48Z cancellation of its MySQL sales-channel N–Z job was on the old head. The new head's run replaces it.
- `fullstackfull/lavkoo#6` (`0a4bb27d`): new draft. Its CI runs the full suite, including the new tests.

## 3. Deployment overlay: root cause, no baseline change

- The overlay job builds from `BASELINE: ${{ vars.PILOT_BASELINE || '698aa1e' }}` in `.github/workflows/ci.yml`. The repository variable is not set. The job logs print `BASELINE: 698aa1e`.
- The gate refuses any release whose diff from `698aa1e` changes files outside the shipped directories. Three files do that: `robots.txt`, `firebase-messaging-sw.js` and `public/firebase-messaging-sw.js`.
- Those files changed in commit `352ed688` (2026-11-11, "Rebrand to Lavkoo and fix server deployment"), which is already on `main`. The same commit changes the Firebase web configuration in both service-worker files, pointing them at the `lavkoo-380f1` project.
- The CI comment says the baseline moves only after a verified deployment: set `PILOT_BASELINE` to the `head` recorded in the deployed package's `MANIFEST.json`. This session has no access to the pilot server, so the baseline was left unchanged.
- Owner decision: confirm whether `352ed688`'s three files are live on the pilot server. If they are, set `PILOT_BASELINE` to the live head and the gate passes for the release. If they are not, deploy those three files by hand, after confirming that the Firebase configuration is meant for production push notifications.

## 4. PR #3 deletion review

- Safe with respect to code references. The root `app/Services/PaymentRoutingService.php` is byte-identical to `Marketplace/PaymentRoutingService.php`, and nothing refers to the root copy. The deleted `app/Http/Controllers/Payment/PaymeraController.php` declares the same class as the live `Payment_Methods/PaymeraController.php`. Nothing refers to the deleted path, and its four `dd()` calls stop execution.
- Not changed by PR #3, and needs the payments owner's decision. The live `Payment_Methods/PaymeraController.php` writes the full request payload and hook traces to `storage/logs/paymera_callback_test.txt` and `storage/logs/paymera_hook_test.txt` on each callback (four writes in `callback()`). It also holds a hard-coded `X-Secret-Key` header value in two places. The value is not recorded here.
- Merging PR #3 still needs the payments owner's sign-off.

## 5. Coupon rule

Unchanged. `eligibleSubtotal >= min_purchase` is pinned by the 12 tests in `fullstackfull/lavkoo#4`. No code in the coupon path changed this session.

## 6. Translation tests

`fullstackfull/lavkoo#4` (digest guard) and `#5` (`CouponMessageKeysTest`) do not write language files. The full-suite runs on `#2` and `#5` showed only the Torod failure, so the translation tests passed, and the digest guard would fail a test if a language file changed.

## 7. Seller app version (read-only)

`lavkooappseller` `main` is `d556fb4`. `pubspec.yaml` declares `1.0.0+3`. `test/app_version_gate_test.dart` holds `kBackendPublishedMinimumVersion = '2.0.1'`. The remote has no tags. The live backend minimum and the published store versions are not in the repository. Nothing was changed. The owner must supply the live values, as the audit report already requests.

## 8. Saudi protection: wallets (PR #6)

The tests characterize `App\Repositories\WalletTransactionRepository::addWalletTransaction()`. Findings from reading the code:

- Three places write a wallet balance and each has its own copy of the rules: the repository; `App\Utils\CustomerManager::create_wallet_transaction()` (REST v1 `pay_by_wallet` and POS); and `App\Utils\OrderManager::createWalletTransaction()`. They use different bonus helpers.
- The repository accepts a debit above the balance. The REST v1 `pay_by_wallet` path checks the balance first (`RestAPI/v1/OrderController.php:444`).
- The ledger's `balance` column leaves out the add-fund bonus, which `users.wallet_balance` includes.
- The balance is read before the database transaction opens, and written back with no row lock. This is a likely lost update when two debits run at once. It is not yet reproduced.

## 9. Not started in this session

- Loyalty points (`addLoyaltyPointTransaction` path), refunds (`order_refund`), inventory, and seller/admin permission tests.
- The six-country foundation (country and store context), the Flutter Customer and Seller apps, and the Platform Super Admin.

## 10. Next tasks, in order

1. Prove the wallet lost update on MySQL in the existing concurrency harness. If it is confirmed, open a fix PR that takes a row lock and adds a regression test.
2. Characterize loyalty points and refunds, using the same method as PR #6.
3. Payment callback hardening, after the payments owner approves: remove the four debug writes, and move the header value to configuration once its correct value is confirmed.
4. Inventory and seller/admin permission tests.
5. Six-country foundation, once `docs/gcc/DECISIONS.md` confirms the design.

## 11. Owner decisions outstanding

1. Overlay: whether the three files from `352ed688` are live, and the `PILOT_BASELINE` value after any deployment.
2. Torod: merge `fullstackfull/lavkoo#1` (recommended). The repair is already carried to `#2`–`#5`.
3. Ledger `balance` should include the add-fund bonus? (PR #6, test 2.)
4. Should the repository refuse debits above the balance itself? (PR #6, test 1.)
5. Paymera callback: remove the debug writes, and rotate and relocate the header value (payments owner).
6. `fullstackfull/lavkoo#3`: payments sign-off before merge.
7. `fullstackfull/lavkoo#4`: scope or drop its `CLAUDE.md` section before any merge.
8. Seller app: live minimum and published store versions.

## 12. CI re-read of the draft PRs (2026-11-13, 22:31Z)

Scheduled re-read of `fullstackfull/lavkoo#2` to `#6`. Each run has 21 jobs, and the log of every failed job was read. The stored head for `#6` was stale: the head is `5c4b3e66`, not `0a4bb27d`. All other stored heads matched.

| PR | Head | Run | Failed job (log read) | Completed, success | In progress | Queued |
|---|---|---|---|---|---|---|
| `fullstackfull/lavkoo#2` | `1ca1c4d8` | `43507739560` | Deployment overlay | 20 | 0 | 0 |
| `fullstackfull/lavkoo#3` | `24cc1033` | `43507754123` | Deployment overlay | 12 | 6 | 2 |
| `fullstackfull/lavkoo#4` | `1d000dfa` | `43507775762` | Deployment overlay | 14 | 4 | 2 |
| `fullstackfull/lavkoo#5` | `cf7d3e28` | `43507803019` | Deployment overlay | 16 | 3 | 1 |
| `fullstackfull/lavkoo#6` | `5c4b3e66` | `43513155172` | Deployment overlay | 5 | 3 | 12 |

- The overlay gate is red on all five heads. Each log names `firebase-messaging-sw.js`, `public/firebase-messaging-sw.js` and `robots.txt` against baseline `698aa1e`. That is the known cause in section 3, not a new one.
- `24cc1033` (`fullstackfull/lavkoo#3`) also lists two deletions: `app/Http/Controllers/Payment/PaymeraController.php` and `app/Services/PaymentRoutingService.php`. The gate never deploys a deletion, so this release needs a manual removal step. Section 4 covers why the deletions are safe in code.
- No job failed on any head except the overlay gate. On `fullstackfull/lavkoo#2`, all 20 other jobs completed with success, including the full SQLite suite that contains the Torod test that failed before the repair.
- Jobs still running or queued on `fullstackfull/lavkoo#3` to `fullstackfull/lavkoo#6` are not green. They are unresolved and need a later re-read.
- No new failing test, so no PR comment was posted in this pass.
- Nothing merged, `main` untouched, production not touched.

## 13. CI re-check of the draft PRs (2026-11-13, about 22:55Z)

Scheduled re-check. Remote heads at the check: `fullstackfull/lavkoo#6` `5c4b3e66`; `fullstackfull/lavkoo#7` `603c2a49` (it was `b2ff9be9` at scheduling); `fullstackfull/lavkoo#8` `adf923ce`; `fullstackfull/lavkoo#9` `41454349`; `fullstackfull/lavkoo#10` `4015ae17`. Each latest run has 21 jobs. The log of every failed job was read.

| PR | Failed jobs (log read) | Completed, success | In progress | Queued |
|---|---|---|---|---|
| `fullstackfull/lavkoo#6` (`5c4b3e66`) | Deployment overlay only | 8 | 2 | 10 |
| `fullstackfull/lavkoo#7` (`603c2a49`) | Deployment overlay only | 3 | 5 | 12 |
| `fullstackfull/lavkoo#8` (`adf923ce`) | Deployment overlay; `Tests, SQLite (PHP 8.2)` | 6 | 2 | 11 |
| `fullstackfull/lavkoo#9` (`41454349`) | Deployment overlay; `Tests, SQLite (PHP 8.3)` | 8 | 3 | 8 |
| `fullstackfull/lavkoo#10` (`4015ae17`) | Deployment overlay only | 6 | 4 | 10 |

- The overlay gate is red on all five. Its log names only the three known files (`firebase-messaging-sw.js`, `public/firebase-messaging-sw.js`, `robots.txt`), so it is the known cause from section 3.
- `Tests, SQLite` failed on `fullstackfull/lavkoo#8` (PHP 8.2) and on `fullstackfull/lavkoo#9` (PHP 8.3) with the same single failure: `TorodShipmentDiscoveryTest::test_the_scheduled_run_links_what_appeared_later_and_keeps_the_status_of_a_linked_shipment_current`, expected `unlinked`, got `needs_review`, at line 198. The fixture's purchase time comes from the clock. The failure reproduces locally on `adf923ce` and on `41454349`.
- The fix is the one-line repair from `5c4b3e66`, which sets the purchase to ten hours ago before the scheduled run. It is carried as `a8c6a42b` on `fullstackfull/lavkoo#8` and as `bad99c78` on `fullstackfull/lavkoo#9`. Both are fast-forward pushes. The test passes locally after the repair. No assertion was changed. The new runs on those two heads are queued, so they are not reported green.
- `fullstackfull/lavkoo#10` has the same fixture and no repair yet. Its `Tests, SQLite (PHP 8.3)` job is still in progress with no completed log. If it fails on the same assertion, the same one-line repair will be carried to `fullstackfull/lavkoo#10` as a fast-forward commit.
- `fullstackfull/lavkoo#6` and `fullstackfull/lavkoo#7` have no test failure so far. Their remaining jobs are in progress or queued.
- Nothing merged, `main` untouched, production not touched. No PR comment was posted: each failure was either the known gate or fixed by a commit.

## 14. Wallet overdraft chain, POS ordering and draft PRs (2026-11-13, about 23:00Z)

Opened as draft PRs, stacked. None is merged or deployed.
- `fullstackfull/lavkoo#11`: the overdraft guard (`gcc/p0-wallet-overdraft-guard`, `692950b5`), based on `gcc/p0-wallet-concurrency-lock`.
- `fullstackfull/lavkoo#12`: checkout and return credit honour a refused debit (`gcc/p0-wallet-debit-result-handling`, `4b31fabd`), based on the guard.
- `fullstackfull/lavkoo#13`: POS sales and edited-order due payments are atomic and lock in product order (`gcc/p0-pos-wallet-atomicity`, `d93cafae` and `4f510ae3`), based on the guard.

Merge rule: `fullstackfull/lavkoo#11` must not merge alone. A caller that ignores a refusal would create an order that was never paid for. `fullstackfull/lavkoo#12` and `fullstackfull/lavkoo#13` are the callers that honour the refusal. Merge all three together, or none.

Review:
- `4b31fabd`: independent review approved with notes. A deadlock inside the nested wallet writer surfaces as a 500 or a false refusal; the fix (rethrow concurrency errors from `CustomerBalanceLock` when nested) is not done. The seller-panel return credit never fires because the request has no customer; this is base behaviour and an owner decision. The checkout debit amount is computed separately from the order total; owner decision.
- `d93cafae`: approved.
- `4f510ae3`: accepted with notes. The stock-order test fails before the change (updates ran 3, 2, 1) and passes after. Its SQL matcher is SQLite-specific, so it is not in a MySQL group.

Not yet reported: the MySQL and MariaDB concurrency tests for the guard, checkout and POS paths (`WalletOverdraftConcurrencyTest`, `WalletCheckoutConcurrencyTest`, `OrderEditDueConcurrencyTest`). All are pending CI.

Branch names: the internal work names `gcc/p0-ov-impl1` and `gcc/p0-ov-impl2` were renamed before the push to `gcc/p0-wallet-debit-result-handling` and `gcc/p0-pos-wallet-atomicity`.

Still in progress, not pushed:
- `gcc/p0-wallet-loyalty-ci-coverage` at `0f794153`, awaiting review.
- `gcc/p0-loyalty-refund-characterization` and `gcc/p0-order-owner-is-guest-scope`, still being implemented.

Not started: RMA receipt idempotency, checkout stock tests and the GCC storefront alias (second batch); seller and vendor order scoping, the remaining web due-payment ownership check, and the gateway replay guard (after the wallet chain).

## 15. Loyalty chain restacked, CI coverage and draft PRs 14 to 16 (2026-11-13, about 23:15Z)

This section supersedes the note in section 13 that PR 10's SQLite 8.3 job had no log yet. The job had completed with two failures.

- `fullstackfull/lavkoo#10` is now at `4e860229`. Two failures were fixed:
  - The Torod clock fixture. The branch was restacked on the wallet branch head `bad99c78` with a merge (`1df11ab0`), so it now contains the repair.
  - `ConcurrentOperationsAreRegisteredTest`. The new loyalty concurrency test asks for `loyalty.convert`, which the harness answers but the test's built-in list did not name. The list now names the wallet and loyalty match arms (`4e860229`). The affected classes pass locally (15 tests). CI for `4e860229` is pending.
- Draft PRs opened:
  - `fullstackfull/lavkoo#14`, CI coverage for the wallet and loyalty tests (`062c25fb`, stacked on `gcc/p0-loyalty-balance-lock`). The reviewer rejected the first commit: its trailer named a model, and the stack precondition failed. The message is amended, and the branch is rebased on `4e860229`. Local tests pass (46). The rebased commit has not been independently reviewed.
  - `fullstackfull/lavkoo#15`, refund and loyalty characterization tests and the proposal document (`d5206691`, stacked). The reviewer accepted with notes. The four MySQL race tests are skipped on purpose until the owner decides.
  - `fullstackfull/lavkoo#16`, customer order ownership with `is_guest` (PERM-04), based on `main` (`637196e1`). The reviewer rejected it for a model name in the trailer (amended), and for a web invoice hunk outside the scope list. Decision: keep the web `generate_invoice` hunk, because the commit message describes it and the defect is the same. The PR body says so, and the owner can drop it. 12 of 15 regression cases fail on `main`.
- Commit messages: the model-name trailer was removed from the new commits (`0f794153`, `c9f4b2d4`). The earlier commits on these branches were already clean.
- Second batch, implemented locally and not pushed: RMA receipt idempotency (`5760bad6`), checkout stock tests (`5c680ea8`, reviewer accepted with notes), and the GCC storefront alias (`6f568043`, reviewer accepted with notes). The alias is based on `a8c6a42b`, which carries the Torod repair.
- Main-based branches (`fullstackfull/lavkoo#16` and the second batch) do not carry the Torod repair. They get it only if their logs show the same assertion.
- Disk: the Composer download cache (8.6 GB) was cleared. The session allowance had reached 100% during a test run. Run fewer test processes at once.
- Owner decisions open: the PERM-04 web invoice scope (`fullstackfull/lavkoo#16`); whether a failing guard should report once; the wallet and loyalty idempotency keys; the items listed in section 11.

## 16. Second batch pushed as draft PRs 17 to 19 (2026-11-13, about 23:20Z)

This supersedes the note in section 15 that the second batch was not pushed. Three new branches were pushed without force; remote heads match local.

- `fullstackfull/lavkoo#17`: RMA receipt idempotency (`gcc/p0-rma-receive-idempotency`, `cc35d611`), based on `main` (`c5c04b7b`). A partial return received twice restocked twice. The return row is now re-read under `lockForUpdate` and re-checked inside the transaction. The variant qty rises with the restock (INV-01, INV-03). No money, refund or schema change.
- `fullstackfull/lavkoo#18`: checkout stock tests (`gcc/p0-checkout-stock-tests`, `28cd8725`), based on `main`. Test-only. Covers product and variant shortfall, `allow_oversell`, unknown policy, whole-cart refusal and id-ordered locks. Adds a MySQL/MariaDB concurrency test that skips on SQLite.
- `fullstackfull/lavkoo#19`: storefront alias (`gcc/p1-storefront-middleware-alias`, `6f568043`), stacked on `gcc/p1-storefront-context` (`fullstackfull/lavkoo#8`, `a8c6a42b`). Not on any route, so Saudi behaviour is unchanged.

Commit messages: the `Co-Authored-By` line naming a model was removed (`5760bad6` became `cc35d611`, `5c680ea8` became `28cd8725`). The `Claude-Session` trailer is kept. No model names in pushed commits, PR titles or PR bodies.

Local verification, SQLite, PHP 8.3.6, PHPUnit 11.5.55. All three worktrees were clean afterwards.
- `#17`, `--filter ReturnReceipt`: 5 tests, 23 assertions, OK, 1 skipped (MySQL concurrency).
- `#18`, `--filter 'GenerateOrderStock|CheckoutStockConcurrency'`: 10 tests, 33 assertions, OK, 1 skipped (MySQL concurrency).
- `#19`, `--filter Storefront`: 230 tests, 5226 assertions, OK, 1 skipped (cause not inspected).
- Not run locally: every MySQL 8.0 and MariaDB 11.4.2 concurrency test. The worktrees have no working DB credential. CI is the authority.

Status: all three are drafts. Each got accept-with-notes from an independent reviewer, with 0 blocking. CI is pending on all three and is not green.
- `fullstackfull/lavkoo#8` (base of `#19`) at `a8c6a42b`: when read at opening, 4 checks succeeded, 2 in progress, 15 queued, none failed. `#19` stays unproven until `#8` is green.

Open items, none blocking:
- `#19`: `ResolveStorefront` passes the raw `storefront` query value into a `?string` parameter. An array input throws a TypeError (HTTP 500). Unreachable today. Fix before any route adopts the alias.
- `#17`: `variationWithReturnedUnits` uses `array_map`, which keeps JSON keys. Review says production data is a list, so it is harmless. Unchanged.
- `#18`: the lock-order assertion in `GenerateOrderStockTest` uses SQLite identifier quoting. It fails if that class runs on MySQL. Test-only fix if CI shows it.

Next: CI re-check of the draft PRs `#6` to `#19`. Carry the Torod repair (`TorodShipmentDiscoveryTest.php:198`) only where a log shows the same assertion. Never report pending jobs as green.

## 17. CI re-check, Torod carry, PR 9 fix propagated, new tracks (2026-11-14, about 00:05Z)

Status changes since section 16. Remote heads are the authority. The list at the end gives the heads at the time of writing.

Completed
- `fullstackfull/lavkoo#9` fix: `CustomerBalanceLock::apply()` rethrows deadlocks and lock-wait timeouts after the rollback, instead of returning `false`, which the callers read as a refusal. Commit `f2866840`. Reviewer verdict: accept with notes. Verified locally: `WalletConcurrencyTest` on MySQL 8.0.46 (4 tests) and `CustomerBalanceLockTest` on SQLite (6 tests).
- Carried into the stack by merge commits, not rebases: `#10`, `#11`, `#12`, `#13`, `#14` and `#15`. `#11` also receives the one-line Torod fixture repair, which `#9` already carries. Verified: `#11` 57 tests OK (10 MySQL-only cases skipped); `#14` 19 tests OK; `#10` `LoyaltyConcurrencyTest` on MySQL 3 of 3.
- `fullstackfull/lavkoo#7`: the one-line Torod repair, cherry-picked from `bad99c78`, pushed as `ad09d6c6`. Log evidence: the only real failure in run 43518357116 was `TorodShipmentDiscoveryTest.php:198`, expected `'unlinked'`, got `'needs_review'`. Reproduced locally before the repair; passes after it. A comment with the evidence is on `#7`.
- `#17` `fb3493dd` (RMA shape characterization, test-only), `#18` `94d82c5f` (lock-query match through the active grammar) and `#19` `74372ebf` (non-string storefront input, default normalization). Each was verified on SQLite and, where relevant, on MySQL 8.0.46 locally. The PR bodies are updated.
- New draft `fullstackfull/lavkoo#20`, based on `main`, head `4a9fa320`: six seller API lookups scoped to `seller_is = 'seller'`. Ten regression tests fail on the base for the leak or crash they name. The diff scan flagged only test fixtures, a dummy bearer-token constant.

In progress when this section was written
- Web due-payment authorization, branch `gcc/p0-web-due-payment-authorization`, base `main`. See S3 and D2.
- API v1 edit-order due-payment ownership, branch `gcc/p0-api-edit-order-due-payment-ownership`, base `main`. See S2 and D1.

CI snapshot, 00:00 to 00:05Z. Authoritative for each head SHA. Many jobs were still queued or running, and pending is not green.
- The only completed failure other than the Deployment Overlay gate was the Torod assertion on `#7`, now repaired.
- The Deployment Overlay job fails on every branch on the known baseline gate: `firebase-messaging-sw.js`, `public/firebase-messaging-sw.js` and `robots.txt`. Not changed here, and `PILOT_BASELINE` is not changed.
- `#16`, `#17` and `#18` are based on `main` and do not carry the Torod repair. Carry it only where their logs show the same assertion.
- Triage note: `--display-skipped` prints skipped tests with messages such as "Needs a real MySQL" and "Only root can give files to another user". In a raw log they look like failures. Count the PHPUnit summary line (`Failures: N`) instead.

Security findings (production exposure; owner and security review required)
- S1. `Helpers::getCustomerInformation` (`app/Utils/Helpers.php`) trusts a client-posted `user` field when no guard user exists. The web and API wallet and digital due-payment paths resolve identity through it. Reproduced on the unchanged code: a posted `user` field debited the owner's wallet (460 against an expected 500). The helper is shared, so it needs a caller inventory before any change. Not changed in this section.
- S2. API v1 edit-order due-payment (`routes/rest_api/v1/api.php` lines 461 to 468, `RestAPI\v1\OrderEditController`) sits behind `apiGuestCheck`, which accepts any valid bearer token or any non-empty `guest_id`. Nothing checks ownership. `duePaymentByCod` and `duePaymentByOfflinePayment` rewrite any order's due-payment record. Track D is in progress.
- S3. Web due-payment (`routes/web/routes.php` line 462, `PaymentController::customerOrderEditPayDueAmount`) has no authentication. Reproduced: unauthenticated offline and cash-on-delivery writes to another customer's order, and a signed-in non-owner spending their own wallet on another order. Track A is in progress.
- S4. Six seller API lookups answered for in-house orders and other sellers' lines. Fixed in `#20`, pending review and CI.

Owner decisions (open; the assistant does not decide these)
- D1. Guest due payment through the API. Track D is checking whether any order-to-guest binding exists in the schema and in guest checkout. If none does, the options are to keep guest writes refused, or to add an order-bound guest token.
- D2. Web guest due payment from the order-tracking page. Track A keeps it working through a marker stored in the session when the order id and phone match. Confirm this approach or choose another.
- D3. A return authorized for zero units is stored as one unit, because `authorize()` clamps the quantity with `max(1, qty)`. Reject such requests instead? Characterized in `#17`.
- D4. Keyed-object variations. The legacy restock path rebuilds an object as a list, while the RMA helper keeps the object (`#17`). Choose one canonical shape. Production data is reported as lists.
- D5. `#9` behaviour change. Deadlocks and lock-wait timeouts now reach four wallet callers as exceptions (HTTP 500, or an outer rollback) instead of a false refusal. Retries are deferred until the closures' side effects are audited. Financial-owner review.
- D6. Debit above balance and idempotency keys (the `#9` notes; unchanged).
- D7. The four refund race tests in `#15` stay skipped until the owner approves fixes (RF-01, RF-03, RF-04, IDEM-08).
- D8. `#3` needs payments sign-off. The Paymera secret rotation (`#7`) needs an authorized person. G0 remains open, and nothing is merged.

Next, in order
1. Review and push the Track A and Track D branches once their reports are accepted, then open draft PRs against `main`.
2. Watch the CI runs for `#8` to `#20`. Carry the Torod repair to `#16`, `#17` or `#18` only with log proof. Keep the stack in sync: a change to `#9` is merged into `#10`, `#11`, `#12`, `#13`, `#14` and `#15`.
3. Add the positive test for the v2 `refund_status_update` happy path (noted in `#20`).
4. Remaining items: gateway replay guard (IDEM-05, owner review); referral characterization (LO-02); inventory and holds tests; six-storefront foundation behind flags; Flutter work by dependency.

Operational notes
- Disk: the session allowance is small. Create new worktrees with `cp -al` (hard links) for `vendor/`. Never use `cp -a` for it, because that fills the allowance. Never symlink `vendor/`, because autoload then resolves app classes from the wrong worktree.
- The local MySQL 8.0.46 instance in the session scratchpad is throwaway: port 3307, with a local-only test credential. That credential must not be copied into any repository or document.
- Commit attribution: commits carry the `Claude-Session` trailer only. No model name is written into any commit, PR title, PR body, comment or handoff.

Heads at the time of writing: `#6` `5c4b3e66`, `#7` `ad09d6c6`, `#8` `a8c6a42b`, `#9` `f2866840`, `#10` `9b398748`, `#11` `7fc382c8`, `#12` `dd5f95e4`, `#13` `d7bd84f6`, `#14` `2ae74598`, `#15` `59133c35`, `#16` `637196e1`, `#17` `fb3493dd`, `#18` `94d82c5f`, `#19` `74372ebf`, `#20` `4a9fa320`.

## 18. Second CI pass, three security tracks as draft PRs (2026-11-14, about 00:21Z)

Since section 17
- Three new draft PRs on `main`. CI is pending on all three, so none is green.
  - `fullstackfull/lavkoo#20`: seller API order and refund lookups scoped to the seller's own orders (`gcc/p0-seller-order-scoping`, `4a9fa320`). Ten regression tests fail on the base.
  - `fullstackfull/lavkoo#21`: API v1 edit-order due payments act only for the order's owner, or for a guest order whose stored `customer_id` equals the posted `guest_id` (`gcc/p0-api-edit-order-due-payment-ownership`, `2fa52614`). Fourteen of 21 new tests fail on the base.
  - `fullstackfull/lavkoo#22`: web due payments act only for the order's owner, or for the order a browser verified on the tracking page (`gcc/p0-web-due-payment-authorization`, `99865aff`). The partial-phone lookup is removed. The test that guarded nothing was rewritten, and it now fails on the old lookup.
- Torod one-line repair, carried only where a log showed the same assertion at `TorodShipmentDiscoveryTest.php:198` (`'unlinked'` expected, `'needs_review'` got). Each carry has a comment on its PR.
  - `fullstackfull/lavkoo#7`: `ad09d6c6`. Reproduced locally before the repair, passes after it.
  - `fullstackfull/lavkoo#18`: `de1270f8`. Reproduced locally before the repair, passes after it.
- Still to watch, all main-based and not yet completed: `#16`, `#17`, `#20`, `#21`, `#22`. Their Torod job may fail the same way. The repair goes in only with a log that shows the same assertion.
- CI reads on `#8` to `#21`, about 00:00 to 00:14Z: no failure except the known Deployment Overlay gate, plus the two Torod failures above. Most jobs were still queued or running.

Security findings after this round
- S1. `Helpers::getCustomerInformation` still trusts a client-posted `user` field for any caller that still uses it. The three endpoints above no longer call it, but the helper itself is unchanged. Open: a caller inventory, then a separate fix. This is a production exposure.
- S2. API v1 edit-order due payments: fixed in `#21`, pending review and CI. Guest identity is still a sequential, guessable id (D1).
- S3. Web due payments: fixed in `#22`, pending review and CI. Signed-out access now needs the verified tracking session (D2).
- S4. Seller API lookups: fixed in `#20`, pending review and CI.

Owner decisions (open; not decided here)
- D1. Guest ids are sequential and guessable (`#21`). Keep the current check, or add an order-bound guest token?
- D2. Signed-out web due payments need the verified tracking session. A partial phone is now refused, and formatted Saudi numbers match (`#22`). Confirm this behaviour change.
- D9. The customer app's guest due-payment flow does not work, before or after `#21`. Guest cash-on-delivery and offline calls send no `guest_id`. The digital call sends the literal `'cash_on_delivery'` as `guest_id`, and that is now refused. Making the guest flow work needs an app change.
- D10. A signed-in customer can no longer act on a guest order, even one placed under a guest id equal to their user id (`#21`).
- D3 to D8 and the `#9` behaviour decision (D5) are unchanged from section 17.

Next, in order
1. Read CI on `#8` to `#22` again. Carry the Torod repair to a main-based branch only with a log that shows the same assertion. Never report pending jobs as green.
2. Review `#20`, `#21` and `#22` with a fresh reviewer, and read CI on each head SHA.
3. Caller inventory for `Helpers::getCustomerInformation` (S1), then a separate fix track.
4. The remaining items from section 17.

Heads at the time of writing: `#7` `ad09d6c6`, `#18` `de1270f8`, `#20` `4a9fa320`, `#21` `2fa52614`, `#22` `99865aff`. The other heads are listed in section 17.

## 19. Identity fix in draft PR 23, container restart, and a production exposure (2026-11-14, about 01:20Z)

Since section 18
- Container restart: background runs stopped. The identity run was interrupted before it reported, and its partial edits were saved and then resumed. The partial change, applied alone, broke the website-payment callback: five `OrderPaymentCallbackTest` cases failed, because the payer had been read from request input. Nothing from that partial state was committed.
- `fullstackfull/lavkoo#23`, draft on `main`, head `9db1506b`: identity comes from the customer guard, then the request object's user resolver, then the server session, then `offline`. The module payment callback sets its verified payer on the resolver and passes the request object. The `OrderManager`, `CartManager` and callback identity sites pass the request object. The API token middleware sets the token customer on the resolver.
  - Verified locally on SQLite: 11 identity tests; 5 module payment callback tests; `ApiOrderPlacement|Cart|OrderManager` 25 tests; `Payment` 87 tests with 1 skip (MySQL only); `Address` 1045 tests with 1 skip; `Auth` and `Security` 21 tests. Lint and `resources/lang` clean.
  - On the previous code, 5 of the 11 new tests fail: the stranger's body on web checkout, the stranger's body on the cart listing (it re-priced the owner's cart row from 40 to 100), and three posted-user cases.
  - Independent review: accept with notes. Both notes were applied.
  - CI: pending. Not green.
- S1 (`Helpers::getCustomerInformation` trusting client input): fixed in `#23`, pending review and CI. The caller inventory covered 74 call sites in 21 files. Each now receives a request object, or the server-set payer.

Production exposure, on `main` before `#23`
- Web cash-on-delivery and offline checkout (`WebController` around lines 629 and 775) call `generateOrder` without a `requestObj`. The fallback `request()->all()` let a visitor's posted `payment_request_from=app` and `customer_id` name the customer that the order was recorded under. Reproduced on `main` by test (a) in `#23`.
- Owner action: review production orders from these two paths for a customer that did not sign in on that request. The assistant has no production read access, so this check belongs to the owner.

Owner decisions (open; not decided here)
- D11. Review and merge `#23` (checkout identity and the module payment callback). This needs a financial and security reviewer. Decide whether any recorded order needs correcting.
- D12. The debug write in `app/Utils/module-helper.php` (about lines 171 to 193) appends customer ids, guest ids and order ids to `storage/logs/order_generate_test.txt` on every paid order. Remove it, or keep it under a stated purpose.
- D13. The module callback trusts the stored `additional_data` payload. Confirm how the gateway callback is verified.
- D1 is unchanged: guest ids are sequential and guessable.

Next, in order
1. Read CI on `#8` to `#23` again. Carry the Torod repair only to a main-based branch whose log shows the same assertion.
2. Independent review of `#22` (web due payments) and `#21` (API due payments), then CI on each head.
3. The debug-log change (D12), as a small separate pull request, once the owner decides.
4. The items from section 18.

Operational notes
- Local MySQL 8.0.46 was restarted from the scratchpad data directory on port 3307. It is a throwaway instance.
- Background tasks do not survive a container restart. Re-create any long run that matters, and check the saved partial edits in the worktree before re-running.

Heads at the time of writing: `#7` `ad09d6c6`, `#18` `de1270f8`, `#20` `19520b10`, `#21` `2fa52614`, `#22` `99865aff`, `#23` `9db1506b`.

## 20. Holds characterization in draft PR 24, and a correction to section 19 (2026-11-14, about 01:45Z)

Corrections to section 19
- The "next" item that asks for an independent review of `#22` and `#21` is out of date. Both were reviewed by workflow reviewers: `#21` accept with notes, and `#22` accept with notes, with a second review of its phone-match test fix also accept with notes. Their heads have not changed since. A new review is needed only after a new head.

New
- `fullstackfull/lavkoo#24`, draft on `main`, head `cb8b53fa`. Test-only: characterization of stock and hold behaviour. It changes no production file. Seventeen SQLite tests pass in default, reverse and random order. The filtered `Stock`, `Return`, `Reservation`, `ShipmentTracker` and `Hold` suites pass: 827 tests, 25 skipped (MySQL only).
- Four findings are pinned as they are, each in a test named `..._currently_`. Each is a fix candidate, and each fix flips its test:
  - F1, `app/Utils/OrderManager.php:135` (twin `app/Repositories/OrderRepository.php:678`): re-opening a cancelled line floors `current_stock` at zero, but decrements the variant quantity with no floor. A variant can go negative.
  - F2, `app/Services/Marketplace/ReturnLogisticsService.php:102,111-115` with `OrderManager.php:141-142`: a partial return restocks without claiming `is_stock_decreased`. Marking the order returned afterwards restocks the whole line again.
  - F3, `ReturnLogisticsService.php:45,102,113`: the whole-line claim compares the line quantity with the return quantity, then adds the return quantity. A return for more units than the line restocks units that never left.
  - F4, `ReturnLogisticsService.php:111-115`: the return receipt raises `current_stock` and never writes the variation JSON. `fullstackfull/lavkoo#17` changes this.
- Merge dependency: F4's test pins what `#17` changes. Whichever of `#17` and `#24` merges second must update that test.
- Not a defect, by the code's own docblock: a reservation released for a cancelled order stays released on reopen and reserve (`ReservationService.php:13-18`). It is an invariant. Owner review is needed, because it can keep a reinstated channel order from being held again.
- Not covered: the insufficient-stock refusal at placement, and `allow_oversell`. Both need the full order pipeline.

Pending
- The CI re-read scheduled for about 01:52Z has not reported yet. Its results will go in the next update.
- Owner decisions D11 to D13 from section 19 are unchanged.

Heads at the time of writing: `#7` `ad09d6c6`, `#18` `de1270f8`, `#20` `19520b10`, `#21` `2fa52614`, `#22` `99865aff`, `#23` `9db1506b`, `#24` `cb8b53fa`.

## 21. CI re-read of #8 to #24, Torod repair carried to #16 and #17 (2026-11-14, about 02:05Z)

Since section 20
- The scheduled CI re-read (trigger `trig_01Uzak1oABmYv3EQGcPDfbRA`, fired 01:52Z) is done. Many jobs were still queued or running when read. Nothing pending is counted as green here.
- Deployment Overlay gate: fails on every head that completed, by design. The overlay log on `#9`, `#16` and `#17` names three paths outside the overlay directories: `firebase-messaging-sw.js`, `public/firebase-messaging-sw.js` and `robots.txt`. Overlay-only. Nothing bypassed. The overlay baseline the workflow uses (`698aa1e`) is unchanged.
- Torod fixture, `TorodShipmentDiscoveryTest.php:198` (`'unlinked'` expected, `'needs_review'` got). It failed in one test per run on `#16` (MySQL and MariaDB sales channels N-Z, SQLite PHP 8.2) and on `#17` (MySQL and MariaDB sales channels N-Z, strict-mode MariaDB, SQLite PHP 8.2 and 8.3). Reproduced locally on both base heads. Every log shows the same assertion, so the repair is carried:
  - `#16`: `65d2d096` on `637196e1`, fast-forward push.
  - `#17`: `5cbc3844` on `fb3493dd`, fast-forward push.
  - Each is the one line from `de1270f8` on `#18`, in `tests/Feature/SalesChannels/TorodShipmentDiscoveryTest.php`. It sets the fixture purchase ten hours ago, as the sibling test at line 180 does. No assertion changed. After the repair, the class passes on both branches: 12 tests, 45 assertions, 0 failures.
- Not carried: `#20` to `#24` have no completed log yet. The time-bomb can still hit them, so the repair goes in only with a log that shows the same assertion.
- Job state per draft PR, from that read (passed / failed / pending; overlay is the one failure on every completed head):
  - `#8`: 20 / 0 / 0, complete except overlay.
  - `#9`: 17 / 0 / 3.
  - `#10`: 6 / 0 / 14.
  - `#11`: 14 / 0 / 6.
  - `#12`: 8 / 0 / 12.
  - `#13`: 3 / 0 / 17.
  - `#14`: 1 / 0 / 19.
  - `#15`: 8 / 0 / 12.
  - `#16`: 14 / 3 Torod failures / 3, old head `637196e1`, repaired above.
  - `#17`: 15 / 5 Torod failures / 0, old head `fb3493dd`, repaired above.
  - `#18`: 21 queued.
  - `#19`: 14 / 0 / 6.
  - `#20` to `#24`: 21 queued each, nothing has run yet.

Owner decisions
- D14 (new). A reservation released for a cancelled channel order stays released after the order is reopened (section 20, invariant). Owner decides whether a reopened order must hold stock again. This can cause oversell until decided.
- D1 to D13 are unchanged.

Next, in order
1. Read the new heads `#16` `65d2d096` and `#17` `5cbc3844`, and the pending jobs on `#9` to `#15`, `#18`, `#19` and `#20` to `#24`. Classify each failure as overlay-only or technical. Never report a pending job as green.
2. Owner review of `#23` (D11) and the decisions above. Nothing below is started until these are answered.
3. Not read in this pass: `#1` to `#7`, which were outside the trigger's `#8` to `#24` scope. `#7` already carries the repair (section 18).

Resume
- `git ls-remote origin refs/heads/gcc/p0-order-owner-is-guest-scope refs/heads/gcc/p0-rma-receive-idempotency` should print `65d2d096` and `5cbc3844`.
- Check runs: `pull_request_read` with `get_check_runs` on `#16` and `#17`. Failing jobs: `get_job_logs` with `job_id`, then count the `Failures: N` line in the PHPUnit summary.

Heads at the time of writing: `#16` `65d2d096`, `#17` `5cbc3844`, `#18` `de1270f8`, `#20` `19520b10`, `#21` `2fa52614`, `#22` `99865aff`, `#23` `9db1506b`, `#24` `cb8b53fa`.

## 22. LO-02 referral characterization in draft PR 25, and owner-level financial exposure (2026-11-14, about 03:30Z)

Since section 21
- Draft PR `fullstackfull/lavkoo#25`, branch `gcc/p0-referral-characterization`, head `fb6b126e`, base `main` at `c5c04b7b`. Test-only: 28 tests, 83 assertions on SQLite. No production file changed. Two commits: `84b96d66` (20 tests) and `fb6b126e` (eight more). A fresh read-only reviewer accepted each with notes and no blocking items. The mutation checks in both reviews failed the tests they targeted.
- Verified by reading `origin/main` and by running each path in the test on SQLite. Not checked against production data.
  1. The delivery-man app pays the referrer twice for one first delivery: `DeliveryManController.php:229` (shared writer) and `:249` (CustomerManager). Live route `PUT /api/v2/delivery-man/update-order-status`.
  2. Seller API v3 `order_detail_status` pays twice: `RestAPI/v3/seller/OrderController.php:475` then `:479`. Live route `PUT /api/v3/seller/order-detail-status/{id}`.
  3. The admin panel pays again when `delivered` is submitted twice: `Admin/Order/OrderController.php` has no already-delivered guard, and `:736` runs the shared writer on every submission. The vendor panel has the guard (line 624).
  4. The vendor panel credits the referee, not the referrer: `Vendor/Order/OrderController.php:696`, credited at `:699-703`.
  5. The vendor first-order check counts cancelled orders: `:695` uses `OrderRepository::getListWhereCount` (lines 489-495), which filters only on `customer_id`.
  6. Multi-currency: the setting is stored in USD (`Admin/Customer/CustomerController.php:507`). The vendor writer and `CustomerManager` divide it by the default-currency rate again. In the test, 10 becomes 10 USD from the shared writer and 2.6667 USD from the others. Seller API v3 `updateOrderDetails` (`:772`) is affected too.
  7. The shared writer (`OrderManager.php:872-882`) has no per-order guard: a second call for the same first order pays again.
- Audit correction: E04 section 5 cites `Admin/Order/OrderController.php:726-734` as a referral writer. Those lines pay loyalty points. The admin referral writer is line 736.
- Not fixed. Any fix changes a payout or a balance, so the owner decides the rule first. Then someone authorized runs a read-only check of production `wallet_transactions` (`reference = 'earned_by_referral'`), grouped by referee. This session has no access to production, and nothing here touches it.

Owner decisions (new)
- D15. Delivery-man app and seller API v3 `order_detail_status`: should the referrer be paid once or twice? If once, through which writer? The shared writer matches the stored USD unit.
- D16. Vendor panel: the referee is credited today. Confirm the referrer should receive the reward, and decide whether any referee credits already made need correcting.
- D17. Admin re-submission of `delivered`: add a server-side already-delivered guard?
- D18. Referral setting unit: USD (as stored, and as the shared writer reads it) or the default currency (as the admin form label shows)?
- D19. First-order rule: delivered and paid orders only (what the shared writer counts), or every order (what the vendor path counts today)?
- D1 to D14 are unchanged.

Pending
- CI on `#25` has not run yet. Expect the Deployment Overlay gate to fail, by design. The full-suite SQLite and MySQL jobs include `TorodShipmentDiscoveryTest`, so the time-bomb can fail there too. Carry the one-line repair (`de1270f8`, the same one carried on `#16` and `#17`) only with a log that shows `TorodShipmentDiscoveryTest.php:198` (`'unlinked'` expected, `'needs_review'` got).
- This session is subscribed to activity on `#25`.
- Heads at the time of writing: `#25` `fb6b126e`, plus the heads in section 21.

Resume
- `git ls-remote origin refs/heads/gcc/p0-referral-characterization` should print `fb6b126e`.
- Check runs: `pull_request_read` with `get_check_runs` on `#25`. Classify each failure as overlay-only or technical.
- Test run: in a worktree of `gcc/p0-referral-characterization` with its vendor hard-linked, `php vendor/bin/phpunit tests/Feature/Referral` should give 28 tests and 83 assertions.

## 23. Order-action permissions in draft PR 26, and a v2 seller API gap (2026-11-14, about 03:45Z)

Since section 22
- Draft PR `fullstackfull/lavkoo#26`, branch `gcc/p0-seller-admin-permission-characterization`, head `9b97f09b`, base `main` at `c5c04b7b`. Test-only: 12 tests, 42 assertions on SQLite. No production file changed. Independent review: accept with notes, no blocking items. Removing each gate, one at a time in a scratch copy, fails the test that targets it.
- Pinned gap, a security finding: the v2 seller refund decision has no status or permission check (`routes/rest_api/v2/api.php:85`). It authenticates through `Helpers::get_seller_by_token` (`app/Utils/Helpers.php:529`), which matches `sellers.auth_token` and checks no status. A seller whose status an admin sets to rejected keeps a working token: the v2 refund decision returns 200 and changes the refund. The v3 route refuses the same token with 401.
- By reading the code, not tested: that helper has 48 call sites in seven v2 seller controllers. They include the v2 order status, payment status and delivery assignment routes, product and shipping routes, and the balance-withdraw route.
- Admin suspension rotates the token (`Admin/Vendor/VendorController.php`, both the single and the bulk path), so a suspended seller's old token stops working. Rejection does not rotate it. The gap is reachable through rejection, and through any status change that leaves the token in place.
- Not fixed. A fix changes which sellers can act, so the owner decides (D20).
- Observed: `POST /admin/customer/wallet/add-fund` sits behind `module:people` (`routes/admin/routes.php:603`). Any admin who manages people can credit customer wallets (D21).
- Not covered: the wallet and POS money routes (gates read, not exercised), and issued API keys and their scopes.

Owner decisions (new)
- D20. Seller API v2 has no status or permission gate. Add the approved check to the v2 seller routes? Should admin rejection also rotate `auth_token`, as suspension does? Either change alters what rejected sellers can do on the mobile app, so it needs owner sign-off before any production change.
- D21. Admin customer wallet add-fund sits behind `module:people`. Keep it, or give wallet credits their own module?
- D22. Wallet and POS money gates are read but not yet exercised. Include them in a later track?
- D1 to D19 are unchanged.

Pending
- CI on `#25` and `#26` has not run yet. The safety-net check-in for `#25` fires about 03:22Z (trigger `trig_01F2afcce88XumUcEKCw7VGm`).
- This session is subscribed to activity on `#25`. Subscribe on `#26` as well.
- Heads at the time of writing: `#25` `fb6b126e`, `#26` `9b97f09b`, `#16` `65d2d096`, `#17` `5cbc3844`.

Resume
- `git ls-remote origin refs/heads/gcc/p0-seller-admin-permission-characterization` should print `9b97f09b`.
- In a worktree of that branch with its vendor hard-linked, `php vendor/bin/phpunit tests/Feature/Permissions` should give 12 tests and 42 assertions.
- Check runs on `#25` and `#26`: classify each failure as overlay-only or technical.

## 24. Torod repair carried to draft PR 25, and the first CI results on PR 26 (2026-11-14, about 05:30Z)

Since section 23
- `#25` (`gcc/p0-referral-characterization`): the MySQL 8.0 sales-channels N-Z job failed on `fb6b126e` with one failure, `TorodShipmentDiscoveryTest.php:198` (`'unlinked'` expected, `'needs_review'` got). The same failure reproduces locally on that head. The one-line repair `de1270f8` is carried as `bb0ec132` (fast-forward). Locally the class passes: 12 tests, 45 assertions. A comment on `#25` cites the log. The other jobs were still queued when this was written.
- `#26` (`gcc/p0-seller-admin-permission-characterization`, head `9b97f09b`): the Deployment overlay gate failed, as it does on every branch that starts from `main`. Its log names `firebase-messaging-sw.js`, `public/firebase-messaging-sw.js` and `robots.txt`, which this PR does not touch. One comment on `#26` says so. Nothing is bypassed. The other 20 jobs were still queued.
- Safety-net check-ins are armed: `#25` at 07:24Z (trigger `trig_015kJ84ftTjP9jMxmKKGQmJr`) and `#26` at 07:33Z (trigger `trig_01E6EEY3CtzZci38ejar6fJ1`). Each one re-arms every four hours while CI is still queued.
- Heads at the time of writing: `#25` `bb0ec132`, `#26` `9b97f09b`, plus the heads in sections 21 and 23.
- Owner decisions D15 to D22 are unchanged.

## 25. Torod repair carried to draft PR 26, and the overlay comment on draft PR 25 (2026-11-14, about 06:35Z)

Since section 24
- `#26` (`gcc/p0-seller-admin-permission-characterization`): the strict-mode MariaDB job failed on `9b97f09b` with one failure, `TorodShipmentDiscoveryTest.php:198` (the same assertion as on `#25`). Reproduced locally on that head. The one-line repair `de1270f8` is carried as `d65a7f5d` (fast-forward). Locally the class passes: 12 tests, 45 assertions. A comment on `#26` cites the log.
- `#25` (`gcc/p0-referral-characterization`, head `bb0ec132`): the overlay gate failed, as it does on every branch from `main`. The log names the same three files. One comment on `#25` says so. Nothing is bypassed. Its test jobs were still queued or running.
- Heads at the time of writing: `#25` `bb0ec132`, `#26` `d65a7f5d`, plus the heads in sections 21 to 24.
- Owner decisions D15 to D22 are unchanged.

## 26. CI complete on draft PR 26, overlay gate unchanged (2026-11-14, about 08:40Z)

Since section 25
- `#26` (`gcc/p0-seller-admin-permission-characterization`, head `d65a7f5d`, unchanged): all 21 checks have completed. 20 succeeded. The Deployment overlay gate failed by design. Its log (job `132361736972`) names only `firebase-messaging-sw.js`, `public/firebase-messaging-sw.js` and `robots.txt`, so the failure is overlay-only.
- The two jobs still running in section 25 have finished: `Strict-mode tests, MariaDB 11.4.2` at 07:50:00Z and `Sales-channel and cost tests, MySQL 8.0 (sales channels A-M)` at 07:44:25Z. Both succeeded. Their logs returned HTTP 404 while they ran, so they were not read until completion.
- No reviews. Comments: the two existing ones. The Torod comment at 06:29:32Z still says the other jobs are queued or running. It is stale and was not edited.
- No new commit or comment on `#26` in the check-in itself. Nothing bypassed.
- The only blockers on `#26` are the overlay baseline decision (section 11, item 1) and owner decisions D20 to D22.
- The `#26` safety-net is not re-armed, because CI is complete.

Heads at the time of writing: `#26` `d65a7f5d`, `#25` `bb0ec132` (not re-read in this check-in), plus the heads in sections 21 to 24.
Owner decisions D15 to D22 are unchanged.

## 27. Owner order of 2026-11-15: four tracks as draft PRs (2026-11-15, about 07:50Z)

Owner order: run the four tracks in sequence, without asking for technical decisions. Financial and security review is needed before merge, not before tests and a proposed fix on an isolated branch. No merge, no production, no real data, no secret changes. None of the PRs below is merged or deployed.

Draft PRs:
- Track 1, seller API v2 refund gate: PR #27 `gcc/p0-seller-v2-refund-approved-gate`, head `887a365f`, base `main`. A `legacy_seller_approved` route middleware on the three v2 refund routes, using the same approved-owner lookup as v3. 9 permission tests and 10 security tests pass. Without the gate, 5 of the 8 original tests fail. Two reviewers: accept with notes. Remaining exposure: 45 other v2 callers of `Helpers::get_seller_by_token` still accept a rejected seller's token. The choke-point fix is recommended, but it needs owner sign-off (D20).
- Track 2, referral exactly-once: PR #29 `gcc/p0-referral-reward-idempotency`, head `f0014cdc`, base `main`. Additive migration `2026_11_23_100000_create_referral_reward_grants_table`, unique on (referee_id, beneficiary_id), no backfill. `ReferralRewardService::creditOnce` is the only reward writer path. 52 referral tests pass; with the guard bypassed, 26 to 27 fail. The concurrency tests skip on SQLite, so their first real run is CI's MySQL partition. Two reviewers: accept with notes. Two cherry-picked commits duplicate PR #25. Amount decision needed (below).
- Track 3, wallet and refund write paths: a read-only scout is running (workflow `wf_0ddbdbee-f97`). No code yet.
- Track 4, GCC foundation:
  - Backend: PR #28 `gcc/p1-storefront-api-header`, head `8c8d32a6`, stacked on PR #19 (`gcc/p1-storefront-middleware-alias`, `74372ebf`), which is stacked on PR #8 (`gcc/p1-storefront-context`, `a8c6a42b`). Adds the `X-Storefront` header and a public `GET /api/v1/storefronts`. Flag `GCC_STOREFRONTS_ENABLED` defaults to false, and only SA is active. Route parity: 191 routes before, 192 after. 268 storefront tests pass. Retarget to `main` after #8 and #19 merge.
  - Seller app: https://github.com/fullstackfull/lavkooappseller/pull/1, head `964557e8`. `MarketScope` plus a default-off `X-Storefront` header. CI runs on the PR. Dart was not run locally.
  - Customer app: https://github.com/fullstackfull/lavkooapp/pull/1, head `5e109211`. Default-off header and six-storefront config. The repo has no CI, and Dart tests were NOT run. Before merge, rename or move the test "is off unless the build defines it".

Open owner decisions (they block merge, not coding):
- D15 to D19, the amount that survives on multi-currency stores. PR #29 changes what is paid on the mixed paths. Delivery-man now credits 10.0 (main wrote 10.0 and 2.67). Seller v3 `order_detail_status` now credits 2.67 (main wrote 2.67 and 10.0). If the owner picks the shared writer's amount, the change is which writer survives.
- D20, the choke-point fix for the 48 v2 `get_seller_by_token` callers. PR #27 covers only the refund group.
- D21 and D22, the wallet module and the POS money gates (for track 3).
- Overlay baseline: `PILOT_BASELINE` is unset and the gate fails on every PR from `main`. Do not change it without server state.
- Merge order: #8, then #19, then retarget #28 to `main`.

Process facts:
- The high-tier review model quota is exhausted until 2026-11-17 21:00 UTC. Do not use that model tier for reviews or agents until it resets. Reviews ran on the other tiers.
- Worktrees under `scratchpad/wt`: `seller-v2-refund`, `referral-idempotency`, `gcc-storefront-api-header`, `flutter-customer-storefront`, `flutter-seller-storefront`. Each vendor directory is hard-linked from the `permissions` worktree with `cp -al`. Tests use a throwaway `APP_KEY` per run.
- Workflow IDs: refund `wf_09530400-968` (review `wf_8508a1f9-c0b`, staff test `wf_c8d29e2b-ee8`); referral `wf_3f9dbf1d-a42` (review `wf_f5a3708d-6b1`); storefront `wf_d65dc964-e8f` (review `wf_dfbb0b82-517`, test fixes `wf_cf6f16fa-ae0`); Flutter `wf_ec13b3c6-cfd`; wallet scout `wf_0ddbdbee-f97`.

Resume:
- `git -C /home/user/lavkoo fetch --prune origin`, then read PRs #27, #28, #29 and the two Flutter PRs with the GitHub tools. Check each head SHA against this section before acting.
- Next: the wallet track, from the output of `wf_0ddbdbee-f97`. Choose fixes that do not change an undecided amount. If a fix touches the referral writer lines, base it on `gcc/p0-referral-reward-idempotency` or wait for PR #29.
- Still open from sections 21 to 26: PR #25 and PR #26 have 20 of 21 checks passing, with only the overlay gate failing. Owner decisions D11 to D14 and D15 to D22 are open.

Later in the same session, these draft PRs were also opened (heads as of their last push):
- PR #30 `gcc/p0-wallet-v3-pos-paid-amount-guard`, head `88d21d8e`: refuses a non-positive wallet paid amount on the v3 seller POS, before any write. Follow-ups: the payment method is matched case-sensitively, so `Wallet` skips both the guard and the debit; the wallet is debited before the stock check (PR #13 may cover this for v3, to be verified); double currency conversion (D18).
- PR #31 `gcc/p0-wallet-withdraw-boundary-tests`, head `77330aa0`: test-only. Pins the withdraw boundaries on v3 and v2. The conversion defect appears only when the settings cache is stale. A multi-currency store with no USD row is noted but not run.
- PR #32 `gcc/p0-wallet-seller-withdraw-scoping`, head `6922d994`: scopes seller withdraw update and close to the signed-in seller and refuses decided requests, with transactions and guards in each WHERE clause. Follow-ups: concurrent updates of a pending request (needs the lock track); wallet rows read outside the transaction; an identical update in the same second is refused.
- PR #33 `gcc/p0-vendor-withdraw-close-post`, head `550a8331`: the vendor close moves from GET to POST, with CSRF enforced. This is a security fix. After deploy, check the vendor close button manually. The view markup has no direct test.
- Flutter: seller app PR https://github.com/fullstackfull/lavkooappseller/pull/1 (head `964557e8`, CI runs on it), and customer app PR https://github.com/fullstackfull/lavkooapp/pull/1 (head `5e109211`, no CI, Dart tests not run).

Open owner items: D15 to D19 (the surviving amount, which also affects PR #29); D18 (currency and rounding); D20 (the choke point for the 45 remaining v2 callers in PR #27); D21 and D22 (the wallet module and the POS money gates); the overlay baseline; the merge order (#8, then #19, then retarget #28 to `main`); financial or security review of #27, #29, #32 and #33; and the manual check of #33 after deploy.

## 28. Continuity pass: Torod carry on open draft PRs, and CI state (2026-11-15, about 10:30Z)

Owner directive: keep working under the charter. Fix open draft PRs; do not multiply them. Nothing is merged or deployed.

- CI on #27 and #29 to #33 failed the SQLite job and the sales-channel N-Z partitions with `TorodShipmentDiscoveryTest::test_the_scheduled_run_links_what_appeared_later_and_keeps_the_status_of_a_linked_shipment_current`, with `'unlinked'` expected and `'needs_review'` returned, at `TorodShipmentDiscoveryTest.php:198`. Evidence: job 132741345797 (PR #27, SQLite PHP 8.3). The cause is the clock-dependent purchase time in the fixture on `main`.
- The one-line repair `de1270f8` (on `gcc/p0-checkout-stock-tests`) was carried to each `main`-based branch, after a local reproduction at line 198 on each: `gcc/p0-seller-v2-refund-approved-gate` `df1c778a`; `gcc/p0-referral-reward-idempotency` `73708954`; `gcc/p0-wallet-v3-pos-paid-amount-guard` `4cae4971`; `gcc/p0-wallet-withdraw-boundary-tests` `00b99740`; `gcc/p0-wallet-seller-withdraw-scoping` `628fa693`; `gcc/p0-vendor-withdraw-close-post` `cb2c5b87`. After the carry each shows 12 tests and 45 assertions passing. PR #28 already had it, inherited from #8.
- Flutter seller app, `lavkooappseller#1`: `analyze-and-test` ran 155 tests, 154 passed and 1 failed. The failure is `app_version_gate_test`: pubspec 1.0.0 against the backend minimum 2.0.1. This is the known release blocker. Neither the version nor the minimum is changed.
- Overlay gate: still failing by design on every PR from `main`, with the three known files.
- In flight: the vendor close forms rendering test on #33 (`gcc/p0-vendor-withdraw-close-post`), and the v3 POS payment-method normalisation on #30 (`gcc/p0-wallet-v3-pos-paid-amount-guard`). Both are fixes on existing branches, not new PRs.
- Resume: read the heads of #27 to #33 with the GitHub tools, and re-read CI on the new heads. Classify each failure as overlay-only or technical from its log. Do not treat pending jobs as passed. The workflow IDs are listed in section 27.

## 29. Wallet and POS tracks as draft PRs, owner order of 2026-11-15 (2026-11-15, about 11:30Z)

Scope: the owner delegated the engineering decisions for these tracks. No merge to `main`, no production publishing, no real financial change, no owner decision taken on the owner's behalf. Every PR below is a draft.

- **Seller withdraw locks, PR 32 (`gcc/p0-wallet-seller-withdraw-scoping`, head `726e681e`).** Seller create-withdraw and admin approval now lock the wallet and the request inside one transaction. Admin approval moves its ledger write inside that transaction, guarded by `approved == 0`. Commits `7af5d10a` (the MySQL races) and `726e681e` (the locks). Verified on the local MySQL (`lavkoo_seller_test`): 25 tests (7 concurrency, 18 scoping), 92 assertions, OK. The author checked `726e681e`; an independent review is still needed before merge. PR 32's body now says so.
- **Currency debit, PR 34 base (`gcc/p0-wallet-currency-consistent-debit`, head `f7aafda9`, draft to be opened).** A wallet order debits its USD amount rounded half up to whole cents, once. The check reads the balance to cents with the same rounding. The debit goes through `CustomerManager::create_wallet_transaction(..., amountInUsd: true)`. This replaces the earlier local commit `8b0384c4`, which was never pushed: that commit still debited `Convert::usd(Convert::default(x))`, so a 10.01 USD order debited 10.010667 in SAR. Verified: the wallet suites on SQLite (85 tests including a unit test, OK) and on MySQL (76 tests including the two-process race, OK). The full SQLite suite is recorded in the PR.
- **POS server total, PR 13 base (`gcc/p0-pos-wallet-server-total`, head `0d6a63c1`, draft PR 35).** A seller-app wallet sale is charged the server total in the store currency, not the client's `paid_amount`. The old path also converted the debit twice: a 37.54 SAR sale debited 2.67 USD. Cash below the total is refused with the existing message. An optional `Idempotency-Key` (additive table `pos_sale_idempotency_keys`) answers a repeated submit with the first order, debited once. A MySQL race test covers two simultaneous submits. Verified: `PosWalletSaleTest` 17 tests, OK on SQLite and MySQL; `tests/Feature/Pos` 3 tests, OK on SQLite. On MySQL, 3 tests in `PosStockDecrementOrderTest` fail on the base as well (seller app, admin panel, vendor panel stock order), with and without this change; they are not touched.
- **Not changed here:** the debit-after-order ordering and the unlocked balance check on REST and web checkout belong to PR 12 and PR 9. Admin POS keeps its unrounded `currencyConverter` debit.

Open decisions for the financial owner. They do not block the drafts, and none of them is merged:

1. **D18 rounding.** Whole-cent half-up rounding for the wallet debit (PR 34 base) against the unrounded `currencyConverter` debit of the admin POS and the v3 POS (PR 35). Align after the owner decides.
2. **Saudi wallets.** A 10.01 USD order now debits 10.01 USD, where SAR stores debited 10.010667. An order whose balance equals its USD amount is now placed, where it was refused. Sign-off is needed before merge of the currency PR.
3. **POS order units.** For wallet sales `paid_amount` is USD and `order_amount` is in the store currency. This mix predates this round.
4. **Idempotency needs the app.** Retry protection applies only when the seller app sends `Idempotency-Key`. The app change is not in these PRs.

Not done in this round: the country and store context behind flags (PR 28 is its base), the Flutter customer and seller slices, and the scheduled CI read of the heads of PRs 27 and 29 to 34 and `lavkooapp#1`. Those carry to the next section.

### CI read of the continuity heads (scheduled trigger, fired 11:06Z)

Read-only. One agent per head confirmed the head with the GitHub get method and read its check runs. Each failing job was re-read by a separate agent, which tried to refute the first classification from the log. The trigger's expected head for PR 32 was 4bb35ccb, which the PR's own lock commits have since superseded; the table gives the real head.

| PR | Head read | Result at read time |
|---|---|---|
| 27 | df1c778a | 19 success, 1 running (MySQL 8.0 sales channels A-M), 1 failure: overlay, verified |
| 29 | 73708954 | 17 success, 3 running, 1 failure: overlay, verified |
| 30 | 4f66dce1 | 11 success, 9 queued or running, 1 failure: overlay, verified |
| 31 | 00b99740 | 20 success, 1 failure: overlay, verified; every check complete |
| 32 | 726e681e | 4 success, 17 queued or running, no failure yet |
| 33 | 8af7d149 | 16 success, 4 running, 1 failure: overlay, verified |
| 34 | a4bd0353, now 832377a3 | 6 success, 3 failures: overlay, verified, plus 2 technical, verified (see below) |
| 35 | 0d6a63c1 | 1 success, 20 queued |
| lavkooapp 1 | 2a8c84c9 | storefront-header, two runs, both success |

PR 34, technical: `TorodShipmentDiscoveryTest.php:198`, `'unlinked'` expected, `'needs_review'` got, in the scheduled-run test, on sales-channel MySQL 8.0 N-Z and on SQLite PHP 8.2. The failing test on that head did not set `purchased_at`, so the time-bomb applied. Fixed by carrying the one-line repair (`de1270f8`, cherry-picked as `832377a3`) onto the same branch. Reproduced red on the head first, then green on SQLite and MySQL (`lavkoo_test_checkout`): 12 tests, 45 assertions. Pushed fast-forward. The new CI run on 832377a3 was queued at push time and is not yet read.

Torod repair state on the other heads: present in both tests on 27, 29, 30, 31, 33 and 35. Missing only on 34, now carried.

Still running at read time, not reported green: 27 (1 job), 29 (3), 30 (9), 32 (17), 33 (4), 35 (20), and the new head of 34. The trigger does not loop, so these were not polled again. Re-read them by hand before any status is reported.

Open items:
- PR 31's body lists two commits; its branch has three, including `00b99740`, the Torod carry. The body needs correcting.
- The currency branch (`gcc/p0-wallet-currency-consistent-debit`, local `f7aafda9`) is stacked on the old PR 34 head. It is rebased onto 832377a3 and pushed only after its full SQLite run passes. The Torod carry is part of that branch too.
- The overlay gate fails on every PR from `main`, as expected, and is not bypassed.

### Currency debit PR 36 (2026-11-15, about 11:30Z)

Draft PR 36 (`gcc/p0-wallet-currency-consistent-debit`, head `8a4eca3b`, base PR 34 branch) carries the whole-cent USD debit. The earlier local commit `8b0384c4` was dropped unpushed. The branch was rebased onto PR 34's head `832377a3`, which carries the Torod repair.

Full SQLite suite, run before the rebase: 6286 tests, 792746 assertions, 98 skipped (MySQL-only), 9 deprecations. One failure: the Torod time-bomb at `TorodShipmentDiscoveryTest.php:198`, which the rebase fixes. After the rebase the Torod file passes (12 tests, 45 assertions) and the wallet subset passes (85 tests).

The PR is not for merge without financial review. Two items need the owner's sign-off: the rounding rule (D18, half up to whole cents) and the Saudi SAR change (a 10.01 USD order now debits 10.01 USD, not 10.010667).

## 30. Currency PR, storefront country context and the Flutter choice slices (2026-11-15, about 12:00Z)

Owner order: no routine status between steps, continue the six-country plan and the Flutter apps, keep Saudi behaviour unchanged, keep new features default-off, and leave merge and production decisions to the owner.

Draft PRs and their heads at the time of writing:

- **PR 36, currency whole-cent debit** (`gcc/p0-wallet-currency-consistent-debit`, head `8a4eca3b`, base PR 34's branch). Rebased onto PR 34's head `832377a3`, which carries the Torod repair. The full SQLite suite ran before the rebase: 6286 tests, 792746 assertions, 98 skipped, 9 deprecations, one failure, the Torod time-bomb, which the rebase fixes. After the rebase the Torod file (12 tests) and the wallet subset (85 tests) pass. Not for merge without financial review: the rounding rule (D18) and the Saudi SAR change.
- **Storefront country context** (`gcc/p1-storefront-country-context`, stacked on PR 28's head `8c8d32a6`). Storefront-facing caches are keyed by market while storefronts are on: the home categories, the theme sections and settings, the theme revision and payloads, and bought-together. Saudi keys and every key while storefronts are off stay as they were. A publish, a theme flush and a category change forget every market's copy. The category change also forgets the entries listed in the home-category index, which before only forgot the index, so the entries served stale data until their TTL. The `storefront` alias now sits on the product groups, the theme group and the beta web group, and not on the list or the config route. Tests: 168 storefront and related tests pass. The full SQLite suite was running at the time of writing; its result goes in the PR.
- **Flutter customer choice** (`lavkooapp` #2, `gcc/p1-customer-storefront-choice`, head `6b745240`, stacked on PR 1). `StorefrontChoice` reads and saves the chosen storefront only while the flag is on. Six tests. The Flutter SDK is not available here, so the result comes from CI, which now also analyses and tests the file.
- **Flutter seller choice** (`lavkooappseller` #2, `gcc/p1-seller-market-choice`, head `e5fd7164`, stacked on the seller storefront header). `SellerMarketChoice`: while the flag is off, every read answers All Markets; while on, one market at a time, and All Markets is refused. Seven tests. Its CI runs the whole project and was already failing on the version gate, which this change does not touch.

Legal readiness (GCC-11) is the next backend slice. Today policies are global `business_settings` keys (`privacy_policy`, `refund_policy`, `return_policy`, `shipping_policy`, `about_us`) and business pages. Per-storefront approval needs an additive table, a readiness service with Saudi grandfathered, and a `sellable` flag on the storefront list while the flag is on. Checkout enforcement comes after that, with its own tests.

Environment note: the session's temporary volume filled up once while a vendor directory was being copied. The partial copy was removed. The new storefront worktree's `vendor` is a real directory whose packages are symlinks to the PR 28 worktree, so the autoloader serves its own code.

Explicit approvals still needed from the owner: the D18 rounding rule and the Saudi SAR change before PR 36 merges; the storefront flag and country list before any Flutter release; the seller version gate before the seller PR's CI can go green.

## 31. Legal readiness as draft PR 38, and the open decisions (2026-11-15, later the same day)

Owner order unchanged: no routine status between steps, continue the six-country plan and the Flutter apps, keep Saudi behaviour unchanged, keep new features default-off, and leave merge and production decisions to the owner.

Draft PRs and heads at the time of writing:

- **PR 36, currency whole-cent debit**: unchanged, head `8a4eca3b`. Not for merge without financial review.
- **PR 37, storefront country context**: unchanged since section 30, head `2344fa30`.
- **PR 38, legal readiness (GCC-11)**: https://github.com/fullstackfull/lavkoo/pull/38, head `72f96581`, base `gcc/p1-storefront-country-context`. Stacked on PR 37: merge 37 first, then retarget 38 to `main`. CI not read at time of writing.
- **lavkooapp #2** (customer choice): unchanged, head `6b745240`.
- **lavkooappseller #2** (seller choice): unchanged, head `e5fd7164`.

Legal readiness, the first slice of GCC-11:

- Additive table `storefront_legal_documents` (one row per country, document type, locale and version), model `StorefrontLegalDocument`, service `StorefrontLegalReadiness::isReady()`, and `storefronts.legal` in `config/gcc.php` (six required types, Saudi Arabia grandfathered).
- A country is ready when every required type has a published row with a non-blank body, an approver other than its author, and text that is not an unedited template.
- Nothing calls `isReady()` yet, so no response, checkout or page changes.
- The first commit, `a08cbf0d`, had two defects. SQL `trim()` removes spaces only, so newline-only bodies passed. Its negative tests also passed for the wrong reason, because the other required types were missing. `72f96581` fixes both: each rule is tested with everything else valid, and the test builds the table from the real migration. The blank-body case was red before the fix and green after it.
- Tests: readiness 15 OK; `tests/Unit/Storefront` and `tests/Feature/Storefront` 103 OK. SQLite only, not run on MySQL. The one PHPUnit deprecation also appears in `StorefrontCacheKeysTest` on its own, so it does not come from this change.

Open decisions for the owner (also in PR 38's body):

- Final list of required types.
- Locale: any locale satisfies a type today, so English alone can pass. Decide whether Arabic must be present.
- Versions: every published version counts. Decide whether only the latest counts.
- Whether checkout must record acceptance of a specific version.
- Approval flow: `App\Services\ApprovalEngine` exists. Wiring legal documents to it has not started.

Security finding, reported and not fixed:

- `app/Http/Controllers/Web/PageController.php`, `getPageView`: when the request carries `source=admin`, the `status = 1` filter is skipped. Anyone who adds `?source=admin` can read unpublished business pages. Changing it alters an access check, so it waits for owner authorization.

Next backend slice, not started:

- A `sellable` flag on the storefront list while the flag is on, computed with `isReady()`. Additive, default off, Saudi unchanged. Checkout enforcement follows it, with its own tests.

Environment note: the primary checkout `/home/user/lavkoo` is on `gcc/p0-coupon-minimum-boundary` (`e2898afa`). Nothing in this work touches it; work runs in the worktrees under the session scratchpad.

Explicit approvals still needed from the owner: the D18 rounding rule and the Saudi SAR change before PR 36 merges; the `?source=admin` fix; the storefront flag and country list before any release; the seller version gate before the seller PR's CI can go green; the open decisions on PR 38.

## 32. Sellable flag on the storefront list, draft PR 39 (2026-11-15, later the same day)

Draft PR 39 (https://github.com/fullstackfull/lavkoo/pull/39, `gcc/p1-storefront-sellable-list`, head `ba398ce0`, base PR 38's branch). While storefronts are on, `GET /api/v1/storefronts` adds `sellable` to each listed storefront, computed by `StorefrontLegalReadiness::isReady()`. While they are off, the payload is unchanged.

- Built as `StorefrontListing` (new), with the controller delegating to it so the controller stays thin.
- Tests on SQLite: list 6 OK and readiness 15 OK; `tests/Unit/Storefront` and `tests/Feature/Storefront` 105 OK. Not run on MySQL.
- Deployment order: run the legal documents migration before enabling `GCC_STOREFRONTS_ENABLED`. With the flag on and any active non-Saudi country, the list queries that table. Without the migration the list returns a server error.
- Open owner decision: keep non-sellable storefronts in the list (the current behaviour, so an app can show them as coming soon) or hide them.

Stack, bottom to top: PR 28, PR 37, PR 38, PR 39. Merge in that order, then retarget each to `main`. CI was not read for PRs 38 or 39 at the time of writing, and it was not polled.

Still open: checkout enforcement of legal readiness (not started); per-storefront stock and price; the Flutter apps do not read `sellable` yet; the `?source=admin` finding; the owner decisions in sections 30 and 31; the financial review of PR 36.

## 33. MySQL migration defect fixed in PRs 38 and 39 (2026-11-15, later the same day)

Owner order unchanged: continue without routine status, keep Saudi behaviour unchanged, keep new features default-off, and leave merge and production decisions to the owner.

The MySQL run found a deployment blocker. The legal documents migration failed on MySQL: Laravel's generated unique index name was 74 characters, over MySQL's 64-character limit (error 1059), so `php artisan migrate` would have failed there. Both indexes now have short explicit names, `storefront_legal_docs_version_unique` and `storefront_legal_docs_country_status_index`.

Fixes, both pushed as fast-forwards:

- PR 38 (`gcc/p1-storefront-legal-readiness`): `53ad482a`. Short index names. The readiness test builds the table only when it is missing and empties it before each test.
- PR 39 (`gcc/p1-storefront-sellable-list`): `7bb90819`. The same migration fix, and the shared test helper does the same.

MySQL runs (local, 127.0.0.1:3307, scratch database `lavkoo_storefront_test`): readiness 15 OK; list and readiness 21 OK. Both started from a freshly dropped table, so the migration ran with the new names. `SHOW INDEX` confirmed that the unique constraint covers all four columns.

Checked, with a limit: the POS idempotency migration in PR 35 (`2026_11_23_110000`) generates a unique name of 58 characters, under the limit. That was a name-length calculation, not a MySQL run. Other migrations in these PRs were not audited.

PR bodies 38 and 39 were updated to say what was run on MySQL.

## 34. Owner directive of 2026-11-15 executed: unpublished exposure, checkout gate, browse-only (2026-11-15, later the same day)

Owner directive: `docs/gcc/handoffs/2026-11-15-legal-security-next-execution.md` (on this branch at `af9b8d7a`). Its engineering defaults are adopted. G0 remains open. Nothing is merged, deployed or released, and every new gate is off.

Draft PRs and heads at the time of writing:

- **PR 40, business pages** (`gcc/security-business-page-unpublished`, base `main`; heads `14bf6a4a` and `84e3c1a1`). `source=admin` is no longer read, and the public page needs status 1. The page-list cache is cleared after a status change or a delete, not before.
- **PR 41, blog posts** (`gcc/security-blog-unpublished-posts`, base `main`; head `dae3b642`). A public post needs status 1 and is_published 1. A draft is no longer public, and the admin preview is a route in the blog admin group. `draft_data` is read as the model's array. The old `json_decode` raised a TypeError on any post with a pending draft.
- **PR 38, legal readiness v2** (`gcc/p1-storefront-legal-readiness`, base #37; head `122960e9`). Only the newest effective text per document type and locale counts. Arabic and English are required by default. Additive migration `2026_11_24_100000` adds `effective_from` and `revoked_at`. A missing table answers no.
- **PR 39, sellable list** (`gcc/p1-storefront-sellable-list`, base #38; head `8149090c`). Merges #38, and the list test covers a missing legal table.
- **PR 42, checkout gate** (`gcc/p1-checkout-legal-readiness`, base #39; head `25349536`). `storefront.sellable` guards place-order (online, offline, wallet), reorder, the digital payment and the wallet top-up. Left open: refund request and store, cancel, the due payments on existing orders, the MyFatoorah status check and the webhook. Web checkout and the seller POS are not gated (see the blockers).
- **PR 43, acceptance receipts, test-only** (`gcc/p1-legal-acceptance-receipts`, base #42; head `82b6dfa3`). Write-once rows. Only the document types listed in `explicit_acceptance` are recorded, and that list is empty by default. Identity is off by default, and a guest is kept only as a SHA-256 hash. The legal entity comes from `GCC_LEGAL_ENTITY`.
- **lavkooapp PR 3, browse-only** (`gcc/p1-customer-sellable-browse-only`, base lavkooapp #2; head `eda78143`). `StorefrontCountry.sellable` is true for Saudi Arabia and false for the others. `StorefrontChoice.canSell()` refuses checkout for a browse-only storefront while storefronts are on. Verified in CI only: no Flutter SDK in this session.

Tests on SQLite: readiness 28 (PR 38); storefront unit and feature suites 136 (PR 43 branch); PR 40 web visibility 9 and admin security guards 10; PR 41 blog 7, with the security guards, 17 in total.

Blockers before any non-Saudi storefront goes live on the web:

- **Web checkout is not gated.** `checkout-complete`, `offline-payment-checkout-complete`, `checkout-complete-wallet` and the web payment routes carry no storefront context. On the web the storefront comes only from the path prefix, and a checkout request has none. The fix is to bind the storefront to the cart when the cart is created, which is not designed yet.
- **Seller POS and the seller API** are out of scope until the seller storefront context exists (phase 2).

Owner decisions and authorizations still needed. Nothing here was changed, because each changes authentication or ownership:

- `APIGuestMiddleware` passes any truthy `guest_id` (`app/Http/Middleware/APIGuestMiddleware.php:26`). The cart (`CartController@index`, the merge of a guest cart), the guest order-by-id call, and the edit-order routes accept it.
- The paid digital download is gated by a 4-digit OTP: `rand(1000, 9999)` in `OrderController`, at lines 730 and 870.
- Customer impersonation by request input: `Helpers::getCustomerInformation` takes `customer_id` from the request when `payment_request_from=app` and no user is set.
- The social-login confirmation route is reachable without authentication (`SocialAuthController`, line 211).

Still pending when this section was written: the verify phase of the `source=admin` audit, the checkout map's classification of the gateways and seller routes, the price and stock map for GCC-02/03, and the full SQLite suite on PR 40's branch.

Environment: the local MySQL on 127.0.0.1:3307 was stopped when I checked it. I started it with default options, and `root` now needs a password that no configured credential provides. No account was changed. MySQL runs for PRs 38, 39, 42 and 43 are still pending.

Next, in order: bind the storefront to the cart so web checkout can be gated; the Saudi price and stock characterization tests, then the seams; the seller storefront context; and the owner decisions listed above.

## 35. Audit disposition, the first GCC-02/03 slices, and MySQL verification (2026-11-16, about 03:00Z)

Same standing rules as section 34. Nothing is merged or deployed. Every new storefront gate is off by default.

### New draft PRs

- **PR 44, seller self-approval** (`gcc/security-seller-self-approval`, base `main`). The web vendor form and API v2 and v3 seller registration took `status` from the request body, so a visitor could send `approved`. All three public paths now create a `pending` seller. `VendorService::getAddData(..., createdByAdmin: true)` keeps the admin add-vendor form, which posts `approved` on purpose. The seller Flutter app never sends `status`. Red then green, 6 tests.
- **PR 45, social login confirmation** (`gcc/security-social-login-confirmation`, base `main`). `GET /customer/auth/login/social/confirmation?status=approve` and its update POST took the customer from the URL and never checked that the browser had just come back from the provider. Both now require the `social_login_new_customer` session entry, and its email must match the identity. Section 34 listed this as an owner decision. On a second look no normal flow depends on the old behaviour (the provider callback always sets the session entry first), so a draft PR is the safe form: it changes nothing until someone merges it. 9 tests, 6 red on the old code.
- **PR 46, search visibility** (`gcc/security-search-fallback-visibility`, base `main`). The translated-name searches had no `active()` scope, and the category filter of three search helpers was chained with `orWhere` outside every other condition, so a search inside a sub category returned every product of it, hidden ones included. Found while verifying audit candidates by running the real queries. 8 tests, 6 red on the old code.
- **PR 47, products belong to one country** (`gcc/p1-country-owned-products`, base #43). `products.storefront_country` (default `SA`, composite index) and `StorefrontCatalog::restrict()` called from `Product::scopeActive()`. It applies only when storefronts are on and the request was bound to a storefront. Before the migration, the default storefront is unchanged and any other shows nothing. 12 tests, also green on MySQL 8.0.46.
- **PR 48, cart guard** (`gcc/p1-storefront-cart-guard`, base #47). The cart routes of API v1 carry the `storefront` alias, and `CartManager::add_to_cart` refuses another country's product. 6 tests, and the alias-placement guard test now requires the alias on the cart routes.

Design choice for GCC-02/03: a product row belongs to one country. DECISIONS.md asks for independent products, prices, SKUs, availability and inventories by country, with deliberate admin-only copying. The price and stock map found about 400 sites (`OrderManager` 67, `CartManager` 40, `ProductManager` 21, `WebController` 21, `OrderEditManager` 21, and the rest), all of which read the product row. With the country on the row, none of them changes. A separate per-country price table, which the map's first draft proposed, would have touched every one of them.

Open after the first two slices: stamp `orders.storefront_country` (and refuse a place-order whose lines belong to another country), bind the web routes to a storefront, admin and seller tools that set the country, and make the product `code` and `barcode` validation unique per country before any admin copy exists.

### CI and carried repairs

- The Torod fixture repair `de1270f8` was carried onto PRs 40, 41, 44 and 45 (`8c6236dc`, `f096f4bc`, `0349aa7e`, `5e0e6b6f`). Before it, PRs 40 and 41 failed the SQLite job and the sales-channel N-Z and strict-mode jobs on `TorodShipmentDiscoveryTest`, the same pre-existing `main` failure as in section 28. The full SQLite suite on PR 40 at `84e3c1a1` ran 6209 tests with that one failure, which replaces the "pending" sentence in section 34.
- The overlay gate still fails by design on every PR from `main` (`firebase-messaging-sw.js`, `public/firebase-messaging-sw.js` and `robots.txt` differ from baseline `698aa1e`). It is not caused by any of these PRs.
- PR 43's checks were all green on its last run.

### Audit disposition: the `source=admin` sweep

The sweep returned 113 "confirmed" rows out of 303 candidates. Most are inventory (the Flutter apps' query paths, coverage notes, cleared items). Those marked confirmed from reading alone are not reliable: one was refuted by compiling the SQL.

Fixed in draft PRs: business pages (#40), blog drafts and unpublished posts (#41), seller self-approval (#44), social confirmation (#45), search visibility (#46).

Rejected after running it: `Product::scopeSellerApproved` as an unparenthesised `orWhere`. Eloquent groups the conditions a scope adds, and the compiled SQL is correctly parenthesised.

Cleared by the sweep itself: `?theme_preview` (HMAC token with expiry), the blog `source` click-count flag, the admin preview links, the tax-report `source`, and every Flutter query string (neither app sends a visibility flag; the only builder of `source=` in the apps is dormant).

Not fixed here, static findings that should be re-verified by running them before anyone acts, listed by what they expose:

- `GET /api/v1/seller?slug=` returns the seller's name, phone and image and the whole shop row, including `tax_identification_number` and the tin certificate URL, for any shop slug, with no approval check. Owner decision: what a public seller page may show.
- `GET /api/v2/seller/brands` is unauthenticated and lists inactive brands too.
- Reviews, rating and counters of a hidden product (`/api/v1/products/reviews/{slug}`, `/rating/{id}`, `/counter/{slug}`), the banner endpoint embedding a hidden product, shop or brand, `/api/v1/faq` returning disabled entries, `/api/v1/shipping-method/detail/{id}` returning disabled methods, and `POST /cart/variant_price` answering for any product.
- The `Review` global scope turns itself off when the URL starts with `/vendor`, `/seller` or `/admin`, as a prefix test, so public `/vendor-shop/...` and `/vendors` pages skip it.
- The about-us text is read without a status filter in `AppServiceProvider` and reaches every page's meta description and footer when the page is unpublished.

Owner decisions still needed, unchanged from section 34 because each changes how the apps authenticate:

- `APIGuestMiddleware` passes any truthy `guest_id`.
- `Helpers::getCustomerInformation` takes `customer_id`, and also a `user[id]` input, from the request on routes that reach it with no customer session. The payment WebView of the app uses this path, so it needs a signed-token design rather than a one-line fix.
- The paid digital download is gated by a 4-digit OTP from `rand(1000, 9999)`.

### A secret in the repository, for the owner

Four tracked files start with a PEM private key header: `public/assets/back-end/Apple-AuthKey.p8`, `public/assets/back-end/Apple-AuthKey (copy).txt`, and the same two names under `resources/assets/back-end/`. They are 257 bytes each, which is the size of an Apple Sign-In key, and they date from the initial commit. I did not open them. `.gitignore` lists `*.p8`, but a tracked file stays tracked. The two under `public/` are downloadable when the document root is `public`. Nothing in `app`, `config` or `routes` refers to the name. Owner action: confirm whether the key is real; if it is, revoke and replace it in the Apple developer account, remove the files, and keep keys outside the web root. I did not delete them, because removing a file from history needs a force push, which is not allowed here.

### MySQL verification

The MySQL on port 3307 still needs a password that nothing configured provides, and I did not touch its accounts. To verify without it, I started a private, throwaway MySQL 8.0.46 on 127.0.0.1:3308, with its own data directory under the scratchpad and `root` without a password. On it, the whole storefront chain (readiness v2 with its migration, the sellable list, the checkout gate, the acceptance receipts, and the new catalog and cart tests) passes: 136 tests for the chain, and 12 for the catalog file. PRs 38, 39, 42 and 43 are therefore verified on MySQL. The base schema dump `installation/backup/database.sql` is not in the checkout, so the migrations were not run against a copy of the production schema.

### Tooling notes for the next session

- Worktrees are under `scratchpad/wt/`, one per branch. `vendor` in each is a hardlink copy of `scratchpad/wallet-sandbox/vendor`; do not delete that directory. I removed 30 worktrees whose commits were all on GitHub; several others had symlinked into one of them, so I rebuilt their `vendor` from the surviving copy (210 packages, identical to `composer.lock`).
- `translate()` writes missing keys into `resources/lang/<locale>/new-messages.php` whenever a test runs. I restored the file before each commit on the new branches, so these PRs add no lang keys; the keys register themselves on first use.

### Still pending

The checkout-path map (gateways and seller routes) and the price and stock map were still verifying their last candidates when this was written. Their results are not used above except for the site counts, which come from the finished sweep phase.

### Addendum to section 35 (same day): the web blocker was smaller than recorded

Section 34 and PR 42 listed "web checkout is not gated, bind the storefront to the cart" as a blocker. Reading `RouteServiceProvider::mapBetaWebRoutes` again, the web storefront routes already carry the `storefront` middleware, so a web request is bound to a storefront from `?storefront=`, the `X-Storefront` header or a path prefix, and the Saudi storefront otherwise. The gap was only that the web sale routes had no gate. Corrected state:

- **PR 49, web sale gate** (`gcc/p1-web-checkout-gate`, base #48, head `c8448d92`). `storefront.sellable` on the three web checkout posts, `customer/web-payment-request` and `customer-add-fund-request`. The due payment on a placed order, the gateway pages and the callbacks stay open. The route test now checks every route that runs a sale action and matches the controller by its full name (a loose suffix had also matched the PayPal gateway). 5 tests, red without the routes. Storefront, Address, Fulfillment and Security suites: 2439 OK.
- No storefront needs to be "bound to the cart" as a stored value. A cart takes only the products of its storefront (#48), and every cart read goes through `active()`, which narrows to the storefront of the request (#47). A checkout judged as the Saudi storefront cannot see another country's items, and a checkout judged as another storefront meets the gate. In the odd case of a cart filled in one storefront and checked out in another, the web path ends in an error instead of a sale (the web cart list keeps a row whose product is no longer visible and fails on it); that is only reachable with storefronts on and a non-Saudi product present, and the result is not an order.
- What is still open on the web is the visitor's way to choose a storefront (path prefix routes or a switcher), which is a product decision, and the admin, vendor and seller tools, which are unbound and see every country until GCC-14 scopes exist.
- PR 46's full SQLite suite at `52a61295`: 6208 tests, one failure, the same Torod fixture. The repair is carried as `50e9037e`.
- PRs 42, 47 and 48 had said the web is unbound. Their descriptions are corrected.
- The checkout-path sweep (294 entries: 120 to gate, 114 exempt, 60 out of scope) matches this split. The routes it still lists as to gate and that no PR covers are the seller POS (v3 API `pos/place-order`, vendor and admin POS), the four API due payments on placed orders (left open on purpose), the marketplace order materializer and its scheduled dispatch, and the seller order edit routes. Those need the seller storefront context.

### Addendum: a paid digital file can leave through the product JSON (owner decision)

`Product::$appends` includes `digital_file_ready_full_url`, and its accessor (`getDigitalFileReadyFullUrlAttribute`) returns the public-storage URL of the "ready after sale" file whenever one is set and the file exists. Every serialization of a `Product`, which includes the product details and lists of API v1, can therefore carry the direct URL of the file, and the file sits on the public disk. That makes the OTP and purchase checks of the download route moot for anyone who has seen the product JSON. I read it in the code and did not run it. Whether it matters depends on whether the store sells digital products at all (`digital_product` business setting). Options for the owner: hide the attribute from public serialization, or move the files to a private disk and serve them through the download route only. The 4-digit OTP in section 35 is the same feature.

The sweep also asked to test the media routes around it, and the product-copy tool of the admin panel when it exists: a copy to another country must not copy the file URL or share the file.

Also not done, by decision: orders do not get a `storefront_country` column for now. An order's country follows from its lines (`order_details` to `products.storefront_country`), and a product never changes country, so adding the column before something reads it would be dead weight. Add it, with the stamp in `OrderManager::getOrderAddData`, when per-country accounting needs it.

### Addendum: product caches per storefront, PR 50 (flag-on prerequisite)

- **PR 50** (`gcc/p1-storefront-product-caches`, base #47, head `71aa7311`). With products owned by a country (#47), every cache built from `Product::active()` has to be written per storefront, or the first request after an invalidation would fill the entry for its own market and the others would be served that market's products. 25 sites across `CacheManagerTrait`, `product.php`, `ProductManager`, `CategoryManager`, `BrandManager`, the category and brand page services and `AppServiceProvider` now write under `storefrontCacheKey()`, and `cacheRemoveByType` clears every storefront's copy. A source scan test fails when one of the listed constants is used without the helper.
- A second trap found on the way and fixed in the same PR: with storefronts on, a request bound to no storefront (admin panel, queue, console) reads every country and would have written the plain key, which is the Saudi storefront's key. It now writes `storefront:*:<key>`.
- **Before the flag is turned on anywhere with a non-Saudi product**, these must be true, in this order: the migrations of #38, #43 and #47 have run; #47 to #50 are merged; the admin and vendor tools list products per country (GCC-14), because they are unbound and see every country; the legal texts of the new country are approved (#42, #49). The checklist is also the reason the flag stays off.
- Not done on purpose: caches whose content does not depend on which country owns a product (settings, currencies, banners, help topics, tags, business pages, the reviews list).

### Addendum: one more audit item fixed, PR 51 (reviews)

- **PR 51, review scope** (`gcc/security-review-scope-segments`, base `main`, head `682255ce`, Torod repair carried as `a2635a1d`). The `Review` global scope hid unapproved reviews with a prefix test on the address (`/admin`, `/seller`, `/vendor`, `/api/v2`, `/api/v3`), so the public `/vendor-shop/...`, `/vendors` and `/seller-profile/...` pages and anything that merely starts with those letters skipped it. The panels are now matched by path segment. Run, not read: 4 of the 13 cases are red on the old code. On those public pages, ratings and review lists now count approved reviews only.
- Section 35 listed this as a static finding. It is now confirmed by running it and fixed. The other low-severity items of section 35 are unchanged.

### Addendum: reconciliation with the earlier draft PRs, PR 52, the verified checkout map

**Correction to the owner decisions in section 35.** Two of the items listed there are already addressed by earlier draft PRs, which I had not cross-checked:

- `Helpers::getCustomerInformation` taking a customer from request input (a posted `user`, a posted `customer_id` with `payment_request_from=app`): **PR 23** (`gcc/p0-trusted-request-identity`, draft) makes identity come only from the customer guard, the request's resolver or the session, and keeps the payment callback working. It is unmerged and waits for the financial and security review it asks for. It also says what is left: the guest id is sequential and guessable, which is the `APIGuestMiddleware` decision below.
- The edit-order and due-payment routes that load any order by id: **PR 21** (API) and **PR 22** (web). Seller order and refund lookups: **PR 20**; seller tokens on v2 refunds: **PR 27**; seller withdraw updates and closes: **PR 32**. Signed-in customer access to guest orders: **PR 16**.

What is still an owner decision: the guest id (`APIGuestMiddleware` passes any truthy `guest_id`, and guest ids are guessable), the 4-digit OTP of the paid digital download, and the digital file URL in the product JSON.

**PR 52, seller APIs and ownership** (`gcc/security-seller-product-ownership`, base `main`, head `c6c8ae4e`, Torod repair carried as `4e9d9902`). `seller_can:products.manage` checks a role, not whose product it is. v3 `edit`, `updateProduct`, `delete`, `review_list`, `barcode_generate` and v2 `edit`, `update`, `status_update`, `delete`, `barcode_generate` looked a product up by id, so any seller or staff member could read, change (the update assigns `user_id` to the caller), switch off or delete another seller's product or an in-house one; the review status and reply handlers of v2 and v3 did the same for reviews, and the v2 barcode route had no token check. All now go through `Product::ofSeller()` and answer 404 in the shape of PR 20. 23 tests, 18 red on the old code. Found while following the seller self-registration bypass (PR 44): once an account is approved, v2 let it act on anyone's products.

**The verified checkout path map** (265 entries after de-duplication; 164 exempt, 87 to gate, 14 out of scope after verification, which changed the proposal of 98 entries). It agrees with PRs 42 and 49: the order and payment initiations are gated; the gateway pay pages, due payments on placed orders, refunds, cancellations and status checks are exempt. Three things came out of it:

- The web reorder (`POST /order-again`) had no gate while its API twin did. Added in PR 49 (`26991196`).
- The verifiers marked the gateway callbacks that create the order after payment as "gate". I kept them exempt: the money has been taken by then, and the owner's rule is that callbacks and old obligations continue.
- Still to gate once the seller storefront context exists, and each needs an owner decision on whether a point-of-sale sale is a storefront sale: `v3/seller/pos/place-order`, the vendor and admin POS `order-place`, the marketplace order materializer and its scheduled dispatch, the sales-channel webhooks. The Auction module is enabled in `modules_statuses.json` but its directory is not in this checkout, so its entry points could not be listed.
- The critic's other gaps are not checkout: admin and vendor `payment_status` writes on any order, seller API v3 `update-payment-status`, the admin wallet credit, and scheduled marketplace settlement. They are money movements by staff, listed for the money review, not changed.

**Stack order for merging.** One chain: #28, #37, #38, #39, #42, #43, #47, #48, #49, #50 (each on the previous one). Independent, on `main`: #40, #41, #44, #45, #46, #51, #52 and the older fixes #16 to #36. The price and stock map (399 sites) is still verifying; its sweep phase is done and its design conclusion (a product row belongs to one country) does not depend on the verification.

### Addendum: independent review of the GCC-02/03 stack, PR 46 follow-ups, PR 53 (payment callbacks), 2026-11-16

**Independent adversarial review of #47 to #50.** A reviewer who had not written the code ran it against the stack. Six things were wrong in the first versions and are fixed in the heads of the PRs, with a test each:

- With storefronts off, the catalog scope still built the resolver, so a missing or broken storefront configuration could have broken the catalog while the feature is meant to be dormant. The scope now reads the flag first and reads nothing else while it is off (#47).
- A storefront request the middleware had not reached yet (the providers boot before any middleware, and `/api/v1/config` runs without it) was not narrowed. It is now narrowed to the storefront it asks for, by the same rules as the middleware (`StorefrontResolver::contextForRequest`: explicit `?storefront=`, then the `X-Storefront` header, then the path prefix). Panels (`admin`, `vendor`, `seller`), the seller APIs (`api/v2`, `api/v3`), queues and the console stay unbound and see every country, as before (#47, and the same rule for cache keys in #50).
- `storefront_country` appeared in product JSON, which the Flutter models parse. `Product::$hidden` now lists it (#47).
- The migration could not finish after it stopped between the column and the index. The two are checked separately now (#47).
- `CartController::update_variation` read a product by id with no storefront guard, the second cart entry besides `add_to_cart` (#48).
- The cache registries of dynamic keys (flash deals, cart lists, category and brand keys) hold the key that was written, so clearing a group clears the scoped key too (#50).

**PR 46 follow-ups** (`0c370141`). The same top-level `orWhere` chain existed in the web product list (`ProductManager::getProductListData`, the `search_category_value` filter), and the quick view's related products skipped `Product::active()`. A list request with a main category in `category_ids[]` and a sub category in `search_category_value` returned all five seeded rows, hidden ones included, on the old code; it returns the two visible ones now. `SearchVisibilityTest` has 9 tests, `tests/Feature/Security` 19.

**PR 53, payment callbacks keep the storefront** (`gcc/p1-payment-callback-storefront`, base #50, head `fb8e983d`). Found by reasoning about the order of events, then reproduced by running it: a gateway reports back in a request that names no storefront, so it is served as the default storefront. The order is built from the cart through `active()`, which is narrowed to the storefront's country since #47. A customer who paid in Kuwait would have had an empty cart and no order, with the money taken. The storefront is now recorded in the payment's `additional_data` when the payment starts (`Payment::generate_link`, the choke point every gateway passes) and bound again while `digital_payment_success` builds the order, then restored, so a queue worker does not carry it into the next job. A storefront switched off since the payment started is still the one it is finished in. A payment with no recorded storefront is finished as before. Nothing is recorded or bound while storefronts are off.

- The hook `digital_payment_success` keeps its name and is now a wrapper. The old body is `digital_payment_place_orders`, unchanged.
- The customer app starts a payment through `POST /api/v1/digital-payment` (and a top-up through `/api/v1/add-to-fund`) with its normal HTTP client, which sends the `X-Storefront` header while storefronts are on (customer app PR 1). So the storefront is recorded when the payment starts and the app needs no change for this. It then opens the returned `redirect_link` in a WebView, and the gateway's callback never carries the header: that is the case #53 handles. The app does not use the web route `/customer/web-payment-request` at all (checked in `lib`).
- Found, not changed: `digital_payment_place_orders` writes `storage/logs/order_generate_test.txt` on every paid order (customer id, guest id, address id, order ids), debugging that was never removed. It should be removed, and it is outside the storefront work.

**Checklist before the flag is turned on, additions.** Run `php artisan cache:clear` once at the moment of turning it on (#50). Payments that started before the deploy carry no storefront and finish as before (#53).

**Stack order for merging.** One chain: #28, #37, #38, #39, #42, #43, #47, #48, #49, #50, #53. Independent, on `main`: #40, #41, #44, #45, #46, #51, #52 and the older fixes #16 to #36.

**MySQL.** The private MySQL 8.0.46 on 127.0.0.1:3308 passes the Storefront feature suite at the tip of the stack (152 tests, 669 assertions) and the Address payment callback and initiation tests (15). Two cart guard tests failed on MySQL only, because they created the `carts` table inside the test and MySQL keeps it between tests; the table is now part of the shared fixture (`457900e5`, merged up the chain). A tooling trap: `ss` is not installed in this container, so "nothing listens on 3308" was a false answer; check with `mysql -uroot -h127.0.0.1 -P3308 -e 'select 1'`. Do not start a second `mysqld` on the same data directory.

**Still running.** The adversarial execution review of #44, #45, #46, #51 and #52 and the integration merge check was started as a workflow. The machine has four CPUs, so it runs two agents at a time and is slow. Its findings are not in yet. The first reviewer, on #44, found no regression for a legitimate caller.

### Addendum: second independent review of the GCC-02/03 stack, what it found, what was fixed, three new PRs (2026-11-16, about 09:30Z)

**The review.** A workflow of 37 agents (about 15 million tokens, 4.3 hours) attacked the stack at `fb8e983d` by execution: dormancy with the flag off, isolation between countries, checkout and cart, payment callbacks, caches, the migration and data layer; a skeptic re-ran every finding from a clean checkout and compared it with the base, and a critic named what nobody examined (four of its five gaps were then verified by execution). Result: 12 confirmed, 11 pre-existing at `origin/main`, 2 refuted. Nothing that was a flag-off change for the Saudi storefront survived, apart from one low finding (below).

**Why most of it was one thing.** A cart group is per customer and seller, not per storefront, so a customer who shops two countries had both countries' rows in one group, and every writer that acts on a whole group or a whole customer cart ignored the storefront. The fix is one global scope on `Cart` (`StorefrontCartScope`), not a patch per writer: it narrows every read and write of a cart row to the storefront's products while the flag is on, so adding from two storefronts makes two cart groups and an action in one storefront never reaches the other's rows. Patching each consumer would have left the ones nobody found; the critic found five more writers (select-cart-items on web and API, remove-all on web and API, buy-now) within minutes.

| Finding | Where it is fixed |
|---|---|
| Cart cleanup after an order deletes the other storefront's checked rows and shipping; a second paid payment can then end with no order | #48 (Cart scope) |
| Zone shipping's free-over threshold summed the other storefront's rows | #48 (Cart scope) |
| Cash on delivery refused because of an invisible row of another country (the guard joins `carts` and `products` itself) | #48 (`restrictJoinedProducts`) |
| remove-all, select-cart-items, buy-now reached the other storefront's rows | #48 (Cart scope) |
| `POST /cart/variant_price` answered the price and stock of any product id | #48 |
| Web restock request subscribed a customer to a product the storefront cannot see | #48 |
| `GET /api/v1/banners` inlined another country's product payload | #48 |
| Search index pool (cap of 500) filled by the largest catalogue; a later storefront got 10 results where Saudi got 60 | #48 (`restrictProductIds`, before the cap) |
| Arabic (translation-only) search listed every country's product with price and stock; the search category filter escaped the scope | #46, merged into the tip #53, 5 new tests |
| Reviews of another country's product returned its price and stock inside each review's product; related, rating, counter, share link answered for it | #47 |
| Index (country, type, status, request status) never chosen; id-ordered lists walked every newer foreign row (1.9 ms became 281 ms on 200,000 rows) | #47: index is now (country, id), 2.0 ms |
| Country code compared case-insensitively in MySQL but strictly in PHP; bad values hide a product from every storefront silently | #47: `ascii_bin` on MySQL and MariaDB; the validation belongs to the admin tool (GCC-14) |
| A rollback of the migration would merge the catalogues | #47: `down()` refuses while another country has products |
| A broken or stale `gcc` configuration failed the cart API routes (and every web route already) while the flag is off | #47: while off, `fromConfig()` falls back to the built-in Saudi storefront; while on it still fails loudly |
| API v1 routes that vary on `X-Storefront` outside the storefront middleware sent no `Vary` | #47: `VaryByStorefront` on the whole v1 group, header only |
| Per-storefront cache copies written while the flag was on survive a product edit made while it was off | not changed: `cache:clear` at every flip (runbook); fanning every forget out while off would cost 168 forgets per product save |
| Nothing ran the storefront tests on MySQL or on MariaDB (the pilot's engine) | #47: a CI group on both |
| The flag off is not a kill switch once another country has products (nothing is narrowed; an in-flight payment is built from every checked row: paid 275, order 375) | `docs/gcc/STOREFRONT_FLAG_RUNBOOK.md` and `StorefrontKillSwitchTest`: deactivate the country, never the flag |

**Pre-existing at `origin/main`, found on the way, each its own draft PR with a red-then-green test:**

- **PR 54** (`gcc/security-cart-update-variation-owner`, head `856309ca`): web update-variation loaded the cart row by the id in the request with no owner check and no check that it belongs to the product named, then took the price from that product: a 100 product was ordered for 1 (reproduced). API select-cart-items took any row ids. Both are scoped to the caller now.
- **PR 55** (`gcc/fix-referral-first-order-notification`, head `cbd976a6`): the first order of a referred customer added the promoter notification in the wrong shape and threw after the order was committed: cart not emptied, hook reported failure, a second delivery of the callback built a second order.
- **PR 56** (`gcc/fix-payment-hook-state-between-settlements`, head `430832b8`): the success hook merged the registered customer into the request and never removed it, so in one process (the scheduled reconcile) a guest settled after a registered customer was read as that customer: no order, payment marked applied, the guest's app told it paid with no order.
- Left as known limits: wishlist and compare adds accept another country's product id; the quick view answers 500 for an id the storefront cannot see.

**Heads now.** #47 `e7c9a632`, #48 `68f9ae8a`, #49 `467d2eb9`, #50 `efea438b`, #53 `c3fddcb0`. The stack's merge order gains one rule: **#46 first** (the stack's narrowing of the translated-name searches depends on it). The tip contains #46 and the runbook.

**Verification.** Storefront, Unit/Storefront and Security suites on SQLite at the tip: 266 tests; Storefront, Unit/Storefront and `SearchVisibilityTest` on MySQL 8.0.46 at the tip: 256 tests. Address, Fulfillment, Security and Unit suites on SQLite at `68f9ae8a` (the Cart scope's first commit): 3443 tests, 763231 assertions, no failure. Every new test is red on the old code. A full SQLite run of the final tip and MariaDB (not installed here, CI has it) are still to do.

**Round 3.** A third review is running on the fixes themselves, the new Cart scope above all, and on PRs 54 to 56 (`wf_650a02d8-e7e`). Do not push to the stack branches while CI runs: every push cancels the run before it. Open: Flutter (the picker, `canSell` in the checkout UI), GCC-14 (admin and seller tools that set and filter the country; validation of the code against `gcc.storefronts.countries`; the per-country unique SKU rule), the MariaDB run, and the owner decisions of section 35.
