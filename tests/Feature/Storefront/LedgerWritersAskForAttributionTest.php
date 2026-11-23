<?php

namespace Tests\Feature\Storefront;

use FilesystemIterator;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * E-05 is only true while every place that writes a wallet or loyalty ledger row asks LedgerAttribution for what stands behind it, and every caller that knows its order,
 * checkout, refund, payment or staff member says so. This reads the source of the application and fails when a ledger writer stops asking, when a new writer appears that
 * does not ask, or when a caller drops (or changes) the origin it passes. A caller that cannot say it is listed with the reason, so that adding one is a decision and not an
 * accident.
 */
class LedgerWritersAskForAttributionTest extends TestCase
{
    /** @var list<string> the names of the six writers of the two ledgers (OrderManager and CustomerTrait share one name): the generic `add()` of a repository is not a writer of a ledger, nothing calls it for one */
    private const WRITER_FUNCTIONS = ['create_wallet_transaction', 'createWalletTransaction', 'addWalletTransaction', 'create_loyalty_point_transaction', 'addLoyaltyPointTransaction'];

    /**
     * The writer whose method names are too common to match on their own: `record(` and `write(` would also catch
     * VendorLedger::record, which is the seller ledger and nothing to do with this one. These are matched only when the call is
     * qualified with the class, which is how every caller writes it.
     *
     * @var array<string, list<string>>
     */
    private const QUALIFIED_WRITER_FUNCTIONS = ['CustomerWalletWriter' => ['record', 'write']];

    /** @var array<string, int> the files that hold a writer, with the number of places in each that ask LedgerAttribution for the columns of the row */
    private const WRITERS = [
        'app/Utils/CustomerManager.php' => 2,
        'app/Utils/OrderManager.php' => 1,
        'app/Traits/CustomerTrait.php' => 1,
        // CustomerWalletWriter arrived with the one-locked-writer work and three callers go through it, so it writes a wallet row
        // like the rest and takes an origin like the rest. This census is the third to have been missing one of the writers --
        // Tests\Unit\Wallet\OneWalletWriterTest was missing OrderManager and WalletBalanceWritersTest was missing this one.
        'app/Services/Customer/CustomerWalletWriter.php' => 1,
        'app/Repositories/WalletTransactionRepository.php' => 1,
        'app/Repositories/LoyaltyPointTransactionRepository.php' => 1,
    ];

    /**
     * The origin each caller passes, as the names of the LedgerOrigin factories it uses, per file. A file with a caller that passes none is in CALLERS_WITHOUT_AN_ORIGIN.
     *
     * @var array<string, list<string>>
     */
    private const ORIGINS = [
        // The wallet checkout debit lives inside OrderManager::generateOrder's transaction, so an order refused for stock takes nothing from the wallet: the `checkout`
        // origin is passed there, and RestAPI/v1/OrderController and Web/WebController call no ledger writer at all. Moving that debit back out changes this list.

        'app/Utils/module-helper.php' => ['payment'],
        'app/Utils/OrderManager.php' => ['checkout', 'order'],
        'app/Traits/OrderEditManager.php' => ['order'],
        'app/Services/RefundStatusService.php' => ['refund'],
        'app/Http/Controllers/Admin/Customer/CustomerWalletController.php' => ['by'],
        'app/Http/Controllers/Admin/Order/OrderController.php' => ['order', 'order', 'order'],
        'app/Http/Controllers/Admin/Order/RefundController.php' => ['refund'],
        'app/Http/Controllers/Admin/POS/POSOrderController.php' => ['by'],
        'app/Http/Controllers/Vendor/Order/OrderController.php' => ['order', 'order', 'order', 'order'],
        'app/Http/Controllers/Vendor/RefundController.php' => ['refund'],
        'app/Http/Controllers/Vendor/POS/POSOrderController.php' => ['by'],
        'app/Http/Controllers/RestAPI/v2/delivery_man/DeliveryManController.php' => ['order'],
        'app/Http/Controllers/RestAPI/v2/seller/OrderController.php' => ['order'],
        'app/Http/Controllers/RestAPI/v3/seller/OrderController.php' => ['order', 'order', 'order', 'order'],
    ];

    /**
     * The callers that pass no origin, with why. A conversion of points writes two rows that already point at each other (the loyalty row's reference is the wallet row's
     * id) and has no order, refund or payment behind it; the seller app's point of sale takes the money before its order exists and has no staff guard to read the seller from.
     *
     * The conversion is split across two files: PointsToWalletConversion debits the points, inside the transaction that credits the wallet, and each loyalty controller
     * passes the wallet credit into it as a closure. That is why there is one call in each of the three rather than two in each of the controllers.
     *
     * @var array<string, int>
     */
    private const CALLERS_WITHOUT_AN_ORIGIN = [
        'app/Http/Controllers/RestAPI/v1/UserLoyaltyController.php' => 1,
        'app/Http/Controllers/Web/UserLoyaltyController.php' => 1,
        'app/Http/Controllers/RestAPI/v3/seller/POSController.php' => 1,
        'app/Services/Loyalty/PointsToWalletConversion.php' => 1,
    ];

