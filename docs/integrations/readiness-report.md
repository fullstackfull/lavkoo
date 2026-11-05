# Production readiness report — GO / NO-GO

**Branch:** `claude/clever-newton-wvhbt2` · **PR:** #1 (not merged) · **Date:** 2026-11-05
**Scope of this phase:** independent review, verification and hardening of the M5AZN + Torod Point (LogesTechs)
fulfillment integration. **Not done, by instruction:** merge, deploy, production migration, real order dispatch, any
live write call to a provider. No live call of any kind was made.

---

## 1. Verdict

| Decision | Verdict | One-line reason |
|---|---|---|
| **A. Start staging validation on a restored copy of the real database** ([guide](staging-validation.md)) | **GO** | The procedure, the read-only preflight and the scenario list exist and were exercised on a real MySQL 8.0 |
| **B. Start read-only (Level 1) live verification with each provider** ([checklists](live-verification/README.md)) | **GO**, once the store owner enters credentials | Read calls only; every write is behind a written authorization |
| **C. Merge PR #1** | **NOT YET** | CI is green, but the hooks that touch checkout and the one-column change to `products` have never run on the real schema (**B1**) |
| **D. Enable real order dispatch (per provider)** | **NO-GO** | Blockers B1–B5 below; provider response schemas that decide correctness are not known (B2, B3) |
| **E. "Select on M5AZN → appears in Pharmacy KSA automatically"** | **CONDITIONAL GO** | Built, idempotent and tested; needs the catalogue field map verified from a real sample (B2) and one observation (M-3) |
| **F. "Automatic Torod Point inventory synchronization"** | **NO-GO — external dependency** | LogesTechs documents no stock field and no inventory endpoint (B7); stock stays manual and labelled; nothing is simulated |

Everything a repository can prove has been proven. What is left needs the real database, the real providers, or business
decisions — none of which can be produced from here.

---

## 2. Your ten priorities — where each stands

| # | Priority | Result | Where |
|---|---|---|---|
| 1 | Independent code review of checkout, payment, inventory, fulfillment, webhook, order-status changes; fix defects | **Done** — three independent reviews plus a re-read of every change; every finding reproduced before it was fixed; 28 defects fixed with tests (§3) | §3, [security-findings](security-findings.md) |
| 2 | Verify the M5AZN import workflow; polling reliability; document delays | **Done in code and tests; one live observation open** — pull is complete, fair and tunable; delay documented | §4, [m5azn-import-workflow](m5azn-import-workflow.md) |
| 3 | Torod inventory: exactly what is missing; technical request; do not simulate | **Done** | §5, [gap analysis](torod-inventory-gap-analysis.md), [request](vendor-requests/logestechs-technical-request.md) |
| 4 | Safe staging procedure on a restored copy of the real schema, compatibility checks, migrations | **Procedure + read-only tooling done; not yet run on the real dump** (it is not in the repository) | [staging-validation](staging-validation.md), `fulfillment:preflight` |
| 5 | Validate routing, duplicates, payment allocation, shipping, VAT, refunds, COD, cancellations, tracking; single and mixed | **Done in tests** (≈ 150 scenario and engine tests, plus unit tests of the state machine and the cash allocator); refunds and payment changes **after** dispatch are not propagated to a provider (N5) | §6 |
| 6 | CI workflow; investigate the full-suite failure | **Done** — workflow green; the failure is unrelated and now explained (§7) | `.github/workflows/ci.yml` |
| 7 | Admin routes without authentication | **Found real and critical; fixed** (SEC-1) with a route-audit test | [security-findings](security-findings.md) |
| 8 | Queue workers, scheduler, webhook security, reconciliation, rollback | **Validated by tests and a read-only readiness check; rehearsal on a real host still to do** | [operations](operations.md) |
| 9 | Provider live-verification checklists, exact samples and credentials to request | **Done** | [live-verification](live-verification/README.md), [M5AZN request](vendor-requests/m5azn-technical-request.md) |
| 10 | This report | — | — |

