<?php

namespace App\Repositories;

use App\Contracts\Repositories\CurrencyRepositoryInterface;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class CurrencyRepository implements CurrencyRepositoryInterface
{
    public function __construct(
        private readonly Currency $currency,
    )
    {
    }

    public function add(array $data): string|object
    {
        $currency = $this->currency->create($data);
        Cache::forget('active_currency_list');
        return $currency;
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->currency->where($params)->with($relations)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->currency
            ->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(
        array       $orderBy = [],
        string|null $searchValue = null,
        array       $filters = [], array $relations = [],
        int|string  $dataLimit = DEFAULT_DATA_LIMIT,
        int|null    $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->currency
            ->when($searchValue, function ($query) use ($searchValue) {
                return $query->where('name', 'like', "%$searchValue%");
            })
            ->when($filters && $filters['status'], function ($query) use ($filters) {
                return $query->where(['status' => $filters['status']]);
            })
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }

    public function update(string $id, array $data): bool
    {
        $updated = $this->currency->where('id', $id)->update($data);
        Cache::forget('active_currency_list');
        return $updated;
    }

    public function updateWhere(array $params, array $data): bool
    {
        $this->currency->where($params)->update($data);
        Cache::forget('active_currency_list');
        return true;
    }

    public function delete(array $params): bool
    {
        $this->currency->where($params)->delete();
        Cache::forget('active_currency_list');
        return true;
    }
}
