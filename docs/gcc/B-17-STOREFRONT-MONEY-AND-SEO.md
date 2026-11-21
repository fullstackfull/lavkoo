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
| price filter bound and its sign | `getProductMaxUnitPriceRange()` (narrowed to the storefront), and `StorefrontCurrency::symbolInForce()` where a storefront has money of its own |
| JSON-LD offer | `App\Services\Seo\StorefrontSeo::offer()`, which calls `money()` where a storefront has money of its own |
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

The price filter's placeholders are the one field that does **not** go through `getCurrencySymbol()`, and deliberately so.
They have always printed `session('currency_symbol')` raw, which on a visitor's first request — before any money helper has
called `loadCurrency()` — is no sign at all. Routing them through the helper would have put the platform's default sign on
a live Saudi field that prints nothing there today. So the partial asks `StorefrontCurrency::symbolInForce()` and falls
through to the session where no storefront currency is in force: a market that trades in its own currency signs them, and
everywhere else the field prints exactly the bytes it prints today, in either session state.

**Not changed, and an owner decision:** `products.unit_price` is `DECIMAL(40,20)`, so a thousandths price is stored as it
was typed. Nothing here rounds or re-scales a stored amount; a rounding change on money is a separate piece of work.

### What the rendered price filter proves, and what it cannot (B-14.5)

The field carries **the sign and the ceiling**. It does **not** carry the floor, and no assertion written against it could
prove one: the partial prints `placeholder="{{ $priceFilterSign }}{{ '0' }}"` — a template literal — and never calls
`getProductMinUnitPriceRange()` at all. The slider beside it is a literal `data-min-value="0"` too. Measured on the Saudi
market: the helper's floor is **350** converted for the web (**100** as stored) while the rendered field offers **`SAR0`**.

So the floor is proved where it is observable, which is the helper, and the ceiling is proved on the rendered field:

| Bound | Surface it is proved on | Proof |
| --- | --- | --- |
| ceiling | the rendered field | Kuwait's own `KD500`, not the whole catalogue's 1000, in both session states |
| sign | the rendered field | `KD` on Kuwait, and the exact bytes the Saudi field prints today in both session states |
| floor | the helper | Saudi 100 and Kuwaiti 0, each a different number from the whole catalogue's. Strip `inCurrentStorefront()` out of `getProductMinUnitPriceRange()` and the Saudi assertion goes red (`0 is not identical to 100`) |

**Why the field was not wired to the helper instead.** It was the other option, and it was declined on the merits. It would
change what a Saudi shopper sees today — the field would offer 350 instead of 0 — and it would leave the placeholder
disagreeing with the slider's own hardcoded minimum unless that moved too, which is a redesign of the control. That is a
behaviour change to a live Saudi surface that nothing in the plan asks for, against a rule that says to inject logic and
never alter layout or design. And the floor is not unread: `api/v1/config` publishes it as `product_min_unit_price_range`,
straight from this helper, so a wrong floor already reaches a client there.

The claim "the field carries the sign only" is itself pinned, so it cannot go quietly out of date: the test asserts the
Saudi field prints neither `SAR100` nor `SAR350`, and that the partial's source does not mention the minimum helper. Wire
the helper into the field and that assertion goes red, which is the signal to come back and prove the floor on the field.

### The theme header's currency switcher: a reported defect the page cannot have (B-16)

A review reported that a Kuwaiti storefront prints **"SAR SAR"** in the header. **It does not, and the change made for it
has been reverted.** Both themes' headers wrap the switcher in `@if($currency_model == 'multi_currency')`, and
`StorefrontCurrency::override()` forces `currency_model` to `single_currency` whenever a storefront currency is in force.
The two states are mutually exclusive.

Measured over all 16 cells of markets off/on × SA/KW × both currency models, on both headers, rendering each header's own
guarded region verbatim — its `@php` assignment, its `@if`, down to the matching `@endif`:

* in each of the 4 cells where a storefront currency is in force, the region renders the **empty string**
  (`effective_model=single_currency`, `in_force=KWD`): a market trading in its own money offers **no switcher at all**;
* in all 16 cells the reverted expression and the proposed one render **identical** output, because wherever the region
  renders, `currencyCodeInForce()` is null and the fallback is the session — exactly what the header printed before.

The "SAR SAR" existed only inside a string the test's own helper had built: it grepped the single line mentioning
`currency_symbol` and rendered that alone, **discarding the two lines of guard above it**. The helper now renders the whole
guarded region, so it pins the page. The change also cost 33 bytes of whitespace on a live Saudi surface — a Blade comment
inside the rendered region — which the revert removes: front header 2733 → 2700, auction 2471 → 2438, non-space character
counts unchanged at 1072 and 812.

What stands in its place is the structural fact, asserted rather than assumed: with the operator's model set to
`multi_currency` (the only setting under which the switcher could appear) and the whole stale pick in the session, a
Kuwaiti storefront renders no switcher, and the model those headers read is `single_currency`. Removing a header's `@if`,
or making `override()` stop forcing the model, each turns that test red.

