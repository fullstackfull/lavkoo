<?php

namespace Tests\Support;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Contracts\HandlesWebhooks;
use App\Services\Fulfillment\Contracts\ProviderAdapter;
use App\Services\Fulfillment\Contracts\ReconcilesSubmissions;
use App\Services\Fulfillment\Data\CancellationResult;
use App\Services\Fulfillment\Data\CapabilityState;
use App\Services\Fulfillment\Data\ConnectionTestResult;
use App\Services\Fulfillment\Data\NormalizedEvent;
use App\Services\Fulfillment\Data\PreflightResult;
use App\Services\Fulfillment\Data\ReadinessReport;
use App\Services\Fulfillment\Data\StatusSnapshot;
use App\Services\Fulfillment\Data\SubmissionResult;
use Carbon\Carbon;

/**
 * A scriptable stand-in for a real provider adapter. Core pipeline tests (routing, dispatch, retry,
 * reconcile, webhooks) use it so they exercise OUR logic and never a real provider; the real adapters are
 * tested separately against faked HTTP.
 */
abstract class FakeProviderAdapter implements ProviderAdapter, HandlesWebhooks, ReconcilesSubmissions
{
    /** @var list<SubmissionResult> results returned by submit(), consumed in order */
    public array $submitQueue = [];

    /** @var list<SubmissionResult> results returned by reconcileSubmission() */
    public array $reconcileQueue = [];

    /** @var list<CancellationResult|\Throwable> results (or failures) returned by cancel(), consumed in order */
    public array $cancelQueue = [];

    /** Runs inside submit(), while the fulfillment is claimed: lets a test act in the middle of a send. */
    public ?\Closure $whileSubmitting = null;

    public ?bool $confirmStatusAnswer = true;

    public PreflightResult|null $preflight = null;

    /** @var list<string> */
    public array $calls = [];

    /** @var list<array{reference: string, payment_mode: string, cod_amount: float, total_amount: float}> what each submit() was asked to send */
    public array $submitted = [];

    public bool $dispatchUsable = true;

    public static function reset(): void
    {
        // Instances are container singletons, reset by re-binding in the test case.
    }

    abstract public function code(): ProviderCode;

    public function readiness(): ReadinessReport
    {
        return new ReadinessReport(
            configured: $this->dispatchUsable,
            missing: $this->dispatchUsable ? [] : ['credentials'],
            capabilities: ['dispatch' => new CapabilityState('dispatch', $this->dispatchUsable ? CapabilityState::ENABLED : CapabilityState::NEEDS_CONFIGURATION)],
        );
    }

    public function testConnection(): ConnectionTestResult
    {
        return new ConnectionTestResult(true, 'ok');
    }

    public function preflight(ProviderFulfillment $fulfillment): PreflightResult
    {
        return $this->preflight ?? PreflightResult::passed();
    }

    public function submit(ProviderFulfillment $fulfillment): SubmissionResult
    {
        $this->calls[] = 'submit:' . $fulfillment->reference . ':' . $fulfillment->items->pluck('product_id')->sort()->implode(',');
        $this->submitted[] = [
            'reference' => $fulfillment->reference,
            'payment_mode' => $fulfillment->payment_mode->value,
            'cod_amount' => (float) $fulfillment->cod_amount,
            'total_amount' => (float) $fulfillment->total_amount,
        ];
        if ($this->whileSubmitting !== null) {
            ($this->whileSubmitting)($fulfillment);
        }

        return array_shift($this->submitQueue) ?? SubmissionResult::accepted('REF-' . $fulfillment->reference);
    }

    public function reconcileSubmission(ProviderFulfillment $fulfillment): SubmissionResult
    {
        $this->calls[] = 'reconcile:' . $fulfillment->reference;

        return array_shift($this->reconcileQueue) ?? SubmissionResult::ambiguous('not_found', 'not found');
    }

    public function cancel(ProviderFulfillment $fulfillment): CancellationResult
    {
        $this->calls[] = 'cancel:' . $fulfillment->reference;

        $next = array_shift($this->cancelQueue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next ?? CancellationResult::requested();
    }

    public function fetchStatus(ProviderFulfillment $fulfillment): ?StatusSnapshot
    {
        return null;
    }

    public function mapStatus(string $providerStatus): ?FulfillmentStatus
    {
        return FulfillmentStatus::tryFrom(strtolower($providerStatus));
    }

    public function normalizeWebhook(string $type, array $payload): ?NormalizedEvent
    {
        if (!isset($payload['ref'], $payload['status'])) {
            return null;
        }

        return new NormalizedEvent(
            type: $type,
            dedupeKey: hash('sha256', $this->code()->value . '|' . $payload['ref'] . '|' . $payload['status'] . '|' . ($payload['time'] ?? '')),
            references: ['barcode' => (string) $payload['ref']],
            providerStatus: (string) $payload['status'],
            occurredAt: isset($payload['time']) ? Carbon::createFromTimestampMs((int) $payload['time']) : null,
            informational: ($payload['informational'] ?? false) === true,
            warehouseStatus: $payload['warehouse'] ?? null,
        );
    }

    public function locateFulfillment(NormalizedEvent $event): ?ProviderFulfillment
    {
        return ProviderFulfillment::where('provider', $this->code()->value)->where('provider_reference', $event->reference('barcode'))->first();
    }

    public function learnReferences(ProviderFulfillment $fulfillment, NormalizedEvent $event): void
    {
    }

    public function confirmStatus(ProviderFulfillment $fulfillment, NormalizedEvent $event): ?bool
    {
        return $this->confirmStatusAnswer;
    }
}
