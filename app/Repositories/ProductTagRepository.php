<?php

namespace App\Repositories;

use App\Contracts\Repositories\ProductTagRepositoryInterface;
use App\Models\ProductTag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductTagRepository implements ProductTagRepositoryInterface
{
    public function __construct(
        private readonly ProductTag $productTag,
    )
    {
    }

    public function add(array $data): string|object
    {
        return $this->productTag->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->productTag->with($relations)->where($params)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->productTag->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy),array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy=[], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->productTag
            ->when($searchValue, function ($query) use($searchValue){
                $query->Where('id', 'like', "%$searchValue%");
            })
            ->when(isset($filters['id']), function ($query) use($filters) {
                return $query->where(['id' => $filters['id']]);
            })
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(array_key_first($orderBy),array_values($orderBy)[0]);
            });

        $filters += ['searchValue' =>$searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }

    public function update(string $id, array $data): bool
    {
        return $this->productTag->find($id)->update($data);
    }

    public function delete(array $params): bool
    {
        $this->productTag->where($params)->delete();
        return true;
    }

    public function getIds(string $fieldName = 'tag_id', array $filters = []): \Illuminate\Support\Collection|array
    {
        return $this->productTag->when(isset($filters['product_id']), function ($query) use ($filters) {
            return $query->where('product_id', $filters['product_id']);
        })->pluck($fieldName);
    }
}
