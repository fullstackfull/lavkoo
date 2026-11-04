<?php

namespace App\Services\DeveloperPortal;

use App\Services\Platform\Policy;
use App\Services\Platform\PolicyRegistry;

/**
 * Whether the portal may actually send a request, and what it has to be told first.
 *
 * A "try it" console on an admin panel is a request signed with whatever authority the person
 * running it has, aimed at the live shop. On a staging copy that is a convenience; on the shop that
 * takes the orders it is a loaded weapon pointed at real customers' data. So the decision to send
 * is made here, in one place, from the endpoint's own facts — never in the page that draws the
 * button, and never from anything the browser sends.
 *
 * Three tiers, and the boundaries between them are deliberately conservative:
 *
 *  - SAFE. A read: GET or HEAD on an endpoint that is not marked destructive. Sending it changes
 *    nothing, so it needs nothing but the admin session that reached this page.
 *
 *  - WRITE. Anything that is not a read. Refused unless the installation has explicitly turned
 *    writes on (DEVELOPER_CONSOLE_ALLOW_WRITES), and even then only with a typed confirmation, so
 *    the "send" that creates a real row can never be one misplaced click. Off by default in every
 *    environment — including the developer's own, because a default that is safe only when someone
 *    remembers to set it is not a default.
 *
 *  - BLOCKED. Never sent, whatever the settings say: a DELETE, or an endpoint whose path is in the
 *    money-and-identity list below. There is no configuration that makes "place an order",
 *    "refund", "withdraw" or "log somebody in" a reasonable thing for a documentation page to do,
 *    and an option to allow it would eventually be switched on by somebody who needed one call.
 */
class ConsoleGuard
{
    public const SAFE = 'safe';
    public const WRITE = 'write';
    public const BLOCKED = 'blocked';

    /** Reads. Everything else is a write until proven otherwise. */
    private const READ_METHODS = ['GET', 'HEAD'];

    /**
     * Reads that are still refused, because the answer is the dangerous part.
     *
     * A GET changes nothing, so the bar is low — but an endpoint that hands back a token, a
     * one-time code or a webhook secret hands it to whoever is looking at the screen, and the
     * transcript of a console call is exactly the kind of thing that ends up in a screenshot.
     */
    private const NEVER_READ = ['token', 'otp', 'password', 'secret', 'credential', 'webhook'];

    /**
     * Writes that are never sent, at any setting.
     *
     * Matched as substrings of the path, so a route added tomorrow under one of these is covered
     * without anybody remembering to come back here. Deliberately blunt: a read under the same
     * prefix is unaffected, so "order" costing an operator the ability to POST to an order route
     * while GET /orders still works is the trade this list is making on purpose.
     */
    private const NEVER_WRITE = [
        'auth', 'login', 'logout', 'register', 'token', 'otp', 'password', 'verify',
        'payment', 'checkout', 'order', 'place', 'refund', 'withdraw', 'wallet', 'transaction',
        'settle', 'payout', 'delete', 'remove', 'destroy', 'reset', 'revoke', 'import', 'restore',
    ];

    /**
     * @param  array<string, mixed>  $endpoint  a manifest entry
     * @return array{tier: string, allowed: bool, needs_confirmation: bool, reason_key: ?string, remedy: ?string}
     */
    public function verdict(array $endpoint, string $method): array
    {
        $method = strtoupper($method);

        if (!$this->settingEnabled('developer_console_enabled', 'console.enabled')) {
            return $this->refuse('the_api_console_is_switched_off_on_this_installation', 'DEVELOPER_CONSOLE_ENABLED=true');
        }

        // The console can only ever aim at this application's own API surface. Not a defence in
        // depth — the ONLY defence that matters: there is no URL field anywhere in it, the target
        // is a manifest entry, and an entry outside api/ is not a target it will accept.
        if (!str_starts_with(ltrim((string) ($endpoint['path'] ?? ''), '/'), 'api/')) {
            return $this->refuse('the_console_only_calls_this_shops_own_api_endpoints', null);
        }

        if (!in_array($method, $endpoint['methods'] ?? [], true)) {
            return $this->refuse('this_endpoint_does_not_answer_that_method', null);
        }

        if ($method === 'DELETE') {
            return $this->refuse('this_endpoint_removes_data_so_the_console_never_sends_it', null);
        }

        if (in_array($method, self::READ_METHODS, true) && empty($endpoint['destructive'])) {
            return $this->matches((string) $endpoint['path'], self::NEVER_READ)
                ? $this->refuse('this_endpoint_answers_with_a_secret_so_the_console_never_shows_it', null)
                : [
                    'tier' => self::SAFE,
                    'allowed' => true,
                    'needs_confirmation' => false,
                    'reason_key' => null,
                    'remedy' => null,
                ];
        }

        if ($this->matches((string) $endpoint['path'], self::NEVER_WRITE)) {
            return $this->refuse('this_endpoint_touches_money_identity_or_removal_so_the_console_never_sends_it', null);
        }

        if (!$this->settingEnabled('developer_console_allow_writes', 'console.allow_writes')) {
            return $this->refuse(
                'this_endpoint_writes_and_writes_are_switched_off_for_the_console',
                'DEVELOPER_CONSOLE_ALLOW_WRITES=true',
                self::WRITE,
            );
        }

        return [
            'tier' => self::WRITE,
            'allowed' => true,
            'needs_confirmation' => true,
            'reason_key' => null,
            'remedy' => null,
        ];
    }

    /**
     * The word the operator has to type before a write is sent.
     *
     * The HTTP method, which is short enough to type and specific enough that it cannot be typed by
     * accident into the wrong endpoint's form.
     */
    public function confirmationFor(string $method): string
    {
        return strtoupper($method);
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function matches(string $path, array $needles): bool
    {
        $path = strtolower(trim($path, '/'));

        foreach ($needles as $needle) {
            if (str_contains($path, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{tier: string, allowed: bool, needs_confirmation: bool, reason_key: ?string, remedy: ?string} */
    private function refuse(string $reasonKey, ?string $remedy, string $tier = self::BLOCKED): array
    {
        return [
            'tier' => $tier,
            'allowed' => false,
            'needs_confirmation' => false,
            'reason_key' => $reasonKey,
            'remedy' => $remedy,
        ];
    }
    /**
     * A portal switch, from the settings page, falling back to configuration.
     *
     * These were environment-only, so an operator could not see whether the console was enabled and
     * turning writes off during an incident meant editing .env and clearing caches. The config
     * value stays the fallback, so an install that sets them by environment is unchanged.
     */
    private function settingEnabled(string $policy, string $configKey): bool
    {
        try {
            $settings = app(Policy::class);

            // Stored, then environment, then the shipped default. An install that turns the console
            // off in .env must not have it turned back on by a default nobody chose.
            if ($settings->isSet($policy)) {
                return (bool) $settings->get($policy);
            }
        } catch (\Throwable) {
            // No settings table yet — fall through to configuration.
        }

        return (bool) config('developer_portal.' . $configKey, PolicyRegistry::definition($policy)['default']);
    }

}
