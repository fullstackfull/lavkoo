<?php

namespace App\Console\Commands;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\Verification\CheckContext;
use App\Services\Fulfillment\Verification\CheckResult;
use App\Services\Fulfillment\Verification\EvidenceStore;
use App\Services\Fulfillment\Verification\LiveVerification;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * The read-only live verification of the provider's page in the admin panel, from the shell: it asks the provider only to READ
 * (products, orders, stock, categories, cities) and keeps the shape of every answer as evidence. Nothing is created, changed or
 * cancelled at the provider, even on a server that is allowed to write. Exit code 1 when a check failed.
 */
class FulfillmentVerifyCommand extends Command
{
    protected $signature = 'fulfillment:verify
        {provider : m5azn or torod_point}
        {--skus= : Torod Point: comma separated SKUs of products the warehouse has counted by hand, to find out which field means the stock}';

    protected $description = 'Ask a provider the read-only questions that settle its field maps, and keep the answers as evidence';

    public function handle(LiveVerification $verification, EvidenceStore $evidence): int
    {
        $provider = ProviderCode::fromSlug((string) $this->argument('provider'));
        if ($provider === null) {
            $this->error('The provider must be m5azn or torod_point.');

            return self::INVALID;
        }

        try {
            $run = $verification->run($provider, ['skus' => CheckContext::parseSkus((string) $this->option('skus'))]);
        } catch (ProviderUnavailableException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['#', 'check', 'status', 'http', 'ms', 'result'], array_map(
            static fn (CheckResult $check): array => [$check->id, $check->label, strtoupper($check->status), $check->httpStatus ?? '-', $check->durationMs, OutputFormatter::escape($check->summary)],
            $run->checks,
        ));
        $summary = $run->summary();
        $this->line("{$summary['pass']} passed, {$summary['warn']} to look at, {$summary['fail']} failed, {$summary['skipped']} skipped.");
        $this->line('Evidence (the shape of each answer, built to hold no credential and no personal data; read it before sharing it): ' . OutputFormatter::escape((string) ($evidence->path($provider->value, $run->id) ?? 'not saved, see the EVIDENCE check')));

        return $run->failed() ? self::FAILURE : self::SUCCESS;
    }
}
