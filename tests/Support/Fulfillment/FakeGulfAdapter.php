<?php

namespace Tests\Support\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\ConfiguredProvider;
use App\Services\Fulfillment\Contracts\ProviderAdapter;
use App\Services\Fulfillment\Contracts\ProviderIdentity;
use App\Services\Fulfillment\Data\CancellationResult;
use App\Services\Fulfillment\Data\ConnectionTestResult;
use App\Services\Fulfillment\Data\PreflightResult;
use App\Services\Fulfillment\Data\ReadinessReport;
use App\Services\Fulfillment\Data\StatusSnapshot;
use App\Services\Fulfillment\Data\SubmissionResult;
use RuntimeException;

/**
 * A carrier for another Gulf country, written as a carrier is written: one class, implementing the one contract, with no case in `ProviderCode` and no
 * change anywhere else (H1-11).
 *
 * It exists in the tests rather than in the application because which carrier actually serves the UAE, Kuwait, Bahrain, Qatar or Oman is the owner's
 * Q-33 and no adapter for a real one may be invented here. What it proves is the mechanism: that a class plus a configuration entry is enough. It sends
 * nothing anywhere -- `submit()` refuses outright unless it is a dry run, so a test that reached a network through this would fail rather than succeed
 * quietly.
 */
final class FakeGulfAdapter implements ProviderAdapter
{
    public const CODE = 'fake_gulf';

    /** @return array<string, mixed> */
    public static function defaultSettings(): array
    {
        return ['dispatch_enabled' => true, 'dry_run' => true];
    }

    public function code(): ProviderIdentity
    {
        return ConfiguredProvider::fromConfig(self::CODE)
            ?? throw new RuntimeException('This adapter is only usable while its configuration entry exists.');
    }

    public function readiness(): ReadinessReport
    {
        return new ReadinessReport(configured: true, missing: [], capabilities: ['submit', 'cancel']);
    }

    public function testConnection(): ConnectionTestResult
    {
        return new ConnectionTestResult(ok: true, message: 'a fake carrier is always reachable, because nothing is reached');
    }

    public function preflight(ProviderFulfillment $fulfillment): PreflightResult
    {
        return PreflightResult::passed();
    }

    public function submit(ProviderFulfillment $fulfillment, bool $dryRun): SubmissionResult
    {
        if (!$dryRun) {
            throw new RuntimeException('A fake carrier must never be sent a real shipment.');
        }

        return SubmissionResult::dryRun(['provider' => self::CODE]);
    }

    public function cancel(ProviderFulfillment $fulfillment): CancellationResult
    {
        return CancellationResult::requested('a fake carrier cancels nothing, because it sent nothing');
    }

    public function fetchStatus(ProviderFulfillment $fulfillment): ?StatusSnapshot
    {
        return null;
    }

    public function mapStatus(string $providerStatus): ?FulfillmentStatus
    {
        return $providerStatus === 'delivered' ? FulfillmentStatus::Delivered : null;
    }
}
