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

        // BOTH fields must be THERE and both must say yes. `status_id` is required explicitly rather than cast, because `(int) null` is 0 and `(int) "OK"` is 0 too: a cast
        // would have turned a missing or unreadable id into noon's success value and let `status_code: "OK"` alone stand for a confirmed write. That is the exact false
        // success this class exists to prevent, so an id that is absent, null, or not a whole number is not success — it is an answer we do not understand.
        $id = $status['status_id'] ?? null;
        $idSaysOk = (is_int($id) || (is_string($id) && $id !== '' && ctype_digit(ltrim($id, '-'))) || (is_float($id) && $id === floor($id)))
            && (int) $id === NoonOperations::ITEM_OK_ID;
        $ok = $code === NoonOperations::ITEM_OK_CODE && $idSaysOk;

        return new self(
            key: $key,
            ok: $ok,
            code: $code,
            message: isset($status['message']) && (string) $status['message'] !== '' ? (string) $status['message'] : null,
        );
    }

    /**
     * What to show a person: noon's own code, and its own words where it gave any.
     *
     * A code that arrived without a usable `status_id` is named as such rather than shown bare, because "OK" on its own is the most misleading thing this could print.
     */
    public function reason(): string
    {
        $code = $this->code ?? 'no_status_from_noon';
        if ($this->code === NoonOperations::ITEM_OK_CODE && !$this->ok) {
            $code = 'ok_without_a_usable_status_id';
        }

        return trim($code . ($this->message === null ? '' : ': ' . $this->message));
    }
}
