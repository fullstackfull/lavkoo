<?php

namespace App\Contracts\Repositories;

use App\Models\WalletTransaction;
use App\Services\Storefront\LedgerOrigin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface WalletTransactionRepositoryInterface extends RepositoryInterface
{
    /**
     * @param array $orderBy
     * @param string|null $searchValue
     * @param array $filters
     * @param array $relations
     * @param int|string $dataLimit
     * @param int|null $offset
     * @return Collection|LengthAwarePaginator
     */
    public function getListWhereSelect(array $orderBy = [], ?string $searchValue = null, array $filters = [], array $relations = [], int|string $dataLimit = DEFAULT_DATA_LIMIT, ?int $offset = null): Collection|LengthAwarePaginator;

    /**
     * @param string $user_id
     * @param float $amount
     * @param string $transactionType
     * @param string $reference
     * @param array $payment_data
     * @param LedgerOrigin|null $origin
     * @return bool|WalletTransaction
     */
    public function addWalletTransaction(string $user_id, float $amount, string $transactionType, string $reference, array $payment_data=[], ?LedgerOrigin $origin = null): bool|WalletTransaction;

    /**
     * @param float $amount
     * @return string|float
     */
    public function addFundToWalletBonus(float $amount): string|float;
}
