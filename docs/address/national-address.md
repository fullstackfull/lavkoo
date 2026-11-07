# National Address (العنوان الوطني) in the unified address

## What the store validates

The customer-facing "ZIP code" is replaced, for Saudi addresses, by the **National Address short address** issued by Saudi Post (SPL): **four letters followed
by four digits** (examples published by SPL and ZATCA: `RAGI2929`, `RHMA3184`).

Source: SPL's own page, <https://splonline.com.sa/en/national-address-1/> — "A short address that is easy to memorize, consists of 4 letters and 4 numbers"; it also
lists the long-form components: building number (4 digits), street, secondary number (4 digits), district, postal code (5 digits), city. A customer finds the short address
in SPL Online, Maha, Tawakkalna, Sehhaty or Absher.

What the code enforces (`App\Services\Address\NationalAddress`, shape in `config/address.php`):

| Input | Result |
|---|---|
| `RAGI2929`, `ragi2929`, `RAGI 2929`, `RAGI-2929` | accepted, stored as `RAGI2929` |
| Arabic-Indic or Persian digits (`RHMA٣١٨٤`), full-width characters, direction marks | normalised, then accepted |
| three or five letters, three or five digits, digits first, Arabic letters, anything else | refused |

What it does **not** claim: SPL does not publish which letters are in use, nor any check digit, so there is **no invented regex** beyond the published shape, and a
well-formed code is *not* proof that the address exists. Existence can be confirmed against SPL's National Address API (a free key from SPL is needed: the Starter plan
is rate limited); that is an integration point, deliberately not built, and is listed in the open decisions.

## Where it is stored and shown

* `shipping_addresses.national_address` (own column, indexed), always the canonical form.
* `orders.shipping_address_data` / `billing_address_data` — the order's address snapshot carries `national_address` together with the region and city names at that moment
  (`address_schema = unified`), so an order never changes when the address book is edited afterwards.
* Shown in: the customer's order details and invoice, the admin and vendor order pages and invoices, the admin customer page, the delivery-man views, and (as text only, never as a
  structured field) wherever a courier needs it and its contract says so — see below.

## Never derived, never faked

The legacy `zip` column is untouched. It is written **only** from what a user typed. It is never filled from the National Address, never defaulted to `00000`/`N/A`, and an edit
that does not mention a ZIP never clears an existing one. ZIP-based delivery restrictions keep working for every address that has a ZIP; see `docs/address/zip-compatibility.md`.

## Couriers

* **Torod Point (LogesTechs) Fulfillment API v5** — `receiverAddress` is `{villageId, cityId, regionId, addressLine1}`. It documents **no** national-address field, so none is sent.
* The LogesTechs **Lastmile v2 "Create Shipment"** call documents `address.nationalAddress`; this store never calls that endpoint for fulfillment orders (it would create a second
  parcel), so that field is unused.
* If a provider's written contract later confirms a field, it is added to that provider's payload builder only — the central address already carries the value.
