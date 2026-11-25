# Track D1 — one selling legal entity: the consent receipt and the gateway account

Worktree `/home/user/wt/de`, branch `wt/de`, cut from `30a2283e`. Two commits, not pushed:

| commit | what |
|---|---|
| `6f69e5b6` | a consent receipt names the company it was given to, not one platform-wide string (M-556, legal half) |
| `596dc262` | the gateway account a sale's money goes through has to be the selling company's (M-605, third clause) |

Engines: **MariaDB 10.11.14** (`-c phpunit.mysql.xml`, database `pharmacy_testing_de`) and **SQLite** (`phpunit.xml`). Both new
classes green on both. No whole-suite sweep was run; one narrow directory (`tests/Feature/Payments`) was run once, because that is
the directory the payment change could break.

---

## M-556 — `docs/gcc/tracker/axis-m-security-tests-and-deployment.md:556`

Card **M2-04. Unified customer account across countries (test plan 4)**. The line, verbatim:

```
  - [MISSING] Marketing and legal consents are scoped by storefront and legal entity (Recorder written, called by no checkout code)
```

**Target state.** Both kinds of consent record which storefront and which selling legal entity they were given in.

**What the recorded evidence gets wrong, and it matters because it sent this task to the wrong place.** "Called by no checkout
code" is stale: `app/Services/Storefront/CheckoutLegalAcceptance.php` exists on this branch and is called from inside
`OrderManager::generateOrder`'s transaction, once per order and per required document type, and it reads the market from the
ORDER row rather than from the request. `CheckoutLegalAcceptanceTest` (15 tests / 71 assertions) covers it. The caller was not the
gap.

**The real gap was the company.** `StorefrontLegalAcceptanceRecorder` wrote `legal_entity` from
`config('gcc.storefronts.legal.legal_entity')` — ONE platform-wide string (`env('GCC_LEGAL_ENTITY')`) — so six storefronts
selling under six companies wrote six receipts naming one. The storefront half was already right; the company half was a
constant.

**What now happens.** The receipt carries the storefront's own selling company: `legal_entity_id` and `entity_snapshot`, the
same two column names and the same two shapes `orders` already records a sale's company under (`2026_11_27_100100`), read from
`LegalEntityRegistry::forCountry()` — the one reader `OrderLegalEntitySnapshot` stamps an order from, so a customer's receipt
and the sale it belongs to cannot name two companies. The frozen copy is kept for the reason an order keeps one: a receipt is
read after the company's name or tax number has changed, and it has to say what the customer was told then.

**Dormant as shipped.** Nothing new is written while legal entities are off (the default), before the registry's migrations have
run, before this table has the columns, or while the storefront has no active company. The `legal_entity` text column is
untouched, so every existing reader and every existing row is unchanged.

Files:

- `database/migrations/2027_01_06_100000_add_legal_entity_to_storefront_legal_acceptances_table.php` (new; additive, nullable,
  indexed on the company, `down()` refuses while a receipt names one)
- `app/Services/Storefront/StorefrontLegalAcceptanceRecorder.php`
- `tests/Feature/Storefront/LegalAcceptanceNamesItsSellingCompanyTest.php` (new)

**Tests: 8 tests / 22 assertions**, MariaDB 10.11.14 and SQLite. Neighbours re-run green:
`StorefrontLegalAcceptanceTest` 11/25, `CheckoutLegalAcceptanceTest` 15/71.

The columns are set as attributes rather than passed to `make()`, deliberately: they are not in the model's `$fillable`, and
mass assignment is how a column of a write-once receipt gets filled from whatever array a caller happens to hold. The model
(`app/Models/StorefrontLegalAcceptance.php`) is outside this track's paths and needed no change.

### Teeth checks (every one applied to production code, run, and restored)

| mutation | result |
|---|---|
| the company is never looked up (`$entity = null`) | 4 red: both storefronts' ids, the frozen identity, the later-change case, and the rollback guard |
| every storefront is given AE's company (`forCountry("AE")`) | 1 red: `test_two_storefronts_name_two_different_selling_companies`, "Failed asserting that 1 is identical to 2" |
| the snapshot keeps only the id, no frozen identity | 2 red, each naming the missing key (`country`, `legal_name`) |
| the migration guard removed from the recorder (`columnsReady()`) | 1 red: unknown column on the pre-migration case |
| the registry's switch bypassed (reads `legal_entities` directly) | 1 red: `test_with_legal_entities_off_...`, "Failed asserting that 1 is null" — this is the Saudi byte-for-byte tooth |
| the migration's rollback guard removed | 1 red: the expected message is not thrown |

