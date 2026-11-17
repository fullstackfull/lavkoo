<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\FileManagerUploadRequest;
use App\Services\FileManager\ArchiveReport;
use App\Services\FileManagerService;
use App\Services\Fulfillment\Support\SafeToast;
use App\Utils\PlainFileName;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileManagerController extends BaseController
{

    public function __construct(private readonly FileManagerService $fileManagerService)
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
        $targetFolder = $this->galleryFolder(request: $request);
        $storage = $this->fileManagerService->galleryDisk(requested: $request['storage']);

        try {
            $cacheKey = "cache_all_files_for_{$storage}_storage";
            Cache::forget($cacheKey);
            Cache::remember($cacheKey, now()->addMinutes(10), function () use ($storage) {
                return Storage::disk($storage)->allFiles('/');
            });
        } catch (\Exception $exception) {}

        $currentFolder = explode('/', base64_decode($targetFolder));
        $previousFolder = count($currentFolder) > 1 ? str_replace('/' . end($currentFolder), '', base64_decode($targetFolder)) : 'public';
        $breadcrumb = $this->fileManagerService->getFileManagerBreadcrumb(path: base64_decode($targetFolder));

        $recentFiles = $this->fileManagerService->getRecentFiles(storage: $storage);

        $storageConnectionType = getWebConfig(name: 'storage_connection_type');
        if ($storage == 's3' && $storageConnectionType == 's3') {
            Storage::disk($storage)->exists(base64_decode($targetFolder));
        }

        $allFilesList = $this->fileManagerService->getAllFilesWithInfo(targetFolder: base64_decode($targetFolder), storage: $storage);
        $allItemList = collect(array_merge($allFilesList['folders'], $allFilesList['files']));
        if (request()->has('search') && !empty(request('search'))) {
            $allItemList = $allItemList->filter(function ($file) use ($request) {
                return str_contains(strtolower($file['name']), strtolower($request['search']));
            });
        }

        $perPage = 30;
        $page = request('page', 1);
        $items = $allItemList->slice(($page - 1) * $perPage, $perPage)->values();

        $allItemList = new LengthAwarePaginator($items, count($allItemList), $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);

        return view('admin-views.file-manager.index', [
            'storage' => $storage,
            'storageConnectionType' => $storageConnectionType,
            'allItemList' => $allItemList,
            'targetFolder' => $targetFolder,
            'currentFolder' => $currentFolder,
            'previousFolder' => $previousFolder,
            'recentFiles' => $recentFiles,
            'breadcrumb' => $breadcrumb,
        ]);
    }

    public function upload(FileManagerUploadRequest $request, FileManagerService $fileManagerService): RedirectResponse
    {
        if (config('app.mode') == 'demo') {
            ToastMagic::info(translate('This_option_is_disabled_for_demo'));
            return back();
        }
        $report = new ArchiveReport();
        $isStored = $fileManagerService->uploadFileAndImages(request: $request, report: $report);
        cacheRemoveByType(type: 'file_manager');

        if (!$isStored) {
            SafeToast::error(message: $report->refusalMessage(), detail: $report->skippedMessage());
        } elseif ($request->has('images')) {
            SafeToast::success(message: translate('image_uploaded_successfully'), detail: $report->skippedMessage());
        } else {
            SafeToast::success(message: translate('File_uploaded_successfully'), detail: $report->skippedMessage());
        }
        return back();
    }

    public function download(Request $request, $fileName): StreamedResponse
    {
        $disk = $this->fileManagerService->galleryDisk(requested: $request['storage']);
        $path = (string)base64_decode(string: (string)$fileName);
        $path = $disk === 'public' ? preg_replace('#^public/#', '', $path) : $path;

        abort_unless(
            $path !== '' && PlainFileName::staysInside(path: $path) && Storage::disk($disk)->exists($path) && !Storage::disk($disk)->directoryExists($path),
            404
        );

        return Storage::disk($disk)->download($path);
    }

    /** The folder the page opens, base64-encoded: the main folder unless the request names a folder inside the disk. */
    private function galleryFolder(Request $request): string
    {
        $targetFolder = $request['targetFolder'];

        return is_string($targetFolder) && PlainFileName::staysInside(path: (string)base64_decode($targetFolder)) ? $targetFolder : 'cHVibGlj';
    }
}
