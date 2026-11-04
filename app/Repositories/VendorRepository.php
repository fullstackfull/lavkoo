<?php

namespace App\Repositories;

use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Models\Seller;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;
class VendorRepository implements VendorRepositoryInterface
{
    public function __construct(
        private readonly Seller $vendor,
    )
    {
    }

    public function getByStatusExcept(string $status, array $relations = [], int $paginateBy = DEFAULT_DATA_LIMIT): Collection|array|LengthAwarePaginator
    {
        return $this->vendor->with($relations)->whereNotIn('status', [$status])->paginate($paginateBy);
    }


    public function add(array $data): string|object
    {
        return $this->vendor->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->vendor->with($relations)
            ->when(isset($params['identity']), function ($query) use ($params) {
                return $query->where(['email' => $params['identity']])
                    ->orWhere(['phone' => $params['identity']]);
            })
            ->when(isset($params['id']), function ($query) use ($params) {
                return $query->where(['id' => $params['id']]);
            })
            ->when(isset($params['withCount']), function ($query) use ($params) {
                return $query->withCount($params['withCount']);
            })
            ->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->vendor->with($relations)->when(!empty($orderBy), function ($query) use ($orderBy) {
            $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
        });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->vendor->with($relations)
            ->where($filters)
            ->when($searchValue, function ($query) use ($searchValue) {
                $searchTerms = explode(' ', $searchValue);
                return $query->where(function ($query) use ($searchTerms) {
                    foreach ($searchTerms as $term) {
                        $query->orWhere('f_name', 'like', "%$term%")
                            ->orWhere('l_name', 'like', "%$term%")
                            ->orWhere('phone', 'like', "%$term%")
                            ->orWhere('email', 'like', "%$term%")
                            ->orWhereHas('shop', function ($query) use ($term) {
                                return $query->where('name', 'like', "%$term%");
                            });
                    }
                });
            })
            ->when(!empty($relations) && in_array('product', $relations), function ($query) {
                return $query->withCount('product')
                ->withCount(['product as wishlist_count' => function ($query) {
                    return $query->whereHas('wishList');
                }]);
            })
            ->when(!empty($orderBy) && array_key_exists('orders_count', $orderBy), function ($query) use ($orderBy) {
                return $query->with(['wallet'])->withSum('wallet as total_earning_sum', 'total_earning')
                    ->orderBy('total_earning_sum', 'desc');
            })
            ->when(!empty($relations) && in_array('orders', $relations), function ($query) {
                return $query->withCount('orders');
            })
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        $filters += ['searchValue' => $searchValue];
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends($filters);
    }
    public function getCountForDashboard(?Carbon $startDate, ?Carbon $endDate): int
    {
        return $this->vendor->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate]);
            })->count();
    }
    public function update(string $id, array $data): bool
    {
        return $this->vendor->find($id)->update($data);
    }

    public function delete(array $params): bool
    {
        $this->vendor->where($params)->delete();
        return true;
    }

    public function getTopVendorListByWishlist(array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->vendor
            ->with($relations)
            ->whereHas('product', function ($query) {
                return $query->where('added_by', 'seller')->whereHas('wishList');
            })
            ->withCount(['product as wishlist_count' => function ($query) {
                return $query->whereHas('wishList');
            }])
            ->orderByDesc('wishlist_count');

        return $dataLimit === 'all'
            ? $query->get()
            : $query->paginate($dataLimit)->appends($filters);
    }
}
