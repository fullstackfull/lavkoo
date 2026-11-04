<?php

namespace App\Services\Marketplace;

use App\Models\Product;
use App\Models\ProductPriceChange;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The one place a price change is written down.
 *
 * Every path that moves a price calls this: the seller form, the admin form, the API, a bulk job, a
 * promotion ending. Putting it in one service is what stops the eighth call site from being the one
 * that forgets — which is exactly how the codebase ended up with seven writers and no history.
 *
 * It records nothing when nothing moved. A save that rewrites the same price is not a price change,
 * and a history full of them is a history nobody can read.
 *
 * Like `AuditLogger`, it never throws into its caller. A price change failing because the note about
 * it could not be saved would be a worse outcome than the missing note.
 */
class PriceChangeRecorder
{
    /**
     * What is moving prices right now.
     *
     * The observer that records a change cannot tell a bulk job from a seller typing into a form —
     * by the time the model fires, both look like a save. So a caller that knows declares it, and
     * everything inside that call is attributed correctly. Anything that does not declare falls back
     * to the guard, which is right for the two form paths and honest for the rest.
     */
    private static ?string $source = null;
    private static ?string $reason = null;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Run a block with every price change inside it attributed to one source.
     *
     * Restores the previous value afterwards rather than clearing it, so nesting — a promotion job
     * that calls a bulk operation — leaves the outer attribution intact.
     */
    public static function attributeTo(string $source, ?string $reason, callable $work): mixed
    {
        $previousSource = self::$source;
        $previousReason = self::$reason;

        self::$source = $source;
        self::$reason = $reason;

        try {
            return $work();
        } finally {
            self::$source = $previousSource;
            self::$reason = $previousReason;
        }
    }

    /** The declared source, or the one the authenticated guard implies. */
    public static function currentSource(): string
    {
        if (self::$source !== null) {
            return self::$source;
        }

        foreach (['admin' => ProductPriceChange::SOURCE_ADMIN_UI, 'seller' => ProductPriceChange::SOURCE_SELLER_UI] as $guard => $source) {
            try {
                if (auth($guard)->check()) {
                    return $source;
                }
            } catch (Throwable) {
                // A guard that cannot be resolved is simply not the answer.
            }
        }

        // Nobody is signed in: a console command, a queued job or the scheduler.
        return ProductPriceChange::SOURCE_AUTOMATION;
    }

    public static function currentReason(): ?string
    {
        return self::$reason;
    }

    /**
     * Record a move, if there was one.
     *
     * @param  array{unit_price?: float|string|null, discount?: float|string|null, discount_type?: string|null}  $before
     * @param  array{unit_price?: float|string|null, discount?: float|string|null, discount_type?: string|null}  $after
     */
    public function record(
        Product|int|string $product,
        array $before,
        array $after,
        string $source = ProductPriceChange::SOURCE_SELLER_UI,
        ?string $reason = null,
    ): ?ProductPriceChange {
        try {
            if (!Schema::hasTable('product_price_changes')) {
                return null;
            }

            $productId = $product instanceof Product ? $product->id : $product;
            $sellerId = $product instanceof Product
                ? (($product->added_by === 'seller') ? $product->user_id : null)
                : null;

            $previousPrice = $this->money($before['unit_price'] ?? null);
            $newPrice = $this->money($after['unit_price'] ?? null);

            if ($newPrice === null) {
                return null;
            }

            $previousDiscount = $this->money($before['discount'] ?? null);
            $newDiscount = $this->money($after['discount'] ?? null);
            $previousType = $before['discount_type'] ?? null;
            $newType = $after['discount_type'] ?? null;

            // Nothing moved. A save that rewrote the same numbers is not a price change, and
            // recording it would bury the ones that are.
            if ($previousPrice === $newPrice && $previousDiscount === $newDiscount && $previousType === $newType) {
                return null;
            }

            $actor = $this->actor();

            $change = ProductPriceChange::create([
                'product_id' => $productId,
                'seller_id' => $sellerId,
                'previous_price' => $previousPrice,
                'new_price' => $newPrice,
                'previous_discount' => $previousDiscount,
                'new_discount' => $newDiscount,
                'previous_discount_type' => $previousType,
                'new_discount_type' => $newType,
                'source' => in_array($source, ProductPriceChange::SOURCES, true) ? $source : ProductPriceChange::SOURCE_SELLER_UI,
                'reason' => $reason,
                'actor_type' => $actor[0],
                'actor_id' => $actor[1],
                'actor_name' => $actor[2],
            ]);

            // Also on the unified trail, so "everything that happened to this product" and
            // "everything this person did" both find it without knowing about this table.
            $this->audit->record(
                action: 'product.price_changed',
                subject: ['type' => 'product', 'id' => $productId],
                before: ['unit_price' => $previousPrice, 'discount' => $previousDiscount, 'discount_type' => $previousType],
                after: ['unit_price' => $newPrice, 'discount' => $newDiscount, 'discount_type' => $newType],
                context: ['source' => $source, 'reason' => $reason],
            );

            return $change;
        } catch (Throwable) {
            // A missing history line is never worth failing the price change it describes.
            return null;
        }
    }

    /**
     * The price fields as they stand, for a caller to capture before it writes.
     *
     * @return array{unit_price: float|null, discount: float|null, discount_type: string|null}
     */
    public function snapshot(Product|array|null $product): array
    {
        return [
            'unit_price' => $this->money($product['unit_price'] ?? null),
            'discount' => $this->money($product['discount'] ?? null),
            'discount_type' => $product['discount_type'] ?? null,
        ];
    }

    private function money(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : round((float) $value, 3);
    }

    /**
     * @return array{0: ?string, 1: ?int, 2: ?string}
     */
    private function actor(): array
    {
        foreach (['admin', 'seller', 'customer'] as $guard) {
            try {
                $user = auth($guard)->user();
            } catch (Throwable) {
                $user = null;
            }

            if ($user) {
                $name = trim(($user->f_name ?? '') . ' ' . ($user->l_name ?? '')) ?: ($user->name ?? null);

                return [$guard, (int) $user->getKey(), $name];
            }
        }

        return ['system', null, null];
    }
}
