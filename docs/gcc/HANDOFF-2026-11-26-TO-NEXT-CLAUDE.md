# Handoff — 2026-11-26, to the next Claude account

Written at the owner's instruction to stop new development and hand over. Everything below is
measured on this tree or read from GitHub, not recalled. Where something is unverified it says so.

---

## 1. Where the code is

| repository | branch | last commit | working tree | pushed |
|---|---|---|---|---|
| `fullstackfull/lavkoo` (backend) | `gcc/development` | **`5aab34f9`** | clean | yes, in sync with `origin` |
| `fullstackfull/lavkooapp` (customer Flutter) | `gcc/development` | **`525da8e`** | clean | yes, in sync with `origin` |
| `fullstackfull/lavkooappseller` (seller Flutter) | `gcc/development` | **`68a5d59`** | clean | yes, in sync with `origin` |

**Nothing is uncommitted and nothing is unpushed, in any of the three.** Verified with
`git status --short` (empty) and `git rev-list --left-right --count origin/gcc/development...HEAD`
(`0 0`) in each.

The five local worktrees under `/home/user/wt/{m,de,acgh,bfi,klj}` are each clean with **0 commits
not already in `gcc/development`** — every track of this session is merged. They are organisation
only; their branch names are never pushed.

`main` was **not** touched in any repository, and no pull request was opened or merged.

---

## 2. CI

### Run 678 — the comprehensive round on the frozen SHA

**<https://github.com/fullstackfull/lavkoo/actions/runs/45744979283>** — `workflow_dispatch` on
`5aab34f9`. **Finished. Conclusion: failure.** 52 jobs: **41 success, 9 failure, 2 skipped**
(the two skipped are `Deployment overlay` and `Saudi baseline expectations unchanged`, both
skipped by design on a dispatch).

The 9 failing jobs come from **three distinct code causes plus the leak guard** — not nine
problems. Failure counts per job are small (4, 4, 2, 2, 2); the long numbered lists in the tail of
each log are the **skip** listing, not failures. Do not read them as failures — that trap is
recorded in `EXECUTION_STATE.md`.

#### Cause A — the staging seeder writes `users.wallet_balance` (7 of the 9 jobs)

`Tests\Feature\Fulfillment\WalletBalanceWritersTest` fails twice:
`test_the_wallet_balance_is_written_by_its_five_writers_and_nothing_else` and
`test_nothing_but_a_named_read_is_reported_without_being_a_writer`
(`tests/Feature/Fulfillment/WalletBalanceWritersTest.php:219` and `:236`).

The diff names the new writer exactly:

```
+    'database/seeders/GccStagingSeeder.php' => Array [ 0 => 'array key given a value' ]
```

Affects: `Tests, SQLite (PHP 8.2)`, `Tests, SQLite (PHP 8.3)`,
`Strict-mode tests, MariaDB 11.4.2`, `Fulfillment + security tests, MySQL 8`,
`Fulfillment + security tests, MariaDB 11.4.2`,
`Fulfillment, security and customer tests, MySQL 8`,
`Fulfillment, security and customer tests, MariaDB 11.4.2`.

**This is the same family as a failure already fixed this session** (`LedgerRowsAreAppendOnlyTest`
in `5aab34f9`): a money guard that keeps a list of permitted writers, and the six-market staging
seeder became one. The seeder writes invented rows only and refuses to run outside
staging/testing/local as the first statement of `run()`. **Likely a one-entry declaration on the
guard's writer list, with that reasoning written beside it** — but read the guard first and decide,
because this is `users.wallet_balance` and the guard exists precisely to stop a new writer slipping
in. Do not widen it reflexively.

#### Cause B — a migration narrows the schema on the way up (both SQLite jobs)

`Tests\Unit\Ci\GccMigrationsContractTest::test_no_migration_of_this_programme_drops_renames_or_changes_on_the_way_up`
(`tests/Unit/Ci/GccMigrationsContractTest.php:193`):

```
2027_01_07_100000_add_market_to_inventory_pools.php: dropUnique()
Add beside, never in place of; or list the file in NOT_EXPAND_ONLY with the decision that allowed it.
```

That migration is from the stock-pools track (merged in `d8fea86f`). It **adds the new unique key
(market first) and then drops the old one** — the track documented that order deliberately, because
the market column is part of a UNIQUE key and two rows whose key contains NULL do not collide on
MySQL/MariaDB, so a nullable market would permit a second pool row for one shelf and product and
the `FOR UPDATE` on that row is the whole single-writer guarantee.

