# Paymera gateway

Code: `app/Http/Controllers/Payment_Methods/PaymeraController.php`. Routes: `paymera.pay` and `paymera.callback` in `routes/web/routes.php`.
Tests: `tests/Feature/Payment/PaymeraCallbackTest.php`.

## Configuration

| Setting | Where | Notes |
|---|---|---|
| `PAYMERA_SECRET_KEY` | Environment (`config('services.paymera.secret_key')`) | Value of the `X-Secret-Key` header sent to Paymera. Required. Never commit it. |
| Terminal ID and token | Admin payment settings (`addon_settings`, key `paymera`) | Unchanged. |

When `PAYMERA_SECRET_KEY` is empty, the gateway counts as not configured: no request goes to Paymera and the payment fails.

## Before deploying this change

1. An authorized person sets `PAYMERA_SECRET_KEY` on each environment, from the secret store.
2. Run `php artisan config:clear`, or `php artisan config:cache` where the server caches configuration.
3. Make one test payment on staging and check that the status request succeeds.

If step 1 is skipped, Paymera's status check cannot run. A customer who already paid at Paymera then ends the callback with an unpaid order.

## Secret rotation

The header value used before this change was committed to this repository, so it is in git history and must be treated as exposed. An authorized person rotates it with Paymera and sets the new value through `PAYMERA_SECRET_KEY`. Rotation is not part of this change, and this change does not use or change any production key.

## Logging

- The callback writes no request data, URL or payload to any file or log.
- The only Paymera log line is `Paymera success hook failed`, written when the success hook throws. Its context holds `payment_id`, the exception class, the file and the line. It holds no message and no payload.
- The debug files `storage/logs/paymera_callback_test.txt` and `storage/logs/paymera_hook_test.txt` were written by earlier code and may hold personal data from past callbacks. Deleting them is an operator action on each server, after a retention review. This change does not delete them.

## Callback trust model (unchanged)

The callback is unauthenticated, so the controller does not trust its contents. It asks Paymera for the status (`GET /api/get-payment-status/{paymeraPaymentId}`) and acts only on that answer:

| Status | Effect |
|---|---|
| `A` accepted | Marks the payment paid once, then runs the success hook. |
| `P` pending | No hook. The customer returns to the `fail` page. |
| `F`, `C` failed or cancelled | Runs the failure hook. |
| anything else | Runs no hook. |

## Known issue outside this change

`app/Http/Controllers/Payment/PaymeraController.php` is a duplicate with the same class name. It has `dd()` calls and the same header value written in source. PR #3 deletes it. Until that PR merges, the duplicate still carries the old value.
