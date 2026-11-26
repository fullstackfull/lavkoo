<?php

namespace Tests\Feature\Security;

use App\Services\Identity\CustomerEmail;
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
 * One customer's e-mail address is one identity, in every spelling and in every market (A2-06, M2-04).
 *
 * The phone half of the card is {@see CanonicalCustomerPhoneTest}; this is the other half, and what was wrong is the same shape with a
 * different cause. `unique:users` compares the string that was typed, through the SERVER'S own collation, so which two spellings are one
 * account is decided by the engine rather than by the platform. Two duplicates survive that, and both were reached here through the real
 * registration endpoint:
 *
 *   - **the domain's trailing dot.** `layla@example.test.` names the same host as `layla@example.test` -- the dot is the root label, and
 *     every resolver and mail server reads them as one domain -- and it is a different STRING on every engine: measured, MariaDB 10.11
 *     answers `'layla@example.test.' = 'layla@example.test'` false. So it is a second account everywhere, and the person who typed it
 *     cannot sign in with the address they believe they registered;
 *   - **capitals, wherever the collation is case-sensitive.** MariaDB 10.11 with `utf8mb4_general_ci` folds them, so there `unique:users`
 *     catches them; SQLite, which this suite runs on, does not, so there `Layla@example.test` is a second account. Which of those is true
 *     of a shop is a property of its server, not of its code.
 *
 * What is NOT a defect, written down so that nobody repairs it twice: surrounding WHITESPACE never reaches the check at all, because
 * `TrimStrings` is in the global middleware stack, so ` layla@example.test ` arrives trimmed. It still matters to the SEARCH, because a
 * row already stored with a space in it cannot be enumerated, and that limit is pinned below.
 *
 * An identity that depends on which server answered is not an identity. The canonical key is the one spelling of an address, and it takes
 * no market -- so the same customer is known by the same key in all six and no country's rule is the default.
 *
 * `gcc.identity.canonical_email.checked` ships off and the first test is that with it off two spellings still make two accounts, which is
 * what the platform does today. The last test is a declaration: every place in the application that creates a customer either carries the
 * rule or is named here with the reason it does not, so a creation path added later cannot quietly be the unguarded one.
 */
class CanonicalCustomerEmailTest extends TestCase
{
    use BuildsRestCustomerAuthSchema;
    use RestoresLanguageFiles;

    private const REGISTER_URI = '/api/v1/auth/register';

    /**
     * Every file of the application that creates a customer account, and how a second account for one address is kept out of it.
     *
     * The split is deliberately the SAME as {@see CanonicalCustomerPhoneTest::CREATION_PATHS}: the two halves of one identity guarded in
     * different places would be a hole shaped like a policy. `guarded` carries `OneAccountPerEmail` in its own validation; `declared` does
     * not, and says why.
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
            // address, and a refusal in the middle of serving somebody at a till is a worse answer than a duplicate a person can merge.
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
            // The website social and OTP sign-ins, which write the row the provider or the code proved: the address they write is the one
            // the customer just proved they hold, and the account they find first is the one they sign in to (A2-04).
            'app/Http/Controllers/Customer/Auth/CustomerAuthController.php' => 'the website one-time-code sign-in',
            'app/Http/Controllers/Customer/Auth/SocialAuthController.php' => 'the website provider sign-in',
        ],
    ];

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

    private function readTheAddressAsOneIdentity(): void
    {
        config(['gcc.identity.canonical_email.checked' => true]);
    }

    private function register(string $email, string $phone = '0551234567'): TestResponse
    {
        return $this->postJson(self::REGISTER_URI, [
            'f_name' => 'Layla',
            'l_name' => 'Alharbi',
            'email' => $email,
            'phone' => $phone,
            'password' => 'a-long-enough-password',
        ]);
    }

    private function holdTheAddress(string $email): int
    {
        return (int) DB::table('users')->insertGetId([
            'f_name' => 'Layla', 'email' => $email, 'phone' => '0551234567',
            'password' => bcrypt('irrelevant'), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, array{0: string}> the six markets, to show the key is the same in each and in none */
    public static function sixMarkets(): array
    {
        return ['SA' => ['SA'], 'AE' => ['AE'], 'KW' => ['KW'], 'QA' => ['QA'], 'BH' => ['BH'], 'OM' => ['OM']];
    }

    /** @return array<string, array{0: string}> spellings of one address that every engine must read as that one address */
    public static function spellingsOfOneAddress(): array
    {
        return [
            'as typed' => ['layla@example.test'],
            'capitals in the local part' => ['Layla@example.test'],
            'capitals in the domain' => ['layla@Example.TEST'],
            'capitals throughout' => ['LAYLA@EXAMPLE.TEST'],
            'a leading space' => [' layla@example.test'],
            'a trailing space' => ['layla@example.test '],
            'spaces on both sides' => ['  layla@example.test  '],
            'a trailing dot on the domain' => ['layla@example.test.'],
            'capitals and a leading space' => [' Layla@Example.test'],
        ];
    }

