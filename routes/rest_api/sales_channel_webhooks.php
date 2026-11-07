<?php

use App\Http\Controllers\Api\TrendyolWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Sales channel webhooks.
|
|   POST /api/sales-channel-webhooks/{account}   order-package events for a Trendyol account; Trendyol sends the account's webhook key in the `x-api-key` header
|
| The address deliberately does not contain the channel's name: Trendyol refuses a webhook address containing "Trendyol", "Dolap" or "localhost".
| The account id is not a secret and the key is: an unknown account and a wrong key are the same flat 401. An id too long to be an id never reaches the controller.
*/
Route::post('sales-channel-webhooks/{account}', [TrendyolWebhookController::class, 'receive'])
    ->where('account', '[0-9]{1,18}')
    ->middleware('throttle:sales-channel-webhooks')
    ->name('sales_channels.webhooks.receive');