No inert tooth. Two mutations surfaced weak messages on the first pass (`Trying to access array offset on null`); the test was
given a named assertion (`frozenCompanyOf()`, "The receipt carries no frozen copy of the company that sold under it.") and
re-checked before anything was committed.

### What of M-556 is NOT closed

**The MARKETING half is still open, and it is not in this track's paths.** `customer_marketing_consents.storefront` and
`.legal_entity_id` exist and are nullable, and `MarketingConsentRecorder` takes `storefront:` and `legalEntityId:` as
parameters — and **no caller passes either**. Checked: `app/Http/Controllers/Web/WebController.php:1748` (the newsletter form,
both the customer and the e-mail branch) and `app/Http/Controllers/Web/MarketingUnsubscribeController.php` pass `market:` and
nothing else; `grantForEmail()` hard-codes `storefront: null, legalEntityId: null` at
`app/Services/Marketing/MarketingConsentRecorder.php:102-103`. So every marketing consent row carries two nulls, and the market
half is the only scope that works.

The fix is small and belongs to whoever owns `app/Services/Marketing/**` and those two controllers: pass the request's
storefront and `LegalEntityRegistry::forCountry()`'s company at each of the three grant sites, and let `withdraw()` keep
carrying the grant's own values forward, which it already does. **Do not add a second reader of "who sells in this market"** —
`forCountry()` is the one the order stamp, the legal receipt and now the gateway-account pick all use.

Until that is done the tracker line cannot go to MET on its own text, which names both kinds of consent. The honest state is
CODED-and-partly-PROVEN: legal consents are scoped and tested; marketing consents are not scoped at all.

---

## M-605 — `docs/gcc/tracker/axis-m-security-tests-and-deployment.md:605`

Card **M2-06. Checkout in one store and one selling company (test plan 6)**. The line, verbatim:

```
  - [MISSING] One checkout names one selling legal entity, recorded on the order and used to pick the gateway account (No entity column or guard in any head)
```

**Target state.** Three clauses: one checkout names ONE selling legal entity; it is RECORDED on the order; it is USED TO PICK
the gateway account.

**The recorded evidence "No entity column or guard in any head" is stale on all three counts.** It was read before the entity
work landed. Verified on this branch, by running the tests rather than by reading the cards:

| clause | state on `wt/de` | proof |
|---|---|---|
| one checkout names one selling legal entity | **already held** | `OneSellingCompanyPerOrder` judges both halves of the selling scope (country and company) off the rows about to be written, from `OrderManager::checkValidationForCheckoutPages`, before `generateOrder`'s transaction and again on the locked rows inside it, plus all three tills and the channel materializer. `tests/Feature/Storefront/StorefrontCheckoutSingleCompanyTest` **26 tests / 68 assertions green**, and it covers the company half specifically (two companies of ONE country refused; one company not refused; a half-backfilled column refusing nothing) |
| recorded on the order | **already held** | `OrderLegalEntitySnapshot::record()`, asked from `OrderStorefrontSnapshot::record()`, writes `orders.legal_entity_id` and `orders.entity_snapshot` once, `whereNull`, from the one country the order's lines belong to. `tests/Feature/Checkout/EachSubOrderCarriesItsOwnMarketTest` **5 tests / 33 assertions green**, and it places real orders through the v1 cash-on-delivery route and reads the company off EACH sub-order, as an identity and not a presence check |
| used to pick the gateway account | **was not asked anywhere — this is the work** | below |

### The defect

`payment_gateway_accounts.legal_entity_id` is a free, unconstrained column. `PaymentAccountBinding::record()` picked the account
by `(market, gateway, environment)` and then merely READ the company off whatever row it found. So an operator can file the
Kuwait Paymob account under the Saudi company and every Kuwaiti sale is collected into that company's merchant account while the
order is stamped Kuwait's. Nothing compared the two.

