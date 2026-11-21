<?php

namespace App\Repositories;

use App\Contracts\Repositories\WalletTransactionRepositoryInterface;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class WalletTransactionRepository implements WalletTransactionRepositoryInterface
{
    public function __construct(
        private readonly WalletTransaction $walletTransaction,
    )
    {
    }


    public function add(array $data): string|object
    {
        return $this->walletTransaction->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->walletTransaction->where($params)->with($relations)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->walletTransaction->with($relations)->when(!empty($orderBy), function ($query) use ($orderBy) {
            $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
        });
        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->walletTransaction
            ->when(!empty($searchValue), function ($query) use ($searchValue) {
                $query->where(function ($query) use ($searchValue) {
                    $query->where('transaction_id', 'like', '%' . $searchValue . '%')
                        ->orWhere('reference', 'like', '%' . $searchValue . '%')
                        ->orWhereHas('user', function ($q) use ($searchValue) {
                            $q->where('f_name', 'like', '%' . $searchValue . '%')
                                ->orWhere('l_name', 'like', '%' . $searchValue . '%')
                                ->orWhereRaw("CONCAT(f_name, ' ', l_name) like ?", ['%' . $searchValue . '%'])
                                ->orWhere('email', 'like', '%' . $searchValue . '%');
                        });
                });
            })
            ->when(!empty($filters['from']) && !empty($filters['to']), function ($query) use ($filters) {
                $query->whereBetween('created_at', [$filters['from'] . ' 00:00:00', $filters['to'] . ' 23:59:59']);
            })
            ->when(!empty($filters['from']) && empty($filters['to']), function ($query) use ($filters) {
                $query->where('created_at', '>=', $filters['from'] . ' 00:00:00');
            })
            ->when(empty($filters['from']) && !empty($filters['to']), function ($query) use ($filters) {
                $query->where('created_at', '<=', $filters['to'] . ' 23:59:59');
            })
            ->when(!empty($filters['transaction_type']) && $filters['transaction_type'] != 'all', function ($query) use ($filters) {
                $query->where('transaction_type', $filters['transaction_type']);
            })
            ->when(!empty($filters['customer_id']) && $filters['customer_id'] != 'all', function ($query) use ($filters) {
                $query->where('user_id', $filters['customer_id']);
            })->latest();

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends(['searchValue' => $searchValue]);
    }

    public function getListWhereSelect(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->walletTransaction->selectRaw('sum(credit) as total_credit, sum(debit) as total_debit')
            ->when((!empty($filters['from']) && !empty($filters['to'])), function ($query) use ($filters) {
                $query->whereBetween('created_at', [$filters['from'] . ' 00:00:00', $filters['to'] . ' 23:59:59']);
            })
            ->when(!empty($filters['transaction_type']) && $filters['transaction_type'] != 'all', function ($query) use ($filters) {
                $query->where('transaction_type', $filters['transaction_type']);
            })
            ->when(!empty($filters['customer_id']) && $filters['customer_id'] != 'all', function ($query) use ($filters) {
                $query->where('user_id', $filters['customer_id']);
            })->latest();

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends(['searchValue' => $searchValue]);
    }

    public function update(string $id, array $data): bool
    {
        return $this->walletTransaction->where('id', $id)->update($data);
    }

    public function delete(array $params): bool
    {
        $this->walletTransaction->where($params)->delete();
        return true;
    }

}
