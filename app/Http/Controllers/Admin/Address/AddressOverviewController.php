<?php

namespace App\Http\Controllers\Admin\Address;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Address\AddressModeRequest;
use App\Services\Address\AddressModeGate;
use App\Services\Address\AddressSchema;
use App\Services\Address\AddressStatus;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** The address system at a glance, and the switch that turns the structured address on or off for the whole store. */
class AddressOverviewController extends Controller
{
    private const LEGACY_TEXT_LIMIT = 2000;

    public function __construct(private readonly AddressStatus $status, private readonly AddressModeGate $gate)
    {
    }

    public function index(): View
    {
        return view('admin-views.address.index', [
            'report' => $this->status->report(legacyTextLimit: self::LEGACY_TEXT_LIMIT),
            'modes' => AddressSchema::MODES,
            'blocked' => $this->gate->problems(AddressSchema::MODE_SOFT),
        ]);
    }

    public function updateMode(AddressModeRequest $request): RedirectResponse
    {
        $problems = $this->gate->switchTo($request->validated('mode'));

        foreach ($problems as $problem) {
            ToastMagic::error(translate($problem));
        }
        if ($problems === []) {
            ToastMagic::success(translate('address_mode_saved'));
        }

        return redirect()->route('admin.address-system.index');
    }
}
