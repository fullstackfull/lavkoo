# LAVKOO — execution state

The one file to read to resume. It says what was finished last, what is next, what is waiting on the owner, and where
the work is. It is updated as part of each batch, not as a separate exercise.

**Standing authority (owner, this session).** Continuous independent development on `gcc/development`, one branch per
repository, without asking for approval per feature or per technical decision. Forbidden without separate approval:
production deploy, production data, real balance migration, activating a country before G0 closes, weakening a security
or financial control. A pending owner decision stops its own task, never the project.

---

## The parallel tracks, and how to pick them up (2026-11-25, owner's instruction)

The owner asked for organised parallel development across five tracks. It is set up as FIVE LOCAL GIT WORKTREES, not
five branches and not five pull requests -- `gcc/development` stays the one official branch per repository, which is what
the owner required.

**Second batch (from `b7e5f180`), re-cut so that every axis has exactly one owner and the 334 remaining criteria are
partitioned with no overlap.** Each track also has its OWN database, because the owner forbids concurrent heavy groups
that share files or a database:

| worktree | local branch | axes | missing | database |
|---|---|---|---|---|
| `/home/user/wt/m` | `wt/m` | M, G | 87 | `pharmacy_testing_m` |
| `/home/user/wt/de` | `wt/de` | D, E | 52 | `pharmacy_testing_de` |
| `/home/user/wt/acgh` | `wt/acgh` | H, C, A | 71 | `pharmacy_testing_acgh` |
| `/home/user/wt/bfi` | `wt/bfi` | I, B, F | 58 | `pharmacy_testing_bfi` |
| `/home/user/wt/klj` | `wt/klj` | K, L, J (+ both app repositories) | 66 | `pharmacy_testing_klj` |

87 + 52 + 71 + 58 + 66 = 334, which is the whole remaining set. `/home/user/wt/kl` is a worktree of **lavkooappseller**
holding the already-merged Jetifier fix -- do not reuse that path for a backend track, which is why the fifth is `klj`.

The first batch was cut by convenience rather than by coverage (D+E, B+F+I, A+C+G+H, J, K+L) and left M -- the largest
axis on the project, and the one with the lowest proven rate -- without a dedicated owner until late.

**Honest about the hardware:** this container has FOUR cores. Five tracks interleave; they do not run five-wide. The
gain is real but it is not 5x, and claiming otherwise would be claiming parallelism that is not available.

**How a backend worktree is made testable, because this is the part with a trap in it.** `cp -al vendor <wt>/vendor`
hardlinks the 11GB directory for about nothing, but `composer dump-autoload` then WRITES THROUGH the hardlink and the
main checkout's class map goes with it. So `vendor/composer` (8.4MB) is replaced with a REAL copy in each worktree before
dumping:

    cp -al vendor /home/user/wt/<t>/vendor
    rm -rf /home/user/wt/<t>/vendor/composer && cp -a vendor /home/user/wt/<t>/vendor/composer   # breaks the hardlink
    cp .env /home/user/wt/<t>/.env
    (cd /home/user/wt/<t> && composer dump-autoload)

Verify it rather than trust it: `stat -c %i <wt>/vendor/composer/autoload_psr4.php` must differ per checkout, and
`php -r 'require "vendor/autoload.php"; echo (new ReflectionClass(App\Utils\OrderManager::class))->getFileName();'`
must answer that worktree's own path. Three worktrees cost about 1GB in total this way.

**What the tracks may and may not do.** They write code and targeted tests and commit in their own worktree. They never
push, never open a pull request and never run a whole PHPUnit group -- this container has FOUR cores and the heavy groups
are the coordinator's to run, one at a time. Each track is given explicit file boundaries; measured across the three
backend tracks, exactly ONE file was touched by more than one of them (`config/gcc.php`, additive keys in different
sections), which is the check to re-run before every merge:

    for t in de bfi acgh; do (cd /home/user/wt/$t && b=$(git merge-base HEAD gcc/development); git diff --name-only $b..HEAD); done | sort | uniq -d