**This one is not a declaration to wave through.** The expand-only contract exists because code
running *before* a deploy stops working the moment a narrowing migration runs. Either the drop moves
to a separate later migration (expand now, contract after the deploy), or the file goes in
`NOT_EXPAND_ONLY` **with a recorded decision**. I did not choose: it is a deployment-safety question
with a real trade-off and the owner stopped new development before it was reached.

#### Cause C — engine-dependent array key order (`Storefront tests, MySQL 8` only)

`Tests\Feature\PlatformAdmin\ReportsAndExportsTest::test_an_export_writes_one_line_of_the_trail_saying_how_many_rows_left_the_platform`
(`:160`) and `Tests\Feature\PlatformAdmin\StepUpTest::test_a_step_up_is_written_to_the_audit_trail_with_how_it_was_proved`.

The diff shows the **same keys in a different order** (`status`, `to`, `file`, `from`, `rows`,
`params`) — `assertSame` on arrays is order-sensitive, and the audit-trail JSON column's key order
differs between MySQL 8 and MariaDB. **This is why my local runs were green**: this container has
MariaDB 10.11.14 and the same group passed 6,254/6,254 here. The fix is almost certainly to compare
without depending on key order (or to sort both sides), not to change what is written — but confirm
on MySQL 8, which this container does not have.

#### Cause D — the leak guard: `leak-guard: 2 finding(s)`

Job `No secret or personal data added`. **This is the known history finding and it is one of the
owner's three open decisions — see §5.** It is not new and not caused by this session's code: the
working tree is clean of it (`php scripts/ci/leak-guard.php --base=origin/gcc/development` reports
nothing added, and was run clean before every push this session). The guard scans **history**, so a
clean tree does not clear it.

### Earlier runs, for the record

- **Run 676** — <https://github.com/fullstackfull/lavkoo/actions/runs/45710495852> on `30a2283e`,
  failure, 8 failing jobs. **All of it is now accounted for**: the localization ratchet on both
  engines (27 untranslated keys, fixed in `fc9a5383` with no baseline raised); the `sales channels
  Ad` shard (`connect_noon`, fixed in `b80c815f`); three channel-screen defects (fixed in
  `3590efb8`); four storefront guards (fixed in `4908730d`); the ledger run-time-table declaration
  (fixed in `5aab34f9`); and the leak guard, which is the owner's decision.
- **Run 677** — <https://github.com/fullstackfull/lavkoo/actions/runs/45726283944> on `bc55c77b`,
  failure. Superseded by 678: it predates the storefront and ledger fixes.

### Local test results on the merged tree (MariaDB 10.11.14, this container)

All green, all on `5aab34f9` or the commit it fixes:

| group | tests | assertions | failures |
|---|---|---|---|
| `tests/Feature/Storefront` + `Unit/Storefront` + `Feature/PlatformAdmin` + `Feature/Seeders` | 6,254 | 53,783 | **0** |
| `tests/Feature/SalesChannels` + `Unit/SalesChannels` | 2,201 | 772,704 | **0** |
| the checkout + marketplace group (25 directories, as `ci.yml` defines it) | 1,702 | 6,328 | **0** |
| `tests/Feature/Payments` | 316 | 1,505 | **0** |
| `tests/Unit/Customer/LedgerRowsAreAppendOnlyTest` | 54 | 87 | **0** |

**What this does NOT cover, stated plainly:** no SQLite run, no MySQL 8 run, and the
`Fulfillment`/`Security`/`Customer` directories were never run locally at all. Causes A, B and C
above are all in that uncovered space — which is why CI found them and I did not.

---

## 3. What was done this session

### Security

- **The customer app verified no server certificate at all** (`SEC-E16-01`). `lavkooapp/lib/main.dart`
  installed a process-wide `HttpOverrides` whose `badCertificateCallback` returned `true` for every
  certificate, host and port, before anything else in `main()`. Any party able to answer for the
  server on the network could read and rewrite everything the app carried — session token, saved
  addresses, the whole payment conversation — with no sign to the shopper. **It was on `main` too.**
  Removed in `lavkooapp` `2db372a`, with `test/https_certificates_are_verified_test.dart` scanning
  all 751 `lib` files to refuse its return (comments stripped first, measured as load-bearing).
  Three checks made removal safe rather than a gamble: `git log -S` puts the line in the
  repository's **initial** commit (template boilerplate, not a workaround); the **seller** app talks
  to the same backend with no override and its CI passes; and it was the only occurrence.
  **Limit: there is no Flutter toolchain in this container, so `flutter test` was never run and I
  claim no green run.** What was run is the scan's logic in standalone Dart 3.13.0, three ways.
