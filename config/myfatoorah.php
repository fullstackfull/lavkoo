<?php

/*
|--------------------------------------------------------------------------
| MyFatoorah (Saudi Arabia) — deployment-level defaults
|--------------------------------------------------------------------------
|
| Credentials are NEVER read from this file or from .env. They are entered in the admin panel
| (Payment Gateways → MyFatoorah) and stored encrypted in the `addon_settings` row whose
| `key_name` is `fatoorah`. Nothing here is a secret, so this file is safe to commit.
|
| The gateway is OFF until two independent switches are both on:
|   1. this file's `enabled` (environment kill switch, MYFATOORAH_ENABLED), and
|   2. the gateway's own `is_active` flag in the admin panel.
|
| The endpoint paths live here rather than in the client because MyFatoorah publishes v2 and v3
| side by side and only some v3 paths are documented. A path that moves can be corrected by
| deployment without a code change.
*/

return [

    /*
    | Global kill switch. With this off, no outbound MyFatoorah call is made — not a payment, not a
    | verification, not a connection test — and the gateway refuses to start new payments however the
    | admin panel is configured. Verification of payments that are ALREADY in flight still runs, so
    | switching this off cannot strand money (see MYFATOORAH_VERIFY_WHEN_DISABLED below).
    |
    | Read with FILTER_VALIDATE_BOOLEAN, not a (bool) cast: the cast turns the usual ways of writing
    | "off" (off, no, n, disabled, 0.0) into TRUE, so someone typing one in a hurry would switch it ON.
    | Here only true, 1, on and yes mean on; anything else, a typo included, means off.
    */
    'enabled' => filter_var(env('MYFATOORAH_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | A payment that has already been handed to MyFatoorah must stay verifiable even after the gateway
    | is switched off, or a customer who paid during the switch-off would never get their order. Reading
    | a payment's status moves no money, so this defaults to ON and should stay on.
    */
    'verify_when_disabled' => filter_var(env('MYFATOORAH_VERIFY_WHEN_DISABLED', true), FILTER_VALIDATE_BOOLEAN),

    /*
    | Second, independent switch for the reconciliation sweep, which runs on the platform's existing
    | Laravel scheduler (app/Console/ScheduleDefinition.php — no second cron entry is needed, and none
    | should be added). Both this and `enabled` above must be on before the sweep is registered at all,
    | so running the migrations starts nothing on a clock.
    |
    | Separate from `enabled` because they answer different questions: `enabled` is "may this deployment
    | talk to MyFatoorah", this is "should this deployment be the one sweeping". A staging copy of
    | production wants the first without the second — two hosts sweeping the same account would double
    | the provider reads to no purpose.
    */
    'schedule_enabled' => filter_var(env('MYFATOORAH_SCHEDULE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | API hosts. MyFatoorah gives every country its own production host and one shared sandbox; an
    | account's API token only works against the host of the country it was issued for.
    | Documented: https://docs.myfatoorah.com/docs/api-key
    */
    'hosts' => [
        'test' => env('MYFATOORAH_TEST_HOST', 'https://apitest.myfatoorah.com'),
        'live' => env('MYFATOORAH_LIVE_HOST', 'https://api-sa.myfatoorah.com'),
    ],

    /*
    | Only these hosts may ever be called, whatever the stored configuration says. A typo or a tampered
    | settings row cannot make the server post an API token to somewhere else.
    */
    'allowed_hosts' => [
        'apitest.myfatoorah.com',
        'api-sa.myfatoorah.com',
    ],

    /*
    | Endpoint paths. The v3 paths below are the ones MyFatoorah documents literally; refunds have both
    | a v3 and a v2 form and the account's permissions decide which one answers, so both are kept.
    */
    'endpoints' => [
        'create_payment'  => env('MYFATOORAH_PATH_CREATE_PAYMENT', '/v3/payments'),
        'payment_details' => env('MYFATOORAH_PATH_PAYMENT_DETAILS', '/v3/payments/{paymentId}'),
        'payment_methods' => env('MYFATOORAH_PATH_PAYMENT_METHODS', '/v3/payment-methods'),

        /*
        | Used ONLY by reconciliation, and only to discover a payment id for an invoice we created but
        | never heard back about (the customer closed the tab AND the webhook was given up on). Once a
        | payment id is found, the normal payment-details call does the actual verification, so the
        | amount, currency and reference checks always run against the one response shape.
        |
        | MyFatoorah's API reference lists "Get Invoice by InvoiceId" and "Get Invoice by
        | ExternalIdentifier" under v3 but does not publish their literal paths, so these are a best
        | reading of their scheme and are configurable for that reason. If an account answers 404 the
        | attempt is reported as needing a person rather than being guessed at — nothing is ever
        | settled on the strength of a path we are unsure of. Confirm both against the live account
        | before relying on unattended recovery of this case (see docs/integrations/myfatoorah.md).
        */
        'invoice_details' => env('MYFATOORAH_PATH_INVOICE_DETAILS', '/v3/invoices/{invoiceId}'),
        'invoice_by_reference' => env('MYFATOORAH_PATH_INVOICE_BY_REFERENCE', '/v3/invoices/external/{reference}'),
        'create_refund'   => env('MYFATOORAH_PATH_CREATE_REFUND', '/v3/refunds'),
        'refund_v2'       => env('MYFATOORAH_PATH_REFUND_V2', '/v2/MakeRefund'),
    ],

    /*
    | The only currency this integration accepts. The store is KSA-only and a mismatch between what we
    | charged and what MyFatoorah captured is treated as a failure to verify, never as a payment.
    */
    'currency' => env('MYFATOORAH_CURRENCY', 'SAR'),

    /*
    | HTTP behaviour. `connect_timeout` and `timeout` are seconds. A payment creation is never retried
    | automatically on a timeout — a second POST could create a second invoice — so only reads
    | (verification, payment methods) use `max_retries`.
    */
    'http' => [
        'connect_timeout' => (int) env('MYFATOORAH_CONNECT_TIMEOUT', 10),
        'timeout'         => (int) env('MYFATOORAH_TIMEOUT', 30),
        'max_retries'     => (int) env('MYFATOORAH_MAX_RETRIES', 2),
        'retry_base_ms'   => (int) env('MYFATOORAH_RETRY_BASE_MS', 400),
    ],

    /*
    | The app polls the status endpoint while a payment resolves, and each poll can cost one read at
    | MyFatoorah. This is the floor between two reads for the SAME attempt: inside it the stored status
    | is returned as-is. It protects MyFatoorah's (undocumented) rate limits even against requests
    | spread over many IPs, which a per-IP route limit cannot. Small enough not to slow the app's own
    | backoff, which starts at one second and widens.
    */
    'min_seconds_between_status_checks' => (int) env('MYFATOORAH_STATUS_MIN_INTERVAL', 3),

    /*
    | How long a hosted payment page stays valid. MyFatoorah expires the invoice itself; this is what we
    | send as PaymentExpiry and what reconciliation uses to stop chasing an attempt that can no longer
    | be paid.
    */
    'payment_expiry_minutes' => (int) env('MYFATOORAH_PAYMENT_EXPIRY_MINUTES', 60),

    /*
    | How many invoices one order may ever produce. A customer pressing "pay" against a provider that
    | keeps timing out would otherwise mint them without limit, and each one is a real invoice in the
    | merchant's dashboard. Ten is far above any honest retry count and well below a runaway.
    */
    'max_attempts_per_payment' => (int) env('MYFATOORAH_MAX_ATTEMPTS', 10),

    /*
    | How long an attempt whose create call never came back is left alone before we are willing to
    | conclude anything about it.
    |
    | This exists because "MyFatoorah has no invoice for this reference" and "MyFatoorah has not been
    | told about this reference YET" look identical from here: both are a 404. An attempt created a
    | second ago may have a POST still in flight, so treating that 404 as "nothing was created" and
    | starting a fresh payment is how two simultaneous checkouts both make an invoice — which is exactly
    | what the MySQL concurrency test caught.
    |
    | So nothing is concluded until the create call cannot still be running: the connect timeout, plus
    | the read timeout, plus a margin for the provider writing it down.
    */
    'attempt_in_doubt_grace_seconds' => (int) env(
        'MYFATOORAH_IN_DOUBT_GRACE',
        (int) env('MYFATOORAH_CONNECT_TIMEOUT', 10) + (int) env('MYFATOORAH_TIMEOUT', 30) + 30,
    ),

    /*
    | How long one process may hold the success-hook lease before another is allowed to take it. It has
    | to exceed the slowest honest run of that hook — which creates an order, decrements stock and books
    | a shipment with a courier — because taking the lease from a process that is still working is what
    | risks doing the work twice. A fencing token makes such an overrun detectable either way.
    */
    'hook_lease_minutes' => (int) env('MYFATOORAH_HOOK_LEASE_MINUTES', 15),

    /*
    | Reconciliation: how many pending attempts one sweep may verify, and how long an attempt is chased
    | before it is left alone. Keeps the sweep inside MyFatoorah's (undocumented) rate limits.
    */
    'reconciliation' => [
        'batch_size'          => (int) env('MYFATOORAH_RECONCILE_BATCH', 50),
        'max_age_hours'       => (int) env('MYFATOORAH_RECONCILE_MAX_AGE_HOURS', 72),
        'min_seconds_between' => (int) env('MYFATOORAH_RECONCILE_MIN_INTERVAL', 60),
        'sleep_ms_between'    => (int) env('MYFATOORAH_RECONCILE_SLEEP_MS', 250),
    ],

    /*
    | Webhook. MyFatoorah signs the body with HMAC-SHA256 (secret in binary, result base64) and sends it
    | in the `MyFatoorah-Signature` header. An unsigned or wrongly signed delivery is refused.
    | `require_signature` exists only so a sandbox can be debugged; it must be true in production and the
    | admin panel warns when it is not.
    | Documented: https://docs.myfatoorah.com/docs/webhook-v2
    */
    'webhook' => [
        'signature_header'  => 'MyFatoorah-Signature',
        'require_signature' => filter_var(env('MYFATOORAH_WEBHOOK_REQUIRE_SIGNATURE', true), FILTER_VALIDATE_BOOLEAN),
        'max_body_bytes'    => (int) env('MYFATOORAH_WEBHOOK_MAX_BODY', 65536),
        'tolerance_seconds' => (int) env('MYFATOORAH_WEBHOOK_TOLERANCE', 900),
        'ip_allowlist'      => array_values(array_filter(array_map('trim', explode(',', (string) env('MYFATOORAH_WEBHOOK_IPS', ''))))),
    ],
];
