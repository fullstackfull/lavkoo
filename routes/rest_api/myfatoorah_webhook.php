<?php

use App\Http\Controllers\Api\MyFatoorahWebhookController;
use App\Http\Controllers\Api\PaymentAccountWebhookController;
use Illuminate\Support\Facades\Route;

/*
| MyFatoorah webhook (V2).
|
|   POST /api/myfatoorah/webhook
|
| This is the URL to register in the MyFatoorah portal (Integration Settings → Webhook), and it is also
| sent per invoice as IntegrationUrls.Webhook so a freshly configured account notifies us even before
| anyone pastes it into the dashboard.
|
| Deliberately NOT in the `api` middleware group: that group records a per-request telemetry row, and
| these bodies carry a cardholder name and a masked card number. Authentication is the
| `MyFatoorah-Signature` header, checked in the controller. Only a rate limit applies here.
*/
Route::post('myfatoorah/webhook', [MyFatoorahWebhookController::class, 'receive'])
    ->middleware('throttle:myfatoorah-webhooks')
    ->name('myfatoorah.webhook');

/*
| A company's own notification address (D2-09).
|
|   POST /api/payments/account/{account}/webhook
|
| The token in the path names ONE gateway account of ONE company, and the delivery is authenticated with that account's own secret. Nothing is answered
| here until a company has an account with a token (payment_gateway_accounts), so while per-company accounts are off this address resolves no account
| and refuses every request; the platform-wide address above keeps taking every notification exactly as it does today.
|
| Outside the `api` middleware group for the same reason as the address above: that group records a telemetry row per request and these bodies carry a
| cardholder name and a masked card number.
*/
Route::post('payments/account/{account}/webhook', [PaymentAccountWebhookController::class, 'receive'])
    ->middleware('throttle:myfatoorah-webhooks')
    ->where('account', '[A-Za-z0-9_-]{1,64}')
    ->name('payments.account.webhook');
