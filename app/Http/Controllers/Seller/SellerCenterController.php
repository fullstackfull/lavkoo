<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SellerApiAuthMiddleware;
use App\Services\Marketplace\SellerPrincipal;
use Illuminate\Http\Request;

/**
 * Shared plumbing for every Seller Center screen.
 *
 * The principal is resolved once by `SellerCenterContext` and read here, so a controller never
 * decides for itself who is acting — and never reaches for `auth('seller')` directly, which would
 * quietly give a staff member the owner's authority.
 */
abstract class SellerCenterController extends Controller
{
    protected function principal(Request $request): SellerPrincipal
    {
        $principal = $request->attributes->get(SellerApiAuthMiddleware::PRINCIPAL);

        // The context middleware runs on the whole group, so an absent principal is a wiring
        // mistake rather than a permission problem — and one that must not degrade into owner.
        abort_unless($principal instanceof SellerPrincipal, 403);

        return $principal;
    }

    protected function sellerId(Request $request): int
    {
        return $this->principal($request)->sellerId();
    }

    /**
     * Which of the seven data states a list is in (handoff 11 §1).
     *
     * The distinction that matters is `empty` versus `no_results`: "you have no orders yet" and
     * "your filters exclude all 218 of your orders" need different copy and different actions, and
     * collapsing them is the single most common way a list lies to its reader.
     */
    protected function listState(int $count, bool $filtered, bool $failed = false): string
    {
        if ($failed) {
            return 'error';
        }
        if ($count > 0) {
            return 'normal';
        }

        return $filtered ? 'no_results' : 'empty';
    }
}
