<?php

use App\Http\Controllers\Api\MyFatoorahWebhookController;
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
