<?php

namespace App\Repositories;

use App\Contracts\Repositories\CartShippingRepositoryInterface;
use App\Models\CartShipping;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class CartShippingRepository implements CartShippingRepositoryInterface
{
    public function __construct(
        private readonly CartShipping    $cartShipping
    )
    {
    }
    public function add(array $data): string|object
    {
        return $this->cartShipping->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->cartShipping->with($relations)->where($params)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        // TODO: Implement getList() method.
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
       return $this->cartShipping->with($relations)
           ->when(isset($searchValue), function ($query) use ($searchValue) {
               return $query->where('cart_group_id', 'like', "%$searchValue%")
                   ->orWhere('shipping_method_id', 'like', "%$searchValue%");
           })
           ->where($filters)->get();
    }

    public function update(string $id, array $data): bool
    {
        return $this->cartShipping->find($id)->update($data);
    }

    public function updateWhere(array $params, array $data): bool
    {
        $this->cartShipping->where($params)->update($data);
        return true;
    }

    public function updateOrInsert(array $params, array $data): bool
    {
        $this->cartShipping->updateOrInsert($params, $data);
        return true;
    }

    public function delete(array $params): bool
    {
        // TODO: Implement delete() method.
    }
}