    /** @return array<string, string> the source of every PHP file of the application, by its path from the root */
    private function sources(): array
    {
        $sources = [];

        foreach (['app', 'Modules'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    $sources[str_replace(base_path() . '/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $sources;
    }

    /** @return list<array{file: string, writer: string, arguments: string}> every call of a writer, with the text of its arguments */
    private function callsOfTheWriters(): array
    {
        $calls = [];

        foreach ($this->sources() as $file => $source) {
            if (!preg_match('/' . implode('|', [...self::WRITER_FUNCTIONS, ...array_keys(self::QUALIFIED_WRITER_FUNCTIONS)]) . '/', $source)) {
                continue;
            }

            $tokens = array_values(array_filter(PhpToken::tokenize($source), static fn (PhpToken $token): bool => !$token->isIgnorable()));

            foreach ($tokens as $index => $token) {
                $followedByCall = ($tokens[$index + 1]->text ?? '') === '(' && !($tokens[$index - 1] ?? null)?->is(T_FUNCTION);
                $isCall = $token->is(T_STRING) && $followedByCall
                    && (in_array($token->text, self::WRITER_FUNCTIONS, true) || $this->isQualifiedWriterCall(tokens: $tokens, index: $index));

                if ($isCall) {
                    $calls[] = ['file' => $file, 'writer' => $token->text, 'arguments' => $this->argumentsAfter(tokens: $tokens, open: $index + 1)];
                }
            }
        }

        return $calls;
    }

    /**
     * Whether the name at this index is one of the qualified writer's methods, called on that class: `Class :: method (`.
     *
     * @param list<PhpToken> $tokens
     */
    private function isQualifiedWriterCall(array $tokens, int $index): bool
    {
        if (($tokens[$index - 1]->text ?? '') !== '::') {
            return false;
        }

        $class = $tokens[$index - 2]->text ?? '';

        return in_array($tokens[$index]->text, self::QUALIFIED_WRITER_FUNCTIONS[$class] ?? [], true);
    }

    /** @param list<PhpToken> $tokens */
    private function argumentsAfter(array $tokens, int $open): string
    {
        $depth = 0;
        $text = '';

        for ($index = $open; $index < count($tokens); $index++) {
            $depth += $tokens[$index]->text === '(' ? 1 : ($tokens[$index]->text === ')' ? -1 : 0);
            $text .= $tokens[$index]->text . ' ';

            if ($depth === 0) {
                return $text;
            }
        }

        return $text;
    }

    public function test_every_writer_asks_for_the_columns_of_the_row_it_saves(): void
    {
        $sources = $this->sources();

        foreach (self::WRITERS as $file => $places) {
            $this->assertSame($places, substr_count($sources[$file], 'app(LedgerAttribution::class)->of('), "{$file} has to ask LedgerAttribution once for each row it writes");
        }
    }

    public function test_no_other_code_writes_a_row_of_either_ledger(): void
    {
        $writes = '/new\s+(WalletTransaction|LoyaltyPointTransaction)\b|\b(WalletTransaction|LoyaltyPointTransaction)::(create|insert|forceCreate|firstOrCreate|updateOrCreate)\b|table\(\s*[\'"](wallet_transactions|loyalty_point_transactions)[\'"]\s*\)\s*->\s*(insert|insertGetId|upsert)/';
        $strangers = [];

        foreach ($this->sources() as $file => $source) {
            if (preg_match($writes, $source) === 1 && !array_key_exists($file, self::WRITERS)) {
                $strangers[] = $file;
            }
        }

        $this->assertSame([], $strangers, 'a new writer of a ledger has to take an origin and ask LedgerAttribution for the columns of its row');
    }

    public function test_every_caller_that_knows_what_stands_behind_its_movement_says_so_and_with_the_right_kind_of_origin(): void
    {
        $origins = [];
        $withoutOrigin = [];

        foreach ($this->callsOfTheWriters() as $call) {
            if (preg_match_all('/LedgerOrigin\s*::\s*(order|checkout|refund|payment|by)\s*\(/', $call['arguments'], $found) === 0) {
                $withoutOrigin[$call['file']] = ($withoutOrigin[$call['file']] ?? 0) + 1;

                continue;
            }

            $origins[$call['file']] = [...($origins[$call['file']] ?? []), ...$found[1]];
        }

        foreach ($origins as &$kinds) {
            sort($kinds);
        }
        unset($kinds);
        ksort($origins);
        ksort($withoutOrigin);
        $expected = self::ORIGINS;
        foreach ($expected as &$kinds) {
            sort($kinds);
        }
        unset($kinds);
        ksort($expected);
        $expectedWithout = self::CALLERS_WITHOUT_AN_ORIGIN;
        ksort($expectedWithout);

        $this->assertSame($expected, $origins, 'the origin each caller passes: a caller that stopped passing one, or passes another kind, changes this');
        $this->assertSame($expectedWithout, $withoutOrigin, 'a caller with no origin has to be listed here with the reason, and one that now passes an origin has to be taken off the list');
    }
}
