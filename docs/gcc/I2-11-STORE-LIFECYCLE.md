# I2-11 to I2-17: the lifecycle of a storefront

Status: coded and tested on SQLite and on MySQL 8.0.46 (branch `gcc/p1-storefront-lifecycle`, stacked on the legal review of PR 105; no MariaDB run), **off by default** (nothing reads any of it while `GCC_STOREFRONTS_ENABLED` is off) and with **no migration** (the state is one business setting for each storefront, like the tax approval and the legal review). Nothing is MET until CI has run on a pushed head.

## The rule

A storefront other than the default one is a **draft** until a person says it is **ready**, and ready until a person approves it and says where the approval is written down, and only an **active** storefront sells. A person can **pause** an active storefront in one of three modes and resume it. The default storefront has always been selling, so it is active without a record; a record only exists once a person paused it.

| From | To | Needs |
|---|---|---|
| draft | ready | what a storefront needs to sell is met (`StorefrontPreflight`), a person, a reason |
| ready | active | the same again (it can change after ready), a person, a reason, a reference (the decision, the ticket or the minutes) |
| active or paused | paused | a mode, a person, a reason; optionally a message for the customers and the end of the stop (`YYYY-MM-DD HH:MM`, UTC, in the future) |
| paused | active | the prerequisites again (a decision can be withdrawn while paused), a person, a reason |

What a storefront needs to sell is asked in one place, `StorefrontPreflight`: its required legal texts published and approved by someone other than their author, the independent review of them on record (PR 105), the currency it trades in, and a tax decision (PR 99). Each is derived from stored texts and decisions, so no flag can stand in for one. The selling gate asks the same questions, plus the state: **`maySell` is "the storefront is active and its prerequisites are met"**, so a prerequisite withdrawn later closes an active storefront without a transition, and the state still says active.

A person is a name typed by whoever runs the command, refused when it names a tool or an assistant (best effort on free text, as the tax approval and the legal review do). Every change is written with its audit line in the same transaction (`storefront.lifecycle.ready`, `.activated`, `.paused`, `.resumed`; the line holds from, to, mode, reason, who and the reference), so a change whose trace cannot be written is not made.

## The three stop modes

| Mode | New sales | Browsing (catalogue, cart, theme, API) | The apps |
|---|---|---|---|
| `browse_only` | refused (`storefront_not_sellable`, 422) with the storefront's message | served | the list says `sellable: false`, `state: paused`, `stop_mode`, `stop_message` |
| `maintenance` | refused as above | refused: 503 `storefront_maintenance` with the message and the end of the stop (`storefront.available`, on the cart, the product browse and the theme routes of API v1) | the list says it, with `stop_until` |
| `redirect` | refused as above | served | the list says `redirect_to`: the default storefront, for the app to send its customers there |

The routes that serve what a customer already has (my orders, their tracking, refund requests and cancellations, payment callbacks, webhooks, due payments) and the admin panel carry neither gate, and a test pins both lists (`StorefrontSalesRoutesTest`): an earlier order is served in every mode (I2-16). One storefront in maintenance does not touch another. The state is in the database, so it survives `cache:clear`.

The default storefront can only be paused `browse_only`: a maintenance or a redirect of the storefront everybody falls back to would strand its own visitors (I2-17).

## The list an app reads

While storefronts are on, `GET /api/v1/storefronts` adds `state` to every entry and, for a paused one, `stop_mode`, `stop_message` (the storefront's own message, else the default text of the mode), `stop_until` and `redirect_to`. While they are off the list keeps its original shape.

## The command

`php artisan gcc:storefront:state [KW] [status|ready|activate|pause|resume] --by= --reason= [--reference=] [--mode=] [--message=] [--until=]`

`status` is read-only and reports, for each configured storefront (or one), its state, the last change, the message for its customers and each prerequisite (`ok` or `UNMET` with the command that records it) and advisory counts (products that would ship free, products of sellers). It **exits 1 while any storefront is blocked**. The other actions record the decision of whoever runs them, and work while the switches are off.

## What it does not do

- **No web redirect and no web maintenance page.** The website does not resolve a storefront from the request yet (no route group under a country prefix; the `storefront` middleware is on the API routes only), so there is nothing to redirect to or to put in maintenance on the web, and the maintenance answer is always JSON (a page would need the error view, which needs the web layout's variables). The redirect mode is data and an answer for the apps; I2-15 criterion 1 (a GET on the web) waits for country paths.
- **The commands are the operator's, with a name typed in.** The admin screen with its permission is `docs/gcc/I2-STOREFRONT-ADMIN-SCREENS.md` (branch `gcc/p1-storefront-admin-screens`); a step-up for activating and pausing belongs to GCC-20.
- **I2-17 criteria 1 and 2** (a new default must be active, legal-ready and not paused; changing the default is an authorised, audited action, not an environment edit) are not done: the default country is still `GCC_DEFAULT_COUNTRY`.
- The customer app and the seller app do not read the new fields yet (J-08), and the customer app does not tell a customer why a storefront disappeared (I2-15 criterion 3).
- **The new gate condition closes every non-default storefront that was selling.** None is, because storefronts are off everywhere; turning them on needs each one marked ready and activated first.
- Maintenance has an end (`stop_until`) but no start, and nothing ends it by itself.
- `gcc:storefront:currency-readiness` does not print the state; `gcc:storefront:state status` is the report.

## Decisions that remain with the owner

1. Who may mark a storefront ready, activate it and pause it (today: whoever may run the command), and whether activation needs two people.
2. Whether a paused storefront in `maintenance` should still answer the theme (the apps read the list for the status).
3. The Arabic text of the new key `storefront_is_in_maintenance` needs a native reader.
4. Whether the default storefront may ever be paused (today: browse only).

## Tests

`StorefrontLifecycleTest` (40: the start of a draft and of the default storefront, every transition and every refusal, a tool as the person for each change, the audit lines, the default storefront, the state surviving a cache flush, one storefront in maintenance and another selling, a change whose trace cannot be written, a record that makes no sense, the gate), `StorefrontLifecycleCommandTest` (14), `StorefrontStopModesTest` (25: what each mode does to browsing, to a new sale and to the list), and three tests added to `StorefrontSalesRoutesTest` (the maintenance gate is on the browse routes and on nothing else, and the open routes carry neither gate). A test world that may sell is also active: `Tests\Support\ActivatesStorefront`, called by the helpers that already open a storefront (`publishRequiredLegalDocuments`, `leaveTheLegalTextsOutOfThisTest`, `BuildsStorefrontCart`, `BuildsSixStorefrontCheckoutWorld`).
