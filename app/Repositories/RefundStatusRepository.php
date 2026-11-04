<?php

namespace App\Repositories;

use App\Contracts\Repositories\RefundStatusRepositoryInterface;
use App\Models\RefundStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class RefundStatusRepository implements RefundStatusRepositoryInterface
{
    public function __construct(
        private readonly RefundStatus $refundStatus,
    )
    {
    }

    public function add(array $data): string|object
    {
        return $this->refundStatus->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->refundStatus->with($relations)->where($params)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->refundStatus->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy),array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy=[], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->refundStatus
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
        return $this->refundStatus->find($id)->update($data);
    }

    public function delete(array $params): bool
    {
        $this->refundStatus->where($params)->delete();
        return true;
    }
}