It is not cosmetic: the credentials a payment is taken with follow the STAMP —
`app/Http/Controllers/Payment_Methods/PaymobController.php:389` resolves them through
`PaymentAccountBinding::accountOf()` — and `GatewayAccountRegistry::forStorefront()` has **exactly one caller**, that binding.
So the pick decides whose account takes the money.

### What now happens

`app/Services/Payments/AccountOfTheSellingCompany.php` (new) is the one answer to "which account may take this market's sales":
the market's account, confirmed to belong to the company that sells in that market. `PaymentAccountBinding::record()` asks it
instead of the registry's raw answer.

Who sells is read from `LegalEntityRegistry::forCountry()` — **the same reader the order's own stamp is read from, and
deliberately not a second answer**. The payment starts before the order exists on the digital path (the web checkout passes
`attribute_id: idate("U")`, a timestamp, and the order is written in the success hook), so the market is what the two have in
common; one company per country is the approved rule and `legal_entities.country` is unique, so the market the lines belong to
and the market the account is registered for name the same company or they name a disagreement.

Silence is not disagreement, the rule this platform applies to every recorded fact: a market with no registered company has
nothing to disagree with, and an account that names NO company is already read as the market's own by
`PaymentAccountBinding::companyOf()`. Neither is refused. What is refused is two recorded values that differ.

Files:

- `app/Services/Payments/AccountOfTheSellingCompany.php` (new)
- `app/Services/Payments/PaymentAccountBinding.php` (the pick, and the docblock that says where it comes from)
- `tests/Feature/Checkout/TheGatewayAccountBelongsToTheSellingCompanyTest.php` (new)

**Tests: 11 tests / 25 assertions**, MariaDB 10.11.14 and SQLite, every payment started through the real choke point all
fourteen gateways start a payment at (`App\Traits\Payment::generate_link`) and every assertion read off the stamp production
wrote. **`tests/Feature/Payments` re-run whole: 316 tests / 1505 assertions, green**, so the binding change broke nothing.

### Teeth checks (every one applied to production code, run, and restored)

| mutation | result |
|---|---|
| the binding put back on the registry's raw pick | 1 red: `test_an_account_of_another_company_does_not_take_this_markets_sale` |
| the switch forced on (`isEnforced()` returns true) | 3 red, the Saudi/today tooth among them: `test_with_the_switch_off_a_mis_registered_account_still_takes_the_payment_exactly_as_today` ("with the switch off the pick is the registry's own answer") |
| an account naming no company read as a disagreement | 1 red: `test_an_account_that_names_no_company_is_the_markets_own_and_still_takes_the_payment` |
| a market with no registered company read as a disagreement | 1 red: `test_a_market_with_no_registered_company_is_not_refused_its_account` |
| the disagreement never reported | 3 red: the pick, the reader and the report |

No inert tooth.

---

## OWNER QUESTIONS HIT

**Q-D1-1 (blocks M-605 going to MET, and it is a money question).** *May a market's sales be taken through a gateway account
that belongs to a DIFFERENT legal entity?*

The repository already answers this twice, in opposite directions, and nobody has reconciled them:

- `app/Services/Payments/PaymentBelongsToOrder.php` records, as a design note, that "a market's gateway account may belong to
  another country's company, which is why the refund keeps the selling company and the paying company as two columns rather
  than one", and at refund time it deliberately does NOT compare them;
- the criterion on line 605 says the selling legal entity is what PICKS the account, which only means something if an account
  of another company does not answer.

So the refusal ships **OFF** and the default is today's behaviour exactly. If the owner says such a registration is legitimate,
the switch stays off for good and the disagreement is a fact to report (`AccountOfTheSellingCompany::disagreement()` answers it
whatever the switch says, which is what an operator's readiness report wants). If the owner says it is not, the right home for
the refusal is the MARKET's readiness (`StorefrontPaymentReadiness`, behind
`gcc.storefronts.sale_gate.needs_a_payment_account`, which also ships off) so that an operator finds out before the market opens
rather than from a customer whose payment has just been refused. **Not built, on purpose:** it is a settlement rule, and the
instruction for this track is not to guess one. Nothing here moves money, converts a currency or changes a money unit.

