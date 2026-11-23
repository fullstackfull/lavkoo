<?php

namespace Tests\Support\Concurrency;

use App\Events\AddFundToWalletEvent;
use App\Models\PaymentRequest;
use Illuminate\Support\Facades\Event;

/** What the wallet top-up concurrency test runs in each process. */
final class WalletTopUpOperations
{
    /**
     * Hands one confirmed payment to the top-up, as the callback of a gateway does.
     *
     * @param array{payment_request: string} $args
     */
    public static function handOver(array $args): string
    {
        Event::fake([AddFundToWalletEvent::class]);

        add_fund_to_wallet_success(PaymentRequest::findOrFail($args['payment_request']));

        return 'handed over';
    }
}