**Three migrations were written with the same timestamp prefix** (`2026_12_27_100000`) by three tracks independently.
Different filenames, so git does not conflict and Laravel orders them by the rest of the name, but renumber them at merge
time rather than leaving three files claiming one minute.

**The apps are verified by CI and by nothing else here**, and that is not a formality: the first merged track came back
with a compile ERROR (`_storefrontCode` declared on one class and called from another), and because the analyze step
precedes the test steps in that job, the failure SKIPPED every test in it. Merge the track, push, read the run, fix.
Never move a card to MET on a track's own report.

## Where the work is

| repository | branch | tip | merged to main |
|---|---|---|---|
| `fullstackfull/lavkoo` | `gcc/development` | see `git log -1` | up to `ca4f68e0`, whose tree is byte-identical to `bc39790` -- the SHA CI run 655 proved green. Everything after it waits on a green run of its own |
| `fullstackfull/lavkooapp` | `gcc/development` | see `git log -1` | up to `0981b9d`, whose tree is byte-identical to `c6d8ea6` -- the SHA CI run 54 proved green (the central address catalogue client, J-14) |
| `fullstackfull/lavkooappseller` | `gcc/development` | see `git log -1` | up to `d948b49`, whose tree is byte-identical to `fecce0b` -- the SHA CI run 56 proved green (the Super Admin health screen, L-09) |

The customer app's CI has no `workflow_dispatch`: it runs on a push to `gcc/**`, so every push to that branch is its own
run and no dispatch is needed. The backend's runs ONLY on dispatch for a branch, so a push there has to be followed by
one.

**Dispatch the backend ONCE per batch, and let it finish.** Its concurrency group is `ci-<ref>` with
`cancel-in-progress` true only for a pull request, so a dispatch while another run is going does not cancel it -- it
QUEUES. Queue a third and GitHub discards the one waiting in the middle. That is why runs 658, 659 and 660 all read
"cancelled" while only 661 ran: each dispatch superseded the pending one before it. Finish the batch, push, dispatch
once, and read that run.

**How a stage is merged.** CI is dispatched on the branch tip; when it comes back green, THAT SHA is merged into `main`
with `--no-ff` and nothing newer is swept in with it. The check is mechanical: `git diff <tested sha> HEAD --name-only`
must print nothing after the merge. Run 655 / `bc39790` / `ca4f68e0` is the worked example.

## Progress, computed and not estimated

`php docs/gcc/audits/2026-11-24-post-merge-assessment/count.php` prints every figure in STATUS.md, the master tracker
and the audit README. Never type a number that script can print.

## Owner decisions taken (do not re-ask)

- **F-13** — LAVKOO identity is global by default; a market may override its marketing name, logos and colours. The
  legal identity of each company stays separate, and legal use needs review.
- **I2-09** — every NEW point-of-sale sale follows the country of its products and their legal company and respects the
  sale gate and the market's state. Orders placed before a stop keep being processed, sales-channel obligations
  included, without opening new sales in an unauthorised way.
- **A1-17** — push topics and marketing notifications are separate per market and the customer app subscribes to its
  own market's topic. Notifications that an existing order needs keep going even when the market has stopped selling.
- **E-02** — preserve Saudi behaviour, and never switch on earning or converting loyalty points automatically in a new
  market that has not adopted its own programme.
- **A1-02** — legacy mode stays; a market is closed through its own country settings and lifecycle.
- **A1-03** — the default storefront is a recorded decision, read at boot, not a deploy.

## Owner decisions still open (build the mechanism dormant, move on)

- **LF-1** — what a loyalty point is worth. Seam ready (`WhatAPointIsWorth`, `gcc.loyalty.point_value`), nothing adopted.
- **E11-F02** — which currency a courier settles in. Seam ready (`WhatACourierIsPaidIn`), nothing adopted; a mislabelled
  shipment is held rather than sent.
- **E04-F10** — whether an admin-cancelled wallet-paid order refunds itself. Seam ready
  (`WhenACancellationRefundsTheWallet`), default is today's behaviour.
