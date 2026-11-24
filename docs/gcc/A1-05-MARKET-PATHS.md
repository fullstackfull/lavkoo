# A1-05: the website answers each market under its own path

**Status:** on `gcc/development`, behind a switch that ships **off**. No migration, no new route, no route renamed, and nothing changes for a
Saudi visitor at the root in either state.

Decision behind it: the plan's own text — the website serves each market under `/sa`, `/ae`, `/kw`, `/qa`, `/bh` and `/om`
(`docs/gcc/DECISIONS.md`, and `App\Services\Storefront\StorefrontUrl`, which has been building those addresses for the canonical link, the
hreflang alternates and the sitemap since B-17).

## The gap

The web routes are mounted at the root and nothing was mounted under a country, so `/kw/shop-cart` answered **404**. A market could be
reached only by the `X-Storefront` header or a `?storefront=KW` query — neither of which a visitor can type, share or bookmark. The sitemap
and the canonical links already named `/kw/...` addresses that did not exist.

## How it works

One route table. The market's segment is taken off the request **before it is routed**, which is the only moment the path can still be
changed, and the market is written on the request for the resolver to read:

```
GET /kw/contacts  ->  ServeStorefrontPathPrefix  ->  GET /contacts, market KW  ->  the same route named `contacts`
```

So a market's pages are the platform's pages: the same controllers, the same route names, the same middleware. A page that works at the root
cannot be broken under a prefix by anything but itself, and `route('contacts')` is still one route.

The other half is the links. While a request is served under a prefix, the paths the application generates are given that market back, so a
link on a Kuwaiti page stays Kuwaiti (`StorefrontPathPrefix::pathWithin`, hung on Laravel's `URL::formatPathUsing`).

| | Behaviour |
|---|---|
| `GET /kw/contacts` | the `contacts` route, served as KW |
| `GET /kw`, `GET /kw/` | that market's home page |
| `GET /sa/contacts` | served as SA, and its links keep `/sa`: the rule is the address the visitor is on, not the market |
| `GET /contacts` | exactly what it is today — SA, bare links. **Nothing takes a Saudi visitor to `/sa`**, and the canonical link of every page is the bare one (`StorefrontUrl::of`), so what search engines keep is the address Saudi Arabia has today |
| `GET /ae/contacts` while AE is inactive | 404, as now |
| `GET /zz/contacts` | 404 |
| `GET /kw/no-such-page` | 404 |
| Switch off, or storefronts off | `/kw/contacts` is a 404 and the root is byte-identical to today |

### What never gets a market

`admin`, `vendor`, `api`, `install`, `sanctum`, `oauth`, `_debugbar`, `_ignition`. A panel screen, a seller screen, the API and the dev tools
are not visitor pages: a market in their address would mean nothing, and would carry one market's context into a screen that serves all six.
They are reached from a prefixed page at the addresses they already have. `api` is in that list although some API routes do serve a
storefront — an API client names its market in the `X-Storefront` header, which is the contract the apps already use, and a path a client
builds itself must not change under it.

Assets are untouched because `asset()` does not go through the path formatter.

### Precedence

The market whose path the request was served under now wins over everything, because it is the address the visitor is on: a
`?storefront=SA` on a `/kw/` URL contradicts the page it is on rather than choosing a market. On an unprefixed request nothing changes — the
explicit choice, then the header, then the path, then the default, exactly as before (`StorefrontResolver::contextForRequest`).

### The formatter is put back

The path formatter lives on the URL generator, which is a singleton. One web request per process never notices, but a worker, a test and a
long-running server serve one request after another in one process, and a formatter left behind would put one market's segment in front of
the next request's links. So the middleware restores whatever was there before, after a failure as well — the same shape as
`StorefrontContext::serve`. A test pins it, and it failed before the restore was added.

## The switch

`gcc.storefronts.paths.enabled` (env `GCC_STOREFRONT_PATHS`, default `false`), and it needs `GCC_STOREFRONTS_ENABLED` as well. While either
is off nothing is stripped, nothing is added and no address changes.

## What is proved

`tests/Feature/Storefront/StorefrontPathRoutingTest.php` (16), against the **real** route table and every middleware that decides which
storefront a request is served as; the two controllers behind the pages are markers that report the market and the addresses generated,
because the real home page reads the installation database and what is under test is the address, not the page:

- a market path is served by the route the root is served by, with the same route name;
- the root of a market is its home page;
- the default storefront's pages are untouched at the root;
- `/sa` is served, and a visitor who entered there keeps `/sa` while the root stays bare;
- the links a market page generates keep that market, by route name and by `url()`;
- the panel, the API and the assets are never given a market;
- an inactive market, an unknown prefix and a page that does not exist are each a 404;
- nothing is served under a market path while either switch is off, and the root is what it was;
- the path outranks a contradicting `?storefront=`, while a query still chooses on an unprefixed page;
- a query string survives the strip;
- a redirect written in a page keeps the market, and a redirect at the root keeps none;
- a market's links do not reach the request after it;
- the route table is identical with the switch on and off.

Eight of the sixteen fail if the middleware is taken out of the global stack.

## Still open

- **The market switcher** a visitor uses to change market from a page (A1-06 covers the choice being kept; the control itself is a product
  decision about where it sits in the theme).
- **A redirect written as a full absolute URL** would not keep the market: only paths go through the formatter. A redirect by route name or
  by path does keep it, which a test pins. Nothing in the storefront writes an absolute one today; it is worth a scan before the switch is
  turned on.
- **`/sa` and SEO**: `/sa/...` is reachable and generates `/sa` links for the visitor who entered there, so the same page has two addresses.
  The canonical link and the sitemap name the bare one, which is what keeps the indexed address the Saudi one.
