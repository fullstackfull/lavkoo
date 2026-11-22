<?php

namespace App\Console\Commands;

use App\Services\Storefront\StorefrontLaunchChecks;
use App\Services\Storefront\StorefrontLifecycle;
use App\Services\Storefront\StorefrontPreflight;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class StorefrontLifecycleCommand extends Command
{
    /** What each unmet prerequisite needs, in the words of the command that records it. */
    private const HOW_TO_MEET = [
        StorefrontPreflight::LEGAL_TEXTS => 'publish and approve every required legal text in the admin panel (approved by someone other than its author)',
        StorefrontPreflight::LEGAL_REVIEW => 'record the independent review: php artisan gcc:storefront:legal-review',
        StorefrontPreflight::CURRENCY => 'give the storefront the currency it trades in: php artisan gcc:storefront:money',
        StorefrontPreflight::LEGAL_ENTITY => 'register the selling company of the storefront: php artisan gcc:legal-entity:upsert',
        StorefrontPreflight::TAX => 'approve its tax setup, or record that it charges none: php artisan gcc:storefront:tax-approve',
    ];

    protected $signature = 'gcc:storefront:state
        {country? : the storefront, for example KW; with none, status reports every configured storefront}
        {action=status : status, ready, activate, pause or resume}
        {--by= : the person with the authority who makes the change}
        {--reason= : why the change is made}
        {--reference= : where the approval is written down: the decision, the ticket or the minutes (activate)}
        {--mode= : how a paused storefront is stopped: browse_only, maintenance or redirect (pause)}
        {--message= : what its customers are told (pause)}
        {--until= : when the stop is expected to end, as YYYY-MM-DD HH:MM in UTC (pause)}';

    protected $description = 'The lifecycle of a storefront: draft, ready, active, paused. status is read-only and says what each storefront still needs before it can sell (it exits 1 while one is blocked); ready, activate, pause and resume record a decision of the person who makes it, with an audit line. Works while the switches are off, and then nothing reads the state yet';

    public function handle(StorefrontLifecycle $lifecycle, StorefrontPreflight $preflight, StorefrontLaunchChecks $checks): int
    {
        $action = strtolower(trim((string) $this->argument('action')));
        $country = $this->argument('country') === null ? null : strtoupper(trim((string) $this->argument('country')));

        if ($action === 'status') {
            return $this->report(lifecycle: $lifecycle, preflight: $preflight, checks: $checks, country: $country);
        }

        if ($country === null) {
            $this->error("Say which storefront: php artisan gcc:storefront:state KW {$action}");

            return self::FAILURE;
        }

        try {
            $record = $this->change(lifecycle: $lifecycle, action: $action, country: $country);
        } catch (InvalidArgumentException|RuntimeException $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%s is now %s%s.', $country, $record['state'], $record['mode'] === null ? '' : " ({$record['mode']})"));

        if (!StorefrontResolver::fromConfig()->isEnabled()) {
            $this->line('Storefronts are off (GCC_STOREFRONTS_ENABLED): nothing reads this yet.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function change(StorefrontLifecycle $lifecycle, string $action, string $country): array
    {
        return match ($action) {
            'ready' => $lifecycle->markReady(country: $country, by: $this->text(option: 'by'), reason: $this->text(option: 'reason')),
            'activate' => $lifecycle->activate(country: $country, by: $this->text(option: 'by'), reference: $this->text(option: 'reference'), reason: $this->text(option: 'reason')),
            'pause' => $lifecycle->pause(country: $country, mode: $this->text(option: 'mode'), by: $this->text(option: 'by'), reason: $this->text(option: 'reason'), message: $this->option('message'), until: $this->option('until')),
            'resume' => $lifecycle->resume(country: $country, by: $this->text(option: 'by'), reason: $this->text(option: 'reason')),
            default => throw new InvalidArgumentException("Unknown action [{$action}]: status, ready, activate, pause or resume."),
        };
    }

    private function report(StorefrontLifecycle $lifecycle, StorefrontPreflight $preflight, StorefrontLaunchChecks $checks, ?string $country): int
    {
        $resolver = StorefrontResolver::fromConfig();
        $countries = $country === null ? $resolver->configuredCountries() : [$country];

        $this->line('Storefronts: ' . ($resolver->isEnabled() ? 'on' : 'off') . ' (GCC_STOREFRONTS_ENABLED); while they are off nothing reads the state of a storefront.');

        $blocked = false;

        foreach ($countries as $code) {
            if (!in_array($code, $resolver->configuredCountries(), true)) {
                $this->error("{$code} is not a configured storefront.");

                return self::FAILURE;
            }

            $blocked = $this->reportOne(lifecycle: $lifecycle, preflight: $preflight, checks: $checks, resolver: $resolver, code: $code) || $blocked;
        }

        return $blocked ? self::FAILURE : self::SUCCESS;
    }

    /** Whether the storefront is blocked: something it needs before it can sell is not met. */
    private function reportOne(StorefrontLifecycle $lifecycle, StorefrontPreflight $preflight, StorefrontLaunchChecks $checks, StorefrontResolver $resolver, string $code): bool
    {
        $status = $lifecycle->statusOf($code);
        $unmet = $preflight->unmet(country: $code, currencyCode: $resolver->currencyOf($code));

        $this->line(sprintf(
            '%s: %s%s%s',
            $code,
            $status['state'],
            $status['mode'] === null ? '' : " ({$status['mode']})",
            $code === $resolver->defaultCountry() ? ' (the default storefront: active without a record)' : '',
        ));

        if ($status['changed_by'] !== null) {
            $this->line(sprintf('  last change: %s, %s, because %s%s', $status['changed_by'], $status['changed_at'] ?? 'at an unknown time', $status['reason'] ?? '?', $status['reference'] === null ? '' : " (approval: {$status['reference']})"));
        }

        if ($status['message'] !== null) {
            $this->line("  message for its customers: {$status['message']}");
        }

        foreach (self::HOW_TO_MEET as $prerequisite => $howToMeet) {
            $this->line(in_array($prerequisite, $unmet, true) ? sprintf('  %-13s UNMET  %s', $prerequisite, $howToMeet) : sprintf('  %-13s ok', $prerequisite));
        }

        $this->reportAdvisory(checks: $checks, code: $code);

        return $unmet !== [];
    }

    private function reportAdvisory(StorefrontLaunchChecks $checks, string $code): void
    {
        $found = $checks->of($code);

        if (($found['free_shipping_products'] ?? 0) > 0) {
            $this->line(sprintf('  advisory: %d products ship free because no shipping cost was typed for them', $found['free_shipping_products']));
        }

        if (($found['seller_products'] ?? 0) > 0) {
            $this->line(sprintf('  advisory: %d products of sellers, which are unsupported there until sellers have markets', $found['seller_products']));
        }
    }

    private function text(string $option): string
    {
        return (string) $this->option($option);
    }
}
