<?php

namespace Tests\Unit\SalesChannels;

use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Accounting\AccountingDestination;
use App\Services\SalesChannels\Accounting\AccountingJournalLine;
use App\Services\SalesChannels\Accounting\NullAccountingDestination;
use Carbon\CarbonImmutable;
use LogicException;
use Tests\TestCase;

/** Odoo is deferred: the books have an extension point, and until one is built the destination posts nothing and says so. */
class AccountingDestinationTest extends TestCase
{
    public function test_the_default_destination_is_none_and_cannot_post(): void
    {
        $destination = app(AccountingDestination::class);

        $this->assertInstanceOf(NullAccountingDestination::class, $destination);
        $this->assertSame(['none', false], [$destination->code(), $destination->available()]);

        $this->expectException(LogicException::class);
        $destination->post([new AccountingJournalLine(AccountingJournalLine::SALE, 'order:1', Money::of('10'), 'SAR', CarbonImmutable::now())]);
    }
}
