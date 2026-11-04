<?php

namespace App\Repositories;

use App\Contracts\Repositories\TagRepositoryInterface;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class TagRepository implements TagRepositoryInterface
{

    public function __construct(private readonly Tag $tag)
    {
    }
    public function add(array $data): string|object
    {
        return $this->tag->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->tag->withoutGlobalScope('translate')->with($relations)->where($params)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->tag->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy),array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy=[], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->tag
            ->when($searchValue, function ($query) use($searchValue){
                $query->where('id', 'like', "%$searchValue%");
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
        return $this->tag->find($id)->update($data);
    }

    public function delete(array $params): bool
    {
        $this->tag->where($params)->delete();
        return true;
    }

    public function incrementVisitCount(array $whereIn = []): bool
    {
        $this->tag->when(isset($whereIn), function ($query) use ($whereIn) {
            foreach ($whereIn as $key => $whereInIndex) {
                return $query->whereIn($key, $whereInIndex);
            }
        })->increment('visit_count');

        return true;
    }
}
