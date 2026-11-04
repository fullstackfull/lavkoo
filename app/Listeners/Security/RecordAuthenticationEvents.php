<?php

namespace App\Listeners\Security;

use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;

/**
 * Who signed in, who tried and failed, and who was locked out.
 *
 * A rejected password left no trace anywhere in the application. There was no `auth.*` action in
 * `app/` or `Modules/`, and neither the admin nor the vendor auth controller called the audit logger
 * at all — so a credential-stuffing run against the seller panel was indistinguishable from silence,
 * and the security panel's own remedy text said as much.
 *
 * Written as a listener on Laravel's own auth events rather than as calls in each controller, for
 * two reasons. It covers every guard at once — admin, employee, seller, seller staff, customer — and
 * it cannot be forgotten by the next login path somebody adds, which is exactly how the gap opened.
 *
 * A failed attempt has no actor by definition, so the identity that was tried is recorded as the
 * SUBJECT and the audit row's own ip_address and user_agent carry who was at the other end. Recording
 * a guessed email as the actor would put an innocent person's name against an attack on their account.
 */
class RecordAuthenticationEvents
{
    /** Identity fields a guard might authenticate by, in the order they are worth recording. */
    private const IDENTITY_FIELDS = ['email', 'identity', 'phone', 'username'];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Failed::class => 'onFailed',
            Lockout::class => 'onLockout',
            Logout::class => 'onLogout',
        ];
    }

    public function onLogin(Login $event): void
    {
        $this->audit->record(
            action: 'auth.signed_in',
            subject: ['type' => $this->subjectType($event->guard), 'id' => $event->user?->getAuthIdentifier()],
            context: ['guard' => $event->guard],
        );
    }

    public function onFailed(Failed $event): void
    {
        $this->audit->record(
            action: 'auth.sign_in_failed',
            subject: ['type' => $this->subjectType($event->guard), 'id' => $event->user?->getAuthIdentifier()],
            context: [
                'guard' => $event->guard,
                'identity' => $this->identity($event->credentials),
                // Whether the account exists at all separates a typo from a guessing run, and it is
                // already knowable to whoever is trying — recording it leaks nothing new.
                'account_exists' => $event->user !== null,
            ],
        );
    }

    public function onLockout(Lockout $event): void
    {
        $this->audit->record(
            action: 'auth.locked_out',
            context: ['identity' => $this->identity((array) $event->request->only(self::IDENTITY_FIELDS))],
        );
    }

    public function onLogout(Logout $event): void
    {
        $this->audit->record(
            action: 'auth.signed_out',
            subject: ['type' => $this->subjectType($event->guard), 'id' => $event->user?->getAuthIdentifier()],
            context: ['guard' => $event->guard],
        );
    }

    /**
     * What the identity was, without the password beside it.
     *
     * The credentials array carries the attempted password, and an audit trail is the last place it
     * may be written — so exactly one field is taken out and everything else is dropped.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function identity(array $credentials): ?string
    {
        foreach (self::IDENTITY_FIELDS as $field) {
            if (!empty($credentials[$field]) && is_scalar($credentials[$field])) {
                return mb_substr((string) $credentials[$field], 0, 191);
            }
        }

        return null;
    }

    private function subjectType(?string $guard): string
    {
        return match ($guard) {
            'admin' => 'admin',
            'seller' => 'seller',
            'customer', 'web' => 'customer',
            default => $guard ?: 'unknown',
        };
    }
}
