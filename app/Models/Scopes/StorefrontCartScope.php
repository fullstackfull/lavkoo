<?php

namespace App\Models\Scopes;

use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * A cart only holds, shows and changes the rows of the storefront it is used from (GCC-02/03). Every read and write of a cart row, the
 * checkout cleanup, the select and remove actions and the lookup of the group a new row joins, goes through this one scope, so a
 * customer who shops two countries has two carts and an action in one never touches the other. It does nothing while storefronts are off.
 */
final class StorefrontCartScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        StorefrontCatalog::restrictCartRows(cartRows: $builder);
    }
}
