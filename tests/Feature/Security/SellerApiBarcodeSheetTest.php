<?php

namespace Tests\Feature\Security;

use Carbon\Carbon;
use FilesystemIterator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\BuildsSellerApiWorld;
use Tests\Support\ReadsPdfText;
use Tests\TestCase;

/**
 * The barcode sheet that the seller apps ask for (`barcode/generate` of the v3 app and of the older v2 app) was written to ONE public file,
 * storage/app/public/product/barcode.pdf, which every seller shared: two sellers who generated a sheet at the same time overwrote each other's, and
 * any seller who knew the file name (it never changed) read whatever sheet was written last, which names another seller's shop, product, price and
 * code. The answer is a bare JSON string, the address of the sheet, which the app opens in the phone's browser; the browser holds no token.
 *
 * The answer keeps its shape, and the address is now a link that the application signs for the one product of the one seller who asked, valid
 * for a few minutes. Nothing is written to disk: the sheet is made when the link is opened, in the language the app asked for.
 *
 * The web server of this installation serves a file that exists under the project root as it is and hands everything else to the application, so a
 * link is opened the same way here, whatever it names.
 */
class SellerApiBarcodeSheetTest extends TestCase
{
    use BuildsSellerApiWorld;
    use ReadsPdfText;

    private const OWN_PRODUCT = 10;
    private const OWN_PRODUCT_WITHOUT_CODE = 11;
    private const OTHER_SELLERS_PRODUCT = 20;

    private const SHARED_FILE = 'storage/app/public/product/barcode.pdf';

    /** @var list<string> */
    private array $filesBefore;

