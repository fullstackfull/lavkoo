<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\TaxModule\app\Services\OrderTaxRowsReconciler;
use Throwable;

class OrderTaxRowsReconcileCommand extends Command
{
    protected $signature = 'orders:tax-rows-reconcile
        {--from= : only the orders created on or after this date, YYYY-MM-DD}
        {--to= : only the orders created on or before this date, YYYY-MM-DD}
        {--tolerance=0.005 : the largest difference, in the currency of the order, that is rounding and not a difference}
        {--list=20 : how many orders of each class are named}
        {--json : print the report as JSON}';

    protected $description = 'Read-only: what the rows of order_taxes add up to against the tax each order carries, every difference classed (short, over, without rows). It never writes, restates or repairs a row: what a finished order recorded is for finance to decide';

    public function handle(OrderTaxRowsReconciler $reconciler): int
    {
        $options = $this->validatedOptions();

        if (is_string($options)) {
            $this->error($options);

            return self::FAILURE;
        }

        if (!$reconciler->canRun()) {
            $this->error('The database has no orders, no order_taxes or no orders.total_tax_amount: there is nothing to compare.');

            return self::FAILURE;
        }

        $report = $reconciler->reconcile(from: $options['from'], to: $options['to'], tolerance: $options['tolerance'], listLimit: $options['list']);
        $differing = $report[OrderTaxRowsReconciler::SHORT]['count'] + $report[OrderTaxRowsReconciler::OVER]['count'] + $report[OrderTaxRowsReconciler::WITHOUT_ROWS]['count'];

        $this->option('json')
            ? $this->line((string) json_encode($report + ['differing' => $differing], JSON_PRETTY_PRINT))
            : $this->printReport(report: $report, options: $options);

        return $differing > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{from: ?CarbonImmutable, to: ?CarbonImmutable, tolerance: float, list: int}|string the options, or what is wrong with the ones given */
    private function validatedOptions(): array|string
    {
        if (preg_match('/^\d{1,12}(\.\d{1,8})?$/', (string) $this->option('tolerance')) !== 1) {
            return '--tolerance must be a plain number that is not negative, such as 0.005.';
        }

        if (preg_match('/^\d{1,5}$/', (string) $this->option('list')) !== 1) {
            return '--list must be a whole number that is not negative.';
        }

        $from = $this->dateOf(option: 'from');
        $to = $this->dateOf(option: 'to');

        if ($from === false || $to === false) {
            return '--from and --to must be dates such as 2026-11-01.';
        }

        return ['from' => $from?->startOfDay(), 'to' => $to?->endOfDay(), 'tolerance' => (float) $this->option('tolerance'), 'list' => (int) $this->option('list')];
    }

    /** @return CarbonImmutable|false|null the date, false when the option is not a date, null when it was not given */
    private function dateOf(string $option): CarbonImmutable|false|null
    {
        $given = $this->option($option);

        if ($given === null || $given === '') {
            return null;
        }

        try {
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $given) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $given) : false;
        } catch (Throwable) {
            return false;
        }

        return $date !== false && $date->format('Y-m-d') === $given ? $date : false;
    }

    /**
     * @param array<string, mixed> $report
     * @param array{from: ?CarbonImmutable, to: ?CarbonImmutable, tolerance: float, list: int} $options
     */
    private function printReport(array $report, array $options): void
    {
        $this->line(sprintf(
            'Orders examined: %d%s%s (rounding tolerance %s).',
            $report['orders'],
            $options['from'] === null ? '' : ', created from ' . $options['from']->toDateString(),
            $options['to'] === null ? '' : ' to ' . $options['to']->toDateString(),
            $this->option('tolerance'),
        ));
        $this->line(sprintf('Reconciled: %d. The rows of these orders add up to the tax they carry.', $report[OrderTaxRowsReconciler::RECONCILED]));

        $meaning = [
            OrderTaxRowsReconciler::SHORT => 'Short: the rows add up to less than the tax the order carries, so a report that adds the rows up under-reports it',
            OrderTaxRowsReconciler::OVER => 'Over: the rows add up to more than the tax the order carries',
            OrderTaxRowsReconciler::WITHOUT_ROWS => 'Without rows: the order carries a tax and has no row recorded for it',
        ];

        foreach ($meaning as $class => $text) {
            $found = $report[$class];
            $this->line(sprintf('%s: %d orders, carried %s, recorded %s, difference %s.', $text, $found['count'], $this->number($found['carried']), $this->number($found['recorded']), $this->number($found['carried'] - $found['recorded'])));

            foreach ($found['orders'] as $order) {
                $this->line(sprintf('  order %d: carried %s, recorded %s', $order['id'], $this->number($order['carried']), $this->number($order['recorded'])));
            }

            if ($found['count'] > count($found['orders'])) {
                $this->line(sprintf('  ... and %d more (raise --list to name them).', $found['count'] - count($found['orders'])));
            }
        }

        $this->line('Nothing was written. A difference is a finding for the finance review, not something this command repairs: the tax of the order, of its lines and of its invoice are not what differs, only the rows a report adds up.');
    }

    private function number(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 4, '.', ''), '0'), '.') ?: '0';
    }
}
