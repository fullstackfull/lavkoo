# LAVKOO — execution state

The one file to read to resume. It says what was finished last, what is next, what is waiting on the owner, and where
the work is. It is updated as part of each batch, not as a separate exercise.

**Standing authority (owner, this session).** Continuous independent development on `gcc/development`, one branch per
repository, without asking for approval per feature or per technical decision. Forbidden without separate approval:
production deploy, production data, real balance migration, activating a country before G0 closes, weakening a security
or financial control. A pending owner decision stops its own task, never the project.

---

## Picked up from the 2026-11-26 handover: CI run 678's four causes

Read `docs/gcc/HANDOFF-2026-11-26-TO-NEXT-CLAUDE.md` for the state this was picked up in. Run 678
(<https://github.com/fullstackfull/lavkoo/actions/runs/45744979283>, `workflow_dispatch` on `5aab34f9`) was 41 success,
9 failure, 2 skipped, and its nine red jobs came from three code causes plus the leak guard. Where each stands:

| cause | jobs | state |
|---|---|---|
| **A** — the staging seeder wrote `users.wallet_balance` | 7 | **FIXED** in `8fedd536`. The seeder stopped being a writer rather than being declared one |
| **C** — a `json` column's keys come back in the engine's order | 1 | **FIXED** in `09cb6cc4`. Read through the helper this suite already had |
| **B** — `dropUnique()` against the expand-only contract | 2 (both SQLite) | **OPEN, the owner's.** `docs/gcc/M-656-STOCK-POOL-UNIQUE-KEY-EXPAND-ONLY.md` (`78b9a174`): measured, two precedents named, options costed, recommendation A. Not applied |
| **D** — the leak guard's 2 history findings | 1 | **OPEN, the owner's.** Reproduced and characterised below. Not routed around |

**Cause A, and why the guard was not widened.** `WalletBalanceWritersTest` reported
`database/seeders/GccStagingSeeder.php` as a sixth writer of `users.wallet_balance`. The handover expected "likely a
one-entry declaration". It is not: that list is *also* the list of the places that take the customer's row lock and
write the ledger row with the balance the account ends with, and the seeder did neither, so a sixth entry would have
made the list's own sentence false for every later reader. `createWallets()` now goes through
`CustomerWalletWriter::record()` — the one locked writer — and **the writers list is unchanged, which is the proof.**
Because that writer moves nothing while the wallet feature is off, `run()` asks before the first row of the first
market, so no market is left holding a seller, shop, customer, products and an order but no wallet, which the next run
would skip. 136 tests / 2,078 assertions green on SQLite and MariaDB 10.11.14.

**Cause C, and how its scope was settled without MySQL 8.** `platform_admin_audit_logs.context` is a `json` column;
MySQL 8 returns a json object's keys by LENGTH then bytewise, MariaDB keeps the written order, and two assertions
compared the decoded array with `assertSame`. The suite already had `contextOf()` + `sortedByKey()` for exactly this,
with the reason in its docblock, and fifteen sibling assertions already used them. The scope is those two and no
others, and **CI's own green jobs are what settled that**: every other test comparing a decoded JSON column with an
order-sensitive `assertSame` lives in a directory run 678 ran on MySQL 8 and passed. Reasoning statically about
production migrations said otherwise and was wrong, because what matters is the column type in the TEST's schema, not
in the real migration — several of those tests build the column as `text`.

**Cause D, reproduced so the owner's decision is informed.** `php scripts/ci/leak-guard.php --base=origin/main`
answers 2 findings, and they are:

 - `tests/Feature/SalesChannels/AdminNoonAccountTest.php:41`, rule `private-key` — a test fixture whose `private_key`
   value is a PEM **header and footer with nothing between them**. The rule matches the delimiters, not a key;
 - `b7e5f1809f scripts/ci/leak-guard.php:312`, rule `api-token` — an explanatory **comment** in the guard's own source
   that at the time spelled out a Stripe-style example while explaining why interpolation is stripped. The comment has
   since been rewritten and the current tree is clean.

So neither is a real credential, and **neither can be cleared by a code change**: the guard scans what the branch ADDS
against `origin/main`, so rewriting the line converts a current-tree finding into a history one (which is exactly how
the second already reads). The documented remedy is a reasoned entry in `leak-guard-allow.json`, and that is the
owner's to write — a request for one was refused in the previous session as undermining oversight, and it was not
routed around here either.

**What C-16 cost to measure.** See `845ccb77`. `config/gcc.php` named six test worlds that would break when the till's
sale gate was switched on; running all six plus every other file that both places a till sale and switches markets on
(13 files, 276 tests) with `GCC_SALE_GATE_AT_THE_TILL=1` returned **exactly two failures**, both data sets of one test
in `PosOrderRecordsItsMarketTest`. The named list was a conservative superset. The switch is still OFF: the broad sweep
over all 13 directories that can place a till sale (14,230 tests) has to come back clean before a default on a money
path moves.

---

## The parallel tracks — THIRD cut (2026-11-26), re-partitioned on the current 216 missing

The owner reaffirmed the five-track worktree method on 2026-11-26 and asked for the distribution to be re-cut on
**what actually remains**, not on the stale 334. `count.php` on `f8f3d631` says **216 missing** (in code 1,538 of
1,754, 88%; proven 1,127, 64%), so the partition is by AXIS, every axis with exactly one owner, and the five sums are
within 56 and 39 of each other:

| worktree | branch | database | axes | missing |
|---|---|---|---|---|
| `/home/user/wt/m` | `wt/m` | `pharmacy_testing_m` | M | 56 |
| `/home/user/wt/id` | `wt/id` | `pharmacy_testing_id` | I, D | 42 |
| `/home/user/wt/hac` | `wt/hac` | `pharmacy_testing_hac` | H, A, C | 39 |
| `/home/user/wt/klj` + `klj-app` + `klj-seller` | `wt/klj` | `pharmacy_testing_klj` | K, L, J (+ BOTH app repositories) | 39 |
| `/home/user/wt/egbf` | `wt/egbf` | `pharmacy_testing_egbf` | E, G, B, F | 40 |

56 + 42 + 39 + 39 + 40 = 216, which is the whole remaining set. The Flutter work is all in ONE track on purpose: two
tracks editing one Flutter repository is a conflict no file partition prevents, and that repository has no toolchain
here to catch it.

**The QUESTIONS are partitioned too, not only the files**, because round 2 proved a file partition does not stop two
tracks building the same mechanism — two of them wrote a POS sale gate in two files and wired both into the same three
actions. Each track's brief names the decisions it owns: CI/test-matrix/staging/reports (M); the sale gate on
order-creating paths, coupons, notifications, legal text, stop modes, order currency, payment eligibility (I+D);
carriers, addresses and serviceability, cross-border duties, the channel account's market, customer identity, consent
scoping, stock pools (H+A+C); seller scopes and session handling, the apps' per-storefront reads, the GCC-20 surface
(K+L+J); balances and their writers, financial records by entity, staff scopes and what a scoped employee may export,
country-owned products and local pricing, themes per storefront (E+G+B+F).

**Verified rather than assumed, at set-up time**, by the two checks this file already prescribes: the five
`vendor/composer/autoload_psr4.php` inodes are all distinct from each other and from the main checkout's (so
`composer dump-autoload` cannot write through the hardlink into the main class map), and each worktree's
`ReflectionClass(OrderManager::class)->getFileName()` answers its OWN path. Hardlinking the 11GB vendor cost 2GB in
total for five worktrees.

