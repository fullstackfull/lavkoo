<?php

namespace App\Repositories;

use App\Contracts\Repositories\ShopRepositoryInterface;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class ShopRepository implements ShopRepositoryInterface
{

    public function __construct(
        private readonly Shop $shop,
    )
    {
    }

    public function add(array $data): string|object
    {
        return $this->shop->newInstance()->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->shop->with($relations)->where($params)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->shop->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->shop->with($relations)
        ->when($searchValue, function ($query) use($searchValue){
            $query->Where('name', 'like', "%$searchValue%");
        })
        ->when(isset($filters['author_type']), function ($query) use ($filters) {
            return $query->where('author_type' , $filters['author_type']);
        })
        ->when(isset($filters['productIds']), function ($query) use ($filters) {
            return $query->whereIn('id' , $filters['vendorIds']);
        })->when(!empty($orderBy), function ($query) use ($orderBy) {
            $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
        });

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }

    public function update(string $id, array $data): bool
    {
        return $this->shop->find($id)->update($data);
    }

    public function updateWhere(array $params, array $data): bool
    {
        $this->shop->where($params)->update($data);
        return true;
    }

    public function delete(array $params): bool
    {
        $this->shop->where($params)->delete();
        return true;
    }

    public function getListWithScope(array $orderBy = [], ?string $searchValue = null, ?string $scope = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->shop->with($relations)
            ->when(isset($scope) && $scope == 'active', function ($query){
                $query->active();
            })
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }
}
