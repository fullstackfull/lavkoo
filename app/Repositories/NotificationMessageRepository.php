<?php

namespace App\Repositories;

use App\Contracts\Repositories\NotificationMessageRepositoryInterface;
use App\Models\NotificationMessage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationMessageRepository implements NotificationMessageRepositoryInterface
{
    public function __construct(
        private readonly NotificationMessage $notificationMessage
    )
    {

    }
    public function add(array $data): string|object
    {
        return $this->notificationMessage->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->notificationMessage->with($relations)->where($params)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->notificationMessage->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy),array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        return $this->notificationMessage->with($relations)->where($filters)->when(!empty($orderBy), function ($query) use ($orderBy) {
            $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
        })->get();
    }

    public function update(string $id, array $data): bool
    {
        return $this->notificationMessage->where(['id'=>$id])->update($data);
    }

    public function delete(array $params): bool
    {
        return $this->notificationMessage->where($params)->delete();
    }
}
