<?php

namespace Tests\Support\Concurrency;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Noon\NoonConnector;
use App\Services\SalesChannels\Noon\NoonOrderMapper;
use App\Services\SalesChannels\Sync\ChannelOrderIngestor;
use Illuminate\Database\DeadlockException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** The operations of the noon concurrency tests (tests/Feature/Concurrency/NoonOrderConcurrencyTest.php), run in worker processes against a real engine. */
final class NoonOperations
{
    /**
     * Runs one worker's own operation, so a single run can mix several.
     *
     * `ConcurrentRunner::run()` takes ONE operation name for the whole run; a mixed run therefore needs a dispatcher registered
     * under its own name, which each worker calls with its own `operation` and `arguments`. `StockOperations::mixed` is the same
     * three lines for the stock tests -- it is named for its suite rather than for being generic, which is why this exists
     * instead of reaching across to it.
     *
     * @param array{operation: string, arguments: array<string, mixed>} $args
     */
    public static function page(array $args): mixed
    {
        return Operations::run($args['operation'], $args['arguments'], 0);
    }


    /**
     * Ships the SAME units of the same FBPI order, in several processes at the same instant.
     *
     * **noon is played by a table here**, `test_noon_shipments`, which the test creates: `shipment/get` reads it and
     * `shipment/create` inserts into it, EVERY create, duplicates included. So the test can simply count the rows -- two rows
     * is two shipments of one set of goods, which is the failure this is for, and no amount of reasoning about who read what
     * first can hide it.
     *
     * The window is real: between a worker being told "noon holds no such shipment" and its create arriving, every other worker
     * is in exactly the same place. Nothing in noon's API closes it -- there is no idempotency rule for
     * `integration_shipment_nr`, documented or otherwise -- so it is closed by one claimed row per shipment reference, and this
     * is what proves it.
     *
     * @param array{account: int, order_id: string, units: list<string>} $args
     * @return array{sent: bool, confirmed: bool, shipment: string|null, reason: string|null, needs_review?: bool}
     */
    public static function shipSameUnits(array $args): array
    {
        $account = SalesChannelAccount::findOrFail($args['account']);
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        Http::preventStrayRequests();
        Http::fake([
            '*/identity/public/v1/api/login*' => Http::response('', 200, ['Set-Cookie' => 'noon_session=abc123; Path=/']),
            '*/fbpi/v1/shipment/get*' => static function (Request $request) {
                $held = DB::table('test_noon_shipments')->where('reference', (string) ((array) $request->data())['integration_shipment_nr'])->first();

                return $held === null
                    ? Http::response([], 404)
                    : Http::response(['fbpi_order_nr' => $held->order_id, 'awbs' => [], 'items' => []]);
            },
            '*/fbpi/v1/shipment/create*' => static function (Request $request) {
                $body = (array) $request->data();
                // Recorded unconditionally, with no unique key: a duplicate must be VISIBLE, not refused by the fixture.
                DB::table('test_noon_shipments')->insert([
                    'reference' => (string) $body['integration_shipment_nr'],
                    'order_id' => (string) $body['fbpi_order_nr'],
                    'created_by' => getmypid(),
                ]);

                return Http::response([]);
            },
        ]);

        return app(NoonConnector::class)->createShipment($account, $args['order_id'], $args['units'], [['courier' => 'noon', 'awb_nr' => 'AWB-1']]);
    }

    /**
     * Ingests ONE noon FBPI order, mapped by the real `NoonOrderMapper` from a noon-shaped payload, so that the mapping itself is under the same test as the ingestion.
     *
     * A noon order arrives from two places at once in practice: the webhook noon sends when the marketplace creates it, and the next sweep of `fbpi-orders/list`. Both end
     * up here, which is why several processes doing exactly this at the same instant has to leave one order and one hold.
     *
     * @param array{account: int, order_id: string, sku: string, units: int} $args
     * @return array{outcome: string, deadlocks: int, other: list<string>}
     */
    public static function ingestSameOrder(array $args): array
    {
        $account = SalesChannelAccount::findOrFail($args['account']);
        $items = [];
        for ($unit = 1; $unit <= (int) $args['units']; $unit++) {
            $items[] = [
                'mp_item_nr' => $args['order_id'] . '-I' . $unit,
                'partner_sku' => $args['sku'],
                'mp_status' => 'MP_ITEM_STATUS_CONFIRMED',
                'integration_status' => 'INTEGRATION_ITEM_STATUS_ACKNOWLEDGED',
                'delivered_invoice_price' => 25.0,
            ];
        }

        $order = app(NoonOrderMapper::class)->map([
            'fbpi_order_nr' => $args['order_id'],
            'mp_code' => 'noon',
            'mp_order_nr' => 'MP-' . $args['order_id'],
            'mp_country_code' => 'sa',
            'customer_country_code' => 'sa',
            'merchant_code' => 'STR-ACME',
            'currency_code' => 'SAR',
            'warehouse_code' => 'WH-RUH-01',
            'order_created_at' => '2026-11-10T09:15:00Z',
            'items' => $items,
        ], $account);

        try {
            return ['outcome' => app(ChannelOrderIngestor::class)->ingest($account, $order)->outcome, 'deadlocks' => 0, 'other' => []];
        } catch (DeadlockException $deadlock) {
            // Reported rather than retried: a deadlock here is a finding, and swallowing it would hide the thing the test is for.
            return ['outcome' => 'deadlock', 'deadlocks' => 1, 'other' => [mb_substr($deadlock->getMessage(), 0, 160)]];
        }
    }
}
