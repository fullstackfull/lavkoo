<?php

namespace App\Http\Controllers\Admin\Address;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Address\LegacyCityDecisionRequest;
use App\Services\Address\AddressCatalog;
use App\Services\Address\LegacyBackfill;
use App\Services\Address\LegacyCityGroup;
use App\Services\Address\LegacyReview;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/** The queue of old free-text cities: a person settles what the automatic match could not, or applies every certain match at once. */
class LegacyAddressController extends Controller
{
    private const PER_PAGE = 25;

    private const TEXT_LIMIT = 2000;

    public function __construct(
        private readonly LegacyReview $review,
        private readonly LegacyBackfill $backfill,
        private readonly AddressCatalog $catalog,
    ) {
    }

    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $groups = $this->review->groups($filters['q'] ?? null);
        $page = LengthAwarePaginator::resolveCurrentPage();

        return view('admin-views.address.legacy', [
            'groups' => new LengthAwarePaginator(
                items: $groups->forPage($page, self::PER_PAGE)->map(fn (LegacyCityGroup $group): LegacyCityGroup => $this->review->read($group))->values(),
                total: $groups->count(),
                perPage: self::PER_PAGE,
                currentPage: $page,
                options: ['path' => $request->url(), 'query' => $request->query()],
            ),
            'plan' => $this->backfill->plan(self::TEXT_LIMIT),
            'blankRows' => $this->review->blankRows(),
            'regions' => $this->catalog->regions(onlyActive: false),
            'search' => $filters['q'] ?? '',
        ]);
    }

    public function confirm(LegacyCityDecisionRequest $request): RedirectResponse
    {
        $updated = $this->review->confirm(typedText: $request->validated('text'), cityId: (int) $request->validated('city_id'), adminId: auth('admin')->id());
        ToastMagic::success(translate('address_legacy_confirmed') . ' ' . translate('address_addresses_updated') . ': ' . $updated);

        return back();
    }

    public function apply(): RedirectResponse
    {
        $updated = $this->backfill->run(apply: true)['updated'];

        if ($updated > 0) {
            ToastMagic::success(translate('address_addresses_updated') . ': ' . $updated);
        } else {
            ToastMagic::info(translate('address_backfill_nothing_to_apply'));
        }

        return back();
    }
}
