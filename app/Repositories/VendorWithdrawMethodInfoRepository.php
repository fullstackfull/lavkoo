<?php

namespace App\Repositories;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Contracts\Repositories\VendorWithdrawMethodInfoRepositoryInterface;
use App\Models\VendorWithdrawMethodInfo;

class VendorWithdrawMethodInfoRepository implements VendorWithdrawMethodInfoRepositoryInterface
{
    public function __construct(
        private readonly VendorWithdrawMethodInfo $vendorWithDrawMethodInfo,
    )
    {
    }

    public function add(array $data): string|object
    {
        return $this->vendorWithDrawMethodInfo->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->vendorWithDrawMethodInfo->with($relations)->where($params)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->vendorWithDrawMethodInfo->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->vendorWithDrawMethodInfo
            ->with($relations)
            ->when($searchValue, function ($query) use ($searchValue) {
                $query->where('method_name', 'like', "%{$searchValue}%");
            })
            ->when(isset($filters['id']), function ($query) use ($filters) {
                return $query->where(['id' => $filters['id']]);
            })
            ->when(isset($filters['method_name']), function ($query) use ($filters) {
                return $query->where(['method_name' => $filters['method_name']]);
            })
            ->when(isset($filters['user_id']), function ($query) use ($filters) {
                return $query->where(['user_id' => $filters['user_id']]);
            })
            ->when(isset($filters['is_active']), function ($query) use ($filters) {
                return $query->where(['is_active' => $filters['is_active']]);
            })
            ->when(isset($filters['is_default']), function ($query) use ($filters) {
                return $query->where(['is_default' => $filters['is_default']]);
            })
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(key($orderBy), current($orderBy));
            });

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }

    public function getListWhereIn(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $whereInFilters = [], array $relations = [], array $nullFields = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->vendorWithDrawMethodInfo
            ->with($relations)->where($filters)
            ->when($searchValue, function ($query) use ($searchValue) {
                return $query->where('title', 'like', "%$searchValue%");
            })
            ->when(isset($filters['id']), function ($query) use ($filters) {
                return $query->where(['id' => $filters['id']]);
            })
            ->when(isset($filters['user_type']), function ($query) use ($filters) {
                return $query->where(['user_type' => $filters['user_type']]);
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
        $this->vendorWithDrawMethodInfo->where('id', $id)->update($data);
        return true;
    }

    public function updateWhere(array $params, array $data): bool
    {
        $this->vendorWithDrawMethodInfo->where($params)->update($data);
        return true;
    }

    public function updateOrInsert(array $params, array $data): bool
    {
        $this->vendorWithDrawMethodInfo->updateOrInsert($params, $data);
        return true;
    }

    public function delete(array $params): bool
    {
        $this->vendorWithDrawMethodInfo->where($params)->delete();
        return true;
    }
}
