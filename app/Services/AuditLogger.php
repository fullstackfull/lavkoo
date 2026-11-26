<?php

namespace App\Services;

use App\Exceptions\AuditLineNotWrittenException;
use App\Http\Middleware\SellerApiAuthMiddleware;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Services\Marketplace\SellerPrincipal;
use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\StorefrontContext;
use Illuminate\Support\Facades\Schema;

/**
 * The one way anything in the system records who did what (Phase 3 — spec item 84).
 *
 * Every module records through this rather than growing its own log. It resolves the actor from
 * whichever guard is authenticated, captures their name so a later deletion does not erase the
 * trail, and stamps the request's IP — none of which a caller should have to remember.
 *
 * `record()` never throws into the caller. An audit write failing must not fail the action being audited:
 * the worst outcome of a logging problem is a missing line, which is far better than a settlement or
 * a payout rolled back because the note about it could not be saved.
 *
 * **`whileLinesAreRequired()` turns that trade around for the length of one action (G-14).** Some actions change who may do what, and for
 * those a change nobody can trace is worse than a change that did not happen. A caller that has opened a transaction marks it, and inside
 * the mark `record()` THROWS instead of swallowing, so the transaction rolls the change back. The mark also puts the asker's country scope
 * on the line, which is what makes it answer "could this employee have done this". Nothing outside the mark changes in any way, and the
 * mark is restored on the way out whatever happens, so one failed action cannot leave the platform fail-closed.
 *
 * **`recordOrFail()` is for the opposite case, and it exists because of a real one.** Some actions ARE the record: a person
 * settling an ambiguous refund by hand has produced nothing else -- no request, no reply, no channel state change -- so an
 * action reported as done whose only trace silently failed to save is a lie told to whoever reads the trail next. Such a
 * caller asks for the line and is told if it could not be written.
 */
class AuditLogger
{
    /** Whether `audit_logs` carries the two columns that say which market and company a line concerns. Asked once per process. */
    private static ?bool $trailNamesItsMarket = null;

    /** Whether a line that cannot be written is an error rather than a missing note, for the length of one action. */
    private static bool $linesAreRequired = false;

    /**
     * Carries `$work` out with the trail fail-closed: a line that cannot be written throws, so a caller that has opened a transaction
     * takes the change back with it.
     *
     * The mark is nested-safe and restored in a `finally`, because a platform left fail-closed by one failed action would refuse every
     * audited write after it -- a logging problem turned into an outage, which is the thing `record()` exists to avoid.
     *
     * @template TResult
     * @param callable(): TResult $work
     * @return TResult
     */
    public static function whileLinesAreRequired(callable $work): mixed
    {
        $was = self::$linesAreRequired;
        self::$linesAreRequired = true;

        try {
            return $work();
        } finally {
            self::$linesAreRequired = $was;
        }
    }

    public static function linesAreRequired(): bool
    {
        return self::$linesAreRequired;
    }

    /**
     * Record an action.
     *
     * @param string $action dotted name whose prefix is the module, e.g. 'settlement.approved'
     * @param array{type: string, id: int|string}|object|null $subject the record acted on
     */
    public function record(
        string $action,
        array|object|null $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?array $context = null,
        ?string $country = null,
    ): ?AuditLog {
        try {
            if (!Schema::hasTable('audit_logs')) {
                if (self::$linesAreRequired) {
                    throw new AuditLineNotWrittenException('audit_logs has not been migrated, so this action cannot be recorded: ' . $action);
                }

                return null;
            }

            [$subjectType, $subjectId] = $this->resolveSubject($subject);
            [$actorType, $actorId, $actorName] = $this->resolveActor();

            $line = AuditLog::create($this->marketOf($country) + [
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'actor_name' => $actorName,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'before' => $before,
                'after' => $after,
                'context' => $this->withTheAskersScope($context),
                'ip_address' => $this->clientIp(),
                'user_agent' => $this->userAgent(),
            ]);
        } catch (\Throwable $failed) {
            if (self::$linesAreRequired) {
                throw new AuditLineNotWrittenException('The audit line for this action could not be written: ' . $action, previous: $failed);
            }

            // A missing audit line is never worth failing the audited action.
            return null;
        }

        return $line;
    }

    /**
     * The countries the asker may work in, added to the context of an action whose line is REQUIRED and to no other (G-14).
     *
     * Spelt out as `unrestricted` rather than left absent for an employee who holds no scope row, because a reader of the trail cannot
     * tell a missing key from an unrestricted account. Outside the mark the context is returned untouched, so no existing line gains a
     * key and no existing reader of the trail sees a shape it did not see before.
     *
     * @param array<string, mixed>|null $context
     * @return array<string, mixed>|null
     */
    private function withTheAskersScope(?array $context): ?array
    {
        if (!self::$linesAreRequired) {
            return $context;
        }

        return ($context ?? []) + ['asker_scope' => $this->askersScope()];
    }

    /** @return list<string> */
    private function askersScope(): array
    {
        try {
            $asker = auth('admin')->user();
            $countries = $asker instanceof Admin ? app(StaffCountryScope::class)->countriesOf(admin: $asker) : null;
        } catch (\Throwable) {
            return ['unknown'];
        }

        return $countries ?? ['unrestricted'];
    }

