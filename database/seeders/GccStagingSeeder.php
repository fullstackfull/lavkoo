<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BusinessSetting;
use App\Services\Customer\CustomerWalletWriter;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Six storefronts of invented data for a staging server (M2 "A seeder creates six storefronts with invented sellers, customers, products,
 * orders and wallets and no real personal data"). Before this there was no `database/seeders` directory at all, and the only generator in
 * the repository was a UserFactory, so there was no way to stand a six-market storefront up without typing rows by hand or copying
 * production -- and the charter forbids a production copy.
 *
 * It is NOT registered in a DatabaseSeeder and is never reached by a bare `php artisan db:seed`. It has to be asked for by name:
 *
 *   php artisan db:seed --class="Database\Seeders\GccStagingSeeder"
 *
 * WHAT IT DOES NOT DO, and must not be made to do.
 *
 *  - It changes NO default and NO setting. It writes no `business_settings` row, it does not touch `system_default_currency`, the currency
 *    model or the decimal setting, and it never edits a row it did not insert. Saudi behaviour is whatever it was before it ran: the Saudi
 *    market gets its own invented seller, customer, products, order and wallets like the other five and nothing else about it moves.
 *  - It does not OPEN a market. Which countries serve a storefront is `gcc.storefronts.countries.*.active` and the storefront lifecycle
 *    (`php artisan gcc:storefront:state <CC> ...`), which is a deliberate act with its own gates. This seeder prepares the DATA a market
 *    would trade with; opening it stays a separate decision.
 *  - It does not reach production. `run()` refuses outside a disposable environment, and the refusal is the first thing it does.
 *
 * It is idempotent per market: a market whose invented seller is already there is left exactly as it is, so running it twice adds nothing
 * and a half-finished run can be finished by running it again.
 *
 * The six markets, their currencies and which one is the default are read from `gcc.storefronts` through StorefrontResolver, never typed
 * here, so this cannot drift from the configuration the application serves.
 */
final class GccStagingSeeder extends Seeder
{
    /**
     * Where it may run. Deliberately a list of names rather than "not production": a server whose APP_ENV somebody forgot to set reads as
     * the framework's default, and the default must not be a place this is allowed to write.
     */
    private const DISPOSABLE_ENVIRONMENTS = ['staging', 'testing', 'local'];

    /** How a row of this seeder is recognised again. Also what an operator greps for to delete the lot. */
    private const REFERENCE = 'gcc-staging-seed';

    public function run(): void
    {
        $this->refuseOutsideADisposableEnvironment();
        $this->refuseWhileTheWalletFeatureIsOff();

        $resolver = StorefrontResolver::fromConfig();

        foreach ($resolver->configuredCountries() as $country) {
            $this->seedMarket(
                identity: InventedMarketIdentity::of(country: $country, currency: $resolver->currencyOf($country)),
                isDefault: $country === $resolver->defaultCountry(),
            );
        }
    }

    /**
     * The first thing run() does. A seeder that writes invented customers, orders and wallet movements into a live database would be a
     * data incident, so this is a refusal and not a warning: it throws, and nothing after it runs.
     */
    private function refuseOutsideADisposableEnvironment(): void
    {
        $environment = (string) app()->environment();

        if (!in_array($environment, self::DISPOSABLE_ENVIRONMENTS, true)) {
            throw new \RuntimeException(
                "GccStagingSeeder refuses to run in '{$environment}'. It writes invented sellers, customers, orders and wallet movements, "
                . 'which belong only on a throwaway database. Allowed: ' . implode(', ', self::DISPOSABLE_ENVIRONMENTS) . '.'
            );
        }
    }

    /**
     * The second refusal, and it is asked HERE rather than where the wallet is written because of where the failure would otherwise land.
     * The customer's credit goes through the platform's one wallet writer, which moves nothing and answers false while the wallet feature
     * is switched off -- so a market would get its seller, shop, customer, products and order and then no wallet, and the next run would
     * skip that market altogether, because a market whose invented seller already exists is left exactly as it is. Asked before the first
     * row of the first market, the answer costs one query and leaves no half-built market behind.
     *
     * It reads the setting CustomerWalletWriter itself reads, and reads it the same way, so this cannot pass where that writer would
     * refuse; reading it here as well is what buys the refusal its position. A missing row is a refusal too, and not an incident: the
     * writer dereferences that row without checking it, so a database that has never had the setting would otherwise fail here as an
     * error about null rather than as the plain fact that this platform has no wallet switched on.
     *
     * It only READS. Whether the wallet feature is on stays the operator's decision, like which markets trade: this seeder changes no
     * setting, which is its own promise and is asserted.
     */
    private function refuseWhileTheWalletFeatureIsOff(): void
    {
        $setting = BusinessSetting::where('type', 'wallet_status')->first();

        if ($setting === null || $setting->value != 1) {
            throw new \RuntimeException(
                'GccStagingSeeder needs the customer wallet switched on. It credits each market\'s invented customer through '
                . 'CustomerWalletWriter, which moves nothing while the wallet is off, and six markets holding none of the balances this '
                . 'seeder exists to create is not the staging world it is for. Switch the wallet on for this database, or seed a '
                . 'database that has it on.'
            );
        }
    }

    private function seedMarket(InventedMarketIdentity $identity, bool $isDefault): void
    {
        if ($this->sellerIdOf($identity) !== null) {
            $this->command?->line("  {$identity->country}: already seeded, left alone");

            return;
        }

        $this->addTheMarketsCurrencyIfItIsMissing($identity);

        $sellerId = $this->createSeller($identity);
        $this->createShop(identity: $identity, sellerId: $sellerId);
        $customerId = $this->createCustomer($identity);
        $productIds = $this->createProducts(identity: $identity, sellerId: $sellerId);
        $orderId = $this->createOrder(identity: $identity, customerId: $customerId, sellerId: $sellerId, productIds: $productIds);
        $this->createWallets(identity: $identity, customerId: $customerId, sellerId: $sellerId);

        $this->command?->line(
            "  {$identity->country} ({$identity->currency}" . ($isDefault ? ', the default storefront' : '')
            . "): seller {$sellerId}, customer {$customerId}, " . count($productIds) . " products, order {$orderId}, two wallets"
        );
    }

    /**
     * A storefront can sell only while its currency exists, so a missing one is added. Only a MISSING one: a code that is already in the
     * table keeps its name, symbol, rate and status exactly as the installation set them, which is how the Saudi riyal stays untouched.
     */
    private function addTheMarketsCurrencyIfItIsMissing(InventedMarketIdentity $identity): void
    {
        if (DB::table('currencies')->where('code', $identity->currency)->exists()) {
            return;
        }

        DB::table('currencies')->insert($this->timestamps() + [
            'name' => $identity->currency,
            'symbol' => $identity->currency,
            'code' => $identity->currency,
            'exchange_rate' => 1,
            'status' => 1,
        ]);
    }

    private function createSeller(InventedMarketIdentity $identity): int
    {
        return (int) DB::table('sellers')->insertGetId($this->timestamps() + [
            'f_name' => $identity->sellerName(),
            'l_name' => $identity->country,
            'email' => $identity->sellerEmail(),
            'phone' => $identity->phone(line: 1),
            'password' => $this->unreachablePassword(),
            'status' => 'approved',
        ]);
    }

    private function createShop(InventedMarketIdentity $identity, int $sellerId): void
    {
        DB::table('shops')->insert($this->timestamps() + [
            'seller_id' => $sellerId,
            'name' => $identity->shopName(),
            'contact' => $identity->phone(line: 1),
            'address' => $identity->address(),
        ]);
    }

    private function createCustomer(InventedMarketIdentity $identity): int
    {
        return (int) DB::table('users')->insertGetId($this->timestamps() + [
            'f_name' => $identity->customerName(),
            'l_name' => $identity->country,
            'email' => $identity->customerEmail(),
            'phone' => $identity->phone(line: 2),
            'password' => $this->unreachablePassword(),
            'is_active' => 1,
        ]);
    }

    /**
     * Two products per market, priced in that market's own currency and never converted from another's: local pricing is the decision
     * (DECISIONS.md, "Local pricing, not auto-FX"), so the figures below are simply what this invented shop asks in its own money.
     *
     * @return list<int>
     */
    private function createProducts(InventedMarketIdentity $identity, int $sellerId): array
    {
        $ids = [];

        foreach ([1 => 25, 2 => 40] as $number => $price) {
            $ids[] = (int) DB::table('products')->insertGetId($this->timestamps() + $this->storefrontOf('products', $identity) + [
                'name' => $identity->productName($number),
                'slug' => Str::slug($identity->productName($number)),
                'code' => $identity->productCode($number),
                'added_by' => 'seller',
                'user_id' => $sellerId,
                'product_type' => 'physical',
                'unit_price' => $price,
                'purchase_price' => $price,
                'current_stock' => 100,
                'status' => 1,
                'request_status' => 1,
                'details' => 'Invented staging product of the ' . $identity->country . ' storefront. Not a real article.',
                'colors' => '[]',
                'choice_options' => '[]',
                'variation' => '[]',
                'images' => '[]',
                'category_ids' => '[]',
                'discount' => 0,
                'discount_type' => 'flat',
                'tax' => 0,
                'tax_type' => 'percent',
                'tax_model' => 'exclude',
            ]);
        }

        return $ids;
    }

    /** @param list<int> $productIds */
    private function createOrder(InventedMarketIdentity $identity, int $customerId, int $sellerId, array $productIds): int
    {
        $amount = 65;

        $orderId = (int) DB::table('orders')->insertGetId($this->timestamps() + $this->storefrontOf('orders', $identity) + [
            'customer_id' => $customerId,
            'customer_type' => 'customer',
            'order_amount' => $amount,
            'order_status' => 'delivered',
            'payment_status' => 'paid',
            'payment_method' => 'cash_on_delivery',
            'order_note' => self::REFERENCE,
        ]);

        foreach ($productIds as $productId) {
            DB::table('order_details')->insert($this->timestamps() + [
                'order_id' => $orderId,
                'product_id' => $productId,
                'seller_id' => $sellerId,
                'qty' => 1,
                'price' => $amount / max(count($productIds), 1),
                'discount' => 0,
                'tax' => 0,
                'delivery_status' => 'delivered',
                'payment_status' => 'paid',
            ]);
        }

        return $orderId;
    }

    /**
     * One wallet each side of the market: the customer's credit, with the movement that put it there, and the seller's earnings. Separate
     * per storefront because the decision is that wallet balances and ledgers are separate by store, legal entity and currency
     * (DECISIONS.md) -- each market's invented customer has their own account, and none of them shares a balance with another market's.
     *
     * The credit goes through CustomerWalletWriter and not through a `users` update of its own. An earlier version of this method wrote
     * the balance column here and inserted the ledger row beside it, and Tests\Feature\Fulfillment\WalletBalanceWritersTest reported it
     * as a sixth writer of users.wallet_balance -- correctly. The answer was to stop being one rather than to be declared one: that list
     * is also the list of the places that take the customer's row lock and write the ledger row with the balance the account ends with,
     * and a seeder doing it by hand did neither, so a sixth entry would have made the list's own sentence false for every later reader.
     *
     * Going through the one writer is also what makes the staging data worth standing up: these wallets are now made the way production
     * makes them -- the same row lock, the same overdraft rule, the same ledger row, the same conversion of the amount into the currency
     * the balance is stored in -- so a defect on the platform's wallet path cannot be hidden by staging rows that never walked it. The
     * figure is therefore what an administrator's credit of 150 produces on this installation, which under the multi-currency model is
     * the converted amount rather than 150 exactly.
     */
    private function createWallets(InventedMarketIdentity $identity, int $customerId, int $sellerId): void
    {
        $credit = 150;

        $movement = CustomerWalletWriter::record(
            customerId: $customerId,
            amount: $credit,
            transactionType: 'add_fund_by_admin',
            reference: self::REFERENCE,
        );

        if ($movement === false) {
            throw new \RuntimeException(
                "GccStagingSeeder could not credit the {$identity->country} customer's wallet, and that market is now half-built. The "
                . 'wallet feature was on when the run started, so what is left is a customer row the writer could not find, or a write '
                . 'that threw and was rolled back with the reason in the log. Delete the rows referencing ' . self::REFERENCE
                . ' and run it again.'
            );
        }

        DB::table('seller_wallets')->insert($this->timestamps() + [
            'seller_id' => $sellerId,
            'total_earning' => 65,
            'withdrawn' => 0,
            'commission_given' => 5,
            'pending_withdraw' => 0,
            'delivery_charge_earned' => 0,
            'collected_cash' => 0,
            'total_tax_collected' => 0,
        ]);
    }

    /**
     * The storefront columns, when the table has them. They arrive with the GCC migrations and the code ships dormant until those have
     * run (the pattern every storefront service follows), so a staging database that has not migrated them still gets its six markets'
     * rows -- they are simply not stamped with a country, which is how every row of the platform reads today.
     *
     * @return array<string, string|int|null>
     */
    private function storefrontOf(string $table, InventedMarketIdentity $identity): array
    {
        $columns = [];

        if ($this->hasColumn(table: $table, column: 'storefront_country')) {
            $columns['storefront_country'] = $identity->country;
        }
        if ($this->hasColumn(table: $table, column: 'currency_code')) {
            $columns['currency_code'] = $identity->currency;
            $columns['currency_rate'] = 1;
        }

        return $columns;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return DB::getSchemaBuilder()->hasColumn($table, $column);
    }

    private function sellerIdOf(InventedMarketIdentity $identity): ?int
    {
        $id = DB::table('sellers')->where('email', $identity->sellerEmail())->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * A hash of a value nobody keeps, so the account EXISTS and CANNOT BE SIGNED INTO.
     *
     * Deliberately not a shared staging password. A seeder that writes one known credential into six sellers and six customers puts that
     * credential in the repository's history for good, and the criterion this seeder answers is "no real personal data of any kind, and
     * nothing that looks like a real credential". The random value is generated, hashed and dropped on the floor in the same expression:
     * it is never printed, returned or stored anywhere but as this hash. An operator who needs to sign in as one of these accounts uses
     * the ordinary password-reset flow, which is also the flow worth exercising on a staging server.
     */
    private function unreachablePassword(): string
    {
        return Hash::make(Str::random(64));
    }

    /** @return array<string, \Illuminate\Support\Carbon> */
    private function timestamps(): array
    {
        return ['created_at' => now(), 'updated_at' => now()];
    }
}
