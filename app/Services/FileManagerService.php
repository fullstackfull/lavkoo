<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class FileManagerService
{

    public function uploadFileAndImages(object $request): bool
    {
        $storage = $request['storage'];
        if ($request->hasfile('images')) {
            $images = $request->file('images');
            foreach ($images as $image) {
                $name = $image->getClientOriginalName();
                if ($storage == 's3') {
                    Storage::disk($storage)->putFileAs($request->path, $image, $name);
                } else {
                    $name = Str::slug(pathinfo($image->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . $image->getClientOriginalExtension();
                    $path = $request['path'] == 'public' ? $name : ($request['path'] . '/' . $name);
                    Storage::disk('public')->put($path, file_get_contents($image));
                }
            }
        }

        if ($request->hasfile('file')) {
            $file = $request->file('file');
            if ($storage === 's3') {
                $zip = new ZipArchive;
                if ($zip->open($file->path()) === true) {
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $stat = $zip->statIndex($i);

                        if (!$stat['name'] || $this->shouldSkip($stat['name'])) {
                            continue;
                        }
                        $filename = $stat['name'];
                        $fileContent = $zip->getFromIndex($i);
                        $format = pathinfo($filename, PATHINFO_EXTENSION);
                        $imageName = Carbon::now()->toDateString() . "-" . uniqid() . "." . $format;
                        $s3 = Storage::disk('s3');
                        $s3Path = $request->path . '/' . $imageName;
                        $s3->put($s3Path, $fileContent, 'public');
                    }
                    $zip->close();
                }
            } else {
                $zip = new ZipArchive();
                if ($zip->open($request->file('file')) === TRUE) {
                    $genFolderName = explode('/', $zip->getNameIndex(0))[0];
                    if ($genFolderName === "__MACOSX") {
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            if (!str_contains($zip->getNameIndex($i), "__MACOSX")) {
                                break;
                            }
                        }
                    }

                    $tempPath = storage_path('app/temp-zip-' . Str::random(10));
                    File::makeDirectory($tempPath);
                    $zip->extractTo($tempPath);
                    $zip->close();
                    if (File::exists($tempPath . '/__MACOSX')) {
                        File::deleteDirectory($tempPath . '/__MACOSX');
                    }

                    $destination = $request['path'] === 'public'
                        ? storage_path('app/public')
                        : storage_path('app/public/' . $request['path']);
                    $this->copyAndTouch($tempPath, $destination);

                    File::deleteDirectory($tempPath);
                    cacheRemoveByType(type: 'file_manager');
                } else {
                    return false;
                }
            }
        }
        return true;
    }

    private function copyAndTouch($src, $dst): void
    {
        $files = File::allFiles($src);
        foreach ($files as $file) {
            $relativePath = Str::after($file->getPathname(), $src);
            $destinationPath = $dst . $relativePath;
            File::ensureDirectoryExists(dirname($destinationPath));
            File::copy($file->getPathname(), $destinationPath);
            touch($destinationPath);
        }
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
