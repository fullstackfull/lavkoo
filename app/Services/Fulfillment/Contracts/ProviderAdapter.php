<?php

namespace App\Services\Fulfillment\Contracts;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Data\CancellationResult;
use App\Services\Fulfillment\Data\ConnectionTestResult;
use App\Services\Fulfillment\Data\PreflightResult;
use App\Services\Fulfillment\Data\ReadinessReport;
use App\Services\Fulfillment\Data\StatusSnapshot;
use App\Services\Fulfillment\Data\SubmissionResult;

/**
 * One provider's side of the integration. An adapter owns everything provider-specific — its URLs, its
 * payload shape, its status vocabulary — and nothing else: routing, idempotency, retries, locking and
 * parent-order aggregation are provider-neutral and live outside it.
 *
 * Adapters never see another provider's data. Each is handed only fulfillments whose `provider` is its
 * own, and the dispatcher refuses to call an adapter with anything else.
 */
interface ProviderAdapter
{
    public function code(): ProviderCode;

    /** What the provider's documentation lets us do, and whether it is configured and switched on. */
    public function readiness(): ReadinessReport;

    /** A read-only call that proves the credentials work. Never creates or changes anything remotely. */
    public function testConnection(): ConnectionTestResult;

    /**
     * Everything that can be checked WITHOUT calling the provider: mapping, address, phone, city,
     * configuration. A failure here holds the fulfillment; nothing is sent.
     */
    public function preflight(ProviderFulfillment $fulfillment): PreflightResult;

    /**
     * Ask the provider to accept the fulfillment. The result must say honestly whether it was
     * accepted, definitely not accepted, or — after a timeout or dropped connection — unknown.
     *
     * $dryRun is the decision the dispatcher took when it claimed the fulfillment, under its lock and after the approval
     * gate. The adapter follows it and never reads the setting again: a dry run builds the request and sends nothing, and an
     * administrator who flips the switch between the claim and the send must not turn a rehearsal into a real order.
     */
    public function submit(ProviderFulfillment $fulfillment, bool $dryRun): SubmissionResult;

    public function cancel(ProviderFulfillment $fulfillment): CancellationResult;

    /** The provider's current view of one fulfillment, or null when it cannot be asked. */
    public function fetchStatus(ProviderFulfillment $fulfillment): ?StatusSnapshot;

    /** The provider's status string translated to ours, or null if the status is not mapped. */
    public function mapStatus(string $providerStatus): ?FulfillmentStatus;
}
