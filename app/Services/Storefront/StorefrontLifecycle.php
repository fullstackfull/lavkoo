<?php

namespace App\Services\Storefront;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * I2-11 to I2-17: the state a storefront is in, kept for each country in the database. A storefront nobody has prepared is a draft. It becomes ready when its prerequisites
 * are met and a person says so (StorefrontPreflight: the texts, their independent review, the currency, the tax decision), and active when a person approves it, and only
 * an active storefront sells. An active storefront can be paused by a person in one of three modes: browse only (it is shown and cannot sell), maintenance (the apps are told,
 * and the requests that browse it are refused), or redirect (its customers are sent to the default storefront). Every change is written with its audit line, who, from, to and why,
 * in the same transaction, so a change whose trace cannot be written is not made.
 *
 * The default storefront has always been selling, so it is active without a record and needs none; it can only be paused browse-only, because a maintenance or a redirect of
 * the storefront everybody falls back to would strand its own visitors. This records what a person tells it and checks what it can: it approves nothing by itself, and a tool or
 * an assistant is refused as the person. Nothing reads any of it while storefronts are off (GCC_STOREFRONTS_ENABLED).
 */
final class StorefrontLifecycle
{
    public const DRAFT = 'draft';

    public const READY = 'ready';

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const BROWSE_ONLY = 'browse_only';

    public const MAINTENANCE = 'maintenance';

    public const REDIRECT = 'redirect';

    /** @var list<string> */
    public const MODES = [self::BROWSE_ONLY, self::MAINTENANCE, self::REDIRECT];

    public const MAXIMUM_MESSAGE_LENGTH = 500;

    private const SETTING_PREFIX = 'storefront_lifecycle_';

    /** The states a record can hold: a storefront with no record is a draft, or active when it is the default one. */
    private const RECORDED_STATES = [self::READY, self::ACTIVE, self::PAUSED];

    private ?bool $settingsTableExists = null;

    public function __construct(
        private readonly StorefrontPreflight $preflight,
        private readonly StorefrontDecisionRecord $decisions,
    ) {
    }

    /** The name of the business setting that holds the state of one storefront. */
    public static function settingName(string $country): string
    {
        return self::SETTING_PREFIX . strtolower(trim($country));
    }

    /** Whether the storefront is allowed to sell as far as its lifecycle goes: it is active. */
    public function isOpenForSale(string $country): bool
    {
        return $this->statusOf($country)['state'] === self::ACTIVE;
    }

    /** The mode a paused storefront is stopped in, or null when it is not paused. */
    public function stopModeOf(string $country): ?string
    {
        return $this->statusOf($country)['mode'];
    }

    /** What the customers of a paused storefront are told, or null when it is not paused or no message was given. */
    public function customerMessageOf(string $country): ?string
    {
        return $this->statusOf($country)['message'];
    }

    /**
     * @return array{state: string, mode: ?string, reason: ?string, message: ?string, until: ?string, changed_by: ?string, reference: ?string, changed_at: ?string, implicit: bool}
     */
    public function statusOf(string $country): array
    {
        $code = $this->codeOf($country);
        $record = $this->settingsTableExists() ? $this->decisions->of(setting: self::settingName($code)) : null;
        $state = is_array($record) ? ($record['state'] ?? null) : null;

        if (!in_array($state, self::RECORDED_STATES, true)) {
            return $this->status(state: $this->isDefault($code) ? self::ACTIVE : self::DRAFT);
        }

        $isPaused = $state === self::PAUSED;

        return [
            'state' => $state,
            'mode' => $isPaused ? $this->modeOf($record['mode'] ?? null) : null,
            'reason' => $this->textOf($record['reason'] ?? null),
            'message' => $isPaused ? $this->textOf($record['message'] ?? null) : null,
            'until' => $isPaused ? $this->textOf($record['until'] ?? null) : null,
            'changed_by' => $this->textOf($record['changed_by'] ?? null),
            'reference' => $this->textOf($record['reference'] ?? null),
            'changed_at' => $this->textOf($record['changed_at'] ?? null),
            'implicit' => false,
        ];
    }

