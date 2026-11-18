# LAVKOO GCC — Owner approval: coupon minimum boundary and seller-version verification
**Date:** 2026-11-12 (Asia/Kuwait)  
**Decision status:** APPROVED, narrowly scoped.  
**Affected repos:** `fullstackfull/lavkoo` (coupon) and `fullstackfull/lavkooappseller` (verification/read-only).  
**Non-goals:** No merge, no production deployment, no new markets, no migrations, no GCC phases 1–17, no GCC-20 implementation.

## 1. Owner's explicit decisions

### Coupon: minimum purchase uses >= (APPROVED)
- An otherwise eligible cart/order amount **equal to** the coupon's `min_purchase` must be accepted; amounts **below** must be rejected; amounts **above** may pass if every other coupon condition is satisfied.
- Preserve all other existing coupon validation, including eligibility, expiry, user/usage limits, discount-versus-order checks and current seller/cart scoping. This does **not** authorize changing coupon discount funding, calculation, payment amounts, currency precision, tax, or cross-store behavior.
- Correct an **inaccurate customer-facing error message** implying the qualifying order must be strictly *greater than* `min_purchase`; the message should say **at least** the minimum (e.g., Arabic: `يجب ألا تقل قيمة الطلب المؤهلة للكوبون عن الحد الأدنى`; English: `Eligible order amount must be at least the coupon minimum.`). Keep placeholders and localization intact.
- **Important evidence-based distinction:** `app/Utils/OrderManager.php:598` currently rejects if `$coupon['min_purchase'] > $onlyProductTotalAmount`, which *already allows equality*. The key `minimum_purchase_amount_not_reached_for_this_coupon.` appears at line 601. **Do not alter that condition solely to make an example pass.**
- **Different rule:** Admin coupon creation in `app/Http/Controllers/Admin/Promotion/CouponController.php:63-67,99-103` uses `the_minimum_purchase_amount_must_be_greater_than_discount_amount`; this concerns the relationship between the **minimum purchase and the fixed discount value**, NOT the cart subtotal boundary. Do not conflate or silently change it. Escalate separately if an actual UI screenshot/message proves another specific copy is wrong.
- Check localization definitions and actual rendered messages. If the existing rendered minimum-subtotal message is already correct, **do not force a wording change**; report the result as verified and add protective tests only. If a message is misleading only for a combined rejection path (e.g., discount > eligible subtotal), characterize and propose the smallest safe copy correction without altering coupon eligibility logic.

### Seller app: verify real release and force-update gate first (APPROVED READ-ONLY)
- **DO NOT lower** the backend minimum `seller_app_version_control`.
- Verify actual currently published seller app version(s), build number(s), platform identifier(s), rollout state (Google Play/App Store or owner-provided signed release records), and *actual live backend minimum* using an **authorized owner/operator-provided sanitized statement or screenshot** (no admin login or live server access granted to Claude).
- Code evidence only: `fullstackfull/lavkooappseller/main` currently declares `version: 1.0.0+3` in `pubspec.yaml`, while `test/app_version_gate_test.dart` contains `kBackendPublishedMinimumVersion = '2.0.1'`; that test constant is not proof of the current production value.
- Produce a comparison of version/build number semantics and compatibility, and propose alternatives. Do not choose `3.x`, bump `pubspec.yaml`, lower a server gate, bypass a test, change release IDs, publish builds or patch production based only on historical values. A separate owner decision is required for **any seller-app release/version or backend gate change**.

## 2. Permitted bounded coding — coupon only

Authorized changes on a new independent `lavkoo` feature branch:
1. Add Saudi regression tests for the coupon minimum-order boundary: `below`, `exactly equal`, `above`, across the already-shared web / customer API service as feasible without a real DB; test existing behavior, don't adjust expected results just for green CI.
2. Optionally correct **only the specific customer-facing translation text** that is demonstrated by evidence to contradict the approved `>=` boundary. Preserve the key, placeholders and language coverage. Do not change the `min_purchase` comparison or pricing logic.
3. Keep tests isolated from production and all external providers, using fake data/SQLite `:memory:` where proven safe.
4. Audit and prevent `translate()` from writing `resources/lang/*/new-messages.php` during tests. The current helper in `app/Utils/language.php` may call `file_put_contents` for an unknown translation key. Use a pre-existing controlled translation key or test-only mock/setup in a disposable workspace, with a pre/post `git status` and diff; do not change the global helper or suppress the problem to force green tests. If no safe method exists, leave tests BLOCKED, document cause and request new authorization.
5. A source-derived issue in an unrelated coupon path must be documented as a separate proposal, not silently fixed as part of this approval.

