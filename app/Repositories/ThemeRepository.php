<?php

namespace App\Repositories;

use App\Contracts\Repositories\ThemeRepositoryInterface;
use App\Models\Theme;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class ThemeRepository implements ThemeRepositoryInterface
{
    public function __construct(
        private readonly Theme $theme,
    )
    {
    }

    public function add(array $data): string|object
    {
        return $this->theme->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->theme->where($params)->with($relations)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->theme->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->theme->with($relations)
            ->when(isset($filters['id']), fn ($q) => $q->where(['id' => $filters['id']]))
            ->when(isset($filters['is_active']), fn ($q) => $q->where(['is_active' => $filters['is_active']]))
            ->when(!empty($searchValue), function ($q) use ($searchValue) {
                return $q->where(function ($sub) use ($searchValue) {
                    $sub->where('name', 'like', "%{$searchValue}%")
                        ->orWhere('slug', 'like', "%{$searchValue}%");
                });
            })
            ->when(!empty($orderBy), fn ($q) => $q->orderBy(array_key_first($orderBy), array_values($orderBy)[0]));

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }

    public function update(string $id, array $data): bool
    {
        $theme = $this->theme->find($id);
        return $theme ? $theme->update($data) : false;
    }

    public function delete(array $params): bool
    {
        return $this->theme->where($params)->delete() > 0;
    }

    public function getActiveTheme(): ?Model
    {
        return $this->theme->newQuery()->where('is_active', true)->first();
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        return $this->theme->newQuery()
            ->where('slug', $slug)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }
}