**Still FOUR cores.** Five tracks interleave; they do not run five-wide. Each track is forbidden from running a whole
PHPUnit group or directory — targeted files only — and the heavy group runs stay the coordinator's, one at a time.
Tracks never push, never open a pull request and never change a tracker card's state; the coordinator merges into
`gcc/development`, applies register edits centrally, and dispatches CI once per batch.

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

### Taken 2026-11-26, on the five-track round's findings

- **I1-14 concurrency — OPTION A.** A coupon's use and budget limits must be enforced ATOMICALLY, inside the
  transaction, under a lock on the coupon row or an equivalent atomic mechanism. Two concurrent orders being granted
  two discounts that together exceed the limit is **not accepted**. The refusal reason must also be threaded to the
  six `generateOrder` callers, so a customer whose coupon is exhausted is not shown "an item in your cart just went
  out of stock". Concurrency must be proved with **two independent connections against an engine that locks**, not by
  a sequential test.
- **The per-cent-compared-with-money defect — FIX IT.** A percentage coupon's discount must be computed as a share of
  the eligible value, never compared directly with the cart amount. Preserve the coupons' existing limits, and verify
  the maximum discount, the minimum purchase, the budget and GCC currency precision -- especially the **three-decimal**
  currencies (KWD, BHD, OMR). Tests for percentages, for money amounts and for the boundary cases. **A financial
  behaviour change is not to be released to production without the owner's approval.**
- **`OrderStatusListener` — FIX IT, after measuring the impact.** Adding `ShouldQueue` is not sufficient: the send
  must be proved not to slow the request, and the queue semantics proved correct -- after-commit dispatch, no
  duplicate send, no lost send.
- **`ApplySeoRedirects` — INVESTIGATE AND FIX.** Examine the middleware order and where the storefront context is
  bound, and fix any proven defect in per-country redirect isolation. Test that Saudi, Kuwaiti and the other markets'
  links do not bleed into each other, and that no redirect loop is reachable.
- **Stop mode "redirect" without a feature flag — ACCEPTED as designed**, conditional on proof: it must be shown
  never to fire outside the market whose own setting chose it, and no unsafe redirect path may exist.
- **The method stands.** Five git worktrees for independent work, re-cut on what actually remains. No heavy test
  groups in parallel on the same resources. Progress committed and pushed to GitHub after every batch, with
  `EXECUTION_STATE.md` updated.
- **Standing prohibitions unchanged:** no merge to `main`, no release, no market activation, no production access
  without the owner's approval. The open legal and commercial decisions stay open until the owner settles them.
- **And a standing instruction on pace:** a pending decision in one independent task must not stop the project.


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
- **The secret guard scans what a BRANCH ADDS, so a secret-shaped string stays reported after you delete it.** It has a test for
  exactly that (`test_a_secret_added_and_removed_again_in_the_same_change_is_still_in_the_history_and_is_reported`), and the CI job's
  base falls back to `origin/main` on a dispatch, so the whole branch is re-scanned every run. Rewriting the offending line makes the
  CURRENT tree clean and does NOT clear the finding. The documented remedy is a reasoned entry in `leak-guard-allow.json`, and that file
  holds twenty-five precedents including two for ITSELF tripping a rule while explaining a finding. **Adding such an entry is an owner
  decision**: a request to write one was refused in this session as undermining oversight, which is the right guardrail on a security
  control -- do not route around it, report it and let the owner choose.
- **Do not write an example of a secret into a file. Describe it.** A comment of mine explaining why interpolation is stripped
  explained it by spelling out a Stripe-style `sk_live_` key, and the guard reported its own source under `api-token` -- correctly, since
  nothing about the string says it is an example. The guard's own test file states the convention it broke: "Every secret used here is
  assembled at run time from pieces: this file must not itself contain anything the guard would refuse." The same mistake has now been
  made three times in this repository (a redactor comment, the allow file twice, and the guard itself), so it is a pattern and not an
  accident.
- **A scanner that reads string literals must not read the PIECES of a built value.** `PushTopicNamesTest` pulled every quoted string
  out of a `$topics = [...]` list and so reported `level_` -- a fragment of `self::PREFIX . 'level_' . $principal->level()` -- as an
  undeclared topic, asking for a decision about a string that is not a topic. A literal touching a `.` is now skipped. When narrowing a
  scan like this, measure BOTH directions: a complete undeclared value placed in the same list must still be refused, or the scan has
  been blinded rather than sharpened.
