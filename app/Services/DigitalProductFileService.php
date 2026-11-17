<?php

namespace App\Services;

use App\Traits\FileManagerTrait;
use App\Utils\PlainFileName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Where the digital files of the shop live.
 *
 * They used to be stored on the public disk under a date and a time-based id, so anyone who knew or guessed a name could fetch a paid file from the
 * storage address with no code and no payment. With `filesystems.digital_files_private` (DIGITAL_FILES_PRIVATE) on, a new file goes to the private
 * disk, which the web server does not serve, and every reader asks this service for the file instead of building an address. With it off (the
 * default) the private disk is never touched and every file is written, read and deleted where it always was.
 *
 * A file is read from the private disk first and from the public disk second, so a file stored before the switch is still delivered until
 * `digital-files:privatize` has moved it. A name is only a claim of a row, so a name that is not one plain file name is found on no disk.
 */
class DigitalProductFileService
{
    use FileManagerTrait {
        fileUpload as protected;
        update as protected;
    }

    public const DIRECTORY = 'product/digital-product/';

    public const PRIVATE_DISK = 'digital_private';

    private const PUBLIC_DISK = 'public';

    public function isPrivate(): bool
    {
        return (bool) config('filesystems.digital_files_private');
    }

    /** Stores a new digital file and returns the name it is stored under. */
    public function store(UploadedFile $file): string
    {
        return $this->fileUpload(dir: self::DIRECTORY, format: $file->getClientOriginalExtension(), file: $file, disk: $this->diskOfNewFiles());
    }

    /** Stores a new digital file in place of an old one, which is deleted unless another product holds it. */
    public function replace(?string $oldName, UploadedFile $file): string
    {
        return $this->update(dir: self::DIRECTORY, oldImage: $oldName, format: $file->getClientOriginalExtension(), image: $file, fileType: 'file', disk: $this->diskOfNewFiles());
    }

    /** @return string|null the disk that holds the file, the private one first, or nothing when the name is no file of the folder */
    public function diskHolding(?string $name): ?string
    {
        if (!PlainFileName::isPlain($name)) {
            return null;
        }

        foreach ($this->disksInOrderOfReading() as $disk) {
            if (Storage::disk($disk)->fileExists(self::DIRECTORY . $name)) {
                return $disk;
            }
        }

        return null;
    }

    public function exists(?string $name): bool
    {
        return $this->diskHolding(name: $name) !== null;
    }

    /** The file as a download, or nothing when no disk holds it. A file that is delivered while the mode is on is never kept by a cache that more than one person reads. */
    public function download(?string $name): ?BinaryFileResponse
    {
        $disk = $this->diskHolding(name: $name);
        if ($disk === null) {
            return null;
        }

        $response = response()->download(Storage::disk($disk)->path(self::DIRECTORY . $name));
        if ($this->isPrivate()) {
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');
        }

        return $response;
    }

    /** Whether the private disk holds the file that a path of the disk names (`product/digital-product/x.pdf`, with or without the slash in front). Always no while the mode is off. */
    public function holdsPrivately(string $path): bool
    {
        $name = $this->isPrivate() ? $this->nameInFolder(path: $path) : null;

        return $name !== null && Storage::disk(self::PRIVATE_DISK)->fileExists(self::DIRECTORY . $name);
    }

    /** Deletes the copy that the private disk holds of the file a path names, if it holds one: a file is deleted from every disk that has it. */
    public function deletePrivateCopy(string $path): void
    {
        if ($this->holdsPrivately(path: $path)) {
            Storage::disk(self::PRIVATE_DISK)->delete(self::DIRECTORY . $this->nameInFolder(path: $path));
        }
    }

    private function diskOfNewFiles(): ?string
    {
        return $this->isPrivate() ? self::PRIVATE_DISK : null;
    }

    /** @return list<string> */
    private function disksInOrderOfReading(): array
    {
        return $this->isPrivate() ? [self::PRIVATE_DISK, self::PUBLIC_DISK] : [self::PUBLIC_DISK];
    }

    /** The name of the file a path of the disk names inside the folder of the digital files, or nothing for any other path. */
    private function nameInFolder(string $path): ?string
    {
        $relative = ltrim($path, '/');
        $name = str_starts_with($relative, self::DIRECTORY) ? substr($relative, strlen(self::DIRECTORY)) : null;

        return PlainFileName::isPlain($name) ? $name : null;
    }
}
