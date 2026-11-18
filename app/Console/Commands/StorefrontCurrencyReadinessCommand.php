<?php

namespace App\Console\Commands;

use App\Services\Storefront\StorefrontCurrency;
use App\Services\Storefront\StorefrontLaunchChecks;
use App\Services\Storefront\StorefrontLegalReadiness;
use App\Services\Storefront\StorefrontLegalReview;
use App\Services\Storefront\StorefrontTax;
use App\Services\Storefront\StorefrontTaxApproval;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class StorefrontCurrencyReadinessCommand extends Command
{
    protected $signature = 'gcc:storefront:currency-readiness';

    protected $description = 'Read-only report before storefront currencies are turned on: for each configured storefront, the currency it trades in, its decimals and whether the currency exists and is active. Exits 1 when a storefront that is switched on lacks its currency, has no approved tax decision, or cannot sell for want of its legal texts or the independent review of them';

    public function handle(
        StorefrontCurrency $currency,
        StorefrontLaunchChecks $checks,
        StorefrontTax $tax,
        StorefrontTaxApproval $approval,
        StorefrontLegalReadiness $legalReadiness,
        StorefrontLegalReview $legalReview,
    ): int {
        $this->line('Storefront currencies: ' . ($currency->isEnabled() ? 'on' : 'off') . ' (GCC_STOREFRONT_CURRENCY, and GCC_STOREFRONTS_ENABLED must be on too).');

        $blocked = false;

        foreach ($currency->report() as $row) {
            $this->line(sprintf('  %-4s %-3s %d decimals  %-8s %s', $row['country'], $row['currency'], $row['decimals'], $row['active'] ? 'active' : 'inactive', $row['status']));
            $blocked = $blocked || ($row['active'] && !str_starts_with($row['status'], 'default') && $row['status'] !== 'ready');
        }

        $unsafe = false;

        foreach ($currency->report() as $row) {
            if ($row['active'] && !str_starts_with($row['status'], 'default')) {
                $this->line($this->amountsLine(currency: $currency, country: $row['country'], currencyCode: $row['currency']));
                $unsafe = $this->launchLine(checks: $checks, country: $row['country']) || $unsafe;
                $this->taxLine(tax: $tax, country: $row['country']);
                $unsafe = $this->taxApprovalLine(approval: $approval, country: $row['country']) || $unsafe;
                $unsafe = $this->legalLine(readiness: $legalReadiness, review: $legalReview, country: $row['country']) || $unsafe;
            }
        }

        $unsafe = $this->sharedLines(checks: $checks, currency: $currency) || $unsafe;

        $this->line($this->closingLine(blocked: $blocked, unsafe: $unsafe));
        $this->line('Not covered by this report: the wallet payment, loyalty, referral rewards, whether the tax rates are right (only that somebody approved them), documents, reports and POS.');

        return $blocked || $unsafe ? self::FAILURE : self::SUCCESS;
    }

    /** What a storefront that is switched on has been given for its amounts, and what it has not: a warning, never a failure, since the neutral values cannot be wrong. */
    private function amountsLine(StorefrontCurrency $currency, string $country, string $currencyCode): string
    {
        $given = $currency->amountsGiven($country);

        if ($given['ignored_tag'] !== null) {
            return sprintf('  %-4s amounts: a row for %s is ignored, this storefront trades in %s: give them again with gcc:storefront:money. No minimum order, no free delivery, top-up closed.', $country, $given['ignored_tag'], $currencyCode);
        }

        $minimum = $given['minimum_order_amount'] === null ? 'no minimum order' : "minimum order {$given['minimum_order_amount']} {$currencyCode}";
        $freeDelivery = $given['free_delivery_over_amount'] === null ? 'no free delivery' : "free delivery over {$given['free_delivery_over_amount']} {$currencyCode}";

        return sprintf('  %-4s amounts: %s, %s, wallet top-up closed until the wallets are per market (GCC-15)%s.', $country, $minimum, $freeDelivery, $given['minimum_order_amount'] === null || $given['free_delivery_over_amount'] === null ? '; set what is missing with gcc:storefront:money' : '');
    }

    /** What a storefront that is switched on holds in the rows its money reads. Prints one line and answers whether anything on it is unsafe. */
    private function launchLine(StorefrontLaunchChecks $checks, string $country): bool
    {
        $found = $checks->of(country: $country);
        $parts = ['shipping is product-only'];
        $unsafe = false;

        if ($found['free_shipping_products'] !== null) {
            $parts[0] .= sprintf(' (%d active physical products have no shipping cost and ship free)', $found['free_shipping_products']);
        }

        if ($found['seller_products'] !== null) {
            $unsafe = $found['seller_products'] > 0;
            $parts[] = $unsafe
                ? sprintf('UNSAFE: %d seller-owned products (sellers have no market, so their amounts are in no storefront\'s currency)', $found['seller_products'])
                : 'no seller-owned products';
        }

        if ($found['coupons'] !== null) {
            $parts[] = sprintf('%d live coupons of %d typed for it', $found['live_coupons'], $found['coupons']);
        }

        $this->line(sprintf('  %-4s launch: %s.', $country, implode(', ', $parts)));

        return $unsafe;
    }

    /**
     * Which tax setup the orders of a storefront that is switched on are taxed with: its own, or nothing. The platform's Saudi setup is never used for it. What the rates
     * must be is the owner's decision, and a setup with no rate says that a storefront charges none. Prints nothing while the tax module has no table.
     */
    private function taxLine(StorefrontTax $tax, string $country): void
    {
        if (!$tax->tracksSetups()) {
            return;
        }

        $setup = $tax->of(country: $country);

        if (!$setup['own']) {
            $this->line(sprintf('  %-4s tax: no setup of its own, so its orders carry no tax (the platform\'s Saudi setup is never used for it). Give it one with gcc:storefront:tax.', $country));

            return;
        }

        $rates = $setup['type'] === 'order_wise'
            ? ($setup['rates'] === [] ? 'no rate, so no tax is charged' : $this->ratesText($setup['rates']))
            : 'the rates are set on each ' . str_replace('_wise', '', $setup['type']);
        $shipping = $setup['shipping'] === [] ? '' : '; on shipping ' . $this->ratesText($setup['shipping']);
        $kind = sprintf('%s, %s', str_replace('_', ' ', $setup['type']), $setup['included'] ? 'tax included in the prices' : 'tax added to the prices');

        $this->line(sprintf('  %-4s tax: its own setup (%s): %s%s.', $country, $kind, $rates, $shipping));
    }

    /** @param list<array{name: string, rate: float}> $rates */
    private function ratesText(array $rates): string
    {
        return implode(', ', array_map(static fn (array $rate): string => sprintf('%s %s%%', $rate['name'], rtrim(rtrim(number_format($rate['rate'], 3, '.', ''), '0'), '.')), $rates));
    }

    /**
     * Whether the tax of a storefront that is switched on has been approved, or a decision that it charges none recorded: without one it cannot sell. Prints one line and
     * answers whether the storefront is unsafe to open.
     */
    private function taxApprovalLine(StorefrontTaxApproval $approval, string $country): bool
    {
        $status = $approval->statusOf(country: $country);
        $decided = $status['decision'] === null ? '' : sprintf(' (%s, approved by %s, %s, %s)', $status['decision'], $status['approved_by'] ?? '?', $status['reference'] ?? '?', $status['approved_at'] ?? '?');

        $this->line(match ($status['status']) {
            StorefrontTaxApproval::APPROVED => sprintf('  %-4s tax decision: approved%s.', $country, $decided),
            StorefrontTaxApproval::STALE => sprintf('  %-4s UNSAFE: the tax decision on record no longer describes what it is taxed with%s, so it cannot sell: approve it again with gcc:storefront:tax-approve.', $country, $decided),
            StorefrontTaxApproval::INCOMPLETE => sprintf('  %-4s UNSAFE: the tax decision on record does not say what was decided, by whom, where or when, so it counts for nothing and the storefront cannot sell: record it again with gcc:storefront:tax-approve.', $country),
            default => sprintf('  %-4s UNSAFE: no approved tax decision, so it cannot sell: approve its setup, or a documented decision that it charges no tax, with gcc:storefront:tax-approve.', $country),
        });

        return !in_array($status['status'], [StorefrontTaxApproval::APPROVED, StorefrontTaxApproval::NOT_REQUIRED], true);
    }

    /**
     * Whether a storefront that is switched on has its required legal texts published and approved, and an independent review of them on record: without both it cannot sell.
     * Prints what is missing and answers whether the storefront is unsafe to open.
     */
    private function legalLine(StorefrontLegalReadiness $readiness, StorefrontLegalReview $review, string $country): bool
    {
        if ($readiness->isGrandfathered($country)) {
            $this->line(sprintf('  %-4s legal: keeps the documents it has always had, so neither their texts nor a review of them are required.', $country));

            return false;
        }

        $textsReady = $readiness->isReady($country);
        $status = $review->statusOf(country: $country);
        $reviewed = $status['status'] === StorefrontLegalReview::RECORDED;

        if ($textsReady && $reviewed) {
            $this->line(sprintf('  %-4s legal: texts approved, independent review recorded (%s, %s, on %s).', $country, $status['reviewed_by'], $status['reference'], $status['reviewed_on']));

            return false;
        }

        if (!$textsReady) {
            $this->line(sprintf('  %-4s UNSAFE: its required legal texts are not all published and approved (or none is required), so it cannot sell: finish them on the legal texts screen.', $country));
        }

        if (!$reviewed) {
            $this->line(match ($status['status']) {
                StorefrontLegalReview::INCOMPLETE => sprintf('  %-4s UNSAFE: the legal review on record does not say who reviewed, where the opinion is written or on which day, so it counts for nothing and the storefront cannot sell: record it again with gcc:storefront:legal-review.', $country),
                default => sprintf('  %-4s UNSAFE: no independent legal review on record, so it cannot sell: record who reviewed the texts, where the opinion is written and the day, with gcc:storefront:legal-review.', $country),
            });
        }

        return true;
    }

    /** The checks that do not belong to one storefront: the coupons and the shipping row. Answers whether anything on them is unsafe. */
    private function sharedLines(StorefrontLaunchChecks $checks, StorefrontCurrency $currency): bool
    {
        $unsafe = false;
        $unknown = $checks->couponsOfAnUnknownStorefront();
        $stamped = $checks->couponsWithAStorefront();

        if ($unknown !== null && $unknown > 0) {
            $unsafe = true;
            $this->line(sprintf('  UNSAFE: %d coupons name a storefront that is not configured, so no storefront ever offers them.', $unknown));
        }

        if ($stamped !== null && $stamped > 0 && !$currency->isEnabled()) {
            $this->line(sprintf('  Warning: %d coupons carry a storefront while storefront currencies are off, so the default storefront offers them with amounts typed in another currency.', $stamped));
        }

        if (!$checks->couponsTrackStorefront() && $currency->isEnabled() && Schema::hasTable('coupons')) {
            $this->line('  Warning: the coupons migration has not run, so a storefront with its own currency meets no coupon until it does.');
        }

        if ($checks->inHouseShippingTypeExists() === false) {
            $unsafe = true;
            $this->line('  UNSAFE: the shop\'s shipping type row (shipping_types, seller 0) is missing.');
        }

        return $unsafe;
    }

    private function closingLine(bool $blocked, bool $unsafe): string
    {
        if ($blocked) {
            return 'Not ready: a storefront that is switched on lacks its currency, so it cannot sell. Fix what is listed above, then run this again.';
        }

        return $unsafe
            ? 'Not ready: what is marked UNSAFE above has to be fixed first, then run this again.'
            : 'Ready: every storefront that is switched on has the currency it trades in.';
    }
}