**Q-D1-2 (M-556, low stakes but it leaves an inconsistency).** What becomes of `gcc.storefronts.legal.legal_entity`
(`env('GCC_LEGAL_ENTITY')`)? It is documented as "the legal entity a storefront contracts with… set per storefront before
release" and it is one value for the whole platform. The registry now answers the same question per storefront. Either the
config value should become per-storefront, or it should be retired and the `legal_entity` text column stop being written. This
track left it writing exactly what it wrote before, so a receipt of a registered company carries both the configured label and
the company's own id and frozen identity. Which is correct is the owner's and counsel's, not a code decision.

**Q-D1-3 (M-605, pre-existing, not introduced here).** `OrderLegalEntitySnapshot::record()` stamps an order from
`LegalEntityRegistry::forCountry()`, while `LegalEntityRegistry::forProduct()` exists and reads the company a PRODUCT names,
falling back to the country's. With one company per country the two agree; they can differ for a product naming an inactive
company or one of another country, and then the order is stamped the country's company while the product names another. That
file is outside this track's paths and was not touched. It is a narrow case and worth a decision rather than a quiet default.

---

## MUST BE DONE AT MERGE

**One config key is missing and this track could not add it.** `config/gcc.php` is outside this track's paths (and the
execution state records it as the one file more than one track touches). The new switch is read as
`config('gcc.storefronts.payment_accounts.account_belongs_to_the_selling_company')`, which is absent and therefore false, so
the code ships off correctly — but an operator cannot discover it. Add to the `payment_accounts` section of `config/gcc.php`:

```php
// Whether a market's gateway account stops answering for its sales when the account belongs to ANOTHER company
// (D1-03). Off: the account the registry holds for the market takes the payment whichever company it is filed
// under, which is what every payment does today. See owner question Q-D1-1.
'account_belongs_to_the_selling_company' => env('GCC_PAYMENT_ACCOUNT_IS_THE_SELLING_COMPANYS', false),
```

`TheGatewayAccountBelongsToTheSellingCompanyTest::test_the_switch_is_off_as_shipped` asserts the shipped value is falsy, so it
keeps passing once the key is declared as `false` and fails the day somebody defaults it to `true`.

**Also at merge:** one migration file, `2027_01_06_100000`, no conflict with the `2027_01_02_*` pair already on the branch.
`tests/Feature/Checkout` gains one class and `tests/Feature/Storefront` one; both are in directories CI's existing groups
already cover.

## Tracker lines, as they should now read

Not edited here — `docs/gcc/tracker/*.md` is outside this track's paths, and a track never moves a card to MET on its own
report (execution state: "Merge the track, push, read the run, fix"). Proposed, for the coordinator to apply after a green CI
run of the branch tip:

- line 556 → **CODED**, not MET, with evidence naming `LegalAcceptanceNamesItsSellingCompanyTest` (8 tests) for the legal half
  and stating plainly that marketing consents carry `storefront` and `legal_entity_id` nullable and that **no caller fills
  either**, so the line's own words ("marketing AND legal") are not met.
- line 605 → **CODED**, with evidence naming all three clauses and their proofs: `StorefrontCheckoutSingleCompanyTest` (26) for
  one selling company per checkout, `EachSubOrderCarriesItsOwnMarketTest` (5) for the record on each sub-order, and
  `TheGatewayAccountBelongsToTheSellingCompanyTest` (11) for the pick — and stating that the pick's refusal ships off pending
  owner question Q-D1-1, and that the stale evidence "No entity column or guard in any head" was false in all three clauses.

## Not done, and why

- **The marketing half of M-556** — outside this track's paths (`app/Services/Marketing/**`, two web controllers). Diagnosed
  exactly, above.
- **A market-readiness refusal for a mis-registered account** — needs Q-D1-1 answered first; building it either way would be
  guessing a settlement rule.
- **The refund path drawing on the company's account (D2-16)** — `PaymentAccountBinding`'s own docblock already names it as the
  one missing reader of the stamp. Out of scope for these two criteria and untouched.
- **No push, no pull request, no merge**, per the track's instructions. No production database was touched; every migration ran
  only against `pharmacy_testing_de`. No real payment call was made (`Http::preventStrayRequests()` in the payment test).
