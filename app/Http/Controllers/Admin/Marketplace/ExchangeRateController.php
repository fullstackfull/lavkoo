<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\Currency;
use App\Services\Marketplace\ExchangeRateService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Admin exchange-rate governance (Phase 3, Stage E). Update several currency rates at once and read the
 * change history — governance on top of the platform's existing currencies and conversion, which are
 * untouched.
 */
class ExchangeRateController extends BaseController
{
    public function __construct(private readonly ExchangeRateService $rates)
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $currencies = Schema::hasTable('currencies') ? Currency::orderBy('code')->get() : collect();
        $history = $this->rates->history(null, 50);

        // Attach each currency's code so a history row reads even after a currency is renamed.
        $currencyNames = $currencies->pluck('code', 'id');

        return view('admin-views.marketplace.exchange-rates', [
            'currencies' => $currencies,
            'history' => $history,
            'currencyNames' => $currencyNames,
        ]);
    }

    public function bulkUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'rates' => 'required|array',
            'rates.*' => 'nullable|numeric|min:0',
        ]);

        $changed = $this->rates->bulkUpdate($validated['rates'], auth('admin')->id());

        // Rates feed cached conversions — clear so the new numbers take effect.
        cache()->flush();

        $changed > 0
            ? ToastMagic::success(translate('rates_updated') . ': ' . $changed)
            : ToastMagic::info(translate('no_rates_changed'));

        return back();
    }
}
