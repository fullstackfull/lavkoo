<?php

namespace App\Console\Commands;

use App\Services\Storefront\StorefrontTax;
use App\Services\Storefront\StorefrontTaxApproval;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class StorefrontTaxApproveCommand extends Command
{
    protected $signature = 'gcc:storefront:tax-approve
        {country : the storefront, for example KW}
        {--decision= : setup (the rates the storefront has now are approved) or no_tax (it is decided, in a document, that it charges no tax)}
        {--by= : who approved it: the name or the role of the person with the authority, for example the tax adviser or the finance director}
        {--reference= : where the decision is written down: the opinion, the ticket or the minutes}
        {--revoke : withdraw the approval, so that the storefront stops selling}
        {--status : only say what is on record, and change nothing}';

    protected $description = 'Records that the tax of a storefront was approved, or that it was decided in a document that it charges none: until then the storefront cannot sell, and the Saudi setup is never used in its place. Records what the owner decided; it approves nothing by itself. Works while the switches are off, and then nothing reads the decision yet';

    public function handle(StorefrontTaxApproval $approval, StorefrontTax $tax): int
    {
        $country = strtoupper(trim((string) $this->argument('country')));

        if ($this->option('status')) {
            return $this->report(approval: $approval, tax: $tax, country: $country);
        }

        try {
            return $this->option('revoke')
                ? $this->revoke(approval: $approval, country: $country)
                : $this->approve(approval: $approval, country: $country);
        } catch (InvalidArgumentException|RuntimeException $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }
    }

    private function approve(StorefrontTaxApproval $approval, string $country): int
    {
        if ($this->option('decision') === null || $this->option('decision') === '') {
            throw new InvalidArgumentException('Give --decision=setup or --decision=no_tax, or --revoke, or --status.');
        }

        $record = $approval->approve(
            country: $country,
            decision: (string) $this->option('decision'),
            approvedBy: (string) $this->option('by'),
            reference: (string) $this->option('reference'),
        );

        $this->info(sprintf(
            '%s: %s, approved by %s (%s) on %s.',
            $country,
            $record['decision'] === StorefrontTaxApproval::DECISION_SETUP ? 'the tax setup it has now is approved' : 'the decision that it charges no tax is recorded',
            $record['approved_by'],
            $record['reference'],
            $record['approved_at'],
        ));
        $this->line('It counts only while the storefront is taxed with what was approved: a change of the rates, the type, the included flag or the shipping tax, and a rate on a storefront approved as taxed by nothing, make it stale and the storefront stops selling until it is approved again.');

        return self::SUCCESS;
    }

    private function revoke(StorefrontTaxApproval $approval, string $country): int
    {
        $revoked = $approval->revoke(country: $country, revokedBy: $this->option('by') === null ? null : (string) $this->option('by'));

        $this->info($revoked ? "{$country}: the tax approval is withdrawn: the storefront cannot sell until its tax is approved again." : "{$country} has no tax approval on record: nothing to withdraw.");

        return self::SUCCESS;
    }

    private function report(StorefrontTaxApproval $approval, StorefrontTax $tax, string $country): int
    {
        $status = $approval->statusOf(country: $country);

        $this->line(sprintf('%s: %s%s', $country, $status['status'], $status['decision'] === null ? '' : sprintf(' (%s, approved by %s, %s, %s)', $status['decision'], $status['approved_by'] ?? '?', $status['reference'] ?? '?', $status['approved_at'] ?? '?')));
        $this->line(sprintf('Taxed with: %s.', $approval->chargesNothing($tax->of(country: $country)) ? 'nothing' : 'a tax setup of its own (gcc:storefront:currency-readiness lists it)'));

        return in_array($status['status'], [StorefrontTaxApproval::NOT_REQUIRED, StorefrontTaxApproval::APPROVED], true) ? self::SUCCESS : self::FAILURE;
    }
}
