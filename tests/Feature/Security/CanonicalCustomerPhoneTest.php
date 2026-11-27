<?php

namespace Tests\Feature\Security;

use App\Services\Identity\CustomerPhone;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRestCustomerAuthSchema;
use Tests\Support\PlatformWriteScanner;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * One customer's phone is one identity, in every spelling and in every market (A2-06).
 *
 * Two things were wrong and they are two criteria of that card.
 *
 * A number with NO country code was read as Saudi, which is the fact for a platform serving one country and the wrong answer five times
 * out of six for a platform serving six: a Kuwaiti customer's own eight-digit number would be judged by the Kingdom's pattern at the one
 * place that decides whether they may have an account. It is attached to the market being SERVED now, and to the default storefront where
 * a request names none, so a Saudi installation answers Saudi Arabia exactly as it always has.
 *
 * And a second account for the same number was refused only by an exact string: `unique:users` compares what was typed, so one phone in
 * three spellings was three customers, and whichever row a later sign-in found first decided which account the person got.
 *
 * `gcc.identity.canonical_phone.checked` ships off and the first test is that with it off two spellings still make two accounts, which is
 * what the platform does today. The last test is a declaration: every place in the application that creates a customer either carries the
 * rule or is named here with the reason it does not, so a creation path added later cannot quietly be the unguarded one.
 */
class CanonicalCustomerPhoneTest extends TestCase
{
    use BuildsRestCustomerAuthSchema;
    use RestoresLanguageFiles;

    private const REGISTER_URI = '/api/v1/auth/register';

    /**
     * Every file of the application that creates a customer account, and how a second account for one number is kept out of it.
     *
     * `guarded` carries `OneAccountPerPhone` in its own validation; `declared` does not, and says why. The two lists together must be
     * every such file, which the last test checks by scanning for the creation itself -- so a path added later is a failure here rather
     * than a hole nobody notices.
     */
    private const CREATION_PATHS = [
        // path => the file whose validation carries the rule, which is the path itself unless it validates through a form request
        'guarded' => [
            'app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php' => null,
            'app/Http/Controllers/RestAPI/v1/auth/SocialAuthController.php' => null,
            'app/Http/Controllers/Customer/Auth/RegisterController.php' => 'app/Http/Requests/Web/CustomerRegistrationRequest.php',
        ],
        'declared' => [
            // A customer created by a member of staff who is looking at the screen: the panel shows them the account that holds the
            // number, and a refusal in the middle of serving somebody at a till is a worse answer than a duplicate a person can merge.
            'app/Http/Controllers/Admin/Customer/CustomerController.php' => 'an administrator adding a customer by hand',
            'app/Http/Controllers/Vendor/CustomerController.php' => 'a seller adding a customer by hand',
            'app/Http/Controllers/RestAPI/v3/seller/POSController.php' => 'the seller app\'s point of sale',
            'app/Http/Controllers/Web/WebController.php' => 'the website point of sale',
            // A customer the platform makes for somebody who has already paid: refusing one would lose the order, and the money is
            // already taken. These are the paths A2-01's duplicate count has to be read against before a unique key is considered.
            'app/Http/Controllers/RestAPI/v1/OrderController.php' => 'a guest order being turned into an account',
            'app/Utils/module-helper.php' => 'the payment settlement path, after the money is taken',
            'app/Http/Controllers/RestAPI/v1/auth/PassportAuthController.php' => 'the legacy passport sign-up, which the apps no longer call',
            // Not a customer of the storefront at all.
            'app/Http/Controllers/Auth/RegisterController.php' => 'the framework scaffolding\'s own sign-up, unrouted on this platform',
            // The website social and OTP sign-ins, which write the row the provider or the code proved: the number they write is the one
            // the customer just proved they hold, and the account they find first is the one they sign in to (A2-04).
            'app/Http/Controllers/Customer/Auth/CustomerAuthController.php' => 'the website one-time-code sign-in',
            'app/Http/Controllers/Customer/Auth/SocialAuthController.php' => 'the website provider sign-in',
        ],
    ];

