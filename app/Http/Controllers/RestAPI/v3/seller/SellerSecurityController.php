<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Services\Platform\PasswordPolicy;
use App\Models\SellerRole;
use App\Models\SellerStaff;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Marketplace\SellerAuditTrailService;
use App\Services\Marketplace\SellerPermissionService;
use App\Services\Marketplace\SellerTeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The shop's own security desk: who works here, what they may do, and what has been done.
 *
 * The rules live in `SellerTeamService`, which the vendor panel calls too — the same authority
 * granted through two doors has to mean the same thing, and a permission model that differs between
 * the panel and the app is a permission model nobody can reason about.
 *
 * Nothing here ever returns a credential. Whether somebody holds a live token is the useful fact;
 * its value is the shop itself.
 */
class SellerSecurityController extends Controller
{
    public function __construct(
        private readonly SellerPermissionService $permissions,
        private readonly SellerTeamService $team,
        private readonly SellerAuditTrailService $trail,
    ) {
    }

    #[ApiDoc(
        summary: 'Every permission a role can be given',
        description: 'Grouped as the panel groups them, so the app builds its form from the server '
            . 'rather than shipping a copy of the list that goes stale the first time one is added.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function permissions(): JsonResponse
    {
        return response()->json(['groups' => $this->permissions->catalog()], 200);
    }

    #[ApiDoc(
        summary: 'The shop\'s roles',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function roles(Request $request): JsonResponse
    {
        return response()->json([
            'roles' => $this->permissions->rolesFor($request->seller->id)
                ->map(fn (SellerRole $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'permissions' => is_array($role->permissions) ? $role->permissions : [],
                    'status' => $role->status,
                    'staff_count' => SellerStaff::where('seller_role_id', $role->id)->count(),
                ])->values(),
        ], 200);
    }

