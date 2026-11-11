<?php

namespace App\Services\Accounting\Odoo;

use App\Services\SalesChannels\Data\ChannelResponse;

/**
 * What came back from one Odoo call, classified by what it proves about whether Odoo ACTED.
 *
 * **This class exists to keep two things apart that a boolean would merge**, and the distinction is the whole safety of writing
 * into somebody's books:
 *
 *  - **REFUSED** — Odoo answered, and the answer was a fault. The request arrived; Odoo decided against it. Sending the same
 *    thing again will be refused again, so a queue must stop and show a person rather than retry.
 *  - **UNREACHABLE / UNREADABLE** — nobody knows whether Odoo got it. A timeout after the request went, a reset, a 5xx, a 2xx
 *    whose body could not be parsed. **An entry may exist.**
 *
 * `ChannelResponse::isTransient()` must NOT be used to decide whether to create an entry again: it answers "could the same
 * request succeed later", which is true of both an auth error (nothing was written) and a lost answer (something may have
 * been). The question a journal entry asks is a different one, and `certainlyDidNotAct()` is that question.
 *
 * ## Why so little counts as "certainly did not act"
 *
 * Only a rehearsal and a request that never left the host. Not a fault — Odoo's documentation does not state that a failed RPC
 * rolls its transaction back, and this code does not rely on behaviour nobody published when the cost of being wrong is a
 * duplicate journal entry. Not a 4xx either: the gateway in front of a SaaS instance can answer 4xx for its own reasons.
 * So anything else means the queue verifies by reference before it ever writes again, and when the verification itself cannot
 * answer, a person decides.
 */
final readonly class OdooAnswer
{
    /** Odoo answered and the answer is the result of the call. */
    public const OK = 'ok';

    /** Odoo answered a fault: it arrived, and Odoo said no. */
    public const REFUSED = 'refused';

    /** Nobody knows whether Odoo got it: a timeout after the request went, a reset, a 5xx, a rate limit, an auth refusal. */
    public const UNREACHABLE = 'unreachable';

    /** Odoo answered 2xx and the body could not be read as a reply. The call may well have been carried out. */
    public const UNREADABLE = 'unreadable';

    /** Nothing was sent, because the connection is rehearsing. */
    public const REHEARSED = 'rehearsed';

    private function __construct(
        public string $state,
        public ChannelResponse $response,
        public mixed $value = null,
        public ?OdooFault $fault = null,
        public ?string $problem = null,
    ) {
    }

    public static function succeeded(ChannelResponse $response, mixed $value): self
    {
        return new self(self::OK, $response, $value);
    }

    public static function refused(ChannelResponse $response, OdooFault $fault): self
    {
        return new self(self::REFUSED, $response, null, $fault, 'odoo_refused_the_call:' . $fault->faultCode);
    }

    public static function unreachable(ChannelResponse $response): self
    {
        return new self(self::UNREACHABLE, $response, null, null, 'odoo_could_not_be_reached:' . $response->kind . ($response->status === null ? '' : ':' . $response->status));
    }

    public static function unreadable(ChannelResponse $response, string $problem): self
    {
        return new self(self::UNREADABLE, $response, null, null, $problem);
    }

    public static function rehearsed(ChannelResponse $response): self
    {
        return new self(self::REHEARSED, $response, null, null, 'rehearsal');
    }

    public function ok(): bool
    {
        return $this->state === self::OK;
    }

    public function isRefusal(): bool
    {
        return $this->state === self::REFUSED;
    }

    public function isRehearsal(): bool
    {
        return $this->state === self::REHEARSED;
    }

    /**
     * Is it CERTAIN that nothing happened in Odoo?
     *
     * The only question a posting queue may act on without verifying first, and it is deliberately almost never true. See the
     * class note: a fault is not here, and nor is a 4xx.
     */
    public function certainlyDidNotAct(): bool
    {
        return $this->state === self::REHEARSED
            || ($this->state === self::UNREACHABLE && $this->response->kind === ChannelResponse::NOT_SENT);
    }

    /**
     * Might an entry exist that we cannot see? Everything that is neither a success nor a proven non-event.
     *
     * A caller that gets true here verifies by reference and, if the verification cannot answer either, hands it to a person.
     */
    public function mayHaveActed(): bool
    {
        return !$this->ok() && !$this->certainlyDidNotAct();
    }

    /**
     * The result as a list of integers, which is what Odoo's `search` and `create` answer.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        $value = is_array($this->value) ? $this->value : (is_int($this->value) ? [$this->value] : []);

        return array_values(array_filter(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $value)));
    }

    /**
     * The result as a list of records, which is what `search_read` and `fields_get` answer.
     *
     * @return list<array<string, mixed>>
     */
    public function records(): array
    {
        return is_array($this->value) ? array_values(array_filter($this->value, 'is_array')) : [];
    }
}
