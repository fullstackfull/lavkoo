# M-656 / M-745 — the stock pool's unique key, and the expand-only contract

**Status: OPEN. Reserved for the owner. Nothing in this document has been applied.**

Written for a decision, not as a record of one. The guard was not bypassed, `NOT_EXPAND_ONLY` was not
edited, and the migration was not changed. CI stays red on two jobs until this is answered — see §7.

---

## 1. What CI says

CI run **678** (<https://github.com/fullstackfull/lavkoo/actions/runs/45744979283>), both SQLite jobs
(`Tests, SQLite (PHP 8.2)` and `Tests, SQLite (PHP 8.3)`):

```
Tests\Unit\Ci\GccMigrationsContractTest::test_no_migration_of_this_programme_drops_renames_or_changes_on_the_way_up
2027_01_07_100000_add_market_to_inventory_pools.php: dropUnique()
Add beside, never in place of; or list the file in NOT_EXPAND_ONLY with the decision that allowed it.
```

One finding, one file, one statement. It is not a test that is wrong: the file does call `dropUnique()`
on the way up, and the guard is reporting exactly that.

## 2. What the migration does, and why it was written that way

`database/migrations/2027_01_07_100000_add_market_to_inventory_pools.php` makes the market part of a
stock pool's identity (M-656, M-745). A pool is what a sales channel may be offered of one physical
stock, and its identity was `(location_id, product_id, variant_key)` — naming no country, so two
countries' accounts publishing one SKU shared one pool row and could each be offered the whole stock.
That row is the row the single writer takes `FOR UPDATE`, so it is also the whole of the
one-division-at-a-time guarantee.

`up()` does three things, each guarded so it re-runs safely:

1. adds `storefront_country CHAR(2) ascii_bin NOT NULL DEFAULT ''` — so every pool that exists today
   stays exactly the pool it was, and reads as the default storefront's;
2. adds the wide unique key `(storefront_country, location_id, product_id, variant_key)`;
3. **drops** the narrow one `(location_id, product_id, variant_key)` — this is the reported statement.

The column is `''` and not `NULL` for a load-bearing reason: on MySQL and MariaDB two rows whose unique
key contains `NULL` do not collide, so a nullable market would permit a second pool row for one shelf
and product and the `FOR UPDATE` would no longer serialise anything. `''` collides with `''`.

Step 3 cannot simply be dropped from the plan: while the narrow key is in place, a second market's pool
of a shelf and product the two markets share is **refused**, which is the thing the migration exists to
allow. So the only question is *when* it is dropped, not *whether*.

## 3. Why the contract exists

`GccMigrationsContractTest` enforces expand-only on the way up, and its own failure message states the
harm it is preventing: *"the code that ran before the deploy stops working the moment they run"*. A
migration runs while the previous version of the application is still serving requests; anything it
takes away is taken away from code that is still using it.

## 4. The measurement — does that harm occur here?

**No, and this was measured rather than argued.** On the container's MariaDB 10.11.14, the table was
built with the narrow key, the three steps of `up()` were applied as raw DDL, and the pre-deploy
insert — one that names no market, which is every insert the running version makes — was repeated:

```
ALTER TABLE inventory_pools ADD COLUMN storefront_country CHAR(2) ... NOT NULL DEFAULT '';
ALTER TABLE inventory_pools ADD UNIQUE KEY ipool_market_location_product_variant_unique (...);
ALTER TABLE inventory_pools DROP INDEX ipool_location_product_variant_unique;

INSERT INTO inventory_pools (location_id, product_id, variant_key) VALUES (1, 10, '');
ERROR 1062 (23000): Duplicate entry '1-10-' for key 'ipool_market_location_product_variant_unique'
```

The duplicate is **still refused**, by the new key, because every row the pre-deploy code writes carries
`storefront_country = ''` from the column default. For code that ran before the deploy the wide key is
identical in effect to the narrow one it replaced: same rows refused, same rows accepted, same single
writer. And the thing the change is for now works — a second market's pool of the same shelf and product
is accepted:

```
INSERT INTO inventory_pools (storefront_country, location_id, product_id, variant_key) VALUES ('KW', 1, 10, '');
-- accepted; the table now holds ('', 1, 10, '') and ('KW', 1, 10, '')
```

So the contract's *rule* is broken while the contract's *purpose* is not. That is the whole of the
decision.

## 5. Two precedents already recorded

`NOT_EXPAND_ONLY` in `tests/Unit/Ci/GccMigrationsContractTest.php` already holds **two entries of this
exact shape**, both recorded earlier in this programme:

| file | the reason recorded with it |
|---|---|
| `2026_12_23_100000_add_country_to_provider_credentials_and_cities.php` | "replaces the key (provider, external_id) with (provider, country, external_id) so two countries may map their own city of that id. The wide key is added BEFORE the narrow one is dropped, so there is no window in which a city could be imported twice; both steps ask hasIndex() first and are skipped when they are already done" |
| `2026_12_28_100000_add_country_to_address_city_aliases.php` | "the same shape for address_city_aliases.normalized_alias, which was unique platform-wide... Wide key first, narrow key dropped after, each guarded by hasIndex()" |

The stock-pool migration is the same shape, for the same reason, in the same order, with the same
`hasIndex()` guards. **This is therefore not a new architectural question; it is a third instance of a
decision this register already records twice.** That is said plainly so the owner can weigh it as a
precedent rather than as a novelty — the decision is still the owner's.

## 6. The options

### Option A — record the decision (what the two precedents did)

Add the file to `NOT_EXPAND_ONLY` with the reason, as the other two carry theirs.

- One migration, one deploy, no new operational step.
- CI goes green on both SQLite jobs.
- Cost: a third exception on the expand-only contract. The list grows, and a list of exceptions is read
  by whoever writes the next migration.

### Option B — split it in two (the only option needing no exception)

`up()` keeps steps 1 and 2 (additive only). Step 3 moves to a **separate, later** migration that runs
after the new version is deployed.

- The contract is satisfied with no exception at all.
- CI goes green on both SQLite jobs.
- In the window between the two migrations the table carries **both** keys. The narrow one is strictly
  the more restrictive, so that window refuses a second market's pool — and **that costs nothing today**,
  because the feature ships dormant: `gcc.storefronts.enabled` is off and `PoolMarket` writes nothing but
  `''` until it is on. So in this particular case the two-stage deploy is cheap.
- Cost: a second migration somebody has to remember to run, and a documented deploy order
  (migrate → deploy → migrate). A half-finished sequence leaves the feature unable to work, silently,
  whenever it is later switched on.

### Option C — never drop the narrow key

Not viable, and recorded only so it is not re-proposed: with that key in place a second market's pool is
refused, so M-656 cannot work at all. Keeping both keys *permanently* is Option B with its second step
never taken.

### Option D — leave CI red

Not an option, but it is the current state and it is what §7 is about.

## 7. What is blocked until this is answered

The two SQLite jobs of every CI run on `gcc/development` stay red on this one finding. They are the jobs
that run the whole suite, so **while this is open, a full-suite green run cannot be produced on this
branch**, and the merge rule ("CI green on the branch tip, then THAT SHA is merged") cannot be satisfied.
The other two causes of run 678 are fixed and pushed; this is the remaining one.

## 8. Recommendation

**Option A**, with Option B as the answer if the standing policy is that the expand-only contract takes no
further exceptions.

The reasons, in order of weight:

1. the harm the contract names **does not occur here**, and that is measured on a real engine (§4), not
   reasoned about: the pre-deploy code keeps precisely the protection it had;
2. the register already records this decision twice, for the same shape and the same reason (§5), so
   Option A is consistent with how this programme has already answered it — and an inconsistent answer is
   itself a cost, because the next person reads all three;
3. Option B buys no safety **in this case** and adds a standing operational obligation; the two-stage
   deploy pays for itself when a narrowing migration really would break running code, and here it would
   not.

If Option A is chosen, the entry should say what the two siblings' entries say and what §4 measured. If
Option B is chosen, the second migration should carry the deploy order in its own docblock, and the
dormancy of the feature should be named there as the reason the intermediate window is safe.

**Awaiting the owner's answer. Neither option has been applied.**
