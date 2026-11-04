<?php

namespace App\Repositories;

use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Models\Attachment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class AttachmentRepository implements AttachmentRepositoryInterface
{
    public function __construct(
        private readonly Attachment $attachment,
    )
    {
    }

    public function add(array $data): string|object
    {
        return $this->attachment->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->attachment->with($relations)->where($params)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->attachment->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->attachment
            ->when(isset($filters['attachable_type']), function ($query) use ($filters) {
                return $query->where(['attachable_type' => $filters['attachable_type']]);
            })
            ->when(isset($filters['attachable_id']), function ($query) use ($filters) {
                return $query->where(['attachable_id' => $filters['attachable_id']]);
            })
            ->when(isset($filters['file_type']), function ($query) use ($filters) {
                return $query->where(['file_type' => $filters['file_type']]);
            })
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }

    public function getListWhereIn(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $whereIn = [], array $relations = [], array $nullFields = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->attachment
            ->when(isset($filters['attachable_type']), function ($query) use ($filters) {
                return $query->where(['attachable_type' => $filters['attachable_type']]);
            })
            ->when(isset($filters['attachable_id']), function ($query) use ($filters) {
                return $query->where(['attachable_id' => $filters['attachable_id']]);
            })
            ->when(isset($filters['file_type']), function ($query) use ($filters) {
                return $query->where(['file_type' => $filters['file_type']]);
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
        return $this->attachment->find($id)->update($data);
    }

    public function updateWhere(array $params, array $data): bool
    {
        $this->attachment->where($params)->update($data);
        return true;
    }

    public function updateOrInsert(array $params, array $data): bool
    {
        $this->attachment->updateOrInsert($params, $data);
        return true;
    }

    public function delete(array $params): bool
    {
        $this->attachment->where($params)->delete();
        return true;
    }

}
