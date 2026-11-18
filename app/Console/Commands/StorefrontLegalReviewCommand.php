<?php

namespace App\Console\Commands;

use App\Services\Storefront\StorefrontLegalReadiness;
use App\Services\Storefront\StorefrontLegalReview;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class StorefrontLegalReviewCommand extends Command
{
    protected $signature = 'gcc:storefront:legal-review
        {country : the storefront, for example KW}
        {--by= : who reviewed the texts: the name of the lawyer or of the firm of that country}
        {--reference= : where the opinion is written down: the opinion, the ticket or the minutes}
        {--on= : the day the review was done, as YYYY-MM-DD}
        {--revoke : withdraw the review, so that the storefront stops selling}
        {--status : only say what is on record, and change nothing}';

    protected $description = 'Records that the legal texts of a storefront were read by an independent reviewer (who, where the opinion is written, on which day): until then the storefront cannot sell. Records what the owner tells it; it reviews nothing by itself. Works while the switches are off, and then nothing reads the record yet';

    public function handle(StorefrontLegalReview $review, StorefrontLegalReadiness $readiness): int
    {
        $country = strtoupper(trim((string) $this->argument('country')));

        if ($this->option('status')) {
            return $this->report(review: $review, readiness: $readiness, country: $country);
        }

        try {
            return $this->option('revoke')
                ? $this->revoke(review: $review, country: $country)
                : $this->record(review: $review, country: $country);
        } catch (InvalidArgumentException|RuntimeException $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }
    }

    private function record(StorefrontLegalReview $review, string $country): int
    {
        $record = $review->record(
            country: $country,
            reviewedBy: (string) $this->option('by'),
            reference: (string) $this->option('reference'),
            reviewedOn: (string) $this->option('on'),
        );

        $this->info(sprintf('%s: the legal review is recorded: %s (%s), on %s.', $country, $record['reviewed_by'], $record['reference'], $record['reviewed_on']));
        $this->line('A review does not make the storefront sellable by itself: its required texts must also be published and approved (see gcc:storefront:currency-readiness).');
        $this->line('A new version of a text does not withdraw the review: the owner decides whether it needs another one.');

        return self::SUCCESS;
    }

    private function revoke(StorefrontLegalReview $review, string $country): int
    {
        $revoked = $review->revoke(country: $country, revokedBy: $this->option('by') === null ? null : (string) $this->option('by'));

        $this->info($revoked ? "{$country}: the legal review is withdrawn: the storefront cannot sell until a review is recorded again." : "{$country} has no legal review on record: nothing to withdraw.");

        return self::SUCCESS;
    }

    private function report(StorefrontLegalReview $review, StorefrontLegalReadiness $readiness, string $country): int
    {
        $status = $review->statusOf(country: $country);

        $this->line(sprintf('%s: %s%s', $country, $status['status'], $status['reviewed_by'] === null ? '' : sprintf(' (%s, %s, on %s, recorded %s)', $status['reviewed_by'], $status['reference'] ?? '?', $status['reviewed_on'] ?? '?', $status['recorded_at'] ?? '?')));
        $this->line(sprintf('Legal texts: %s.', match (true) {
            $readiness->isGrandfathered($country) => 'it keeps the documents it has always had',
            $readiness->isReady($country) => 'every required text is published and approved',
            default => 'not ready (a required text is missing, not effective, not approved by someone other than its author, or none is required)',
        }));

        return in_array($status['status'], [StorefrontLegalReview::NOT_REQUIRED, StorefrontLegalReview::RECORDED], true) ? self::SUCCESS : self::FAILURE;
    }
}
