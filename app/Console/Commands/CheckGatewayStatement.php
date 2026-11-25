<?php

namespace App\Console\Commands;

use App\Services\Payments\GatewayStatementCheck;
use App\Services\Storefront\StorefrontCurrency;
use Illuminate\Console\Command;

/**
 * Compares a gateway's settlement statement with the payments this shop recorded (D2-16).
 *
 * READ-ONLY and nothing about that is provisional: the comparison reports, and a person decides. A
 * command that settled what it found would be settling on the strength of a file somebody put on a disk,
 * which is not the evidence the recovery sweep settles on (the gateway's own API answer, under its own
 * switch).
 *
 * The file is an ordinary CSV with a header row naming at least a reference, an amount and a currency.
 * Providers spell those differently, so a few names are accepted for each; a header that names none of
 * them is refused with the names it looked for rather than parsed by position, because reading a column
 * of amounts as a column of references would produce a report full of confident nonsense.
 */
class CheckGatewayStatement extends Command
{
    protected $signature = 'payments:check-statement
                            {gateway : The gateway key, as addon_settings spells it (paymob_accept, fatoorah, ...)}
                            {file : A CSV of the gateway settlement lines}';

    protected $description = 'Compare a gateway statement with recorded payments, per company and currency (writes nothing)';

    /** The column names each field is accepted under, lower-cased and with spaces and hyphens read as underscores. */
    private const COLUMNS = [
        'reference' => ['reference', 'transaction_id', 'transaction', 'txn_id', 'id', 'gateway_reference'],
        'amount' => ['amount', 'amount_cents', 'total', 'gross', 'paid_amount', 'settlement_amount'],
        'currency' => ['currency', 'currency_code', 'iso_currency'],
    ];

    /** A settlement file is a few thousand lines; anything far larger is not the file the operator meant to give. */
    private const MOST_BYTES = 20 * 1024 * 1024;

    public function handle(GatewayStatementCheck $check, StorefrontCurrency $currencies): int
    {
        $gateway = (string) $this->argument('gateway');
        $file = (string) $this->argument('file');

        if (!is_file($file) || !is_readable($file)) {
            $this->error("Cannot read {$file}.");

            return self::FAILURE;
        }

        if (filesize($file) > self::MOST_BYTES) {
            $this->error(sprintf('%s is larger than %d MB, which is not a settlement statement.', $file, self::MOST_BYTES / 1024 / 1024));

            return self::FAILURE;
        }

        $lines = $this->read(file: $file);

        if ($lines === null) {
            return self::FAILURE;
        }

        $found = $check->compare(gateway: $gateway, statement: $lines);

        $this->line(sprintf('%d statement line(s) read from %s.', count($lines), $file));

        $this->table(
            ['company', 'market', 'currency', 'lines', 'statement', 'recorded', 'difference', 'matched'],
            array_map(function (array $row) use ($currencies): array {
                $decimals = $currencies->decimalsOf($row['currency'] === '' ? 'USD' : $row['currency']);
                $major = static fn (int $minor): string => number_format($minor / (10 ** $decimals), $decimals, '.', '');

                return [
                    $row['company'],
                    $row['market'] ?? '-',
                    $row['currency'],
                    $row['lines'],
                    $major($row['statement_minor']),
                    $major($row['recorded_minor']),
                    $major($row['statement_minor'] - $row['recorded_minor']),
                    $row['matched'],
                ];
            }, $found['per_market']),
        );

        foreach ([
            'in_the_statement_only' => 'The gateway took money this shop has no settled payment for',
            'recorded_only' => 'Settled here and in no line of the statement',
            'amount_differs' => 'The same reference for two different amounts',
            'currency_differs' => 'The same reference in two different currencies',
        ] as $key => $heading) {
            if ($found[$key] === []) {
                continue;
            }

            $this->newLine();
            $this->warn(sprintf('%s (%d):', $heading, count($found[$key])));
            $this->table(array_keys($found[$key][0]), array_map('array_values', $found[$key]));
        }

        $this->newLine();
        $this->info('Nothing was written. A payment is settled by the recovery sweep or by a gateway confirmation, never by a statement.');

        return self::SUCCESS;
    }

    /**
     * @return list<array{reference: string, amount: string, currency: string}>|null null when the header names no usable column
     */
    private function read(string $file): ?array
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            $this->error("Cannot open {$file}.");

            return null;
        }

        $header = fgetcsv($handle);
        $positions = $this->positionsIn(header: is_array($header) ? $header : []);

        if ($positions === null) {
            fclose($handle);
            $this->error('The header names no reference, amount or currency column. Looked for: '
                . implode('; ', array_map(static fn (string $field, array $names): string => $field . ' = ' . implode('/', $names), array_keys(self::COLUMNS), self::COLUMNS)));

            return null;
        }

        $lines = [];

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }

            $lines[] = [
                'reference' => trim((string) ($row[$positions['reference']] ?? '')),
                'amount' => trim((string) ($row[$positions['amount']] ?? '0')),
                'currency' => trim((string) ($row[$positions['currency']] ?? '')),
            ];
        }

        fclose($handle);

        return $lines;
    }

    /**
     * @param list<string|null> $header
     *
     * @return array{reference: int, amount: int, currency: int}|null
     */
    private function positionsIn(array $header): ?array
    {
        $normalised = [];
        foreach ($header as $index => $name) {
            $normalised[strtolower(str_replace([' ', '-'], '_', trim((string) $name)))] = $index;
        }

        $positions = [];
        foreach (self::COLUMNS as $field => $names) {
            foreach ($names as $name) {
                if (array_key_exists($name, $normalised)) {
                    $positions[$field] = $normalised[$name];
                    break;
                }
            }

            if (!array_key_exists($field, $positions)) {
                return null;
            }
        }

        return $positions;
    }
}
