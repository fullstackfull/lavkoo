# A1-03 / GCC-19: the default storefront is a decision, not a deploy

**Status:** on `gcc/development`, in commit [`998257be`](https://github.com/fullstackfull/lavkoo/commit/998257be) — whose message describes
only the fulfilment characterization it also carries (C-15). The two were staged together by mistake; the history is not rewritten for it,
so this line is where a reader finds the commit. No migration, no new table — one business setting, like every other storefront decision. Dormant while
`GCC_STOREFRONTS_ENABLED` is off: nothing is read, nothing can be changed, and the platform's default is the one the configuration ships.

## The gap

Which market a visitor who names none is served came from `config('gcc.storefronts.default_country')`, i.e. from `GCC_DEFAULT_COUNTRY` in the
environment. Saudi Arabia is the right first answer and is what ships, but the plan says the admin can change the default later — and
changing an environment value is a deploy, not an administrator's decision.

## How it works

The default is a **recorded decision**, the same shape as a storefront's lifecycle: who made it, when, and why, written in the same
transaction as its audit line (`StorefrontDecisionRecord`), so a decision whose trace cannot be written is not made.

It is read **once, when the application boots**, and applied to the configuration (`StorefrontDefault::applyToConfig`, called from
`AppServiceProvider::boot`). Never inside `StorefrontResolver`: that is a value object several callers build directly, with no container and
no database, and `fromConfig()` runs many times in one request — a record read there would cost a lookup per resolve. One read at boot also
means the whole process agrees about the market it is serving: a web request, a console command and a queue worker alike.

```bash
php artisan gcc:storefront:default
```

```bash
php artisan gcc:storefront:default AE --by="The owner" --reason="The Emirates market opens first"
```

```bash
php artisan gcc:storefront:default --withdraw --by="The owner" --reason="The Saudi market leads again"
```

With no country the command reports what is in force, whether that is the configuration's answer or a person's, and for each market the
platform serves whether it could be the default and why not. The change applies from the next request, because the decision is read at boot.

## What is refused, and why

| Refused | Why |
|---|---|
| While storefronts are **off** | with them off the default market *is* the platform, so moving it would move every visitor of a Saudi installation to another country's catalogue in one step |
| A market the platform does not **serve** — unconfigured, inactive, or not a country code | nothing could reach it, so it cannot be the market a visitor is served |
| A market that **may not sell yet**, or is **paused** | a default that cannot take an order is a shop with the lights off |
| A **tool** as the person, or a change with no reason | the same rule every storefront decision follows: a person with the authority, and a reason |

**"Operational" means `StorefrontSellingGate::maySell`** — the gate every order and payment path already asks: the market's legal texts and
their independent review, its currency, its selling company, its tax decision (`StorefrontPreflight`), and a person having made it active and
not paused it (`StorefrontLifecycle`). That is the platform's own definition of a storefront that may trade, and using it here means there is
one definition rather than two.

## It cannot take the site down

The resolver refuses to be built on a default it cannot serve — which would be every request. So **anything** that stops the record being read
or trusted leaves the configured default alone: storefronts off, no settings table, no record, a record naming a market that is no longer
served or no longer able to sell, a database that cannot answer, or a configuration whose own default has become unservable. A test pins the
case that matters: a recorded default that can no longer sell is ignored and the next request is served Saudi Arabia.

The one thing the record never does is move a visitor who has already chosen a market: a choice outranks the default, and always did (A1-06).

## What is proved

`tests/Feature/Storefront/StorefrontDefaultChoiceTest.php` (14), on SQLite and MariaDB 11.4:

- the platform starts with Saudi Arabia, with nothing recorded;
- a person moves the default and the next request is served that market;
- the move is written down with its audit line, and the trace says where it came from;
- a market that may not sell yet, one that was opened and then paused, and one the platform does not serve are each refused;
- the default cannot be moved while storefronts are off, and a record made before they were switched off is not read;
- a recorded default that can no longer sell is ignored and the site stays up;
- a tool as the person, and a change with no reason, are refused;
- the record can be withdrawn, and then the configuration decides again;
- moving the default does not move a visitor who chose a market;
- the command reports what is in force and why a market could not be the default, moves it, and refuses one that may not sell.

## Still open

- **The apps.** The customer and seller apps still use their own constant instead of the `default_country` that `GET /api/v1/storefronts`
  answers. That is the last criterion of A1-03 and it is work in the two Flutter repositories.
- **A panel field.** The command is what an operator has today. A field in Business Setup would call this same service, and this requirement
  carries a security review for exactly that.
