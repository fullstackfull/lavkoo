<?php

namespace App\Services\Marketplace;

use App\Models\SellerApiKey;
use App\Models\SellerRole;
use App\Models\SellerStaff;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Who works in a shop, and what each of them may do.
 *
 * The rules here were previously written once in the vendor panel's controller and would have had
 * to be written a second time for the app. Two copies of "a staff member may only be given a role
 * belonging to the same shop" is one copy too many: the day they disagree, one surface hands out an
 * authority the other refuses, and nobody notices until it is a support ticket.
 *
 * Everything is scoped to a shop id supplied by the caller — never read from a session or a token
 * in here — so the same code serves a signed-in vendor, an API principal, and a test.
 */
class SellerTeamService
{
    public function __construct(
        private readonly SellerPermissionService $permissions,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @throws ValidationException
     */
    public function createRole(int|string $sellerId, array $input, ?SellerPrincipal $by = null): SellerRole
    {
        $role = SellerRole::create([
            'seller_id' => $sellerId,
            'name' => $input['name'],
            // Sanitised against the catalogue, so a request naming a permission that does not exist
            // stores nothing rather than a string that later reads as an authority — and narrowed
            // to what the writer holds, so writing a role is never a way to grant yourself more
            // than you were given.
            'permissions' => $this->grantable($input['permissions'] ?? [], $by),
            'status' => SellerRole::STATUS_ACTIVE,
        ]);

        $this->audit->record(
            action: 'seller.role_created',
            subject: ['type' => 'seller_role', 'id' => $role->id],
            after: ['name' => $role->name, 'permissions' => $role->permissions],
            context: ['seller_id' => $sellerId],
        );

        return $role;
    }

    public function updateRole(SellerRole $role, array $input, ?SellerPrincipal $by = null): SellerRole
    {
        $before = ['name' => $role->name, 'permissions' => $role->permissions, 'status' => $role->status];

        $role->update([
            'name' => $input['name'],
            'permissions' => $this->grantable($input['permissions'] ?? [], $by),
            'status' => $input['status'] ?? SellerRole::STATUS_ACTIVE,
        ]);

        $this->audit->record(
            action: 'seller.role_updated',
            subject: ['type' => 'seller_role', 'id' => $role->id],
            before: $before,
            after: ['name' => $role->name, 'permissions' => $role->permissions, 'status' => $role->status],
            context: ['seller_id' => $role->seller_id],
        );

        return $role;
    }

    public function deleteRole(SellerRole $role): void
    {
        // Detached first, so nobody is left holding a role that no longer exists. A staff member
        // with no role can still sign in and can do nothing, which is a safe state; one pointing at
        // a deleted row is not a state anybody reasoned about.
        SellerStaff::where(['seller_id' => $role->seller_id, 'seller_role_id' => $role->id])
            ->update(['seller_role_id' => null]);

        $this->audit->record(
            action: 'seller.role_deleted',
            subject: ['type' => 'seller_role', 'id' => $role->id],
            before: ['name' => $role->name, 'permissions' => $role->permissions],
            context: ['seller_id' => $role->seller_id],
        );

        $role->delete();
    }

    /**
     * @throws ValidationException
     */
    public function createStaff(int|string $sellerId, array $input): SellerStaff
    {
        if (SellerStaff::where(['seller_id' => $sellerId, 'email' => $input['email']])->exists()) {
            throw ValidationException::withMessages([
                'email' => translate('a_staff_member_with_this_email_already_exists'),
            ]);
        }

        $this->assertRoleBelongsToShop($input['seller_role_id'] ?? null, $sellerId);

        $staff = SellerStaff::create([
            'seller_id' => $sellerId,
            'seller_role_id' => $input['seller_role_id'] ?? null,
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
            'status' => SellerStaff::STATUS_ACTIVE,
        ]);

        $this->audit->record(
            action: 'seller.staff_added',
            subject: ['type' => 'seller_staff', 'id' => $staff->id],
            after: ['name' => $staff->name, 'email' => $staff->email, 'seller_role_id' => $staff->seller_role_id],
            context: ['seller_id' => $sellerId],
        );

        return $staff;
    }

    /**
     * @throws ValidationException
     */
    public function updateStaff(SellerStaff $staff, array $input): SellerStaff
    {
        $this->assertRoleBelongsToShop($input['seller_role_id'] ?? null, $staff->seller_id);

        $before = ['name' => $staff->name, 'seller_role_id' => $staff->seller_role_id, 'status' => $staff->status];

        $attributes = [
            'name' => $input['name'],
            'seller_role_id' => $input['seller_role_id'] ?? null,
            'status' => $input['status'] ?? SellerStaff::STATUS_ACTIVE,
        ];

        if (!empty($input['password'])) {
            $attributes['password'] = Hash::make($input['password']);
        }

        $staff->update($attributes);

        // Switching somebody off has to end the session they are already in, not merely stop the
        // next one starting. Their token is what the API reads, so it goes with the status — and
        // so do the keys they issued, which otherwise keep acting for the shop after the person
        // who created them has gone.
        if ($attributes['status'] !== SellerStaff::STATUS_ACTIVE) {
            $staff->forceFill(['auth_token' => null])->save();
            $this->revokeKeysIssuedBy($staff);
        }

        // A new password is how somebody responds to a stolen token, so it has to end the sessions
        // that token is in. Saying "updated" while every stolen bearer kept working would be worse
        // than not offering the field.
        if (!empty($input['password'])) {
            $staff->forceFill(['auth_token' => null])->save();
        }

        $this->audit->record(
            action: 'seller.staff_updated',
            subject: ['type' => 'seller_staff', 'id' => $staff->id],
            before: $before,
            after: ['name' => $staff->name, 'seller_role_id' => $staff->seller_role_id, 'status' => $staff->status],
            context: ['seller_id' => $staff->seller_id],
        );

        return $staff;
    }

    public function deleteStaff(SellerStaff $staff): void
    {
        $this->revokeKeysIssuedBy($staff);

        $this->audit->record(
            action: 'seller.staff_removed',
            subject: ['type' => 'seller_staff', 'id' => $staff->id],
            before: ['name' => $staff->name, 'email' => $staff->email],
            context: ['seller_id' => $staff->seller_id],
        );

        $staff->delete();
    }

    /**
     * End a staff member's API session without changing anything else about them.
     *
     * Separate from deactivating: an owner who thinks a phone has been lost wants the token gone
     * now and the employee still employed tomorrow.
     */
    public function signOutStaff(SellerStaff $staff): void
    {
        $staff->forceFill(['auth_token' => null])->save();

        $this->audit->record(
            action: 'seller.staff_signed_out',
            subject: ['type' => 'seller_staff', 'id' => $staff->id],
            context: ['seller_id' => $staff->seller_id],
        );
    }

    /**
     * Revoke the keys a departing employee issued.
     *
     * A key is a credential the shop owns, but it was created by a person, and the usual reason a
     * person is deactivated is that they should no longer be able to act. Leaving their keys live
     * — still carrying whatever scopes they were given — makes offboarding a half-measure, and the
     * owner cannot even see which keys were theirs.
     */
    private function revokeKeysIssuedBy(SellerStaff $staff): void
    {
        if (!Schema::hasTable('seller_api_keys')) {
            return;
        }

        $revoked = SellerApiKey::where('seller_id', $staff->seller_id)
            ->where('created_by_staff_id', $staff->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        if ($revoked > 0) {
            $this->audit->record(
                action: 'seller.api_keys_revoked_with_staff',
                subject: ['type' => 'seller_staff', 'id' => $staff->id],
                context: ['seller_id' => $staff->seller_id, 'keys' => $revoked],
            );
        }
    }

    /**
     * The permissions this principal is able to hand out.
     *
     * An owner may hand out anything in the catalogue — there is no authority above them to
     * withhold it. Anybody else may hand out only what they themselves hold, which is what stops
     * a `staff.manage` credential writing itself a role that can move money.
     *
     * @return array<int, string>
     */
    private function grantable(array $requested, ?SellerPrincipal $by): array
    {
        $valid = $this->permissions->sanitize($requested);

        if ($by === null || $by->isOwner()) {
            return $valid;
        }

        return array_values(array_filter($valid, fn (string $permission) => $by->can($permission)));
    }

    /**
     * @throws ValidationException
     */
    private function assertRoleBelongsToShop(int|string|null $roleId, int|string $sellerId): void
    {
        if ($roleId && !SellerRole::where(['id' => $roleId, 'seller_id' => $sellerId])->exists()) {
            throw ValidationException::withMessages([
                'seller_role_id' => translate('that_role_does_not_belong_to_this_shop'),
            ]);
        }
    }
}
