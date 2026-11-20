<?php

namespace App\Contracts\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * A repository that reads its table and appends to it, and offers no way to change or remove a row that is already there.
 *
 * It is what a financial ledger needs: a wallet or a loyalty movement is a record of something that happened, so it is written once and
 * read for ever after. RepositoryInterface is this interface plus update() and delete(), which is what every other repository wants;
 * a ledger's repository implements this one, so that the means to edit a money row is not sitting there waiting for a caller.
 */
interface AppendOnlyRepositoryInterface
{
    /**
     * @param array $data Data value must be in key and value pair structure, ex: params = ['name'=>'John Doe']
     * @return string|object
     */
    public function add(array $data): string|object;

    /**
     * @param array $params Params value must be in key and value pair structure, ex: params = ['name'=>'John Doe']
     * @param array $relations Relations value must be a relation name in array structure, ex: relations = ['product','category']
     * @return Model|null
     */
    public function getFirstWhere(array $params, array $relations = []): ?Model;

    /**
     * @param array $orderBy
     * @param array $relations
     * @param int|string $dataLimit If you need all data without pagination, you need to set dataLimit = 'all'
     * @param int|null $offset
     * @return Collection|LengthAwarePaginator
     */
    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator;

    /**
     * @param array $orderBy
     * @param string|null $searchValue
     * @param array $filters Filters value must be in key and value pair structure, support one level nested array, ex: Filters = ['category'=>[1,2,5,8], 'email'=>['x@x.com','test@test.com']]
     * @param array $relations
     * @param int|string $dataLimit If you need all data without pagination, you need to set dataLimit = 'all'
     * @param int|null $offset
     * @return Collection|LengthAwarePaginator
     */
    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator;
}
