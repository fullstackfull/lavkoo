# Handoff — what CI actually said, three corrections to the record, and `main` is red

**Session** `claude/exciting-shannon-4mh2ok`, 2026-11-20, afternoon (UTC). Read after
[the 2026-11-19 night handoff](2026-11-19-2330-wave7-reviews-corrections-and-owner-items-claude-lucid-babbage.md),
which it does not supersede. Nothing is merged, deployed, rotated or approved. No production data was touched.

## 1. Where the tracker stands

**1,753 criteria — 814 MET, 359 CODED, 580 MISSING.** Implemented 66.9%, proven 46.4%, release-ready 7.7%.
Last night it was 808 / 339 / 606.

Pushed as `c7f88ae0` and `2e2054d5` on `claude/exciting-shannon-4mh2ok`.

| moved | from | to | on |
|---|---|---|---|
| E-02.3 | CODED | **MET** | PR 130 `c53ac2c3` |
| A1-08.4 | CODED | **MET** | PR 138 `fe588e44` |
| A2-14.0 | CODED | **MET** | PR 138 `fe588e44` |
| E-17.2 | MISSING | CODED | PR 137 `a7c8760e` — held back deliberately, see §4 |
| E-08.1 | MISSING | CODED | PR 131 `cf0228b8` — the record was a day stale, see §5 |

**A2-14 is now MET on all four of its criteria.**

## 2. `main`'s own suite is red, and has been since 11 November

This is the most useful thing in this handoff, because it has been silently costing every account time.

`TorodShipmentDiscoveryTest::test_the_scheduled_run_links_what_appeared_later_and_keeps_the_status_of_a_linked_shipment_current`
fails on `main` at line 198: expects `'unlinked'`, finds `'needs_review'`.

The product is right. The fixture is dated. `followed()` leaves the mapping's `purchased_at` at
`BuildsRemoteOrders::PURCHASED_AT`, the calendar literal `2026-11-10 09:00:00`, and
`ShipmentDiscovery::waitedLongEnough()` measures a 36-hour waiting time from it **against the real clock**. From
**2026-11-11 21:00** onward the run hands every fixture order to a person. The sibling test in the same class
pins the instant; this one forgot. The fixture's own docblock records that the same pairing broke the dispatch
tests once before, which is why `BuildsDispatchWorld` pins its clock.

**It shows up as five red CI jobs, one cause:** both `Tests, SQLite` (PHP 8.2 and 8.3), `Sales-channel and cost
tests (sales channels N-Z)` on **MySQL 8.0 and MariaDB 11.4.2**, and `Strict-mode tests, MariaDB 11.4.2`. I read
the logs of all five on PR 137 and all five on PR 139: every one is this test and nothing else.

`gcc/release/gcc-integration-ci` repaired it on 2026-11-13 in `a8c6a42b`. **`main` never received it.** So:

- a branch cut from the integration line is green (PR 130, PR 138);
- a branch cut from `main` or from a pre-13-November base is red for this reason alone (PR 137 and PR 139 are
  both **193 commits** behind the integration line).

[PR 140](https://github.com/fullstackfull/lavkoo/pull/140) fixes it at the root, against `main`: the instant is
pinned inside `followed()`, so a test added to that class later cannot inherit the bug, and a data provider
asserts the property at four distances from the fixture's own date. Two mutations killed on both engines.
`tests/Feature/SalesChannels` + `tests/Feature/Fulfillment` together: **3,194 tests, 31,673 assertions, OK** on
SQLite, where before it was that same set with one failure.

**If you are about to chase a red job on a branch, check this first.**

## 3. What an independent review is worth, measured twice

**PR 130 / E-02.3 went three rounds.** Round 2's reviewer found 7 defects. Round 3's verifier then found **11
more survivors**, and the worst was working code, not a weak test:

> a pooled-balance overdraft guard in `CustomerMarketBalances::change()`, the column name built as
> `implode('_', ['sharing','group'])`. KW credited 30, BH credited 70, both grouped `'gulf'`. **A debit of 100
> against KW was applied, leaving KW at −70.0.** All 140 wallet tests green on both engines.

That is money crossing a market boundary — the exact thing E-02.3 forbids — with the whole lane green. It
survived because the test that *looked* like it covered the spend path opened an account only in BH and debited
KW, so the refusal it observed came from there being no KW account at all. **A test whose refusal comes from a
missing row rather than from the rule it names is worth nothing, and reads as if it were worth everything.**

**PR 138 / A2-14.0.** The reviewer wrote 22 evasions of the migration guard and **21 passed** — a table named
through `(new User)->getTable()`, through `config()`, through `trim()`, through a class constant; `up()` calling
`down()`; a write in a class declared above the migration; `$customer->orders()->delete()` on a relation; a
builder copied to a second variable; a name concatenated inside `DB::statement`; `LOAD DATA INFILE`; a semicolon
inside an SQL comment; the `DB` facade imported under another name; a migration in a module directory the guard
never read. All 22 are now refused by **two** gates: the text of every migration, and running all of them against
a seeded database and reading what the engine was actually asked to do.

The guard file went from 51 tests / 62 assertions to **87 / 122**.

## 4. E-17.2 is CODED although a green named job covers it

PR 137's `Marketplace and ledger tests` passed on **MySQL 8** (job 136188660584) and **MariaDB 11.4.2** (job
136188660651). By the letter of the MET rule — a named job on a named head that covers the criterion — that is
enough.