### The same guard decides the currency a payment is taken in

This matters beyond the header, because the money paths read the visitor's pick in the same shape. Four places do:

| Where | Line | What it reads |
| --- | --- | --- |
| `Customer\PaymentController` | 417 | `$request['current_currency_code'] ?? session('currency_code')` |
| `Customer\PaymentController` | 486 | the same, for a wallet top-up |
| `Customer\PaymentController` | 634 | the same, for a cash-on-delivery change amount |
| `OrderEditManager` | 829 | the same, on an order edit |

**Every one of them sits inside `if (getWebConfig(name: 'currency_model') == 'multi_currency')`**, and that is the setting
`override()` forces to `single_currency`. Measured in the real market context, through the same `getWebConfig()` calls
those branches make, with the operator's model set to `multi_currency` and the whole stale pick (`SAR`, its symbol and a
3.75 rate) in the session:

| Market | Model the branch reads | Pick-reading branch entered | Currency the other branch resolves |
| --- | --- | --- | --- |
| KW (trades in its own money) | `single_currency` | **no** | **KWD** |
| SA (the default storefront) | `multi_currency` | yes | SAR |

So on a market that trades in its own money the pick is never read, and the currency comes from
`getWebConfig('system_default_currency')` — which the same override points at that market's own currency. The Saudi
storefront keeps the operator's own currency model and the platform's own currency, exactly as before.

A test pins both halves, because they are what keeps those four reads unreachable on a money path: make `override()` stop
forcing the model, or stop pointing the default currency at the market, and it goes red.

**A fifth reader, and it is not behind that guard.** `Web\WebController` line 638 writes
`'bring_change_amount_currency' => session('currency_code')` straight into the order `generateOrder()` creates, on the
cash-on-delivery checkout, with **no currency-model condition around it** — unlike `PaymentController` line 634, which
writes the same field behind the guard. So for this one the session pick itself has to be right, and it is:
`loadCurrency()` keys off `getWebConfig('system_default_currency')`, which `override()` points at the market's own
currency, and rewrites `currency_code`, `currency_symbol` and the rate whenever that differs from what the session carries.

Measured as a visitor reaches it — the whole of a Saudi pick in the session, `system_default_currency_info` included, which
is what somebody who browsed Saudi Arabia and then opened Kuwait actually carries, then `loadCurrency()`: the session reads
**`KWD`**, so a Kuwaiti cash-on-delivery order is stored with Kuwaiti currency on it, not the Saudi code. Pinned, and it
goes red if `override()` stops pointing the default currency at the market.

**So no live defect was found on any of the five session-currency readers on these paths, and nothing on them was changed.**
Four are behind a guard that cannot be true while a storefront currency is in force; the fifth is correct because
`loadCurrency()` re-syncs the session from the same overridden setting. What this round adds is the three guards that keep
that true.

**Not done:** driving the controllers end to end, with a cart, a customer and a gateway. Whether a client-supplied
`current_currency_code` should be trusted at all on the default storefront is a pre-existing platform question, older than
the markets and untouched by them. Two readers outside these paths were not examined: `UserLoyaltyController` lines 143–144
(the loyalty-to-currency quote) and the exchange-rate helpers in `app/Utils/currency.php`.

### The published offer on the default storefront is dormant, and it is dormant on a known defect

`StorefrontSeo::offer()` answers the storefront's money only while a storefront trades in its own currency. Wherever the
platform's own currency settings stand — the markets switched off, or a request served from the default storefront — it
returns the amount **as it is stored**, `getCurrencyCode()` and the two decimals `StructuredDataBuilder` has always
rounded to, which is the offer this page published before any of this existed.

That is deliberately a dormant copy of a defect. Under `currency_model = multi_currency` the money helpers treat a stored
amount as a base-currency amount and convert it for display, so a product stored at `100.00` **prints** as `SAR375.00`
and its JSON-LD offer says `100 SAR`; a visitor who has picked another currency shifts the printed price again. The page
and the crawler have disagreed like that since long before the markets, and reading `money()` here would have corrected
it — publishing `375 SAR`, or `367.25 AED` for a visitor who picked the dirham, on the live Saudi page, from a slice
whose headline claim is that nothing moves. It would also have made the published offer follow the visitor's session
rather than the page.

So the offer stays dormant and the disagreement is pinned instead, by
`StorefrontMoneyAndSeoTest::test_the_published_offer_still_disagrees_with_the_printed_price_under_the_multi_currency_model`,
beside the percentage-rounding characterisation next to it. **Whether the crawler should be told the printed price is an
owner decision about what Google is shown, and it belongs to a slice that announces it.** A market that trades in its own
currency has no such gap: the single-currency model is in force there, so the printed price and the offer are the same
number by construction.

## One answer about addresses and indexing

