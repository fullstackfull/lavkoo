<?php

namespace Tests\Unit\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Services\Fulfillment\FulfillmentStateMachine;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FulfillmentStateMachineTest extends TestCase
{
    private FulfillmentStateMachine $machine;

    protected function setUp(): void
    {
        $this->machine = new FulfillmentStateMachine();
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse($time);
    }

    public function test_a_newer_event_moves_the_shipment_forward(): void
    {
        $this->assertSame(
            FulfillmentStateMachine::APPLY,
            $this->machine->decide(FulfillmentStatus::InTransit, FulfillmentStatus::OutForDelivery, $this->at('2026-01-01 10:00'), $this->at('2026-01-01 11:00')),
        );
    }

    public function test_an_event_older_than_the_newest_applied_one_is_stale_whatever_its_status(): void
    {
        $this->assertSame(
            FulfillmentStateMachine::STALE,
            $this->machine->decide(FulfillmentStatus::OutForDelivery, FulfillmentStatus::InTransit, $this->at('2026-01-01 11:00'), $this->at('2026-01-01 10:00')),
        );
        // ...even one that would be "forward" in rank:
        $this->assertSame(
            FulfillmentStateMachine::STALE,
            $this->machine->decide(FulfillmentStatus::InTransit, FulfillmentStatus::OutForDelivery, $this->at('2026-01-01 11:00'), $this->at('2026-01-01 10:59')),
        );
    }

    public function test_a_timestamped_regression_is_allowed_when_newer_because_couriers_retry(): void
    {
        $this->assertSame(
            FulfillmentStateMachine::APPLY,
            $this->machine->decide(FulfillmentStatus::DeliveryFailed, FulfillmentStatus::OutForDelivery, $this->at('2026-01-01 10:00'), $this->at('2026-01-01 15:00')),
        );
    }

    public function test_without_a_timestamp_an_event_may_only_move_forward(): void
    {
        $this->assertSame(
            FulfillmentStateMachine::STALE,
            $this->machine->decide(FulfillmentStatus::OutForDelivery, FulfillmentStatus::Processing, null, null),
        );
        $this->assertSame(
            FulfillmentStateMachine::APPLY,
            $this->machine->decide(FulfillmentStatus::Processing, FulfillmentStatus::InTransit, null, null),
        );
    }

    public function test_the_same_status_is_a_duplicate(): void
    {
        $this->assertSame(
            FulfillmentStateMachine::SAME,
            $this->machine->decide(FulfillmentStatus::InTransit, FulfillmentStatus::InTransit, $this->at('2026-01-01 10:00'), $this->at('2026-01-01 12:00')),
        );
    }

    #[DataProvider('terminalStates')]
    public function test_a_terminal_shipment_is_never_overwritten(FulfillmentStatus $terminal): void
    {
        foreach ([FulfillmentStatus::InTransit, FulfillmentStatus::OutForDelivery, FulfillmentStatus::Cancelled, FulfillmentStatus::Delivered] as $incoming) {
            if ($incoming === $terminal) {
                continue;
            }
            $this->assertSame(
                FulfillmentStateMachine::TERMINAL,
                $this->machine->decide($terminal, $incoming, $this->at('2026-01-01 10:00'), $this->at('2026-01-02 10:00')),
                "{$terminal->value} must not become {$incoming->value}",
            );
        }
    }

    /** @return iterable<string, array{FulfillmentStatus}> */
    public static function terminalStates(): iterable
    {
        yield 'cancelled' => [FulfillmentStatus::Cancelled];
        yield 'returned' => [FulfillmentStatus::Returned];
    }

    public function test_a_delivered_parcel_can_still_be_returned_by_the_recipient(): void
    {
        $this->assertSame(
            FulfillmentStateMachine::APPLY,
            $this->machine->decide(FulfillmentStatus::Delivered, FulfillmentStatus::Returned, $this->at('2026-01-01 10:00'), $this->at('2026-01-03 10:00')),
        );
        $this->assertSame(
            FulfillmentStateMachine::TERMINAL,
            $this->machine->decide(FulfillmentStatus::Delivered, FulfillmentStatus::InTransit, $this->at('2026-01-01 10:00'), $this->at('2026-01-03 10:00')),
        );
    }

    public function test_a_stale_return_does_not_undo_a_later_delivery(): void
    {
        $this->assertSame(
            FulfillmentStateMachine::TERMINAL,
            $this->machine->decide(FulfillmentStatus::Delivered, FulfillmentStatus::Returned, $this->at('2026-01-05 10:00'), $this->at('2026-01-03 10:00')),
        );
    }

    public function test_providers_cannot_push_dispatcher_only_states(): void
    {
        foreach ([FulfillmentStatus::Pending, FulfillmentStatus::Held, FulfillmentStatus::Dispatching, FulfillmentStatus::ReconcilePending, FulfillmentStatus::Failed] as $internal) {
            $this->assertSame(
                FulfillmentStateMachine::STALE,
                $this->machine->decide(FulfillmentStatus::Submitted, $internal, null, $this->at('2026-01-03 10:00')),
            );
        }
    }

    public function test_provider_evidence_resolves_a_submission_we_were_unsure_about(): void
    {
        $this->assertSame(
            FulfillmentStateMachine::APPLY,
            $this->machine->decide(FulfillmentStatus::ReconcilePending, FulfillmentStatus::Processing, null, $this->at('2026-01-03 10:00')),
        );
    }
}