It is recorded **CODED** because it has had **no independent review**, the tests are mine, and E-02.3 above is
what that is worth on its own. The review is running. Do not promote it without one.

## 5. E-08.1's evidence was a day out of date

The criterion read `account_delete hard-deletes the user (REST v1, web); no test` while PR 131 had been carrying
**34 tests / 156 assertions** for it since 2026-11-19, read by two adversarial reviewers, with CI on `cf0228b8`
at **20 of 21 success** (the one failure the deployment overlay, red by design; both whole-suite SQLite jobs
green). The record is now the whole story.

It is CODED, not MET, and not because of the testing:

1. the criterion is true only with `GCC_REFUSE_ACCOUNT_DELETE_WITH_BALANCE` **on**, and it ships **off** — with
   it off the ledger rows survive but the balance record goes with the `users` row;
2. turning it on takes away something a Saudi customer can do today, which is the owner's decision;
3. with it on, **a customer holding 2.50 SAR could never delete their account.** Wallet payment at checkout is
   all or nothing, there is no customer withdrawal, points below the conversion minimum cannot be converted, and
   the only admin wallet action *adds* funds. Exactly one way out must exist first.

**A pre-condition for three of the six markets, recorded now so it is not found late:** the `0.01` threshold is
one halala, and one fils in AED and QAR — but **KWD, BHD and OMR are 1/1000 currencies**, so up to **nine** of
their smallest units would be treated as rounding dust. No minor-unit field exists anywhere in the codebase, so a
currency-aware threshold needs that field first.

## 6. The migration audit — 378 files, 147 refs, read end to end

The headline is good news and worth stating plainly: **no migration anywhere in this project writes to a table
holding a customer's money.** Not one. The entire census of data writes in any `up()` is 10 statements in 6
files, and the ones that touch customer tables are deliberate credential purges on the security-hotfix line.
Every column this programme adds to a money or customer table is **nullable, with no default and no backfill** —
consistently, across all 28 branch-added migrations. Two independently written detectors agree.

Three things that are not good news:

**(a) A money write placed in a migration is invisible to the guard built to forbid it.**
`WalletBalanceWritersTest::ROOTS` is `['app', 'Modules', 'routes', 'config']` — `database` is absent, so
`writersOf()` never opens `database/migrations`. The detector would match `['wallet_balance' => 0]` perfectly;
the guard simply never reads the file. Its own self-check asserts only that 500+ files were scanned, so nothing
pins the reach. Being closed now.

**(b) 26 migrations skip silently** when the table they need is absent — the guard's false arm does nothing,
Laravel records the migration as run, the change never happens, and **nothing anywhere records that it did
nothing**: no log line, no exception, no row. Nine are on money or customer-identity tables, two of them security
purges. `CLAUDE.md` says import the dump *before* migrating; these 26 are written to tolerate exactly the
forbidden order, so if anyone ever migrates first, the change is lost permanently and silently. Three different
syntactic forms produce it, so a guard matching only the obvious one misses two thirds.

