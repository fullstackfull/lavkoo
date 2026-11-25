<?php

namespace App\Http\Controllers\Admin\Shipping;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ScopeShippingToStaffCountries;
use App\Services\Shipping\CrossBorderPairs;
use App\Services\Shipping\CrossBorderPolicy;
use App\Services\Shipping\PairNotRecorded;
use App\Services\Shipping\ShippingEligibility;
use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Where a person opens or closes selling from one market into one country (H1-01).
 *
 * The platform ships selling inside each storefront's own country and nowhere else. This screen is where that changes, and the change is
 * recorded rather than configured: who opened the pair, when, and what it was before.
 *
 * **Only staff of the SOURCE market may change what it sells into**, which is the side the platform is answerable for; the rule itself is
 * {@see CrossBorderPairs}'s, so the screen and the engine cannot disagree about who may do what. The table it lists is narrowed the same
 * way, so a restricted employee is not even shown another market's pairs.
 */
class CrossBorderPairController extends Controller
{
    public function __construct(
        private readonly CrossBorderPairs $pairs,
        private readonly StaffCountryScope $staff,
    ) {
        // Registered for what it BINDS rather than for what it checks: the rule in the registry names no row, because a pair has no id --
        // the service that writes it is where a source market the employee does not hold is refused. Without the binding, that service
        // would read `boundCountries()` on a route where nothing bound anything, which is a guard that looks narrowed and is not.
        $this->middleware(ScopeShippingToStaffCountries::class);
    }

    public function index(): View
    {
        $resolver = StorefrontResolver::fromConfig();

        return view('admin-views.shipping.cross-border-pairs', [
            'ready' => $this->pairs->available(),
            'engineOn' => ShippingEligibility::isOn(),
            'killSwitch' => CrossBorderPolicy::isClosed(),
            // The markets this employee may change, which for an unrestricted one is every market the platform serves.
            'sources' => $this->staff->narrow($resolver->configuredCountries()),
            'destinations' => $resolver->configuredCountries(),
            'recorded' => $this->pairs->recorded(),
            'declared' => (array) config('gcc.shipping.cross_border.pairs', []),
        ]);
    }

    public function record(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'source_country' => ['required', 'string', 'size:2'],
            'destination_country' => ['required', 'string', 'size:2'],
            'open' => ['nullable', 'boolean'],
        ]);

        try {
            $open = $this->pairs->record(
                source: $validated['source_country'],
                destination: $validated['destination_country'],
                open: $request->boolean('open'),
                adminId: (int) auth('admin')->id() ?: null,
            );
        } catch (PairNotRecorded $refused) {
            return back()->withErrors(['source_country' => $refused->getMessage()]);
        }

        return back()->with('success', $open
            ? translate('that_market_may_now_sell_into_that_country')
            : translate('that_market_may_no_longer_sell_into_that_country'));
    }
}
