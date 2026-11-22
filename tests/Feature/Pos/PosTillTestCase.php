<?php

namespace Tests\Feature\Pos;

use App\Enums\SessionKey;
use App\Events\DigitalProductDownloadEvent;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Http\Middleware\SellerMiddleware;
use App\Http\Middleware\SellerStaffAccessMiddleware;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Product;
use App\Models\Seller;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsPosSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Base for the tests that drive the two point-of-sale tills through their own routes.
 *
 * Both panels place a sale with a POST the panel's own JavaScript makes: `admin/pos/order-place` and `vendor/pos/order-place`.
 * The cart the sale is placed from lives in the session, which is where the till's own add-to-cart route puts it, so these
 * tests build it through that route and then post the sale — no service is called directly, and nothing builds the state the
 * guard under test is supposed to detect.
 *
 * The heavy request middleware of the panels is switched off, as the panel address tests do; the authentication guard and the
 * controller are not.
 */
abstract class PosTillTestCase extends TestCase
{
    use BuildsPosSchema;
    use RestoresLanguageFiles;

    protected const ADMIN_TILL = 'admin';

    protected const VENDOR_TILL = 'vendor';

    protected const SELLER_ID = 7;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();

        Http::preventStrayRequests();
        Event::fake([DigitalProductDownloadEvent::class]);
        $this->withoutMiddleware([
            AdminMiddleware::class, SellerMiddleware::class, SellerStaffAccessMiddleware::class, ActivationCheckMiddleware::class,
            MaintenanceModeMiddleware::class, ModulePermissionMiddleware::class, Localization::class, DetectMobile::class,
            ApplySeoRedirects::class, NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);

        $this->createPosStoreTables();
        DB::table('sellers')->insert(['id' => self::SELLER_ID, 'f_name' => 'Vendor', 'status' => 'approved']);
        DB::table('shops')->insert(['id' => 2, 'seller_id' => self::SELLER_ID, 'author_type' => 'vendor', 'name' => 'Vendor shop']);

        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** Signs in as the panel the till belongs to. */
    protected function actingAtTill(string $till): static
    {
        return $till === self::ADMIN_TILL
            ? $this->actingAs((new Admin())->forceFill(['id' => 1, 'name' => 'Master Admin']), 'admin')
            : $this->actingAs((new Seller())->forceFill(['id' => self::SELLER_ID, 'f_name' => 'Vendor']), 'seller');
    }

    /** The till's own URLs, as its JavaScript posts to them. */
    protected function addToCartUrl(string $till): string
    {
        return $till === self::ADMIN_TILL ? '/admin/pos/add-to-cart' : '/vendor/pos/add-to-cart';
    }

    protected function placeOrderUrl(string $till): string
    {
        return $till === self::ADMIN_TILL ? '/admin/pos/order-place' : '/vendor/pos/order-place';
    }

    /** A physical product on the shelf, owned by whichever panel's till will sell it. */
    protected function shelfProduct(int $id, int $stock, string $till = self::ADMIN_TILL, float $price = 100.0, ?string $variation = null): void
    {
        DB::table('products')->insert([
            'id' => $id,
            'name' => 'Product ' . $id,
            'slug' => 'product-' . $id,
            'code' => 'CODE' . $id,
            'added_by' => $till === self::ADMIN_TILL ? 'admin' : 'seller',
            'user_id' => $till === self::ADMIN_TILL ? 0 : self::SELLER_ID,
            'seller_id' => $till === self::ADMIN_TILL ? 1 : 2,
            'seller_is' => $till === self::ADMIN_TILL ? 'admin' : 'seller',
            'product_type' => 'physical',
            'current_stock' => $stock,
            'unit_price' => $price,
            'variation' => $variation ?? json_encode([]),
            'choice_options' => json_encode([]),
            'colors' => json_encode([]),
            'images' => json_encode([]),
            'status' => 1,
            'request_status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Puts the cart in the session the way the till's own page does: a walk-in customer and one line per product. */
    protected function givenCartAtTill(string $till, array $lines): string
    {
        $cartId = 'walk-in-customer-1';
        $cart = [];
        foreach ($lines as $productId => $line) {
            $quantity = is_array($line) ? (int) $line['quantity'] : (int) $line;
            $cart[] = [
                'id' => $productId,
                'name' => 'Product ' . $productId,
                'quantity' => $quantity,
                'price' => is_array($line) ? (float) ($line['price'] ?? 100.0) : 100.0,
                'discount' => 0,
                'tax' => 0,
                'variant' => is_array($line) ? ($line['variant'] ?? null) : null,
                'variations' => is_array($line) ? ($line['variations'] ?? []) : [],
                'productType' => 'physical',
                'customerId' => 0,
                'thumbnail' => null,
                'maximum_order_quantity' => 1000,
                'add_to_cart_time' => now(),
            ];
        }

        $this->withSession([
            SessionKey::CURRENT_USER => $cartId,
            SessionKey::CART_NAME => [$cartId],
            $cartId => $cart,
        ]);

        return $cartId;
    }

    /** Posts the sale through the till's own place-order route. */
    protected function placeSaleAtTill(string $till, float $amount, array $extra = []): TestResponse
    {
        return $this->actingAtTill($till)->post($this->placeOrderUrl($till), array_merge([
            'amount' => $amount,
            'type' => 'cash',
            'fulfillment' => 'instant',
            'paid_amount' => $amount,
        ], $extra));
    }

    /**
     * The four readings the slice is judged on, taken after a sale was posted.
     *
     * @return array{orders: int, order_details: int, charged: float|null, current_stock: int, movements: list<array{qty_change: int, balance_after: int|null}>}
     */
    protected function readings(int $productId): array
    {
        $order = Order::query()->orderByDesc('id')->first();

        return [
            'orders' => Order::query()->count(),
            'order_details' => DB::table('order_details')->count(),
            'charged' => $order ? (float) $order->order_amount : null,
            'current_stock' => (int) Product::query()->whereKey($productId)->value('current_stock'),
            'movements' => StockMovement::query()->where('product_id', $productId)->orderBy('id')
                ->get(['qty_change', 'balance_after'])
                ->map(static fn (StockMovement $movement): array => [
                    'qty_change' => (int) $movement->qty_change,
                    'balance_after' => $movement->balance_after === null ? null : (int) $movement->balance_after,
                ])->all(),
        ];
    }

    /** The variant quantities of a product's variation JSON, by variant type. */
    protected function variantQuantities(int $productId): array
    {
        $variation = json_decode((string) Product::query()->whereKey($productId)->value('variation'), true) ?: [];

        return collect($variation)->mapWithKeys(static fn (array $variant): array => [$variant['type'] => (int) $variant['qty']])->all();
    }

    /** @return list<string> the kinds of toast (success, error...) the last response asked the next page to show */
    protected function toastTypes(): array
    {
        return array_column(session('laravel-toaster-magic::messages', []), 'type');
    }
}
