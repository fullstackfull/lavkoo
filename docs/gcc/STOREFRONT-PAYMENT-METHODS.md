# Per-storefront payment methods (D2-V2)

**Status: written, switched off, never run anywhere but in the test suite.** Nothing here has been run against the live
server, a live gateway or live data. Turning the switch on is the owner's decision and needs the reading below first.

## What it is

The platform switches each of three ways to pay on and off for all six markets at once, with a business setting of the same
name: `cash_on_delivery`, `digital_payment`, `offline_payment`. A country can now switch any of the three **off for its own
storefront**, and can do nothing else.

A storefront can only switch **off**. A method the platform has off stays off in every storefront, whatever a country lists.

## Where it is

| | |
|---|---|
| The switch | `config/gcc.php` → `storefronts.payment_methods.enabled` (`GCC_STOREFRONT_PAYMENT_METHODS`), **false** as it ships |
| The lists | `config/gcc.php` → `storefronts.payment_methods.switched_off`, e.g. `'KW' => ['offline_payment']`; empty as it ships |
| The one place that knows | `App\Services\Storefront\StorefrontPaymentMethods` |
| How every reader is told | `getWebConfig()` asks that service for these three names (`app/Utils/settings.php`), so the checkout, the account pages and the answer to the apps read "off" with no change of their own |
| How a request is refused | the route middleware `storefront.method:<method>` (`App\Http\Middleware\EnsureStorefrontOffersPaymentMethod`), before the handler |
| The message | `this_payment_method_is_not_available_in_this_storefront`, in `en`, `sa` and `ae` |

## What a customer meets in a storefront that switched a method off

- The checkout and the account pages do not offer it, because they read the setting as off.
- `GET /api/v1/config` answers that method as off, so the apps do not offer it. The rest of the answer is unchanged.
- A request that asks for it anyway is refused **before anything is written**: no order, no payment row, no wallet movement.
  An app is answered **422** with a stable code and the message in the language it asked for; a browser is sent back to the
  page with the message on it.
- The wallet is not one of the three and is not touched here; it has its own setting.

## What is NOT changed

- **The platform's own settings pages.** The admin panel is served from no storefront, so it reads and writes the platform's
  own values exactly as before. A storefront's list is configuration, not a setting a screen edits.
- **A gateway's own readiness.** It is judged against the platform (`StorefrontPaymentMethods::platformSetting`), because it
  is also asked on the gateway's own page, which names no storefront, for a payment another storefront started.
- **Queues, console commands and the seller APIs**: no storefront, so the platform's settings as always.
- **Saudi Arabia**: with the switch off, which is how it ships, every storefront reads the platform's settings untouched, byte
  for byte. The default storefront can switch a method off for itself like any other, but only once the switch is on and only
  if it is listed.

## Before turning it on

1. Decide, per country, which of the three that country may not offer, and have it confirmed by whoever answers for what is
   lawful there. The list in `config/gcc.php` is configuration, not a statement of law.
2. Check that each method is on at platform level for the countries that should offer it: a storefront cannot switch one on.
3. Remember that the apps read the three flags from `GET /api/v1/config`. An app that has cached a config from another
   storefront must ask again after a storefront change; the customer app does that already (J-04).
4. The wallet top-up goes through `digital_payment`. Switching that off in a country also stops top-ups there.

## Tests

`tests/Feature/Storefront/StorefrontPaymentMethodsTest.php` (the service, the middleware, the message, the shipped
configuration), `StorefrontPaymentMethodCheckoutTest.php` (every writer: the API order, the offline order, the gateway start,
the wallet top-up, the due of an edited order, and the website's own three posts) and `StorefrontPaymentMethodConfigAnswerTest.php`
(the answer to the apps, including that it is byte for byte the platform's own answer while nothing is switched off).
