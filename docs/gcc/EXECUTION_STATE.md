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
| `fullstackfull/lavkoo` | `gcc/development` | see `git log -1` | up to `dabad3b1`; everything after it waits on a green run of the branch tip |
| `fullstackfull/lavkooapp` | `gcc/development` | `fb4ebca` | not yet |
| `fullstackfull/lavkooappseller` | `claude/intelligent-franklin-jq3d4h` | — | its GCC work is already on main |

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
- **Q-13** — who grants and maintains product destination permissions. The table and the deny-by-default rule are in;
  `recorded_by` and `recorded_at` are there so whoever it is leaves a trace.
- **I2-09 (remainder)** — what happens to marketplace orders of a paused country.
- **A1-17 (remainder)** — nothing: the topic decision is taken, above.

## Next, in dependency order

1. **H1-02 remainder** — `provider_city_mappings.country_code` written and candidates filtered by it.
2. **H1-03 remainder** — the admin screen that grants a permission, audited.
3. **H1-06 remainder** — the dispatch side re-asking the engine for the product permission (the route half already
   holds there).
4. **H1-04** then **H1-09**, then the rest of axis H.
5. Then the axes with the most missing, by dependency rather than by ease: L (GCC-20 super admin), K and J (the two
   apps), B, F, G.

## Rules this work follows, learned the hard way

- Never write to or delete a path before reading what is there and `git log --oneline -- <path>`.
- Every new switch ships off, and the first test asserts that with it off nothing changed.
- A guard that fails open is reported, not relaxed; a guard that asks to be recorded is recorded.
- A test that proves a problem exists is not a fix for it.
- Teeth-check every feature: break it deliberately, see the test fail, restore.
