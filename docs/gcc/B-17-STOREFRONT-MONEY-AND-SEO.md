# A storefront shows its own money and is indexed as itself (B-03, B-14, B-16, B-17)

The price a page shows and the price a search engine is told have to be the same number, so the money and the search
signals of a storefront are one piece of work.

## One answer about money

`App\Services\Storefront\StorefrontCurrency::money()` is the single answer to *what money is this amount, in the
storefront serving this request*: the number, the currency, that currency's decimals, its symbol and the formatted text.

It states today's answer rather than inventing one. The number and the text come from the money helpers the storefront
has always used (`webCurrencyConverterOnlyDigit`, `webCurrencyConverter`, `getCurrencyCode`, `getCurrencySymbol`), and
those read the three currency settings through `getWebConfig()`, which `StorefrontCurrency::override()` answers for a
storefront that trades in its own currency. **Nothing stored is re-scaled or re-rounded.**

Every surface asks it:

| Surface | How |
| --- | --- |
| product page price, listing, cards | the price helpers, which `money()` composes and a test compares against it |
| price filter bound and its sign | `getProductMaxUnitPriceRange()` (narrowed to the storefront) and `getCurrencySymbol()` |
| JSON-LD offer | `App\Services\Seo\StorefrontSeo::offer()`, which calls `money()` |
| customer API: config, product details, filter bounds | `StorefrontCurrency::apiMoney()`, which calls `money()` |
| sitemap | `StorefrontSitemap`, which takes its URLs from `StorefrontUrl` |

### Decimals

A currency's minor unit is data, in `config('gcc.storefronts.currency.decimals')`: `KWD`, `BHD` and `OMR` are
thousandths, every other currency hundredths. `StorefrontCurrency::decimalsOf()` reads it, `override()` hands it to
`decimal_point_settings`, and every formatter follows without a change of its own.

Two places used to assume hundredths and no longer do:

* `StructuredDataBuilder` rounded an offer's price to two decimals. It now takes `price_decimals`, which defaults to two,
  so a caller that says nothing publishes exactly what it published before.
* A visitor's currency pick is kept in the session and the session outlives the storefront it was made on, so a visitor
  who picked the riyal and then opened Kuwait saw Kuwaiti amounts under a Saudi sign. `getCurrencyCode()` and
  `getCurrencySymbol()` now follow the storefront in force, and the switcher refuses on a storefront that trades in one
  currency instead of writing a pick there.

**Not changed, and an owner decision:** `products.unit_price` is `DECIMAL(40,20)`, so a thousandths price is stored as it
was typed. Nothing here rounds or re-scales a stored amount; a rounding change on money is a separate piece of work.

## One answer about addresses and indexing

`App\Services\Storefront\StorefrontUrl` is the only place that builds a storefront's address for a page. The prefix is
the `path` of the market's entry in `config('gcc.storefronts.countries')` — the `/sa`, `/ae`, `/kw`, `/qa`, `/bh`, `/om`
of `docs/gcc/DECISIONS.md` — and the default storefront keeps the bare address it has today.

`App\Services\Seo\StorefrontSeo` answers three things from it: the canonical address of a page, its hreflang alternates
(every indexable market times every published language, with the default market as `x-default`), and whether a market may
be indexed at all.

**A market is indexable once it is active and may sell**: `StorefrontResolver::activeStorefronts()` for the first and
`StorefrontSellingGate::maySell()` — legal texts approved, currency present — for the second. Nothing new decides this,
so the robots header, the sitemap and the app's `sellable` flag cannot disagree. A market being prepared stays reachable
to whoever is preparing it and is served `X-Robots-Tag: noindex, nofollow` by
`App\Http\Middleware\NoIndexUnlaunchedStorefront`.

**If the owner wants a market to be indexable before it may sell, or not indexable while it may**, that is the one
decision this design needs: it would become a flag of its own rather than the selling gate.

## Sitemaps

`App\Services\Seo\StorefrontSitemap` writes one file per indexable market other than the default one
(`sitemap-<stamp>-<path>.xml`), holding that market's landing page and its own products, read through the catalogue
narrowed to it. The platform file the panel has always generated is the default storefront's own sitemap: once the
markets are on it lists that storefront's products, so no other market's product is advertised under the bare address.

**The `/kw` path prefix has no routes registered yet.** The canonical and sitemap addresses follow the recorded decision
and become reachable when that route group lands; until the markets are switched on, nothing emits them.

## Dormancy

With the markets switched off, and with them on while only Saudi Arabia is configured, every number, tag and file is what
it is today. `tests/Feature/Storefront/StorefrontMoneyAndSeoTest.php` takes one snapshot of everything this feature can
state about a Saudi page — money, the app's money block, the canonical, the alternates, indexability, the robots header,
the price filter's ceiling and floor and its conversion, the sign, a flat clearance discount, the storefront sitemaps and
the platform sitemap's slugs — and compares the two states field by field.

A dormant installation serves Saudi Arabia only, so it holds no other market's products; the comparison runs on that
catalogue, and no field is excused.

With all six markets live and other markets' products present, the Saudi money, canonical, robots header, price-filter
ceiling, sign and discount are byte-identical to the switched-off state, and the Saudi sitemap keeps its own product and
gains no other market's. Two things move, both by design and neither about money:

* the page now names the markets that carry the product in its hreflang set, Saudi Arabia among them — the requirement;
* the price filter's floor rises, because the Saudi catalogue no longer holds a cheaper Kuwaiti product. That is the
  catalogue narrowing this branch already carried, and the floor is in riyals in both states.
