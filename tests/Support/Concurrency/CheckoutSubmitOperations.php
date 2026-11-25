<?php

namespace Tests\Support\Concurrency;

use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Tests\Support\BuildsApiAddressSchema;

/**
 * What a worker process does in the checkout-submit concurrency test: one cash-on-delivery checkout through the REST v1 endpoint, with
 * the `Idempotency-Key` header the client sends, exactly as a phone that retried its own request would send it.
 *
 * It goes through the HTTP kernel rather than calling the order writer, because the header is what names the submit and the header only
 * exists on a request.
 */
final class CheckoutSubmitOperations
{
    /**
     * @param array{customer: int, key: string} $args
     * @return array{status: int, body: mixed}
     */
    public static function submit(array $args): array
    {
        (new class {
            use BuildsApiAddressSchema;

            public function prepare(): void
            {
                $this->useThrowawayPassportKeys();
                $this->useTaxIncludedPrices();
            }
        })->prepare();

        auth()->guard('api')->setUser(User::findOrFail((int) $args['customer']));
        auth()->shouldUse('api');

        $request = Request::create('/api/v1/customer/order/place', 'GET', server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer test-token',
            'HTTP_IDEMPOTENCY_KEY' => (string) $args['key'],
        ]);
        $response = app(HttpKernel::class)->handle($request);

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
    }
}
