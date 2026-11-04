<?php

namespace App\Repositories;

use App\Contracts\Repositories\OrderEditHistoryRepositoryInterface;
use App\Models\OrderEditHistory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class OrderEditHistoryRepository implements OrderEditHistoryRepositoryInterface
{

    public function __construct(private readonly OrderEditHistory $orderEditHistory){}

    public function add(array $data): OrderEditHistory
    {
        return $this->orderEditHistory->create($data);
    }


    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->orderEditHistory->where($params)->with($relations)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        // TODO: Implement getList() method.
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->orderEditHistory->with($relations)
            ->when(isset($filters['id']), function ($query) use ($filters) {
                $query->where('id', $filters['id']);
            })
            ->when(isset($filters['u_id']), function ($query) use ($filters) {
                $query->where('u_id', $filters['u_id']);
            })
            ->when(isset($filters['order_id']), function ($query) use ($filters) {
                $query->where('order_id', $filters['order_id']);
            })
            ->when(isset($filters['edit_by']), function ($query) use ($filters) {
                $query->where('edit_by', $filters['edit_by']);
            })
            ->when(isset($filters['edited_user_id']), function ($query) use ($filters) {
                $query->where('edited_user_id', $filters['edited_user_id']);
            })
            ->when(isset($filters['order_due_payment_status']), function ($query) use ($filters) {
                $query->where('order_due_payment_status', $filters['order_due_payment_status']);
            })
            ->when(isset($filters['order_return_payment_status']), function ($query) use ($filters) {
                $query->where('order_return_payment_status', $filters['order_return_payment_status']);
            })
            ->when(isset($filters['order_due_payment_method']), function ($query) use ($filters) {
                $query->where('order_due_payment_method', $filters['order_due_payment_method']);
            })
            ->when(isset($filters['order_return_payment_method']), function ($query) use ($filters) {
                $query->where('order_return_payment_method', $filters['order_return_payment_method']);
            });

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }

    public function update(string $id, array $data): bool
    {
        // TODO: Implement update() method.
    }

    public function updateWhere(array $params, array $data): bool
    {
        $this->orderEditHistory->where($params)->update($data);
        return true;
    }

    public function delete(array $params): bool
    {
        // TODO: Implement delete() method.
    }
}
