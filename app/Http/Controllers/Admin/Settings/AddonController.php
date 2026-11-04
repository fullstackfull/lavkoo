<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\AddonRequest;
use App\Services\AddonService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;

class AddonController extends BaseController
{

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View
    {
        $addons = self::getDirectories();
        return view('admin-views.system-setup.addons.index', compact('addons'));
    }

    public function publish(Request $request, AddonService $addonService): JsonResponse|int|RedirectResponse
    {
        $data = $addonService->getPublishData(request: $request);

        if ($request->ajax()) {
            return response()->json($data);
        }

        if ($data['status'] == 'success') {
            ToastMagic::success($data['message']);
        } else {
            ToastMagic::error($data['message']);
        }
        return back();
    }

    public function activation(Request $request, AddonService $addonService): Redirector|RedirectResponse|Application
    {
        $data = $addonService->getActivationData(request: $request);
        if ($data['status']) {
            ToastMagic::success(translate('activated_successfully'));
            return back();
        }
        return redirect($data['activationUrl']);
    }

    public function upload(AddonRequest $request, AddonService $addonService): JsonResponse|RedirectResponse
    {
        if (env('APP_MODE', 'dev') == 'demo') {
            if ($request->ajax()) {
                return response()->json([
                    'status' => 'error',
                    'message' => translate('Uploading_ZIP_files_is_currently_unavailable_in_demo_mode')
                ]);
            }
            ToastMagic::error(translate('Uploading_ZIP_files_is_currently_unavailable_in_demo_mode'));
            return back();
        }

        $data = $addonService->getUploadData(request: $request);

        if ($request->ajax()) {
            return response()->json([
                'status' => $data['status'],
                'message' => $data['message']
            ]);
        }

        if ($data['status'] == 'success') {
            ToastMagic::success($data['message']);
        } else {
            ToastMagic::error($data['message']);
        }

        return back();
    }

    public function delete(Request $request, AddonService $addonService): JsonResponse|RedirectResponse
    {
        $data = $addonService->deleteAddon(request: $request);

        if ($request->ajax()) {
            return response()->json($data);
        }

        if ($data['status'] == 'success') {
            ToastMagic::success($data['message']);
        } else {
            ToastMagic::error($data['message']);
        }

        return back();
    }

    function getDirectories(): array
    {
        $scan = scandir(base_path('Modules/'));
        $addonsFolders = array_diff($scan, ['.', '..', '.DS_Store']);
        $collection = collect($addonsFolders);

        $addonsFolders = $collection->reject(function ($value, $key) {
            return $value === "doc.txt";
        });
        $addons = [];

        foreach ($addonsFolders as $directory) {
            if (!in_array($directory, ['Blog', 'TaxModule', 'AI'])) {
                if (file_exists(base_path('Modules/' . $directory . '/Addon/info.php'))) {
                    $addons[] = 'Modules/' . $directory;
                }
            }
        }
        return $addons;
    }
}
