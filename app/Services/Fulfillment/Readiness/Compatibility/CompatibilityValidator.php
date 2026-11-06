<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

use App\Services\Fulfillment\Readiness\FulfillmentPreflight;
use App\Services\Fulfillment\Readiness\PreflightCheck;

/**
 * Checks a restored copy of the store's database against everything the fulfillment integration assumes about it, without
 * changing anything: schema (presence and storage types), the aggregate shape of orders, order lines, products, payment
 * states and stock, the checkout configuration, and what `php artisan migrate` would apply.
 *
 * It is the successor of "does it look right" by hand. It still proves only what a database can show: it says nothing about
 * whether checkout, routing or dispatch behave on that database; the staging scenarios do that.
 */
final class CompatibilityValidator
{
    public function __construct(
        private readonly ReadOnlyRunner $runner,
        private readonly FulfillmentPreflight $preflight,
        private readonly RestoreSection $restore,
        private readonly StructureSection $structure,
        private readonly CheckoutSection $checkout,
        private readonly OrdersSection $orders,
        private readonly OrderDetailsSection $orderDetails,
        private readonly ProductsSection $products,
        private readonly PaymentStatesSection $paymentStates,
        private readonly InventorySection $inventory,
        private readonly MigrationsSection $migrations,
    ) {
    }

    public function validate(CompatibilityOptions $options): CompatibilityReport
    {
        return $this->runner->run($options, fn (ReadOnlyProbe $probe): array => $this->collect($probe, $options));
    }

    /** @return list<CompatibilityCheck> */
    private function collect(ReadOnlyProbe $probe, CompatibilityOptions $options): array
    {
        return [
            ...$this->fromPreflight($this->preflight->safety(allowProduction: $options->allowProduction, allowLive: false)),
            ...$this->fromPreflight($this->preflight->schema()),
            ...$this->restore->checks($probe, $options),
            ...$this->structure->checks($probe, $options),
            ...$this->checkout->checks($probe, $options),
            ...$this->orders->checks($probe, $options),
            ...$this->orderDetails->checks($probe, $options),
            ...$this->products->checks($probe, $options),
            ...$this->paymentStates->checks($probe, $options),
            ...$this->inventory->checks($probe, $options),
            ...$this->migrations->checks($probe, $options),
        ];
    }

    /**
     * @param list<PreflightCheck> $checks
     * @return list<CompatibilityCheck>
     */
    private function fromPreflight(array $checks): array
    {
        return array_map(CompatibilityCheck::fromPreflight(...), $checks);
    }
}
