<?php

namespace App\Repositories\MarketAccounts;

use App\Contracts\Repositories\MarketAccounts\MarketAccountRepositoryInterface;
use App\Contracts\Repositories\MarketAccounts\MarketAccountRow;
use App\Enums\Wallet\MarketBalanceKind;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

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

    public function lockedOrOpened(int|string $customerId, string $market, string $currencyCode, ?string $legalEntity): MarketAccountRow
    {
        $locked = $this->locked(customerId: $customerId, market: $market);

        if ($locked !== null) {
            return $locked;
        }

        $this->open(customerId: $customerId, market: $market, currencyCode: $currencyCode, legalEntity: $legalEntity);

        $opened = $this->locked(customerId: $customerId, market: $market);

        if ($opened === null) {
            throw new RuntimeException("The market account of customer #{$customerId} in {$market} was opened but cannot be read back.");
        }

        return $opened;
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

    /**
     * Opens the account with nothing in it. The unique key on (customer, market) is the authority: when another writer opened the same
     * account first, the insert is ignored and that row is the one this writer then locks.
     */
    private function open(int|string $customerId, string $market, string $currencyCode, ?string $legalEntity): void
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
}
