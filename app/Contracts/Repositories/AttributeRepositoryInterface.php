<?php

namespace App\Contracts\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface AttributeRepositoryInterface extends RepositoryInterface
{
    /**
     * The attributes a named storefront offers, for a panel screen that works on one country's catalogue while belonging to no
     * storefront itself (B-12). The ids in $alwaysOffered are kept whatever the mapping says, so a form never drops what the row it is
     * editing already carries. While the mechanism is dormant this is the plain list, unchanged.
     *
     * @param array $alwaysOffered attribute ids the form must offer even where the mapping does not
     * @param array $orderBy
     * @param int|string $dataLimit If you need all data without pagination, you need to set dataLimit = 'all'
     * @return Collection|LengthAwarePaginator
     */
    public function getListOfferedIn(string $storefrontCountry, array $alwaysOffered = [], array $orderBy = [], int|string $dataLimit = DEFAULT_DATA_LIMIT): Collection|LengthAwarePaginator;
}
