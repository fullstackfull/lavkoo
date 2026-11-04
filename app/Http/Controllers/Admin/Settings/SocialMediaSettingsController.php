<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Contracts\Repositories\SocialMediaRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\SocialMediaRequest;
use App\Http\Requests\Admin\SocialMediaUpdateRequest;
use App\Services\SocialMediaService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SocialMediaSettingsController extends BaseController
{

    public function __construct(
        private readonly SocialMediaRepositoryInterface $socialMediaRepo,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View
    {
        $socialMediaLinks = $this->socialMediaRepo->getListWhere(orderBy: ['id' => 'desc'], filters: ['status' => 1], dataLimit: 10);
        return view('admin-views.pages-and-media.social-media.index', compact('socialMediaLinks'));
    }

    public function add(SocialMediaRequest $request, SocialMediaService $socialMediaService): JsonResponse
    {
        $this->socialMediaRepo->add(data: ['name' => $request['name'], 'link' => $request['link'], 'icon' => $socialMediaService->getIcon(request: $request)]);
        return response()->json(['status' => 'success']);
    }

    public function getUpdate(Request $request): JsonResponse
    {
        $data = $this->socialMediaRepo->getFirstWhere(params: ['id' => $request['id']]);
        return response()->json($data);
    }

    public function update(SocialMediaUpdateRequest $request, SocialMediaService $socialMediaService): RedirectResponse
    {
        $this->socialMediaRepo->update(id: $request['id'], data: ['name' => $request['name'], 'link' => $request['link'], 'icon' => $socialMediaService->getIcon(request: $request)]);
        ToastMagic::success(translate('updated_successfully'));
        return back();
    }

    public function delete(Request $request): RedirectResponse
    {
        $this->socialMediaRepo->delete(params: ['id' => $request['id']]);
        return back();
    }

    public function updateStatus(Request $request): RedirectResponse
    {
        $this->socialMediaRepo->update(id: $request['id'], data: ['active_status' => $request['status']]);
        ToastMagic::success(translate('status_updated_successfully'));
        return back();
    }

}