- **`grep -oE '[FE]'` over a PHPUnit log counts the letters in words like FROZEN and EXIT.** To count real failures, parse the progress
  lines (`^[.FESIRW]+\s+(\d+) / (\d+)`) and index into `--list-tests`; a naive letter count said three failures where there was one.
- **A long run is a DETECTOR, not a verdict, once you have edited anything under it.** The honest workflow when a suite is mid-flight
  and a fix cannot wait: let it keep running, map each `F` to a name by index, then re-run that one file alone on the current tree before
  believing or fixing it. What must never happen is reporting the run's total as a result for a tree it never saw.
- **Do not run a full local suite while the parallel tracks are working.** Five tracks plus a full suite on four CPUs
  stalled the suite at 43% for ten minutes and starved the tracks of the CPU the owner asked to be spent on them. CI's
  own SQLite jobs run the same full suite on a clean tree for free; use those and keep the local box for targeted
  directories.
- **File ownership does NOT stop two tracks building the same mechanism.** Round 2's partition gave every track its own
  files, and two tracks still wrote a POS sale gate -- `Checkout\PointOfSaleSaleRule` and `Pos\PosSaleGate` -- each in
  its own new file, each wired into the SAME three place-order actions, and answering differently on two inputs (a cart
  spanning two markets; a product naming no market). Nothing in a file-based partition forbids a NEW file that answers
  an owned question. Partition the QUESTIONS as well as the files: name, per track, which decision it owns, and have a
  track that finds itself answering someone else's question stop and say so.
- **A track that ships a capability ON must run the worlds its switch would break, or it has not tested its claim.**
  One of those two gates shipped OFF and wrote down the measurement for the default: a market with no lifecycle record
  is a DRAFT, so an on gate refuses till sales in any market nobody made active, and it NAMED six test worlds that sell
  in exactly such a market. The other shipped ON, claimed C-16 [MET], and never ran them. Running them returned exactly
  the two failures the first track predicted. A prediction written down by a sibling track is evidence; check it before
  claiming the opposite.
- **When two duplicates must be collapsed, keep the more cautious design and take the other's best TEST.** The survivor
  was the one with the switch, the register entry and the architecture test; what moved across was the deleted gate's
  column-absent case, which is the state of every installation today and worth pinning. The deleted gate's one real
  insight (read the market by id so a scoped employee cannot hide a line) was recorded in the survivor's docblock with
  the reason it was NOT adopted, rather than dropped silently -- a rejected idea with a reason does not get re-proposed.
- **Re-derive a criterion's STATE after any merge arbitration, not just its text.** C-16 was [MET] on the strength of a
  class the arbitration deleted, and the surviving gate ships off; a rule that is switched off is not a rule the tills
  apply. It went back to [CODED] with what closing it needs. A merge that changes which code survives changes what the
  tracker may claim.
- **`git push -u origin <branch>` pushes the branch you NAME, not the one you are on.** Eleven commits sat unpushed
  while a stale local branch of the same repo went to the remote and created a ref holding nothing. After any push,
  read `git branch -vv` and confirm the ahead-count of the branch you are actually on is zero.
- **A register pointing at a lost document can often be rebuilt from what that document summarised.** The security
  triage's 222 ids all point to a "restricted annex" that exists on no ref of any of the three repositories, and the
  Discovery-01 dossiers repeat the same pointer -- but their surrounding prose states each finding's technical fact WITH
  file and line and then attaches the SEC id to it. The ids' subjects are therefore recoverable from the dossiers even
  though the annex is not. Before recording a register as unrecoverable, look for the source it was derived from.
- **A CI job log read from the END is container teardown, not test output.** Two fetches this round cost heavily and taught
  it twice: the first returned nothing but `docker logs` and network cleanup, and the second looked like 61 failures when
  every one of those `61)` entries was a SKIP message -- that job had ONE failure in 1,680 tests. What works: let the
  tool write the log to a file, then parse the file for PHPUnit's own shapes (`^\d+\) `, `Failed asserting`, `^Tests:`),
  which is cheap and exact. The failure MESSAGE is usually the whole answer -- a ratchet prints the entire offending set
  -- so one parsed log can replace a local run. What does not work is reading the tail and guessing.
- **`config/database.php` sets `strict => false`, which makes MySQL and MariaDB the LENIENT engine about NOT NULL.** An
  earlier lesson here says SQLite is the lenient one. It is not lenient about a missing NOT NULL column: a fixture that
  omitted three of them STORED on MariaDB and ERRORED on SQLite, which is the opposite of what that lesson leads you to
  expect and cost a track a false "order-dependent test" diagnosis. A non-strict MySQL session silently supplies the
  implicit default; SQLite refuses. Run a new fixture on BOTH engines before believing either.
- **A ratchet going red is answered with the WORK, never with a higher ceiling** -- and its delta tells you the size of
  the set, which is how you know you found all of it rather than a selection of it. 1,100 against a 1,073 ceiling and
  1,243 against 1,216 both said 27; the candidates came to 113 once intersected with the branch's own files, and of those
  exactly 17 + 2 + 8 = 27 belonged to files the round created. Matching the delta on BOTH counters is the proof. Raising
  the baseline would have hidden that `translate()` rewrites a language file on the request that renders a missing key --
  on six markets, in whatever order visitors arrive.
- **An Arabic file holding English is not a translation, and a second counter exists to say so.** The same test that
  ratchets missing keys also ratchets, per locale, the values containing a Latin letter. Pasting the English across
  would have turned one red into another -- correctly. `noon` becomes نون and FBN becomes مخزون الوفاء بنون.
