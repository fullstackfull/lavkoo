# Thirty requirements are one criterion from done

**Written** 2026-11-22, after the tracker reached 854 MET / 379 CODED / 520 MISSING of 1753.
**Branch** `claude/lucid-babbage-jiiim2`. **Nothing here is merged and nothing is deployed.**

## Why this exists

Waves have been chosen so far by what was nearest to hand. This counts where the 520
MISSING criteria actually sit, so the next waves can be chosen by yield instead.

## A note on what this document does not say

Five of the thirty requirements below are security-shaped: an identifier's secrecy, an
authorisation boundary, a rate limit, a return address. Each one's criterion text and its
evidence already sit in the tracker, among 1753 criteria. This document names those
requirements and says what they are waiting on, but it does **not** restate what is
currently unprotected, because a short ranked list of unfixed weaknesses is worth more to
an attacker than the same facts spread across the whole tracker. The owner's standing rule
is that vulnerability detail is not published before the system is secured, and that rule
applies to a summary of my own making as much as to a pull request. Read the detail in the
tracker, requirement by requirement, where it has always been.

## The distribution

| MISSING criteria in the requirement | Requirements |
|---|---|
| 0 | 114 |
| 1 | **88** |
| 2 | 73 |
| 3 | 55 |
| 4 | 24 |
| 5 | 5 |

Of the 114 with nothing missing, **47 are fully MET** — no MISSING and no CODED left.

Only **19 requirements are wholly missing**, and they hold just **75** of the 520
criteria. The other 445 are scattered one and two at a time inside requirements that are
already most of the way there. So the work left is not nineteen big holes; it is a long
tail of last criteria.

### The number that matters

**Thirty requirements are missing exactly ONE criterion and have nothing CODED left.**
Closing those thirty single criteria would take fully-MET requirements from **47 to 77** —
a 64% increase in completed requirements, for thirty criteria of work.

They are listed below, each triaged by what it is actually waiting on. The triage is the
point: eighteen can be built now, and twelve cannot, for five different reasons.

## Buildable now, backend only — eighteen

| Requirement | The one criterion left | What the record says is missing |
|---|---|---|
| B-08 | Bulk import can target a chosen country, and an employee with several countries may import | the sheet has no country column |
| C-14 | A marketplace account of country X takes part only in the pools of that country's products | the pool membership and the listing gate carry no country |
| D1-07 | The included tax amount of a tax-included order is recorded | the cart's tax functions return zero when tax is included |
| D2-08 | A staff member scoped to one country cannot open or change another company's gateway settings | an authorisation boundary; detail in the tracker, not restated here |
| D2-14 | After the gateway the customer lands on the success or fail page of the storefront they paid in | the two pages are unprefixed routes; the rest in the tracker |
| D2-17 | Public payment endpoints are rate-limited | partial coverage and **no test**; detail in the tracker |
| D2-V2 | Wallet top-up limits are per market | the switch and both limits are one settings row each, read with no storefront |
| F-08 | A schedule for AE fires on AE's clock and publishes only AE's theme | — |
| F-09 | An app upgrade replaces a payload cached for the previous build | the cache key is the page and the storefront, with no build in it |
| F-14 | Changing a storefront's brand name does not change the legal name on existing invoices | — (the frozen copy this needs already exists on the legal-entity line) |
| H2-04 | A settled cost is queued for Odoo by the application itself, and drain and reconciliation run on a schedule | the enqueue is called only from tests; no schedule entry; drain is a button |
| H2-09 | Regulated rules can differ per market and per channel | the config is keyed by channel only |
| H2-16 | A listing, price or stock push is refused when the product's country differs from the account's | three writers read no country |
| H2-17 | An inventory location and its pool belong to one country storefront and refuse another country's listings | three global locations; the pool lookup has no country filter |
| I2-12 | Legal entity, currency, payment accounts, shipping, catalogue and theme are checked for the country | the preflight checks four of the six |
| M1-03 | Each of the five resolutions is pinned by a test | recorded in a commit message only |
| M1-11 | Files outside the overlay directories are checked, applied and rolled back by the tool | seventeen files go by a shell loop with hash lists |
| M2-02 | Orders, wallets, loyalty, queued mail, push and console runs carry or honour the storefront | none of 27 queued classes mentions a storefront — **the notification part of this has just advanced on PR 164** |

