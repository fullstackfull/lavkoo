<?php

namespace App\Services\Storefront;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Which market a visitor who names none is served: Saudi Arabia to begin with, and afterwards whatever a person with the authority has
 * recorded (GCC-19, A1-03).
 *
 * The configuration ships `SA` and `GCC_DEFAULT_COUNTRY` can change it, but only with a deploy, and the plan asks that an administrator be
 * able to change it. So the choice is a recorded decision, like a storefront's lifecycle: written with who made it, when and why, in the same
 * transaction as its audit line ({@see StorefrontDecisionRecord}), and read back as the platform's default.
 *
 * It is read ONCE, at boot, and applied to the configuration ({@see applyToConfig}), never inside {@see StorefrontResolver}. The resolver is a
 * value object that several callers build directly, with no container and no database, and `fromConfig()` runs many times in a request: a
 * record read there would cost a lookup per resolve. One read at boot also means the whole process agrees about which market it is serving --
 * a web request, a console command and a queue worker alike.
 *
 * Three refusals keep the platform from being pointed at a market that cannot serve it, which is what A1-03 asks for:
 *
 *  - while storefronts are **off** the default cannot be changed at all. With them off the default market IS the platform, and moving it
 *    would move every visitor of a Saudi installation to another country's catalogue in one step.
 *  - a market the configuration does not serve, or does not have active, is refused: nothing could reach it.
 *  - a market that **may not sell** is refused -- its legal texts, their independent review, its currency, its selling company and its tax
 *    decision ({@see StorefrontPreflight}), and a person having made it active and not paused it ({@see StorefrontLifecycle}). A default
 *    that cannot take an order is a shop with the lights off.
 *
 * What it does NOT do is move a visitor who has already chosen a market: a choice outranks the default, and always did (A1-06).
 */
#[Singleton]
final class StorefrontDefault
{
    public const SETTING = 'storefront_default_country';

    private const SCHEMA_ANSWER_KEY = '_schema_has_business_settings';

    private const SCHEMA_ANSWER_SECONDS = 21600;

    public function __construct(
        private readonly StorefrontDecisionRecord $decisions,
        private readonly StorefrontSellingGate $gate,
    ) {
    }

    /**
     * Puts the recorded default in force for this process. Called once, from the application's boot.
     *
     * Anything that stops the record being read or trusted leaves the configured default alone: storefronts off, no settings table, no
     * record, a record naming a market that is no longer served or no longer able to sell, or a database that cannot answer. A default the
     * resolver would refuse must never reach it -- the resolver throws on an unservable default, which would be the whole site.
     */
    public function applyToConfig(): void
    {
        $recorded = $this->recorded();

        if ($recorded !== null) {
            config(['gcc.storefronts.default_country' => $recorded]);
        }
    }

    /**
     * The market a person recorded as the default, while it is still one the platform can serve, or null.
     *
     * Everything is inside the one guard, deliberately: whatever stops the record being read or trusted leaves the configured default alone.
     * That includes a configuration whose own default has become unservable -- the resolver refuses to be built on one, and this reader must
     * not pass that refusal on to the boot it is called from.
     */
    public function recorded(): ?string
    {
        try {
            if (!StorefrontResolver::fromConfig()->isEnabled() || !$this->settingsTableExists()) {
                return null;
            }

            $record = $this->decisions->of(setting: self::SETTING);
            $country = is_array($record) ? ($record['country'] ?? null) : null;
            $code = is_string($country) ? strtoupper(trim($country)) : '';

            return $code !== '' && $this->refusalOf($code) === null ? $code : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** The market in force for this process: the recorded one once it is applied, and otherwise the configured one. */
    public function inForce(): string
    {
        return StorefrontResolver::fromConfig()->defaultCountry();
    }

    /**
     * Records a market as the platform's default.
     *
     * @return array<string, mixed> the record that was written
     *
     * @throws InvalidArgumentException with the reason, when the change cannot be made
     */
    public function change(string $country, string $by, string $reason): array
    {
        $code = strtoupper(trim($country));
        $person = trim($by);
        $why = trim($reason);

        $this->refuse(match (true) {
            !StorefrontResolver::fromConfig()->isEnabled() => 'Storefronts are switched off, so the default storefront is the platform itself: switch them on before moving it.',
            !$this->settingsTableExists() => 'The business settings table is not there, so no decision can be recorded.',
            $person === '' || $why === '' => 'A change of the default storefront is recorded with the person who makes it and the reason.',
            StorefrontDecisionRecord::namesATool($person) => "[{$person}] is a tool, not a person with the authority to move the platform's default storefront.",
            mb_strlen($person) > StorefrontDecisionRecord::MAXIMUM_TEXT_LENGTH || mb_strlen($why) > StorefrontDecisionRecord::MAXIMUM_TEXT_LENGTH => 'The person and the reason are at most ' . StorefrontDecisionRecord::MAXIMUM_TEXT_LENGTH . ' characters.',
            default => $this->refusalOf($code),
        });

        $record = [
            'country' => $code,
            'reason' => $why,
            'changed_by' => $person,
            'changed_at' => now()->toIso8601String(),
        ];

        $this->decisions->write(
            setting: self::SETTING,
            record: $record,
            action: 'storefront.default.changed',
            country: $code,
            context: ['from' => $this->inForce()],
        );

        return $record;
    }

    /** Takes the recorded default away, so the configured one is the default again. False when there was none. */
    public function withdraw(string $by, string $reason): bool
    {
        return $this->decisions->withdraw(
            setting: self::SETTING,
            action: 'storefront.default.withdrawn',
            country: (string) ($this->recorded() ?? $this->inForce()),
            context: ['by' => trim($by), 'reason' => trim($reason)],
        );
    }

    /** Why this market may not be the platform's default, or null when it may. */
    public function refusalOf(string $country): ?string
    {
        $code = strtoupper(trim($country));
        $resolver = StorefrontResolver::fromConfig();

        if (!$resolver->servesStorefront($code)) {
            return "{$code} is not a storefront this platform serves: a visitor could not reach it, so it cannot be the one they are served.";
        }

        // Asking whether a market may sell reads its lifecycle and its prerequisites. A database that cannot answer is a refusal, not a
        // yes: a default nobody could check is one that might not be able to take an order.
        try {
            $maySell = $this->gate->maySell(country: $code, currencyCode: $resolver->currencyOf($code));
        } catch (Throwable $theGateCouldNotBeAsked) {
            // The driver's own message is not repeated: it names the database and the connection, and this answer is printed and logged.
            report($theGateCouldNotBeAsked);

            return "Whether {$code} may sell cannot be read right now, so it is not made the default.";
        }

        if (!$maySell) {
            return "{$code} may not sell yet, so it cannot be the default storefront: `php artisan gcc:storefront:state {$code} status` says what it still needs.";
        }

        return null;
    }

    private function refuse(?string $refusal): void
    {
        if ($refusal !== null) {
            throw new InvalidArgumentException($refusal);
        }
    }

    /** The same cached answer the application's boot uses, so asking here costs nothing it has not already paid. */
    private function settingsTableExists(): bool
    {
        try {
            return (bool) Cache::remember(self::SCHEMA_ANSWER_KEY, self::SCHEMA_ANSWER_SECONDS, static fn (): bool => Schema::hasTable('business_settings'));
        } catch (Throwable) {
            return false;
        }
    }
}
