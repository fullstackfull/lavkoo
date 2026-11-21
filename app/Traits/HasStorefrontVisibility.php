<?php

namespace App\Traits;

use App\Services\Storefront\StorefrontVisibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * Narrows every read of this model to what the request's storefront shows (B-11, B-13, B-15, B-16).
 *
 * It is a global scope rather than a change in each reader so that the category tree, the home page, the filters, the category and brand
 * pages, the APIs and the app lists are all narrowed by the one rule, and a reader added tomorrow is narrowed too. The admin and vendor
 * panels, the seller APIs, queues and console commands belong to no storefront, so the scope is silent there and the central rows stay
 * reachable exactly as they are today.
 *
 * The model names the entity type the mapping is keyed by; {@see StorefrontVisibility} decides what a mapping row means for it.
 */
trait HasStorefrontVisibility
{
    public static function bootHasStorefrontVisibility(): void
    {
        $entityType = static::STOREFRONT_ENTITY_TYPE;

        static::addGlobalScope('storefront_visibility', static function (Builder $builder) use ($entityType): void {
            StorefrontVisibility::restrict(query: $builder, entityType: $entityType);
        });
    }

    /** Whether the storefront this request is served from shows this row. */
    public function isShownByItsStorefront(?string $country = null): bool
    {
        return StorefrontVisibility::isVisible(
            entityType: static::STOREFRONT_ENTITY_TYPE,
            entityId: $this->getKey(),
            country: $country,
        );
    }
}
