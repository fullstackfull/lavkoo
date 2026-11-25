<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\GccStagingSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use LeakGuard\Scanner;
use Tests\TestCase;

require_once dirname(__DIR__, 3) . '/scripts/ci/leak-guard.php';

/**
 * The six storefronts of invented data, and the two promises that make the seeder safe to keep: every market gets its OWN seller,
 * customer, products, order and wallets, and no value it writes is real personal data or looks like a credential.
 *
 * The second promise is not asserted by eye. Every string this seeder writes is read by the repository's OWN scanner
 * (scripts/ci/leak-guard.php, the one CI runs on every pull request), under a path that is not a fixtures path, so the e-mail and the
 * Saudi-mobile rules apply at full strength. If somebody later types a plausible telephone number or a shared staging password into the
 * seeder, this test goes red for the same reason CI would.
 *
 * The schema is built here rather than migrated: of the 300-odd migrations most are ALTER-only and assume the proprietary installation
 * dump, which is not in the repository (see App\Console\Commands\BootstrapTestSchema). The GCC storefront columns DO have real
 * migrations, so those are run rather than hand-written -- the same approach as tests/Support/BuildsLegalEntityWorld.
 */
final class GccStagingSeederTest extends TestCase
{
    private const MARKETS = ['SA', 'AE', 'KW', 'QA', 'BH', 'OM'];

    private const CURRENCY_OF = ['SA' => 'SAR', 'AE' => 'AED', 'KW' => 'KWD', 'QA' => 'QAR', 'BH' => 'BHD', 'OM' => 'OMR'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        $this->theRiyalTheInstallationAlreadyHad();
    }

    public function test_it_creates_six_storefronts_each_with_its_own_seller_customer_products_order_and_wallets(): void
    {
        $this->seed(GccStagingSeeder::class);

        $this->assertSame(self::MARKETS, $this->countriesOf('products'), 'every configured market has a catalogue of its own');

        foreach (self::MARKETS as $market) {
            $sellerId = $this->sellerOf($market);
            $customerId = $this->customerOf($market);

            $this->assertNotNull($sellerId, "{$market} has a seller");
            $this->assertNotNull($customerId, "{$market} has a customer");
            $this->assertSame(1, DB::table('shops')->where('seller_id', $sellerId)->count(), "{$market} has a shop");
            $this->assertSame(2, DB::table('products')->where('storefront_country', $market)->count(), "{$market} has its own products");
            $this->assertSame(1, DB::table('orders')->where('storefront_country', $market)->count(), "{$market} has an order of its own");
            $this->assertSame(1, DB::table('wallet_transactions')->where('user_id', $customerId)->count(), "{$market}'s customer has a wallet movement");
            $this->assertSame(1, DB::table('seller_wallets')->where('seller_id', $sellerId)->count(), "{$market}'s seller has a wallet");
            $this->assertGreaterThan(0, (float) DB::table('users')->where('id', $customerId)->value('wallet_balance'), "{$market}'s customer holds a balance");
        }
    }

    public function test_no_market_shares_a_seller_a_customer_or_a_wallet_with_another(): void
    {
        $this->seed(GccStagingSeeder::class);

        $sellers = array_map(fn (string $market): ?int => $this->sellerOf($market), self::MARKETS);
        $customers = array_map(fn (string $market): ?int => $this->customerOf($market), self::MARKETS);

        $this->assertCount(6, array_unique($sellers), 'six sellers, not one shared by six markets');
        $this->assertCount(6, array_unique($customers), 'six customers, not one identity across the markets');
        $this->assertSame(6, DB::table('seller_wallets')->distinct()->count('seller_id'), 'a wallet per seller');
        $this->assertSame(6, DB::table('wallet_transactions')->distinct()->count('user_id'), 'a wallet movement per customer');
    }

    /** Local pricing, not auto-FX: a market's order is priced in that market's own money and never in the default storefront's. */
    public function test_each_order_is_in_its_own_markets_currency(): void
    {
        $this->seed(GccStagingSeeder::class);

        foreach (self::MARKETS as $market) {
            $this->assertSame(
                self::CURRENCY_OF[$market],
                DB::table('orders')->where('storefront_country', $market)->value('currency_code'),
                "the {$market} order is in its own currency"
            );
        }

        $this->assertSame(['AED', 'BHD', 'KWD', 'OMR', 'QAR', 'SAR'], $this->currencyCodes(), 'each market can trade in its own money');
    }