- **Q-33** — which carrier serves which country, under which contract and company id. `ProviderRouteSupport` holds the
  answer; both carriers declare SA:SA until it is written down.
- **Q-13** — who grants and maintains product destination permissions, which regulated classes may cross which border,
  and who is entitled to approve that list. The table, the deny-by-default rule, the admin screen with its audit and
  the regulated gate are all in; `recorded_by`, `recorded_at` and the clearance reference are there so whoever it is
  leaves a trace, and `gcc.shipping.regulated.clearance_module` is a setting so naming the entitled role is not a code
  change. `gcc.shipping.regulated.mode` ships `off`, so until the lists are written the gate refuses nothing.
- **I2-09 (remainder)** — what happens to marketplace orders of a paused country.
- **A1-17 (remainder)** — nothing: the topic decision is taken, above.

## Next, in dependency order

Done since this file was written: H1-02, H1-03 (its admin screen and audit included), H1-06 (the engine, the three API
paths, the website, the stored decision, the dispatch re-ask) and H1-04 (the regulated gate, its clearance, the whole
refusal vocabulary in Arabic and English, and the customer app showing every reason).

H1-09 is done but for one criterion, and that one is BLOCKED rather than unwritten: naming which tax regime a
cross-border sale falls under needs the owner's Q-11 answered. H1-03 and H1-06 are complete in code.

Done since: the seller's held shipments, which closed H1-04 and H1-06 completely; H1-08's zone rule pinned, the app and
website brought into agreement, the per-kilogram rate given a weight and the rate's origin recorded on the order; and
H1-05's city serviceability moved from the dispatcher to the checkout. H1-03, H1-04 and H1-06 are complete in code.
What is left on H1-05, H1-08 and H1-09 is owner decisions (licensed city lists, Q-07, Q-08, Q-11), not code.

Done since: **H1-13 is complete** — all four criteria. Its matrix found the engine comparing a country's name with its
code (fixed in `e97ba253`, the worst kind of defect: dormant code that would have refused every order the day it was
switched on). The return half is now built too: `App\Services\Returns\CrossBorderReturn` puts a returned unit back on
the shelf of the market that SOLD it and records the currency the customer paid in, and one order in the matrix walks
the whole way -- checkout, decision, duty quote, dispatch, return, shelf -- because two tests that each assume the
other's half prove no span.

Done since: **H1-11's first three criteria**. The closed `ProviderCode` enum is no longer the gate -- a carrier is one
adapter class plus one configuration entry, resolved by `ProviderRegistry` through a `ProviderIdentity` the enum
implements, so all 827 places that name a case are untouched. The same pass fixed the H1-13 family of defect again: the
four places that tell a carrier where a parcel goes were asking which market SOLD the order.

Done since: **L-09's endpoint**, its capability and two of its four signals. Nothing in the tracker's count moves, because each of its
criteria names more signals than are built -- which is the honest record and is written out in the card.

1. **H1-11's remainder** — one carrier code serving two countries with separate credentials (`integration_settings` is
   unique on `provider` alone, so it needs a row keyed by code AND country). Delivery Syria waits on H1-14.
2. **H1-14** — local shipping rules belong to a storefront and are never inherited (3 missing).
3. **H1-02 remainder** — filtering candidate cities by the country of OUR city. Blocked on H1-10 / the address system
   going multi-country; recorded as blocked rather than faked.
4. **L-09's remaining signals** — the carrier half (`provider_sync_states` already carries the figures; it needs the provider mapped to a
   market), then reconciliation breaks, then staff and seller issues, then an alert per signal.
5. **H2-06, H2-07 and H2-10** are the largest remaining gaps in H2 (the marketplace integrations).
6. Then the axes with the most missing, by dependency rather than by ease: L (GCC-20 super admin), K and J (the two
   apps), B, F, G.

## Rules this work follows, learned the hard way