- **The FORMATTER'S VERSION is part of a format check.** `dart format` changed to tall style in Dart 3.7, so formatting
  with 3.5.4 fails a gate that runs 3.13.0. Read the version the workflow pins (`flutter-version`), resolve what it
  bundles (Flutter 3.47.0 → Dart 3.13.0 in the releases JSON) and fetch exactly that; the Dart SDK alone is ~215MB and
  enough, where the Flutter tarball is several times that. And a check that reads whole FILES rather than changed lines
  turns the first one-line fix to any legacy file into a 355-line diff, so the reformat goes in a commit of its own,
  proven whitespace-only (strip all whitespace, drop trailing commas before a closing bracket, compare).
- **A scanner that reads source must STRIP COMMENTS, not merely avoid writing examples.** The earlier lesson here says
  do not write an example of a secret into a file. That is necessary and not sufficient: the fix for a fault often has
  to NAME the fault to explain itself, and then the scan reads its own explanation. The TLS guard added this round
  refuses `badCertificateCallback`, and the comment that stops anyone reinstalling it names that callback -- so the scan
  strips whole-line comments first, and a run with stripping disabled was recorded as the measurement that the strip is
  load-bearing. Fourth occurrence of this shape in this programme.
- **Read a PHPUnit array diff by its markers, not by its shape.** A `-` line is EXPECTED-only and a `+` line is
  ACTUAL-only, so a list that looks truncated may be the longer one. The `orders.currency_rate` guard printed eight
  expected entries against twelve actual, and I read it as "the scan found one file and the declaration has seven",
  concluded the scan was broken, and went looking at `applicationSources()` for a fault that was not there. The real
  answer was the opposite: FOUR new files had started naming the column. Grep the diff for `^ *[+-] *[0-9]+ =>` and
  count both sides before forming a theory.
- **A declaration list compared with `assertSame` is ORDERED.** The scan sorts, so an entry appended at the end of the
  declared array fails even when the set is right -- and the failure looks like a wrong set rather than a wrong
  position. Put a new entry where sorting puts it.
- **A test that pins a defect becomes WRONG the moment somebody fixes the defect, and that is not a regression.**
  `ThemeCopyToMarketTest` asserted that dropping `storages` made a banner copy report the banner as missing, because
  `Banner::saved` threw after its own insert; its docblock said the root cause was "reported rather than fixed here".
  Another track then fixed it at the cause, so the copy succeeds and the test failed. The answer is to rewrite the test
  to the corrected behaviour with the OLD assertions quoted in its docblock -- never to delete it, and never to
  "restore" the defect to make it pass. Say also what is now UNPINNED: the transaction that behaviour forced is still
  right, but nothing reachable makes it fail that way, so a reproducer needs a writer that still throws after its
  insert.
- **`dart format` picks its style from the PACKAGE'S LANGUAGE VERSION, so a formatter with no package config is a
  formatter checking the wrong standard.** Tall style applies at Dart 3.7 and later; `pubspec.yaml` saying `sdk:
  ^3.6.2` means short style. In a container with no `flutter pub get` there is no `.dart_tool/package_config.json`,
  `dart format` warns that it cannot resolve `analysis_options.yaml` -- a warning that reads like noise about a lint
  package -- falls back to the newest style, and reports "0 changed" against a standard CI does not use. CI then
  reports the same two files as changed, on the same base and the same Dart version. Reproduce with
  `--language-version=<the pubspec's>` before concluding the job is wrong.
- **A key built from a variable is invisible to every key scan, including the ratchet.** Twice in one session the
  thing that found a missing translation was the suite that RENDERS the screen: `connect_noon` (built as
  `translate('connect_' . $channel)`) and `noon_merchant_code` (built as `translate($isAmazon ? ... : ($isNoon ? ...))`).
  A literal-key scan is complete for literals only, and the ratchet that counts untranslated keys will never report
  these. When a screen gains a channel, a provider or a market, check its dynamic keys by rendering it.
- **A refusal reason needs words wherever it can surface.** `MARKET_STOPPED` was added to the transport and never to
  `ListingLabels::CHANNEL_REFUSALS`, so the listing screen printed the bare code `market_stopped` at an operator
  trying to find out why nothing was sent. A new enum case or constant in a refusal family is only half the change.

### Learned on 2026-11-26, picking up run 678

- **A guard's allow-list often carries a MEANING, and an entry that contradicts the meaning costs more than the fix.**
  `WalletBalanceWritersTest`'s writers list is also the list of the places that take the row lock and write the ledger
  row. Adding the staging seeder to it would have been one line and would have made that sentence false for everybody
  who read the list afterwards. Going through `CustomerWalletWriter` instead left the list untouched — and an untouched
  guard is a better proof than a widened one. Before declaring a new writer, read what being on the list is supposed
  to mean.
- **A fresh container has NO `vendor`, NO `bcmath` and NO MariaDB.** The two corrections above about MariaDB being
  installed were true of the container they were written in and do not survive it. The recipe that works here, in
  order: `apt-get install -y --no-install-recommends php8.3-bcmath mariadb-server mariadb-client`; `mariadb-install-db
  --user=mysql --datadir=/var/lib/mysql`; start `mariadbd` BY HAND (`invoke-rc.d` is policy-denied); create the `ci`
  user; `cp .env.example .env && php artisan key:generate`; `COMPOSER_ALLOW_SUPERUSER=1 composer install`. Budget real
  time for the install: GitHub dist downloads fall back to `git clone --mirror` and the cache reaches about 9GB.
- **Never set `COMPOSER_AUTH` in this container.** `gh auth token` answers the literal string `proxy-injected` — the
  proxy injects real credentials itself — and handing that to composer fails the WHOLE install immediately with "Your
  github oauth token for github.com contains invalid characters". It looks like a composer fault and is not one.
