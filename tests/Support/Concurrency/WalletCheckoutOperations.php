<?php

namespace Tests\Support\Concurrency;

use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Tests\Support\BuildsApiAddressSchema;

/** What a worker process does in the wallet checkout concurrency tests: one REST v1 wallet order, through the HTTP kernel, as the given customer. */
final class WalletCheckoutOperations
{
    /**
     * @param array{customer: int} $args
     * @return array{status: int, body: mixed}
     */
    public static function placeApiWalletOrder(array $args): array
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

        $request = Request::create('/api/v1/customer/order/place-by-wallet', 'GET', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-token']);
        $response = app(HttpKernel::class)->handle($request);

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
    }
}
