<?php

namespace App\Contracts\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

interface CustomerRepositoryInterface extends RepositoryInterface
{

    /**
     * @param array $ids
     * @param array $relations
     * @param int|string $dataLimit
     * @param int|null $offset
     * @return Collection|LengthAwarePaginator
     */
    public function getListWhereNotIn(array $ids = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator;

    /**
     * @param object $request
     * @param array|object|null $skipIds
     * @param int|string $dataLimit
     * @return object
     */
    public function getCustomerNameList(object $request, array|object|null $skipIds = [], int|string $dataLimit = DEFAULT_DATA_LIMIT): object;

    public function updateWhere(array $params, array $data): bool;

    /**
     * @param string|int $id
     * @return bool
     */
    public function deleteAuthAccessTokens(string|int $id): bool;

    /**
     * @param array $params
     * @param array $data
     * @return mixed
     */
    public function updateOrCreate(array $params, array $data): mixed;

    public function getByIdentity(array $filters = []): ?Model;
}
