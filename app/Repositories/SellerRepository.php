<?php

namespace App\Repositories;

use App\Contracts\Repositories\SellerRepositoryInterface;
use App\Models\Seller;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class SellerRepository implements SellerRepositoryInterface
{

    public function __construct(private readonly Seller $seller)
    {
    }
    public function add(array $data): string|object
    {
        return $this->seller->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->seller->where($params)->with($relations)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->seller->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy),array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->seller
            ->when($searchValue, function ($query) use ($searchValue) {
                return $query->where('id', 'like', "%$searchValue%");
            })
            ->when(isset($filters['id']), function ($query) use ($filters) {
                return $query->where(['id' => $filters['id']]);
            })
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }

    public function update(string $id, array $data): bool
    {
        return $this->seller->find($id)->update($data);
    }

    public function delete(array $params): bool
    {
        $this->seller->where($params)->delete();
        return true;
    }

    public function getListWithScope(array $orderBy = [], ?string $searchValue = null, ?string $scope = null, array $filters = [], array $whereIn = [], array $whereNotIn = [], array $relations = [], array $withCount = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->seller->with($relations)
            ->when(isset($withCount['product']), function ($query) use ($withCount) {
                return $query->withCount($withCount['product']);
            })
            ->when(isset($relations['shop']), function ($query) use($relations){
                return $query->with($relations['shop']);
            })->when(isset($relations['product.reviews']), function ($query) use($relations){
                return $query->with($relations['product.reviews']);
            })
            ->when(isset($withCount['product']), function ($query) use ($withCount) {
                return $query->withCount([$withCount['product'] => function ($query) {
                    return $query->active();
                }]);
            })
            ->when(isset($scope) && $scope == 'active', function ($query) {
                return $query->approved();
            })->when(isset($filters['brand_id']), function ($query) use ($filters) {
                return $query->where(['brand_id' => $filters['brand_id']]);
            })->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }
}
