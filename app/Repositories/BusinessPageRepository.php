<?php

namespace App\Repositories;

use App\Contracts\Repositories\BusinessPageRepositoryInterface;
use App\Models\BusinessPage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class BusinessPageRepository implements BusinessPageRepositoryInterface
{
    public function __construct(
        private readonly BusinessPage $businessPage,
    )
    {
    }

    public function add(array $data): string|object
    {
        return $this->businessPage->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->businessPage->with($relations)->where($params)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->businessPage->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->businessPage->when($searchValue, function ($query) use ($searchValue) {
                $query->Where('title', 'like', "%$searchValue%");
            })
            ->when(isset($filters['slug']), function ($query) use ($filters) {
                return $query->where(['slug' => $filters['slug']]);
            })
            ->when(isset($filters['status']), function ($query) use ($filters) {
                return $query->where(['status' => $filters['status']]);
            })
            ->when(isset($filters['default_status']), function ($query) use ($filters) {
                return $query->where(['default_status' => $filters['default_status']]);
            })
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }

    public function getListWhereIn(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $whereIn = [], array $relations = [], array $nullFields = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->businessPage->when($searchValue, function ($query) use ($searchValue) {
                $query->Where('title', 'like', "%$searchValue%");
            })
            ->when(isset($filters['slug']), function ($query) use ($filters) {
                return $query->where(['slug' => $filters['slug']]);
            })
            ->when(isset($filters['status']), function ($query) use ($filters) {
                return $query->where(['status' => $filters['status']]);
            })
            ->when(isset($filters['default_status']), function ($query) use ($filters) {
                return $query->where(['default_status' => $filters['default_status']]);
            })
            ->when(!empty($whereIn), function ($query) use ($whereIn) {
                foreach ($whereIn as $key => $filterIndex) {
                    $query->whereIn($key, $filterIndex);
                }
            })
            ->when(!empty($nullFields), function ($query) use ($nullFields) {
                return $query->whereNull($nullFields);
            })
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }

    public function update(string $id, array $data): bool
    {
        cacheRemoveByType(type: 'business_pages');
        return $this->businessPage->find($id)->update($data);
    }

    public function updateWhere(array $params, array $data): bool
    {
        cacheRemoveByType(type: 'business_pages');
        $this->businessPage->where($params)->update($data);
        return true;
    }

    public function delete(array $params): bool
    {
        cacheRemoveByType(type: 'business_pages');
        $this->businessPage->where($params)->delete();
        return true;
    }

}
