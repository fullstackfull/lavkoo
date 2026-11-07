<?php

namespace App\Services\OperationalCosts\Data;

use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonInterface;

/**
 * Everything a contract rule may need to price one service: when it happened, how much of it there was, and the facts the rule's
 * conditions look at. A fact the caller does not know is null, and a rule that needs it says so (CostInputMissing) instead of
 * pricing the service as if the fact were zero.
 */
final readonly class ServiceContext
{
    public function __construct(
        public CarbonInterface $serviceDate,
        public string $currency,
        public int $units = 0,
        public int $shipments = 1,
        public int $orders = 1,
        public ?Money $value = null,
        public ?Money $weightKg = null,
        public ?Money $volumeM3 = null,
        public ?string $city = null,
        public ?string $country = null,
        public ?string $paymentMethod = null,
        public ?string $salesChannel = null,
        public ?string $serviceLevel = null,
        public int $days = 1,
        public bool $valueWasNotConvertible = false,
    ) {
    }

    public function withValue(?Money $value): self
    {
        return new self(
            $this->serviceDate, $this->currency, $this->units, $this->shipments, $this->orders, $value, $this->weightKg,
            $this->volumeM3, $this->city, $this->country, $this->paymentMethod, $this->salesChannel, $this->serviceLevel, $this->days,
        );
    }

    /** The same facts with the value stated in another currency; null when it could not be converted (then a rule that needs the value says the rate is missing). */
    public function inCurrency(string $currency, ?Money $convertedValue): self
    {
        return new self(
            $this->serviceDate, $currency, $this->units, $this->shipments, $this->orders, $convertedValue, $this->weightKg,
            $this->volumeM3, $this->city, $this->country, $this->paymentMethod, $this->salesChannel, $this->serviceLevel, $this->days, valueWasNotConvertible: $convertedValue === null,
        );
    }
}
