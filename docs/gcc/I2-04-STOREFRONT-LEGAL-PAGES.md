# I2-04: the legal pages of a storefront

Status: code and tests, in a draft branch. It has no switch of its own: it acts only while `GCC_STOREFRONTS_ENABLED` is on, and only for a storefront other than the default one. Nothing here has been run on the pilot server or on production.

## The rule

The website and the apps show a storefront its own current effective legal text in the visitor's language, through the business pages that already exist. A storefront with no text in that language shows no page: never another country's text, never the Saudi text as a stand-in, never a text of another language. The default storefront (`gcc.storefronts.default_country`, Saudi Arabia unless the admin changes it; the `grandfathered` list of the readiness is not used here), every request while storefronts are off, and every request that belongs to no storefront (console, panels) keep serving the platform's business pages exactly as before; `SaudiBusinessPagesUnchangedTest` records those answers from the code as it was and pins them with storefronts off and on.

## The mapping

One place: `gcc.storefronts.legal.page_slugs` in `config/gcc.php`.

| Document type | Business page (slug) |
|---|---|
| `terms` | `terms-and-conditions` |
| `privacy` | `privacy-policy` |
| `refund` | `refund-policy` |
| `return` | `return-policy` |
| `cancellation` | `cancellation-policy` |
| `shipping` | `shipping-policy` |

Every other business page stands for no legal document and stays the same in every storefront: `about-us` and the pages an admin adds. A document type that is required (`required_documents`) needs a slug here, or its text can never be shown (`StorefrontLegalPageSlugsTest`). The business pages have no language column and no translations: a page is one row with a title, a body and a banner, so only the body of a mapped page changes.

## What is served when

For a request of a storefront other than the default one, a mapped page (`App\Services\Storefront\StorefrontLegalPages`):

- keeps its title, banner and publish switch from the platform's page, and takes its body from the storefront's text. A page the admin has unpublished is unpublished in every storefront.
- shows the text `StorefrontLegalReadiness` calls current for the storefront, the document type and the language: the newest published text that is already effective. It counts only while it is approved by someone other than its author, not revoked, not blank and not an unedited template (`visibleTextsFor`, the same test as the sale gate). An older text never takes the place of a current one that is unfit, a draft, an archived or a scheduled row changes nothing, and a missing table of texts shows nothing instead of failing.
- is not shown when there is no such text: the website answers as it does for a page that does not exist (back to the home page with the "page not found" message), and the page list of the apps leaves the entry out.

The visitor's language is the one the site is read in: the language code of the session on the website, the `lang` header on the API, then the language the platform shows that code in (`sa` is Arabic, `us` is English). Only a language the storefront is required to have texts in (`required_locales`, `locales_by_country`) counts, so a language the platform or the storefront does not know is not given another one.

The storefront comes from `?storefront=`, the `X-Storefront` header or the path, as everywhere else. It is read with `StorefrontCatalog::country()` and not from the bound context, because the pages API does not (and, by `ResolveStorefrontMiddlewareTest`, must not) carry the `storefront` middleware: an app that names Kuwait in the header would otherwise be answered as the default storefront.

### Where

- Website: `GET business-page/{slug}` (`PageController::getPageView`).
- Apps: `GET /api/v1/business-pages` (`ConfigController::getBusinessPagesList`), with the `X-Storefront` and `lang` headers the app already sends. The shape of the answer is unchanged. The config answer carries no policy text any more (its policy keys are null), so the list is the only way a text reaches an app.
- The footer and the checkout read the same list through `$web_config['business_pages']`, which is built before the visitor's session is read, so on the website that list follows the store's default language; the page itself follows the visitor's.

### The cache of the list

`cacheBusinessPagesTable()` keeps the default storefront's list under the key it always had (`cache_for_business_page_list`, three hours). Another storefront's list has a key of its own per language it serves, `storefront:KW:cache_for_business_page_list:ar`, with `none` for a visitor whose language it has no texts in, so no request can mint more keys than the languages configured.

It is cleared with the platform's list when a business page changes (`cacheRemoveByType('business_pages')`), and without touching the default storefront's list when a legal text is saved or deleted through the model (`StorefrontLegalDocumentObserver`). A text that is scheduled to take effect later also ends the life of the list at that moment, so a list never shows a text after a newer one has taken effect. A write through the query builder raises no event: the writer has to call `cacheRemoveByType('business_pages')`.

No code in this tree writes `storefront_legal_documents` yet, so nothing clears the lists today except the platform's page edits; the observer is there for the publisher of I2-03.

### The links

The checkout agreement (the two copies in `_order-summary.blade.php`) and the registration agreement already link through the page slugs. A page opened in a new tab is served by the default storefront unless the link says which storefront it belongs to, so those links are built with `businessPageUrl()`, which adds `?storefront=KW` for another storefront and returns the link it always was for the default one. The checkout lists only the policies the storefront has a text for; the terms link is always there.

## What is not done

- No admin screen, publisher or import writes the legal texts (I2-03, I2-13.2). Until they exist the table is filled by hand.
- No acceptance receipt: what the customer saw is not recorded with the order (I2-05).
- The footers, the e-mail templates and the seller registration link are as they were. The website has no storefront picker, so its next click goes back to the default storefront (`STOREFRONT_FLAG_RUNBOOK.md`); only the agreements a customer accepts carry the storefront. The seller registration terms wait for the owner's decision on seller terms.
- The text itself, and its review by a native Arabic reader and a legal reviewer, belongs to I2-03 and Q-16: this slice shows what is recorded and records nothing.
- The title of a mapped page is the platform's, in one language: the table of texts has no title.
- The body is shown as the page shows its text, as HTML without escaping. The publisher of I2-03 has to sanitise a text when it is written.
- The tree has one storefront theme (`resources/themes/default`); there is no second theme to adapt. Any theme gets the same behaviour by calling `StorefrontLegalPages` and `businessPageUrl()`.

## Owner decisions that remain

- Whether a storefront may fall back to its English text when the Arabic one is missing (today: nothing is shown, the safe default).
- Whether seller terms are a separate document from customer terms (Q-16).
- Whether a storefront's legal page should depend on the platform page's publish switch, or have a switch of its own once the I2-03 workflow exists.
- Whether `about-us` and the pages an admin adds should become per-country.

## Tests

| Criterion | Tests |
|---|---|
| I2-04.0 the website shows the storefront's text | `StorefrontLegalPagesWebTest` |
| I2-04.1 the API list returns the storefront's texts | `StorefrontLegalPagesApiTest` |
| I2-04.2 no text, no other country's text | `StorefrontLegalPagesWebTest`, `StorefrontLegalPagesApiTest`, `StorefrontLegalVisibleTextsTest` |
| I2-04.3 the list cache is keyed by storefront | `StorefrontLegalPagesCacheTest` |
| I2-04.4 the agreement links | `StorefrontLegalLinksTest` |
| I2-04.5 Saudi Arabia unchanged, storefronts off and on | `SaudiBusinessPagesUnchangedTest` |
