# Handoff: the seller integration branch is run per step, twenty-one seller criteria are met, workflows are closing the gaps of the F, I, J and M axes (2026-11-18, 21:15 UTC)

Sanitized. Nothing here is a secret or personal data. Every pull request is a **Draft**. None is merged, none is deployed, no production data or key was touched, G0 stays open, and the deferred risks DR-1 to DR-5 are still open. This continues [the handoff of 19:40](2026-11-18-1940-flips-audit-and-fixes-claude-exciting-shannon.md). **No approval was recorded by Claude or by CI.**

## Measured (the tracker of this commit against `4cd56ee8`)

| | Before | After |
|---|---:|---:|
| Criteria | 1,675 | 1,675 |
| MET | 736 | **757** |
| CODED | 350 | **331** |
| MISSING | 589 | **587** |
| Code completion / proven by a passing test / release readiness | 64.8% / 43.9% / 7.7% | 65.0% / 45.2% / 7.7% |

| Movement | Count | Which |
|---|---:|---|
| MISSING to MET | **1** | K-05.4: a scan that finds no product tells the seller (lavkooappseller #5, `83893fa`, four behaviour tests, read in run 29) |
| MISSING to CODED | **1** | K-15.4: each feature's tests run as a named step on the integration branch (not yet on the pull requests) |
| CODED to MET | **20** | K-01.0, .2, .3 (market scope, the header map of main), K-02.0 to .3 (the market choice), K-05.0 to .2 (the scan request and its refusals), K-07.0 to .3 (order edit refusals and the approved-only average), K-10.0 and .4 (a currency per market; All Markets has none), K-12.0 (the credential header), K-12.1 (a burst of 401s signs out once: lavkooappseller #11, run 31), K-13.0 (the scan's Arabic and English keys), K-16.0 (flag off: main's requests) |
| A whole requirement READY FOR OWNER REVIEW | 0 | no reviewer is named; **no approval was recorded by Claude or by CI** |

## What was done

- **The seller app has an integration branch and it was run per step.** `claude/seller-all-integration-ci` (`f695f8c`) merges the heads of lavkooappseller #1 to #10 (the conflicts were the CI file, two language files and the signatures of the POS interfaces; all resolved by keeping both sides) and runs every feature's tests as a named step with `if: always()` before the full run. **Run 29, read per step:** install, formatting, analyze and the translation check success; market scope, market choice, header and version helper 32 tests, POS scan 109, order edit 17, platform admin shell with its flag on 724 (1 skipped), no theme call 3, push route and rating 26, and after the full run the POS rules 70, all success; the full run ended 1,110 passed, 1 failed, 1 skipped, the failure being the known `app_version_gate_test` (the owner's decision), which nothing touches; the Android build is skipped because that job is red for it. The head is a merge: the stacked pull requests have no run of their own, and nothing ran on a device. K-01.1 (flag on, a request through the client) and K-02.4 (no inference from IP or locale: a source scan) stay coded, K-13.3 (the translation check on the head of every pull request) stays coded.
- **A scan that finds nothing is no longer silent** (lavkooappseller #5, `83893fa`). The server answers a code no product holds with 200 and an empty list; the scan handed the list to the product reader, which refused it, and nobody caught that: nothing was shown and the scan was left loading. It now shows the existing `product_not_found` text in English and Arabic and ends. The test that pinned the old crash was replaced by four.
- **A burst of 401s signs the seller out once** (lavkooappseller #11, new draft, `6491b82`: three tests on the real `ApiChecker`, three mutations killed, and a named CI step). Its own run 31 (`6491b82`, event pull_request) was read per step: the named step passed 3 tests, the full run ended 144 passed, 1 failed (the known test); K-12.1 is met.
- **An order placed before a stop is served in every stop mode** (lavkoo #111, `aa8251d0`): `StorefrontStopContinuityTest`, 57 tests, drives the refund, the cancellation, the pages and tracking, the support tickets and chat, the due payment, the MyFatoorah payment and its webhook and the Torod shipment webhook in browse only, maintenance and redirect, and asserts a new sale is refused in the same case; the earnings of an order are driven below the route; the withdrawal, payout and settlement routes are pinned structurally. No application code changed. On a strict MySQL server the checkout-driven cases skip (two bare `GROUP BY` clauses of the application, as for their neighbours). Run 351 is in progress; I2-16.4 stays coded until it is read.
- **A disk allowance was nearly spent** (0.4 GB free of the allowance) by the package git history inside every worktree's `vendor` directory (about 10 GB, shared by hard links). The `.git` directories of the vendor packages were deleted in every worktree; vendor is used by nothing but the autoloader. Nothing else was deleted.

## Still open at this hour

- Workflow `wf_b18c5e18-2c3` (the review fixes of #114 and #115) and `wf_818b6b07-dd7` (F-02.3 on #112, M2-04.0 and I1-12.3 on the branch of #96) are in their review stage; `wf_cf587d4c-2c1` (pins for the criteria of F-04, F-07 to F-12 that are coded with no test, and the preview token of one storefront refused by another) is implementing. Nothing from them is pushed or counted.
- Seller slices written and not started: money in the market's own decimals and no exchange rate (K-10.1 to .3), the market selector with a live header (K-03), a queued sale replayed only under its seller (K-06.2).
- For the owner (unchanged): the version-gate test (K-14, K-15.2), the 83 Arabic strings for a native reader, reviewers for R1 to R6 are not named, a stray file `/six-one-mysql.txt` at the root of the container and the undeletable branch `gcc/tmp-analyzer-gate-proof`, and the finance review that gates every ledger, tax and refund pull request.
