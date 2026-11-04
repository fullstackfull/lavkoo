<?php

namespace App\Contracts\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface SellerRepositoryInterface extends RepositoryInterface
{
    /**
     * @param array $orderBy
     * @param string|null $searchValue
     * @param string|null $scope
     * @param array $filters
     * @param array $whereIn
     * @param array $whereNotIn
     * @param array $relations
     * @param array $withCount
     * @param int|string $dataLimit
     * @param int|null $offset
     * @return Collection|LengthAwarePaginator
     */
    public function getListWithScope(array $orderBy = [], ?string $searchValue = null, ?string $scope = null, array $filters = [], array $whereIn = [], array $whereNotIn = [], array $relations = [], array $withCount = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator;
}