**NOT authorized:** any other production runtime source edits besides the exact **message copy** described above; edits to coupon eligibility logic, discount math, money path (G1-E09-01), wallet/ledger, seller auth, GCC market context, APIs, schema/migrations, backend force-update settings, `pubspec.yaml`, release settings, translations unrelated to the proven message, or security remediation. No live servers, DB, provider API calls, secret exposure, force push, main changes, merge or deployment.

## 3. GitHub plan / separation of PRs

- Keep existing backend [Draft PR #1](https://github.com/fullstackfull/lavkoo/pull/1) for Torod test-only fix + payment routing characterization. It targets `main` and remains under separate review; **do not merge** based on this decision.
- Create a **separate feature branch** for coupon minimum tests/copy from the **current `lavkoo/main` SHA**, not from the audit branch or PR #1. Proposed name: `gcc/p0-coupon-minimum-boundary`. Create a draft PR only when a reviewed, testable diff exists; no automatic merge.
- Seller app version work is **read-only** at present: publish a sanitized findings report / owner decision matrix on the audit branch. No seller code branch/PR until actual live version and policy are confirmed and the owner gives further permission.
- Reverify current HEADs and any concurrent edits before work; do not override another agent. The feature branch may contain this owner authorization note and a local-only scoped addendum to `CLAUDE.md` clarifying the authorized exception. This is an exception to the old blanket `DISCOVERY ONLY` text **only for this narrow feature branch**.
- Pre-push gate: Review complete diff, security/PII, assertions, whether `resources/lang` changed unintentionally, no modified application runtime logic, no skipped tests. **After review:** atomic commit + immediate push to remote feature branch + verify SHA + link. No unreviewed drafts pushed.
- Avoid including untracked `CouponConditionsTest.php` or any other worktree file without reviewing provenance/diff. Do not claim it is included in PR #1: its published PR patch did **not** contain it at the last check.
- Preserve all historical Saudi behavior unrelated to this exact minimum coupon rule, do not touch application release version IDs or alter the original G0 gate.
- If PR checks are red for an unrelated, independently owned cause, do not mask/redesign them under this task. Report the precise names/results and keep draft.

## 4. Required outputs and acceptance

**Coupon draft PR** (`lavkoo`, not merged):
- Exact source excerpt confirming `min_purchase > eligibleSubtotal` rejects only below threshold.
- Test cases for less/equal/greater, with evidence that the same result is used by web and customer API.
- Evidence for rendered message and either minimal correction of demonstrated inaccurate text or explicit `NO COPY CHANGE NEEDED`.
- Confirm no `translate()` auto-write to tracked language assets and clean worktree after tests.
- Branch + SHA + test commands/results + remote URL, and draft PR if changes are reviewed.
- No runtime business logic modifications, no schema/prod changes.

**Seller verification report** (audit branch; docs only):
- Current source version, hardcoded test minimum, actual published version(s)/backend minimum **VERIFIED only with owner/operator-provided trustworthy evidence**, otherwise `UNKNOWN`.
- Safe compatibility recommendation and **explicit separate owner approval** required before any version/gate/app changes.

**Stop condition:** no merge, no deployment, no release, no migration and no G0 closure. Never portray the approval as consent to weaken seller version enforcement or change coupon financial rules.

## 5. Supporting sources (checked at approval)

- `lavkoo/main` at `55e3dd0ea41069f64b0657fcbb4f8953710d2cd9` (reverify).
- `lavkooappseller/main` at `9ea64232683a6b415fb504e3d0a740a2e5a53306` (reverify).
- `lavkoo` Draft PR #1 branch `gcc/p0-saudi-baseline-ci` was at `d664ab4b0a9a9f87137e7a739e091d50b9cc1802`, still OPEN/DRAFT during review (reverify).
- `lavkoo` CI run `43227034387` observed IN_PROGRESS during review; one deployment-overlay job had failed; inspect eventual results, no assumptions.
- Existing code: `app/Utils/OrderManager.php:597-605`, `app/Utils/language.php:19-59`, `app/Http/Controllers/Admin/Promotion/CouponController.php:60-69,96-104`.
- Owner's previous authorization `docs/gcc/PHASE0-B-SAUDI-REGRESSION-CI-AUTHORIZATION.md` remains in force for PR #1 only.