    /**
     * The criterion's own words: no real personal data. Every string the seeder wrote is put to the repository's scanner under a path
     * that gets every rule -- not tests/, which the scanner excuses by policy -- so an e-mail address that could reach a mailbox, a
     * Saudi mobile number, a private key, a token, a JWT, credentials in a URL or a credential literal all fail here.
     */
    public function test_no_row_it_writes_carries_a_real_looking_secret_or_a_real_persons_details(): void
    {
        $this->seed(GccStagingSeeder::class);

        $scanner = new Scanner(base_path());
        $findings = [];

        foreach (['sellers', 'shops', 'users', 'products', 'orders', 'order_details', 'wallet_transactions', 'seller_wallets', 'currencies'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                foreach ((array) $row as $column => $value) {
                    if (!is_string($value) || $value === '') {
                        continue;
                    }
                    foreach ($scanner->rulesBrokenBy('database/seeders/GccStagingSeeder.php', "'{$column}' => '{$value}',") as $rule) {
                        $findings[] = "{$table}.{$column} breaks {$rule}";
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($findings)));
    }

    /** The same rules, over the seeder's own two source files: an invented number typed into the source is a finding in the history for good. */
    public function test_the_seeders_own_source_holds_no_secret_and_no_personal_data(): void
    {
        $scanner = new Scanner(base_path());
        $findings = [];

        foreach (['GccStagingSeeder.php', 'InventedMarketIdentity.php'] as $file) {
            $path = 'database/seeders/' . $file;
            foreach (file(base_path($path), FILE_IGNORE_NEW_LINES) as $number => $line) {
                foreach ($scanner->rulesBrokenBy($path, $line) as $rule) {
                    $findings[] = $path . ':' . ($number + 1) . ' ' . $rule;
                }
            }
        }

        $this->assertSame([], $findings);
    }

    /**
     * Not "the twelve hashes differ" -- bcrypt salts every hash, so twelve accounts sharing ONE staging password still store twelve
     * different strings, and an earlier version of this test passed with a shared password for exactly that reason. The question is
     * whether a password the repository KNOWS opens any of these accounts, so every string literal in the seeder's source, and the
     * joined literals of each of its lines (a password written in pieces is still written down), is put to every stored hash.
     */
    public function test_no_account_it_creates_can_be_signed_into_with_a_password_the_repository_knows(): void
    {
        $this->seed(GccStagingSeeder::class);

        $hashes = array_merge(
            DB::table('sellers')->pluck('password')->map('strval')->all(),
            DB::table('users')->pluck('password')->map('strval')->all(),
        );
        $this->assertCount(12, $hashes);

        foreach ($hashes as $hash) {
            $this->assertMatchesRegularExpression('/^\$2y\$/', $hash, 'the stored value is a hash, not a password');
        }

        foreach ($this->literalsOfTheSeedersSource() as $candidate) {
            foreach ($hashes as $hash) {
                $this->assertFalse(Hash::check($candidate, $hash), "a password written in the seeder's source opens one of its accounts");
            }
        }
    }

    /** The hasher must never be handed a literal: the plaintext has to come from a random source and be dropped on the floor. */
    public function test_the_password_it_stores_is_drawn_from_a_random_source_and_never_written_in_the_source(): void
    {
        $source = file_get_contents(base_path('database/seeders/GccStagingSeeder.php'));

        $this->assertDoesNotMatchRegularExpression(
            '/Hash::make\s*\(\s*[\'"]/',
            (string) $source,
            'Hash::make() is given a literal, so the repository knows a password to these accounts'
        );
        $this->assertMatchesRegularExpression('/Hash::make\(Str::random\(\d+\)\)/', (string) $source);
    }

    /**
     * Every quoted string in the seeder's two files, and the joined literals of each line, as password candidates.
     *
     * @return list<string>
     */
    private function literalsOfTheSeedersSource(): array
    {
        $candidates = [];

        foreach (['GccStagingSeeder.php', 'InventedMarketIdentity.php'] as $file) {
            foreach (file(base_path('database/seeders/' . $file), FILE_IGNORE_NEW_LINES) as $line) {
                preg_match_all('/\'([^\']*)\'|"([^"]*)"/', $line, $matches, PREG_SET_ORDER);
                $onThisLine = array_map(static fn (array $match): string => ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? ''), $matches);
                $candidates = array_merge($candidates, $onThisLine, [implode('', $onThisLine)]);
            }
        }

        return array_values(array_filter(
            array_unique($candidates),
            static fn (string $candidate): bool => strlen($candidate) >= 4 && strlen($candidate) <= 72,
        ));
    }

    public function test_running_it_twice_adds_nothing(): void
    {
        $this->seed(GccStagingSeeder::class);
        $after = $this->rowCounts();

        $this->seed(GccStagingSeeder::class);

        $this->assertSame($after, $this->rowCounts());
    }

    public function test_it_changes_no_setting_and_leaves_the_currency_the_installation_had_exactly_as_it_was(): void
    {
        $riyalBefore = (array) DB::table('currencies')->where('code', 'SAR')->first();

        $this->seed(GccStagingSeeder::class);

        $this->assertSame($riyalBefore, (array) DB::table('currencies')->where('code', 'SAR')->first(), 'the riyal row is untouched');
        $this->assertSame(0, DB::table('business_settings')->count(), 'the seeder writes no setting, so no default moves');
    }

    public function test_it_refuses_to_run_anywhere_that_is_not_a_throwaway_database(): void
    {
        $was = app()['env'];
        app()['env'] = 'production';

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("refuses to run in 'production'");
            (new GccStagingSeeder())->run();
        } finally {
            app()['env'] = $was;
            $this->assertSame(0, DB::table('sellers')->count(), 'and it wrote nothing before refusing');
        }
    }