- **The P0 security closure map** — `docs/gcc/audits/2026-11-25-p0-security-id-closure-map.md`
  (`ed9f074a`). One row per explicit P0 with its dossier citation, the subject reconstructed from
  the dossier's own prose, the file and line, and the code and test that closes it. **6 of 23
  CLOSED-WITH-TEST, 0 CODE-WITHOUT-TEST, 2 OPEN, 1 fixed this session, 14 NOT-RECOVERABLE.** The
  "restricted annex" every id points to **exists at no path on any ref of the three repositories**
  (196 refs, 16,844 paths swept) and the Discovery-01 dossiers only repeat the same pointer, so
  where a dossier states no technical fact for an id there is nothing to reconstruct from. Those 14
  need the annex produced, or the authors of stages 1–3 naming which ids their work closed.
- **The secret scanner knew one market of six.** A number written `+971`, `+965`, `+974`, `+973` or
  `+968` broke no rule anywhere in the programme while a Saudi one was caught — five markets'
  customers were not personal data. `gcc-mobile-number` added in `5102d6d1` by each country's own
  numbering plan, 11 tests (7 refusals and 4 that must stay quiet), teeth = exactly those 7. Still
  only Saudi on `origin/main`, which is Q-47 / D-04.
- **A refusal with no words.** `MARKET_STOPPED` reached the listing screen like any other refusal
  but was never added to `ListingLabels::CHANNEL_REFUSALS`, so the screen printed the bare code
  `market_stopped` at an operator trying to find out why nothing was sent (`3590efb8`).

### Money

- **A market's sales could be collected into another company's merchant account.**
  `payment_gateway_accounts.legal_entity_id` was a free column that nothing compared with who sells
  in the market, so the Kuwait Paymob account filed under the Saudi company collected every Kuwaiti
  sale into that company's account while the order was stamped Kuwait — and credentials follow the
  stamp. One answer now (`AccountOfTheSellingCompany`), **shipped OFF** pending **Q-D1-1** (§5).
  11 of 11 teeth bit.
- **A partial return put back more units than ever left.** The order-edit screen's writer gave a
  line's whole quantity back whenever `is_stock_decreased` was 1, which a *partial* return leaves
  set: shelf 50, line of 3, a return of 1 received → **54, where 53 is all that ever left**. Fixed
  at the cause (one place answers how many units may still go back) plus the order-line lock the
  other three writers already took. 8 teeth, and **one inert tooth found and closed**.
- **A stock pool was shared across countries.** `PoolKey` carried no country, so two accounts in
  two countries publishing one SKU shared one pool row and could each offer the whole stock. Now
  per market, with `''` as the default storefront's pool so every existing pool is unchanged byte
  for byte.

### Markets, queues and translations

- **Queued work carries the market** (`25621bd0`). The recorded evidence — "none of 27 `ShouldQueue`
  files mentions a storefront" — was wrong three ways: 27 counted FILES, only 26 classes implement
  the contract, and 16 already carried or honoured a market. 22 of 26 now do and the other 4 are
  declared platform-wide and enforced by building each class and asking it.
- **27 keys shipped untranslated** and the ratchet caught it (1,100 against a 1,073 ceiling, the
  same delta of 27 on both counters). Added in all three locales with **no baseline raised**, and
  the Arabic contains no Latin because a second counter ratchets exactly that.
- **Two more keys were invisible to every scan** — `connect_noon` and `noon_merchant_code`, built as
  `translate('connect_' . $channel)` and `translate($isAmazon ? ... )`. **Twice this session the
  thing that found a missing translation was the suite that renders the screen.** A literal-key scan
  is complete for literals only.
- **Both app repositories now refuse a secret**, and the seller's `dart format` step — four fixed
  paths with `continue-on-error: true`, which refused nothing — became a gate that fails.
  **`lavkooapp`'s new `formatting` job went red on my own commit** and was right to: `dart format`
  picks its style from the package's language version (`sdk: ^3.6.2` → short style), and with no
  `flutter pub get` in this container it fell back to tall style and reported "0 changed" against a
  standard CI does not use. Fixed in `525da8e`.