    /**
     * A draft becomes ready: its prerequisites are met and a person says so.
     *
     * @return array<string, mixed> the record that was written
     * @throws InvalidArgumentException with the reason, when the change cannot be made
     */
    public function markReady(string $country, string $by, string $reason): array
    {
        $code = $this->codeOf($country);
        $this->refuse($this->refusalOfAnUnknownStorefront($code));
        $from = $this->statusOf($code)['state'];

        $this->refuse(match (true) {
            $this->isDefault($code) => "{$code} is the default storefront: it is active already, so there is nothing to prepare.",
            $from !== self::DRAFT => "{$code} is {$from}: only a draft storefront can be marked ready.",
            default => $this->refusalOfThePerson(code: $code, by: $by, reason: $reason) ?? $this->refusalOfThePreflight($code),
        });

        return $this->change(code: $code, from: $from, state: self::READY, by: $by, reason: $reason, action: 'storefront.lifecycle.ready');
    }

    /**
     * A ready storefront becomes active: a person approves it and says where the approval is written. The prerequisites are asked again, because they can change after it was marked ready.
     *
     * @return array<string, mixed> the record that was written
     * @throws InvalidArgumentException with the reason, when the change cannot be made
     */
    public function activate(string $country, string $by, string $reference, string $reason): array
    {
        $code = $this->codeOf($country);
        $this->refuse($this->refusalOfAnUnknownStorefront($code));
        $from = $this->statusOf($code)['state'];

        $this->refuse(match (true) {
            $this->isDefault($code) => "{$code} is the default storefront: it is active already.",
            $from !== self::READY => "{$code} is {$from}: only a ready storefront can be activated" . ($from === self::PAUSED ? ' (resume a paused one).' : ' (mark it ready first).'),
            default => $this->refusalOfThePerson(code: $code, by: $by, reason: $reason) ?? $this->refusalOfTheReference($reference) ?? $this->refusalOfThePreflight($code),
        });

        return $this->change(code: $code, from: $from, state: self::ACTIVE, by: $by, reason: $reason, action: 'storefront.lifecycle.activated', reference: trim($reference));
    }

    /**
     * An active storefront is paused in a mode, or a paused one is paused in another.
     *
     * @return array<string, mixed> the record that was written
     * @throws InvalidArgumentException with the reason, when the change cannot be made
     */
    public function pause(string $country, string $mode, string $by, string $reason, ?string $message = null, ?string $until = null): array
    {
        $code = $this->codeOf($country);
        $this->refuse($this->refusalOfAnUnknownStorefront($code));
        $from = $this->statusOf($code)['state'];
        $message = $message === null || trim($message) === '' ? null : trim($message);
        $until = $until === null || trim($until) === '' ? null : trim($until);

        $this->refuse(match (true) {
            !in_array($from, [self::ACTIVE, self::PAUSED], true) => "{$code} is {$from}: only an active storefront can be paused.",
            !in_array($mode, self::MODES, true) => 'Choose a stop mode: ' . implode(', ', self::MODES) . '.',
            $this->isDefault($code) && $mode !== self::BROWSE_ONLY => "{$code} is the default storefront: stopping it in {$mode} mode would strand its own visitors, so only " . self::BROWSE_ONLY . ' is allowed.',
            $message !== null && mb_strlen($message) > self::MAXIMUM_MESSAGE_LENGTH => 'The message for the customers is at most ' . self::MAXIMUM_MESSAGE_LENGTH . ' characters.',
            $until !== null && !$this->isAFutureMoment($until) => 'Give the end of the stop as YYYY-MM-DD HH:MM (UTC), in the future.',
            default => $this->refusalOfThePerson(code: $code, by: $by, reason: $reason),
        });

        return $this->change(code: $code, from: $from, state: self::PAUSED, by: $by, reason: $reason, action: 'storefront.lifecycle.paused', mode: $mode, message: $message, until: $until);
    }

    /**
     * A paused storefront sells again. Its prerequisites are asked again, because a decision can have been withdrawn while it was paused.
     *
     * @return array<string, mixed> the record that was written
     * @throws InvalidArgumentException with the reason, when the change cannot be made
     */
    public function resume(string $country, string $by, string $reason): array
    {
        $code = $this->codeOf($country);
        $this->refuse($this->refusalOfAnUnknownStorefront($code));
        $from = $this->statusOf($code)['state'];

        $this->refuse(match (true) {
            $from !== self::PAUSED => "{$code} is {$from}: only a paused storefront can be resumed.",
            default => $this->refusalOfThePerson(code: $code, by: $by, reason: $reason) ?? ($this->isDefault($code) ? null : $this->refusalOfThePreflight($code)),
        });

        return $this->change(code: $code, from: $from, state: self::ACTIVE, by: $by, reason: $reason, action: 'storefront.lifecycle.resumed');
    }

