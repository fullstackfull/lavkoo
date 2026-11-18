# I2-03 and I2-11: the admin screens of a storefront

Status: coded and tested on SQLite (branch `gcc/p1-storefront-admin-screens`, which contains the lifecycle of PR 111 and the legal text publisher of PR 114 by merge; draft, nothing merged). It needs **no migration** and **no switch**: it writes through the two services that already exist, and it is reachable only by an admin whose role holds one of three new permissions, which no role holds until someone grants it. Nothing is MET until CI has run on a pushed head.

## What was missing

Both decisions that open a country were console commands: `gcc:storefront:state` (a storefront is marked ready, activated, paused, resumed) and `gcc:storefront:legal-text` (a text is drafted, edited, approved, published, revoked). The person was a name or an admin number typed by whoever ran the command, no role could be refused, and a country could not be run from the panel the business uses. The tracker therefore kept I2-11 criterion "every change is permission-gated" and I2-03 criterion "each step is permission-gated" missing.

## The screens

`/admin/storefronts` lists every configured storefront with its state (draft, ready, active, paused and the stop mode), the four things it needs before it can sell (legal texts, their independent review, the currency, the tax decision) as met or unmet, who last changed it and why, what its customers are told and when the stop should end. A draft with something unmet is offered no step; a draft with everything met is offered *mark ready*; a ready storefront *activate* (with the place where the approval is written down); an active one *pause* in one of three modes, with a message for the customers and an end in UTC; a paused one *resume* or *change how it is stopped*. The default storefront is shown as active without a record and can only be paused browse-only.

`/admin/storefronts/{country}/legal-texts` lists the text each required document type and language needs and the one in force today, then every version that was ever written with who wrote and approved it and the steps it can still take: a **draft from a template**, an **edit** (an approved text goes back to a draft and its editor becomes its author), **approve**, **publish** from a day (UTC) or now, **revoke** with a reason, a **new version** of a published text, and **put a draft away**. The default storefront keeps its documents and has no form.

Every step is the step the console command takes: the same service, the same refusals in the service's own words (shown to the person, escaped), the same audit line in the same transaction.

## Who may do what

Three permissions in the role form, in a section of their own like Monitoring (`StorefrontPermissionService`):

| Permission | Steps |
|---|---|
| `storefront_lifecycle` | mark ready, activate, pause, resume |
| `storefront_legal_write` | draft from a template, edit, new version, put a draft away |
| `storefront_legal_approve` | approve, publish, revoke |

The master admin holds all three; no other role holds any until it is granted; a switched-off role holds none. The permission is read from the role on every request, so granting and withdrawing take effect at once (tested through the real role form). The list of storefronts is open to a role that holds any one of them, the legal texts to a writer or an approver, and every POST needs its own. Writing and approving are two permissions so that the person who writes a text is not the person who puts it in force; the publisher refuses the author as the approver in any case, so a master admin who wrote a text still cannot approve it.

The routes use `EnsureStorefrontPermission`, not `module:a,b`: the module middleware of a tree without the security set reads its first permission only, and a route open to a writer or an approver must not depend on which tree it runs in.

## What keeps it safe

- **The person is the signed-in admin.** The lifecycle record carries "name (admin number)" built from the session, a typed `by` is ignored (tested), and the audit line's actor is the admin guard (`actor_id`, `actor_name`).
- **A text is cleaned before it is kept.** The website shows a legal text without escaping, so what is typed into the screen goes through the platform's `HtmlSanitizer` (scripts, styles, forms, frames, event handlers and `javascript:` links are removed). The console command still stores a file as it is.
- **Everything typed is shown escaped**, and a text is shown in a `<pre>` as its source, never rendered.
- A database failure is not shown as a refusal (its message holds the statement); only the services' own refusals are.
- Every POST has a throttle bucket of its own; the screens work while `GCC_STOREFRONTS_ENABLED` is off, as the commands do, because a storefront is prepared before it is switched on, and the page says so.

## Found on the way

A moment typed in UTC (the screen and the command read theirs as UTC) was stored by the publisher as it came and read back in the application's time zone (`Asia/Dhaka` by default), so a text scheduled a few hours ahead took effect hours early. Fixed on PR 114 (`9f099f97`) with a test that fails without it; this branch contains the fix.

## What it does not do

- **No step-up and no dual approval for publishing, revoking and activating** (GCC-20 level 3, I2-03 criterion 5): the screen refuses the author as the approver, which is the dual control of the text, and nothing more.
- **No scope by country.** An admin who holds a permission holds it for every storefront; the staff scope of axis G (a role limited to some countries) is not applied to these screens.
- No source of templates (the person pastes the template) and no Saudi pages as templates; no retention rule; no acceptance receipt.
- The side bar and rail entry exists in the v2 admin layout only (the classic layout is not rendered by the panel any more).
- A native reader has not read the 79 new Arabic strings (`resources/lang/sa` and `ae`); see `reviews/R5-native-arabic-reader.md`.

## Tests

`AdminStorefrontLifecycleScreenTest` (18), `AdminStorefrontLegalTextScreenTest` (26), `AdminStorefrontRoleFormTest` (3) and `AdminStorefrontMenuTest` (6, SQLite only because the side bar reads tables of the whole panel): who may open each page and take each step (master admin, each of the three roles, a role with another module, a switched-off role), a refusal leaving nothing changed and nothing audited, the person being the signed-in admin and not a typed name, every step through the real routes with its audit line and actor, the author refused as the approver in the page and in the service, a second writer becoming the author of what they edit, the day read as UTC and never from the past, a revoked text closing a storefront that sold, scripts and event handlers removed, the default storefront and an unknown country, and both screens in every state in English and in both Arabic files with no key written to a language file.

## Decisions that remain with the owner

1. Who holds the three permissions (a country manager, legal counsel, a second approver) and whether activating a storefront needs a second person.
2. Whether publishing a text needs a step-up on the phone (GCC-20 level 3).
3. Where templates come from.
4. The Arabic text of the 79 new strings.
