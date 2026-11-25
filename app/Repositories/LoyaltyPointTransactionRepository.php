<?php

namespace App\Repositories;

use App\Services\Loyalty\LoyaltyEarning;
use App\Services\Loyalty\WhenARefundWouldTakePointsBelowZero;
use App\Contracts\Repositories\LoyaltyPointTransactionRepositoryInterface;
use App\Models\BusinessSetting;
use App\Models\LoyaltyPointTransaction;
use App\Models\User;
use App\Services\Storefront\LedgerAttribution;
use App\Services\Storefront\LedgerOrigin;
use App\Services\Customer\CustomerBalanceLock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
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

    public function addLoyaltyPointTransaction(string|int $userId, string $reference, string|int|float $amount, string $transactionType, ?LedgerOrigin $origin = null): bool
    {
        // Read for the MARKET THE ORDER WAS SOLD IN, not off the business_settings row (E-02, J-V2). This writer is reached from the admin
        // and the vendor order pages, where the request serves no storefront, so it read the platform's rate for every order of every
        // market -- and the sibling writer in CustomerManager read the rate of the request's market, which for those pages is the same
        // platform rate. Neither asked the order. A market whose programme is switched off earned points anyway.
        $earning = app(LoyaltyEarning::class);

        if (!$earning->programmeIsOn(origin: $origin)) {
            return true;
        }

        $credit = 0;
        $debit = 0;
        if ($transactionType == 'order_place') {
            $credit = $earning->pointsForAnOrder(amount: $amount, origin: $origin);
        } else if ($transactionType == 'point_to_wallet' || $transactionType == 'refund_order') {
            $debit = $amount;
        }

        if ($credit == 0 && $debit == 0) {
            return false;
        }

        return CustomerBalanceLock::apply($userId, function (User $user) use ($reference, $transactionType, $credit, $debit, $origin) {
            // A refund takes back more points than the customer still holds and the balance goes NEGATIVE: the conversion
            // debit below has always been checked against the balance and the refund debit never was (E-14). What should
            // happen instead -- refuse, clamp, or allow and report -- is the owner's, so the rule is a setting that ships
            // as today's behaviour and this call returns $debit unchanged while it does.
            if ($transactionType == 'refund_order') {
                $allowed = WhenARefundWouldTakePointsBelowZero::debitFor(held: (float) $user['loyalty_point'], debit: (float) $debit);
                if ($allowed === null) {
                    return false;
                }
                $debit = $allowed;
            }

            $currentBalance = $user['loyalty_point'] + $credit - $debit;
            if ($transactionType == 'point_to_wallet' && $currentBalance < 0) {
                return false;
            }

            $user->loyalty_point = $currentBalance;
            $user->save();
            $this->loyaltyPointTransaction->create([
                'user_id' => $user['id'],
                'transaction_id' => Str::uuid(),
                'transaction_type' => $transactionType,
                'reference' => $reference,
                'balance' => $currentBalance,
                'credit' => $credit,
                'debit' => $debit,
                'created_at' => now(),
                'updated_at' => now(),
                // What stands behind the row (E-05); dormant until its migration runs, and written inside the balance
                // lock after the point guard above, so it records the write rather than deciding it.
            ] + app(LedgerAttribution::class)->of(table: $this->loyaltyPointTransaction->getTable(), origin: $origin));

            return true;
        });
    }
}