- Never write to or delete a path before reading what is there and `git log --oneline -- <path>`.
- **One PHPUnit suite at a time in this container.** Two suites run at once share `resources/lang`, the bootstrap
  cache and the storage directory, and the four-group run of 2026-11-25 came back with 4 failures in
  AdminSalesChannelsAccountsTest that do not exist when that group runs alone (1,977 green). A verdict from an
  overlapped run proves nothing and costs a re-run to find out.
- **Do not edit a file while a suite that loads it is running.** PHPUnit reads a class the first time it is used, so an
  edit lands halfway through the run and the result describes no single tree. Finish the edits, then run.
- Every new switch ships off, and the first test asserts that with it off nothing changed.
- A guard that fails open is reported, not relaxed; a guard that asks to be recorded is recorded.
- A test that proves a problem exists is not a fix for it.
- Teeth-check every feature: break it deliberately, see the test fail, restore.
- Never trust a long test run that was in flight while files were edited: it reports failures that a clean re-run does
  not. It has happened twice. Re-run on an untouched tree before believing a regression.
- A text replace whose needle is a statement rather than a whole LINE will match inside a commented-out line, and
  inserting a newline there uncomments whatever followed the needle. That is how a dead `import` at the foot of the
  customer app's checkout controller became live code and failed the analyzer. Match the whole line, comment marker
  included.
