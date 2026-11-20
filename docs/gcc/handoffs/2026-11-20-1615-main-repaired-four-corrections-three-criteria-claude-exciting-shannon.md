# Handoff — 2026-11-20 16:15 UTC — main is repaired, four records of mine were wrong, three criteria advanced

Account: `claude/exciting-shannon-4mh2ok` (docs branch). Session continues from
`2026-11-20-1400-ci-read-three-corrections-and-a-red-main-claude-exciting-shannon.md`.

Nothing merged. Nothing deployed. No production data touched. No key rotated. No force push. No history
rewritten. Every pull request below is a draft.

---

## 1. `main`'s suite is repaired, and the proof is read rather than assumed

[PR 140](https://github.com/fullstackfull/lavkoo/pull/140) — `gcc/p0-discovery-test-calendar-independent`,
head `aa7ba270`, one file, +68/−2, tests only.

`BuildsRemoteOrders::PURCHASED_AT` is the calendar literal `2026-11-10 09:00:00`, and
`TorodShipmentDiscoveryTest::test_the_scheduled_run_links_what_appeared_later_...` measured a 36-hour
waiting window against the clock. From 2026-11-11 21:00 it failed permanently, on five CI jobs, on every
branch cut from `main`. The GCC integration line repaired it on 2026-11-13 in `a8c6a42b`; `main` never
received it.

**CI run [44745082228](https://github.com/fullstackfull/lavkoo/actions/runs/44745082228), read from the
downloaded log archive, job by job: 20 of 21 green.** The only red job is `Deployment overlay (artifact,
deploys nothing)`, which is red by design on every run of this workflow. Grepped across all 21 job logs:
**no `FAILURES!` and no `ERRORS!` anywhere.**

| job | counts |
|---|---|
| `Tests, SQLite (PHP 8.3)` — whole suite | 6204 tests, 792334 assertions |
| `Tests, SQLite (PHP 8.2)` — whole suite | 6204 tests, 792334 assertions |
| `Strict-mode tests, MariaDB 11.4.2` | 4025 tests, 35478 assertions |
| `Sales-channel and cost tests (N-Z)`, MariaDB 11.4.2 **and** MySQL 8.0 | 752 tests, 4616 assertions each |
| `Fulfillment + security tests` | 1614 / 8448 MySQL 8, 1614 / 8451 MariaDB 11.4.2 |
| `Address tests`, both engines | 1045 / 4956 |
| `tests/Unit/Deploy` | 215 / 774 |

All five previously-red jobs are green. Local corroboration on another engine: the whole `SalesChannels`
+ `Fulfillment` group, **3194 tests, 31673 assertions, OK**.

**This is the highest-value item for whoever reads this next.** Until it lands, every branch cut from
`main` inherits a red suite, and "CI is red" stops carrying information. It changes no application file.

---

## 2. Four records of mine were wrong, and independent review found all four

The pattern is the same each time: a criterion recorded MET on a test that did not cover what it claimed.
This is the third session in a row it has happened, and the review step is what catches it.

### E-17.2 — CODED → **MISSING** (`e7244db0`)

An independent review of [PR 137](https://github.com/fullstackfull/lavkoo/pull/137) at `a7c8760e`, then an
adversarial verification of that review, refuted three sentences I had written myself:

- **The lock claim is false.** Two PDO connections on InnoDB: the second writer is **blocked** (error 1205)
  under every index shape `vendor_ledger_entries` can have today, in **both** directions. It is free only
  with an index including the chain key, and the branch adds none. Deleting `lockForUpdate()` outright
  leaves the suite green.
- **"Off is exactly what shipped" is true of every figure and false of the work.** +36% statements per
  ledger write with the switch off, including a full `information_schema.columns` listing each time, +6
  round trips per order line — on the live Saudi money path.
- **The payout is not a missing feature, it is a defect the switch introduces.** Switch on, a seller
  holding 100 SAR + 40 AED: `withdrawable()` returns 140, the reserve lands in the SAR chain, SAR ends at
  −40 while AED still advertises 40 dirhams that are gone. Before the branch there was one chain and its
  balance was right.

And the reason it was never MET: **the legal-entity half of the chain key is wired to nothing.**
`legal_entity_id` appears nowhere in `app/`, `Modules/`, `database/`, `config/` or `routes/` outside the
four files the branch touches — no table, no model, no migration, no resolver. Every live row carries NULL
and only a support trait supplies a company. **That is the same shape as the E-02.3 failure this programme
already had to correct publicly.** Two named CI jobs were green on both MySQL 8 and MariaDB 11.4.2 and the
criterion was still not met, which is the whole argument for the review step.

The review was fair about what worked: the currency OR-group is correctly parenthesised, the off-path
figures are byte-identical to the base, settlements are never drawn across two companies, the counts
reproduce exactly, and 12 of 15 mutations die.

### E-03.3 — MET → CODED (`d630f2ee`), then corrected again (`741fab1b`)

First correction: the criterion says a source-scan test **fails** when code assigns `wallet_balance` or
`loyalty_point` outside the single writer. For anything under `database/` that was false, and it was proved
false **by running it**: a real `DB::table('users')->update(['wallet_balance' => 0, 'loyalty_point' => 0])`
appended to the `up()` of a real migration left **all eighteen tests green, the self-check included**. The
root list was `['app', 'Modules', 'routes', 'config']`.

Second correction, and this one is against my own fix report: an adversarial verification returned
**fix_incomplete** and **evaded the fixed guard eighteen times**. The headline is the audit's own mutation
with **one character changed** — `['wallet_balance' => '0']` — because the write detector's lookahead
`(?![\s'"])`, written to skip casts and name maps, skips every write whose value is a string literal. An
`UPDATE ... SET wallet_balance = '0'` was run on MariaDB to confirm it zeroes the `decimal(40,20)` column.

Also open at that head: `resources/` (1523 PHP files), `public/` and `bootstrap/` were still not roots; the
**house coding standard** walks past the guard, because named arguments are mandatory here and
`increment(column: 'wallet_balance')` was invisible; a second unlocked write added **inside** an
already-listed writer stayed green with its count unchanged, so the file list was an allow-list after all;
and a column named in the `WHERE` of a row-changing statement was reported as a **write**, with advice that
is nonsense for a read.

Two sentences of mine had to go: "nine further evasion forms are closed" was true only of the spellings
tested, and "the false alarm measured at exactly zero" was a snapshot of five migrations, not a property.

---

## 3. Three criteria advanced on real evidence

### E-08.0 — blocker removed (`cc383786`)

[PR 142](https://github.com/fullstackfull/lavkoo/pull/142), `gcc/p1-ledger-append-only-and-account-deletion`.

E-08.0's own record carried its release condition: *"must not be read as met until the owner removes them
or makes them throw"* — the four `update`/`delete` statements in the two ledger repositories that nothing
in the application calls. PR 142 removes all four, and **not by throwing**. It splits the contract: a new
`AppendOnlyRepositoryInterface` holds the reads and the append; `RepositoryInterface` extends it and adds
`update`/`delete`; the two ledger interfaces extend the append-only one. One line changed in each ledger
interface, the **94** interfaces that extend `RepositoryInterface` untouched, and a ledger repository can
no longer declare an edit without breaking its own contract. A compile-time answer with no exception path
to test — better than the run-time refusal this programme had planned.

It also closes **both misses the PR 118 audit proved by experiment**: a ledger delete through a table name
held in a variable (now `UNKNOWN_TABLE`) and a ledger model editing its own rows from a property (now
`model WalletTransaction`) — the ninth edit form PR 118 deliberately left out *because main broke it*, and
which is enforceable now only because the four statements are gone.

50 new tests / 140 assertions. The admin screen that empties tables now protects both ledgers.
`tests/Feature/Customer` moved **into** the real-engine job and the strict-mode job, and `Fulfillment,
security and customer tests` is green on **MySQL 8 and MariaDB 11.4.2** — the account-deletion half is now
proved on real engines, which it was not before.

Still CODED, and no longer for a reason about the code: the 33-test scan carrying this criterion lives in
`tests/Unit/Customer`, deliberately kept out of the real-engine job, so the only covering job is the
whole-suite SQLite one — red on **one failure in 6250**, and that failure is the calendar fixture PR 140
fixes.

### D1-02.4 — **MET** (`cc1ab5d2`)

[PR 143](https://github.com/fullstackfull/lavkoo/pull/143), head `fc559957`, 6 files, +401/−2. The last of
D1-02's five criteria, and the only one still MISSING.

The defect was reproduced before it was fixed, on `7e32bba2`: one legacy cart group with Saudi product 1
(100) and Emirati product 2 (250), both ticked — with the storefront switch **off**, order placement
returned 200 and wrote order 100001 with rows [1, 2] and countries `["SA","AE"]`; the same on an unbound
request with storefronts on.

The guard judges **the rows about to be written**, not the customer's whole cart, which is what keeps the
neighbouring criterion (a legacy mixed group is checked out per storefront) true — and the fifth mutation
proves the coupling, because judging the whole cart breaks that criterion's own test too. `generateOrder()`
asserts before the transaction, so a mixing order is never partly written. Query count unchanged: 17 for
the page check, 218 for the cash-on-delivery order, with and without the guard.

MET on named green jobs on `fc559957`: `Storefront tests` on **MySQL 8 and MariaDB 11.4.2**, both
whole-suite SQLite jobs, and strict-mode MariaDB. 21 of 23 green, overlay red by design.

**The country half is live; the company half is dormant** because `products.legal_entity_id` does not exist
in this tree, and the record says so. That is deliberately distinguished from the E-02.3 failure it
resembles: there the criterion's own subject was absent, here the refusal is real and the company clause
describes a state that cannot yet arise.

### E-12.5 — **MET** (`b45b8542`)

[PR 141](https://github.com/fullstackfull/lavkoo/pull/141), head `9242fce7`, 8 files, +769/−26. **A live
money defect, not a missing test.**

The guard deciding whether a seller may withdraw and the debit that took the money converted currency
through different helpers: `Convert::usd` reading the **cached** `business_settings` table for the guard and
the stored row, `BackEndHelper::currency_to_usd` reading `system_default_currency` **straight from the
database** for the debit, and `currencyConverter` as a third route from the vendor panel. While the cached
and stored defaults disagree the numbers differ by the exchange rate: 100 USD of earnings, a store selling
in SAR at 3.75, a 375 SAR request passes a guard that sees 100 and is debited 375 — `total_earning` goes to
**−275**, and the request row records 100, so closing it credits back 100 and the rest is gone.

**The platform opens that window by itself:** `updateSystemCurrency` writes the new default and forgets only
session keys, never `clearWebConfigCacheKeys()`, so with `CACHE_DRIVER=file` the cache holds the old default
for three hours.

The fix routes guard, stored amount and debit through one value across five entry points, and keeps
`Convert::usd` specifically because it already wrote the stored amount that every credit-back path reads. It
**refuses rather than inventing a rate** when no usable USD row exists — `Convert::usd` alone would have
fallen back to 1:1 and moved real money at a rate the store does not hold.

MET on a named green job the branch itself adds: **`Wallet tests, MySQL 8`** on `9242fce7`, running
`tests/Feature/Wallet` on a real engine, because these paths build their schema by hand and SQLite in memory
cannot tell whether they hold where the previous test's tables survive. 33 tests / 156 assertions.

Left to the owner, not silently carried: the v2 **close** path still credits the stored amount and debits
the posted one — the D18 decision pinned on PR 32.

Tracker now: **1753 criteria, 815 MET, 359 CODED** (67.0% implemented, 46.5% proven).

---

## 4. Two heads carry fixes that NOBODY INDEPENDENT HAS CHECKED

Both were fixed by the same lane that reviewed them, so their reports are self-reports. Verification of
both is running as this is written; its verdicts are the next thing to record.

- **PR 95 at `7465de7d`** (wallet guard): claims all 26 scanner misses and 18 plants closed, both
  false-positive classes fixed, `resources`/`public`/`bootstrap` added as roots, 71 tests / 112 assertions,
  16 of 16 self-mutations red, and the scan now reading 4511 files in **19 seconds** against 1.2s at the
  branch point. The previous round of this same lane was true in its numbers and false in its headline.
- **PR 137 at `5a0b7484`** (ledger chain): one commit on top of the reviewed head that **changed
  application source on the branch under review** — eight files of code and config, five of tests. It
  reports finding a defect worse than the review's: with ledgers **on** and settlements **off**, one
  settlement was drawn across two chains netting 140 and recording closing 40. It also corrects both
  earlier reports: there are **eight** `VendorLedger::record()` call sites, not two, and **none passes a
  company**.

---

## 5. Three things no single pull request can see

1. **PR 95 and PR 142 both extend `tests/Support/PlatformWriteScanner.php`** — one by 184 lines for
   ledger-row edits, the other by a larger rewrite for balance-column writes. They will conflict, and
   whichever lands second must merge both extensions rather than take one side.
2. **`legal_entity_id` does not exist on any table.** It blocks E-17.2's company half and D1-02.4's company
   half alike. PR 143 handles the absence honestly (read the country today, the company the day a product
   carries one, from one loop, with a test that adds the column and watches the scope change); PR 137 does
   not, which is why E-17.2 reads MISSING. **The PR 143 pattern is the one to copy.**
3. **PR 142's secret-scan job was red on this branch's own doing**, and it is fixed: a documentation example
   of the filters array carrying two placeholder addresses, at `AppendOnlyRepositoryInterface.php:43`. The
   line is a pure **move** — unchanged at `RepositoryInterface.php:37` on `main` and at
   `OrderRepositoryInterface.php:13` on the branch — and the guard reads added lines, so a move looks like
   someone typing addresses in today. `e7b71e6b` rewords the example to spell no address; the guard reads
   the whole range's **history**, not its tip, so `37ac6845` adds an entry to
   `scripts/ci/leak-guard-allow.json` pinned to rule, path and line 43, with the reason written out.
   `leak-guard --base=c5c04b7b` exits 0. **No security gate was weakened and no real address was ever in
   this repository.**

---

## 6. Still open for the owner

Unchanged from the previous handoff unless noted:

- **Security:** the private series stays private. PR 134 remains parked — all three repositories are public,
  and instruction 3 forbids publishing vulnerability detail before the system is secured. The patches are
  not yet applied.
- **A minor-unit field** must exist before any 1/1000-currency market (KWD, BHD, OMR): the 0.01 threshold is
  SAR-shaped, and up to **nine** of their smallest units would be treated as rounding dust. A pre-condition
  for three of the six markets.
- **Account deletion** — refuse versus anonymise needs a legal reading for six countries, plus one way for a
  customer to clear a small residue. None exists today, so with the switch on, 2.50 SAR makes an account its
  owner can never delete.
- **Customer-app iOS bundle id**: `com.ksacosmetics.app` in Xcode against `com.lavkoo.app` in
  Firebase/Android. No App Store numeric id is in the repository.
- **Seller app version floor** — no change without verified release evidence.
- **The v2 withdraw close path** (D18, PR 32).
- **Base currency changing under a live ledger**: `VendorLedger::chain()` resolves the base at call time, so
  every unlabelled row migrates between chains and the old chain silently loses them. Undecided and untested.
- Nobody has read `installation/backup/database.sql`.

DR-1 to DR-5 remain open risks. G0 remains open.

---

## 7. What to pick up next

1. **Record the verification verdicts** on PR 95 `7465de7d` and PR 137 `5a0b7484` the moment they land. Do
   not move E-03.3 or E-17.2 on a self-report.
2. **PR 140 is the unblocker.** While `main` is red, every branch inherits it and CI stops being evidence.
3. **E-17.2's fix round still owes the legal entity.** Either wire it from the order's own market (the PR 143
   pattern) or take the company out of the chain key and record the per-entity half as its own requirement.
4. The **32 verifier-proposed criteria** still need a state and evidence each.
5. **C-13.2** should reuse the scanner machinery these two lanes have now hardened twice.
6. Three app draft PRs still need CI read and evidence recorded: lavkooapp 21 `3b22f15e`, lavkooapp 22
   `17081515`, lavkooappseller 18 `3a034704`.

Method held throughout: Code → Tests → Independent review → Fix → Commit → Push → Draft PR → Tracker. The
review step earned its keep four times today.
