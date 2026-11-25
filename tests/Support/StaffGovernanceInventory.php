<?php

namespace Tests\Support;

use App\Http\Controllers\Admin\Employee\CustomRoleController;
use App\Http\Controllers\Admin\Employee\EmployeeController;
use App\Http\Controllers\Admin\Marketplace\AuditLogController;
use App\Http\Controllers\Admin\OperationalCosts\ContractController;
use App\Http\Controllers\Admin\OperationalCosts\CostEntryController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\Settings\InhouseShopController;
use App\Http\Controllers\Admin\ThirdParty\PaymentMethodController;
use App\Http\Controllers\Admin\ThirdParty\SMSModuleController;
use App\Http\Controllers\Admin\Vendor\VendorController;

/**
 * Every controller of the admin panel that reads or writes the GOVERNANCE of the panel itself -- the employees, their roles, the countries
 * they work with, the audit trail and the credentials of the integrations -- and what a staff country scope does about it (GCC-14c).
 *
 * The third survey beside the products' and the orders', and it answers a different question. Those two ask which market a row of the SHOP
 * belongs to; this one asks who may change who may see it. The criterion it closes is that these screens were in NEITHER scan, so a screen
 * that hands an employee the power to widen their own reach could be added with nobody deciding so.
 *
 * `master_only`: the screen is behind `EnsureMasterAdmin`, so no restricted employee reaches it at all and a country scope has nothing to
 * narrow. That is a real answer and not an omission -- but it is only true while the middleware is there, so the guard asserts it on every
 * route of such a controller. `scoped`: a slice narrows it. `not_needed`: it holds no row that belongs to a market, or the rows it reads are
 * the asker's own. `out`: it still shows every market to a restricted employee; the slice or the open question that takes it is named.
 */
final class StaffGovernanceInventory
{
    public const MASTER_ONLY = 'master_only';

    public const SCOPED = 'scoped';

    public const NOT_NEEDED = 'not_needed';

    public const OUT = 'out';

    /** The slices and open questions that take what is still out. */
    public const SLICES = [
        'slice 6' => 'integrations and costs: sales channels, fulfilment providers and the operational costs',
        'owner' => 'who may hold a credential of one market, which needs the owner to say whether a gateway is per market at all (G1-13)',
    ];

    /** @var array<class-string, array{class: string, slice: string|null, reason: string}> */
    public const CONTROLLERS = [
        EmployeeController::class => [
            'class' => self::MASTER_ONLY, 'slice' => null,
            'reason' => 'Creating, listing, viewing, editing, exporting and switching off an employee, and giving one a role. The whole route group is behind EnsureMasterAdmin, so there is no such thing as a restricted employee managing staff, and the three questions a people manager would raise -- not reaching the master account, not creating an employee wider than oneself, not handing out a role one does not hold -- are answered by the gate rather than by a scope. AdminService::isOutOfReachOfStaffManagement and ::assignableRoleId guard the actions as well, so the master account and the master ROLE stay out of reach even of the first admin\'s own screens.',
        ],
        CustomRoleController::class => [
            'class' => self::MASTER_ONLY, 'slice' => null,
            'reason' => 'Creating, editing, exporting, switching off and deleting a role, which is what decides every other employee\'s modules. Behind EnsureMasterAdmin for the same reason, and the master role itself is refused by AdminService::assignableRoleId whoever asks.',
        ],
        AuditLogController::class => [
            'class' => self::SCOPED, 'slice' => null,
            'reason' => 'The audit centre. A restricted employee reads the lines that NAME one of their countries, and both reads of the screen are narrowed -- the listing and the module list of its filter, because the second would otherwise tell them which areas another market\'s actions came from. A line that names no market is nobody\'s: AuditLogger refuses to file a panel action as the default market\'s, so a reading that handed those lines to whoever holds the default market would invent the same fact at the other end.',
        ],
        ProfileController::class => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'The signed-in employee\'s OWN profile, read and written by the id of the account that asks. It reaches no other employee\'s row.',
        ],
        VendorController::class => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'Reads the admins table for one thing only: to refuse a vendor registering under the master account\'s e-mail address (Admin::masterAdminUsesEmail). That answer must hold whatever countries the employee works with. The vendor screens themselves are scoped in slice 3 (StaffOrderInventory).',
        ],
        InhouseShopController::class => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'Reads admin 1 to build the in-house shop object (its created_at). It reaches no employee\'s row as an employee. The page itself is scoped in slice 4 (StaffPromotionRoutes).',
        ],
        ContractController::class => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'Names the employees who made the revisions of a contract, to print their names beside the revisions. Those employees are whoever they were: narrowing the NAMES by the reader\'s countries would blank out the history rather than hide anything. The contract rows themselves are out of slice 6 (the operational costs).',
        ],
        CostEntryController::class => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'Names the employee who approved a cost entry, for the same reason. The cost rows themselves are out of slice 6.',
        ],
        PaymentMethodController::class => [
            'class' => self::OUT, 'slice' => 'owner',
            'reason' => 'The credentials of every payment gateway, in one global settings row each (`live_values`). A restricted employee with the third-party setup module reads and writes every market\'s. Whether a gateway belongs to a market at all is the owner\'s to say (G1-13); the gateway ACCOUNTS of GCC-06 are the per-market design, and this screen is the legacy global one.',
        ],
        SMSModuleController::class => [
            'class' => self::OUT, 'slice' => 'owner',
            'reason' => 'The credentials of every SMS gateway, the same global settings rows, and the same open question.',
        ],
    ];

    /** @return array<string, int> how many controllers there are of each status */
    public static function countsByStatus(): array
    {
        $counts = [self::MASTER_ONLY => 0, self::SCOPED => 0, self::NOT_NEEDED => 0, self::OUT => 0];
        foreach (self::CONTROLLERS as $entry) {
            $counts[$entry['class']]++;
        }

        return $counts;
    }
}