**(c) Seven of the money and customer tables are created by no migration at all.** `users`, `orders`,
`order_details`, `shipping_addresses`, `admin_wallets`, `seller_wallets` and `withdraw_requests` are *named* in
13, 39, 11, 5, 5, 7 and 5 migrations respectively and `Schema::create` for them appears **zero** times. They
exist only inside `installation/backup/database.sql`, which `.gitignore` excludes (`*.sql`, `/backup`) and which
is in **no ref of this repository**. So any trigger, view, stored procedure, event, generated column or column
default that file carries is outside the reach of every guard here and outside that audit. **Nobody has read the
dump.** A trigger on `orders` writing `users.wallet_balance` would be invisible to all of it.

Related and measured: on `gcc/release/all-integration-ci`, **289 of 361 migrations are executed by no test at
all**, the 91-column decimal retype sweep among them, and the suite never runs the migration set as a unit —
there is exactly one `artisan('migrate')` call in the whole suite and it is scoped to `Modules/TaxModule`.

## 7. A live-platform defect found by not dismissing a red job

CI on an unrelated branch failed one staff-scope test: it expected order `102` first and got `103`. The same test
passed locally on MariaDB 10.11 over the same rows. Two engines, same data, different answer.

The test was not wrong. **Twelve report screens paginate on a non-unique order** — six on `created_at` alone, two
on `current_stock`, one on `wish_list_count`, three on nothing at all. With ties the engine may answer page 2 in
a different order from page 1, because each page is its own query with its own `OFFSET`, so **a row can appear on
two pages and another on none**. An admin reading a transaction report can be shown the same money twice. The row
count still adds up, which is why it stayed quiet. No existing test covered any of the twelve.

[PR 139](https://github.com/fullstackfull/lavkoo/pull/139) adds the tie-break and a guard that resolves the order
reaching every `paginate(` in the report controllers and the seller report service, reporting what it cannot
resolve rather than passing it. It found two screens the fix had missed. `Report pagination tests` passed on
**MariaDB 11.4.2 and MySQL 8**, 5 tests / 9 assertions each.

**No requirement among the 359 covers report pagination**, so nothing was recorded. This is not GCC work; it is a
defect on the live Saudi platform.

## 8. In flight right now

| lane | what | state |
|---|---|---|
| review of PR 137 | the independent review that gates E-17.2 | running |
| `WalletBalanceWritersTest` | reproduce §6(a) by running it, then close it and pin the reach | running |
| E-12.5 | the withdraw guard and the debit must use one currency conversion | running |
| E-08.0 | remove the unused ledger `update()`/`delete()` and pin their absence | running |
| D1-02.4 | an order whose rows span two countries is refused at order time | running |

**Note for whoever picks up E-08.0:** the scanner for it already exists — `tests/Support/LedgerEditScanner.php`
with `LedgerRowsAreNeverEditedTest` (128 tests) on PR 118. That is the one to extend, not
`tests/Support/PlatformWriteScanner.php`. E-08.0 is CODED because that scan reports the sentence **false** on
`main`: the writers exist. Removing them is what moves it.

## 9. What needs the owner

Unchanged from last night and still open:

1. **The customer app's iOS bundle id contradicts its Firebase configuration.** Xcode's
   `PRODUCT_BUNDLE_IDENTIFIER` is `com.ksacosmetics.app` in all three configurations, while
   `GoogleService-Info.plist`, `firebase_options.dart` and Android all say `com.lavkoo.app`. If the iOS build
   ships as the former, iOS Firebase is pointed at a bundle id the build does not have. The seller app is
   consistent across all four places. **Not changed — a bundle id is a release decision.** Which is real?
2. **No App Store numeric id exists anywhere in the repository**, so the iOS update fallback is an App Store
   search rather than the product page.
3. **The seller app's version floor**: lower the shop's floor to the app's real version, or renumber the app at
   or above 14.1.
4. **Account deletion: refuse, or anonymise?** §5. Needs a legal reading for each of the six countries, and one
   way for a customer to clear a small residue before the switch can go on anywhere.
5. **A minor-unit field** is needed before any 1/1000-currency market (KW, BH, OM) can use a money threshold.
6. **Nobody has read `installation/backup/database.sql`.** §6(c). Proving anything about the live schema needs a
   staging database restored from it.

G0 remains open. DR-1 to DR-5 remain open risks. The private security series stays in the private channel and
nothing about it is recorded here.
