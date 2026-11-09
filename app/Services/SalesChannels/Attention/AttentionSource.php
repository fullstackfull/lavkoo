<?php

namespace App\Services\SalesChannels\Attention;

/**
 * One place where work can come to rest waiting for a person, declared rather than coded.
 *
 * ## Why a declaration and not a method per table
 *
 * Nine tables across five integrations can end in a state that means "no machine will touch this again". They were each
 * built with that discipline on purpose and each one was invisible: a pharmacist had to know to go looking. A declaration
 * keeps the list in one place, lets a tenth source be added as one entry, and -- the part that matters -- makes the ALLOWED
 * ACTIONS part of the data, so the screen cannot offer an action the source never sanctioned.
 *
 * ## The rule the actions exist to enforce
 *
 * **A send that may already have happened is never sent again from here.** A refund, a return decision, a cut package, a
 * journal entry, a payment: for each of those the channel either publishes no way to ask what became of the request, or the
 * answer costs money to get wrong. So those sources declare `retryable: false` and the only things a person may do are open
 * the record and record what they found at the channel.
 *
 * `retryable: true` is for work that is idempotent BY CONSTRUCTION and nothing else -- a stock event, whose publisher compares
 * every quantity with what it last wrote, so working it again cannot take units off twice.
 */
final readonly class AttentionSource
{
    /** Go and look at the record on its own screen. Always allowed; changes nothing. */
    public const OPEN = 'open';

    /**
     * A person went to the channel, found out what really happened, and writes it down here. Settles the row as a human
     * judgement, with who and when and why. It sends NOTHING.
     */
    public const ACKNOWLEDGE = 'acknowledge';

    /**
     * Put the work back in the queue. **Only for a source that is idempotent by construction**, and `AttentionActions`
     * refuses it for any other — the one action that could cost money if it were offered by mistake.
     */
    public const PUT_BACK = 'put_back';

    /**
     * @param string $key stable identifier, used in routes and translations
     * @param string $domain which part of the business this belongs to, for grouping on the screen
     * @param class-string $model the Eloquent model
     * @param list<string> $states the states that mean a person is needed
     * @param array<string, string> $columns where to find each of: reference, account, reason, detail, since
     * @param list<string> $actions what a person may do, from the constants above
     * @param bool $retryable whether the underlying operation is idempotent by construction
     * @param string $settledState the state ACKNOWLEDGE moves a row to, where the source has one
     * @param string $stateColumn which column holds the state -- not every table calls it `state`
     */
    public function __construct(
        public string $key,
        public string $domain,
        public string $model,
        public array $states,
        public array $columns,
        public array $actions,
        public bool $retryable,
        public ?string $settledState = null,
        public ?string $pendingState = null,
        public string $stateColumn = 'state',
    ) {
    }

    public function table(): string
    {
        return (new $this->model())->getTable();
    }

    public function allows(string $action): bool
    {
        return in_array($action, $this->actions, true);
    }

    /** The translation key for this source's name on the screen. */
    public function label(): string
    {
        return 'attention_source_' . $this->key;
    }

    /**
     * Why this source's rows cannot simply be sent again, in words a person reads.
     *
     * Kept on the source rather than in the view because it is a fact about the integration, not a presentation choice: the
     * screen has to be able to say WHY the only button is "I checked".
     */
    public function whyNotRetryable(): ?string
    {
        return $this->retryable ? null : 'attention_why_' . $this->key;
    }

    public function column(string $name): ?string
    {
        return $this->columns[$name] ?? null;
    }
}
