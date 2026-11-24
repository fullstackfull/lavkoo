# The unified main, repaired, with the whole suite green

**Head:** `051a75c2` on `main` · **engine of record:** MariaDB 10.11 (`phpunit.mysql.xml`), SQLite where a figure
differs by engine · **date:** 2026-11-24

## What this was

The branch-collection phase ended with all 185 branches merged. This pass merged nothing further. It looked for what
the merges had broken, fixed it, amended the tests that contradicted the three decisions the owner adopted, and ran
every test directory on the result.

Nothing found here was visible to a linter. The tree lints clean (4,463 files, `php -l`), no two files declare the same
fully qualified class (3,899 declarations), and there is no conflict marker anywhere. Every defect below is a merge
that produced **valid code with the wrong meaning**, nearly always by keeping both lanes' version of one thing.

Two scans were written for that shape and are in the scratchpad rather than the repository, because they are
throwaway tools: one for a statement that can never run (a `return` followed by more code), one for an assignment the
next line throws away. Between them they found four of the defects below. A third scan — a comment paragraph that
appears twice in one file — found the fifth.

## The money defects

**Approving a seller's withdrawal paid it twice.** `VendorController::withdrawStatus()` held the payout twice: once
inside the locked transaction, once after it with no row lock and no state guard. An approval raised `withdrawn` by
the amount twice and lowered `pending_withdraw` by it twice — on the test's 200 that is `withdrawn` 400 and
`pending_withdraw` −200. The second copy is deleted; the company the ledger entry is filed under, which only it
carried, moved into the locked transaction. `b91a2009`, with `SellerWithdrawConcurrencyTest` (7) as the proof.

**Two live double debits at the wallet checkout**, plus a dead third copy of the due-payment block. `dc0acc32`.

**`usdToDefaultCurrency()` returned a figure one minor unit below the one it was given** — `floor()` of a binary
double is not idempotent, so 0.58 came back 0.57. Measured over the first 100,000 exact minor-unit figures: 4,586 of
them (4.59 %) came back low at two decimals, 741 at three. `7e6c93aa`, and the editable-field helper had kept both
lanes' version of the same repair, the dead one reading a variable that does not exist in it (`9d603e12`).

**Three refund rows were stamped with the order's currency twice** — the refund lane fills the columns as the row is
inserted, the D1-06 lane stamped them again after the save, finding nothing to fill and spending a read and an update
on every refund. The three later calls are gone; the refund route now issues the base's 20 statements and its two
writes, and on SQLite the payout route's statements are the base's byte for byte. `a49d24bf`.

**The attribution every wallet writer asked for was being dropped**: `WalletTransaction::$fillable` did not list the
seven columns the writers fill through `LedgerAttribution`, and `fill()` ignores what is not fillable. `8f4811d6`.
Then the origins themselves, lost at nine call sites by the merges and restored from the commit that wrote them —
the order edits, the POS-cancel credits, the refund points, both panel tills and the seller app's delivered-order
points. `7b05bea7`.

## Permissions and scope

**A product a seller added in the vendor panel took the wrong country.** `ProductService` held both lanes' version of
one assignment, the second throwing the first away, so the panel saved the table default (SA) for every seller while
the seller app saved the market they were granted — one seller, two screens, two countries, and the selling company
that follows from the country gone with it. `6bcba001`.

**The vendor till sold other vendors' products** (`bb5485a8`) and **two seller endpoints stopped refusing an in-house
order that shares the seller's id** (`8f707ed9`) — both restored.

**The seller app's till answered a mixed receipt with `product_not_available`**, which tells the till a product is out
of stock. The shared refusal — 422 `{"code":"order_mixes_selling_companies"}`, the body the customer API already
answers — is restored. `a49d24bf`.

## Saudi compatibility

Checked at every step, and unchanged throughout: one country in, one order out at all three tills, for the same
money; the Paymob return still settles on `success=true` alone (`975018ff` restored the strict check a merge had
loosened to accept `TRUE` and `1`); a storefront's legal texts come back in the configured order (`93754fa0`); and
the Saudi route surface is declared route by route, with the one route the payment-account lane adds written out.

## The three decisions, as adopted

1. **The ledger's company column is filled whenever the column exists**, both switches off, so the history exists to
   split later — `2abbc9f9`, with a case proving two companies are each recorded while the seller still has ONE
   balance.
2. **An unauthenticated Paymob return decides nothing, the failure hook included** — `b7fa8f56`, with a case proving
   a stranger cannot fire the hook while the real return still settles the payment.
3. **D1-02 holds: an order names one country and one selling company, and a mixed cart is refused** — `f00e983d`,
   extended here to the seller app's till, where the rule was being answered with the wrong refusal and where the
   test's own fixture had stopped reaching it.

Where a lane's test contradicted a later, money-correct rule, the test was amended to pin the rule, never disabled.
The largest of those: `WalletCheckoutLedgerTest` asserted that a wallet checkout of each of the six storefronts writes
an attributed debit, but while a storefront trades in its own money its wallet is **closed** — one balance in the
platform's money cannot pay a dinar order (`EnsureWalletIsOpenInStorefront`). The refusal wins; the test now proves
the whole attribution on the default storefront, the refusal with nothing written on the other five, and the company
half of the attribution on those five while their money is the platform's.

## The suite, on this head

| area | tests | area | tests |
|---|---|---|---|
| Storefront (4 chunks) | 4,038 | Security (3 chunks) | 4,183 |
| SalesChannels (3 chunks) | 1,977 | Fulfillment | 1,436 |
| Address | 1,190 | PlatformAdmin | 873 |
| Marketplace + Payments + MyFatoorah | 714 | OperationalCosts | 683 |
| Wallet + Unit/Wallet + Unit/Customer | 506 | StaffScope | 459 |
| Catalog | 456 | Accounting + Reports + Retention + Marketing | 452 |
| 17 smaller Feature directories | 450 | Concurrency | 109 |
| 10 Unit directories | 1,820 | | |

**19,346 tests, 886,227 assertions, 0 failures, 0 errors, 18 skipped**, in 22 chunks, each against a database created
for it and dropped after it.

## What is still open, and not hidden

- **`routes/test.php`** holds unreachable scratch code (`return "bdsb"; return view(...)`). It dates from the initial
  upstream commit, not from the assembly, and is a development-only route file. Left alone.
- **The delivery-man withdrawal approval** applies its movement once, but takes no row lock and has no state guard,
  where the seller one now has both. That is how it has always been; closing it is a change of its own.
- **170 of main's 355 merges were resolved by hand** (their trees are not what git would have produced). The defects
  above came out of those. A line-by-line audit of the rest is not proportionate, and the suite is the better
  detector; it is now green.
- **Branch cleanup:** 121 draft pull requests are closed and no pull request is open in any of the three
  repositories. The 228 branches whose content is fully in main could **not** be deleted from this session — both
  `git push origin --delete` and the GitHub ref API answer 403 ("Write access to this GitHub API path is not
  permitted through this proxy") while an ordinary push to `main` is allowed. The full list with tip SHAs is in
  `docs/gcc/audits/BRANCH_CLEANUP_2026-11-24.md`.

## Ready to go back to the GCC plan?

**Yes, for development.** The unified `main` builds, lints and passes every test, the money paths that the merges had
doubled or silently disabled are closed with a test each, and Saudi behaviour is measured as unchanged at every till,
gateway and refund path.

**No, not for release.** G0 is still open and nothing here changes that. No production deploy, no production database
access, no seller app version change and no merge beyond `main` was made or is implied by this pass.