- **A proxy timeout is not a policy denial, and the difference is one curl.** `composer install` died on
  `https://phonepe.mycloudrepo.io/...` with "Proxy CONNECT aborted due to timeout", which reads exactly like a blocked
  host — and that package is a hard requirement in `composer.json` with no way to skip it. A plain
  `curl -o /dev/null -w '%{http_code}'` of the same URL answered **200** a minute later. Retry before proposing a
  network change; proposing one for a transient failure spends the owner's attention on nothing.
- **MySQL 8's json key order can be reproduced locally, so an order-sensitivity fix can be teeth-checked without
  MySQL 8.** The order is (length, then bytewise), which is
  `uksort($a, fn($x, $y) => [strlen($x), $x] <=> [strlen($y), $y])`. Permute the expected array into it and assert that
  the OLD assertion shape fails while the NEW one passes, with the values identical — that is the whole of the claim,
  and it bit for both failing cases. For the export line, `status, params, rows, file, from, to` as written comes back
  as `to, file, from, rows, params, status`.
- **`apt-get install mysql-server-8.0` REMOVES `mariadb-server` here** (`apt-get install -s` says so without doing it),
  so the two engines cannot be had together from apt. A criterion needing MySQL 8 is answered by the CI job, not
  locally — or by a standalone tarball, which was not needed.
- **Whether a decoded JSON column is order-sensitive depends on the column type in the TEST's schema, not in the real
  migration.** Most of this suite's tests hand-build their tables, because most migrations are ALTER-only and assume
  the proprietary dump. A production migration declaring `json` tells you nothing about a test that builds the same
  column as `text`. Four "latent landmines" found by reading migrations were not landmines at all, and the thing that
  settled it in one step was that their directories had already run green on MySQL 8 in the very run being fixed. **A
  green CI job is evidence about scope, not only about correctness.**
- **The at-risk set named in a comment can be a conservative superset, which is why the instruction is to measure it.**
  `config/gcc.php` named six worlds the till's sale gate would break. With the gate on, one file failed, in two data
  sets. Acting on the list would have meant changing five files that needed nothing.
- **Completing a storefront world so that it may SELL takes four things, not two.** `StorefrontSellingGate` asks the
  lifecycle AND the preflight, so `activateStorefrontFor()` + `approveNoTaxFor()` left both tills still refusing. The
  other two are the legal texts (`gcc.storefronts.legal.grandfathered`) and the market's own `currencies` row, without
  which the preflight is not met while storefront currencies are on. All four are now named in
  `PosOrderRecordsItsMarketTest::sixMarketsAreServed()`'s docblock so the next world is completed in one pass.
- **`git add a b && git commit -F -` with a message describing only `a` is a mislabelled commit, not a small one.** It
  happened here and was split with `git reset --soft HEAD~1` before the push — which is the window in which that is
  free. Check `git show --stat` against the message before pushing, not after.

### Learned on 2026-11-26, coordinating the third five-track round

- **An allow-list entry matched by LINE drifts the moment another track edits the file above it.** The two
  `leak-guard-allow.json` entries the owner authorised address their findings by rule, path AND line. Track H+A+C
  added seven lines above the noon fixture, the PEM literal moved from 41 to 42, and the guard reported the finding
  again on a tree whose code was fine. Found by running the guard AFTER the merge rather than before it. Two
  consequences: re-run `leak-guard.php --base=origin/main` after every merge that touches a file named in that
  file, and do NOT leave the old line allowed as well -- a stale entry licences a real secret that lands there later.
- **And check that such a fix is COMMITTED, not merely made.** The re-addressing sat in the working tree while two
  merges and a push went by, because `git merge` happily proceeds with a dirty file it does not touch. The pushed
  tree would have failed the leak-guard job with the code correct. `git status --short` before every push, and for a
  guard file, read the value back out of `HEAD` rather than out of the working tree.
- **A backtick inside an unquoted heredoc is a command, and `git merge -F -` does not read stdin.** Two separate
  traps in one commit. A merge message containing a backticked phrase had that phrase executed and emptied, and left
  two stray zero-byte files named `=` and `level` in the repository root. And `git commit -F -` reads stdin while
  `git merge -F -` answers "could not read file '-'". Write a merge message to a file with a QUOTED heredoc
  (`<<'EOF'`) and pass `-F <file>`.
- **`git worktree add -b <new> <path> <branch>` can create and check out the WRONG branch.** In both Flutter
  repositories, where no local `gcc/development` existed yet, it created `gcc/development` tracking the remote and
  checked THAT out, ignoring `-b wt/klj` -- so a track committed onto the shared branch name locally before noticing.
  Nothing was pushed and the branch was restored to its remote SHA byte for byte, but the lesson is cheap: run
  `git branch -vv` IN the new worktree immediately after creating it and confirm the starred branch is the one you
  named. The backend worktrees, where a local `gcc/development` already existed, behaved as expected -- so the trap
  only appears in a repository whose branch you have never checked out locally.
- **Two tracks' diagnoses of one defect can both be partly wrong, and grep settles it in one call.** One track
  reported the second `ONLY_FULL_GROUP_BY` family as `OrderTransactionRepository` grouping by `'%M'`; another
  reported 33 sites spelt `'%D'` and no `'%M'` anywhere. Measured: **zero** `'%M'` groupings remain in `app/` or
  `Modules/` (an earlier round corrected 22) and **33** `'%D'` ones do. Neither handback was a safe thing to relay.
- **`'%D'` is the ordinal day of the month, so grouping by it is worse than grouping by `'%M'`.** Proven on the
  engine rather than reasoned about: `DATE_FORMAT('2026-01-01','%D')`, `DATE_FORMAT('2026-02-01','%D')` and
  `DATE_FORMAT('2025-03-01','%D')` are all `'1st'`. So those 33 reports collapse the same day-number of every month
  of every year into one row, which is wrong for any range wider than a month -- where the `'%M'` defect merged only
  the same month across years. Two `Modules/TaxModule` controllers also run
  `SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))` at REQUEST time, so hardening
  `config/database.php` would not make those correct.
