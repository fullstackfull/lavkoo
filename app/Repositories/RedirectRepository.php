<?php

namespace App\Repositories;

use App\Contracts\Repositories\RedirectRepositoryInterface;
use App\Models\Redirect;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class RedirectRepository implements RedirectRepositoryInterface
{
    public function __construct(
        private readonly Redirect $redirect,
    )
    {
    }

    public function add(array $data): string|object
    {
        return $this->redirect->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->redirect->where($params)->with($relations)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->redirect->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->redirect->with($relations)
            ->when(isset($filters['id']), function ($query) use ($filters) {
                return $query->where(['id' => $filters['id']]);
            })
            ->when(isset($filters['is_active']), function ($query) use ($filters) {
                return $query->where(['is_active' => $filters['is_active']]);
            })
            ->when(!empty($searchValue), function ($query) use ($searchValue) {
                return $query->where(function ($q) use ($searchValue) {
                    $q->where('from_path', 'like', "%{$searchValue}%")
                        ->orWhere('to_path', 'like', "%{$searchValue}%");
                });
            })
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }

    public function update(string $id, array $data): bool
    {
        $redirect = $this->redirect->find($id);
        return $redirect ? $redirect->update($data) : false;
    }

    public function updateByParams(array $params, array $data): bool
    {
        return $this->redirect->where($params)->update($data) > 0;
    }

    public function delete(array $params): bool
    {
        return $this->redirect->where($params)->delete() > 0;
    }

    public function getActiveRulesArray(): array
    {
        return $this->redirect->newQuery()
            ->where('is_active', true)
            ->get(['from_path', 'to_path', 'status_code', 'match_type', 'is_active'])
            ->toArray();
    }

    public function existsForFromPath(string $fromPath, ?int $exceptId = null): bool
    {
        return $this->redirect->newQuery()
            ->where('from_path', $fromPath)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }
}
