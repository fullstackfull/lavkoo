<?php

namespace App\Repositories;

use App\Contracts\Repositories\DeliveryManWalletRepositoryInterface;
use App\Models\DeliverymanWallet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class DeliveryManWalletRepository implements DeliveryManWalletRepositoryInterface
{

    public function __construct(
        private readonly DeliverymanWallet $deliveryManWallet,
    )
    {
    }

    public function add(array $data): string|object
    {
        return $this->deliveryManWallet->newInstance()->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->deliveryManWallet->where($params)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->deliveryManWallet->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy = [], string|null $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->deliveryManWallet
            ->with($relations)
            ->when($searchValue, function ($query) use($searchValue){
                $query->orWhere('id', 'like', "%$searchValue%");
            })
            ->when(isset($filters['id']), function ($query) use ($filters){
                return $query->where(['id'=>$filters['id']]);
            })
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(array_key_first($orderBy),array_values($orderBy)[0]);
            });

        $filters += ['searchValue' =>$searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }

    public function update(string $id, array $data): bool
    {
        return $this->deliveryManWallet->where('id', $id)->update($data);
    }

    public function delete(array $params): bool
    {
        $this->deliveryManWallet->where($params)->delete();
        return true;
    }

    public function updateWhere(array $params, array $data): bool
    {
        return $this->deliveryManWallet->where($params)->update($data);
    }
}