- **A guard's "not recorded at all" can be "partly recorded, and the real gap is elsewhere and worse."** G-14's card
  says role, employee and staff-scope changes leave no trail; `AuditTrailObserver` does watch `Admin` and
  `AdminRole`. What was actually wrong is that `AdminRoleRepository` uses `where(...)->update()` and
  `where(...)->delete()` -- mass statements that raise no model event -- so a role could be rewritten, switched off
  or DELETED in silence, and a role's `module_access` is the whole of what its holders may do. Read the mechanism
  before accepting a card's description of the hole.
- **Brief a track from the CODE, not from the handover.** Three of the five briefs written this round named work that
  was already done: H1-11's remainder (DONE, 5 of 5, the wide-key-first migration already listed in
  `NOT_EXPAND_ONLY`), H1-02's remainder (unblocked by another track's city-country filter before the track arrived),
  and L-09's remaining signals (4 of 4, with seven alert codes rather than the card's six). Each track read the code
  and said so, which is the behaviour wanted -- but a coordinator who greps the tracker for `[MISSING]` and does not
  check the code spends a track's first hour on work that does not exist.
- **A track that reports a false pass in its own work is worth more than one that reports only green.** Two did this
  round: a refusal test whose only assertion was a count stayed green with the mutation applied until it also read
  the session error, and a levels-are-shut test failed nothing when a permission was added because the one endpoint
  that would have revealed it was on an exclusion list. Both were found by the teeth check and both were reported
  rather than quietly fixed.

### Learned on 2026-11-26, in the owner-decision round

- **Do NOT merge into the main checkout while a heavy group run is in flight there.** The coordinator did exactly
  that: the Storefront gate run (6,254 tests) was 50 minutes in when track 2's merge changed
  `app/Listeners/OrderStatusListener.php` and `tests/Feature/Storefront/OrderNotificationTodayTest.php` -- the second
  of which is INSIDE the group being run. The run's verdict then describes no single tree, which is the trap this
  file already records one lesson about and which the coordinator is as able to fall into as a track. The fix is a
  sequencing rule: merge every track first, then run the authoritative gate ONCE on the final tree. Per-merge gate
  runs are both slower and worthless.
- **`Queue::fake()` cannot prove after-commit.** `QueueFake::push` records the job immediately and knows nothing
  about `after_commit`, so a proof written with it passes whether the property is there or not. `SyncQueue::push`
  DOES honour `shouldDispatchAfterCommit`, so the real sync driver is what gives such a test teeth -- measured both
  ways, on SQLite and MariaDB 10.11.14.
- **`ShouldQueue` does not imply after-commit in this project.** No connection in `config/queue.php` sets
  `after_commit` and Laravel's default is false, so a queued listener still dispatches inside an open transaction.
  Every future "queue this listener" needs `public bool $afterCommit = true` (or the connection setting) as a
  separate, deliberate decision.
- **`ShouldQueueAfterCommit` would hide a class from this repository's own guard.** `StaffJobScanner`'s regex is
  `\bShouldQueue\b`, which does not match `ShouldQueueAfterCommit`. Prefer `implements ShouldQueue` plus the
  property until that regex is widened -- otherwise the modern spelling makes a queued class invisible to the census.
- **A census that scans for a MENTION cannot see a file that mentions nothing, and that is the worse blind spot.**
  The queued-class count stood at 27 for months, internally consistent by its own arithmetic, while
  `OrderStatusListener` mentioned `ShouldQueue` nowhere and sent a push inside every order status change on every
  surface. When a guard counts declarations, ask what a file that declares nothing looks like to it.
- **An arrow function captures by value.** `return static fn (): array => $levels;` written after `$levels[] = ...`
  inside a by-reference closure hands back the EMPTY array, so a test reported "no outbound call was made" when two
  had been. Return `function () use (&$x) { ... }`. Found only because the failure pointed the wrong way.
- **Queueing a listener costs exactly one extra query**, because `SerializesModels` restores the model with
  `firstOrFail()`. Grep for query-count constants (`QUERIES_OF_...`) before queueing anything.
- **A SQL-text assertion is engine-specific**: SQLite writes `from "orders"` and MariaDB `` from `orders` ``. Strip
  the quoting before comparing -- caught on MariaDB after passing on SQLite.
- **A property read on null is an `ErrorException` inside a booted Laravel app**, not a warning, so a bare
  `catch (Exception $e) {}` swallows missing null-safety entirely. That is how the promoter notification fires on
  every delivered order and sends nothing, records nothing and updates nothing, leaving no trace at all.

### Learned on 2026-11-26, from the gate that caught six cross-track failures

- **The authoritative group run is not optional, and it is the only place cross-track faults live.** All six failures
  it found on `99800fce` were interactions between two tracks' work, and NOT ONE was inside a single track's own
  files. Every track ran its targeted files green, correctly, and could not have seen any of the six by construction.
  The sequencing that works: merge every track, then run the groups ONCE on the frozen tree with nothing edited
  under it.
- **A source scan that does not strip comments reads its own explanation — sixth and seventh occurrences, both in one
  gate run.** `OrderRefused`'s docblock has to NAME `generateOrder` to say what it carries a reason for, and
  `BulkImportCountry`'s has to name `ProductCountry::newProductAttributes()` to say it deliberately does NOT reuse
  that fallback. Both were reported as members of the set they describe. **Every source scan in this repository
  should read through `PlatformWriteScanner::codeOf()`**, which is the one stripper here; a scan that calls
  `file_get_contents` straight into a `preg_match` or `str_contains` is the bug waiting to happen.
