<?php

namespace App\Services\SalesChannels\Noon;

use App\Services\SalesChannels\Data\ChannelResponse;

/**
 * Whether noon holds a shipment under one of our numbers — as THREE answers, because two is what let a duplicate through.
 *
 * An earlier version of this read returned `?array`: the shipment, or null. One null served a 503, a timeout, an auth error and
 * a 2xx whose body could not be read, and `createShipment()` took any null as a confirmed absence and sent a create. So a first
 * attempt that reached noon and whose answer was lost would be followed by a second shipment of the same goods — the one mistake
 * in this module that cannot be taken back, since cancelling a noon shipment cancels every item in it.
 *
 * The comment above that line read "a read that failed is NOT an absence" while the code returned the same null for both. The
 * rule was right and the code did the opposite, which is why the three states are now a type rather than a convention:
 *
 *  - **PRESENT** — noon answered, and the answer carries the shipment. Nothing to send.
 *  - **ABSENT** — noon answered authoritatively that it holds no such shipment. The ONLY answer a create may be made on.
 *  - **UNKNOWN** — anything else at all. Nobody knows, so nobody may create.
 *
 * ## What counts as authoritative
 *
 * Only a 404. noon's reference page for `shipment/get` **does not document what it answers for a shipment number it does not
 * hold**, so the HTTP-standard meaning is the only one available, and it is the reading this repository already uses for
 * Amazon's listing check. Everything else is UNKNOWN by construction: a 5xx, a timeout, a reset, a rate limit, a 401 or 403, a
 * rehearsal, and — the case worth naming — **a 2xx whose body carries no `fbpi_order_nr`**, which might be noon's way of saying
 * "not found" or might be a truncated response, and guessing which would be guessing about somebody's goods.
 *
 * If a real account turns out to answer a readable 2xx for a shipment it does not hold, this will report UNKNOWN and refuse to
 * ship until a person looks. That is the safe direction to be wrong in, and it is recorded in
 * docs/sales-channels/api-reference.md rather than guessed at.
 *
 * **And the derived shipment number is not a defence on its own.** noon documents no idempotency for
 * `integration_shipment_nr`; deriving the same value means a retry ASKS about the same shipment, not that re-sending it is
 * harmless.
 */
final readonly class NoonShipmentCheck
{
    public const PRESENT = 'present';

    public const ABSENT = 'absent';

    public const UNKNOWN = 'unknown';

    /** The one UNKNOWN that is not a problem: nobody was asked because the account is only rehearsing. */
    public const REHEARSAL = 'dry_run';

    /**
     * @param array{fbpi_order_nr: string, awbs: list<array<string, mixed>>, items: list<array<string, mixed>>}|null $shipment
     */
    private function __construct(
        public string $state,
        public ?array $shipment = null,
        public ?string $reason = null,
    ) {
    }

    /** @param array{fbpi_order_nr: string, awbs: list<array<string, mixed>>, items: list<array<string, mixed>>} $shipment */
    public static function present(array $shipment): self
    {
        return new self(self::PRESENT, $shipment);
    }

    public static function absent(): self
    {
        return new self(self::ABSENT);
    }

    public static function unknown(string $reason): self
    {
        return new self(self::UNKNOWN, null, $reason);
    }

    /**
     * Reads one answer from `shipment/get`.
     *
     * The order of the tests is the whole of it, and each branch names why it is not an absence.
     */
    public static function of(ChannelResponse $response): self
    {
        // A rehearsal asked nobody anything.
        if ($response->isDryRun()) {
            return self::unknown(self::REHEARSAL);
        }

        // The one authoritative absence. See the class note on why nothing else qualifies.
        if ($response->status === 404) {
            return self::absent();
        }

        if (!$response->ok()) {
            // A 5xx, a timeout, a reset, a rate limit, a 401 or 403. None of them says anything about what noon holds, and the
            // kind is kept so a person reads what actually happened rather than a flat "failed".
            return self::unknown('noon_could_not_be_asked:' . $response->kind . ($response->status === null ? '' : ':' . $response->status));
        }

        $order = trim((string) $response->get('fbpi_order_nr', ''));
        if ($order === '') {
            // A 2xx we cannot read. It MIGHT be noon's way of saying not-found; it might be a truncated or proxied body. Both
            // look identical from here, and only one of them makes creating safe.
            return self::unknown('noon_answered_without_a_shipment_we_could_read');
        }

        return self::present([
            'fbpi_order_nr' => $order,
            'awbs' => array_values(array_filter((array) $response->get('awbs', []), 'is_array')),
            'items' => array_values(array_filter((array) $response->get('items', []), 'is_array')),
        ]);
    }

    /** May a shipment be CREATED on the strength of this? Only a confirmed absence, never silence and never a failure. */
    public function mayCreate(): bool
    {
        return $this->state === self::ABSENT;
    }

    public function isPresent(): bool
    {
        return $this->state === self::PRESENT;
    }

    /** Nobody knows. A caller that was about to ship must stop and leave it for a person. */
    public function isUnknown(): bool
    {
        return $this->state === self::UNKNOWN;
    }

    /**
     * Nobody was asked because the account is rehearsing, so this UNKNOWN needs no person.
     *
     * A rehearsal has to be able to walk the whole path, and it sends nothing in any case: the write itself answers `isDryRun()`
     * and stops there. Without this, every rehearsal would come back as a shipment awaiting human review.
     */
    public function isRehearsal(): bool
    {
        return $this->state === self::UNKNOWN && $this->reason === self::REHEARSAL;
    }
}
