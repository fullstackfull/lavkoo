<?php

use App\Http\Controllers\Api\DeliverySyriaWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Delivery Syria — inbound status webhook (optional courier integration).
|
| Mapped under the `api` prefix by RouteServiceProvider, so the courier's spec URL resolves exactly:
|   POST /api/delivery-syria/orders/update-status
|
| Authenticated by the `deliverysyria_auth` middleware (X-Platform: deliverysyria + Bearer webhook
| token), not Passport — the caller is the courier, not a platform user. The route does nothing unless
| the admin has enabled the integration and set a webhook token.
*/
Route::group(['prefix' => 'delivery-syria', 'middleware' => ['deliverysyria_auth']], function () {
    Route::post('orders/update-status', [DeliverySyriaWebhookController::class, 'updateStatus']);
});
