<?php

namespace Tests\Unit\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus as S;
use App\Services\Fulfillment\FulfillmentAggregator as A;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FulfillmentAggregatorTest extends TestCase
{
    /** @param list<S> $statuses */
    #[DataProvider('policy')]
    public function test_parent_status_policy(array $statuses, string $expected): void
    {
        $this->assertSame($expected, A::statusFor($statuses));
    }

    /** @return iterable<string, array{list<S>, string}> */
    public static function policy(): iterable
    {
        yield 'no provider shipments' => [[], A::NONE];
        yield 'single delivered' => [[S::Delivered], A::DELIVERED];
        yield 'every shipment delivered' => [[S::Delivered, S::Delivered], A::DELIVERED];
        yield 'one of two delivered is NOT delivered' => [[S::Delivered, S::InTransit], A::PARTIALLY_DELIVERED];
        yield 'delivered plus one still processing' => [[S::Delivered, S::Processing], A::PARTIALLY_DELIVERED];
        yield 'delivered plus a failed dispatch needs attention' => [[S::Delivered, S::Failed], A::ATTENTION_REQUIRED];
        yield 'both on the road' => [[S::InTransit, S::OutForDelivery], A::SHIPPED];
        yield 'one on the road, one preparing' => [[S::InTransit, S::Submitted], A::PARTIALLY_SHIPPED];
        yield 'warehouse work is processing, not delivery' => [[S::Processing, S::Processing], A::PROCESSING];
        yield 'just accepted' => [[S::Submitted], A::PROCESSING];
        yield 'nothing sent yet' => [[S::Pending, S::Held], A::NOT_STARTED];
        yield 'all cancelled' => [[S::Cancelled, S::Cancelled], A::CANCELLED];
        yield 'a cancelled one is no longer required' => [[S::Cancelled, S::Delivered], A::DELIVERED];
        yield 'cancelled plus in transit' => [[S::Cancelled, S::InTransit], A::SHIPPED];
        yield 'all returned' => [[S::Returned, S::Returned], A::RETURNED];
        yield 'delivered and returned' => [[S::Delivered, S::Returned], A::PARTIALLY_RETURNED];
        yield 'failed delivery attempt' => [[S::InTransit, S::DeliveryFailed], A::ATTENTION_REQUIRED];
        yield 'postponed delivery' => [[S::DeliveryPostponed], A::ATTENTION_REQUIRED];
        yield 'unknown outcome after a timeout' => [[S::ReconcilePending, S::Delivered], A::ATTENTION_REQUIRED];
        yield 'provider-reported issue' => [[S::NeedsAttention], A::ATTENTION_REQUIRED];
        yield 'partially delivered by the courier' => [[S::PartiallyDelivered], A::ATTENTION_REQUIRED];
    }
}
