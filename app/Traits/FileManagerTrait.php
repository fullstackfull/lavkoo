<?php

namespace App\Traits;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use InvalidArgumentException;
use League\Flysystem\FilesystemException;

trait FileManagerTrait
{
    /**
     * upload method working for image
     * @param string $dir
     * @param string $format
     * @param $image
     * @return string
     */
    protected function upload(string $dir, string $format, $image = null): string
    {
        $storage = config('filesystems.disks.default') ?? 'public';
        Cache::forget("cache_all_files_for_public_storage");
        if (!is_null($image)) {
            if (!$this->checkFileExists($dir)['status']) {
                Storage::disk($storage)->makeDirectory($dir);
            }

            $isOriginalImage = in_array($image->getClientOriginalExtension(), ['gif', 'svg']);
            if ($isOriginalImage) {
                $imageName = Carbon::now()->toDateString() . "-" . uniqid() . "." . $image->getClientOriginalExtension();
                Storage::disk($storage)->put($dir . $imageName, file_get_contents($image));
            } else {
                if (in_array(request()->ip(), ['127.0.0.1', '::1']) && !(imagetypes() & IMG_WEBP) || env('APP_DEBUG') && !(imagetypes() & IMG_WEBP)) {
                    $format = 'png';
                }
                $imageWebp = Image::make($image)->encode($format);
                $imageName = Carbon::now()->toDateString() . "-" . uniqid() . "." . $format;
                Storage::disk($storage)->put($dir . $imageName, $imageWebp);
                $imageWebp->destroy();
            }
        } else {
            $imageName = 'def.png';
        }

        cacheRemoveByType(type: 'file_manager');
        return $imageName;
    }

    /**
     * @param string $dir
     * @param string $format
     * @param $file
     * @return string
     */
    public function fileUpload(string $dir, string $format, $file = null): string
    {
        $storage = config('filesystems.disks.default') ?? 'public';
        Cache::forget("cache_all_files_for_public_storage");

        if (!is_null($file)) {
            $fileName = Carbon::now()->toDateString() . "-" . uniqid() . "." . $format;
            if (!$this->checkFileExists($dir)['status']) {
                Storage::disk($storage)->makeDirectory($dir);
            }
            if ($file) {
                Storage::disk($storage)->put($dir . $fileName, file_get_contents($file));
            }
        } else {
            $fileName = 'def.png';
        }

        return $fileName;
    }

    /**
     * @param string $dir
     * @param $oldImage
     * @param string $format
     * @param $image
     * @param string $fileType image/file
     * @return string
     */
    public function update(string $dir, $oldImage, string $format, $image, string $fileType = 'image'): string
    {
        if ($this->checkFileExists(filePath: $dir . $oldImage)['status']) {
            Storage::disk($this->checkFileExists(filePath: $dir . $oldImage)['disk'])->delete($dir . $oldImage);
        }
        return $fileType == 'file' ? $this->fileUpload($dir, $format, $image) : $this->upload($dir, $format, $image);
    }

    /**
     * A second, independent copy of a stored file: a new name on the disk this installation writes uploads to, holding the same bytes.
     *
     * Two records must never point at one file. A product copied into another country whose image is the source's file loses that image the
     * day the source is edited or deleted, and one country's catalogue then depends on another country's storage. The copy is written where
     * `upload()` writes, so it lands exactly where a freshly uploaded file would, and the source is looked for with `diskHolding()`.
     *
     * @param string|null $storageType the disk the record remembers this file on (`*_storage_type`, or a gallery entry's `storage`)
     * @return array{name: string, storage: string}|null null when the source file cannot be read, so a caller can record that rather than share it
     */
    protected function copyStoredFile(string $dir, ?string $fileName, ?string $storageType = null, ?string $format = null): ?array
    {
        if ($fileName === null || $fileName === '' || $fileName === 'def.png') {
            return null;
        }

        $sourceDisk = $this->diskHolding(filePath: $dir . $fileName, storageType: $storageType);
        if ($sourceDisk === null) {
            return null;
        }

        $storage = config('filesystems.disks.default') ?? 'public';
        $extension = $format ?? (pathinfo($fileName, PATHINFO_EXTENSION) ?: 'webp');
        $copyName = Carbon::now()->toDateString() . '-' . uniqid() . '.' . $extension;

        if (!$this->checkFileExists(filePath: $dir)['status']) {
            Storage::disk($storage)->makeDirectory($dir);
        }
        Storage::disk($storage)->put($dir . $copyName, Storage::disk($sourceDisk)->get($dir . $fileName));
        Cache::forget('cache_all_files_for_public_storage');
        cacheRemoveByType(type: 'file_manager');

        return ['name' => $copyName, 'storage' => $storage];
    }

    /**
     * The disk a stored file is really on, or null when none of the candidates holds it.
     *
     * `checkFileExists` probes the public disk and then s3, but only while `filesystems.disks.default` says s3 -- a key this application never
     * sets. `setStorageConnectionEnvironment()` sets `filesystems.default` and `filesystems.disks.<type>`, and `config/filesystems.php` has no
     * `disks.default` entry at all, so that test is never true and a file on s3 reads as missing. Asked here, in order: the disk the record
     * itself remembers, the public disk, and the one this installation stores on now.
     *
     * Each disk is asked once, and a disk that cannot answer -- credentials that no longer exist for a disk a row still names -- is not the
     * disk holding the file, so the next candidate is asked instead and a file none of them holds comes back as "not copied", which is what
     * the caller reports.
     */
    private function diskHolding(string $filePath, ?string $storageType): ?string
    {
        $candidates = [];
        foreach ([$storageType, 'public', config('filesystems.default')] as $candidate) {
            $disk = is_string($candidate) ? trim($candidate) : '';
            if ($disk !== '' && !in_array($disk, $candidates, true) && config('filesystems.disks.' . $disk) !== null) {
                $candidates[] = $disk;
            }
        }

        foreach ($candidates as $disk) {
            try {
                if (Storage::disk($disk)->exists($filePath)) {
                    return $disk;
                }
            } catch (FilesystemException | InvalidArgumentException) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param string $filePath
     * @return array
     */
    protected function  delete(string $filePath): array
    {
        if ($this->checkFileExists(filePath: $filePath)['status']) {
            Storage::disk($this->checkFileExists(filePath: $filePath)['disk'])->delete($filePath);
        }
        cacheRemoveByType(type: 'file_manager');
        Cache::forget("cache_all_files_for_public_storage");
        return [
            'success' => 1,
            'message' => translate('Removed_successfully')
        ];
    }

    public function setStorageConnectionEnvironment(): void
    {
        $storageConnectionType = getWebConfig(name: 'storage_connection_type') ?? 'public';
       Config::set('filesystems.default', $storageConnectionType);
        $storageConnectionS3Credential = getWebConfig(name: 'storage_connection_s3_credential');
        if ($storageConnectionType == 's3' && !empty($storageConnectionS3Credential)) {
            Config::set('filesystems.disks.' . $storageConnectionType, $storageConnectionS3Credential);
        }
    }

    private function checkFileExists(string $filePath): array
    {
        if (Storage::disk('public')->exists($filePath)) {
            return [
                'status' => true,
                'disk' => 'public'
            ];
        } elseif (config('filesystems.disks.default') == 's3' && Storage::disk('s3')->exists($filePath)) {
            return [
                'status' => true,
                'disk' => 's3'
            ];
        } else {
            return [
                'status' => false,
                'disk' => config('filesystems.disks.default') ?? 'public'
            ];
        }
    }
}
