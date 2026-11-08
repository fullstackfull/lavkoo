# Putting a product of the store ON a channel

One pipeline for every channel: a draft, what the channel itself says it requires, the gates, one call that creates the listing, and what is made of the answer. Built for
**Amazon** in this round (`putListingsItem`); Trendyol and Noon plug into the same two contracts without changing any of it.

**Nothing here is on by default.** `SALES_CHANNELS_LISTING_PUBLISH_ENABLED=false` ships off: a draft can be prepared, checked against the channel and read on screen, and nothing
leaves the host.

## The draft

`channel_listing_drafts` holds ONE intention per (account, product, variant) — the table's unique key — so asking twice edits the first instead of making a second listing. It
carries the CHANNEL's fields (`payload`), the channel's product type, what the channel said it requires, what our validation found, and, once sent, the channel's submission id
and what it made of it.

* **The product is never written.** A draft is the channel's side; `products` is read only (its `code` becomes the seller SKU when nobody chose one).
* **The SKU is the identity.** It is decided once and never changed afterwards: it is what makes a second call set the same listing instead of creating another.
* **A product that is already listed on the account** is an update from the start — the draft is linked to that listing row (`product_mapping_id`) when it is created.
* **Editing the fields sends the draft back to `draft`**: what the channel accepted before says nothing about the new values.

## What the channel requires

Asked of the channel, never invented (`DescribesListingRequirements`). For Amazon that is `getDefinitionsProductType`, and **only a `required` list the response itself carries
is used**: the full rules live in a JSON Schema behind a presigned link this version does not fetch, so where the response carries no such list the answer is **unknown**.

An unknown requirement is never read as "nothing is required". The draft stays a draft with `listing_requirements_unknown`, unless the owner has declared the list themselves
(`listing_requirements` on the account: `{"PRODUCT_TYPE": {"required": ["item_name", "brand"]}}`), which is then used and **recorded as the owner's, not the channel's**
(`requirements.source`). A channel's answer is trusted for `SALES_CHANNELS_LISTING_REQUIREMENTS_FRESH_HOURS` (24) before it is asked again.

Validation compares the payload with that list: a required field that is missing or empty, and a value outside the ones the channel says it accepts, are named by field.

## The gates, in order

| # | Gate | Refusal |
|---|---|---|
| 1 | the host switch | `listing_publishing_off` |
| 2 | the account is enabled | `account_off` |
| 3 | the `product_sync` capability | `product_sync_capability_off` |
| 4 | a recorded `product_writes` approval on the account | `product_writes_approval_missing` |
| 5 | the channel's connector can really create a listing | `channel_cannot_create_listings` |
| 6 | the draft is not already with the channel | `listing_already_submitted` / `listing_already_accepted` |
| 7 | every field the channel requires is there | `listing_fields_missing` (with the field names) / `listing_requirements_unknown` |
| 8 | a regulated product was asked for by a person | `regulated_product_needs_a_person` |
| 9 | no other listing of this product on this account | `listing_already_exists` |

**A pharmacy's catalogue (8).** The code cannot know which of the shop's categories need a licence, so it makes the OWNER's list the condition:
`SALES_CHANNELS_LISTING_REGULATED_CATEGORY_IDS` and `SALES_CHANNELS_LISTING_REGULATED_NAME_PATTERNS`. A product that matches is never published by anything automatic; a named
person may publish it, and that is recorded. **Both lists ship empty, so this gate stops nothing until the owner fills it** — and it is not a substitute for checking each
channel's own rules and the Saudi requirements for health products.

## The call, and what becomes of it

One `PUT` that SETS the listing (`requirements: LISTING`), so repeating it after a timeout cannot make a second listing at the channel. Then:

* **accepted** → the listing row is created here, `mapped` to the product, **with stock and price publishing OFF**: the listing exists at the channel and sells nothing until
  somebody has decided where its stock comes from and switched publishing on. This is the separation the owner asked for — a product can be on a channel before it has a
  fulfilment provider or any stock.
* **refused** → the channel's own reasons are kept on the draft (`channel_problems`) and no listing row is made. A corrected draft goes out again as the SAME listing.
* **neither** → the draft waits (`submitted`) and `follow()` asks the channel what became of it (`getListingsItem` with its issues). Nothing is ever sent a second time to find out.
* **a rate limit** is not a refusal of the listing: the draft stays ready and the next run sends it.
* **any other failure** keeps only the KIND of failure (`listing_write_failed`); what a channel says about a product can repeat the whole payload back, so its words are not stored.

Every submission, acceptance and refusal is an audit line (`sales_channel.listing_submitted|accepted|rejected`) with the person who asked.

## From the command line

```bash
php artisan channels:listings                      # every draft and its state
php artisan channels:listings --check              # ask each channel what it requires; writes nothing to any channel
php artisan channels:listings --follow             # what became of the submissions the channels are still working on
php artisan channels:listings --publish=12 --admin=4   # send one draft; a person must say which
```

A sweep never publishes by itself: `--publish` needs a draft id and an admin id, because a regulated product may only be published by a named person.

## What is not built yet

* **No admin screen.** The pipeline, the gates and the command are built; the unified product screen (choose channels, complete the fields, preview, send, watch the approval)
  is the next slice.
* **Trendyol and Noon** do not implement `CreatesListings` yet, so they refuse with `channel_cannot_create_listings` instead of pretending. Trendyol also needs its category,
  brand and attribute lookups; Noon has no connector at all.
* **Amazon's full product-type schema** (the presigned link) is not fetched, and `catalog_items` (ASIN matching) and `JSON_LISTINGS_FEED` (bulk) are still not built.
* **Images** are not uploaded: a channel that needs a hosted image URL takes what the payload carries.
* Nothing here was run against a real seller account.
