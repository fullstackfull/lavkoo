<?php

namespace App\Console\Commands;

use App\Services\Storefront\StorefrontDefault;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Shows or moves the market a visitor who names none is served (GCC-19, A1-03), for an operator who has no screen for it yet.
 *
 * Without a country it reports: what is in force, whether that is the configuration's or a recorded decision, and which of the markets this
 * platform serves could be the default at all. With one it records the change, with the person who makes it and why, and refuses a market
 * that is not served, is not active, or may not sell yet.
 */
class StorefrontDefaultCommand extends Command
{
    protected $signature = 'gcc:storefront:default
        {country? : the market to make the default, for example AE; with none, reports what is in force}
        {--by= : the person with the authority who makes the change}
        {--reason= : why the default is moved}
        {--withdraw : take the recorded default away, so the configured one is the default again}';

    protected $description = "Shows or moves the default storefront: the market a visitor who names none is served. A market that is not served, not active or not yet able to sell is refused";

    public function handle(StorefrontDefault $default): int
    {
        $country = $this->argument('country');

        if ($this->option('withdraw')) {
            return $this->withdraw($default);
        }

        return $country === null ? $this->report($default) : $this->change($default, country: (string) $country);
    }

    private function report(StorefrontDefault $default): int
    {
        $recorded = $default->recorded();

        $this->line(sprintf(
            'The default storefront is %s, %s.',
            $default->inForce(),
            $recorded === null ? 'as the configuration says' : 'as a person recorded it',
        ));

        $rows = [];

        foreach (StorefrontResolver::fromConfig()->reachableCountries() as $market) {
            $refusal = $default->refusalOf($market);
            $rows[] = [$market, $refusal === null ? 'could be the default' : 'no', $refusal ?? ''];
        }

        $this->table(['market', 'eligible', 'why not'], $rows);

        return self::SUCCESS;
    }

    private function change(StorefrontDefault $default, string $country): int
    {
        try {
            $record = $default->change(country: $country, by: (string) $this->option('by'), reason: (string) $this->option('reason'));
        } catch (InvalidArgumentException $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('The default storefront is now %s, recorded by %s.', $record['country'], $record['changed_by']));
        $this->line('It applies from the next request: the decision is read once when the application boots.');

        return self::SUCCESS;
    }

    private function withdraw(StorefrontDefault $default): int
    {
        $by = (string) $this->option('by');
        $reason = (string) $this->option('reason');

        if (trim($by) === '' || trim($reason) === '') {
            $this->error('Withdrawing the recorded default is recorded with the person who does it and the reason.');

            return self::FAILURE;
        }

        if (!$default->withdraw(by: $by, reason: $reason)) {
            $this->line('There was no recorded default to withdraw: the configuration decides.');

            return self::SUCCESS;
        }

        $this->info('The recorded default is withdrawn. From the next request the configuration decides again.');

        return self::SUCCESS;
    }
}
