<?php

use App\Http\Controllers\Api\FulfillmentWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Provider fulfillment webhooks (Torod Point / LogesTechs).
|
|   POST /api/fulfillment-webhooks/torod-point/{secret}/lastmile      delivery lifecycle
|   POST /api/fulfillment-webhooks/torod-point/{secret}/fulfillment   warehouse picked / packed
|
| Deliberately NOT in the `api` middleware group: that group records a per-request telemetry row, and the
| URL of this route carries a secret. Only a rate limit applies; authentication is in the controller.
*/
Route::post('fulfillment-webhooks/{provider}/{secret}/{type}', [FulfillmentWebhookController::class, 'receive'])
    ->whereIn('type', ['lastmile', 'fulfillment'])
    ->middleware('throttle:fulfillment-webhooks')
    ->name('fulfillment.webhooks.receive');
