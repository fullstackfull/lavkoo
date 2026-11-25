<?php

namespace App\Services\Payments;

use App\Models\PaymentRequest;
use App\Services\Storefront\StorefrontCurrency;
use Illuminate\Container\Attributes\Singleton;

/**
 * What a gateway says it took, against what this shop recorded, per company and per currency (D2-16).
 *
 * The gateway's own statement is the only record that is not this platform's, which is what makes it
 * worth comparing: a payment row says what the shop THINKS happened, and a settlement line says what the
 * money actually did. Four things can be wrong, and each is a different problem for a different person:
 *
 *   - a line the shop has no settled payment for -- the gateway took money the shop never recorded, which
 *     is the case a recovery sweep exists to prevent and the one finance has to chase;
 *   - a settled payment no line mentions -- the shop recorded money the gateway has not paid out;
 *   - the same reference for two different amounts -- one of the two records is wrong about the money;
 *   - the same reference in two different currencies, which is a worse version of the same thing.
 *
 * READ-ONLY, and that is a design rule rather than a limitation of this version. A comparison that
 * settled what it found would settle on the strength of a file an operator put on a disk; the sweep
 * settles on the strength of the gateway's own API answer, under its own switch, and those are not the
 * same evidence. Nothing here writes.
 *
 * PER COMPANY AND PER CURRENCY, NEVER ACROSS. Every total is kept under one (company, currency) key and
 * two currencies are never added: the platform has six markets whose money is not interchangeable, and a
 * sum over them is a number that means nothing. Amounts are compared in the MINOR UNIT of the line's own
 * currency ({@see StorefrontCurrency::decimalsOf}), so a dinar's third decimal is a unit of comparison
 * and not a rounding error -- comparing 12.345 and 12.35 as floats is how a four-fils difference becomes
 * "equal".
 *
 * A payment carrying no company stamp belongs to the platform itself, and is reported under the company
 * key `platform` rather than being dropped or guessed at. That is every payment on the platform today.
 */
#[Singleton]
final class GatewayStatementCheck
{
    /** The company key a payment that names no company is reported under: the platform's own row. */
    public const PLATFORM = 'platform';

    public function __construct(
        private readonly PaymentAccountBinding $binding,
        private readonly StorefrontCurrency $currencies,
    ) {
    }

    /**
     * @param list<array{reference: string, amount: int|float|string, currency: string}> $statement the gateway's settlement lines
     *
     * @return array{
     *     per_market: array<string, array{company: string, market: ?string, currency: string, lines: int, statement_minor: int, recorded_minor: int, matched: int}>,
     *     in_the_statement_only: list<array{reference: string, amount: string, currency: string}>,
     *     recorded_only: list<array{payment: string, reference: string, amount: string, currency: string, company: string}>,
     *     amount_differs: list<array{reference: string, statement: string, recorded: string, currency: string, company: string}>,
     *     currency_differs: list<array{reference: string, statement: string, recorded: string}>
     * }
     */
    public function compare(string $gateway, array $statement): array
    {
        $recorded = $this->recordedPayments(gateway: $gateway);
        $found = [
            'per_market' => [],
            'in_the_statement_only' => [],
            'recorded_only' => [],
            'amount_differs' => [],
            'currency_differs' => [],
        ];

        foreach ($statement as $line) {
            $reference = trim((string) ($line['reference'] ?? ''));
            $currency = strtoupper(trim((string) ($line['currency'] ?? '')));
            $amount = (string) ($line['amount'] ?? '0');

            if ($reference === '' || $currency === '') {
                // A line that names no transaction or no currency cannot be compared with anything, and
                // guessing which payment it meant is how a comparison invents a match.
                $found['in_the_statement_only'][] = ['reference' => $reference, 'amount' => $amount, 'currency' => $currency];
                continue;
            }

            $payment = $recorded[$reference] ?? null;

            if ($payment === null) {
                $found['in_the_statement_only'][] = ['reference' => $reference, 'amount' => $amount, 'currency' => $currency];
                $this->addToMarket($found, company: self::PLATFORM, market: null, currency: $currency, statementMinor: $this->minor($amount, $currency), recordedMinor: 0, matched: false);
                continue;
            }

            unset($recorded[$reference]);

            if ($payment['currency'] !== $currency) {
                $found['currency_differs'][] = ['reference' => $reference, 'statement' => $currency, 'recorded' => $payment['currency']];
                $this->addToMarket($found, company: $payment['company'], market: $payment['market'], currency: $currency, statementMinor: $this->minor($amount, $currency), recordedMinor: 0, matched: false);
                continue;
            }

            $statementMinor = $this->minor($amount, $currency);
            $recordedMinor = $this->minor($payment['amount'], $currency);
            $agrees = $statementMinor === $recordedMinor;

            if (!$agrees) {
                $found['amount_differs'][] = [
                    'reference' => $reference,
                    'statement' => $amount,
                    'recorded' => $payment['amount'],
                    'currency' => $currency,
                    'company' => $payment['company'],
                ];
            }

            $this->addToMarket($found, company: $payment['company'], market: $payment['market'], currency: $currency, statementMinor: $statementMinor, recordedMinor: $recordedMinor, matched: $agrees);
        }

        // Whatever is left was settled here and is in no line of the statement.
        foreach ($recorded as $reference => $payment) {
            $found['recorded_only'][] = [
                'payment' => $payment['payment'],
                'reference' => (string) $reference,
                'amount' => $payment['amount'],
                'currency' => $payment['currency'],
                'company' => $payment['company'],
            ];
            $this->addToMarket($found, company: $payment['company'], market: $payment['market'], currency: $payment['currency'], statementMinor: 0, recordedMinor: $this->minor($payment['amount'], $payment['currency']), matched: false);
        }

        ksort($found['per_market']);

        return $found;
    }