    /** @return list<string> */
    private function countriesOf(string $table): array
    {
        $countries = DB::table($table)->distinct()->pluck('storefront_country')->all();
        $ordered = array_values(array_filter(self::MARKETS, static fn (string $market): bool => in_array($market, $countries, true)));

        return count($countries) === count($ordered) ? $ordered : array_map('strval', $countries);
    }

    /** @return list<string> */
    private function currencyCodes(): array
    {
        $codes = DB::table('currencies')->pluck('code')->map('strval')->all();
        sort($codes);

        return $codes;
    }

    private function sellerOf(string $market): ?int
    {
        $id = DB::table('sellers')->where('l_name', $market)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function customerOf(string $market): ?int
    {
        $id = DB::table('users')->where('l_name', $market)->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $counts = [];
        foreach (['currencies', 'sellers', 'shops', 'users', 'products', 'orders', 'order_details', 'wallet_transactions', 'seller_wallets'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /** The riyal row an installation already has, so the test can prove the seeder does not touch it. */
    private function theRiyalTheInstallationAlreadyHad(): void
    {
        DB::table('currencies')->insert([
            'id' => 1,
            'name' => 'Saudi Riyal',
            'symbol' => 'SAR',
            'code' => 'SAR',
            'exchange_rate' => 1,
            'status' => 1,
        ]);
    }

    private function createTables(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->longText('value')->nullable();
            $table->timestamps();
        });
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('symbol')->nullable();
            $table->string('code');
            $table->decimal('exchange_rate', 24, 6)->default(1);
            $table->boolean('status')->default(1);
            $table->timestamps();
        });
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('name')->nullable();
            $table->string('contact')->nullable();
            $table->string('address')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->decimal('wallet_balance', 18, 12)->default(0);
            $table->decimal('loyalty_point', 18, 12)->default(0);
            $table->boolean('is_active')->default(1);
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('code')->nullable();
            $table->string('added_by')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('product_type')->nullable();
            $table->decimal('unit_price', 24, 3)->default(0);
            $table->decimal('purchase_price', 24, 3)->default(0);
            $table->integer('current_stock')->default(0);
            $table->integer('status')->default(1);
            $table->integer('request_status')->default(1);
            $table->longText('details')->nullable();
            $table->text('colors')->nullable();
            $table->text('choice_options')->nullable();
            $table->text('variation')->nullable();
            $table->text('images')->nullable();
            $table->text('category_ids')->nullable();
            $table->decimal('discount', 24, 3)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('tax', 24, 3)->default(0);
            $table->string('tax_type')->nullable();
            $table->string('tax_model')->nullable();
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_type')->nullable();
            $table->decimal('order_amount', 24, 3)->default(0);
            $table->string('order_status')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('payment_method')->nullable();
            $table->text('order_note')->nullable();
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 24, 3)->default(0);
            $table->decimal('discount', 24, 3)->default(0);
            $table->decimal('tax', 24, 3)->default(0);
            $table->string('delivery_status')->nullable();
            $table->string('payment_status')->nullable();
            $table->timestamps();
        });
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->uuid('transaction_id');
            $table->decimal('credit', 24, 3)->default(0);
            $table->decimal('debit', 24, 3)->default(0);
            $table->decimal('admin_bonus', 24, 3)->default(0);
            $table->decimal('balance', 24, 3)->default(0);
            $table->string('transaction_type')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
        });
        Schema::create('seller_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->decimal('total_earning', 24, 6)->default(0);
            $table->decimal('withdrawn', 24, 6)->default(0);
            $table->decimal('commission_given', 24, 6)->default(0);
            $table->decimal('pending_withdraw', 24, 6)->default(0);
            $table->decimal('delivery_charge_earned', 24, 6)->default(0);
            $table->decimal('collected_cash', 24, 6)->default(0);
            $table->decimal('total_tax_collected', 24, 6)->default(0);
            $table->timestamps();
        });

        // The GCC storefront columns have real migrations, so they are run rather than written out again here.
        foreach ([
            '2026_11_25_100000_add_storefront_country_to_products.php',
            '2026_11_26_100000_add_storefront_snapshot_to_orders_table.php',
        ] as $migration) {
            (require database_path('migrations/' . $migration))->up();
        }
    }
}
