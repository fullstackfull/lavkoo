<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Contracts\Repositories\RedirectRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\RedirectStoreRequest;
use App\Models\Redirect;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RedirectController extends BaseController
{
    public function __construct(
        private readonly RedirectRepositoryInterface $redirectRepo,
    )
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $search = $request?->get('searchValue');
        $redirects = $this->redirectRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: $search,
            dataLimit: 20,
        );

        return view('admin-views.seo-settings.redirects', [
            'redirects' => $redirects,
            'search'    => $search,
        ]);
    }

    public function store(RedirectStoreRequest $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }
        if ($this->redirectRepo->existsForFromPath($request['from_path'])) {
            ToastMagic::error(translate('a_redirect_for_this_source_path_already_exists') . '!');
            return back()->withInput();
        }

        $this->redirectRepo->add($this->payload($request));
        $this->flushCache();
        ToastMagic::success(translate('redirect_added_successfully'));

        return $this->backToIndex();
    }

    public function update(RedirectStoreRequest $request, int $id): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }
        $redirect = $this->redirectRepo->getFirstWhere(params: ['id' => $id]);
        if (!$redirect) {
            ToastMagic::error(translate('redirect_not_found') . '!');
            return $this->backToIndex();
        }
        if ($this->redirectRepo->existsForFromPath($request['from_path'], $id)) {
            ToastMagic::error(translate('a_redirect_for_this_source_path_already_exists') . '!');
            return back()->withInput();
        }

        $this->redirectRepo->update((string) $id, $this->payload($request));
        $this->flushCache();
        ToastMagic::success(translate('redirect_updated_successfully'));

        return $this->backToIndex();
    }

    public function updateStatus(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return back();
        }
        $this->redirectRepo->updateByParams(
            params: ['id' => $request['id']],
            data: ['is_active' => $request['status'] == 1],
        );
        $this->flushCache();
        ToastMagic::success(translate('status_updated_successfully'));

        return back();
    }

    public function delete(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }
        $this->redirectRepo->delete(params: ['id' => $request['id']]);
        $this->flushCache();
        ToastMagic::success(translate('redirect_deleted_successfully'));

        return $this->backToIndex();
    }

    private function payload(RedirectStoreRequest $request): array
    {
        return [
            'from_path'       => $request['from_path'],
            'to_path'         => $request['to_path'],
            'status_code'     => $request['status_code'],
            'match_type'      => $request['match_type'],
            'is_active'       => $request['is_active'] ?? true,
            'note'            => $request['note'] ?? null,
            'created_by_type' => 'admin',
            'created_by_id'   => auth('admin')->id(),
        ];
    }

    private function blockedOnDemo(): bool
    {
        if (config('app.mode') == 'demo') {
            ToastMagic::error(translate('you_can_not_update_this_on_demo_mode'));
            return true;
        }
        return false;
    }

    private function flushCache(): void
    {
        Cache::forget(Redirect::ACTIVE_CACHE_KEY);
    }

    private function backToIndex(): RedirectResponse
    {
        return redirect()->route('admin.seo-settings.redirects.index');
    }
}
