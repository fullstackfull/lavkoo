<?php

namespace App\Repositories\MarketAccounts;

use App\Contracts\Repositories\MarketAccounts\MarketAccountRepositoryInterface;
use App\Contracts\Repositories\MarketAccounts\MarketAccountRow;
use App\Enums\Wallet\MarketBalanceKind;
use Illuminate\Database\Eloquent\Model;

/**
 * The reads and writes both market account tables share. A subclass says which model it works on and which balance it holds, and the
 * balance says which column is the amount: money for a wallet, points for a loyalty account.
 *
 * It lives in a sub-namespace so the convention-based binder in `App\Providers\InterfaceServiceProvider`, which pairs each file of
 * `app/Repositories` with the interface of the same name, never pairs this abstract class with anything.
 */
abstract class MarketAccountRepository implements MarketAccountRepositoryInterface
{
    abstract protected function account(): Model;

    abstract protected function kind(): MarketBalanceKind;

    public function amountOf(int|string $customerId, string $market): ?float
    {
        $amount = $this->account()->newQuery()
            ->where(['customer_id' => $customerId, 'market' => $market])
            ->value($this->amountColumn());

        return $amount === null ? null : (float) $amount;
    }

    public function locked(int|string $customerId, string $market): ?MarketAccountRow
    {
        $row = $this->account()->newQuery()
            ->where(['customer_id' => $customerId, 'market' => $market])
            ->lockForUpdate()
            ->first();

        return $row === null ? null : new MarketAccountRow(
            id: (int) $row->getKey(),
            market: (string) $row->market,
            currencyCode: (string) $row->currency_code,
            amount: (float) $row->{$this->amountColumn()},
        );
    }

    /**
     * Opens the account with nothing in it. The unique key on (customer, market) is the authority: when the account is already open, or
     * another writer opened it first, the insert is ignored and that row is the one every writer then locks.
     *
     * IT MUST NOT BE CALLED INSIDE THE TRANSACTION THAT LOCKS THE ACCOUNT, and both of InnoDB's ways of refusing say why. A locking read
     * that finds no row holds the GAP of the unique key rather than a record, so two writers opening the same account each need to insert
     * inside the other's gap and one is killed with a deadlock (SQLSTATE 40001) instead of made to wait. Doing the insert first instead
     * trades that for the other half: an insert that is ignored takes a SHARED lock on the row it collided with, and two writers that both
     * hold it and then both ask to lock the row for update deadlock on the upgrade — which is the ordinary case, every credit after the
     * first. Opened on its own, outside, the insert holds nothing by the time the transaction starts: the lock below is then a plain record
     * lock on a row that exists, two writers queue, and the second adds to what the first wrote.
     */
    public function open(int|string $customerId, string $market, string $currencyCode, ?string $legalEntity): void
    {
        $this->account()->newQuery()->insertOrIgnore([
            'customer_id' => $customerId,
            'market' => $market,
            'currency_code' => $currencyCode,
            'legal_entity' => $legalEntity,
            $this->amountColumn() => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function writeAmount(int $accountId, float $amount): void
    {
        $this->account()->newQuery()->whereKey($accountId)->update([
            $this->amountColumn() => $amount,
            'updated_at' => now(),
        ]);
    }

    private function amountColumn(): string
    {
        return $this->kind()->amountColumn();
    }
}
