<?php

namespace Tests\Support\Concurrency;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Sync\ChannelOrderIngestor;
use App\Services\SalesChannels\Noon\NoonOrderMapper;
use Illuminate\Database\DeadlockException;

/** The operations of the noon concurrency tests (tests/Feature/Concurrency/NoonOrderConcurrencyTest.php), run in worker processes against a real engine. */
final class NoonOperations
{
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