- A gate that prints a count and withholds the lines costs a round trip every time it fires. When one does that, make it
  print what it found before fixing what it found (the app's `analyze` job, now fixed).
- `resources/lang` is test-managed: `translate()` writes into it and `RestoresLanguageFiles` puts it back. An edit made
  there while a suite is running is silently reverted, which looks exactly like the edit never happening.
- SQLite does not enforce what MySQL does. An index name Laravel builds from a long table and two columns passed the
  whole SQLite suite and failed on MariaDB at 69 characters against a limit of 64; `MigrationIdentifierLengthTest` now
  catches that from the source. Run a new migration against the container's MariaDB (`-c phpunit.mysql.xml`) before
  believing it.
- A static schema cache in a service outlives the schema on a real server, where every test drops the tables. Such a
  cache belongs in the base `TestCase`'s flush list, not in each test that happens to trip over it.
- **A reading of what the code MEANT is a hypothesis, and a group run is the answer.** `CartManager` leaves the shipping
  type as `''` when the `shipping_method` setting is missing, which is not `order_wise`, so such a shop drops its
  cart-shipping row and charges per product. That looked like a defect and the "fix" -- defaulting to `order_wise` --
  made the charge ZERO for those shops, because nothing writes a cart-shipping row for them. Thirty-nine tests across
  the address and storefront groups said so, and they were right. Before changing a default that money passes through,
  run the groups that touch money; a targeted test passing proves only that the new behaviour is self-consistent.
- **CORRECTION (2026-11-25): MariaDB is NOT installed in this container and `service mariadb start` answers "unrecognized
  service".** The recipe below was written in a container that had it. To get a real engine here:
  `apt-get update && apt-get install -y --no-install-recommends mariadb-server mariadb-client`, then start `mariadbd` BY
  HAND (`invoke-rc.d` is policy-denied), then create the user as below. Two defects in one batch were found only by doing
  this and would have passed CI's SQLite jobs: `DB::table()->whereKey()` is the MODEL's method and compiles to
  `where('key', 1)`, which MariaDB rejects as an unknown column while SQLite read the unknown name as a string, returned
  nothing, and let a byte-for-byte assertion pass FOR THE WRONG REASON; and `expires_at` as TIMESTAMP silently stored
  `2099-01-31` as `0000-00-00` because MySQL's TIMESTAMP range ends in 2038, so a far-future expiry read back as already
  expired, while SQLite stored it happily. Use DATETIME for a date that may be beyond 2038.
  Where two tracks share this container, give each its own DATABASE (`pharmacy_testing_de` and so on) so one suite cannot
  clobber another's.
- **CORRECTION OF THE CORRECTION (2026-11-25, later): MariaDB 10.11.14 IS now installed in this container** -- a track
  installed it with the apt recipe above and `mariadbd` is running. So a real engine is available without doing that
  again: `mysql -h 127.0.0.1 -u ci -pci-pass`. The PILOT runs **11.4.2**, which apt here will not give, so a criterion
  that names the pilot's engine version cannot be closed in this container and must say so instead of being closed on
  10.11.
- **A real engine can be run IN this container, and should be, before pushing a schema or collation test.**
  `service mariadb start`, then once: `mysql -u root -e "CREATE USER IF NOT EXISTS 'ci'@'%' IDENTIFIED BY 'ci-pass'; GRANT ALL
  PRIVILEGES ON *.* TO 'ci'@'%' WITH GRANT OPTION; FLUSH PRIVILEGES;"` -- root authenticates by unix socket, so a TCP connection as root is
  denied and a user has to be made. Then
  `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=pharmacy_testing DB_USERNAME=ci DB_PASSWORD=ci-pass php vendor/bin/phpunit -c
  phpunit.mysql.xml <paths>`. This would have caught run 664's one failure before it was pushed: a test asserting that the market compares
  BYTE for byte built its own `char(2)` column, which takes the engine's own case-INSENSITIVE collation, so `kw` matched `KW` on MariaDB and
  MySQL 8 and did not on SQLite. The repair is the shape to copy -- the test creates the table WITHOUT the column and runs the real migration
  to add it, so the column is exactly what production gets, `ascii_bin` and all.
- **This platform has TWO user models, and the guards hand over the one the services do not use.** `App\Models\User` is what the
  panels and the services take; `config/auth.php` gives the `api` and `customer` guards `App\User`. So `$request->user()` and
  `auth('customer')->user()` answer the second one. A service typed on the first is a TypeError on every customer-facing caller, and
  `actingAs($customer, 'api')` HIDES it, because it injects the instance the test made. `MarketBalanceReader` shipped that way and was a
  500 on the wallet list, the loyalty list and both web pages for every real customer. Type such a parameter on both, and write at least
  one test that loads the model the guard would.
- **A per-market read is unreachable until a route resolves a storefront.** The `storefront` middleware is applied per sub-group in
  `routes/rest_api/v1/api.php`, not to the `v1` group: `customer/info`, `customer/wallet/*` and `customer/loyalty/*` carried `auth:api`
  and nothing else, so the app's `X-Storefront` header never reached three controllers that had already been wired to
  `MarketBalanceReader`. Wiring a reader is half the job; grep the route file for the group before claiming the read happens.
- **A balance column can be NULL, and a declared `float|int` return makes that a TypeError** rather than the null every surface has
  always shown. Widen the type and cast where the number is spent, not where it is read.
- **A rate or a switch that belongs to a MARKET must be read for the market of the ORDER, not of the request.** Every writer that credits
  an order's points is reached from a panel or a seller API, where `StorefrontCatalog::country()` is null -- correctly, because a panel
  sees every market. `StorefrontCurrency::withinOrder($order, ...)` is the way in, and the order must be read with the QUERY BUILDER:
  `App\Models\Order` carries `StaffCountryOrderScope`, so a restricted employee marking an order delivered finds no row through the
  model and the rate falls back to the platform's with nothing saying so. That trap is now a test.
- **The platform-admin area may not name the seller world at all.** `ReverseIsolationTest` scans every file under
  `app/Services/PlatformAdmin`, the platform middleware, controllers and commands for `Seller`, `seller_`, `auth_token`,
  `Passport`, `Sanctum`, `Auth::` and `auth(` -- comments stripped first, so what a file SAYS about what it refuses does
  not count. L-09's first version read `seller_sla_breaches` straight from `PlatformHealthService` and tripped it. The
  answer is not an exemption: the reads moved to `App\Services\Marketplace\ShopIssueCounts`, which the platform
  service asks for counts, and the API field became `open_shop_issues`. The guard also stops at its FIRST violating
  file in glob order, so a clean report on one file does not mean the next is clean.
- **A method a parent gains must not already exist as `private` in a child.** PHP will not narrow an inherited method,
  so the child file becomes a fatal error on load -- exit 255 and NOT a test failure, which is why it took a CI job log
  to see it. H1-11 put `protected fakeOrderAccepted()` on `TorodTestCase` while `TorodCityResolutionTest` had carried a
  private one since it was written, and it cost the whole SQLite suite and the whole Address group. A one-line scan
  over the test tree for a private override of a protected ancestor method found exactly one real case and two
  namespace-collision false positives; worth running after adding a helper to any base test case.
- **A test that builds its own copy of a migrated table must put the table BACK.** Tables persist across classes within
  a process, so a class that drops one and walks away takes it from every class that runs after it -- on the in-memory
  database, all of them. `Tests\Support\RestoresCategoryShippingCostsTable` is the shape: drop, then re-run the real
  create migration. A table with no create migration (`shipping_methods`, `payment_requests`) is the opposite case --
  absent IS its migrated state, so dropping it is the restore.
- **A test of a helper is not a test of the caller that was supposed to use it.** Twice in one batch: a direct test of
  `marketKeyOf()` stayed green when the call was taken out of the controller, and a direct test of
  `soleMarketOfShop()` would have too. The teeth check is what finds this -- remove the CALL, not the helper -- and the
  replacement test goes through the caller: two costs of one category in two markets, with only the named one changing.
- Where a record cannot name the rule that produced a figure, it says nothing. `shipping_rate_source` is null for a shop
  in that state rather than guessing `product_wise`, because the type written on those lines is an empty string too.
- **`requestObj` in `generateOrder`'s data is NOT always a Request, and a typed parameter turns that into a TypeError on a money
  path.** `app/Utils/module-helper.php` -- the payment-settlement path -- builds it as an ARRAY and merges that array into the GLOBAL
  request before calling, so the global request is where its input actually is; the web and API callers pass a real Request. Every
  existing reader got away with it by being untyped (`Helpers::getCustomerInformation($data['requestObj'] ?? request())`). I2-05's
  receipt call was typed `Request`, and the result was 39 errors and 12 failures across the storefront group whose visible symptom was
  a settlement asserting `status => settled` and getting `settle_failed` -- i.e. the failure surfaced two layers from its cause. Guard
  it with `instanceof Request` and fall back to `request()`. The general lesson: a NEW typed call site inside `generateOrder` inherits
  seven callers' habits, and one of them is not what the key's name says it is.
- **A test of a group is the only thing that finds a defect whose symptom is in another feature.** The TypeError above passed
  `php -l`, passed its own 15-test file, and passed four neighbouring classes. It took the 5,051-test storefront group to show it, in a
  class about stopping a market. Run the group before pushing anything that touches `generateOrder`.
- **The tracker lags the code, and reconciling it is work rather than bookkeeping.** Four cards checked in one batch were behind what
  the repository already does: F-04's last criterion was fully built (`ThemeCacheKeysAreStorefrontAwareTest` declares and ENFORCES the
  global keys, and `CampaignResolver` had already moved to a scoped key); D1-01's "orders has no such column" was false
  (`2026_11_26_100000_add_storefront_snapshot_to_orders_table`); B-02's "the gallery copy form pre-fills the source price" was moot
  (`ProductCopyToCountry` zeroes price, purchase price, discount, stock and every variant, and creates the copy unpublished, with
  `ProductCopyToCountryTest` asserting it); and part of I2-05 had been built as `CheckoutLegalAcceptance` after the card was written.
  Read the code before coding a card's "what remains": the percentage the owner reads is computed from these cards, so a stale MISSING
  understates the project exactly as badly as a stale MET overstates it.
