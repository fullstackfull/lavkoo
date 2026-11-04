<?php

namespace App\Repositories;

use App\Contracts\Repositories\OrderExpectedDeliveryHistoryRepositoryInterface;
use App\Models\OrderStatusHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class OrderExpectedDeliveryHistoryRepository implements OrderExpectedDeliveryHistoryRepositoryInterface
{
    public function __construct(private readonly OrderStatusHistory $orderStatusHistory)
    {
    }

    public function add(array $data): string|object
    {
        return $this->orderStatusHistory->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->orderStatusHistory->where($params)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->orderStatusHistory->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        return $this->orderStatusHistory->where($filters)->latest()->get();
    }

    public function update(string $id, array $data): bool
    {
        return $this->orderStatusHistory->find($id)->update($data);
    }

    public function delete(array $params): bool
    {
        $this->orderStatusHistory->where($params)->delete();
        return true;
    }
}
