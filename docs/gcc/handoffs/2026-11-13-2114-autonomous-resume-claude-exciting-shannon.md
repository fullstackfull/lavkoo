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