- **Two readings of one question agreeing is worth more than either alone.** F-04's global-cache verdicts were re-derived from scratch,
  input by input, by a separate audit that had not read the declaration list, and it reached the same answer on all six keys -- and
  added two things the cards had not joined up: the analytics rollup has no market dimension at all (so every reach figure an operator
  arranges a storefront by is a platform total, which no cache key can fix), and `total_spent` sums `order_amount` across orders
  carrying different `currency_code`s. Both are in coverage-gaps now. Where scoping a key would DISGUISE a defect rather than fix it,
  say so in the declaration instead of scoping it.

### Learned on 2026-11-25, in the five-track batch

- **A child class may not NARROW a parent's signature, and PHP refuses that at CLASS LOAD -- not as a failing test.**
  `AuditLogger::record()` gained `?string $country`, and one test file still mirrored the old five-parameter signature in
  an anonymous subclass. The result was exit 255 the moment PHPUnit loaded that file: ~20,000 tests never ran, in every
  job that reached it -- both SQLite runs, the strict-mode run and the fulfillment group -- and a local full run reported
  NO failures at all, because it had aborted. Two consequences worth keeping:
  - `php vendor/bin/phpunit --list-tests` loads every test class without running one. It is the cheap detector for this
    whole family of fault and it takes seconds.
  - **Do not mirror a signature you do not use.** The suite already had `Tests\Support\BuildsAuditLogTable`: build the
    real table, let the real service write, assert on the row. That also tests more than a double can, since a double
    cannot catch a line the service failed to persist. `AuditTrailIsNotMirroredTest` now fails any test that
    `extends AuditLogger`.
