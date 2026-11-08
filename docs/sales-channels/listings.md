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

## A submission is not a listing

A channel that answers "accepted" has **taken the submission**. Amazon's `putListingsItem` answers `ACCEPTED` to something it has merely queued, and the listing can still be
refused, held or left incomplete afterwards. So:

* a write that is taken leaves the draft **`submitted`**, with the submission id, and **creates no listing row here**;
* `follow()` asks what the channel **holds** (`getListingsItem` with `summaries,issues`). Only that answer creates the listing row, and only when a summary for our SKU exists
  and no issue of severity ERROR is attached. An `ERROR` makes it `rejected` with the channel's own words; no summary yet, a 404, a failed call or a rehearsal leave it waiting;
* what the channel says about the listing is kept as it said it (`channel_listing_statuses`: `BUYABLE`, `DISCOVERABLE`), so that **"the listing exists" is never read as "the
  listing is sellable"**;
* and because this pipeline never publishes an offer, a channel that reports the listing **BUYABLE** is a surprise, not a success: it is recorded as a problem on the draft
  (`the_channel_reports_this_listing_buyable_although_no_offer_of_ours_was_published`) for a person to look at — a quantity came from somewhere else.

## Create with PUT, change with PATCH

They are different calls, and the difference matters: a `PUT` SETS the whole listing, so used on a listing that exists it would replace it and every attribute we were never
told about would be gone.

* **Create** (`putListingsItem`) is used **only** when the channel holds nothing for the SKU, and with the product-only requirements mode
  (`listing_requirements_mode`, default `LISTING_PRODUCT_ONLY` — UNVERIFIED for the KSA product types, which is why it is an account setting).
* **Change** (`patchListingsItem`, `amazon.listings_patch_content`) sends **only the fields whose value differs** from what the channel was last given (`published_payload` on
  the draft), each as a `replace`. Nothing is ever removed: a field the draft stops naming keeps the value the channel holds, because a partial update must not become a way of
  emptying a listing.

## No offer ever leaves here

A draft may carry a price because a person filled in a form. It never reaches a channel from this pipeline: every attribute the connector names in `offerAttributes()`
(`purchasable_offer`, `list_price`, `fulfillment_availability`, `price`, `quantity`, …, configurable per channel) is **withheld** and written on the draft
(`withheld_attributes`), so nobody believes it was sent. A quantity and a price reach a channel only through stock and price publishing, which are capabilities of their own
with approvals of their own. A draft that holds nothing BUT an offer sends nothing at all (`listing_has_nothing_to_send_that_is_not_an_offer`).

This does not depend on `inventory_publish` being off — that is the second lock, not the first.

## Whose SKU is it

Before anything is written, our own records and then the channel are asked:

* a listing of ours with that key that stands for **another product** refuses the attempt (`listing_key_belongs_to_another_product`) — and the channel is not even called;
* any other listing of this product on the account refuses it (`listing_already_exists`);
* the channel is asked what it **holds** for the SKU. If it holds a listing and we have no record linking it to this product, the attempt is refused
  (`sku_already_listed_at_the_channel_and_not_linked_here`): replacing it would overwrite a listing we know nothing about, and adopting it would be a guess. A person links it
  (the listing sync and the products screen are for that) and then the draft updates it;
* a read that **failed** is not "the channel holds nothing": it is `could_not_read_what_the_channel_holds` and nothing is written (`ListingSubmission::UNKNOWN`). Only a 404, or
  an answer with no summary, makes a create safe;
* and when the channel confirms a listing whose key already belongs to another product of ours, the row is **not** re-pointed: the draft keeps the problem instead.

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
| 8 | the pharmacy gate: classified, and cleared if the owner's rules name it | `regulated_classification_missing` / `regulated_product_needs_recorded_clearance` |
| 9 | our own records do not use the key for another product, and no other listing of this product | `listing_key_belongs_to_another_product` / `listing_already_exists` |
| 10 | the channel does not already hold a listing for the SKU that we have no record of | `sku_already_listed_at_the_channel_and_not_linked_here` / `could_not_read_what_the_channel_holds` |
| 11 | there is something to send that is not an offer | `listing_has_nothing_to_send_that_is_not_an_offer` |