---

## 3. What the review found and fixed (all with tests that fail on the old behaviour)

**Money and orders**
1. A COD shipment's cash-to-collect was frozen at routing; an order **paid or part-paid before dispatch** would have made the
   provider collect cash the customer had already paid. The shipment is now re-assessed against the order before every send.
2. A cash split that stops being allowed between routing and dispatch is held instead of sent.
3. Releasing a money hold could write a null total.
4. A shipment's total was the whole order total when in-house lines existed (fixed earlier in the phase: pro-rata shares that sum to the cent).
5. A basket shipped by two collectors was accepted with COD and then sat held, unshipped; COD is no longer offered for it (web + API).
6. **Delivered** was guarded only in the admin panel; the vendor panel, both seller API endpoints and the delivery-man app wrote the same status unguarded.
7. A closed order with provider lines could be reopened, orphaning goods nobody ships.
8. Editing an order with provider lines desynchronised what the provider shipped (fixed earlier: edits are refused).
9. Inventory sync could put stock back up for units already sold (unrouted COD orders, failed shipments) and oversell.
10. Provider timestamps were converted with the wrong time zone when the store's zone differed from the config zone.

**Dispatch, duplicates, cancellation**
11. A cancellation was recorded as "requested" even when the provider **refused or was unreachable**, and was never retried — an unsupported cancel looked done.
12. Dispatch, reconcile and cancel jobs each held their **own** lock on the same shipment (Laravel adds the job class to the key), so a cancel could race a send in flight.
13. Operator overrides ("release", "confirm not created", "link reference") were unlocked read-then-write: a reconciliation that proved the order exists could be overwritten, producing a **second order at the provider**. All are now locked, conditional steps.
14. Linking a reference another shipment already carried threw a raw database error; a duplicate provider reference at acceptance stranded the shipment in "dispatching".
15. Re-dispatching a cancelled shipment needed no statement that the original is really cancelled at the provider.
16. A shipment that already carries a provider reference could be sent again.
17. A 401/403 from Torod Point failed the order permanently (manual release each) when it is the credential that is wrong.
18. Same-second webhook events were ordered without their milliseconds, so an older event could overwrite a newer status.
19. The dispatch and routing sweeps looked at "the first N rows" every minute; N rows that can never progress starved everything behind them.
20. An unmapped provider status vanished silently; polling skipped *needs attention* / *partly delivered* shipments.

**Security** (details in [security-findings](security-findings.md))
21. **Unauthenticated admin routes** (SEC-1, critical, pre-existing).
22. **A forged webhook could mark our shipment delivered** by pairing our invoice number with someone else's delivered package (SEC-4, high).
23. **Supplier HTML printed to every customer** (stored XSS, SEC-5, high).
24. Remote image fetching accepted SVG, trusted the claimed type, and resolved the host twice (SEC-6).
25. Provider and supplier text reached the admin's browser as HTML through toasts (SEC-7).
26. Provider credentials were copied into the session on a failed save (SEC-8).
27. *Download AWB* was a GET that called the provider and redirected anywhere; provider-calling admin routes were unthrottled (SEC-9); rotating the webhook secret wiped the base URL; a variant with no price was sellable for nothing (SEC-10).
28. A guest id compared with a numeric column was read as `0` by MySQL (SEC-11).

### What the first real-MySQL run found

The CI job I added to test against MySQL **had been running on SQLite**: `phpunit.xml` pins SQLite with `<server>` entries
that override the job's environment. The guard test written for exactly this (`CiDatabaseTest`) failed on every run, so the
check was red from commit 7300867 on, and the "MySQL" tests proved nothing about MySQL. I did not look at CI between those
commits and I should have; it was found in this session. It now runs with `phpunit.mysql.xml`. Run for the first time on a
real MySQL 8.0 (locally and in CI), the suites revealed: `fulfillment:preflight` **crashed on MySQL 8** (upper-cased
`information_schema` columns) — the database it exists for; the guest-id coercion (SEC-11); and three test-harness
assumptions (auto-increment, JSON key order). The preflight also crashed on a database that had never been migrated, the
state it is run in first. After fixing, **all MySQL-targeted tests pass on MySQL 8.0.46 both with the platform's non-strict
setting and under strict SQL mode**, and CI run #10 is green with all five jobs.

