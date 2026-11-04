<?php

namespace App\Http\View\Composers;

use App\Http\Middleware\SellerApiAuthMiddleware;
use App\Services\Marketplace\SellerPrincipal;
use App\Services\SellerCenter\Counts;
use App\Services\SellerCenter\ModuleFlags;
use App\Services\SellerCenter\Navigation;
use App\Services\SellerCenter\Search;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Everything the shell needs, resolved once per request.
 *
 * The rail, the panel, the mobile drawer and the palette are four renderings of one structure, so
 * they are computed here rather than in each controller — a screen that forgot to pass the
 * navigation would render a shell with no way out of it.
 */
class SellerCenterComposer
{
    public function __construct(
        private readonly Request $request,
        private readonly Counts $counts,
        private readonly Search $search,
    ) {
    }

    public function compose(View $view): void
    {
        $principal = $this->request->attributes->get(SellerApiAuthMiddleware::PRINCIPAL);

        if (!$principal instanceof SellerPrincipal) {
            $view->with(['scNav' => [], 'scActive' => ['group' => null, 'item' => null], 'scCounts' => [], 'scCommands' => []]);

            return;
        }

        $counts = $this->counts->for($principal);
        $navigation = Navigation::for($principal, $counts, $this->flags($principal->sellerId()));

        $view->with([
            'scPrincipal' => $principal,
            'scNav' => $navigation,
            'scActive' => Navigation::active($this->request->path(), $navigation),
            'scCounts' => $counts,
            'scCommands' => $this->search->commands($principal),
            'scShop' => $principal->seller->shop ?? null,
            'scRoleLabel' => $principal->isOwner() ? translate('owner') : ($principal->staff->role->name ?? translate('staff')),
            'scNotifications' => [],
            'scNotificationUnread' => 0,
        ]);
    }

    /**
     * Module flags. An absent flag means off (handoff 07.8) — a warehouse tab that appears because
     * a setting row is missing is worse than one that never appears.
     *
     * Answered by the same service the seller app's API answers with, so the two clients cannot
     * disagree about whether a shop runs warehouses.
     *
     * @return array<string, bool>
     */
    private function flags(int|string|null $sellerId): array
    {
        return ModuleFlags::forSeller($sellerId);
    }
}
