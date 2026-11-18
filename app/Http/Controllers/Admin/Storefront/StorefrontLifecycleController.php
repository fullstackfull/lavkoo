<?php

namespace App\Http\Controllers\Admin\Storefront;

use App\Http\Requests\Admin\Storefront\StorefrontLifecycleRequest;
use App\Services\Storefront\StorefrontAdminBoard;
use App\Services\Storefront\StorefrontLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * I2-11: the screen where a person with the permission marks a storefront ready, activates it, pauses it in one of three modes and resumes it. Every step is the one
 * StorefrontLifecycle takes for the console command (the same refusals, the same audit line in the same transaction), made by the admin who is signed in.
 */
class StorefrontLifecycleController extends StorefrontAdminController
{
    public function __construct(
        private readonly StorefrontLifecycle $lifecycle,
        private readonly StorefrontAdminBoard $board,
    ) {
    }

    public function index(): View
    {
        return view('admin-views.storefronts.index', [
            'storefronts' => $this->board->rows(),
            'storefrontsAreOn' => $this->board->storefrontsAreOn(),
            'modes' => StorefrontLifecycle::MODES,
        ]);
    }

    public function markReady(StorefrontLifecycleRequest $request, string $country): RedirectResponse
    {
        return $this->attempt(
            step: fn () => $this->lifecycle->markReady(country: $country, by: $request->person(), reason: $request->reason()),
            done: 'the_storefront_is_marked_ready',
        );
    }

    public function activate(StorefrontLifecycleRequest $request, string $country): RedirectResponse
    {
        return $this->attempt(
            step: fn () => $this->lifecycle->activate(country: $country, by: $request->person(), reference: $request->reference(), reason: $request->reason()),
            done: 'the_storefront_is_active',
        );
    }

    public function pause(StorefrontLifecycleRequest $request, string $country): RedirectResponse
    {
        return $this->attempt(
            step: fn () => $this->lifecycle->pause(country: $country, mode: $request->mode(), by: $request->person(), reason: $request->reason(), message: $request->message(), until: $request->until()),
            done: 'the_storefront_is_paused',
        );
    }

    public function resume(StorefrontLifecycleRequest $request, string $country): RedirectResponse
    {
        return $this->attempt(
            step: fn () => $this->lifecycle->resume(country: $country, by: $request->person(), reason: $request->reason()),
            done: 'the_storefront_sells_again',
        );
    }
}