---

## 4. M5AZN: does "select on M5AZN → Supply/Import → appears in the store" work?

* **Mechanism.** M5AZN offers no push. We pull `seller/myproductlist`; each product is staged (de-duplicated by a payload
  hash), then imported idempotently by M5AZN's own id (unpublished by default; a SKU or barcode already in the store is
  *never* adopted — it needs review; a product or variant with no price is never sellable).
* **Reliability (tested).** Every page of a long list is read (50-page ceiling, reported as *truncated*); a later page that
  fails keeps what was read and is reported *partial*; an API that ignores the page parameter is not read forever;
  the sync is idempotent across repeated runs; newly selected products are not starved by a long list of already-imported
  ones; a killed run no longer silences the sweep; only products whose payload changed are re-applied.
* **Delay.** `delay = M5AZN's own latency (unknown) + up to one poll interval (default 30 min, set per provider, minimum 5)
  + up to 5 minutes (the scheduler wakes every 5 minutes)`. **Sync products now** removes the wait. Worst case with defaults
  ≈ 35 minutes plus M5AZN's latency. It is *polling*, so it can never be instantaneous.
* **Limitations.** (a) Nothing imports until the catalogue field map is verified from a **real sample** (M5AZN publishes no
  response schema) — **B2**. (b) Whether a product selected with *Supply/Import* actually appears in `myproductlist`
  under the default `product_type=local` (or only under `global`/`ali_express`) is **unverified** — checklist M-3; the setting
  *Catalogue product types* covers the other types once known. (c) A product removed from the M5AZN list is **not
  detected**: the store product stays with its last stock, so a customer could still order something M5AZN no longer
  supplies — that order's shipment would be refused or fail and need an operator. Unpublish such products by hand
  (documented limitation, non-blocker N13).

## 5. Torod Point inventory — exactly what is missing