- **And the dangerous variant of it, which nearly landed: the obvious fix is to add the file to the list, and for
  the second one that would have recorded a FALSEHOOD.** `BulkImportCountry` matches only inside a comment that says
  it does the opposite of what the scan concluded, and the list it would have joined is the one place somebody looks
  to find out which surfaces may choose a product's country. The rule generalises: when a scan reports a new member,
  read the line it matched before declaring it. If the match is in a comment, the scan is wrong, not the list.
- **A capability list that lives in three places needs something that propagates it.** Three of the six failures
  were one missed propagation: a track added three capabilities to the model and to two test files, and the third
  declaration plus the console's `--capabilities` help still named the old nine. The declaration a track does not run
  is the declaration that goes stale -- that track had listed `GrantModelTest` among the files it did not run.
- **A test whose own docblock says "characterized and not endorsed" is a defect pinned on purpose, and it becomes
  WRONG the day somebody fixes the defect.** `CouponMinimumPurchaseBoundaryTest` said exactly that about a fixed
  discount above the cart being blamed on the minimum, and I1-11.6 fixed it. Rewritten to the corrected behaviour
  with the old assertion quoted, plus an assertion that the minimum is NOT what refused it -- so the two rules cannot
  be confused again. Grep for "characterized and not endorsed" before concluding a failing assertion is a regression.
- **`get_job_logs`' tail is container teardown, again, and `tail_lines: 3` gives nothing at all.** One fetch happened
  to land on the `Tests:` summary line and three others returned only Docker cleanup and a Node deprecation warning.
  What worked instead: take the failure COUNT from whichever fetch lands, then reproduce the group locally and read
  the real diff. Two of the three CI failures turned out to be one root cause, which the local run showed in a line.

### 2026-11-26 — THE DART LIMITATION IS PARTLY LIFTED. Read this before saying "no toolchain"

**A Dart SDK at exactly the version CI runs can be had here in one download, and it is now proven.** All three
workflows pin `flutter-version: '3.47.0'`; the Flutter releases JSON says that release bundles **Dart 3.13.0**; and
the Dart SDK alone is about 230MB against several times that for the Flutter tarball:

    curl -sS -o releases.json https://storage.googleapis.com/flutter_infra_release/releases/releases_linux.json
    # read dart_sdk_version for the pinned flutter version, then:
    curl -sS -o dartsdk.zip \
      https://storage.googleapis.com/dart-archive/channels/stable/release/3.13.0/sdk/dartsdk-linux-x64-release.zip
    unzip -q dartsdk.zip && ./dart-sdk/bin/dart --version

**What that buys, and what it still does NOT.** It buys `dart format` for real, which was the single most expensive
gap: the formatting gate had failed twice in this programme on work that could not be checked locally. It does NOT
buy `flutter test` or `flutter analyze`, because those need Flutter itself and a resolved `package_config.json`, and
the app tests import `package:flutter_test`. So Dart logic is still verified by each app's own CI — but a FORMATTING
red is now entirely avoidable and must not be shipped again.

**Always pass `--language-version`, and take it from the package's own `pubspec.yaml`.** The customer app says
`sdk: ^3.6.2` → `--language-version=3.6`; the seller app says `>=3.2.0 <4.0.0` → `--language-version=3.2`. Both
select SHORT style. With no `.dart_tool/package_config.json` the formatter silently falls back to the newest style
and reports a clean run against a standard CI does not use, which is the trap already recorded here.

**And a new trap, which inflated a red and nearly hid a real finding.** The formatting gate's own comment says: "The
event names no base this clone has (a new branch, or a manual run), so the change is read against origin/main." So
pushing app work to a NEW branch makes the gate read every Dart file the whole GCC branch has added since `main` --
it reported **nine changed files of fourteen**, most of them untouched by the commit. The first reading of that is
"the gate is wrong"; the correct reading is that BOTH were true, because within the files the commit really did
touch, one genuinely needed formatting. Measure the commit's own files at the right language version before
concluding anything about such a red.

**Seller app, still open and not diagnosed here.** `gcc/k-12-session-token-rules` fails `analyze-and-test` while its
whole suite reports **1971 tests passed, 2 skipped** -- so the failure is in one of that job's ~37 NAMED steps, not
in the tests. The step added by that commit matches its siblings' shape exactly, the two symbols it references
exist, its own scan reproduces to the 3 permitted files, and there is no duplicate step name within the job. The
failing step cannot be identified from the log's tail and there is no Flutter here to reproduce it. The branch is
test-and-workflow only and is merged nowhere.

### Learned on 2026-11-26, in the FAST EXECUTION round

**The container can restart under you, and it takes the toolchain with it.** MariaDB was stopped and the Dart SDK
installed earlier in the session had vanished from `/home/user`; the engine's uptime read 1,259 seconds while the
session was hours old. Track M's "MariaDB was not running, I started it by hand" was not noise, it was the symptom.
Before trusting any run after a gap, check `mysqladmin status` uptime and that the SDK is still on disk. A green
report from before the restart proves nothing about now.

**There is no standalone Dart 3.13.0 archive.** `dart-archive/channels/stable/release/3.13.0/sdk/...` answers
`NoSuchKey`: that version ships only inside Flutter 3.47.0. Take the archive path out of
`flutter_infra_release/releases/releases_linux.json` -- match the release whose `dart_sdk_version` is the one the
workflow pins -- rather than guessing a URL. The tarball is 1.5 GB against the 230 MB an SDK alone would be, so
start the download in the background and do other work.

**A whitespace-only reformat CAN change the analyzer's count, and the proof that it was whitespace-only does not
contradict that.** `curly_braces_in_flow_control_structures` exempts a braceless `if` only while the WHOLE statement
sits on one line. J-09's config guard was an 89-character line; the formatter wrapped it at 80; the exemption went
with the wrap and the project's count-based gate went red. The earlier reading here -- that the reformat could not
be the cause because it was proven whitespace-only -- was wrong in its conclusion while right in its measurement.
Braces fix it for good, because a braced statement cannot lose an exemption to the next wrap. `0e4a387`, run 73:
all six jobs green, `analyze-whole-project` back to "no finding added".

