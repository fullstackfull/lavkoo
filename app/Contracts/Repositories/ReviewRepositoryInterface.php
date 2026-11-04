<?php

namespace App\Contracts\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface ReviewRepositoryInterface extends RepositoryInterface
{
    /**
     * @param bool $globalScope
     * @param array $orderBy
     * @param string|null $searchValue
     * @param array $filters
     * @param array $whereInFilters
     * @param array $relations
     * @param array $nullFields
     * @param int|string $dataLimit
     * @param int|null $offset
     * @return Collection|LengthAwarePaginator
     */
    public function getListWhereIn(bool $globalScope = true, array $orderBy = [], ?string $searchValue = null, array $filters = [], array $whereInFilters = [], array $relations = [], array $nullFields = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator;


    /**
     * @param string $whereHas
     * @param array $whereHasFilter
     * @param array $orderBy
     * @param string|null $searchValue
     * @param array $filters
     * @param array $whereInFilters
     * @param array $relations
     * @param array $nullFields
     * @param bool $globalScope
     * @param int|string $dataLimit
     * @param int|null $offset
     * @return Collection|LengthAwarePaginator
     */
    public function getListWhereHas(string $whereHas, array $whereHasFilter, array $orderBy = [], ?string $searchValue = null, array $filters = [], array $whereInFilters = [],  array $relations = [], array $nullFields = [], bool $globalScope = true, int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator;


    /**
     * @param array $params
     * @param array $data
     * @return bool
     */
    public function updateWhere(array $params, array $data): bool;


    /**
     * @param array $params
     * @param array $data
     * @return bool
     */
    public function updateOrInsert(array $params, array $data): bool;

    /**
     * @param array $params
     * @param array $whereInFilters
     * @return int|null
     */
    public function getCount(array $params, array $whereInFilters): int|null;

}
