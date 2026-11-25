<?php

namespace App\Traits;

use App\Services\DigitalProductFileService;
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
use InvalidArgumentException;
use League\Flysystem\FilesystemException;
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
        $storage = $this->storageDisk();
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
     * @param string|null $disk the disk to store the file on, where it is not the one every upload goes to
     * @return string
     */
    public function fileUpload(string $dir, string $format, $file = null, ?string $disk = null): string
    {
        $storage = $disk ?? $this->storageDisk();
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
     * @param string|null $disk the disk to store a file on, where it is not the one every upload goes to
     * @return string
     */
    public function update(string $dir, $oldImage, string $format, $image, string $fileType = 'image', ?string $disk = null): string
    {
        // Only when there IS an old file. With $oldImage null or empty -- a record that has never had one -- `$dir . $oldImage` is
        // the DIRECTORY, `admin/` or `product/`, and Storage::exists() answers true for a directory, so the delete was being handed
        // a folder where a file name belongs. Flysystem does not remove a directory through delete(), so no loss has been measured
        // from this; it is a path that should never have been built, not a bug with a reproduction behind it.
        if ($oldImage !== null && trim((string) $oldImage) !== '') {
            $this->deleteUnsharedFiles(filePaths: [$dir . $oldImage]);
        }

        return $fileType == 'file' ? $this->fileUpload($dir, $format, $image, $disk) : $this->upload($dir, $format, $image);
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

        $storage = $this->storageDisk();
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
     * Asked in order: the disk the record itself remembers, the public disk, and the one this installation stores on now (`storageDisk()`). A
     * record that remembers nothing is still found, and a record that remembers a disk the installation has since moved off is found on the
     * disk it is really on rather than read as missing.
     *
     * Each disk is asked once, and a disk that cannot answer -- credentials that no longer exist for a disk a row still names -- is not the
     * disk holding the file, so the next candidate is asked instead and a file none of them holds comes back as "not copied", which is what
     * the caller reports.
     */
    private function diskHolding(string $filePath, ?string $storageType): ?string
    {
        $candidates = [];
        foreach ([$storageType, 'public', $this->storageDisk()] as $candidate) {
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
            app(DigitalProductFileService::class)->deletePrivateCopy(path: $filePath);
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
        $installationDisk = $this->storageDisk();

        if (Storage::disk('public')->exists($filePath)) {
            return [
                'status' => true,
                'disk' => 'public'
            ];
        } elseif ($installationDisk !== 'public' && Storage::disk($installationDisk)->exists($filePath)) {
            return [
                'status' => true,
                'disk' => $installationDisk
            ];
        } elseif (app(DigitalProductFileService::class)->holdsPrivately(path: $filePath)) {
            return [
                'status' => true,
                'disk' => DigitalProductFileService::PRIVATE_DISK
            ];
        } else {
            return [
                'status' => false,
                'disk' => $installationDisk
            ];
        }
    }

    /**
     * The disk this installation stores an uploaded file ON, which is the answer `config('filesystems.disks.default')` was asked for in five
     * places and never gave.
     *
     * There is no `disks.default` entry in `config/filesystems.php` and nothing writes one, so every one of those reads was `null ?? 'public'`
     * -- i.e. the local public disk, whatever the shop had configured. For a shop on the public disk that is the right answer by accident; for a
     * shop on s3 it meant the DESTINATION of every upload and every copy was the local disk and the `disk` recorded against the row was always
     * `public`, so the file was written where nothing serves it from and the record pointed at the wrong disk as well.
     *
     * What the installation actually chose is `filesystems.default`: `setStorageConnectionEnvironment()` copies the `storage_connection_type`
     * setting into it at boot, and `config/filesystems.php` seeds it from `FILESYSTEM_DRIVER`.
     *
     * `local` is not an answer. It is Laravel's shipped default for that env var, this application has never stored an upload there (every
     * record's `*_storage_type` is `public` or `s3`), and its root is outside the directory the `storage` symlink publishes -- so a file written
     * there has no address. A disk the configuration does not define is not an answer either. Both fall back to `public`, which is what the five
     * reads effectively returned, so a shop that has not chosen s3 behaves exactly as it did.
     */
    private function storageDisk(): string
    {
        $chosen = config('filesystems.default');
        $chosen = is_string($chosen) ? trim($chosen) : '';

        return $chosen !== '' && $chosen !== 'local' && config('filesystems.disks.' . $chosen) !== null ? $chosen : 'public';
    }
}