From the Fulfillment API v5 document: (1) **no response schema for `GET /public/fulfillment/product`**, so no field is
documented as stock; (2) **no inventory endpoint**; (3) no stock-change feed or webhook; (4) **reservation semantics
undefined** (when does an accepted order reserve, pick, decrement; what does cancel release); (5) the `quantity` in the bulk
upsert is undefined (opening balance? adjustment?) — **we never send it**; (6) no documented maximum page size, total, ordering
stability or rate limit. [The request](vendor-requests/logestechs-technical-request.md) (items 1–8 and 21–28) asks for each.
Until LogesTechs answers, stock for a mapped product is **manual and labelled**; a stock figure from the list is shown as
*not confirmed as available quantity*, and only an administrator ticking the confirmation (after a verified map **and**
LogesTechs' written statement) makes it drive availability. Units already sold but not yet taken by the provider are
subtracted from any provider figure, so a sync can never put sold units back on sale.

## 6. Order scenarios (single and mixed providers) — all asserted in tests

Prepaid, COD, part-paid COD, fully-paid-but-COD; VAT inclusive and exclusive; product discount + coupon + shipping; three-way
cent splits; provider + in-house; mixed COD held / allowed / partly verified; paid after routing; repeated and concurrent
routing; one line can never be owned twice; cancellation before routing / before dispatch / after acceptance (acknowledged,
refused, unsupported, unreachable, retried, blocked); one provider's shipment cancelled while the other runs; immutability
after a product is moved or repriced; customer tracking (one order, one shipment per provider, provider names never shown,
delivered only when all required shipments are). **Refunds:** the platform's refund flows are untouched; a refund or price
change cannot rewrite what was sold and sent (immutable snapshot); it is **not propagated** to a provider after dispatch
(neither provider documents an update call) — operator action (N5).

## 7. CI and the failing full-suite test

* **Workflow** `.github/workflows/ci.yml` on every pull request: PHP syntax sweep (8.2 and 8.3); the **full suite** on SQLite
  (8.2 and 8.3); the fulfillment, security and CI-proof suites on a **real MySQL 8 service** (≈ 9 minutes). Each job proves
  which database it ran on (`CiDatabaseTest`). Run #10 (head `a6eac2c`): **success, 5/5 jobs**.
* **The failing test** `tests/Feature/ExampleTest` (`GET /`): it fails identically on the **untouched base commit** `34dce65`
  (verified in a worktree of the base): the storefront request middleware reads tables that come from the installation
  dump (`guest_users`, `business_settings`…), which is not in the repository, so the in-memory test database has none.
  **Unrelated to this work.** It now skips with that explanation when the tables are absent and still runs where the dump is imported.

## 8. Blockers and non-blockers

**Blocker** = must be cleared before the decision it blocks. **Non-blocker** = can ship with it; tracked.

### Blockers

| ID | Blocker | Blocks | How it clears | Owner |
|---|---|---|---|---|
| **B1** | **Not run on the real database.** `orders`, `order_details`, `products` come from a dump not in the repository; tests use an inferred schema. The checkout hooks, status guards and the `products` column are unproven on the real schema/data/triggers | C, D | [staging-validation.md](staging-validation.md) sections 1–10 signed off | DBA + engineer |
| **B2** | **M5AZN: response schemas unknown.** No import, stock follow or status polling without verified field maps; the order reference path, status vocabulary, duplicate-`id` behaviour and the *Supply/Import → list* behaviour are unverified | D (M5AZN), E | [M-1 … M-13](live-verification/m5azn.md); M5AZN's [answers](vendor-requests/m5azn-technical-request.md) | Store owner + M5AZN |
| **B3** | **Torod: order response and duplicate behaviour unknown.** Order reference path, `invoiceNumber` duplicate behaviour, v2/v5 city id equivalence, `villageId` requirement; COD collection-method values if COD is taken | D (Torod) | [T-1 … T-13](live-verification/torod-point.md); LogesTechs' [answers](vendor-requests/logestechs-technical-request.md) | Store owner + LogesTechs |
| **B4** | **Deployment security actions** (we cannot see production): confirm production's `APP_KEY` is not the example key in `.env.example` (SEC-2); rotate the Zender secret and review its message log (SEC-1) | D | Checklists in [security-findings](security-findings.md) | Store owner / ops |
| **B5** | **Runtime wiring on the target host** not rehearsed: worker under a process manager with `retry_after` 180, cron `schedule:run`, a real cache driver, **trusted proxies** (client IP), heartbeat green | D | [staging-validation §8](staging-validation.md#8-queue-scheduler-and-recovery-rehearsal); preflight + Integration Center *System checks* all PASS | Ops |
| **B6** | **Business decisions for cash on delivery**: is COD taken for provider items at all; keep mixed-collection blocked (recommended) or verify it per provider; who collects at M5AZN and how it is settled | D, only if COD is offered | Decide; then M-12 / T-9c | Store owner |
| **B7** | **Torod stock**: LogesTechs must state which field is available-to-sell (or provide an inventory endpoint) | F only | T-1b with physical counts + written answer | LogesTechs |

### Non-blockers (tracked; none prevents the decisions above)

| ID | Item | Why it is acceptable | Mitigation / follow-up |
|---|---|---|---|
| N1 | M5AZN cannot cancel through the API | Documented; surfaced, not hidden | A blocked cancel shows instructions, lists under *Needs attention*, makes the order *attention required*; operator cancels at M5AZN and marks it |
| N2 | M5AZN status words undocumented / no webhook | Delivery can be closed by an operator; unknown words change nothing and are shown | Status map once M-7 is known; **becomes a blocker if the business requires automatic delivery status** |
| N3 | Torod webhooks unsigned; package barcode trusted on first sight | Terminal claims verified against our package and cash; contradictory references unmatched; secret may be a header | Ask for `invoiceNumber` + IPs/signature (request items 15, 16, 23, 24); IP allow-list supports CIDR |
| N4 | Torod warehouse order cannot be cancelled before its package exists | Reported as unsupported with instructions | Ask (item 14/27) |
| N5 | Payment/refund changes after dispatch are not propagated to a provider | Neither provider documents an update call | Operator handles with the provider; refunds in the platform are unaffected |
| N6 | Orders with provider lines cannot be edited or reopened | Prevents desynchronising what a provider ships | Cancel and re-place; a future amend flow needs provider update calls |
| N7 | Supplier product names stored as given; descriptions lose inline styling | Names are escaped on output; descriptions are the HTML-bearing field | — |
| N8 | Platform MySQL runs non-strict; other platform guest endpoints compare text with numeric ids | Pre-existing, outside this change; our code is fixed and passes under strict mode too | Owner of those endpoints should validate guest ids (SEC-11) |
| N9 | Dead code: `App\Exceptions\Handler`; unrouted `DeliverySyriaWebhookController` | No runtime effect | Remove when convenient |
| N10 | Tests use an inferred core schema and never call a provider | Stated limits; B1/B2/B3 exist for exactly this | — |
| N11 | Multi-currency rate drift between checkout and routing | The store is single-currency (SAR); an unknown rate holds the shipment | — |
| N12 | Field-map screens accept any path; numeric bounds/PII in samples | Admin-only; samples redacted | Low-priority hardening |
| N13 | A product removed from M5AZN's list is not detected | The order for it would fail visibly (held/failed shipment), never silently | Unpublish by hand; a "not seen for N syncs" flag is a possible follow-up |

---

## 9. Evidence

| Evidence | Result |
|---|---|
| Full suite, SQLite | **639 tests, 3,030 assertions, 0 failures** (2 skipped: `ExampleTest` without the dump; the CI-database proof outside CI) |
| MySQL 8.0.46, platform non-strict mode | fulfillment + security + CI-proof suites: **all pass** |
| MySQL 8.0.46, **strict** SQL mode (temporary experiment, reverted) | **all pass** — nothing relies on non-strict coercion |
| GitHub Actions run #10 (`a6eac2c`) | **success, 5/5 jobs** (SQLite 8.2/8.3, lint 8.2/8.3, real MySQL 8) |
| Mutation checks | fixes for the webhook binding, millisecond ordering and sweep rotation were reverted one at a time and the tests confirmed to fail |
| Real calls to a provider | **none** (`Http::preventStrayRequests()` in every test; nothing live was run) |
| Production access | **none** |

## 10. Recommended next steps, in order

1. **You** (or the DBA): restore a scrubbed copy of the real database on staging and run [staging-validation](staging-validation.md) → clears B1. (≈ half a day.)
2. **You**: enter M5AZN and Torod credentials on staging; run the **Level 1** checks (read-only) and record the evidence → B2, B3 (read part). Send the two vendor requests today — their answers are the longest lead time.
3. **You**: decide B6 (COD policy) and confirm B4.
4. **Authorize, separately and in writing**, the Level 2 calls (one test order per provider, immediate cancel) using the template → rest of B2, B3. Tell me when, and I will help run and read them.
5. Ops rehearsal on staging (B5), then the [go-live checklist](operations.md#6-go-live-checklist-in-this-order-one-provider-at-a-time) — one provider at a time, dry-run first.
6. Merge PR #1 after step 1 passes. LogesTechs' stock answer (B7) unlocks automatic inventory whenever it arrives; nothing else waits on it.
