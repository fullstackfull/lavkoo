<?php

namespace App\Repositories;

use App\Contracts\Repositories\DeliveryManTransactionRepositoryInterface;
use App\Models\DeliveryManTransaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class DeliveryManTransactionRepository implements DeliveryManTransactionRepositoryInterface
{
    public function __construct(
        private readonly DeliveryManTransaction $deliveryManTransaction
    )
    {
    }
    public function add(array $data): string|object
    {
        return $this->deliveryManTransaction->newInstance()->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
       return $this->deliveryManTransaction->where($params)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->deliveryManTransaction->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy),array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->deliveryManTransaction->with($relations)
            ->when($searchValue, function ($query)use($searchValue){
                $query->orWhere('f_name', 'like', "%$searchValue%")
                    ->orWhere('l_name', 'like', "%$searchValue%")
                    ->orWhere('phone', 'like', "%$searchValue%");
            })
            ->when(isset($filters['delivery_man_id']), function($query) use($filters){
                return $query->where(['delivery_man_id' => $filters['delivery_man_id']]);
            })
            ->when(isset($filters['transaction_type']), function($query) use($filters){
                return $query->where(['transaction_type' => $filters['transaction_type']]);
            })
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }

    public function update(string $id, array $data): bool
    {
        return $this->deliveryManTransaction->find($id)->update($data);
    }

    public function delete(array $params): bool
    {
        $this->deliveryManTransaction->where($params)->delete();
        return true;
    }
}
