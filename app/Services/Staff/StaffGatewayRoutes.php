<?php

namespace App\Services\Staff;

use App\Http\Controllers\Admin\Payment\OfflinePaymentMethodController;
use App\Http\Controllers\Admin\ThirdParty\PaymentMethodController;
use App\Http\Controllers\Payment_Methods\MtnCashPaymentController;

/**
 * The payment-gateway SETTINGS screens, and what an employee scoped to one country may do on them (D2-08).
 *
 * The gap in one line: these screens held `module:3rd_party_setup` and nothing else, so an employee given one market and that module could
 * read and rewrite the credentials every market takes card payments with. `Tests\Support\StaffGovernanceInventory` has recorded
 * `PaymentMethodController` as still out since the governance survey; this is the mechanism that takes it, behind a switch.
 *
 * **Why the answer for a restricted employee is the whole screen rather than a subset of its rows.** A gateway's configuration is ONE row of
 * `addon_settings` for the whole installation -- its secret key, its integration id, its HMAC, its live-or-test mode -- and the per-company
 * accounts of `payment_gateway_accounts` do not replace it: `gcc.storefronts.payment_accounts.enabled` ships off, the table ships empty,
 * only `paymob_accept` may be configured per company at all, and a market with no account of its own reads that platform row. So a write
 * here is not an act of one market: it is a write to every market's merchant account at once, including the five an employee of Kuwait was
 * never given, and its gentlest form -- pointing a gateway at the sandbox, or switching it off -- stops the shop taking money in countries
 * that employee has no standing in. A READ is no better, because these screens print the secret key and the webhook secret, which is the
 * whole of what it takes to accept somebody else's payment or to sign a confirmation of one.
 *
 * `unavailable` is therefore the honest scope, and it is not a new idea: {@see StaffIntegrationRoutes} already gives a restricted employee
 * that same answer for the MyFatoorah settings screen -- the same kind of credential screen, decided in slice 6 -- and
 * {@see StaffOrderRoutes} gives it for a hand-made wallet credit. It also does NOT answer the owner's open question of whether a gateway
 * belongs to a market at all (G1-13): a refusal needs no answer to that, where a narrowing would need one. What would make a narrowing
 * possible is a per-company gateway account with a screen of its own, which {@see \App\Services\Payments\GatewayAccountDirectory} has the
 * registry for and no screen; such an account names its market and could be checked against the employee's countries.
 *
 * Every action of a covered screen has a rule, and a route without one is REFUSED to a restricted employee rather than let through, so a new
 * action on one of these screens is unreachable to a restricted employee until somebody decides what it reads. The alternative is a new
 * credential screen that is open by default. What a rule can say:
 *
 *   none         the action reads and writes nothing that belongs to a market
 *   unavailable  it reads or writes the installation's one gateway configuration, which is every market's
 *
 * **The switch ships OFF** (`gcc.staff.gateway_settings.scoped`). While it is off every action's rule is `none`, which is these screens
 * exactly as they are today: the module permission is the whole gate and no extra query is made. The MyFatoorah screen is deliberately
 * absent, because slice 6 already covers it and two middlewares on one route would be two places with one rule between them.
 * `MtnCashPaymentController`'s customer-facing and point-of-sale actions are absent for a different reason: they are not settings, they are
 * somebody paying, and the middleware is registered on the five administrative actions alone.
 */
final class StaffGatewayRoutes
{
    /** The rule of an action that reads nothing belonging to a market. */
    public const NONE = 'none';

    /** The rule of an action that reads or writes the installation's one gateway configuration. */
    public const UNAVAILABLE = 'unavailable';

    /** The switch that puts the gateway settings screens inside the employee's country scope (D2-08). Off as it ships. */
    public const SWITCH = 'gcc.staff.gateway_settings.scoped';

    /**
     * Every action of every covered screen, with the rule it takes while {@see SWITCH} is on.
     *
     * The offline payment methods are here beside the gateways because what they hold is the same kind of fact: the bank account, the wallet
     * number or the branch a customer is told to pay into, with no market column of its own. Changing one changes where every market's
     * customers send their money.
     *
     * @var array<class-string, array<string, string>>
     */
    private const RULES = [
        PaymentMethodController::class => [
            'index' => self::UNAVAILABLE,
            'UpdatePaymentConfig' => self::UNAVAILABLE,
            'UpdateStatus' => self::UNAVAILABLE,
        ],
        OfflinePaymentMethodController::class => [
            'index' => self::UNAVAILABLE,
            'getAddView' => self::UNAVAILABLE,
            'add' => self::UNAVAILABLE,
            'getUpdateView' => self::UNAVAILABLE,
            'update' => self::UNAVAILABLE,
            'updateStatus' => self::UNAVAILABLE,
            'delete' => self::UNAVAILABLE,
        ],
        MtnCashPaymentController::class => [
            'generateKeys' => self::UNAVAILABLE,
            'activationPrecheck' => self::UNAVAILABLE,
            'activate' => self::UNAVAILABLE,
            'authTest' => self::UNAVAILABLE,
            'importOriginalKeys' => self::UNAVAILABLE,
        ],
    ];

    /** @return list<class-string> the screens whose actions a staff country scope covers */
    public static function controllers(): array
    {
        return array_keys(self::RULES);
    }

    /** Whether the gateway settings screens are inside the employee's country scope (D2-08). Off as it ships. */
    public static function areScoped(): bool
    {
        return filter_var(config(self::SWITCH, false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The rule of every action of a covered screen, by the name of the action.
     *
     * While the switch is off every rule is `none`, so these screens are what they are today and anything that reads this registry reads
     * the same answer the platform gave before the registry existed.
     *
     * @return array<string, string>
     */
    public static function rulesOf(string $controller): array
    {
        $rules = self::RULES[ltrim($controller, '\\')] ?? [];

        return self::areScoped() ? $rules : array_map(static fn (): string => self::NONE, $rules);
    }

    public static function ruleOf(string $controller, string $method): ?string
    {
        return self::rulesOf(controller: $controller)[$method] ?? null;
    }

    public static function isKnown(string $rule): bool
    {
        return in_array($rule, [self::NONE, self::UNAVAILABLE], true);
    }
}
