<?php

namespace App\Repositories;

use App\Contracts\Repositories\RecentSearchRepositoryInterface;
use App\Models\RecentSearch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class RecentSearchRepository implements RecentSearchRepositoryInterface
{
    public function __construct(
        private readonly RecentSearch $recentSearch,
    )
    {
    }

    public function add(array $data): string|object
    {
        return $this->recentSearch->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->recentSearch->with($relations)->where($params)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->recentSearch->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->recentSearch
            ->with($relations)
            ->when($searchValue, function ($query) use ($searchValue) {
                $query->where('title', 'like', "%{$searchValue}%");
            })
            ->when(isset($filters['id']), function ($query) use ($filters) {
                return $query->where(['id' => $filters['id']]);
            })
            ->when(isset($filters['user_type']), function ($query) use ($filters) {
                return $query->where(['user_type' => $filters['user_type']]);
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
        $query = $this->recentSearch
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
        $this->recentSearch->where('id', $id)->update($data);
        return true;
    }

    public function updateWhere(array $params, array $data): bool
    {
        $this->recentSearch->where($params)->update($data);
        return true;
    }

    public function updateOrInsert(array $params, array $data): bool
    {
        $this->recentSearch->updateOrInsert($params, $data);
        return true;
    }

    public function delete(array $params): bool
    {
        $this->recentSearch->where($params)->delete();
        return true;
    }
}
