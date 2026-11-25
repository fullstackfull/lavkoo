# GCC-20 — which admin workflows belong on a phone, and which stay on the desktop

Written 2026-11-25, for L-13's criterion *"a written classification names which admin workflows are useful on
mobile and which stay desktop-only"*. It is a classification of what is worth BUILDING for the phone, not a
permission model: what an operator may do is their grant's business (L-05, L-12), and nothing here widens or
narrows it. Where a row says **desktop**, the mobile shell does not offer the workflow at all; the web panel
keeps it exactly as it is.

## The three tests a workflow has to pass to be on the phone

A workflow earns a place in the Super Admin shell when all three hold. They are the reasons, so that a
workflow not listed below can be classified by applying them rather than by asking.

1. **It is read in a minute or acted on in one.** The phone is used standing up, between other things. A
   screen whose value is comparison across twenty rows and six columns is a desktop screen however well it is
   drawn.
2. **A mistake on it is cheap or is caught.** A tap is easier to make by accident than a click, a phone is
   easier to leave unlocked than a laptop, and a mobile network drops a request half-way more often. A
   workflow whose mistake cannot be undone needs a confirmation, a precondition and a step-up to be here at
   all — and some do not belong here even with all three.
3. **It needs no file, no keyboard and no second screen.** Uploading a document, pasting a credential,
   reconciling two reports side by side and editing rich text are desktop work. A phone can RECEIVE a file
   (an export) and should not be asked to produce one.

## Mobile: the monitor half (level 1, built or building)

| Workflow | Why the phone | Where it is |
|---|---|---|
| All-markets overview: state, orders by status, pending fulfilment, returns, stock health | One screen answers "is anything wrong this morning" | L-07, `GET /overview` |
| Market readiness: active, storefront enabled, legal ready, selling company | A fact, read-only, one line per market | L-07/L-08, `GET /markets` |
| Money by market, company and currency — never one figure across two | Read in a minute; refuses rather than guesses | L-08, in `GET /overview` and `GET /reports` |
| Operational health: stuck payments, failing integrations and carriers, reconciliation breaks, open shop issues, pending approvals | The whole point of a phone: notice early, from anywhere | L-09, `GET /health` |
| Global alerts by severity and market, with a link to the market | Triage is exactly a minute's work | L-10, `GET /alerts` |
| The market selector, propagating to every query | One gesture, and it is how every screen above is narrowed | L-11 |
| Audit log of the platform admin's own actions | Read-only, and the answer to "who did that" is wanted where you are | L-04, `GET /audit-logs` |
| One market's report over a window: orders, returns, sales and settlements by company and currency | Read in a minute, and the figure a finance reader asks for first | L-14, `GET /reports` |
| Receiving an export of one market as a file | The phone receives; it does not produce | L-14, `GET /exports/orders` |
| The admin's own sessions and ending one of them | Losing a phone is the reason this exists | L-03 |

## Mobile: the acting half (levels 2 and 3, each needing its own authorization)

Listed as *useful on mobile*, which is not the same as authorized. None of these exists; each needs the
owner's separate approval (charter s.5) and the guards of L-15.

| Workflow | Why the phone | What it must carry |
|---|---|---|
| Assign or acknowledge a support issue | A decision of seconds, cheap to undo | market and capability scope, before/after audit |
| Request a stock review | Asks a person to look; changes no stock | scope, audit |
| Switch a store to Browse Only or Maintenance | The one critical action worth doing from anywhere, because the reason to do it is usually an incident | readiness preflight, status preconditions, named confirmation, fresh second factor, idempotency key, audited event, and GCC-12's pause modes existing at all |
| Acknowledge an alert (not resolve it) | Stops six people chasing the same thing | audit only |

## Desktop only

| Workflow | Why not the phone |
|---|---|
| Payment account, credential or settlement destination | Test 2. The mistake is money leaving for the wrong account, and it is not undone by tapping again. It also needs pasting a credential, which is test 3 |
| Publishing a legal policy | Test 1 and test 3: it is a document to read in full, in two languages, next to the previous version |
| Changing a permission or a grant | Test 2, and deliberately: a grant of this API is made by CONSOLE with the owner's confirmation (L-15), which is not a screen anywhere |
| Approving a large refund | Test 2. Dual approval on one device is not dual approval |
| Enabling or disabling an integration | Test 2: the blast radius is every order of a channel, and the preflight is a page of its own |
| Releasing stock | Test 2: it is an inventory decision taken against a list of locations and reservations |
| Product, category and catalogue editing | Test 1 and 3: this is bulk work with images and long text |
| Seller onboarding, verification and document review | Test 3: files |
| Tax configuration and compliant tax reports | Test 1: the report is the output of reading several of them together; the phone may show the SUMMARY (above), never the configuration |
| Theme and storefront design | Test 1 and 3 |
| Campaign and marketing authoring | Test 3: rich text and images. The phone may read a campaign's figures |
| Country settings and lifecycle (opening or closing a market) | Test 2, and it is not an incident response: a market is opened once, with counsel |
| Accounting posting review and correction | Test 1: two ledgers, side by side |
| Anything that writes customer personal data | Not a test but a rule: a mobile screen is read over shoulders and on other people's networks, and L-09 and L-14 already refuse to put a person in an answer |

## What this classification does NOT decide

- **Whether the owner wants any of the acting half.** Levels 2 and 3 are their authorization to give, per
  action class, and this file lists what would be worth building if they do.
- **The order of the mobile drill-down views.** L-13's remaining criteria name per-market orders, returns,
  products, inventory, sellers, staff, logistics, payments, settlements, settings, campaigns, policies, themes,
  support and integration state. All of them pass test 1 as READ views and none exists yet; which comes first
  is the owner's (recorded as open on the L-13 card).
- **What personal data a list may show.** Open, and the rule above is the floor, not the answer.
