<?php

namespace App\Services;

use App\Models\OrderDetail;
use App\Traits\FileManagerTrait;
use App\Utils\PlainFileName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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

    private const FOLDER = 'product/digital-product';

    public const DIRECTORY = self::FOLDER . '/';

    public const PRIVATE_DISK = 'digital_private';

    /** The route of the website that streams the file of an order line to a signed address. */
    public const CUSTOMER_ROUTE = 'digital-product-download-file';

    private const PUBLIC_DISK = 'public';

    /** How long the signed address that the website hands out lasts: a browser fetches it at once. */
    private const SIGNED_ADDRESS_MINUTES = 10;

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

    /** Whether a row keeps its file on the bucket: the test storageLink() makes before it builds a bucket address (a row typed `s3` of a shop that stores on the public disk is read from the public disk). */
    public function isOnTheBucket(?string $storageType): bool
    {
        return $storageType === 's3' && config('filesystems.disks.default') === 's3';
    }

    /** The name of the file an order line delivers: the ready file its snapshot names, or the file uploaded after the sale. */
    public function nameOfOrderLine(OrderDetail $orderDetail): ?string
    {
        $product = $this->snapshotOf(orderDetail: $orderDetail);

        return $this->isReadyProduct(snapshot: $product) ? $product['digital_file_ready'] : $orderDetail['digital_file_after_sell'];
    }

    /**
     * Where the buyer of an order line can fetch the file the line delivers, answered the way storageLink() answers (`key`, `path`, `status`). While the mode is off it
     * is the public address, as it always was. While it is on it is the signed address of the route that streams the file, and only for a paid order.
     *
     * @return array{key: ?string, path: ?string, status: int}
     */
    public function answerForTheBuyer(OrderDetail $orderDetail): array
    {
        $product = $this->snapshotOf(orderDetail: $orderDetail);
        $isReadyProduct = $this->isReadyProduct(snapshot: $product);
        $storageType = $isReadyProduct ? ($product['storage_path'] ?? 'public') : $orderDetail->digitalFileAfterSellStorageType();

        if ($this->isPrivate() && !$this->isOnTheBucket(storageType: $storageType)) {
            return $this->answerWith(name: $this->nameOfOrderLine(orderDetail: $orderDetail), address: $this->signedAddressOf(orderDetail: $orderDetail));
        }

        return $isReadyProduct ? storageLink(self::FOLDER, $product['digital_file_ready'], $storageType) : $orderDetail->digital_file_after_sell_full_url;
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

    /** The product of an order line the way it was when the order was placed. */
    private function snapshotOf(OrderDetail $orderDetail): array
    {
        return (array) json_decode((string) $orderDetail['product_details'], true);
    }

    private function isReadyProduct(array $snapshot): bool
    {
        return ($snapshot['digital_product_type'] ?? null) == 'ready_product' && !empty($snapshot['digital_file_ready']);
    }

    private function signedAddressOf(OrderDetail $orderDetail): ?string
    {
        $name = $this->nameOfOrderLine(orderDetail: $orderDetail);
        if ($orderDetail->order?->payment_status !== 'paid' || !$this->exists(name: $name)) {
            return null;
        }

        return URL::temporarySignedRoute(
            name: self::CUSTOMER_ROUTE,
            expiration: now()->addMinutes(self::SIGNED_ADDRESS_MINUTES),
            parameters: ['id' => $orderDetail->id, 'fileName' => $name],
            absolute: false,
        );
    }

    /** @return array{key: ?string, path: ?string, status: int} */
    private function answerWith(?string $name, ?string $address): array
    {
        return ['key' => $name, 'path' => $address, 'status' => $address === null ? 404 : 200];
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
