# ZIP code compatibility

The `zip` column (`shipping_addresses.zip`, the `zip` key inside every order snapshot, `users.zip`) is **kept everywhere and never altered by this feature**.

## What the ZIP is used for today

Three `business_settings` keys control everything; none is created by a migration or seeder, so *absent means off*:

| Key | Effect |
|---|---|
| `delivery_zip_code_area_restriction` | ZIP allow-list (`delivery_zip_codes`): with it **on**, an address without a listed ZIP is refused when it is saved (checkout, account address book, REST address add/update, REST order placement) |
| `delivery_country_restriction` | country allow-list (`delivery_country_codes`) compared with the English country name (`Saudi Arabia`) |
| `zip_code_field_status` | only shows/hides the ZIP box in three storefront views |

No shipping price, no report/export and neither courier integration reads the ZIP. Invoices, order pages and the REST JSON show it.

## Rules of the unified address

1. **Never derived.** The ZIP is written only from what a person typed for a country other than Saudi Arabia (or an optional postal-code box). It is never copied from, padded from or guessed
   from the National Address, the building number or the city. No `00000`, no `N/A`.
2. **Never cleared by accident.** An edit that does not mention a ZIP keeps the stored one. Old addresses keep their ZIP when they are completed with a region, city and National Address.
3. **Always present in snapshots.** `AddressSnapshot::build()` keeps the `zip` key (null when absent), so every reader that expects it keeps working; readers are null-safe and print the ZIP
   line only when there is one.
4. **Country stays as before.** Saudi addresses keep writing `country = 'Saudi Arabia'`; input is recognised as `SA`, `KSA`, `Saudi Arabia` or the Arabic spellings.

## Staged path for ZIP-based rules

| Stage | State | What happens |
|---|---|---|
| **S0 — ship** | unified mode `off` (default) | nothing changes: old form, old validation, old snapshot keys |
| **S1 — gate and pause** | switching the mode to `soft`/`strict`; and the ZIP restriction being turned on later | the switch is **refused** while `delivery_zip_code_area_restriction` is ON: a ZIP allow-list cannot be evaluated for an address that carries only a National Address. If the restriction is turned on **after** the mode was chosen, the structured address is **paused**: `AddressSchema::mode()` answers `off` while the restriction is on, so the store keeps the old address with its ZIP code and keeps judging it exactly as before; turning the restriction off brings the chosen mode back (nothing was changed or lost). The admin overview and the delivery-zone page say so and show the number of saved Saudi addresses without a ZIP |
| **S2 — area rules** | future, needs the owner's decision | a region/city based "deliverable" rule edited in the address admin (default off) evaluated for addresses with ids, while the ZIP list keeps being evaluated for other countries and old addresses |
| **S3 — import** | future, needs an official postal-code → city dataset | an optional, reviewed import of the ZIP list into area rules; never automatic |

Why S1 instead of silently ignoring the restriction: a store that restricts delivery by ZIP would otherwise start accepting addresses the rule was meant to reject. Refusing the switch, and pausing the
feature when the restriction appears later, keeps the ZIP rule authoritative without ever locking Saudi customers out of checkout (refusing every ZIP-less Saudi address while both are on would stop all Saudi orders after a single settings change).

For non-Saudi addresses the ZIP box stays visible and the ZIP rule keeps working exactly as before.
