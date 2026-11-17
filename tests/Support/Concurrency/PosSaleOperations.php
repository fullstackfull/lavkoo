<?php

namespace Tests\Support\Concurrency;

use App\Http\Controllers\RestAPI\v3\seller\POSController;
use App\Models\Seller;
use Illuminate\Http\Request;
use Tests\Support\BuildsApiAddressSchema;

/** What a worker process does in the seller app's point-of-sale concurrency test: one wallet sale through the controller, with an Idempotency-Key. */
final class PosSaleOperations
{
    /**
     * @param array{seller: int, customer: int, key: string, lines: list<array<string, mixed>>, paid: float} $args
     * @return array{status: int, body: mixed}
     */
    public static function placeSellerSale(array $args): array
    {
        (new class {
            use BuildsApiAddressSchema;

            public function prepare(): void
            {
                $this->useTaxIncludedPrices();
            }
        })->prepare();

        $input = [
            'customer_id' => $args['customer'], 'cart' => [], 'modified_cart' => $args['lines'], 'fulfillment' => 'instant',
            'payment_method' => 'wallet', 'paid_amount' => $args['paid'],
        ];
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $args['key']];
        $request = Request::create('/api/v3/seller/pos/place-order', 'POST', [], [], [], $server, (string) json_encode($input));
        $request['seller'] = (new Seller())->forceFill(['id' => $args['seller']]);
        $response = app(POSController::class)->place_order($request);

        return ['status' => $response->getStatusCode(), 'body' => $response->getData(true)];
    }
}
