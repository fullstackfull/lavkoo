<?php

namespace App\Services;

use App\Traits\ActivationClass;
use App\Utils\PlainFileName;
use App\Traits\AddonHelper;
use App\Traits\FileManagerTrait;
use App\Traits\SettingsTrait;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class AddonService
{
    /** The modules that ship with the platform: they are in the Modules directory but are not add-ons, and are never published, activated or deleted here. */
    private const CORE_MODULES = ['Blog', 'TaxModule', 'AI'];

    use SettingsTrait;
    use FileManagerTrait;
    use ActivationClass;
    use AddonHelper;

    public function getUploadData(object $request): array
    {
        $tempFolderPath = storage_path('app/temp/');
        if (!File::exists($tempFolderPath)) {
            File::makeDirectory($tempFolderPath, 0775, true);
        }

        $file = $request->file('file_upload');
        $filename = $file->getClientOriginalName();
        $tempPath = $file->storeAs('temp', $filename);

        $zip = new ZipArchive();
        if ($zip->open(storage_path('app/' . $tempPath)) === TRUE) {

            $addon = $this->addonEntries(zip: $zip);

            if ($addon !== null) {
                ['folder' => $getAddonFolder, 'entries' => $entries] = $addon;
                $extractPath = base_path('Modules');
                if (!File::exists($extractPath)) {
                    File::makeDirectory($extractPath, 0775, true);
                }
                if (File::exists($extractPath . '/' . $getAddonFolder)) {
                    $message = translate('already_installed');
                    $status = 'error';
                } else {
                    if (!is_writable($extractPath)) {
                        @chmod($extractPath, 0775);
                    }
                    if (!is_writable($extractPath) || !@$zip->extractTo($extractPath, $entries)) {
                        $zip->close();
                        return [
                            'status' => 'error',
                            'message' => translate('addon_extraction_failed_modules_directory_is_not_writable') . ': ' . $extractPath,
                        ];
                    }
                    $zip->close();
                    @File::chmod($extractPath . '/' . $getAddonFolder . '/Addon', 0777);
                    $status = 'success';
                    $message = translate('upload_successfully');

                    $this->syncPublicModuleLinks();
                }
            } else {
                if (File::isDirectory(storage_path('app/temp'))) {
                    File::cleanDirectory(storage_path('app/temp'));
                }
                $status = 'error';
                $message = translate('invalid_file!');
            }
        } else {
            $status = 'error';
            $message = translate('file_upload_fail!');
        }

        if (File::exists(base_path('Modules/__MACOSX'))) {
            File::deleteDirectory(base_path('Modules/__MACOSX'));
        }

        if (File::isDirectory(storage_path('app/temp'))) {
            File::cleanDirectory(storage_path('app/temp'));
        }

        return [
            'status' => $status,
            'message' => $message
        ];
    }

    /**
     * The folder an add-on archive installs and the entries it extracts, or nothing when the archive is not an add-on of one folder: the folder
     * is a plain module name that is not a module that ships with the platform, it holds `Addon/info.php`, and every entry lies inside it (the
     * archive was extracted whole into the Modules directory, so an entry of another folder overwrote a core module or a file outside it).
     *
     * @return array{folder: string, entries: list<string>}|null
     */
    private function addonEntries(ZipArchive $zip): ?array
    {
        $folder = explode('.', explode('/', (string)$zip->getNameIndex(0))[0])[0];
        if (preg_match('/^[A-Za-z0-9_]+$/D', $folder) !== 1 || in_array($folder, self::CORE_MODULES, true)) {
            return null;
        }

        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string)$zip->getNameIndex($index);
            if (str_starts_with($name, '__MACOSX/')) {
                continue;
            }
            if (!str_starts_with($name, $folder . '/') || str_contains($name, '\\') || !PlainFileName::staysInside(path: $name)) {
                return null;
            }
            $entries[] = $name;
        }

        return in_array($folder . '/Addon/info.php', $entries, true) ? ['folder' => $folder, 'entries' => $entries] : null;
    }

    /**
     * The path of an installed add-on exactly as the add-on list shows it ("Modules/Name"), or nothing. The request names the add-on, and the
     * path was then joined to the application root to include its info.php, to rewrite it and to delete the directory, so a path the list
     * never offered (`storage`, `app`, `../other-site`) was a way to run, overwrite or delete anything the web user can reach. A link in the
     * Modules directory is no add-on either: deleting through it empties the directory it points at and leaves the link.
     */
    public function installedAddonPath(mixed $path): ?string
    {
        if (!is_string($path) || preg_match('#^Modules/([A-Za-z0-9_]+)$#D', $path, $match) !== 1 || in_array($match[1], self::CORE_MODULES, true)) {
            return null;
        }

        $directory = base_path($path);

        return !is_link($directory) && is_file($directory . '/Addon/info.php') ? $path : null;
    }

    public function getPublishData(object $request): array
    {
        $path = $this->installedAddonPath($request['path']);
        if ($path === null) {
            return ['status' => 'error', 'message' => translate('invalid_request')];
        }
        $fullData = include(base_path($path . '/Addon/info.php'));
        $addonName = $fullData['name'];

        if (isset($getAddonFolder) && $getAddonFolder === 'Auction') {
            try {
                Artisan::call('migrate');
            } catch (\Throwable $e) {
            }
        }

        if ($fullData['purchase_code'] == null || $fullData['username'] == null) {
            $modalView = ($fullData['module_name'] ?? null) === 'Gateways'
                ? 'admin-views.system-setup.addons.partials.activation-modal-data'
                : 'admin-views.system-setup.addons.partials.addon-activation-modal';
            return [
                'flag' => 'inactive',
                'view' => view($modalView, compact('fullData', 'path', 'addonName'))->render(),
            ];
        }
        $fullData['is_published'] = $fullData['is_published'] ? 0 : 1;
        $str = "<?php return " . var_export($fullData, true) . ";";
        file_put_contents(base_path($path . '/Addon/info.php'), $str);

        Artisan::call('optimize:clear');
        Artisan::call('view:clear');

        return [
            'status' => 'success',
            'message' => translate('status_updated_successfully')
        ];
    }

    public function getActivationData(object $request): array
    {
        $path = $this->installedAddonPath($request['path']);
        if ($path === null) {
            return ['status' => 0, 'activationUrl' => url('/')];
        }
        $url = $this->getCurrentDomain();
        $full_data = include(base_path($path . '/Addon/info.php'));

        $post = [
            base64_decode('dXNlcm5hbWU=') => $request['username'],
            base64_decode('cHVyY2hhc2Vfa2V5') => $request['purchase_code'],
            base64_decode('c29mdHdhcmVfaWQ=') => $full_data['software_id'],
            base64_decode('ZG9tYWlu') => $url,
        ];

        $response = Http::post(base64_decode('aHR0cHM6Ly9jaGVjay42YW10ZWNoLmNvbS9hcGkvdjEvYWN0aXZhdGlvbi1jaGVjaw=='), $post)->json();
        $status = isset($response['active']) ? base64_decode($response['active']) ?? 1 : 0;

        if ((int)$status) {
            $full_data['is_published'] = 1;
            $full_data['username'] = $request['username'];
            $full_data['purchase_code'] = $request['purchase_code'];
            $str = "<?php return " . var_export($full_data, true) . ";";
            file_put_contents(base_path($path . '/Addon/info.php'), $str);
        }

        $activationUrl = base64_decode('aHR0cHM6Ly9hY3RpdmF0aW9uLjZhbXRlY2guY29t');
        $activationUrl .= '?username=' . $request['username'];
        $activationUrl .= '&purchase_code=' . $request['purchase_code'];
        $activationUrl .= '&domain=' . url('/') . '&';

        return [
            'status' => (int)$status,
            'activationUrl' => $activationUrl
        ];

    }

    public function deleteAddon(object $request): array
    {
        $path = $this->installedAddonPath($request['path']);
        if ($path === null) {
            return ['status' => 'error', 'message' => translate('file_delete_fail')];
        }
        $full_path = base_path($path);
        if (basename($path) === 'Gateways') {
            $old = base_path('app/Traits/Payment.php');
            $new = base_path('app/Traits/Payment.txt');
            copy($new, $old);
        }

        if (File::deleteDirectory($full_path)) {
            $status = 'success';
            $message = translate('file_delete_successfully');
            $this->syncPublicModuleLinks();
        } else {
            $status = 'error';
            $message = translate('file_delete_fail');
        }

        return [
            'status' => $status,
            'message' => $message
        ];
    }

    private function syncPublicModuleLinks(): void
    {
        if (DOMAIN_POINTED_DIRECTORY != 'public') {
            return;
        }

        try {
            $this->doSyncPublicModuleLinks();
        } catch (\Throwable $e) {

        }
    }

    private function doSyncPublicModuleLinks(): void
    {
        $publicModulesPath = public_path('Modules');
        $modulesBasePath = base_path('Modules');

        // Legacy installs may have public/Modules as an umbrella symlink to ../Modules.
        // Replace it with a real directory so each addon can be exposed as its own entry.
        if (is_link($publicModulesPath)) {
            @unlink($publicModulesPath);
        }
        if (!File::isDirectory($publicModulesPath)) {
            File::makeDirectory($publicModulesPath, 0775, true);
        }

        // Cleanup: drop legacy whole-module symlinks and stale module-assets
        // (target addon removed or no longer ships a module-assets dir).
        foreach (scandir($publicModulesPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $entryPath = $publicModulesPath . DIRECTORY_SEPARATOR . $entry;
            $sourceAssets = $modulesBasePath . DIRECTORY_SEPARATOR . $entry . DIRECTORY_SEPARATOR . 'module-assets';

            // macOS zip noise — never expose, regardless of symlink/dir/file.
            if ($entry === '__MACOSX') {
                if (is_link($entryPath) || is_file($entryPath)) {
                    @unlink($entryPath);
                } elseif (File::isDirectory($entryPath)) {
                    File::deleteDirectory($entryPath);
                }
                continue;
            }

            // Legacy whole-module symlink — remove so it can be replaced by a scoped copy.
            if (is_link($entryPath)) {
                @unlink($entryPath);
                continue;
            }

            if (!File::isDirectory($entryPath)) {
                continue;
            }

            $assetsPath = $entryPath . DIRECTORY_SEPARATOR . 'module-assets';
            $sourceMissing = !File::isDirectory($sourceAssets);

            if ($sourceMissing) {
                $this->removePath($assetsPath);
            }

            if ($sourceMissing && File::isDirectory($entryPath) && count(scandir($entryPath)) === 2) {
                @rmdir($entryPath);
            }
        }

        // Copy module-assets into public/Modules/{Name}/module-assets for every addon
        // that ships one. Refreshed each sync so reinstalled/updated addons expose
        // their latest assets without relying on host symlink support.
        foreach (File::directories($modulesBasePath) as $modulePath) {
            $moduleName = basename($modulePath);
            $sourceAssets = $modulePath . DIRECTORY_SEPARATOR . 'module-assets';
            if (!File::isDirectory($sourceAssets)) {
                continue;
            }

            $moduleStubPath = $publicModulesPath . DIRECTORY_SEPARATOR . $moduleName;
            $assetsPath = $moduleStubPath . DIRECTORY_SEPARATOR . 'module-assets';

            if (!File::isDirectory($moduleStubPath)) {
                File::makeDirectory($moduleStubPath, 0775, true);
            }

            $this->removePath($assetsPath);

            if (!File::copyDirectory($sourceAssets, $assetsPath)) {
                $this->copyAddonAssetsBestEffort($sourceAssets, $assetsPath);
            }
        }

        Artisan::call('optimize:clear');
        Artisan::call('view:clear');
    }

    private function copyAddonAssetsBestEffort(string $source, string $destination): void
    {
        $this->removePath($destination);
        File::makeDirectory($destination, 0775, true);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $target = $destination . DIRECTORY_SEPARATOR . $relativePath;

            if ($item->isDir()) {
                // Symlink or stray file at this path would cause copies to land
                // outside the intended tree — drop it before recreating.
                if (is_link($target) || (file_exists($target) && !is_dir($target))) {
                    @unlink($target);
                }
                if (!is_dir($target)) {
                    @mkdir($target, 0775, true);
                }
                continue;
            }

            // copy() follows symlinks at the destination, so unlink first to
            // avoid overwriting the link target instead of replacing the link.
            if (is_link($target) || is_dir($target)) {
                $this->removePath($target);
            }

            @copy($item->getPathname(), $target);
        }
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (File::isDirectory($path)) {
            File::deleteDirectory($path);
        }
    }

    public function getCurrentDomain(): string
    {
        return str_replace(["http://", "https://", "www."], "", url('/'));
    }

    public function addonActivationProcess(object $request): array
    {
        $response = $this->getRequestConfig(
            username: $request['username'],
            purchaseKey: $request['purchase_key'],
            softwareId: $request['software_id'] ?? SOFTWARE_ID,
            softwareType: $request['software_type'] ?? base64_decode('cHJvZHVjdA=='),
            name: $request['name'],
            identifier: $request['email'],
        );

        $this->updateActivationConfig(app: $request['addon_name'], response: $response);

        $status = $response['active'] ?? 0;
        $message = $response['message'] ?? translate('Activation_failed');

        if ((int)$status) {
            return [
                'status' => (int)$status,
                'activation_status' => 1,
                'username' => $request['username'],
                'purchase_code' => $request['purchase_code'],
            ];
        }

        return [
            'status' => (int)$status,
            'message' => $message
        ];
    }

}
