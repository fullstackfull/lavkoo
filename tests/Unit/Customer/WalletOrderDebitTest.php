<?php

namespace Tests\Unit\Customer;

use App\Services\Customer\WalletOrderDebit;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WalletOrderDebitTest extends TestCase
{
    /** @return array<string, array{orderAmountInUsd: float|string, debitInUsd: float}> */
    public static function orderAmountsRoundedHalfUpToCents(): array
    {
        return [
            'below half a cent' => ['orderAmountInUsd' => 10.004, 'debitInUsd' => 10.0],
            'exactly half a cent' => ['orderAmountInUsd' => 10.005, 'debitInUsd' => 10.01],
            'half a cent given as text' => ['orderAmountInUsd' => '10.015', 'debitInUsd' => 10.02],
            'whole cents' => ['orderAmountInUsd' => 10.01, 'debitInUsd' => 10.01],
        ];
    }

    #[DataProvider('orderAmountsRoundedHalfUpToCents')]
    public function test_the_debit_is_the_order_amount_rounded_half_up_to_whole_cents(float|string $orderAmountInUsd, float $debitInUsd): void
    {
        $this->assertSame($debitInUsd, WalletOrderDebit::forOrderAmount($orderAmountInUsd)->amountInUsd());
    }

    /** @return array<string, array{walletBalanceInUsd: float|string|null, covered: bool}> */
    public static function balancesAgainstTenDollarsOneCent(): array
    {
        return [
            'exactly the debit' => ['walletBalanceInUsd' => '10.01', 'covered' => true],
            'float residue below the debit' => ['walletBalanceInUsd' => '10.00999999999999978684', 'covered' => true],
            'half a cent short rounds up to the debit' => ['walletBalanceInUsd' => 10.005, 'covered' => true],
            'more than half a cent short' => ['walletBalanceInUsd' => '10.004', 'covered' => false],
            'one cent short' => ['walletBalanceInUsd' => '10.00', 'covered' => false],
            'empty wallet' => ['walletBalanceInUsd' => null, 'covered' => false],
        ];
    }

    #[DataProvider('balancesAgainstTenDollarsOneCent')]
    public function test_a_balance_covers_the_debit_when_it_rounds_to_at_least_the_debit_in_cents(float|string|null $walletBalanceInUsd, bool $covered): void
    {
        $this->assertSame($covered, WalletOrderDebit::forOrderAmount(10.01)->isCoveredBy($walletBalanceInUsd));
    }
}