    /**
     * The split the e-mail half of this card must match ({@see CanonicalCustomerEmailTest}), so one identity is not guarded in two
     * different sets of places.
     *
     * @return array{guarded: array<string, string|null>, declared: array<string, string>}
     */
    public static function creationPaths(): array
    {
        return self::CREATION_PATHS;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildRestCustomerAuthSchema();
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('referred_by')->nullable();
        });
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function readTheNumberAsOneIdentity(): void
    {
        config(['gcc.identity.canonical_phone.checked' => true]);
    }

    private function register(string $phone, string $email = 'first@example.test'): TestResponse
    {
        return $this->postJson(self::REGISTER_URI, [
            'f_name' => 'Layla',
            'l_name' => 'Alharbi',
            'email' => $email,
            'phone' => $phone,
            'password' => 'a-long-enough-password',
        ]);
    }

    private function accountsHolding(string ...$phones): int
    {
        return DB::table('users')->whereIn('phone', $phones)->count();
    }

    /** @return array<string, array{0: string, 1: string}> a market and one of its numbers, as a customer of that market writes it */
    public static function sixMarketsAndTheirOwnNumbers(): array
    {
        return [
            'Saudi Arabia' => ['SA', '0551234567'],
            'the Emirates' => ['AE', '0501234567'],
            'Kuwait' => ['KW', '51234567'],
            'Qatar' => ['QA', '31234567'],
            'Bahrain' => ['BH', '31234567'],
            'Oman' => ['OM', '91234567'],
        ];
    }

    public function test_as_it_ships_two_spellings_of_one_number_still_make_two_accounts(): void
    {
        $this->assertFalse(CustomerPhone::isChecked(), 'the switch ships off');

        $this->register('0551234567')->assertOk();
        $this->register('+966551234567', email: 'second@example.test')->assertOk();

        $this->assertSame(2, $this->accountsHolding('0551234567', '+966551234567'), 'today the platform keeps both, and that is what off means');
    }

    public function test_a_number_that_names_no_country_belongs_to_the_market_being_served(): void
    {
        $this->assertSame('SA', CustomerPhone::marketOf('0551234567'), 'the default storefront, which is what a Saudi platform answers');
        $this->assertSame('KW', CustomerPhone::marketOf('51234567', servedMarket: 'KW'));
        $this->assertSame('QA', CustomerPhone::marketOf('31234567', servedMarket: 'QA'));
    }

    public function test_a_number_that_names_its_own_country_belongs_to_that_market_whatever_is_being_served(): void
    {
        $this->assertSame('KW', CustomerPhone::marketOf('+96551234567', servedMarket: 'SA'));
        $this->assertSame('SA', CustomerPhone::marketOf('00966551234567', servedMarket: 'KW'));
        $this->assertSame('OM', CustomerPhone::marketOf('96891234567', servedMarket: 'SA'));
    }

    public function test_a_number_of_a_country_the_platform_has_no_plan_for_is_judged_by_nobody(): void
    {
        $this->assertNull(CustomerPhone::canonical('+447911123456'), 'a British mobile belongs to no market of ours');
        $this->assertNull(CustomerPhone::canonical('+12025550123'));
    }

    #[DataProvider('sixMarketsAndTheirOwnNumbers')]
    public function test_each_market_has_one_canonical_spelling_of_its_own_number(string $market, string $national): void
    {
        $canonical = CustomerPhone::canonical($national, servedMarket: $market);

        $this->assertNotNull($canonical, "{$market} can read its own number");

        $bare = ltrim($national, '0');
        foreach (['+' => '+', '00' => '00', 'bare' => ''] as $spelling) {
            $code = substr($canonical, 1, strlen($canonical) - strlen($bare) - 1);
            $this->assertSame(
                $canonical,
                CustomerPhone::canonical($spelling . $code . $bare, servedMarket: $market),
                'every spelling of it reads as the same number',
            );
        }
    }

    public function test_a_second_account_for_the_same_number_in_another_spelling_is_refused(): void
    {
        $this->readTheNumberAsOneIdentity();
        $this->register('0551234567')->assertOk();

        $this->register('+966551234567', email: 'second@example.test')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'phone');

        $this->assertSame(1, $this->accountsHolding('0551234567', '+966551234567'));
    }

    /**
     * THE KNOWN LIMIT, pinned so that it is a fact and not a surprise: a number stored WITH SEPARATORS is not one of the spellings
     * searched for, so a second account for it is still possible.
     *
     * Finding it needs either a canonical column -- the owner's open decision, a migration over live data -- or a suffix scan of the
     * table, which on a live one is slow AND wrong, because the real duplicate may not be among the rows the scan's limit returns. The
     * day the column exists this test is the one that says what changed.
     */
    public function test_a_number_stored_with_separators_in_it_is_not_found_and_that_is_written_down(): void
    {
        $this->readTheNumberAsOneIdentity();
        DB::table('users')->insert([
            'f_name' => 'Layla', 'email' => 'held@example.test', 'phone' => '00966 55 123 4567',
            'password' => bcrypt('irrelevant'), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNull(app(CustomerPhone::class)->accountHoldingTheSameNumber('0551234567'));
        $this->assertTrue(
            \App\Services\Fulfillment\Support\SaudiPhone::isSameNumber('00966 55 123 4567', '0551234567'),
            'the comparison itself knows they are one number; what is missing is a way to FIND the row',
        );
    }

    /** A spelling with no separators is found, whichever of the forms a row was written in. */
    public function test_every_contiguous_spelling_of_a_stored_number_is_found(): void
    {
        $this->readTheNumberAsOneIdentity();
        $phones = app(CustomerPhone::class);

        foreach (['0551234567', '551234567', '+966551234567', '00966551234567', '966551234567', '9660551234567'] as $stored) {
            DB::table('users')->where('email', 'held@example.test')->delete();
            DB::table('users')->insert([
                'f_name' => 'Layla', 'email' => 'held@example.test', 'phone' => $stored,
                'password' => bcrypt('irrelevant'), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->assertNotNull($phones->accountHoldingTheSameNumber('+966551234567'), "a row stored as {$stored} is found");
        }
    }

    public function test_another_subscribers_number_is_not_refused(): void
    {
        $this->readTheNumberAsOneIdentity();
        $this->register('0551234567')->assertOk();

        $this->register('0557654321', email: 'second@example.test')->assertOk();

        $this->assertSame(2, DB::table('users')->count());
    }

    public function test_an_account_is_not_a_duplicate_of_itself(): void
    {
        $this->readTheNumberAsOneIdentity();
        $this->register('0551234567')->assertOk();
        $id = (int) DB::table('users')->where('phone', '0551234567')->value('id');

        $phones = app(CustomerPhone::class);

        $this->assertSame($id, $phones->accountHoldingTheSameNumber('+966551234567'));
        $this->assertNull($phones->accountHoldingTheSameNumber('+966551234567', exceptId: $id));
    }

    public function test_a_number_of_a_country_with_no_plan_never_refuses_a_registration(): void
    {
        $this->readTheNumberAsOneIdentity();
        DB::table('users')->insert([
            'f_name' => 'Layla', 'email' => 'held@example.test', 'phone' => '+447911123456',
            'password' => bcrypt('irrelevant'), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNull(
            app(CustomerPhone::class)->accountHoldingTheSameNumber('+447911123456'),
            'a key nobody can compute must not become a refusal: the exact-string rule is still what refuses it',
        );
    }

    public function test_with_the_switch_off_the_search_is_never_made(): void
    {
        DB::table('users')->insert([
            'f_name' => 'Layla', 'email' => 'held@example.test', 'phone' => '0551234567',
            'password' => bcrypt('irrelevant'), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::enableQueryLog();
        $this->register('+966551234567', email: 'second@example.test')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], array_values(array_filter(
            array_column($queries, 'query'),
            static fn (string $sql): bool => str_contains($sql, 'like'),
        )), 'no suffix search is made while the switch is off');
    }

    /**
     * The declaration: every file that creates a customer is either guarded or named with its reason.
     *
     * Comments are stripped before the code is read ({@see PlatformWriteScanner::codeOf()}), so what a file SAYS about the rule does not
     * count -- only a call to it.
     */
    public function test_every_creation_path_is_guarded_or_declared(): void
    {
        $creating = $this->filesThatCreateACustomer();
        $declared = [...array_keys(self::CREATION_PATHS['guarded']), ...array_keys(self::CREATION_PATHS['declared'])];
        sort($declared);

        $this->assertSame([], array_values(array_diff($creating, $declared)), 'a file that creates a customer and is in neither list');
        $this->assertSame([], array_values(array_diff($declared, $creating)), 'a declared file that no longer creates a customer');

        foreach (self::CREATION_PATHS['guarded'] as $path => $validatedBy) {
            $carrier = $validatedBy ?? $path;
            $this->assertStringContainsString(
                'OneAccountPerPhone',
                PlatformWriteScanner::codeOf((string) file_get_contents(base_path($carrier))),
                "{$path} is listed as guarded, by {$carrier}",
            );
        }
    }

    /** @return list<string> the application's files that write a customer row, by the three ways it is written */
    private function filesThatCreateACustomer(): array
    {
        $found = [];

        foreach (['app/Http', 'app/Utils', 'app/Services'] as $tree) {
            foreach ($this->phpFilesIn(base_path($tree)) as $path) {
                $code = PlatformWriteScanner::codeOf((string) file_get_contents($path));
                $creates = preg_match('/(?<![A-Za-z])User::create\(/', $code) === 1
                    || str_contains($code, 'customerRepo->add(')
                    || str_contains($code, 'customerRepo->updateOrCreate(');

                if ($creates) {
                    $found[] = str_replace(base_path() . '/', '', $path);
                }
            }
        }
        sort($found);

        return array_values(array_unique($found));
    }

    /** @return list<string> */
    private function phpFilesIn(string $directory): array
    {
        $files = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($items as $item) {
            if ($item->isFile() && $item->getExtension() === 'php') {
                $files[] = $item->getPathname();
            }
        }

        return $files;
    }
}