    /**
     * Every settled payment of this gateway, by the GATEWAY's own reference, with the company and market
     * it was taken for as the payment froze them.
     *
     * @return array<string, array{payment: string, amount: string, currency: string, company: string, market: ?string}>
     */
    private function recordedPayments(string $gateway): array
    {
        $byReference = [];

        PaymentRequest::query()
            ->where('payment_method', $gateway)
            ->where('is_paid', 1)
            ->whereNotNull('transaction_id')
            ->orderBy('created_at')
            ->each(function (PaymentRequest $payment) use (&$byReference): void {
                $reference = trim((string) $payment->transaction_id);

                if ($reference === '') {
                    return;
                }

                $record = $this->binding->of(payment: $payment);

                $byReference[$reference] = [
                    'payment' => (string) $payment->id,
                    'amount' => (string) $payment->payment_amount,
                    'currency' => strtoupper((string) $payment->currency_code),
                    'company' => $record?->companyId === null ? self::PLATFORM : (string) $record->companyId,
                    'market' => $record?->market,
                ];
            });

        return $byReference;
    }

    /**
     * An amount in the smallest coin of its own currency, as an integer.
     *
     * Floats are the wrong unit for this comparison and the dinar is why: 12.345 and 12.35 differ by four
     * fils, and a float comparison at two decimals calls them equal. The decimals come from the currency
     * ({@see StorefrontCurrency::decimalsOf}), which gives the dinar and the two rials three.
     */
    private function minor(int|float|string $amount, string $currency): int
    {
        $decimals = $this->currencies->decimalsOf($currency === '' ? 'USD' : $currency);

        return (int) round((float) $amount * (10 ** $decimals));
    }

    /** @param array{per_market: array<string, array<string, mixed>>} $found */
    private function addToMarket(array &$found, string $company, ?string $market, string $currency, int $statementMinor, int $recordedMinor, bool $matched): void
    {
        $key = $company . '|' . ($currency === '' ? '?' : $currency);

        $found['per_market'][$key] ??= [
            'company' => $company,
            'market' => $market,
            'currency' => $currency,
            'lines' => 0,
            'statement_minor' => 0,
            'recorded_minor' => 0,
            'matched' => 0,
        ];

        $found['per_market'][$key]['lines']++;
        $found['per_market'][$key]['statement_minor'] += $statementMinor;
        $found['per_market'][$key]['recorded_minor'] += $recordedMinor;
        $found['per_market'][$key]['matched'] += $matched ? 1 : 0;
        $found['per_market'][$key]['market'] ??= $market;
    }
}
