<?php

namespace App\Traits;

use App\Utils\PlainFileName;
use App\Utils\SharedProductFile;
use App\Utils\StoredFileExtension;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Exception\NotReadableException;
use Intervention\Image\Facades\Image;
use Symfony\Component\HttpFoundation\File\File;

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

            if (StoredFileExtension::isGifToKeep($image)) {
                $imageName = Carbon::now()->toDateString() . "-" . uniqid() . ".gif";
                Storage::disk($storage)->put($dir . $imageName, file_get_contents($image));
            } else {
                if (in_array(request()->ip(), ['127.0.0.1', '::1']) && !(imagetypes() & IMG_WEBP) || env('APP_DEBUG') && !(imagetypes() & IMG_WEBP)) {
                    $format = 'png';
                }
                if (!StoredFileExtension::isRasterImage($image)) {
                    throw ValidationException::withMessages(['image' => translate('the_file_is_not_a_supported_image')]);
                }
                try {
                    $imageWebp = Image::make($image)->encode($format);
                } catch (NotReadableException) {
                    throw ValidationException::withMessages(['image' => translate('the_file_is_not_a_supported_image')]);
                }
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
            $fileName = Carbon::now()->toDateString() . "-" . uniqid() . "." . StoredFileExtension::of(clientExtension: $format, file: $file instanceof File ? $file : null);
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
        $this->deleteUnsharedFiles(filePaths: [$dir . $oldImage]);

        return $fileType == 'file' ? $this->fileUpload($dir, $format, $image) : $this->upload($dir, $format, $image);
    }

    /**
     * @param string $filePath
     * @return array
     */
    protected function  delete(string $filePath): array
    {
        return $this->deleteAll(filePaths: [$filePath]);
    }

    /**
     * @param list<string> $filePaths
     * @return array
     */
    protected function deleteAll(array $filePaths): array
    {
        $this->deleteUnsharedFiles(filePaths: $filePaths);
        cacheRemoveByType(type: 'file_manager');
        Cache::forget("cache_all_files_for_public_storage");
        return [
            'success' => 1,
            'message' => translate('Removed_successfully')
        ];
    }

    /**
     * Deletes the files that are on a disk and that no other product holds; a file that is not there costs no search of the products.
     *
     * @param list<string> $filePaths
     */
    private function deleteUnsharedFiles(array $filePaths): void
    {
        $stored = array_filter($filePaths, fn (string $filePath): bool => PlainFileName::staysInside(path: $filePath) && $this->checkFileExists(filePath: $filePath)['status']);
        foreach (array_diff($stored, SharedProductFile::sharedAmong(paths: $stored)) as $filePath) {
            Storage::disk($this->checkFileExists(filePath: $filePath)['disk'])->delete($filePath);
        }
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