    /**
     * Which market, and which company, this line concerns (G1-08).
     *
     * Six markets share one trail, so a line that says only "coupon.updated" could be any market's coupon. The market is the one the
     * CALLER names, or -- when it names none -- the storefront the request is bound to. A panel request is bound to none on purpose: an
     * administrator sees every country, so a panel action is NOT filed as the default market's, which would be an invented fact in an
     * audit trail. The company follows from the market, through the one registry that answers it.
     *
     * Nothing is written before the migration adds the columns, and the columns are read for their presence once per process, because a
     * batch of a hundred lines must not ask the database a hundred times.
     *
     * @return array<string, mixed>
     */
    private function marketOf(?string $country): array
    {
        if (!$this->trailNamesItsMarket()) {
            return [];
        }

        $market = strtoupper(trim((string) ($country ?? $this->storefrontOfTheRequest())));

        if ($market === '') {
            return [];
        }

        return ['storefront_country' => $market, 'legal_entity_id' => $this->companyOf($market)];
    }

    /** The storefront this request was narrowed to, or null where nothing narrowed it: a panel, a console command, a queued job. */
    private function storefrontOfTheRequest(): ?string
    {
        return app()->bound(StorefrontContext::class) ? app(StorefrontContext::class)->country : null;
    }

    private function companyOf(string $market): ?int
    {
        try {
            $entity = app(LegalEntityRegistry::class)->forCountry($market);
        } catch (\Throwable) {
            return null;
        }

        return $entity === null ? null : (int) $entity->id;
    }

    private function trailNamesItsMarket(): bool
    {
        return self::$trailNamesItsMarket ??= Schema::hasColumn('audit_logs', 'storefront_country')
            && Schema::hasColumn('audit_logs', 'legal_entity_id');
    }

    /** Forgets what was read about the trail's shape, and leaves the trail as it ships, for a test that migrates it mid-run. */
    public static function forget(): void
    {
        self::$trailNamesItsMarket = null;
        self::$linesAreRequired = false;
    }

    /**
     * Record an action, and **throw if the line cannot be written**.
     *
     * For an action whose only durable trace IS the audit line. `record()` swallows a failure because a missing note is
     * better than a rolled-back payout; here the reverse holds: there is nothing to roll back and nothing else to show, so a
     * silent failure would leave somebody's hand-made decision about a customer's money with no author and no date.
     *
     * A host whose `audit_logs` table has not been migrated is told so by name rather than passing silently.
     *
     * @param array{type: string, id: int|string}|object|null $subject
     * @throws \RuntimeException when the line could not be stored
     */
    public function recordOrFail(
        string $action,
        array|object|null $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?array $context = null,
        ?string $country = null,
    ): AuditLog {
        if (!Schema::hasTable('audit_logs')) {
            throw new \RuntimeException('audit_logs has not been migrated, so this decision cannot be recorded: ' . $action);
        }

        $line = $this->record($action, $subject, $before, $after, $context, $country);
        if ($line === null) {
            throw new \RuntimeException('The audit line for this decision could not be written: ' . $action);
        }

        return $line;
    }

    /**
     * @return array{0: ?string, 1: int|string|null}
     */
    private function resolveSubject(array|object|null $subject): array
    {
        if ($subject === null) {
            return [null, null];
        }

        if (is_array($subject)) {
            return [$subject['type'] ?? null, $subject['id'] ?? null];
        }

        // An Eloquent model, or anything with a key: record its class and id.
        return [get_class($subject), $subject->getKey() ?? ($subject->id ?? null)];
    }

    /**
     * @return array{0: ?string, 1: int|null, 2: ?string}
     */
    private function resolveActor(): array
    {
        // Checked in order of privilege so an admin acting is recorded as an admin even if another
        // guard also happens to resolve.
        $admin = $this->fromGuard('admin');
        if ($admin !== null) {
            return $admin;
        }

        // The seller app does not log a guard in — it carries a token the middleware resolves to a
        // principal. Without this every action taken from the app was recorded as the system doing
        // it, which is the one thing an audit trail must never say.
        $principal = $this->sellerPrincipal();
        if ($principal !== null) {
            return $this->fromPrincipal($principal);
        }

        foreach (['seller', 'customer'] as $guard) {
            $actor = $this->fromGuard($guard);
            if ($actor !== null) {
                return $actor;
            }
        }

        return ['system', null, 'System'];
    }

    /**
     * @return array{0: string, 1: ?int, 2: ?string}|null
     */
    private function fromGuard(string $guard): ?array
    {
        try {
            $user = auth($guard)->user();
        } catch (\Throwable) {
            return null;
        }

        return $user ? [$guard, (int) $user->getKey(), $this->nameOf($user)] : null;
    }

    private function sellerPrincipal(): ?SellerPrincipal
    {
        try {
            $principal = request()->attributes->get(SellerApiAuthMiddleware::PRINCIPAL);
        } catch (\Throwable) {
            return null;
        }

        return $principal instanceof SellerPrincipal ? $principal : null;
    }

    /**
     * The credential, not the shop.
     *
     * A staff member and a key are recorded as themselves rather than as the owner, because "who
     * could have done this" is the question the trail exists to answer.
     *
     * @return array{0: string, 1: ?int, 2: ?string}
     */
    private function fromPrincipal(SellerPrincipal $principal): array
    {
        return [$principal->actorType(), $principal->actorId(), $principal->actorLabel()];
    }

    private function nameOf(object $user): ?string
    {
        foreach (['name', 'f_name', 'email'] as $attribute) {
            if (!empty($user->{$attribute})) {
                return (string) $user->{$attribute};
            }
        }

        return null;
    }

    private function clientIp(): ?string
    {
        try {
            return request()->ip();
        } catch (\Throwable) {
            return null;
        }
    }

    private function userAgent(): ?string
    {
        try {
            return substr((string) request()->userAgent(), 0, 255) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
