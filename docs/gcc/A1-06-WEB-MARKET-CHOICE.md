# A1-06: the visitor's choice of market on the website

**Status:** on `gcc/development`. No migration, no new table, no new cookie. Nothing is offered, kept or read while
`GCC_STOREFRONTS_ENABLED` is off, and nothing appears in the page of an installation that serves one market.

Decision behind it: **a manual choice takes precedence over any country guessed from the IP** (`docs/gcc/DECISIONS.md`).

## The gap

The backend has always let a *request* name its market — a `?storefront=` query, the `X-Storefront` header the apps send, and, since A1-05,
the path a page is served under. The website offered the visitor no way to say it and kept nothing, so a `?storefront=KW` choice was gone on
the next click. The customer app has had a chooser and a stored choice since `lavkooapp#7`; the website had neither.

## How it works

| | |
|---|---|
| Choosing | `POST /storefront` with `storefront=KW` (route `storefront.choose`). A POST because it changes what the site serves this visitor, and a GET could be done to them by a link, an image or a prefetch |
| Where it is kept | the session, where the visitor's currency and language choices already live — so it lasts exactly as long as the rest of what the site remembers about them, and no new cookie is set |
| What the answer does | sends them to the page they were on **inside the market they chose**, so the address carries the market too and a share or a bookmark of it keeps it (A1-05). Any market segment already in that address is replaced, never stacked: from `/kw/contacts` to Emirates is `/ae/contacts` |
| A market the platform does not serve | refused: nothing is kept, and the visitor is left where they were with `this_store_is_not_available` |
| While storefronts are off, or one market is served | the route answers **404** and the control is not rendered |

### Precedence

The resolver now reads, in order: the market whose **path** the request was served under, the **explicit query**, the **header** an app
sends, the **remembered choice**, the path of a request that was not stripped, and then the default
(`StorefrontResolver::resolve`). So:

- a market in the address always wins — the page the visitor is looking at is the market they are in;
- the remembered choice decides the pages that name no market, which is what "remembers it for the next page" means;
- a choice that is no longer served is ignored, and `StorefrontChoice::remembered()` answers null for it;
- **nothing infers a market from the IP.** There is no GeoIP database in this installation and no inference anywhere in the code, so the
  rule "an IP guess never replaces a stored choice" holds by construction. If an IP suggestion is ever wanted it gets its own switch and
  sits below the remembered choice, never above it.

### The control

The market picker sits in the top bar of the default theme, beside the currency and language pickers it is a sibling of, and is rendered
only where more than one market is served — so an installation that serves one country sees exactly the top bar it sees today. Each choice is
a form, not a link, because choosing is a change; no script is involved. The country's name is the one the platform already spells
(`GlobalConstant::COUNTRIES`), so nothing new has to be translated.

## What is proved

`tests/Feature/Storefront/StorefrontWebChoiceTest.php` (14), against the real route table:

- the choice is kept and decides the page after it;
- it sends the visitor to the same page in that market, with the query kept;
- switching markets replaces the segment instead of stacking it;
- choosing the default market sends them to the bare address;
- a visitor with nowhere to go back to, and one whose referer is another site, land on that market's home page with the choice kept;
- a market the platform does not serve — inactive, unknown, nonsense, empty — is refused four times over with nothing kept;
- the address outranks what was remembered, and so does an explicit query;
- a remembered market that was switched off afterwards is ignored;
- nothing is offered or reachable while storefronts are off, or while one market is served;
- the markets offered are the ones served, named as the platform names them, with the current one marked.

The first case fails if the remembered choice is taken out of the resolver's order.

## Still open

- **The control is not rendered in a test.** The Blade is conditional on `StorefrontChoice::isOffered()`, which is pinned, and the markets it
  lists come from `offered()`, which is pinned — but the partial itself needs the whole theme world to render, so what is proved is the data
  and the endpoint, not the markup.
- **An IP suggestion** does not exist and is not wanted until the owner asks for one; the rule it would have to obey is written above.
- **The seller APIs** still ignore the market the seller app chose, which is A1-11's seller markets rather than this.
- **Arabic** for `store` and `this_store_is_not_available` — the English keys are added; a native reader should write the Arabic.