- **When a deliberate change alters HOW MANY queries a security-sensitive read makes, grep for tests that COUNT its
  queries -- not only for its callers.** `StaffCountryScope::resolve()` asks `admin_country_scopes` two different
  questions on purpose (G1-14): has this employee any row, and which rows are still in force. An employee with NO row is
  unrestricted here, so merging those into one "ignore the expired rows" query would make an employee whose every row has
  expired read as unrestricted -- a privilege escalation on a timer. Two tests predating that change asserted ONE read
  (`StaffLayoutTest`, `StaffSearchHitsTest`), and both failures arrived looking exactly like a performance regression
  inviting precisely the repair that must not be made. Fix the expectation, pin it, and say in the test why the old
  number was right when it was written.
- **A handed-over diagnosis is a hypothesis, including one from another agent.** "Somebody added a second resolve on the
  layout path" was wrong -- nothing was added; the read itself became two queries. Acting on the diagnosis instead of
  measuring it would have removed a security property.
- **A new test directory is invisible to the real engines until it is named in a CI group.**
  `CiEveryTestDirectoryRunsOnARealEngineTest` catches it. `tests/Feature/Currency` is the sharpest case so far: it
  measures which row an UNORDERED query returns first, which is exactly what SQLite and MySQL are free to answer
  differently, so proving it on SQLite proved nothing about the server.
- **Every new table needs an entry in `docs/gcc/table-ownership.json`.** That file conflicted in EVERY merge of this
  batch, because every track adds tables and the register is one file. If you add a table, add the entry and name it in
  your handback so the coordinator resolves it centrally. `TableOwnershipTest` needs no database -- run that one file
  before each merge.
- **Settle a classification disagreement on the register's own definitions, not by counting votes.** Four readings split
  2-2 on `notification_channel_profiles`, and BOTH leading arguments were factually false: "it is `country` because it
  names no legal entity" (`storefront_legal_documents` is `storefront` and names none either) and "`storefront` is what
  every market column is filed as" (four tables are `country`). What decided it was A1-09 -- the ISO code is the
  storefront key only FOR NOW, so the question is which owner the row follows once a storefront has an identity of its
  own. A sending account follows the shop.
- **Teeth must be measured on the engine where the assertion actually bites.** Swapping `dateTime()` for `timestamp()`
  fails the past-2038 test on MariaDB and changes NOTHING on SQLite, which keeps dates as text. An assertion with no
  teeth on the engine you ran is not evidence; say which engine proved it.
- **A guard's own teeth can come back inert, and that is a finding, not a tidy-up.** Removing `narrowLayout`'s
  `attributes->has()` guard failed nothing, because on that path nothing binds before the view composer runs. Written
  into the test rather than left as false coverage.
