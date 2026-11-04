<?php

namespace App\Repositories;


use App\Contracts\Repositories\OrderDetailsRewardsRepositoryInterface;
use App\Models\OrderDetailsRewards;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;


class OrderDetailsRewardsRepository implements OrderDetailsRewardsRepositoryInterface
{
    public function __construct(
        private readonly OrderDetailsRewards $orderRewardDetails
    )
    {

    }

    public function add(array $data): string|object
    {
        return $this->orderRewardDetails->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->orderRewardDetails->where($params)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->orderRewardDetails->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->orderRewardDetails
            ->with($relations)
            ->where($filters)
            ->when(isset($filters['id']), function ($query) use ($filters) {
                return $query->where('id', $filters['id']);
            })
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }

    public function update(string $id, array $data): bool
    {
        return $this->orderRewardDetails->find($id)->update($data);
    }

    public function delete(array $params): bool
    {
        $this->orderRewardDetails->where($params)->delete();
        return true;
    }
}