    private bool $createdTheProductDirectory = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerApiWorld();
        $this->createCatalogueTables();
        $this->seedCatalogue();
        $this->prepareTheProductDirectory();
        $this->filesBefore = $this->filesInTheStorageDirectory();
    }

    protected function tearDown(): void
    {
        $this->removeWhatTheTestMayHaveLeft();
        Carbon::setTestNow();
        $this->tearDownSellerApiWorld();

        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function apps(): array
    {
        return ['the app (v3)' => ['v3'], 'the older app (v2)' => ['v2']];
    }

    /** @return array<string, array{string, string}> */
    public static function pairsOfApps(): array
    {
        return ['both on the app (v3)' => ['v3', 'v3'], 'both on the older app (v2)' => ['v2', 'v2'], 'one on each' => ['v2', 'v3']];
    }

    /** @return array<string, array{array<string, int|null>}> what somebody who holds a link of their own can change in it, to read a sheet that is not theirs */
    public static function changesToAnotherSellersLink(): array
    {
        return [
            'the product of the first seller put in the link' => [['product' => self::OWN_PRODUCT]],
            'the first seller and their product put in the link' => [['seller' => self::SELLER, 'product' => self::OWN_PRODUCT]],
            'the quantity raised' => [['quantity' => 270]],
            'the signature taken out' => [['signature' => null]],
            'the expiry moved' => [['expires' => 4102444800]],
            'the signature taken out and the first seller and their product put in' => [['signature' => null, 'seller' => self::SELLER, 'product' => self::OWN_PRODUCT]],
        ];
    }

    #[DataProvider('apps')]
    public function test_the_owner_opens_the_link_the_answer_names_and_gets_the_sheet_of_their_own_product(string $app): void
    {
        $link = $this->generateTheLink(app: $app, seller: self::SELLER, productId: self::OWN_PRODUCT, quantity: 3);

        $sheet = $this->openTheLink($link)->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', (string) $sheet->getContent());
        $text = $this->textOfPdf((string) $sheet->getContent());
        $this->assertStringContainsString('Own product', $text);
        $this->assertStringContainsString('Alpha Shop', $text);
        $this->assertSame(3, substr_count($text, '1000010'));
    }

    #[DataProvider('pairsOfApps')]
    public function test_two_sellers_who_generate_a_sheet_at_the_same_time_each_open_only_their_own(string $appOfTheFirst, string $appOfTheSecond): void
    {
        $firstLink = $this->generateTheLink(app: $appOfTheFirst, seller: self::SELLER, productId: self::OWN_PRODUCT);
        $secondLink = $this->generateTheLink(app: $appOfTheSecond, seller: self::OTHER_SELLER, productId: self::OTHER_SELLERS_PRODUCT);

        $this->assertNotSame($firstLink, $secondLink);
        $firstText = $this->textOfPdf((string) $this->openTheLink($firstLink)->assertOk()->getContent());
        $secondText = $this->textOfPdf((string) $this->openTheLink($secondLink)->assertOk()->getContent());

        $this->assertStringContainsString('Own product', $firstText);
        $this->assertStringNotContainsString('Other sellers product', $firstText);
        $this->assertStringContainsString('Other sellers product', $secondText);
        $this->assertStringNotContainsString('Own product', $secondText);
    }

    #[DataProvider('changesToAnotherSellersLink')]
    public function test_a_seller_cannot_turn_their_own_link_into_the_sheet_of_another_seller(array $changes): void
    {
        $this->generateTheLink(app: 'v3', seller: self::SELLER, productId: self::OWN_PRODUCT);
        $secondLink = $this->generateTheLink(app: 'v3', seller: self::OTHER_SELLER, productId: self::OTHER_SELLERS_PRODUCT);

        $this->openTheLink($this->changed(link: $secondLink, changes: $changes))->assertForbidden();
    }

    public function test_a_link_opens_under_whatever_host_and_scheme_the_server_sees_it_with(): void
    {
        $link = $this->generateTheLink(app: 'v3', seller: self::SELLER, productId: self::OWN_PRODUCT);

        $text = $this->textOfPdf((string) $this->openTheLink(str_replace(['http://localhost', 'localhost'], ['https://www.example.test', 'www.example.test'], $link))->assertOk()->getContent());

        $this->assertStringContainsString('Own product', $text);
    }

    public function test_a_link_that_names_a_product_but_was_never_signed_gives_nothing(): void
    {
        $this->generateTheLink(app: 'v3', seller: self::SELLER, productId: self::OWN_PRODUCT);

        $this->openTheLink(url('/api/v3/seller/products/barcode/sheet?seller=' . self::SELLER . '&product=' . self::OWN_PRODUCT . '&quantity=2&lang=en'))->assertForbidden();
    }

    public function test_the_old_shared_address_gives_nothing_to_anybody(): void
    {
        $this->generateTheLink(app: 'v3', seller: self::SELLER, productId: self::OWN_PRODUCT);

        $this->openTheLink(asset(self::SHARED_FILE))->assertNotFound();
    }

    public function test_a_link_stops_working_after_a_few_minutes(): void
    {
        $link = $this->generateTheLink(app: 'v3', seller: self::SELLER, productId: self::OWN_PRODUCT);

        Carbon::setTestNow(now()->addMinutes(30));

        $this->openTheLink($link)->assertForbidden();
    }

    public function test_a_link_for_a_product_that_is_gone_answers_not_found(): void
    {
        $link = $this->generateTheLink(app: 'v3', seller: self::SELLER, productId: self::OWN_PRODUCT);
        DB::table('products')->where('id', self::OWN_PRODUCT)->delete();

        $this->openTheLink($link)->assertNotFound();
    }

    public function test_a_link_for_a_product_that_is_no_longer_the_sellers_answers_not_found(): void
    {
        $link = $this->generateTheLink(app: 'v3', seller: self::SELLER, productId: self::OWN_PRODUCT);
        DB::table('products')->where('id', self::OWN_PRODUCT)->update(['user_id' => self::OTHER_SELLER]);

        $this->openTheLink($link)->assertNotFound();
    }

    #[DataProvider('pairsOfApps')]
    public function test_no_file_is_left_on_the_shared_path_or_anywhere_in_the_storage_directory(string $appOfTheFirst, string $appOfTheSecond): void
    {
        $firstLink = $this->generateTheLink(app: $appOfTheFirst, seller: self::SELLER, productId: self::OWN_PRODUCT);
        $secondLink = $this->generateTheLink(app: $appOfTheSecond, seller: self::OTHER_SELLER, productId: self::OTHER_SELLERS_PRODUCT);
        $this->openTheLink($firstLink);
        $this->openTheLink($secondLink);

        $this->assertFileDoesNotExist(base_path(self::SHARED_FILE));
        $this->assertSame($this->filesBefore, $this->filesInTheStorageDirectory());
    }

    public function test_the_sheet_is_printed_in_the_language_the_app_asked_for(): void
    {
        DB::table('translations')->insert([
            'translationable_type' => 'App\Models\Product', 'translationable_id' => self::OWN_PRODUCT, 'locale' => 'fr', 'key' => 'name', 'value' => 'Produit propre',
        ]);
        $this->withHeader('lang', 'fr');
        $link = $this->generateTheLink(app: 'v3', seller: self::SELLER, productId: self::OWN_PRODUCT);
        $this->flushHeaders();

        $text = $this->textOfPdf((string) $this->openTheLink($link)->assertOk()->getContent());

        $this->assertStringContainsString('Produit propre', $text);
        $this->assertStringNotContainsString('Own product', $text);
    }

    #[DataProvider('apps')]
    public function test_a_product_without_a_code_still_asks_for_one_and_no_link_is_made(string $app): void
    {
        $response = $this->askForTheLink(app: $app, seller: self::SELLER, productId: self::OWN_PRODUCT_WITHOUT_CODE, quantity: 2);

        $this->assertSame(203, $response->getStatusCode());
        $this->assertSame(translate('Please update product code!'), $response->json('message'));
    }

    private function generateTheLink(string $app, int $seller, int $productId, int $quantity = 2): string
    {
        $response = $this->askForTheLink(app: $app, seller: $seller, productId: $productId, quantity: $quantity)->assertOk();
        $this->assertIsString($response->json(), 'the app reads the answer as the address to open');

        return $response->json();
    }

    private function askForTheLink(string $app, int $seller, int $productId, int $quantity): TestResponse
    {
        $uri = 'products/barcode/generate?quantity=' . $quantity . '&id=' . $productId;

        return $app === 'v2' ? $this->sellerApiV2('GET', $uri, as: $seller) : $this->sellerApi('GET', $uri, as: $seller);
    }

    /** What the phone gets when the app opens the address: no token, no headers of the app. */
    private function openTheLink(string $link): TestResponse
    {
        $file = base_path(ltrim((string) parse_url($link, PHP_URL_PATH), '/'));
        if (is_file($file)) {
            return TestResponse::fromBaseResponse(response((string) file_get_contents($file), 200, ['Content-Type' => 'application/pdf']));
        }

        return $this->getJson($link);
    }

    /** @param array<string, int|null> $changes a key set to null is taken out of the link */
    private function changed(string $link, array $changes): string
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        foreach ($changes as $key => $value) {
            $value === null ? $query = array_diff_key($query, [$key => true]) : $query[$key] = $value;
        }

        return strtok($link, '?') . '?' . http_build_query($query);
    }

    /** @return list<string> */
    private function filesInTheStorageDirectory(): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(storage_path('app'), FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = $file->getPathname();
        }
        sort($files);

        return $files;
    }

    /** The directory the product images live in exists in every installation, and the sheet used to be written there. */
    private function prepareTheProductDirectory(): void
    {
        $directory = dirname(base_path(self::SHARED_FILE));
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
            $this->createdTheProductDirectory = true;
        }
        @unlink(base_path(self::SHARED_FILE));
    }

    private function removeWhatTheTestMayHaveLeft(): void
    {
        @unlink(base_path(self::SHARED_FILE));
        if ($this->createdTheProductDirectory) {
            @rmdir(dirname(base_path(self::SHARED_FILE)));
        }
    }

    private function seedCatalogue(): void
    {
        DB::table('business_settings')->insert(['type' => 'system_default_currency', 'value' => '1']);
        Cache::flush();
        DB::table('currencies')->insert(['id' => 1, 'name' => 'Saudi Riyal', 'symbol' => 'SAR', 'code' => 'SAR', 'exchange_rate' => 1]);
        DB::table('shops')->insert([['seller_id' => self::SELLER, 'name' => 'Alpha Shop'], ['seller_id' => self::OTHER_SELLER, 'name' => 'Bravo Shop']]);

        $product = fn (int $id, int $userId, string $name, ?string $code) => DB::table('products')->insert([
            'id' => $id, 'user_id' => $userId, 'added_by' => 'seller', 'name' => $name, 'code' => $code, 'unit_price' => 100, 'images' => '[]',
        ]);
        $product(self::OWN_PRODUCT, self::SELLER, 'Own product', '1000010');
        $product(self::OWN_PRODUCT_WITHOUT_CODE, self::SELLER, 'Own product without a code', null);
        $product(self::OTHER_SELLERS_PRODUCT, self::OTHER_SELLER, 'Other sellers product', '2000020');
    }

    private function createCatalogueTables(): void
    {
        $this->createSellerApiTable('products', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('added_by')->nullable();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->decimal('unit_price', 16, 2)->default(0);
            $table->text('images')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        $this->createSellerApiTable('translations', function (Blueprint $table) {
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
        });
        $this->createSellerApiTable('shops', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('currencies', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code')->nullable();
            $table->decimal('exchange_rate', 16, 4)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }
}