    #[ApiDoc(
        summary: 'Create a role',
        description: 'Permissions are sanitised against the catalogue, so a request naming one that '
            . 'does not exist stores nothing rather than a string that later reads as an authority.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function storeRole(Request $request): JsonResponse
    {
        if ($refusal = $this->refuseIntegration($this->principal($request))) {
            return $refusal;
        }

        $validator = validator($request->all(), [
            'name' => 'required|string|max:120',
            'permissions' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->refuse($validator->errors()->toArray());
        }

        $role = $this->team->createRole($request->seller->id, $validator->validated(), $this->principal($request));

        return response()->json(['message' => translate('role_created'), 'id' => $role->id], 201);
    }

    #[ApiDoc(
        summary: 'Rewrite a role',
        description: 'Takes effect on every holder\'s next request: permissions are read at request '
            . 'time rather than baked into a token, so revoking one does not wait for a sign-out.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function updateRole(Request $request, $id): JsonResponse
    {
        if ($refusal = $this->refuseIntegration($this->principal($request))) {
            return $refusal;
        }

        $role = $this->ownedRole($request, $id);

        if (!$role) {
            return $this->notFound('role');
        }

        $validator = validator($request->all(), [
            'name' => 'required|string|max:120',
            'permissions' => 'nullable|array',
            'status' => 'nullable|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return $this->refuse($validator->errors()->toArray());
        }

        $this->team->updateRole($role, $validator->validated(), $this->principal($request));

        return response()->json(['message' => translate('role_updated')], 200);
    }

    #[ApiDoc(
        summary: 'Delete a role',
        description: 'Anybody holding it is detached rather than deleted. A staff member with no role '
            . 'can sign in and do nothing, which is a state somebody reasoned about; one pointing at '
            . 'a deleted row is not.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function destroyRole(Request $request, $id): JsonResponse
    {
        if ($refusal = $this->refuseIntegration($this->principal($request))) {
            return $refusal;
        }

        $role = $this->ownedRole($request, $id);

        if (!$role) {
            return $this->notFound('role');
        }

        $this->team->deleteRole($role);

        return response()->json(['message' => translate('role_deleted')], 200);
    }

    #[ApiDoc(
        summary: 'Who works in this shop',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function staff(Request $request): JsonResponse
    {
        return response()->json([
            'staff' => $this->permissions->staffFor($request->seller->id)
                ->map(fn (SellerStaff $staff) => [
                    'id' => $staff->id,
                    'name' => $staff->name,
                    'email' => $staff->email,
                    'seller_role_id' => $staff->seller_role_id,
                    'role' => $staff->role?->name,
                    'status' => $staff->status,
                    'signed_in' => !empty($staff->auth_token),
                    'last_login_at' => $staff->last_login_at,
                ])->values(),
        ], 200);
    }

    #[ApiDoc(
        summary: 'Add somebody to the shop',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function storeStaff(Request $request): JsonResponse
    {
        if ($refusal = $this->refuseIntegration($this->principal($request))) {
            return $refusal;
        }

        $validator = validator($request->all(), [
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:191',
            'password' => app(PasswordPolicy::class)->ruleString(),
            'seller_role_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return $this->refuse($validator->errors()->toArray());
        }

        try {
            $staff = $this->team->createStaff($request->seller->id, $validator->validated());
        } catch (ValidationException $exception) {
            return $this->refuse($exception->errors());
        }

        return response()->json(['message' => translate('staff_member_added'), 'id' => $staff->id], 201);
    }

    #[ApiDoc(
        summary: 'Change what somebody may do, or switch them off',
        description: 'A full replacement, as PUT implies and as the vendor panel\'s form does: a '
            . 'request that omits seller_role_id removes the role rather than leaving it alone, so '
            . 'send the whole member. Switching one off ends the session they are already in rather '
            . 'than only stopping the next: their token is cleared with their status.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function updateStaff(Request $request, $id): JsonResponse
    {
        if ($refusal = $this->refuseIntegration($this->principal($request))) {
            return $refusal;
        }

        $staff = $this->ownedStaff($request, $id);

        if (!$staff) {
            return $this->notFound('staff');
        }

        $validator = validator($request->all(), [
            'name' => 'required|string|max:120',
            'seller_role_id' => 'nullable|integer',
            'status' => 'nullable|in:active,inactive',
            'password' => app(PasswordPolicy::class)->ruleString(required: false),
        ]);

        if ($validator->fails()) {
            return $this->refuse($validator->errors()->toArray());
        }

        try {
            $this->team->updateStaff($staff, $validator->validated());
        } catch (ValidationException $exception) {
            return $this->refuse($exception->errors());
        }

        return response()->json(['message' => translate('staff_member_updated')], 200);
    }

    #[ApiDoc(
        summary: 'Sign somebody out of every device',
        description: 'Ends the session without changing anything else about them — the answer when a '
            . 'phone has been lost and the employee is still employed tomorrow.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function signOutStaff(Request $request, $id): JsonResponse
    {
        if ($refusal = $this->refuseIntegration($this->principal($request))) {
            return $refusal;
        }

        $staff = $this->ownedStaff($request, $id);

        if (!$staff) {
            return $this->notFound('staff');
        }

        $this->team->signOutStaff($staff);

        return response()->json(['message' => translate('staff_member_signed_out')], 200);
    }

    #[ApiDoc(
        summary: 'Remove somebody from the shop',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function destroyStaff(Request $request, $id): JsonResponse
    {
        if ($refusal = $this->refuseIntegration($this->principal($request))) {
            return $refusal;
        }

        $staff = $this->ownedStaff($request, $id);

        if (!$staff) {
            return $this->notFound('staff');
        }

        $this->team->deleteStaff($staff);

        return response()->json(['message' => translate('staff_member_removed')], 200);
    }

    #[ApiDoc(
        summary: 'Who currently holds a way into this shop',
        description: 'The owner and every staff member, with whether each has a live token right now. '
            . 'Never the token itself — whether one exists is the useful fact; its value is the shop.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function access(Request $request): JsonResponse
    {
        return response()->json([
            'holders' => $this->trail->accessHolders(
                sellerId: $request->seller->id,
                ownerName: trim("{$request->seller->f_name} {$request->seller->l_name}"),
                ownerHasToken: !empty($request->seller->auth_token),
            ),
        ], 200);
    }

    #[ApiDoc(
        summary: 'What has been done in this shop, and by whom',
        description: 'Actions taken by the owner or their staff, plus decisions the marketplace '
            . 'recorded about the shop. Somebody who has since left still appears in the history of '
            . 'what they did, which is exactly when a seller most wants to look. Filterable by action '
            . 'prefix — "seller.staff" for the team, "seller.automation" for the rules.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function audit(Request $request): JsonResponse
    {
        // `?action[]=` arrives as an array, and the trail takes a prefix. Reading it as anything
        // other than one string turned a malformed query into a five hundred.
        $action = $request->get('action');

        return response()->json($this->trail->recent(
            sellerId: $request->seller->id,
            limit: (int) $request->get('limit', 50),
            action: is_scalar($action) ? (string) $action : null,
        ), 200);
    }

    /**
     * A key may not change who works here.
     *
     * The key console already refuses a key that tries to mint a key. Minting a *person* is the
     * larger escalation and was open: a key holding `staff.manage` could write a role with every
     * permission, attach an account with a password of its choosing, and sign in as a human —
     * surviving its own revocation, because the planted account is not the key.
     */
    private function refuseIntegration(SellerPrincipal $principal): ?JsonResponse
    {
        if ($principal->apiKey === null) {
            return null;
        }

        return response()->json(['errors' => [
            ['code' => 'api_key', 'message' => translate('api_keys_cannot_manage_people')],
        ]], 403);
    }

    private function principal(Request $request): SellerPrincipal
    {
        $principal = $request->attributes->get(SellerApiAuthMiddleware::PRINCIPAL);

        return $principal instanceof SellerPrincipal ? $principal : SellerPrincipal::owner($request->seller);
    }

    private function ownedRole(Request $request, $id): ?SellerRole
    {
        return SellerRole::where('seller_id', $request->seller->id)->find($id);
    }

    private function ownedStaff(Request $request, $id): ?SellerStaff
    {
        return SellerStaff::where('seller_id', $request->seller->id)->find($id);
    }

    private function notFound(string $code): JsonResponse
    {
        return response()->json(['errors' => [
            ['code' => $code, 'message' => translate('not_found')],
        ]], 404);
    }

    private function refuse(array $errors): JsonResponse
    {
        $formatted = [];

        foreach ($errors as $field => $messages) {
            $formatted[] = ['code' => $field, 'message' => is_array($messages) ? $messages[0] : $messages];
        }

        return response()->json(['errors' => $formatted], 403);
    }
}
