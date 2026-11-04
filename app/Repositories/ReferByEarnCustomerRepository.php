<?php

namespace App\Repositories;


use App\Models\ReferralCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Contracts\Repositories\ReferByEarnCustomerRepositoryInterface;

class ReferByEarnCustomerRepository implements ReferByEarnCustomerRepositoryInterface
{
    public function __construct(
        private readonly ReferralCustomer $referalCustomer,
    ) {}

    public function add(array $data): string|object
    {
        return $this->referalCustomer->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->referalCustomer->with($relations)->where($params)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->referalCustomer->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->referalCustomer
            ->with($relations)
            ->when($searchValue, function ($query) use ($searchValue) {
                $query->where('customer_discount_amount_type', 'like', "%{$searchValue}%");
            })
            ->when(isset($filters['id']), function ($query) use ($filters) {
                return $query->where(['id' => $filters['id']]);
            })
            ->when(isset($filters['user_id']), function ($query) use ($filters) {
                return $query->where(['user_id' => $filters['user_id']]);
            })
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(key($orderBy), current($orderBy));
            });

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }

    public function getListWhereIn(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $whereInFilters = [], array $relations = [], array $nullFields = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->referalCustomer
            ->with($relations)->where($filters)
            ->when($searchValue, function ($query) use ($searchValue) {
                return $query->where('customer_discount_amount_type', 'like', "%$searchValue%");
            })
            ->when(isset($filters['id']), function ($query) use ($filters) {
                return $query->where(['id' => $filters['id']]);
            })
            ->when(isset($filters['user_id']), function ($query) use ($filters) {
                return $query->where(['user_id' => $filters['user_id']]);
            })
            ->when(!empty($whereInFilters), function ($query) use ($whereInFilters) {
                foreach ($whereInFilters as $key => $filterIndex) {
                    $query->whereIn($key, $filterIndex);
                }
            })
            ->when(!empty($nullFields), function ($query) use ($nullFields) {
                return $query->whereNull($nullFields);
            })
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }

    public function update(string $id, array $data): bool
    {
        $this->referalCustomer->where('id', $id)->update($data);
        return true;
    }

    public function updateWhere(array $params, array $data): bool
    {
        $this->referalCustomer->where($params)->update($data);
        return true;
    }

    public function updateOrInsert(array $params, array $data): bool
    {
        $this->referalCustomer->updateOrInsert($params, $data);
        return true;
    }

    public function delete(array $params): bool
    {
        $this->referalCustomer->where($params)->delete();
        return true;
    }
}
