# F-03: what the theme engine sends to each channel

Status: coded and tested on SQLite and on MySQL 8.0.46 (branch `gcc/p1-theme-channel-delivery`, stacked on PR 112; no MariaDB run). It needs **no migration and no switch**: it changes what a client that names the seller app's channel is sent, and the key of a cache entry. Nothing is MET until CI has run on a pushed head.

## What was wrong

A request names its channel in the `X-UI-Channel` header or the `channel` query (`web`, `customer_app` or `vendor_app`); a client that names none is placed by its platform. `ComponentCapabilityRegistry::servableToChannel()` has always said that the seller app (`vendor_app`) draws nothing, and its comment says why: "it renders no sections rather than being sent sections it cannot draw". The delivery never asked it. `ThemeDelivery::build()` tested the section rules (including the channels a section is restricted to) and then whether a **customer-app** build holds the type, so a request that named the seller app's channel was sent every section that was not restricted to another channel: the same page as the customer app.

Two more things followed from the same gap:

- The cache key of a delivered page held the platform, the device, the audience, the segments, the language, the engine and schema versions and the declared components, **not the channel**. The seller app and the customer app are both `platform: app`, so whichever asked first decided what the other was sent from the cache, for ten minutes.
- The checksum stored on a published version hashed the platforms and the audience of every section and **not its channels**, so a change of the channels of a section alone left the stored checksum as it was and an installed app was never told to fetch the page again (finding E07-F18).

## What it does now

| | Before | After |
|---|---|---|
| A request that names the seller app's channel | the page of the customer app | no section; the report says `delivered: 0` and names each type it would have sent, with the reason `no_renderer_exists_for_this_channel` |
| A live campaign for such a request | spliced into the page | not spliced (the overlay is not asked for) |
| A section restricted to `vendor_app` | sent to the seller app, and to nobody else | sent to nobody: nothing draws it (the publish validator already refuses a section restricted to channels that cannot be drawn) |
| Cache key of a delivered page | no channel | the channel |
| Stored checksum | no channels | the channels of a **restricted** section, in one order, as one more element of the section; a section that is not restricted (or whose channels are all unknown to the engine) keeps the checksum it always had, so republishing the same content costs no installed app a download |

The web and the customer app are sent what they were sent.

## What it does not do

- **F-03 criterion 1 (web and app published separately for one storefront) is not done.** A version is still one publish for every channel.
- The seller app **never calls** a theme endpoint today; that is pinned in the seller app by a source test of its own (K-18), not here.
- `/api/v1/theme/version` still answers a seller app client with the revision of the published theme: it is a revision number, not a section.
- The theme tokens of a page (`tokens`) are still part of a payload to any channel; they are not sections.

## Tests

`ThemeChannelDeliveryTest` (12): the seller app is sent nothing and is told why (header and query), the customer app and a client that names no channel are sent the page as before, a section built for the seller app is sent to nobody, a section built for the customer app is not sent to the seller app, a live campaign is spliced for the customer app and not for the seller app, neither app is served the other's cached page (both orders), a change of the channels alone moves the stored checksum, naming the same channels in another order (or twice) does not, a section that is not restricted keeps the checksum of the old formula (the old formula is written out in the test), an unknown channel is no restriction. Six mutations (no channel gate, no channel in the cache key, no channels in the checksum, channels unsorted, an empty restriction always hashed, the overlay still joining) each fail it.

`tests/Feature/Storefront` and `tests/Unit/Storefront` (871) pass on SQLite; the new class and the theme serving, publish and cache classes pass on MySQL 8.0.46 after one MySQL-only test repair on PR 112 (the order of the keys of a JSON audit context).

## Decisions that remain with the owner

1. Whether the seller app should one day be sent sections (a renderer in the seller app is a feature, not a flag).
2. Whether the version endpoint should answer the seller app with an empty revision.
