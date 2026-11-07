<?php

namespace App\Services\SalesChannels\Dispatch;

use App\Models\ProviderFulfillment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A provider's error message may repeat what it was sent ("Invalid receiver Sara Alharbi at King Fahd Rd 12"). The Fulfillment module keeps that text so that a person can see what went wrong, and
 * its own redaction knows patterns (phones, e-mails), not the name or street of one buyer. For the orders made here the buyer's own values are known, so they are taken out of every place that
 * text lands: the fulfilment's last error, the provider call log and the provider's last-error line. The words around them stay, so the message is still readable.
 *
 * It matches whole values (the name as sent, the street line as sent, the phone in its usual spellings), never a part of a name: a part would also hide ordinary words. A value the provider
 * changed before echoing it (cut, translated) is not found; that is what the removal after the shipment is accepted is for.
 *
 * Safe to run as often as you like.
 */
final class BuyerEchoScrubber
{
    private const MASK = '«redacted»';

    /** @return int how many stored texts were changed */
    public function scrubOrder(int $internalOrderId): int
    {
        $tokens = $this->valuesOf($internalOrderId);
        if ($tokens === []) {
            return 0;
        }

        $fulfillments = ProviderFulfillment::where('order_id', $internalOrderId)->get(['id', 'provider', 'last_error_message']);
        $changed = 0;
        foreach ($fulfillments as $fulfillment) {
            $changed += $this->update('provider_fulfillments', 'id', $fulfillment->id, 'last_error_message', $fulfillment->last_error_message, $tokens);
            $changed += $this->scrubLog($fulfillment->id, $tokens);
            $changed += $this->scrubSyncState((string) $fulfillment->provider, $tokens);
        }

        return $changed;
    }

    /** @param list<string> $tokens */
    private function scrubLog(int $fulfillmentId, array $tokens): int
    {
        if (!Schema::hasTable('provider_api_logs')) {
            return 0;
        }

        $changed = 0;
        foreach (DB::table('provider_api_logs')->where('provider_fulfillment_id', $fulfillmentId)->get(['id', 'error_message', 'response_summary']) as $row) {
            $changed += $this->update('provider_api_logs', 'id', $row->id, 'error_message', $row->error_message, $tokens);
            $changed += $this->update('provider_api_logs', 'id', $row->id, 'response_summary', $row->response_summary, $tokens);
        }

        return $changed;
    }

    /** @param list<string> $tokens */
    private function scrubSyncState(string $provider, array $tokens): int
    {
        if (!Schema::hasTable('provider_sync_states')) {
            return 0;
        }

        $changed = 0;
        foreach (DB::table('provider_sync_states')->where('provider', $provider)->whereNotNull('last_error')->get(['id', 'last_error']) as $row) {
            $changed += $this->update('provider_sync_states', 'id', $row->id, 'last_error', $row->last_error, $tokens);
        }

        return $changed;
    }

    /** @param list<string> $tokens */
    private function update(string $table, string $key, int|string $id, string $column, ?string $text, array $tokens): int
    {
        if ($text === null || $text === '') {
            return 0;
        }
        $clean = str_ireplace($tokens, self::MASK, $text);

        return $clean === $text ? 0 : DB::table($table)->where($key, $id)->update([$column => $clean]);
    }

    /** @return list<string> the buyer's values as an echo would spell them, longest first */
    private function valuesOf(int $internalOrderId): array
    {
        $address = (array) json_decode((string) DB::table('orders')->where('id', $internalOrderId)->value('shipping_address_data'), true);
        $values = [trim((string) ($address['contact_person_name'] ?? '')), trim((string) ($address['address'] ?? ''))];
        $digits = preg_replace('/\D+/', '', (string) ($address['phone'] ?? '')) ?? '';
        if (strlen($digits) >= 9) {
            $national = substr($digits, -9);
            array_push($values, '0' . $national, $national, '966' . $national, '00966' . $national, '+966' . $national);
        }

        $values = array_values(array_unique(array_filter($values, static fn (string $value): bool => mb_strlen($value) >= 5)));
        usort($values, static fn (string $first, string $second): int => mb_strlen($second) <=> mb_strlen($first));

        return $values;
    }
}
