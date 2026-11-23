# Handoff: autonomous development, tasks 1–5 (2026-11-13)

Sanitized. Nothing here is a secret or personal data. Every PR is a **Draft**. None is merged, none is deployed. G0 remains open.

## Pull requests and heads

| PR | Branch | Head | Base | What it does |
|---|---|---|---|---|
| #1 | `gcc/p0-saudi-baseline-ci` | `d664ab4b` | main | Torod fixture repair (test-only). Overlay job fails, as known. |
| #2 | `gcc/p0-admin-coupon-translation-key` | `1ca1c4d8` | main | Coupon message key. |
| #3 | `gcc/p0-remove-duplicate-payment-routing` | `24cc1033` | main | Removes the duplicate payment-routing class and the duplicate Paymera controller. Owner sign-off needed. |
| #4 | `gcc/p0-coupon-minimum-boundary` | `1d000dfa` | main | Coupon boundary tests. Owner-approved exception. |
| #5 | `gcc/p0-missing-message-keys` | `cf7d3e28` | main | Two coupon refusal messages defined in language files. |
| #6 | `gcc/p0-saudi-wallet-characterization` | `5c4b3e66` | main | Wallet ledger characterization. Torod repair carried on this session (fast-forward). |
| #7 | `gcc/p0-paymera-callback-security` | `b2ff9be9` | main | Paymera secret read from config. Callback logging removed. Security review needed. |
| #8 | `gcc/p1-storefront-context` | `adf923ce` | main | Storefront country resolver, behind a flag that is off by default. |
| #9 | `gcc/p0-wallet-concurrency-lock` | `41454349` | main | Wallet writes take a row lock. |
| #10 | `gcc/p0-loyalty-balance-lock` | `4015ae17` | #9's branch | Loyalty writes take the lock. Points-to-wallet is atomic. Stacked on #9. |

## Evidence

- **Wallet lost update** reproduced on MySQL 8.0.46. Ten debits of 1.0 from 100.0 ended at 99.0, not 90.0, in three of three runs. Fixed in #9. On `origin/main` all four write paths fail. On #9 all four pass.
- **Loyalty lost update**: ten credits of 10 points each ended at 120 (repository) and 110 (CustomerManager), not 200. **Conversion race**: four simultaneous 60-point conversions from 100 all succeeded. Fixed in #10. On `origin/main` the same tests fail. On #10 they pass.
- **Paymera** (#7): the header secret came from source. The callback wrote full payloads to debug files. Both removed. Seven tests, and all seven fail against the old controller.
- **PR #6 failure**: `TorodShipmentDiscoveryTest::test_the_scheduled_run_links_what_appeared_later_and_keeps_the_status_of_a_linked_shipment_current` expected `unlinked`, got `needs_review`. Reproduced locally on `0a4bb27d`. It is the known fixture time-bomb. The one-line repair (`purchased_at` set to ten hours ago) is on #1 and carried to #5. Carried to #6 as `5c4b3e66`, verified locally: 12 Torod tests pass.
- **Overlay job** on every PR fails with the known reason: the diff against baseline `698aa1e` includes `robots.txt` and the Firebase service-worker files. This is not caused by these PRs. Owner decision: `PILOT_BASELINE`, or deploy those files by hand.

## Owner actions required

1. **Paymera secret rotation.** The previous header value is in git history on `main`. An authorized person rotates it with Paymera. Then set `PAYMERA_SECRET_KEY` in each environment, before deploying #7. Delete the old debug files on servers after a retention review. See `docs/integrations/paymera.md` on #7.
2. **Overdraft.** Writers accept a debit above the balance. Controllers check the balance outside the lock, so two guarded debits can overdraw. Decide whether writers refuse overdrafts (then check inside the lock).
3. **Refund debits** can take loyalty points below zero (`refund_order`). Owner decision.
4. **Two wallet-credit formulas** for the same loyalty conversion: REST uses `CustomerManager`, web uses the `CustomerTrait` writer. Finance review needed.
5. **No idempotency keys** on wallet or loyalty writes. Characterized in tests, not endorsed.
6. **PR #3** needs payments sign-off (duplicate removal).
7. **G0** remains open. No GCC phase may be merged or released before it closes.

## Not started (Task 2 scope)

- Seller and admin permission tests.
- Inventory and holds tests.
- Full ledger, wallet and bonus reconciliation. PR #6 covers the bonus discrepancy only.

## CI status at time of writing

- Local full-suite runs were not completed (too slow to finish). **CI on each head SHA is the authoritative run.**
- #1: every check passes except the overlay job.
- #2 to #5: checks in progress at last read. Overlay failing, as known.
- #6: re-run after `5c4b3e66` is pending. The scheduled follow-up will re-read it.
- #7 to #10: pending.

## Next

- Task 5: next slices behind default-off flags. Wire the resolver where the request enters. Then catalog and SKU isolation design. Then GCC-20 read-only monitoring, only after owner review.
- Task 2: permission and inventory tests, and the reconciliation work above.
- Keep the Saudi baseline and the default-off flags. Do not merge.