- **A narrowing that reads `boundCountries()` proves nothing until something on that route BINDS them**, and a test that
  sets the request attribute by hand and calls the controller directly cannot tell the difference. That is how the audit
  centre shipped a guard that read every market's trail while looking narrowed. Drive the real route.
- **A refusal test alone cannot distinguish a guard from a wrong URL.** Every refusal test needs its unrestricted twin;
  a track caught its own false pass that way, having asserted 404 against a URL that never existed.
- **Check what a monitoring probe actually reports before describing a monitoring gap.**
  `SystemHealthService::snapshot()` wraps each section in a try/catch, so a missing table reads `['error' => ...]` --
  broken, and honestly broken. It does NOT report a reassuring zero. The dramatic reading was the wrong one, and
  overstating a blast radius is not the safe direction: it is the kind of error that stops a real defect being fixed.
- **In a secret scanner, interpolation must be STRIPPED, not excused.** `looksLikeSecret` judges a LITERAL, and PHP's
  `{$name}` contributes no literal content, so `"gateways.{$name}"` -- a key path being read -- was refused as a
  credential. Adding `{$` to the placeholder list is one character's work and measurably worse: it excuses the whole
  value, so `"sk_live_...{$suffix}"` and `"{$prefix}Zq81-mailbox-pass-4417"` both pass. Stripping leaves the literal half
  to be judged. Never silence such a finding with an allow-list entry: that silences the next real finding on that line
  too.
- **This container was missing ONE PHP extension that CI installs, and it cost a wrong diagnosis.** CI's
  `PHP_EXTENSIONS` list includes `bcmath`; this image did not have it. So every money test that sums exactly with
  `bcadd()` ERRORED locally -- `Call to undefined function bcadd()` -- while passing in CI. I read a local storefront
  run's 56 failures as a reproduction of CI's red storefront job and said so; it was not one, because the two
  environments were not the same. Fixed once and for good with `apt-get install -y php8.3-bcmath`, after which the
  refund file is 27 green. **Before trusting a local run against a CI result, diff the extension lists**:
  `php -m` against `PHP_EXTENSIONS` in `.github/workflows/ci.yml`. `bcmath` was the only one missing, and it is
  installed now.
- **A class that passes alone and fails inside its group is a different bug from the one it looks like.**
  `StorefrontCustomerSignInTest` is 61 green on MariaDB on its own and failed 33 times inside the group run. Run the
  file alone before reading a group's failures as defects in the classes they are reported against -- the index-mapping
  trick below makes that cheap.
- **A killed or still-running suite can still name its failures.** `phpunit --list-tests` prints tests in execution
  order, so the position of each `F` in the progress output maps to a test name: count the marks per line against the
  `n / total` counter, then index into the list. That turned an abandoned run into six named classes without re-running
  anything, and it is the way to read a run that is too slow to finish. Mind the offset if the tree has changed since.
- **`config/database.php` sets `'strict' => false`, and six report screens depend on it.** Laravel answers that by
  setting `sql_mode='NO_ENGINE_SUBSTITUTION'` for the session, which REMOVES `ONLY_FULL_GROUP_BY` even on a MySQL 8
  server whose global default includes it. Eleven call sites in six controllers -- admin AND vendor panels -- select
  `YEAR(created_at)`/`MONTH(created_at)` and order by `created_at` while grouping by `DATE_FORMAT(created_at, '%M')`,
  which is error 1055 and an HTTP 500 the moment anyone hardens that line. The same grouping is wrong across years:
  `'%M'` groups January 2025 with January 2026. This is why a strict-mode run of the GCC groups cannot simply be
  switched on, and the repair is a money-report change with figures to compare.
- **Do not run a full local suite while the parallel tracks are working.** Five tracks plus a full suite on four CPUs
  stalled the suite at 43% for ten minutes and starved the tracks of the CPU the owner asked to be spent on them. CI's
  own SQLite jobs run the same full suite on a clean tree for free; use those and keep the local box for targeted
  directories.
