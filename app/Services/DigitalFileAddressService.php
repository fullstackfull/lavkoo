<?php

namespace App\Services;

use App\Models\OrderDetail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/**
 * What an answer says about where a digital file can be fetched.
 *
 * Every screen and every JSON answer that showed a digital file built the public address of it. While DIGITAL_FILES_PRIVATE is off they still do, and nothing here changes
 * what they say. While it is on the answer carries the authenticated route of the area that asks, or the signed address the website's checks hand out, or nothing: never the
 * public address of a file, even of one that is still on the public disk.
 */
class DigitalFileAddressService
{
    /** The route of the website that streams the file of an order line to a signed address. */
    public const CUSTOMER_ROUTE = 'digital-product-download-file';

    /** How long the signed address that the website hands out lasts: a browser fetches it at once. */
    private const SIGNED_ADDRESS_MINUTES = 10;

    /** @var array<string, string> the prefix of the names of the routes of each area of the shop, by the address its requests have */
    private const AREAS = [
        'admin/*' => 'admin.',
        'vendor/*' => 'vendor.',
        'api/v3/seller/*' => 'seller-api.v3.',
        'api/v2/seller/*' => 'seller-api.v2.',
        'api/v1/*' => 'customer-api.',
    ];

    /** @var array<string, string> the name of the route that streams each kind of file, after the prefix of the area */
    private const ROUTES = [
        'order-line' => 'orders.digital-file',
        'product' => 'products.digital-file',
        'variation' => 'products.digital-variation-file',
    ];

    public function __construct(private readonly DigitalProductFileService $files)
    {
    }

    /**
     * What an answer that used to carry the public address of a file says while the mode is on: the authenticated route of the area that asks (the admin panel, the
     * vendor panel, a seller app, the customer app) when it has one for this file, otherwise no address, and the status 404 that storageLink() gives a file it cannot
     * address. Nothing while the mode is off or the row keeps its file on the bucket, so the caller gives the answer it always gave.
     *
     * @param string $kind `order-line`, `product` or `variation`
     * @return array{key: ?string, path: ?string, status: int}|null
     */
    public function privateAnswer(?string $name, string $kind, int|string $id, ?string $storageType = null): ?array
    {
        if (!$this->files->isPrivate() || $this->files->isOnTheBucket(storageType: $storageType)) {
            return null;
        }

        return $this->answerWith(name: $name, address: $this->files->exists(name: $name) ? $this->addressInTheArea(kind: $kind, id: $id, name: $name) : null);
    }

    /** The `digital_file_ready_full_url` of the snapshot of an order line: what storageLink() answers, or the route of the area that asks while the mode is on. */
    public function readyFileAnswerOfLine(OrderDetail $orderDetail): array
    {
        $product = $orderDetail->productSnapshot();
        $storageType = $product['storage_path'] ?? 'public';

        return $this->privateAnswer(name: $product['digital_file_ready'], kind: 'order-line', id: $orderDetail->id, storageType: $storageType)
            ?? storageLink(DigitalProductFileService::FOLDER, $product['digital_file_ready'], $storageType);
    }

    /**
     * Where the buyer of an order line can fetch the file the line delivers, answered the way storageLink() answers (`key`, `path`, `status`). While the mode is off it
     * is the public address, as it always was. While it is on it is the signed address of the route that streams the file, and only for a paid order.
     *
     * @return array{key: ?string, path: ?string, status: int}
     */
    public function answerForTheBuyer(OrderDetail $orderDetail): array
    {
        $deliversTheReadyFile = $orderDetail->deliversTheReadyFile();
        $storageType = $deliversTheReadyFile ? ($orderDetail->productSnapshot()['storage_path'] ?? 'public') : $orderDetail->digitalFileAfterSellStorageType();

        if ($this->files->isPrivate() && !$this->files->isOnTheBucket(storageType: $storageType)) {
            return $this->answerWith(name: $orderDetail->deliveredDigitalFile(), address: $this->signedAddressOf(orderDetail: $orderDetail));
        }

        return $deliversTheReadyFile ? storageLink(DigitalProductFileService::FOLDER, $orderDetail->deliveredDigitalFile(), $storageType) : $orderDetail->digital_file_after_sell_full_url;
    }

    /** The address the apps built the address of a digital file from: the public folder, which does not exist while the mode is on. */
    public function publicBaseUrl(): string
    {
        return $this->files->isPrivate() ? '' : dynamicStorage(path: 'storage/app/public/' . DigitalProductFileService::FOLDER);
    }

    /** The address of the route of the area that is asking, or nothing when the area has none for this kind of file (the website gets its signed address elsewhere). */
    private function addressInTheArea(string $kind, int|string $id, string $name): ?string
    {
        foreach (self::AREAS as $pattern => $prefix) {
            if (request()->is($pattern)) {
                $route = $prefix . self::ROUTES[$kind];

                return Route::has($route) ? route($route, ['id' => $id, 'fileName' => $name]) : null;
            }
        }

        return null;
    }

    private function signedAddressOf(OrderDetail $orderDetail): ?string
    {
        $name = $orderDetail->deliveredDigitalFile();
        if ($orderDetail->order?->payment_status !== 'paid' || !$this->files->exists(name: $name)) {
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
}
