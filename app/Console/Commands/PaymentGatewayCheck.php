<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Payments\GatewayReadiness;
use Illuminate\Support\Facades\DB;

/**
 * Why a payment gateway is not taking payments, without printing a single credential.
 *
 * A gateway's settings live in addon_settings as two JSON blobs — live_values and test_values — and
 * the controllers read the ONE that matches the row's `mode` column. That is the fault nobody can
 * see from the admin screen: the form shows the fields filled in, because they ARE filled in, in the
 * column the gateway is not reading. A shop can sit with a switched-on gateway, correct credentials
 * saved under `test`, `mode` set to `live`, and a checkout that refuses every payment.
 *
 * So this reports the column in force and which of its fields are blank, BY NAME. No value is ever
 * read out — the point is to be safe to run on a live shop and safe to paste into a support chat.
 *
 *   php artisan payment:check            all configured gateways
 *   php artisan payment:check paymera    one of them
 *
 * Exits non-zero when a gateway that is SWITCHED ON has a gap, so it can gate a deploy.
 */
class PaymentGatewayCheck extends Command
{
    protected $signature = 'payment:check {gateway? : one gateway key, e.g. paymera}';

    protected $description = 'Report which payment gateways can actually take a payment, and why not';

    public function handle(): int
    {
        $wanted = $this->argument('gateway');

        try {
            $rows = DB::table('addon_settings')
                ->where('settings_type', 'payment_config')
                ->when($wanted !== null, fn ($query) => $query->where('key_name', $wanted))
                ->orderBy('key_name')
                ->get();
        } catch (\Throwable $exception) {
            $this->error('addon_settings could not be read: ' . $exception->getMessage());

            return self::FAILURE;
        }

        if ($rows->isEmpty()) {
            $this->warn($wanted === null
                ? 'No payment gateway is configured at all (no addon_settings row with settings_type=payment_config).'
                : "No configuration row exists for '{$wanted}'. Save it once in Admin → 3rd Party Setup → Payment Methods.");

            return self::FAILURE;
        }

        $table = [];
        $faults = [];
        $inTestMode = [];
        $broken = 0;

        foreach ($rows as $row) {
            $active = (int) ($row->is_active ?? 0) === 1;
            $verdict = $this->verdict($row);

            // Configured correctly and pointed at the wrong world. Not a fault — a shop is
            // legitimately in test mode while it is being set up — but it is the difference between
            // taking money and rehearsing, and nothing on the checkout says which is happening.
            if ($active && $row->mode === 'test' && $verdict === 'ready') {
                $inTestMode[] = $row->key_name;
            }

            if ($verdict !== 'ready') {
                // Below the table, not inside it. This sentence is the answer, and a table cell
                // wraps it into unreadable fragments on any terminal narrower than the machine it
                // was written on.
                $faults[] = ($active ? '  ' : '  (off) ') . $row->key_name . ': ' . $verdict;
            }
            if ($active && $verdict !== 'ready') {
                $broken++;
            }

            $table[] = [
                $row->key_name,
                $active ? 'on' : 'off',
                var_export($row->mode, true),
                app(GatewayReadiness::class)->columnInForce($row->mode) ?? '—',
                $verdict === 'ready' ? 'ready' : 'CANNOT TAKE PAYMENTS',
            ];
        }

        $this->table(['gateway', 'switched', 'mode', 'reads', 'verdict'], $table);

        if ($faults !== []) {
            $this->newLine();
            $this->line('Why:');
            foreach ($faults as $fault) {
                $this->line($fault);
            }
        }

        if ($inTestMode !== []) {
            $this->newLine();
            $this->warn('Switched on, and in TEST mode: ' . implode(', ', $inTestMode));
            $this->line('  Every real checkout goes to the gateway\'s test environment, so no money moves.');
            $this->line('  To take real payments: set the gateway to Live and save the LIVE credentials under it.');
        }

        $this->newLine();

        if ($broken > 0) {
            $this->error($broken . ' switched-on gateway(s) cannot take a payment.');
            $this->line('Nothing above prints a credential — only field names — so it is safe to share.');

            return self::FAILURE;
        }

        $this->info('Every switched-on gateway has the credentials it reads.');

        return self::SUCCESS;
    }


    /**
     * The row's fault in one phrase, or "ready".
     *
     * Field NAMES only. A gateway's token is exactly the thing a support paste must not carry.
     */
    private function verdict(object $row): string
    {
        return app(GatewayReadiness::class)->verdict($row);
    }

}
