<?php

namespace App\Repositories;

use App\Contracts\Repositories\DeliveryManRepositoryInterface;
use App\Models\DeliveryMan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class DeliveryManRepository implements DeliveryManRepositoryInterface
{
    public function __construct(
        private readonly DeliveryMan $deliveryMan,
    )
    {
    }

    public function add(array $data): string|object
    {
        return $this->deliveryMan->newInstance()->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->deliveryMan->with($relations)->where($params)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->deliveryMan->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy),array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->deliveryMan->with($relations)
            ->withCount('orders')
            ->when($searchValue, function ($query) use ($searchValue) {
                $searchTerms = explode(' ', $searchValue);
                $query->where(function ($query) use ($searchTerms) {
                    foreach ($searchTerms as $term) {
                        $query->orWhere('f_name', 'like', "%$term%")
                            ->orWhere('l_name', 'like', "%$term%")
                            ->orWhereRaw('CONCAT(f_name, " ", l_name) like ?', ["%$term%"])
                            ->orWhere('email', 'like', "%$term%")
                            ->orWhere('full_phone', 'like', "%$term%");
                    }
                });
            })
            ->when(isset($filters['seller_id']), function ($query) use ($filters) {
                $query->where('seller_id', $filters['seller_id']);
            })
            ->when(in_array('review', $relations), function ($query) use ($filters, $relations) {
                return $query->withCount('review')
                    ->withAvg('review', 'rating');
            })
            ->when(isset($filters['sort_by']) && $filters['sort_by'] == 'rating', function ($query) use ($filters) {
                return $query->withCount('review')
                    ->withAvg('review', 'rating')->orderBy('review_avg_rating', 'desc');
            })
            ->when(isset($filters['sort_by']) && $filters['sort_by'] == 'latest', function ($query) use ($filters) {
                return $query->orderBy('id', 'desc');
            })
            ->when(isset($filters['sort_by']) && $filters['sort_by'] == 'oldest', function ($query) use ($filters) {
                return $query->orderBy('id', 'asc');
            })
            ->when(in_array('deliveredOrders', $relations), function ($query) {
                return $query->whereHas('deliveredOrders',);
            })
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(key($orderBy), current($orderBy));
            });
        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);

    }

    public function getTopRatedList(array $orderBy = [], array $filters = [], array $whereHasFilters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        return $this->deliveryMan->with($relations)
            ->when(isset($filters['seller_id']), function ($query) use ($filters) {
                return $query->where('seller_id', $filters['seller_id']);
            })
            ->when(in_array('review', $relations), function ($query) use ($filters, $relations) {
                return $query->withCount('review')
                    ->withAvg('review', 'rating');
            })
            ->when(isset($filters['sort_by']) && $filters['sort_by'] == 'rating', function ($query) use ($filters) {
                return $query->withCount('review')
                    ->withAvg('review', 'rating')->orderBy('review_avg_rating', 'desc');
            })
            ->when(current($relations) == 'deliveredOrders', function ($query) use ($whereHasFilters) {
                return $query->whereHas('deliveredOrders', function ($query) use ($whereHasFilters) {
                    return $query->when($whereHasFilters, function ($query) use ($whereHasFilters) {
                        return $query->where($whereHasFilters)->whereNotNull('delivery_man_id');
                    });
                });
            })
            ->withCount(current($relations))
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(key($orderBy), current($orderBy));
            })
            ->get();
    }

    public function update(string $id, array $data): bool
    {
        return $this->deliveryMan->find($id)->update($data);
    }

    public function delete(array $params): bool
    {
        $this->deliveryMan->where($params)->delete();
        return true;
    }


    public function getListWhereIn(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], array $nullFields = [], array $withCounts = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->deliveryMan
            ->when(!empty($searchValue), function ($query) use ($searchValue) {
                $query->where('f_name', 'like', "%$searchValue%")
                    ->orWhere('l_name', 'like', "%$searchValue%")
                    ->orWhere('phone', 'like', "%$searchValue%");
            })
            ->when(!empty($filters) && isset($filters['order_seller']) && isset($filters['shipping_method']), function ($query) use ($filters) {
                $query->when($filters['order_seller'] == 'seller' && $filters['shipping_method'] == 'sellerwise_shipping', function ($query) use ($filters) {
                    $query->where(['seller_id' => $filters['seller_id']]);
                })->when($filters['order_seller'] == 'seller' && $filters['shipping_method'] == 'inhouse_shipping', function ($query) use ($filters) {
                    $query->where(['seller_id' => 0]);
                });
            })
            ->when(isset($filters['is_active']), function ($query) use ($filters) {
                $query->where(['is_active' => $filters['is_active']]);
            })
            ->when(isset($withCounts), function ($query) use ($withCounts) {
                $query->withCount($withCounts);
            })
            ->when(!empty($nullFields), function ($query) use ($nullFields) {
                $query->whereNull($nullFields);
            })
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }
}
