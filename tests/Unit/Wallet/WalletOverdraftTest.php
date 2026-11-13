<?php

namespace Tests\Unit\Wallet;

use App\Services\Wallet\WalletOverdraft;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WalletOverdraftTest extends TestCase
{
    #[DataProvider('debits')]
    public function test_a_debit_is_refused_only_when_it_would_take_the_balance_below_zero(float $balance, float $debit, bool $refused): void
    {
        $this->assertSame($refused, WalletOverdraft::refuses($balance, $debit));
    }

    public static function debits(): array
    {
        return [
            'within the balance' => [100.0, 60.0, false],
            'exactly the balance' => [40.0, 40.0, false],
            'a fraction above the balance' => [40.0, 40.001, true],
            'above the balance' => [40.0, 60.0, true],
            'empty wallet, any debit' => [0.0, 0.01, true],
            'already negative, any debit' => [-5.0, 1.0, true],
            'zero debit never refused' => [0.0, 0.0, false],
            'negative debit is a credit, never refused' => [0.0, -10.0, false],
            'float noise at the boundary' => [0.3, 0.1 + 0.2, false],
            'noise below the tolerance' => [0.0, 0.0000005, false],
            'above the tolerance' => [0.0, 0.000002, true],
        ];
    }
}
