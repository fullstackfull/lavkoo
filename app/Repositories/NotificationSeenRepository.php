<?php

namespace App\Repositories;

use App\Contracts\Repositories\NotificationSeenRepositoryInterface;
use App\Models\NotificationSeen;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationSeenRepository implements NotificationSeenRepositoryInterface
{
    public function __construct(
        private readonly NotificationSeen $notificationSeen
    )
    {
    }

    public function add(array $data): string|object
    {
        return $this->notificationSeen->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->notificationSeen->where($params)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->notificationSeen->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->notificationSeen->with($relations)
            ->when(isset($filters['id']), function ($query) use ($filters) {
                return $query->where('id', $filters['id']);
            })
            ->when(isset($searchValue), function ($query) use ($searchValue) {
                return $query->where('id', 'like', "%{$searchValue}%");
            })
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }

    public function update(string $id, array $data): bool
    {
        return $this->notificationSeen->find($id)->update($data);
    }

    public function delete(array $params): bool
    {
        $this->notificationSeen->where($params)->delete();
        return true;
    }
}
