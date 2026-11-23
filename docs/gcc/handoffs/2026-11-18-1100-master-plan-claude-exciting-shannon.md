# Handoff: master plan completion batch (2026-11-18, late morning)

Sanitized. Nothing here is a secret or personal data. Every pull request is a **Draft**. None is merged, none is deployed, no production data or key was touched, G0 stays open, and the deferred risks DR-1 to DR-5 are still open (no key was rotated).

## What the owner asked for

"LAVKOO GCC: master plan completion directive": publish a measured tracker of the whole multi-country plan, then implement the highest incomplete executable requirement, test it on isolated databases, check that Saudi behaviour is unchanged, commit, push, update the draft pull request and the tracker, and repeat; integration-only CI branches are allowed and are never merged. Forbidden without separate approval: merge to `main`, deployment, real data, key changes, migrations on production, a change of a live financial or legal rule, bypassing hooks or protection.

Read first: [MASTER_IMPLEMENTATION_TRACKER.md](../MASTER_IMPLEMENTATION_TRACKER.md) (states, evidence, percentages and their formulas), [PR_LEDGER.md](../PR_LEDGER.md) (every pull request, its base and head; run `python3 docs/gcc/tools/check_pr_ledger.py` to see whether it is current), [MERGE_ORDER.md](../MERGE_ORDER.md), [DEFERRED_RISKS.md](../DEFERRED_RISKS.md) and the audit [all-in-one integration](../audits/2026-11-18-all-in-one-integration/README.md).

## Heads at the time of writing (lavkoo)

| PR | Branch | Head | Base | What it is |
|---|---|---|---|---|
| #87 | `gcc/p1-storefront-currency` | `44b53616` | #64 | GCC-05 slices 1 to 5: currency of a storefront, its amounts, shipping, panel order edit, coupons, readiness, minimum-order gate proof, closed wallet |
| #88 | `gcc/p1-wallet-reconcile-command` | `b5a3b5d1` | `main` | E-06 `wallet:reconcile`, read only; CI run 285: 20 of 21 jobs green (the overlay job is red by design) |
| #89 | `gcc/p0-coupon-use-count-strict-sql` | `ea6827da` | `main` | three checkout statements a strict SQL server refuses |
| #90 | `gcc/p1-legal-entity-registry` | `26995055` | #87 | GCC-13: the selling company, the order stamp, the invoice that prints it |
| #91 | `gcc/p0-branch-protection-draft` | `faa06167` | `main` | the protection rule for `main` and CODEOWNERS, documents only, nothing applied |
| #92 | `gcc/p0-test-empty-real-database` | `cdf825b2` | `main` | every test starts from an empty database on a real server |
| #93 | `gcc/p1-remove-order-debug-file` | `5e128f06` | `main` | the per-order debug file of customer, address and order ids is no longer written |
| #94 | `gcc/p0-ci-every-test-directory-on-a-real-engine` | `a0289e75` | `main` | a guard: every test directory runs on a real MySQL or MariaDB or says why not |
| #95 | `gcc/p1-wallet-balance-writers-guard` | `ce4d2950` | `main` | E-03 source guard for the writers of the wallet and loyalty balances |
| #96 | `gcc/p1-storefront-six-country-isolation` | `6f55384e` | #87 | isolation for every ordered pair of six countries; one customer across the storefronts |

`gcc/release/all-integration-ci` (head `1365cdad`, CI only, never merged) holds all of the above on top of the 82 other main-based pull requests. Its run 293 on the earlier head `8598331b` had 25 of 29 jobs green, one skipped by design, three still running and none red when this was written; read it per job before relying on it. Apps: lavkooapp #8 (`97399d4f`) and #9 (`45bfed30`), both green in the app repository's own CI.

## What was done in this batch

- GCC-05 slice 5 on #87: the amounts a storefront compares with (`storefront_money_<country>`, written only by `gcc:storefront:money`), product-wise shipping, the panel order edit in the order's own money, the coupon that belongs to one storefront (storage, read side, write side, screens), the readiness lines, the minimum-order gate proof through a real API checkout, and the wallet closed on a storefront with its own currency. Everything is off by default and write-free while off.
- GCC-13 step 2 on #90: the three invoices print the company frozen on the order.
- Guards and proofs: #95 (the six writers of the balances), #96 (six-country isolation, one customer), #94 (every test directory on a real engine), the union's real-engine group and strict-mode paths.
- E-06 CI read, the integration reports, this ledger and a truthful STATUS.

## Things the next session must know

1. **A push to a pull request cancels the run of its previous head** (`cancel-in-progress` for pull requests) and runners are saturated: pushing every few minutes means no CI is ever read. Batch the commits, push, then wait.
2. **Check `git status` for `??` files before every commit.** The commit `27f30e15` named a migration and did not contain it (fixed in `5ad7bfa8`); the tests had passed locally because the file was on disk.
3. SQLite hides defects: a bare `GROUP BY`, an unknown double-quoted name read as a string and no row locks. Real-engine runs found 34 fixture failures and three statements a strict server refuses (#89, #92, #94).
4. The coupon-boundary exception of `CLAUDE.md` applies to its own branch only; `min_purchase > eligible total` is not to be touched.
5. Findings for the owner, not changed: a double submit of the checkout can answer 500 to the second request; the platform's `usdToDefaultCurrency` floors and loses a digit for some two-decimal amounts (0.29 shows as 0.28); 23 test directories had only ever run on SQLite.

## Next, in order

1. Read the CI of #87, #90, #89, #92, #93, #94, #95, #96 per job as the queue drains; fix a red only on the pull request that owns it; flip the tracker's CODED criteria that the green jobs prove.
2. When run 293 ends, merge the newer heads into `gcc/release/all-integration-ci`, dispatch one more run and read it per job; mark requirements whose pull requests are all in a green union INTEGRATED AND TESTED.
3. Slice 5 left: the tax and refund snapshot (D1-07, D1-10); then the per-market wallet and loyalty accounts (E-01, E-02, E-05, E-07: the expand-only migration and the shadow write; owner decisions open on the key, the unit and the bonus rule).
4. Owner decisions that block code are listed on the pull request bodies (#87, #90) and in the tracker cards; none was taken on the owner's behalf.