    public function test_as_it_ships_two_spellings_of_one_address_still_make_two_accounts(): void
    {
        $this->assertFalse(CustomerEmail::isChecked(), 'the switch ships off');

        $this->register('layla@example.test')->assertOk();
        $this->register('layla@example.test.', phone: '0557654321')->assertOk();

        $this->assertSame(
            2,
            DB::table('users')->count(),
            'today the platform keeps both, on every engine, and that is what off means',
        );
    }

    /**
     * The whitespace half of the story, so that it is not repaired twice: `TrimStrings` is in the global middleware stack, so a space
     * around the address never reaches the uniqueness check and today's platform already refuses that second account.
     */
    public function test_surrounding_whitespace_never_reaches_the_check_because_the_request_is_trimmed(): void
    {
        $this->assertFalse(CustomerEmail::isChecked(), 'with the switch off it is the exact-string rule that answers');
        $this->register('layla@example.test')->assertOk();

        $this->register(' layla@example.test ', phone: '0557654321')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'email');

        $this->assertSame(1, DB::table('users')->count(), 'the trimmed address was already taken, which is what the platform does today');
    }

    public function test_with_the_switch_off_no_search_for_the_address_is_made(): void
    {
        $this->holdTheAddress('layla@example.test');

        DB::enableQueryLog();
        $this->register('layla@example.test.', phone: '0557654321')->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertSame([], array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'select "id", "email"') || str_contains($sql, 'select `id`, `email`'),
        )), 'the canonical search is the only reader of id and email together, and it must not have run');
    }

    /**
     * The defect itself, in the engine's own words: the same two pairs of spellings get different answers from MariaDB and from SQLite,
     * which is why the platform cannot leave the question to `unique:users`.
     */
    public function test_the_engine_itself_disagrees_about_which_spellings_are_one_account(): void
    {
        $capitalsAreOneValue = (bool) DB::selectOne("select ('Foo@example.test' = 'foo@example.test') as same")->same;
        $aLeadingSpaceIsOneValue = (bool) DB::selectOne("select (' foo@example.test' = 'foo@example.test') as same")->same;

        $theTrailingDotIsOneValue = (bool) DB::selectOne("select ('foo@example.test.' = 'foo@example.test') as same")->same;

        $this->assertFalse($theTrailingDotIsOneValue, 'no engine folds the domain\'s trailing dot, so THAT duplicate is possible everywhere');
        $this->assertFalse($aLeadingSpaceIsOneValue, 'nor a leading space, which is why a row already holding one cannot be found');

        $this->assertSame(
            DB::connection()->getDriverName() !== 'sqlite',
            $capitalsAreOneValue,
            'a case-insensitive server folds the capitals and SQLite does not: the identity depends on the engine, which is the defect',
        );

        $this->assertTrue(
            CustomerEmail::isSameAddress('Foo@example.test', ' foo@example.test '),
            'the canonical key answers the same question the same way on both',
        );
    }

    #[DataProvider('spellingsOfOneAddress')]
    public function test_every_spelling_of_one_address_has_the_same_canonical_key(string $spelling): void
    {
        $this->assertSame('layla@example.test', CustomerEmail::canonical($spelling));
    }

    #[DataProvider('sixMarkets')]
    public function test_the_key_is_the_same_in_every_market_and_in_none(string $market): void
    {
        config(['gcc.storefronts.default_country' => $market]);

        $this->assertSame(
            'layla@example.test',
            CustomerEmail::canonical(' Layla@Example.TEST '),
            "the address names its own mailbox in {$market} as in every other market: there is no country whose rule is the default",
        );
    }

    public function test_an_address_of_another_mailbox_is_not_the_same_identity(): void
    {
        $this->assertFalse(CustomerEmail::isSameAddress('layla@example.test', 'layla@other.test'));
        $this->assertFalse(CustomerEmail::isSameAddress('layla@example.test', 'layla.a@example.test'));
        $this->assertFalse(
            CustomerEmail::isSameAddress('layla@example.test', 'layla+shop@example.test'),
            'a +tag is one mailbox at some providers and two at others: folding it would merge accounts the platform has always kept apart',
        );
    }

    public function test_a_value_that_is_not_one_address_is_judged_by_nobody(): void
    {
        foreach (['', '   ', 'layla', 'layla@', '@example.test', 'layla@a@example.test', 'layla@.'] as $value) {
            $this->assertNull(CustomerEmail::canonical($value), "'{$value}' is not one address");
        }

        $this->assertNull(CustomerEmail::canonical(null));
    }

    public function test_a_second_account_for_the_same_address_in_another_spelling_is_refused(): void
    {
        $this->readTheAddressAsOneIdentity();
        $this->holdTheAddress('layla@example.test');

        $this->register('layla@example.test.', phone: '0557654321')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'email');

        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_every_spelling_a_row_was_stored_in_is_found(): void
    {
        $this->readTheAddressAsOneIdentity();
        $addresses = app(CustomerEmail::class);

        foreach (['layla@example.test', 'layla@example.test.'] as $stored) {
            DB::table('users')->delete();
            $id = $this->holdTheAddress($stored);

            $this->assertSame($id, $addresses->accountHoldingTheSameAddress('layla@example.test'), "a row stored as '{$stored}' is found");
            $this->assertSame($id, $addresses->accountHoldingTheSameAddress('layla@example.test.'), "and by the dotted spelling of it");
        }
    }

    /**
     * THE SECOND KNOWN LIMIT: a row ALREADY stored with whitespace around the address is not found, because the spellings a search is
     * made from cannot enumerate what somebody once typed. Nothing creates such a row any more -- the request is trimmed -- so this is
     * about the rows a live table already holds, which is why A2-01's duplicate count comes before a unique key.
     */
    public function test_a_row_stored_with_whitespace_around_it_is_not_found_and_that_is_written_down(): void
    {
        $this->readTheAddressAsOneIdentity();
        $this->holdTheAddress(' layla@example.test');

        $this->assertNull(app(CustomerEmail::class)->accountHoldingTheSameAddress('layla@example.test'));
        $this->assertTrue(
            CustomerEmail::isSameAddress(' layla@example.test', 'layla@example.test'),
            'the comparison itself knows they are one address; what is missing is a way to FIND the row',
        );
    }

    /**
     * THE KNOWN LIMIT, pinned so that it is a fact and not a surprise: on a case-sensitive engine a row stored with capitals is not one of
     * the spellings searched for, so a second account for it is still possible there.
     *
     * A real server's collation closes it by itself -- the `whereIn` is case-insensitive there, which the same assertion asserts in the
     * other direction. Closing it on every engine needs either a canonical column or `lower(email)`, and the second gives up the index on
     * `users.email`, which on a live table is the cost the phone half refused a suffix scan for. The column is the owner's open decision
     * (A2-01), and the day it exists this test is the one that says what changed.
     */
    public function test_a_row_stored_with_capitals_is_found_only_where_the_engine_folds_them(): void
    {
        $this->readTheAddressAsOneIdentity();
        $this->holdTheAddress('LAYLA@EXAMPLE.TEST');

        $found = app(CustomerEmail::class)->accountHoldingTheSameAddress('layla@example.test');

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->assertNull($found, 'SQLite compares text case-sensitively, so the search does not reach the row: the pinned limit');
        } else {
            $this->assertNotNull($found, 'a case-insensitive collation widens the exact search to every difference of case');
        }

        $this->assertTrue(
            CustomerEmail::isSameAddress('LAYLA@EXAMPLE.TEST', 'layla@example.test'),
            'the comparison itself knows they are one address; what can be missing is a way to FIND the row',
        );
    }

    public function test_another_customers_address_is_not_refused(): void
    {
        $this->readTheAddressAsOneIdentity();
        $this->holdTheAddress('layla@example.test');

        $this->register('noura@example.test', phone: '0557654321')->assertOk();

        $this->assertSame(2, DB::table('users')->count());
    }

    public function test_an_account_is_not_a_duplicate_of_itself(): void
    {
        $this->readTheAddressAsOneIdentity();
        $id = $this->holdTheAddress('layla@example.test');
        $addresses = app(CustomerEmail::class);

        $this->assertSame($id, $addresses->accountHoldingTheSameAddress(' Layla@example.test '));
        $this->assertNull($addresses->accountHoldingTheSameAddress(' Layla@example.test ', exceptId: $id));
    }

    public function test_a_value_that_is_not_one_address_never_refuses_a_registration(): void
    {
        $this->readTheAddressAsOneIdentity();
        $this->holdTheAddress('layla');

        $this->assertNull(
            app(CustomerEmail::class)->accountHoldingTheSameAddress('layla'),
            'a key nobody can compute must not become a refusal: the exact-string rule is still what refuses it',
        );
    }

    /**
     * The declaration: every file that creates a customer is either guarded or named with its reason, and the split is the phone's.
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

        $this->assertSame(
            CanonicalCustomerPhoneTest::creationPaths(),
            self::CREATION_PATHS,
            'the two halves of one identity are guarded in the same places, or the split is a hole shaped like a policy',
        );

        foreach (self::CREATION_PATHS['guarded'] as $path => $validatedBy) {
            $carrier = $validatedBy ?? $path;
            $this->assertStringContainsString(
                'OneAccountPerEmail',
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
