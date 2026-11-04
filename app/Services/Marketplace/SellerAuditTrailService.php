<?php

namespace App\Services\Marketplace;

use App\Models\AuditLog;
use App\Services\Platform\Policy;
use App\Models\SellerApiKey;
use App\Models\SellerStaff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * What happened in this shop, and who did it.
 *
 * `audit_logs` is one table for the whole platform, so the hard part is deciding which of its rows
 * belong to one seller. Two things make a row theirs, and both are needed:
 *
 * The actor was them — the owner's own account, one of their staff, or a key they issued. Staff ids are read fresh
 * rather than stored on the row, so an employee who has since left still shows up in the history of
 * what they did, which is exactly when a seller most wants to look.
 *
 * Or the row says so — anything the marketplace recorded *about* this shop carries the shop's id in
 * its context, and a seller should be able to see the decisions taken about them, not only the ones
 * they took themselves.
 *
 * Rows matching neither are somebody else's. There is no filter a caller can pass to widen this.
 */
class SellerAuditTrailService
{
    /**
     * @return Builder<AuditLog>|null  null when the table has not been created yet
     */
    public function forSeller(int|string $sellerId): ?Builder
    {
        if (!Schema::hasTable('audit_logs')) {
            return null;
        }

        $staffIds = Schema::hasTable('seller_staff')
            ? SellerStaff::where('seller_id', $sellerId)->pluck('id')->all()
            : [];

        // Revoked keys included, for the same reason as departed staff: what a credential did is
        // most worth looking at once it has been taken away.
        $keyIds = Schema::hasTable('seller_api_keys')
            ? SellerApiKey::where('seller_id', $sellerId)->pluck('id')->all()
            : [];

        return AuditLog::query()->where(function (Builder $query) use ($sellerId, $staffIds, $keyIds) {
            $query->where(function (Builder $actor) use ($sellerId) {
                $actor->where('actor_type', 'seller')->where('actor_id', $sellerId);
            });

            if ($staffIds !== []) {
                $query->orWhere(function (Builder $actor) use ($staffIds) {
                    $actor->where('actor_type', 'seller_staff')->whereIn('actor_id', $staffIds);
                });
            }

            if ($keyIds !== []) {
                $query->orWhere(function (Builder $actor) use ($keyIds) {
                    $actor->where('actor_type', 'seller_api_key')->whereIn('actor_id', $keyIds);
                });
            }

            // Recorded about the shop rather than by it. Stored as JSON, so this is a text match —
            // and it has to include what follows the number, because a bare LIKE on the id finds
            // shop 11 while looking for shop 1. A value is always followed by a comma or the
            // closing brace of its object.
            $query->orWhere(function (Builder $recorded) use ($sellerId) {
                $needle = '%"seller_id":' . (int) $sellerId;
                $recorded->where('context', 'like', $needle . ',%')
                    ->orWhere('context', 'like', $needle . '}%');
            });
        });
    }

    /**
     * The shop's history, newest first.
     *
     * @return array{entries: array<int, array>, total: int}
     */
    public function recent(int|string $sellerId, int $limit = 50, ?string $action = null): array
    {
        $query = $this->forSeller($sellerId);

        if (!$query) {
            return ['entries' => [], 'total' => 0];
        }

        if ($action !== null && $action !== '') {
            $query->where('action', 'like', $action . '%');
        }

        $total = (clone $query)->count();

        $entries = $query->orderByDesc('id')
            ->limit(min(app(Policy::class)->int('limit_audit_rows'), max(1, $limit)))
            ->get()
            ->map(fn (AuditLog $entry) => [
                'id' => $entry->id,
                'action' => $entry->action,
                'actor_name' => $entry->actor_name,
                'actor_type' => $entry->actor_type,
                'subject_type' => $entry->subject_type,
                'subject_id' => $entry->subject_id,
                'before' => $entry->before,
                'after' => $entry->after,
                'ip_address' => $entry->ip_address,
                'created_at' => $entry->created_at,
            ])
            ->all();

        return ['entries' => $entries, 'total' => $total];
    }

    /**
     * Who currently holds a way into this shop.
     *
     * Read from the credentials themselves rather than from a session store: the owner holds one
     * API token and each staff member holds their own, so "has a live token" is the honest answer
     * to "who can act as this shop right now".
     *
     * @return array<int, array>
     */
    public function accessHolders(int|string $sellerId, ?string $ownerName, bool $ownerHasToken): array
    {
        $holders = [[
            'type' => 'owner',
            'id' => (int) $sellerId,
            'name' => $ownerName,
            'role' => null,
            'status' => 'active',
            'signed_in' => $ownerHasToken,
            'last_login_at' => null,
        ]];

        if (!Schema::hasTable('seller_staff')) {
            return $holders;
        }

        foreach (SellerStaff::with('role')->where('seller_id', $sellerId)->get() as $staff) {
            $holders[] = [
                'type' => 'staff',
                'id' => $staff->id,
                'name' => $staff->name,
                'role' => $staff->role?->name,
                'status' => $staff->status,
                // Not the token itself, ever. Whether one exists is the useful fact; its value is
                // a credential, and an endpoint that returned it would be handing out the shop.
                'signed_in' => !empty($staff->auth_token),
                'last_login_at' => $staff->last_login_at,
            ];
        }

        return $holders;
    }
}