### The register

- **Nine criteria off MISSING** (`c146a258`), **nothing promoted to MET** — correctly, because no CI
  run had executed this round's code. The register keeper checked 11 claims that did not hold,
  three of them from the tracks' own handoffs.
- **A fourth state word was refused.** `[PARTIAL]` was recommended for one criterion; `count.php`
  matches only `[MET]`, `[CODED]` and `[MISSING]`, so it would have dropped the criterion from every
  total and broken the counter's one invariant.
- **Five narrative fields** that still contradicted their own criteria were corrected (`e6590f4c`).
- **The roadmap now has risks and an as-built map** (`bc55c77b`): a `| المخاطر |` row on all 17
  phase tables, derived from each phase's own scope plus the failures this programme already paid
  for, and **the first appendix connecting a phase to the code that exists**. What writing it
  revealed matters more than the rows: **every proposed branch name and every proposed flag name in
  the roadmap is now fiction**, and not one-to-one either (the switches were cut per capability, not
  per phase); phase 1's `countries` and `storefronts` tables were **deliberately never built and are
  not owed**; **phase 5's `off|shadow|enforce` was never built, so that phase's own Go/No-Go
  criterion — a shadow run with no unexplained differences — cannot be met by the code that
  exists**; phase 10's four stop states became seven; and **GCC-20 has no phase at all** despite
  being some 45 classes plus seller-app work.

### Figures

From `php docs/gcc/audits/2026-11-24-post-merge-assessment/count.php` on `5aab34f9`:

**in code 1,538 of 1,754 (88%) · proven 1,127 of 1,754 (64%) · missing 216** — and the three states
still sum to 1,754. (Missing was 272 at the start of the owner's last instruction.)

---

## 4. What remains

### Immediate — the three CI causes above

A and C are small and well diagnosed. **B needs a decision, not a fix.**

### The next functional task that was queued and not started

**Complete the till worlds so the POS sale gate can be switched on (C-16).**
`PointOfSaleSaleRule` ships OFF because a market with no lifecycle record reads DRAFT, so an on
gate refuses till sales in worlds nobody made active. A grep suggests only
`PosOrderRecordsItsMarketTest` is affected, **but that must be measured, not guessed**: flip
`gcc.storefronts.sale_gate.at_the_till` on in a scratch run (do not commit it), find the real
at-risk set, complete each world with `approveNoTaxFor` + `activateStorefrontFor`, then default the
switch on and move C-16 to MET. Market activation stays inside a test's own config — the owner's
standing constraint is no market activation before G0.

### Reported and deliberately not fixed (each with its reason)

- **Marketing consents are not scoped at all.** `MarketingConsentRecorder` takes `storefront:` and
  `legalEntityId:` and **no caller passes either**; `grantForEmail()` hard-codes both null at
  `MarketingConsentRecorder.php:102-103`. Every row carries two nulls. This is the open half of
  M-556. Fix is three call sites plus two web controllers, reusing `forCountry()`.
- **`ScheduleDefinition` holds 45 entries with no market classification** — the open half of M-888.
- **A return naming no order line restocks its typed quantity uncapped** (2 sold, 4 typed → shelf
  54). Pinned today. **Owner question**, not a defect with one right answer: what does a return with
  no order line mean — goods arriving without an order, or a mis-typed RMA? Capping would break a
  possibly-correct path.
- **`RefundListener` is not queued at all.** It imports `ShouldQueue` and `InteractsWithQueue` and
  implements neither, so a refund push is sent inside the request that recorded the refund — and
  `CustomerStatusUpdateListener:19` justifies its own queuing with "follows RefundListener, which
  this project already runs on the queue", which is false.
- **`app/Jobs/SendEmailJob.php` is unreachable** — nothing dispatches it. Wired rather than deleted,
  because deleting changes the queued-class count and an inventory names it. Somebody should decide.
- **The own-warehouse authority is one env value for the whole platform** (A1-18, owner).
- **The channel account's country is settable through the service and console but has no row on the
  admin form.**
- **`StackAttention` builds a `PoolKey` without its market**, so with the switch on its
  over-subscription row misses a non-default market's pools. Two lines.
- **`ARCHITECTURE_AS_BUILT.md` §4 is stale on one line**: it says the search index has no market
  dimension, but `ProductSearchService:193` narrows through `StorefrontCatalog::restrictProductIds`
  before the cap and `StorefrontSearchIndexTest` pins it. The analytics and monitoring halves of
  that line are correct.
- **One property is now unpinned and the docblock says so.** `ThemeCopyToMarketTest` used to prove
  that a banner copy which cannot finish leaves no row behind. The storage track fixed the root
  cause (`49f0d21d`), so nothing reachable fails that way any more. A reproducer needs a writer that
  still throws after its own insert.

### Known limits of this container

No Flutter toolchain (so no `flutter test`, and the Dart work is verified by standalone Dart 3.13.0
only), MariaDB 10.11.14 rather than the pilot's 11.4.2, no MySQL 8, no SQLite run performed, and no
production or staging access of any kind.

---

## 5. The three decisions reserved for the owner — none was taken

1. **`scripts/ci/leak-guard-allow.json`.** The `No secret or personal data added` job reports
   **2 findings** in HISTORY. The current tree is clean and the guard scans history, so no code
   change clears it. The documented remedy is a reasoned entry in that allow file, which holds
   twenty-five precedents including two for itself tripping a rule while explaining a finding. **A
   request to add one was refused in this session as undermining oversight — which is the right
   guardrail on a security control. It was not routed around, and it is the owner's to choose.**
2. **Q-D1-1 — may a market's sales be taken through a gateway account belonging to another legal
   entity?** The repository answers this **twice, in opposite directions**: `PaymentBelongsToOrder`
   records as a design note that a market's account may belong to another country's company and
   deliberately does not compare them at refund time, while criterion M2-06 says the selling entity
   is what picks the account. Because neither answer is the owner's, the refusal **ships off** and
   today's behaviour is unchanged. If the answer is "not legitimate", the refusal belongs in
   `StorefrontPaymentReadiness` — refuse the market before it opens, not the customer's payment —
   and that was deliberately not built, because it is a settlement rule.
   Also open: **Q-D1-2** (what becomes of `GCC_LEGAL_ENTITY` now the registry answers per
   storefront) and **Q-D1-3** (`forCountry()` vs `forProduct()` diverge for a product naming an
   inactive company or one of another country).
3. **The stray remote branch `origin/claude/elegant-goodall-who7tz`.** I created it by mistake —
   `git push -u origin <branch>` pushes the branch you NAME, not the one you are on, so eleven
   commits sat unpushed while a stale local branch went up. It sits at `ce536e2a`, which is `main`'s
   tip, and holds **0 commits not already on `main`**, so it is noise and nothing more. Deleting it
   was refused by the permission classifier, correctly, as a destructive remote action. It is safe
   to delete and safe to leave.

**Also still forbidden without separate approval, and not done:** no merge into `main`, no pull
request, no production deploy or release, no production database access, no market activation, no
credentials rotation, no force push and no history rewrite. G0 remains open.

---

## 6. How to work on this, from what it cost this session

Read `docs/gcc/EXECUTION_STATE.md` first — it is the session-to-session memory and it grew by about
eighteen lessons today, most of them paid for by a real failure. The ones that would have saved the
most time:

- **A CI job log read from the END is container teardown, not test output.** Let the tool write the
  log to a file and parse it for PHPUnit's own shapes (`^\d+\) `, `Failed asserting`, `^Tests:`). The
  long numbered list near the end is usually the **skip** listing: one job this session looked like
  61 failures and had one.
- **Read a PHPUnit array diff by its markers, not its shape.** `-` is expected-only, `+` is
  actual-only. I read one backwards, concluded a scan had broken, and went hunting a fault that did
  not exist — the diff was telling me four new files had started naming a money column.
- **Map an `F` in the progress output to a name by index** (61 per line) against
  `--list-tests` in the same argument order. That replaced a three-hour wait with one call.
- **Do not edit files under a running suite**, and do not believe a failure from a contended box
  until it is re-run alone. Both mistakes were made this session and both are recorded.
- **File ownership does not stop two tracks building the same mechanism.** Two tracks wrote a POS
  sale gate in two files and wired both into the same three actions, answering differently on two
  inputs. Partition the **questions**, not only the files.
- **A test that pins a defect becomes wrong the moment somebody fixes the defect**, and that is not a
  regression: rewrite it to the corrected behaviour with the old assertions quoted, and say what is
  now unpinned.
- **`count.php` is the only source of the figures**, and its one invariant is that the three states
  sum to the criteria count. Never invent a fourth state word.
- A track never promotes its own tracker card; the coordinator applies register edits centrally.
