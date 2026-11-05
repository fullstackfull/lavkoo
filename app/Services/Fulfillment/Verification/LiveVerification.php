<?php

namespace App\Services\Fulfillment\Verification;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\ProviderRegistry;
use App\Services\Fulfillment\Support\HttpResult;
use App\Services\Fulfillment\Support\PiiRedactor;
use App\Services\Fulfillment\Support\ProviderSyncRecorder;
use Throwable;

/**
 * Asks a provider the read-only questions that settle its field maps, and keeps the answers as evidence.
 *
 * What makes it safe to run against the real provider with real credentials on the live server:
 *  - it only calls operations the registry classifies as a read (and the login that returns a token): there is no order, product
 *    or cancellation call in the check lists, and a test proves it even with external writes switched on;
 *  - every call goes through ProviderHttp, so the kill switch and the write guard apply to it like to everything else;
 *  - what it keeps is the SHAPE of each answer, with secrets and personal data removed before anything is stored.
 */
class LiveVerification
{
    /** A courtesy to the provider: one call every so often, not a burst. */
    private const PAUSE_MICROSECONDS = 150_000;

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ProviderConnectionManager $connections,
        private readonly M5aznChecks $m5azn,
        private readonly TorodChecks $torod,
        private readonly EvidenceStore $evidence,
        private readonly ProviderSyncRecorder $sync,
    ) {
    }

    /**
     * @param array<string, mixed> $options `skus`: SKUs of products whose stock has been counted by hand (Torod Point)
     * @throws ProviderUnavailableException when the integration is switched off or the provider is not enabled: nothing was asked
     */
    public function run(ProviderCode $provider, array $options = []): VerificationRun
    {
        if (!$this->connections->canCall($provider)) {
            throw new ProviderUnavailableException('The integration or this provider is switched off (FULFILLMENT_INTEGRATIONS_ENABLED, or the provider\'s Integration enabled switch); nothing was asked.');
        }

        $started = hrtime(true);
        $startedAt = now()->toIso8601String();
        $this->sync->attempted($provider->value, ProviderSyncRecorder::LIVE_VERIFICATION);

        $missing = $this->registry->adapter($provider)->readiness()->missing;
        $context = new CheckContext($options);
        $results = [];
        foreach ($this->checksFor($provider) as $check) {
            $results[] = $missing === []
                ? $this->execute($check, $context)
                : $this->result($check, CheckResult::SKIPPED, null, 0, 'Not asked: these are not saved yet: ' . implode(', ', $missing) . '.');
            if ($missing === []) {
                usleep(self::PAUSE_MICROSECONDS);
            }
        }

        $run = new VerificationRun(
            id: $this->evidence->newRunId($provider->value),
            provider: $provider->value,
            environment: $this->environment($provider),
            startedAt: $startedAt,
            durationMs: (int) round((hrtime(true) - $started) / 1_000_000),
            checks: $results,
        );
        $this->evidence->save($run);
        $this->record($provider, $run);

        return $run;
    }

    /** @return list<VerificationCheck> */
    private function checksFor(ProviderCode $provider): array
    {
        return $provider === ProviderCode::M5azn ? $this->m5azn->checks() : $this->torod->checks();
    }

    private function execute(VerificationCheck $check, CheckContext $context): CheckResult
    {
        $started = hrtime(true);

        try {
            $response = ($check->call)($context);
        } catch (ProviderUnavailableException $exception) {
            return $this->result($check, CheckResult::FAIL, null, $this->elapsed($started), 'Not sent: ' . $this->safe($exception->getMessage()));
        } catch (Throwable $exception) {
            return $this->result($check, CheckResult::FAIL, null, $this->elapsed($started), 'The call failed before an answer came back (' . $exception::class . ').');
        }

        if ($response === null) {
            return $this->result($check, CheckResult::SKIPPED, null, 0, $context->takeSkipReason());
        }

        return $response->ok() ? $this->answered($check, $response, $context, $this->elapsed($started)) : $this->refused($check, $response);
    }

    private function answered(VerificationCheck $check, HttpResult $response, CheckContext $context, int $milliseconds): CheckResult
    {
        if ($response->json === null) {
            return $this->result($check, CheckResult::WARN, $response->status, $milliseconds, 'Answered, but the body is not JSON: nothing to record a field map from.');
        }

        $shape = ResponseShape::profile($response->json, $check->strict);
        $findings = ResponseShape::findings($shape);
        $extra = $check->analyse === null ? [] : ($check->analyse)($response, $context, $findings);
        $verdict = $extra['_verdict'] ?? null;
        unset($extra['_verdict']);
        $findings += $extra;

        [$status, $summary] = $verdict !== null
            ? [$verdict['status'], $verdict['summary']]
            : $this->judge($check, $findings);

        return $this->result($check, $status, $response->status, $milliseconds, $summary, $findings, $shape);
    }

    private function refused(VerificationCheck $check, HttpResult $response): CheckResult
    {
        $summary = match ($response->kind) {
            HttpResult::AUTH_ERROR => 'The provider refused the credentials (HTTP ' . $response->status . '). Check what is saved on this page.',
            HttpResult::RATE_LIMITED => 'The provider is rate limiting this account (HTTP 429). Wait, then run again.',
            HttpResult::NOT_SENT, HttpResult::AMBIGUOUS => 'No answer: ' . $this->safe($response->message()),
            default => 'HTTP ' . $response->status . ': ' . $this->safe($response->message()),
        };

        return $this->result($check, CheckResult::FAIL, $response->status, $response->durationMs, $summary);
    }

    /**
     * @param array<string, mixed> $findings
     * @return array{0: string, 1: string}
     */
    private function judge(VerificationCheck $check, array $findings): array
    {
        if (!$check->expectsList) {
            return [CheckResult::PASS, 'Answered.'];
        }
        if (!array_key_exists('items_path', $findings)) {
            return [CheckResult::WARN, 'Answered, but no list of records was found in the response: set the items path of the field map by hand.'];
        }
        if (($findings['item_count'] ?? 0) === 0) {
            return [CheckResult::WARN, 'Answered with an empty list: there is nothing to record a schema from yet.'];
        }

        $parts = [$findings['item_count'] . ' record(s)' . ($findings['items_path'] === '' ? '' : " at '{$findings['items_path']}'")];
        if (isset($findings['stock_fields'])) {
            $parts[] = 'stock-like fields: ' . implode(', ', array_column($findings['stock_fields'], 'field'));
        }

        return [CheckResult::PASS, implode('; ', $parts) . '.'];
    }

    /**
     * @param array<string, mixed> $findings
     * @param array<string, mixed>|null $shape
     */
    private function result(VerificationCheck $check, string $status, ?int $http, int $milliseconds, string $summary, array $findings = [], ?array $shape = null): CheckResult
    {
        return new CheckResult($check->id, $check->label, $check->operation, $status, $http, $milliseconds, $summary, $check->request, $findings, $shape);
    }

    private function record(ProviderCode $provider, VerificationRun $run): void
    {
        $summary = $run->summary();
        $failed = array_map(static fn (CheckResult $check): string => $check->id, array_filter($run->checks, static fn (CheckResult $check): bool => $check->status === CheckResult::FAIL));

        // A run in which nothing was actually answered (every check skipped) proves nothing, so it never counts as a success.
        match (true) {
            $run->failed() => $this->sync->failed($provider->value, ProviderSyncRecorder::LIVE_VERIFICATION, count($failed) . ' check(s) failed: ' . implode(', ', $failed)),
            $summary[CheckResult::PASS] === 0 => $this->sync->failed($provider->value, ProviderSyncRecorder::LIVE_VERIFICATION, 'nothing was verified: no check passed'),
            default => $this->sync->succeeded($provider->value, ProviderSyncRecorder::LIVE_VERIFICATION, ['run_id' => $run->id] + $summary),
        };
    }

    /** @return array<string, mixed> */
    private function environment(ProviderCode $provider): array
    {
        $config = $this->connections->config($provider);

        return array_filter([
            'mode' => $this->connections->mode()->value,
            'external_writes_enabled' => $this->connections->externalWritesEnabled(),
            'host' => parse_url($config->baseUrl, PHP_URL_HOST) ?: null,
            'company_id' => $provider === ProviderCode::TorodPoint ? $config->string('company_id') : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function safe(string $message): string
    {
        return PiiRedactor::scrub($message);
    }

    private function elapsed(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
