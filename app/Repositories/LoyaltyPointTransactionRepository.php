<?php

namespace App\Repositories;

use App\Contracts\Repositories\LoyaltyPointTransactionRepositoryInterface;
use App\Models\BusinessSetting;
use App\Models\LoyaltyPointTransaction;
use App\Models\User;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoyaltyPointTransactionRepository implements LoyaltyPointTransactionRepositoryInterface
{
    public function __construct(
        private readonly LoyaltyPointTransaction $loyaltyPointTransaction,
        private readonly BusinessSetting         $businessSetting,
        private readonly User                    $user,
    )
    {
    }


    public function add(array $data): string|object
    {
        return $this->loyaltyPointTransaction->create($data);
    }

    public function getFirstWhere(array $params, array $relations = []): ?Model
    {
        return $this->loyaltyPointTransaction->with($relations)->where($params)->first();
    }

    public function getList(array $orderBy = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->loyaltyPointTransaction->with($relations)
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                return $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit);
    }

    public function getListWhere(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->loyaltyPointTransaction
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
            ->when((isset($filters['from']) && isset($filters['to'])), function ($query) use ($filters) {
                $query->whereBetween('created_at', [$filters['from'] . ' 00:00:00', $filters['to'] . ' 23:59:59']);
            })
            ->when((isset($filters['from']) && empty($filters['to'])), function ($query) use ($filters) {
                $query->where('created_at', '>=', $filters['from'] . ' 00:00:00');
            })
            ->when((empty($filters['from']) && !empty($filters['to'])), function ($query) use ($filters) {
                $query->where('created_at', '<=', $filters['to'] . ' 23:59:59');
            })
            ->when(isset($filters['transaction_id']), function ($query) use ($filters) {
                $query->where('transaction_id', $filters['transaction_id']);
            })
            ->when(isset($filters['transaction_type']) && $filters['transaction_type'] != 'all', function ($query) use ($filters) {
                $query->where('transaction_type', $filters['transaction_type']);
            })
            ->when(isset($filters['customer_id']) && $filters['customer_id'] != 'all', function ($query) use ($filters) {
                $query->where('user_id', $filters['customer_id']);
            })
            ->when(!empty($orderBy), function ($query) use ($orderBy) {
                $query->orderBy(array_key_first($orderBy), array_values($orderBy)[0]);
            });

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends(['searchValue' => $searchValue]);
    }

    public function getListWhereSelect(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator
    {
        $query = $this->loyaltyPointTransaction->selectRaw('sum(credit) as total_credit, sum(debit) as total_debit')
            ->when(($filters['from'] && $filters['to']), function ($query) use ($filters) {
                $query->whereBetween('created_at', [$filters['from'] . ' 00:00:00', $filters['to'] . ' 23:59:59']);
            })
            ->when($filters['transaction_type'], function ($query) use ($filters) {
                $query->where('transaction_type', $filters['transaction_type']);
            })
            ->when(isset($filters['customer_id']) && $filters['customer_id'] != 'all', function ($query) use ($filters) {
                $query->where('user_id', $filters['customer_id']);
            })->latest();

        return $dataLimit == 'all' ? $query->get() : $query->paginate($dataLimit)->appends(['searchValue' => $searchValue]);
    }

    public function update(string $id, array $data): bool
    {
        return $this->loyaltyPointTransaction->find($id)->update($data);
    }

    public function delete(array $params): bool
    {
        $this->loyaltyPointTransaction->where($params)->delete();
        return true;
    }

    public function addLoyaltyPointTransaction(string|int $userId, string $reference, string|int|float $amount, string $transactionType): bool
    {
        $settings = array_column($this->businessSetting->whereIn('type', ['loyalty_point_status', 'loyalty_point_exchange_rate', 'loyalty_point_item_purchase_point'])->get()->toArray(), 'value', 'type');
        if ($settings['loyalty_point_status'] != 1) {
            return true;
        }
        $credit = 0;
        $debit = 0;
        $user = $this->user->find($userId);
        if ($transactionType == 'order_place') {
            $credit = (int)($amount * $settings['loyalty_point_item_purchase_point'] / 100);
        } else if ($transactionType == 'point_to_wallet') {
            $debit = $amount;
        } else if ($transactionType == 'refund_order') {
            $debit = $amount;
        }

        if ($credit == 0 && $debit == 0) {
            return false;
        }
        $currentBalance = $user['loyalty_point'] + $credit - $debit;

        $loyaltyPointTransaction = [
            'user_id' => $user['id'],
            'transaction_id' => Str::uuid(),
            'transaction_type' => $transactionType,
            'reference' => $reference,
            'balance' => $currentBalance,
            'credit' => $credit,
            'debit' => $debit,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            DB::beginTransaction();
            $user->loyalty_point = $currentBalance;
            $user->save();
            $this->loyaltyPointTransaction->create($loyaltyPointTransaction);
            DB::commit();
            return true;
        } catch (Exception $ex) {
            info($ex);
            DB::rollback();
        }
        return false;
    }
}
