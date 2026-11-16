<?php

namespace App\Services;

use App\Services\FileManager\ArchiveReport;
use App\Utils\PlainFileName;
use App\Utils\StoredFileExtension;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
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

    public function uploadFileAndImages(object $request, ArchiveReport $report = new ArchiveReport()): bool
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

        if ($request->hasfile('file') && !$this->storeArchive(archive: $request->file('file'), folder: $folder, disk: $storage, report: $report)) {
            return false;
        }

        return true;
    }

    /**
     * Stores the entries of an archive that the gallery stores. They are unpacked into a directory of their own first, under a budget, so an
     * archive that is a way to fill a disk writes nothing to the gallery or the bucket and a script in it never reaches either, and the
     * directory goes whatever happens to the copy.
     */
    private function storeArchive(UploadedFile $archive, string $folder, string $disk, ArchiveReport $report): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($archive->getPathname()) !== true) {
            return false;
        }

        $entries = $this->storableEntries(zip: $zip, report: $report);
        if ($entries === null || $entries === []) {
            $zip->close();
            return false;
        }

        $directory = storage_path('app/temp-zip-' . Str::random(10));
        File::makeDirectory($directory);
        try {
            $names = $this->unpackEntries(zip: $zip, entries: $entries, directory: $directory, archiveBytes: (int)$archive->getSize(), report: $report);
            $stored = match (true) {
                $names === null => 0,
                $disk === 's3' => $this->putOnBucket(directory: $directory, names: $names, folder: $folder),
                default => $this->copyToGallery(directory: $directory, names: $names, folder: $folder),
            };
        } finally {
            $zip->close();
            File::deleteDirectory($directory);
        }
        cacheRemoveByType(type: 'file_manager');

        return $stored > 0;
    }

    /** @return array<int, string>|null the names of the entries the gallery stores by their index, or nothing when there are more of them than a gallery needs */
    private function storableEntries(ZipArchive $zip, ArchiveReport $report): ?array
    {
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string)$zip->statIndex($index)['name'];
            if ($this->isArchiveMetadata(name: $name)) {
                continue;
            }
            if (!$this->isStorableEntry(name: $name)) {
                $report->skip(name: $name);
                continue;
            }
            if (count($entries) === self::MOST_ARCHIVE_ENTRIES) {
                $report->refuse(reason: ArchiveReport::TOO_MANY_FILES, limit: self::MOST_ARCHIVE_ENTRIES);
                return null;
            }
            $entries[$index] = $name;
        }

        return $entries;
    }

    /**
     * Streams the entries into the directory and counts the bytes that really arrive: the sizes an archive declares are what its maker wrote
     * down, and a stream of zeros under a header that says 100 bytes unpacks to what it unpacks to. An entry that cannot be read (an encrypted
     * one) is left out.
     *
     * @param array<int, string> $entries
     * @return list<string>|null the names that were unpacked, or nothing when the archive unpacks to more than it may
     */
    private function unpackEntries(ZipArchive $zip, array $entries, string $directory, int $archiveBytes, ArchiveReport $report): ?array
    {
        $budget = $this->unpackingBudget(zip: $zip, entries: $entries, archiveBytes: $archiveBytes);
        $remainingBytes = $budget;
        $unpacked = [];
        foreach ($entries as $index => $name) {
            $written = $this->unpackEntry(zip: $zip, index: $index, target: $directory . '/' . $name, limit: $remainingBytes);
            if ($written === null) {
                $report->skip(name: $name);
                continue;
            }
            if ($written > $remainingBytes) {
                $report->refuse(reason: ArchiveReport::TOO_LARGE, limit: intdiv($budget, 1024 * 1024));
                return null;
            }
            $remainingBytes -= $written;
            $unpacked[] = $name;
        }

        return $unpacked;
    }

    /**
     * What an archive may unpack to: 50 MB, or 100 times what its stored entries take compressed. The compressed sizes are declared too (an
     * entry listed again and again promises its data again and again), so they count for no more than the archive itself holds.
     *
     * @param array<int, string> $entries
     */
    private function unpackingBudget(ZipArchive $zip, array $entries, int $archiveBytes): int
    {
        $compressedBytes = 0;
        foreach (array_keys($entries) as $index) {
            $compressedBytes += $zip->statIndex($index)['comp_size'];
        }

        return max(self::LARGE_ARCHIVE_BYTES, self::MOST_COMPRESSION_RATIO * min($compressedBytes, $archiveBytes));
    }

    /** @return int|null the bytes written, one more than the limit at the most; nothing when the entry cannot be read */
    private function unpackEntry(ZipArchive $zip, int $index, string $target, int $limit): ?int
    {
        $source = $zip->getStreamIndex($index);
        if ($source === false) {
            return null;
        }

        File::ensureDirectoryExists(dirname($target));
        $destination = fopen($target, 'wb');
        $written = stream_copy_to_stream($source, $destination, $limit + 1);
        fclose($source);
        fclose($destination);

        return $written === false ? null : $written;
    }

    /** A folder, or a file that the system that made the archive added on its own (the `__MACOSX` twins, `.DS_Store`, Thumbs.db): nothing the admin put there. */
    private function isArchiveMetadata(string $name): bool
    {
        return $name === '' || str_ends_with($name, '/') || self::shouldSkip($name) || self::shouldSkip(basename($name));
    }

    /**
     * A file of a data type, under a name the disk accepts, that holds no script extension.
     * A name with a hidden segment (`.hidden/a.jpg`, `.thumb.png`) is none either: the gallery never listed such a path, so it was never stored.
     */
    private function isStorableEntry(string $name): bool
    {
        return preg_match('#(^|/)\.[^/]#', $name) !== 1
            && PlainFileName::staysInside(path: $name)
            && StoredFileExtension::isDataType(extension: pathinfo($name, PATHINFO_EXTENSION))
            && !StoredFileExtension::hidesHandler(fileName: basename($name));
    }

    /**
     * Copies the unpacked files into the folder of the gallery.
     *
     * @param list<string> $names
     */
    private function copyToGallery(string $directory, array $names, string $folder): int
    {
        $gallery = $folder === 'public' ? storage_path('app/public') : storage_path('app/public/' . $folder);
        foreach ($names as $name) {
            $target = $gallery . '/' . $name;
            File::ensureDirectoryExists(dirname($target));
            File::copy($directory . '/' . $name, $target);
            touch($target);
        }

        return count($names);
    }

    /**
     * Stores the unpacked files on the bucket, each under a name of its own.
     *
     * @param list<string> $names
     */
    private function putOnBucket(string $directory, array $names, string $folder): int
    {
        foreach ($names as $name) {
            $imageName = Carbon::now()->toDateString() . '-' . uniqid() . '.' . pathinfo($name, PATHINFO_EXTENSION);
            Storage::disk('s3')->putFileAs($folder, $directory . '/' . $name, $imageName, 'public');
        }

        return count($names);
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
