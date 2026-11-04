<?php

namespace App\Contracts\Repositories;

interface OrderDetailRepositoryInterface extends RepositoryInterface
{
    /**
     * @param array $params
     * @param array $data
     * @return bool
     */
    public function updateWhere(array $params, array $data): bool;

    /**
     * @param string|null $searchValue
     * @param array $filters
     * @param array $relations
     * @return int
     */
    public function getListWhereCount(?string $searchValue = null, array $filters = [], array $relations = []): int;
}