`App\Services\Storefront\StorefrontUrl` is the only place that builds a storefront's address for a page. The prefix is
the `path` of the market's entry in `config('gcc.storefronts.countries')` — the `/sa`, `/ae`, `/kw`, `/qa`, `/bh`, `/om`
of `docs/gcc/DECISIONS.md` — and the default storefront keeps the bare address it has today.

Only a market a visitor can actually reach gets a segment of its own: `of()` reads the **active** storefronts, so a market
that is switched off takes the platform address rather than a `/kw/...` link to a page that answers nothing — which is
what a country this installation does not serve has always taken.

`App\Services\Seo\StorefrontSeo` answers three things from it: the canonical address of a page, its hreflang alternates,
and whether a market may be indexed at all.

### Which markets a page declares is a catalogue question

*May this market be indexed* and *does this market carry this page* are two different questions, and an hreflang set
answers the second. They were being answered by one source — `indexableCountries()` — and that published `en-SA`,
`ar-SA` and an `x-default` at a bare address on every Kuwaiti product page and in `sitemap-now-kw.xml`, while the Saudi
storefront holds no Kuwaiti product and its own `ProductDetailsController` redirects away from that page. `x-default` is
the address a crawler falls back to for every language it cannot match, so that was the worst of the three.

`StorefrontSeo::countriesCarrying()` is the one source now. It asks the catalogue, through the same narrowing the
storefront's own pages use (`StorefrontCatalog::offers()`), in each candidate market's context and with no query of its
own; the page composer and the sitemap both go through it. `alternates()` takes `carriedBy` as a **required** argument,
with no default, because defaulting it to the indexing gate is exactly the mistake: a landing page passes
`indexableCountries()` deliberately and visibly.

| Page | What it declares |
| --- | --- |
| a product page | its own market's address in each published language — every product row belongs to one country |
| a landing page | every live market, in each published language, because every market has a landing page of its own |
| a page of a market that may not be indexed | nothing at all: no hreflang, no `x-default` |

A market alternate therefore appears where a page really does exist in several markets. **Linking equivalent products
across markets is a separate piece of work**, and `countriesCarrying()` is the method it changes.

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
and become reachable when that route group lands; until the markets are switched on, nothing emits them. So a
non-default market's canonical is **a correct string and not yet a reachable address** — `https://lavkoo.test/kw/...`
would answer 404 today. Any criterion recorded on it carries that caveat.

## Dormancy

With the markets switched off, and with them on while only Saudi Arabia is configured, every number, tag and file is what
it is today. `tests/Feature/Storefront/StorefrontMoneyAndSeoTest.php` takes one snapshot of everything this feature can
state about a Saudi page and compares the two states field by field:

* **the rendered page** — the `seo.product-supplement` partial, through the real composer: the canonical, the hreflang
  set and the JSON-LD a crawler actually reads;
* **the rendered price filter** — the theme's own `_filter-product-price` partial, in both session states a visitor can be
  in: before a money helper has warmed their currency keys, and after. Its two placeholders are pinned absolutely, not
  merely compared between the two states, because the sign is what moved here;
* the offer, as `StorefrontSeo::offer()` answers it and as `StructuredDataBuilder` publishes it;
* money, the app's money block, the canonical, the alternates, indexability, the robots header, the price filter's
  ceiling and floor and its conversion, the sign, a flat clearance discount, the storefront sitemaps and the platform
  sitemap's slugs.

Those are the two rendered surfaces this slice touches, and both are in the snapshot, because a proof that compares the
services and not the pages they feed excuses the feature's headline output: it is how a converted price reached the
published offer, and how a currency sign reached the price filter's placeholders, with nothing in the suite rendering
either one.

Both comparisons run under **both currency models**, `multi_currency` and `single_currency`, because the model an
operator picked decides whether the money helpers convert a stored amount or show it as it is, and a dormancy claim that
holds under one and not the other is not a dormancy claim.

A dormant installation serves Saudi Arabia only, so it holds no other market's products; the comparison runs on that
catalogue, and no field is excused.

With all six markets live and other markets' products present, the whole Saudi page a crawler reads is byte-identical to
the switched-off state — money, the rendered partial, the canonical, the alternates, the offer, the robots header, the
price-filter ceiling, the sign and the discount — and the Saudi sitemap keeps its own product and gains no other
market's. One thing moves, and it is not about money: the price filter's floor rises, because the Saudi catalogue no
longer holds a cheaper Kuwaiti product. That is the catalogue narrowing this branch already carried, and the floor is in
riyals in both states.

## One market never reads another's cache

The price filter's bound and the brand facet are cached, so the key decides whether a market can be served another
market's catalogue. `StorefrontMoneyAndSeoTest::test_a_catalogue_answer_warmed_in_one_market_is_never_served_to_another`
warms each for real as one market and reads it for real as the other, **both ways round, in one store with no flush
between the legs** — a test that clears the cache between them shows only that two markets compute different numbers.
Each leg carries two vacuity checks: the warming market's entry is still in the store when the asking market reads past
it, and a value under a key nothing scopes travels from one market to the other. The Saudi storefront's key is the plain
key by design, so the probe uses a key of its own.
