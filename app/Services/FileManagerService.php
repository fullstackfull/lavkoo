<?php

namespace App\Services;

use App\Utils\PlainFileName;
use App\Utils\StoredFileExtension;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class FileManagerService
{
    /** An archive with more entries than a gallery needs, or one that is mostly empty space (a 200 KB zip that holds 100 MB), is a way to fill a disk. */
    private const MOST_ARCHIVE_ENTRIES = 5000;

    private const LARGE_ARCHIVE_BYTES = 50 * 1024 * 1024;

    private const MOST_COMPRESSION_RATIO = 100;

    /** The disk the gallery works on: S3 only when the shop keeps its files there, whatever disk the request names. */
    public function galleryDisk(mixed $requested): string
    {
        return $requested === 's3' && getWebConfig(name: 'storage_connection_type') === 's3' ? 's3' : 'public';
    }

    public function uploadFileAndImages(object $request): bool
    {
        $storage = $this->galleryDisk(requested: $request['storage']);
        $folder = $request['path'];
        if (!is_string($folder) || !PlainFileName::staysInside(path: $folder)) {
            return false;
        }

        if ($request->hasfile('images')) {
            $images = $request->file('images');
            foreach ($images as $image) {
                $baseName = pathinfo($image->getClientOriginalName(), PATHINFO_FILENAME);
                $extension = StoredFileExtension::of(clientExtension: $image->getClientOriginalExtension(), file: $image);
                if ($storage == 's3') {
                    Storage::disk($storage)->putFileAs($folder, $image, $baseName . '.' . $extension);
                } else {
                    $name = Str::slug($baseName) . '.' . $extension;
                    $path = $folder == 'public' ? $name : ($folder . '/' . $name);
                    Storage::disk('public')->put($path, file_get_contents($image));
                }
            }
        }

        if ($request->hasfile('file')) {
            $archive = $request->file('file');
            $isStored = $storage === 's3'
                ? $this->putArchiveOnBucket(archive: $archive, folder: $folder)
                : $this->unpackArchive(archive: $archive, folder: $folder);
            if (!$isStored) {
                return false;
            }
        }
        return true;
    }

    /** Stores the entries of an archive that the gallery stores on the bucket, each under a name of its own; whether anything was stored. */
    private function putArchiveOnBucket(mixed $archive, string $folder): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            return false;
        }

        $stored = 0;
        foreach ($this->storableEntries(zip: $zip) ?? [] as $index => $name) {
            $content = $zip->getFromIndex($index);
            if ($content === false) {
                continue;
            }
            $imageName = Carbon::now()->toDateString() . '-' . uniqid() . '.' . pathinfo($name, PATHINFO_EXTENSION);
            Storage::disk('s3')->put($folder . '/' . $imageName, $content, 'public');
            $stored++;
        }
        $zip->close();

        return $stored > 0;
    }

    /**
     * Unpacks the entries of an archive that the gallery stores into a folder of the public disk. The entries are chosen before anything is
     * written, so a script in the archive never reaches the disk, and the unpacking directory goes whatever happens to the copy.
     */
    private function unpackArchive(mixed $archive, string $folder): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            return false;
        }

        $entries = $this->storableEntries(zip: $zip);
        if ($entries === null || $entries === []) {
            $zip->close();
            return false;
        }

        $tempPath = storage_path('app/temp-zip-' . Str::random(10));
        File::makeDirectory($tempPath);
        try {
            $zip->extractTo($tempPath, array_values($entries));
            $zip->close();
            $stored = $this->copyAndTouch($tempPath, $folder === 'public' ? storage_path('app/public') : storage_path('app/public/' . $folder));
        } finally {
            File::deleteDirectory($tempPath);
        }
        cacheRemoveByType(type: 'file_manager');

        return $stored > 0;
    }

    /** @return array<int, string>|null the names of the entries the gallery stores by their index, or nothing when the archive is a way to fill the disk */
    private function storableEntries(ZipArchive $zip): ?array
    {
        if ($zip->numFiles > self::MOST_ARCHIVE_ENTRIES) {
            return null;
        }

        $entries = [];
        $size = 0;
        $compressedSize = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $size += $stat['size'];
            $compressedSize += $stat['comp_size'];
            if ($this->isStorableEntry(name: (string)$stat['name'])) {
                $entries[$index] = $stat['name'];
            }
        }

        return $size > self::LARGE_ARCHIVE_BYTES && $size > $compressedSize * self::MOST_COMPRESSION_RATIO ? null : $entries;
    }

    /**
     * A file of a data type, under a name the disk accepts, that holds no script extension and is no metadata of the system that made the archive.
     * A name with a hidden segment (`.hidden/a.jpg`, `.thumb.png`) is none either: the unpacking never listed it, so it was never stored.
     */
    private function isStorableEntry(string $name): bool
    {
        return $name !== ''
            && !str_ends_with($name, '/')
            && !self::shouldSkip($name)
            && preg_match('#(^|/)\.[^/]#', $name) !== 1
            && PlainFileName::staysInside(path: $name)
            && StoredFileExtension::isDataType(extension: pathinfo($name, PATHINFO_EXTENSION))
            && !StoredFileExtension::hidesHandler(fileName: basename($name));
    }

    /** @return int how many files were copied */
    private function copyAndTouch($src, $dst): int
    {
        $copied = 0;
        $files = File::allFiles($src);
        foreach ($files as $file) {
            if (!StoredFileExtension::isDataType(extension: $file->getExtension()) || StoredFileExtension::hidesHandler(fileName: $file->getFilename())) {
                continue;
            }
            $relativePath = Str::after($file->getPathname(), $src);
            $destinationPath = $dst . $relativePath;
            File::ensureDirectoryExists(dirname($destinationPath));
            File::copy($file->getPathname(), $destinationPath);
            touch($destinationPath);
            $copied++;
        }

        return $copied;
    }

    public static function shouldSkip($filename): bool
    {
        $skipFiles = [
            '__MACOSX/', // Skip macOS metadata files
            '.DS_Store', // Skip .DS_Store files
            'Thumbs.db', // Skip Thumbs.db files (Windows)
            // Add more conditions as needed
        ];

        foreach ($skipFiles as $skipFile) {
            if (strpos($filename, $skipFile) === 0) {
                return true;
            }
        }

        return false;
    }


    public static function getFoldersWithInfo(string|null $targetFolder, string|null $storage = 'public'): array
    {
        $currentFolder = Storage::disk($storage)->Directories($targetFolder);

        $folders = [];
        foreach ($currentFolder as $folder) {
            $name = explode('/', $folder);
            $readableName = str_ireplace(['\'', '"', ',', ';', '<', '>', '?', '“', '”', '_', '-'], ' ', preg_replace('/\s\s+/', ' ', end($name)));

            $currentFolderFiles = Storage::disk($storage)->files($folder);
            $currentFolderFiles = array_filter($currentFolderFiles, function ($file) {
                return basename($file) !== '.DS_Store' && basename($file) !== 'Thumbs.db';
            });

            $totalFileSize = 0;
            foreach ($currentFolderFiles as $file) {
                $totalFileSize += Storage::disk($storage)->size($file);
            }

            $folders[] = [
                'name' => $readableName,
                'path' => $folder,
                'encodePath' => base64_encode($folder),
                'lastPath' => str_replace(end($name), '', $folder),
                'type' => 'Folder',
                'totalFiles' => count($currentFolderFiles) ?? 0,
                'size' => self::getAdvancedFileFormatSize($totalFileSize),
                'allFolders' => self::getFoldersWithInfo($folder),
                'totalFolders' => count(Storage::disk($storage)->Directories($folder)),
            ];
        }

        usort($folders, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return $folders;
    }

    public static function getAllFilesWithInfo($targetFolder = null, string|null $storage = 'public'): array
    {
        $targetFolder = $targetFolder == 'public' ? '/' : $targetFolder;
        $currentFolderFiles = Storage::disk($storage)->files($targetFolder);

        $currentFolderFiles = array_filter($currentFolderFiles, function ($file) {
            return basename($file) !== '.DS_Store' && basename($file) !== 'Thumbs.db';
        });

        $AllDirectories = Storage::disk($storage)->directories($targetFolder);
        $filesWithInfo = self::getCurrentFolderFilesWithInfo(targetFolder: $targetFolder, storage: $storage);
        $foldersWithInfo = self::getFoldersWithInfo(targetFolder: $targetFolder, storage: $storage);

        $totalFileSize = 0;
        foreach ($currentFolderFiles as $file) {
            $totalFileSize += Storage::disk($storage)->size($file);
        }

        return [
            'size' => self::getAdvancedFileFormatSize($totalFileSize),
            'files' => $filesWithInfo,
            'folders' => $foldersWithInfo,
            'totalFiles' => count($currentFolderFiles),
            'totalDirectories' => count($AllDirectories),
        ];
    }

    public static function getCurrentFolderFilesWithInfo($targetFolder = null, $storage = 'public'): array
    {
        $filePaths = Storage::disk($storage)->files($targetFolder);
        $FilesWithInfo = [];
        foreach ($filePaths as $file) {
            $type = explode('/', Storage::disk($storage)->mimeType($file))[0];
            $name = explode('/', $file);
            $dbPath = explode('/', $file, 2);

            if (!(empty(end($name)) || self::shouldSkip(end($name)))) {
                $FilesWithInfo[] = [
                    'name' => end($name),
                    'short_name' => self::getFileMinifyString(end($name)),
                    'driver' => $storage,
                    'path' => $file,
                    'db_path' => end($dbPath),
                    'encodePath' => Crypt::encryptString($file),
                    'type' => $type,
                    'size' => self::getAdvancedFileFormatSize(Storage::disk($storage)->size($file)),
                    'sizeInInteger' => Storage::disk($storage)->size($file),
                    'extension' => pathinfo($file, PATHINFO_EXTENSION),
                ];
            }
        }
        usort($FilesWithInfo, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        return $FilesWithInfo;
    }

    public static function getFileMinifyString($inputString, $prefixLength = 15, $suffixLength = 8, $ellipsis = '.....')
    {
        if (strlen($inputString) <= $prefixLength + $suffixLength) {
            return $inputString;
        }
        $prefix = substr($inputString, 0, $prefixLength);
        $suffix = substr($inputString, -$suffixLength);
        return $prefix . $ellipsis . $suffix;
    }

    public static function getAdvancedFileFormatSize($size = 0): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $unitIndex = 0;
        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }

    public static function getFileManagerBreadcrumb($path): array
    {
        $decodedPath = trim($path, '/');
        $breadcrumb = [];

        $segments = explode('/', $decodedPath);
        $segments = in_array('public', $segments) ? $segments : array_merge(['public'], $segments);

        $relativePath = '';
        foreach ($segments as $index => $segment) {
            if ($segment === 'public') {
                $decodePath = '/';
                $relativePath = ''; // reset relative for others
            } else {
                $relativePath .= ($relativePath === '' ? '' : '/') . $segment;
                $decodePath = $relativePath;
            }
            if (!empty($decodePath)) {
                $breadcrumb[] = [
                    'name' => $segment === 'public'
                        ? (getDefaultLanguage() == 'en' ? 'Public' : translate('Main'))
                        : ucwords(str_replace(['-', '_'], ' ', $segment)),
                    'path' => base64_encode($decodePath),
                    'decode_path' => $decodePath,
                ];
            }
        }

        return $breadcrumb;
    }

    public function getRecentFiles(string $storage = 'public'): array
    {
        $cacheKey = $storage == 's3' ? 'cache_for_recent_file_list_s3' : 'cache_for_recent_file_list_public';
        return Cache::remember($cacheKey, CACHE_FOR_3_HOURS, function () use ($storage) {
            $recentFilesPath = Storage::disk($storage)->allFiles('/');
            usort($recentFilesPath, function ($a, $b) use ($storage) {
                $timeA = Storage::disk($storage)->lastModified($a);
                $timeB = Storage::disk($storage)->lastModified($b);
                return $timeB - $timeA;
            });
            $recentFilesPath = array_slice($recentFilesPath, 0, 10);

            $FilesWithInfo = [];
            foreach ($recentFilesPath as $file) {
                $type = explode('/', Storage::disk($storage)->mimeType($file))[0];
                $name = explode('/', $file);
                $dbPath = explode('/', $file, 2);

                if (!(empty(end($name)) || self::shouldSkip(end($name)))) {
                    $FilesWithInfo[] = [
                        'name' => end($name),
                        'short_name' => self::getFileMinifyString(end($name)),
                        'driver' => $storage,
                        'path' => $file,
                        'db_path' => end($dbPath),
                        'encodePath' => Crypt::encryptString($file),
                        'type' => $type,
                        'size' => self::getAdvancedFileFormatSize(Storage::disk($storage)->size($file)),
                        'sizeInInteger' => Storage::disk($storage)->size($file),
                        'extension' => pathinfo($file, PATHINFO_EXTENSION),
                        'last_modified' => Carbon::parse(date('Y-m-d H:i:s', Storage::disk($storage)->lastModified($file)))
                    ];
                }
            }
            return $FilesWithInfo;
        });
    }
}