**A pharmacy's catalogue (8).** The code cannot know which of the shop's categories need a licence, so the OWNER's rules are the condition — and the gate **fails closed**:

* `SALES_CHANNELS_LISTING_REGULATED_MODE` is `off` as it ships: the gate stops nothing. That is a deliberate choice, not an accident of empty lists.
* With `strict`, a product **nobody has classified** (no category recorded at all) is refused: `regulated_classification_missing`. "We could not tell" is not "it is fine".
* A product the rules name — category, brand or name pattern, globally or for that channel only (`listing.regulated.channels.<channel>`) — needs a **recorded clearance** on
  the draft: the reference of the licence, registration or decision, and the person who recorded it. **An `--admin=` on a command line is not a clearance**, and neither is a
  request that merely carries an admin id (`regulated_product_needs_recorded_clearance`).
* The person recording it must hold the `sales_channels` module, checked against their role (`regulatory_clearance_needs_the_sales_channels_permission`), and a clearance
  without a reference is refused.

None of this is a substitute for checking each channel's own rules and the Saudi requirements for health products; it is the lock that stops the code from deciding.

## When the listing is recorded here

Only when the channel says it holds one (above). The row is `mapped` to the product — we asked for it, so there is nothing to guess — and it starts **with stock and price
publishing OFF**: the listing exists at the channel and sells nothing until somebody has decided where its stock comes from and switched publishing on. This is the separation
the owner asked for: a product can be on a channel before it has a fulfilment provider or any stock.

* **a rate limit** is not a refusal of the listing: the draft stays ready and the next run sends it;
* **any other failure** keeps only the KIND of failure (`listing_write_failed`); what a channel says about a product can repeat the whole payload back, so its words are not stored.

Every submission, update, refusal, confirmation and clearance is an audit line (`sales_channel.listing_submitted|listing_update_submitted|listing_rejected|listing_live|
listing_regulatory_clearance_recorded|listing_key_taken_at_the_channel`) with the person who asked.

## From the command line

```bash
php artisan channels:listings                      # every draft, its state and what the channel says about it
php artisan channels:listings --check              # ask each channel what it requires; writes nothing to any channel
php artisan channels:listings --follow             # ask the channels what they HOLD for the drafts they are still working on
php artisan channels:listings --publish=12 --admin=4   # send one draft; a person must say which
```

```bash
php artisan channels:listings --clear-regulated=12 --admin=4 --reference=SFDA-12345
```

A sweep never publishes by itself: `--publish` needs a draft id and an admin id. Recording a clearance sends nothing and needs the reference and an admin who holds the module.

## On the product form

The in-house product form gains a **Sales channels** card (`_sales-channels-product.blade.php`, read only): for every channel account, whether the product is listed, the draft's
state and what the channel is missing, the listing key, what is being published (and the plain words *nothing is published yet* when neither stock nor price is), and when it was
last published. Opening the page calls no channel and reads no requirement, so the form never waits on a marketplace; before the module's migrations have run the card is not
there at all.

## What is not built yet

* **No actions on the screen yet.** The card shows the truth, the gates and the command do the work; completing the fields, previewing and sending from the product page (with its
  own permission and audit) is the next slice.
* **Trendyol and Noon** do not implement `CreatesListings` yet, so they refuse with `channel_cannot_create_listings` instead of pretending. Trendyol also needs its category,
  brand and attribute lookups; Noon has no connector at all.
* **Amazon's full product-type schema** (the presigned link) is not fetched, and `catalog_items` (ASIN matching) and `JSON_LISTINGS_FEED` (bulk) are still not built.
* **Images** are not uploaded: a channel that needs a hosted image URL takes what the payload carries.
* Nothing here was run against a real seller account.