Three notes on this table. **D2-08, D2-14 and D2-17** are the security-shaped three in this
group: each is built and published as isolation or as a limit, never as a description of
what the current state would let someone do. **M2-02** is much larger than the other
seventeen and should not be treated as a one-criterion job.

## In flight — one

**E-11**, "the admin and vendor POS charge the server-computed sale total in whole cents",
is being built now on `gcc/p1-till-charges-the-server-total`, stacked on PR 156.

It is a money defect, which is why it went first: the admin till reads the sale amount from
the request, compares that posted figure against the wallet balance and debits it, while the
server holds the cart in the session and computes its own discounted total and tax a few
lines further down. The vendor till has the same shape.

The criterion has two halves and the lane is told that only closing both makes E-11 fully
MET: the total must be the server's, **and** the arithmetic must be in whole minor units —
which in this project means three decimal places for KWD, BHD and OMR, not two.

## Waiting on the owner — five

| Requirement | The one criterion left | What it waits on |
|---|---|---|
| A2-07 | A guest's identifier is a secret, not a sequential number | PERM-05: a guest token, or accept the present scheme |
| D1-15 | A guest order, cart or address cannot be reached by knowing or guessing the guest id | the same decision |
| I2-03 | Publishing and revoking a legal text asks for step-up or dual approval | GCC-20 level 3 |
| M1-07 | Each of the nine fixes has a release path and a tested combination | a release decision, which is reserved |
| M2-01 | Saudi behaviour is proven on a structure-only copy of the real Saudi schema | an authorised isolated copy; production data is forbidden to this work |

A2-07 and D1-15 are **one decision controlling two requirements**, which makes PERM-05 the
highest-yield single answer on this list.

## Waiting on a device or on the app repositories — three

| Requirement | The one criterion left | What it waits on |
|---|---|---|
| F-06 | After a switch the app renders the page the real server serves for the new storefront | a device run on two themes |
| M1-09 | Both Flutter apps fetch digital files through the authenticated routes | both app repositories, and decision 8 keeps the switch off |
| M2-10 | Seller app GCC context passes its CI; a device run confirms caches and chooser | the seller app's CI is red on main, and a device run |

M2-10 is entangled with the seller-app version gate: `pubspec.yaml` on that repository's
main ships a version below both its own force-update constant and the minimum the backend
seeds, so its CI has been red for ten days. **No seller app version change is being made:
the standing rule reserves it for verified release evidence.**

## Waiting on other work — three

| Requirement | The one criterion left | What it waits on |
|---|---|---|
| D1-12 | Each sub-order carries its own storefront and selling company | the legal-entity migrations and the refusal are on two disjoint branch lines — see the correction of 2026-11-22 and the follow-up task |
| D2-12 | A Paymob payment can be confirmed without the customer's browser | held deliberately: it touches an item that is not public |
| L-05 | Exports and push topics appear in the matrix | L-14 and L-16: no export or push code exists to put in a matrix |

## What follows from this

1. The eighteen buildable ones are the next waves, in groups that share a code area rather
   than one lane each: the three H2 country-isolation criteria are one lane; the three
   payments-surface criteria are another; F-08, F-09 and F-14 are a storefront-lifecycle
   lane.
2. **PERM-05 is worth answering before anything else on the owner's list**, because one
   answer unblocks two requirements that are each one criterion from done.
3. Nineteen wholly-missing requirements hold only 75 criteria. They are not where the
   remaining work is, and treating them as the backlog would mis-aim every wave after this
   one.

## How the figures here were produced

By loading the tracker generator's own `load_all()` and counting criterion states directly,
not by reading the rendered Markdown. The counting script is three lines over the generator
that is committed, and is not itself committed. Anyone can reproduce it: load `load_all()`,
then count `ac['s']` per requirement.
