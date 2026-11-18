<?php

namespace App\Services\Storefront;

use App\Utils\Helpers;

/**
 * Who may take the steps of a storefront in the admin panel (I2-03, I2-11).
 *
 * It layers on the panel's role-based access exactly the way MonitoringPermissionService does: the keys are read from the `module_access` of the admin's role, the master
 * admin (the first role) holds all of them, and no existing role holds any until someone grants it in the role form. The legal texts are split in two so that a person who
 * writes a text cannot also approve and publish it: the service refuses the author as the approver, and the role refuses it before the request reaches the service.
 */
final class StorefrontPermissionService
{
    /** Marks a storefront ready, activates it, pauses it in a mode and resumes it. */
    public const LIFECYCLE = 'storefront_lifecycle';

    /** Writes the legal texts of a storefront: a draft from a template, an edit, a new version, and putting a draft away. */
    public const LEGAL_WRITE = 'storefront_legal_write';

    /** Approves a legal text written by someone else, publishes it from a day, and revokes it. */
    public const LEGAL_APPROVE = 'storefront_legal_approve';

    /**
     * @return array<string, string> the permissions a role may hold, each with the translation key of its label in the role form
     */
    public static function all(): array
    {
        return [
            self::LIFECYCLE => 'storefront_permission_lifecycle',
            self::LEGAL_WRITE => 'storefront_permission_legal_write',
            self::LEGAL_APPROVE => 'storefront_permission_legal_approve',
        ];
    }

    public static function can(string $permission): bool
    {
        return Helpers::module_permission_check(mod_name: $permission);
    }

    /** Whether the signed-in admin may open the storefront screens at all: any one of the permissions is enough to read them. */
    public static function canOpenTheScreens(): bool
    {
        return collect(array_keys(self::all()))->contains(static fn (string $permission): bool => self::can(permission: $permission));
    }
}