    /**
     * @return array<string, mixed>
     */
    private function change(string $code, string $from, string $state, string $by, string $reason, string $action, ?string $reference = null, ?string $mode = null, ?string $message = null, ?string $until = null): array
    {
        $record = [
            'state' => $state,
            'mode' => $mode,
            'reason' => trim($reason),
            'message' => $message,
            'until' => $until,
            'changed_by' => trim($by),
            'reference' => $reference ?? $this->statusOf($code)['reference'],
            'changed_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        $this->decisions->write(
            setting: self::settingName($code),
            record: $record,
            action: $action,
            country: $code,
            context: ['from' => $from, 'to' => $state, 'mode' => $mode, 'reason' => $record['reason'], 'by' => $record['changed_by'], 'reference' => $record['reference']],
        );

        return $record;
    }

    private function refusalOfThePerson(string $code, string $by, string $reason): ?string
    {
        $by = trim($by);
        $reason = trim($reason);

        return match (true) {
            $by === '' || mb_strlen($by) > StorefrontDecisionRecord::MAXIMUM_TEXT_LENGTH => 'Say who makes the change: the name of the person with the authority, in at most ' . StorefrontDecisionRecord::MAXIMUM_TEXT_LENGTH . ' characters.',
            StorefrontDecisionRecord::namesATool($by) => 'A change is made by a person with the authority, not by a tool or an assistant.',
            $reason === '' || mb_strlen($reason) > StorefrontDecisionRecord::MAXIMUM_TEXT_LENGTH => 'Say why, in at most ' . StorefrontDecisionRecord::MAXIMUM_TEXT_LENGTH . ' characters.',
            !Schema::hasTable('business_settings') => 'There is no business_settings table.',
            default => null,
        };
    }

    private function refusalOfAnUnknownStorefront(string $code): ?string
    {
        return in_array($code, StorefrontResolver::fromConfig()->configuredCountries(), true) ? null : "{$code} is not a configured storefront.";
    }

    private function refusalOfTheReference(string $reference): ?string
    {
        $reference = trim($reference);

        return $reference === '' || mb_strlen($reference) > StorefrontDecisionRecord::MAXIMUM_TEXT_LENGTH
            ? 'Say where the approval is written down: the decision, the ticket or the minutes, in at most ' . StorefrontDecisionRecord::MAXIMUM_TEXT_LENGTH . ' characters.'
            : null;
    }

    private function refusalOfThePreflight(string $code): ?string
    {
        $unmet = $this->preflight->unmet(country: $code, currencyCode: StorefrontResolver::fromConfig()->currencyOf($code));

        return $unmet === [] ? null : "{$code} does not meet what it needs to sell: " . implode(', ', $unmet) . ". The state of a storefront shows each one: php artisan gcc:storefront:state {$code}.";
    }

    private function refuse(?string $refusal): void
    {
        if ($refusal !== null) {
            throw new InvalidArgumentException($refusal);
        }
    }

    private function isDefault(string $code): bool
    {
        return $code === StorefrontResolver::fromConfig()->defaultCountry();
    }

    private function codeOf(string $country): string
    {
        return strtoupper(trim($country));
    }

    private function isAFutureMoment(string $text): bool
    {
        $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $text, new DateTimeZone('UTC'));

        return $moment !== false && $moment->format('Y-m-d H:i') === $text && $moment > CarbonImmutable::now('UTC');
    }

    /** A paused record that names no known mode is stopped the safe way: browse only still serves what a customer already has. */
    private function modeOf(mixed $mode): string
    {
        return in_array($mode, self::MODES, true) ? $mode : self::BROWSE_ONLY;
    }

    private function settingsTableExists(): bool
    {
        return $this->settingsTableExists ??= Schema::hasTable('business_settings');
    }

    /** @return array{state: string, mode: null, reason: null, message: null, until: null, changed_by: null, reference: null, changed_at: null, implicit: true} */
    private function status(string $state): array
    {
        return ['state' => $state, 'mode' => null, 'reason' => null, 'message' => null, 'until' => null, 'changed_by' => null, 'reference' => null, 'changed_at' => null, 'implicit' => true];
    }

    private function textOf(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