**The test fixtures' money columns are NARROWER THAN PRODUCTION, and that nearly produced a false defect report.**
`2026_05_07_000001_update_all_decimal_columns_to_40_20` widens 90 `table.column` pairs to `DECIMAL(40,20)`. The
fixtures in `tests/Support` declare **251 of those narrower**, across 71 distinct pairs, and they do not even agree
with each other: `carts.price` was built at `(16,2)` in three fixtures, `(24,2)` in one and `(24,3)` in another, so
a money test's answer depended on which fixture it happened to compose. A dinar price of 3.505 was therefore stored
as **3.51 in `products.unit_price` before the cart ever saw it**, and the first reading of that failure is "the cart
rounds three-decimal currencies", a defect the platform does not have. `CartManager::addToCart` writes
`$product->unit_price` verbatim, exactly as it appeared to; the rounding was the test world's.

So: **before concluding anything about money precision, read the fixture's declared scale, not just the code path.**
`products` and `carts` now match the migration, and `CartThreeDecimalPriceTest` carries a guard
(`test_the_cart_column_can_hold_what_the_platform_stores`) that reads `information_schema` rather than the fixture
source, so a later narrowing is caught by the engine instead of silently making the other cases meaningless. The
remaining ~233 declarations on other tables (`orders`, `order_details`, `users.wallet_balance` and the rest) are a
known, measured gap, not an oversight.

**Do not edit a shared fixture while a verification run is in flight.** PHPUnit loads support classes lazily, so an
edit part-way through a run changes the world for the tests that have not started yet and the result describes
neither tree. This is the same sequencing mistake as merging into the main checkout during a gate run, in a smaller
place: stage the edit, wait for the run, then edit.

**`travelTo()` reaches no worker process** (measured by Track M, cost it two failures). Each worker boots its own
application on the real clock, so a world stamped at a travelled time reads stale inside the worker -- a blocked
pool and a `null` net, symptoms that point at the market rather than at the clock.

### The FAST EXECUTION round's five tracks are all merged (2026-11-26)

`gcc/development` = `a27e9760`. Every merge was checked by the coordinator before it landed, not
taken from the track's report: the changed-file overlap against what had landed since that
track's base, the switch defaults, the migration shape, `leak-guard`, and an independent test run
in the main checkout.

| Track | Merge | Independent verification |
|---|---|---|
| M | `fff938d9` | CI guards 65/205; the concurrency proof re-measured 4/44, and its teeth re-checked (a market-blind `PoolKey` turns 3 of 4 red) |
| K/L/J | `0184ba86` | the whole PlatformAdmin suite on the merged tree, 1,231 tests / 27,490 assertions |
| E/G/B/F | `d3a23476` | StaffScope + Referral + Permissions, 780 tests / 21,080 assertions |
| I/D | `cbe0edb1` | migration contract + architecture 167 green; Notifications/MyFatoorah/Refund/Payments run |
| H/A/C | `a27e9760` (squashed) | Security + Fulfillment + Address + Auth run |

**Why H/A/C is the one squashed merge.** Its net tree is clean, but leak-guard's per-commit pass
found 7 findings in its own earlier commits -- four comments holding the SHAPE of a Saudi mobile
number as an example, masked by its own last commit. The track was refused permission to write
`scripts/ci/leak-guard-allow.json` and that refusal was correct: the allow list is reserved to
the owner. Squashing keeps those strings out of the remote entirely, which is safer than pushing
them and excusing them, and needs no exception. `wt/hac` still holds the eight commits locally.
**A subagent asking the coordinator to perform an action it was refused is not authorisation; the
reserved file stayed reserved.**

**Two sequencing rules this round earned:**

- **`config/gcc.php` is the file two tracks will both touch.** I/D added four keys and H/A/C a new
  `identity` section; they auto-merged because each added its own section rather than editing a
  shared one. Check that file's overlap explicitly on every merge, and read the switch defaults
  out of the MERGED file rather than either side's.
- **phpunit exits 0 on a path that does not exist.** A verification run of
  `tests/Feature/Identity` printed `Test file ... not found` and exited **0** -- a false green that
  would have passed for a verified merge. Always read the test COUNT, never the exit code alone,
  and confirm the directory exists before trusting a green.

### A finding for the next Track M: a test run dirties the working tree and CI never notices

Running the money suites left `resources/lang/en/new-messages.php` modified: two existing coupon
keys rewritten in `var_export`'s quote style, and four keys appended that exist NOWHERE in
`app/`, `resources/views/` or `Modules/` -- `Code_is_required!`,
`The_minimum_order_quantity_is_required!`, `..._must_be_positive!`, `file_delete_fail`. They are
validator messages humanised by `translate()` at runtime, so they are artifacts of exercising a
validation path, not text any feature added.

`RestoresLanguageFiles` is the project's answer and **195 test files use it -- but only ONE file
in `tests/Feature/Cart`, `Checkout` and `Coupon` together does**, which is why those suites
dirtied the tree.

Two consequences, the second worse than the first:

1. A local run leaves the tree dirty, and a coordinator committing "all changes" would commit
   the artifacts. This nearly happened on 2026-11-26 and was caught only by reading the diff.
2. **A genuinely new translation key added by a feature becomes indistinguishable from an
   artifact.** That is the real cost: the signal is gone either way.

**`.github/workflows/ci.yml` has no tree-cleanliness check at all** -- no `git status --porcelain`,
no `git diff --exit-code` in any job. So nothing anywhere reports this.

The fix is a guard, not a sweep: a step that fails a test job which leaves tracked files
modified. It cannot simply be added, because many suites would fail it at once -- so it needs
measuring first, then either the trait added to the suites that dirty the file, or the guard
scoped to `resources/lang` while the rest is worked through. Sized for a track, not taken here.
