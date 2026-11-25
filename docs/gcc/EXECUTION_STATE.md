# LAVKOO — execution state

The one file to read to resume. It says what was finished last, what is next, what is waiting on the owner, and where
the work is. It is updated as part of each batch, not as a separate exercise.

**Standing authority (owner, this session).** Continuous independent development on `gcc/development`, one branch per
repository, without asking for approval per feature or per technical decision. Forbidden without separate approval:
production deploy, production data, real balance migration, activating a country before G0 closes, weakening a security
or financial control. A pending owner decision stops its own task, never the project.

---

## Where the work is

| repository | branch | tip | merged to main |
|---|---|---|---|
| `fullstackfull/lavkoo` | `gcc/development` | see `git log -1` | up to `ca4f68e0`, whose tree is byte-identical to `bc39790` -- the SHA CI run 655 proved green. Everything after it waits on a green run of its own |
| `fullstackfull/lavkooapp` | `gcc/development` | see `git log -1` | up to `45ebfad`, whose tree is byte-identical to `0d91632` -- the SHA CI run 52 proved green (the customs-duty line at checkout) |
| `fullstackfull/lavkooappseller` | `gcc/development` | see `git log -1` | up to `5fc4411`, whose tree is byte-identical to `596ed26` -- the SHA CI run 52 proved green |

The customer app's CI has no `workflow_dispatch`: it runs on a push to `gcc/**`, so every push to that branch is its own
run and no dispatch is needed. The backend's runs ONLY on dispatch for a branch, so a push there has to be followed by
one.

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

1. **H1-11** — carrier adapters open to other countries (4 missing), the largest remaining H1 gap. **H1-14** — local
   shipping rules belong to a storefront and are never inherited (3 missing).
2. **H1-02 remainder** — filtering candidate cities by the country of OUR city. Blocked on H1-10 / the address system
   going multi-country; recorded as blocked rather than faked.
3. **H2-06, H2-07 and H2-10** are the largest remaining gaps in H2 (the marketplace integrations).
4. Then the axes with the most missing, by dependency rather than by ease: L (GCC-20 super admin), K and J (the two
   apps), B, F, G.

## Rules this work follows, learned the hard way

- Never write to or delete a path before reading what is there and `git log --oneline -- <path>`.
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
- Where a record cannot name the rule that produced a figure, it says nothing. `shipping_rate_source` is null for a shop
  in that state rather than guessing `product_wise`, because the type written on those lines is an empty string too.
