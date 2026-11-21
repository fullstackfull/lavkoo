<?php

namespace App\Repositories;

use App\Contracts\Repositories\AttributeRepositoryInterface;
use App\Models\Attribute;
use App\Models\Translation;
use App\Services\Storefront\StorefrontVisibility;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class AttributeRepository implements AttributeRepositoryInterface
{
    public function __construct(
        private readonly Attribute $attribute,
        private readonly Translation      $translation,
    )
    {
    }


    public function add(array $data): string|object
    {
        return $this->attribute->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->attribute->withoutGlobalScope('translate')->where($params)->with($relations)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->attribute->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    /**
     * The named country's attributes, whatever storefront the request itself belongs to: the panel screens this serves belong to none,
     * and a caller that named a country has already said which one it means, so the request's own scope is dropped rather than ANDed on.
     */
    public function getListOfferedIn(string $storefrontCountry, array $alwaysOffered = [], array $orderBy = [], int|string $dataLimit = DEFAULT_DATA_LIMIT): Collection|LengthAwarePaginator
    {
        $query = StorefrontVisibility::offeredIn(
            query: $this->attribute->newQuery()->withoutGlobalScope('storefront_visibility'),
            entityType: StorefrontVisibility::ATTRIBUTE,
            country: $storefrontCountry,
            alwaysOffered: $alwaysOffered,
        )->when(!empty($orderBy), function ($query) use ($orderBy) {
            $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
        });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(
        array      $orderBy = [],
        string     $searchValue = null,
        array      $filters = [], array $relations = [],
        int|string $dataLimit = DEFAULT_DATA_LIMIT,
        int        $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->attribute->when($searchValue, function ($query) use ($searchValue) {
            $attributeIds = $this->translation
                ->where('translationable_type', 'App\Models\Attribute')
                ->where('key', 'name')
                ->where('value', 'like', "%{$searchValue}%")
                ->pluck('translationable_id')
                ->toArray();
            $query->where(function ($q) use ($searchValue, $attributeIds) {
                $q->where('name', 'like', "%$searchValue%")
                    ->orWhere('id', $searchValue)
                    ->orWhereIn('id', $attributeIds);
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
        return $this->attribute->where('id', $id)->update($data);
    }

    public function delete(array $params): bool
    {
        $this->attribute->where($params)->delete();
        return true;
    }
}
