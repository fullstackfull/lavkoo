<?php

namespace App\Repositories;

use App\Contracts\Repositories\RefundRequestRepositoryInterface;
use App\Models\RefundRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class RefundRequestRepository implements RefundRequestRepositoryInterface
{
    public function __construct(
        private readonly RefundRequest $refundRequest,
    )
    {
    }

    public function add(array $data): string|object
    {
        return $this->refundRequest->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->refundRequest->where($params)->with($relations)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->refundRequest->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->refundRequest
            ->with($relations)
            ->when($searchValue, function ($query) use ($searchValue) {
                $query->where('id', 'like', "%{$searchValue}%");
            })
            ->when(isset($filters['id']), function ($query) use ($filters) {
                return $query->where(['id' => $filters['id']]);
            })
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(key($orderBy), current($orderBy));
            });

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }

    public function getListWhereHas(array $orderBy = [], ?string $searchValue = null, array $filters = [], ?string $whereHas = null, array $whereHasFilters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->refundRequest->with($relations)->whereHas($whereHas, function ($query) use ($whereHasFilters) {
            return $query->when(isset($whereHasFilters['seller_is']) && $whereHasFilters['seller_is'] == 'admin', function ($query) use ($whereHasFilters) {
                return $query->where(['seller_is' => $whereHasFilters['seller_is']]);
            })->when(isset($whereHasFilters['seller_is']) && $whereHasFilters['seller_is'] == 'seller', function ($query) use ($whereHasFilters) {
                return $query->where(['seller_is' => $whereHasFilters['seller_is']])
                    ->when(isset($whereHasFilters['seller_id']), function ($query) use ($whereHasFilters) {
                        return $query->where(['seller_id' => $whereHasFilters['seller_id']]);
                    });
            });
        })->when(isset($searchValue), function ($query) use ($searchValue, $relations) {
            $key = explode(' ', $searchValue);
            $query->where(function ($subQuery) use ($key, $relations) {
                foreach ($key as $value) {
                    $subQuery->orWhere('order_id', 'like', "%{$value}%")
                        ->orWhere('id', 'like', "%{$value}%")
                        ->orWhereHas('customer', function ($q) use ($value) {
                            $q->where('f_name', 'like', "%{$value}%")
                                ->orWhere('l_name', 'like', "%{$value}%")
                                ->orWhereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%{$value}%"])
                                ->orWhere('phone', 'like', "%{$value}%");
                        });
                }
            });
        })->when(isset($filters['status']), function ($query) use ($filters) {
            $query->where(['status' => $filters['status']]);
        })->when(isset($filters['from_date']) && isset($filters['to_date']), function ($query) use ($filters) {
            $query->whereBetween('created_at', [$filters['from_date'] . ' 00:00:00', $filters['to_date']   . ' 23:59:59']);
        })->when(isset($filters['from_date']) && !isset($filters['to_date']), function ($query) use ($filters) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        })->when(!isset($filters['from_date']) && isset($filters['to_date']), function ($query) use ($filters) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        })
        ->orderBy(key($orderBy), current($orderBy));

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }

    public function getFirstWhereHas(array $params, ?string $whereHas = null, array $whereHasFilters = [], array $relations = []): ?Model
    {
        return $this->refundRequest->with($relations)->whereHas($whereHas, function ($query) use ($whereHasFilters) {
            $query->where($whereHasFilters);
        })->where($params)->first();
    }

    public function update(string $id, array $data): bool
    {
        return $this->refundRequest->find($id)->update($data);
    }

    public function delete(array $params): bool
    {
        $this->refundRequest->where($params)->delete();
        return true;
    }
}
