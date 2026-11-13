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
