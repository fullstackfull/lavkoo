<?php

namespace App\Services\SalesChannels\Noon;

/**
 * What noon said about ONE item inside a 200.
 *
 * This class exists because of one sentence in noon's pricing guide: *"a `200` response does not mean every price was set."* Every batch call noon offers answers one
 * `items[]` entry per item submitted, each carrying its own `status`, and a caller that read only the HTTP status would report a price nobody set and a quantity noon never
 * took. So the per-item status is the result, and the HTTP status only says whether the request was processed at all.
 *
 * Success is `status_code == "OK"` with `status_id == 0`. Anything else means THAT item failed and its value at noon did not change.
 */
final readonly class NoonItemStatus
{
    private function __construct(
        public string $key,
        public bool $ok,
        public ?string $code,
        public ?string $message,
    ) {
    }

    /**
     * Reads one `items[]` entry.
     *
     * `$key` is how the caller recognises the item again: noon echoes back the fields it was given (`partner_sku`, `warehouse_code`, `country_code`, `barcode`), never a
     * position, so an answer is matched by its own identifiers rather than by the order it came in.
     *
     * @param array<string, mixed> $item
     */
    public static function of(array $item, string $key): self
    {
        $status = (array) ($item['status'] ?? []);
        $code = isset($status['status_code']) ? (string) $status['status_code'] : null;
        $id = $status['status_id'] ?? null;

        // Both have to agree. An answer with neither field is NOT taken as success: a missing status is an answer we do not understand, and reading it as "fine" is the
        // whole mistake this class is here to prevent.
        $ok = $code === NoonOperations::ITEM_OK_CODE && (int) $id === NoonOperations::ITEM_OK_ID;

        return new self(
            key: $key,
            ok: $ok,
            code: $code,
            message: isset($status['message']) && (string) $status['message'] !== '' ? (string) $status['message'] : null,
        );
    }

    /** What to show a person: noon's own code, and its own words where it gave any. */
    public function reason(): string
    {
        return trim(($this->code ?? 'no_status_from_noon') . ($this->message === null ? '' : ': ' . $this->message));
    }
}
