<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\BuildsDigitalFileWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * What a customer can fetch of a digital file while the private mode is on, and that nothing changes while it is off.
 *
 * The website hands the browser the address of the file after the checks it always made (the order is paid and the buyer is signed in, or the code the
 * buyer received is right), and the browser fetches it. With the mode on that address cannot be a public one: it is a signed, short-lived address of a route that
 * streams the file from the server and asks again whether the order is paid. The apps of the shop get the file from the route that checked them, which streams it.
 */
class DigitalFilesCustomerDownloadTest extends TestCase
{
    use BuildsDigitalFileWorld;
    use RestoresLanguageFiles;

    private const NAME = '2026-11-16-guide.pdf';

    private const OTP = '4821';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildDigitalFileWorld();
        $this->withoutTheShellOfTheWeb();
        Schema::create('guest_users', static function (Blueprint $table) {
            $table->id();
            $table->string('ip_address')->nullable();
            $table->string('fcm_token')->nullable();
            $table->timestamps();
        });
        RateLimiter::clear('digital-product-download-otp:1');
        DB::table('users')->insert([
            ['id' => 5, 'f_name' => 'Buyer', 'email' => 'buyer@example.test', 'phone' => '0555000005'],
            ['id' => 6, 'f_name' => 'Other', 'email' => 'other@example.test', 'phone' => '0555000006'],
        ]);
        DB::table('products')->insert(['id' => 5, 'name' => 'Guide', 'product_type' => 'digital', 'digital_product_type' => 'ready_product', 'digital_file_ready' => self::NAME, 'thumbnail' => 't.webp']);
        $this->orderLine(id: 1);
        $this->orderLine(id: 2, payment: 'unpaid');
        $this->orderLine(id: 3, type: 'ready_after_sell');
    }

    protected function tearDown(): void
    {
        $this->removeDigitalDisks();
        RateLimiter::clear('digital-product-download-otp:1');
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** An order line of a digital product: its order and its payment, and what the line delivers (the ready file named in its snapshot, or the file uploaded after the sale). */
    private function orderLine(int $id, string $payment = 'paid', string $type = 'ready_product', ?int $customer = 5, bool $guest = false): void
    {
        $snapshot = ['name' => 'Guide', 'product_type' => 'digital', 'digital_product_type' => $type, 'digital_file_ready' => $type === 'ready_product' ? self::NAME : null, 'storage_path' => 'public'];
        DB::table('orders')->insert([
            'id' => $id, 'customer_id' => $customer, 'is_guest' => $guest ? 1 : 0, 'payment_status' => $payment,
            'shipping_address_data' => $guest ? json_encode(['email' => 'guest@example.test', 'phone' => '0555000009', 'contact_person_name' => 'Guest']) : null,
        ]);
        DB::table('order_details')->insert([
            'id' => $id, 'order_id' => $id, 'product_id' => 5, 'payment_status' => $payment, 'product_details' => json_encode($snapshot),
            'digital_file_after_sell' => $type === 'ready_after_sell' ? self::NAME : null,
        ]);
    }

    private function codeSentFor(int $line): void
    {
        DB::table('digital_product_otp_verifications')->insert(['order_details_id' => (string) $line, 'identity' => 'buyer@example.test', 'token' => self::OTP, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function asBuyer(): static
    {
        return $this->actingAs(User::findOrFail(5), 'customer');
    }

    private function asNobody(): void
    {
        Auth::forgetGuards();
    }

    private function askForTheAddressOf(int $line): TestResponse
    {
        return $this->getJson("/digital-product-download/{$line}");
    }

    private function verifyTheCode(int $line, string $code = self::OTP): TestResponse
    {
        return $this->postJson('/digital-product-download-otp-verify', ['otp' => $code, 'order_details_id' => $line]);
    }

    /** What a browser does with the address it was given: fetches it, with whatever session it has. */
    private function fetch(string $address): TestResponse
    {
        return $this->get($address);
    }

    private function assertStreamed(TestResponse $response, string $content = 'the guide'): void
    {
        $response->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertSame($content, file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    private function assertNoFile(TestResponse $response): void
    {
        $this->assertNotInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertStringNotContainsString('the guide', (string) $response->getContent());
    }

    // ---- the website: the address the buyer gets -----------------------------------------------------------------------------------

    public function test_while_the_mode_is_off_the_buyer_gets_the_public_address_of_the_file_as_always(): void
    {
        $this->useDigitalDisks(private: false);
        $this->putOnPublicDisk(self::NAME);
        $this->asBuyer();

        $response = $this->askForTheAddressOf(line: 1);

        $response->assertOk()->assertJsonPath('status', 1)->assertJsonPath('file_name', self::NAME);
        $this->assertSame(storageLink('product/digital-product', self::NAME, 'public')['path'], $response->json('file_path'));
        $this->assertSame(200, $this->publicAddressStatus(self::NAME));
    }

    public function test_while_the_mode_is_off_the_file_delivered_after_the_sale_has_its_public_address_as_always(): void
    {
        $this->useDigitalDisks(private: false);
        $this->putOnPublicDisk(self::NAME);
        $this->asBuyer();

        $response = $this->askForTheAddressOf(line: 3);

        $response->assertJsonPath('status', 1);
        $this->assertSame(storageLink('product/digital-product', self::NAME, 'public')['path'], $response->json('file_path'));
    }

    /** @return array<string, array{0: int}> the line that delivers a ready file, and the line that delivers a file uploaded after the sale */
    public static function linesOfPaidOrders(): array
    {
        return ['a ready product' => [1], 'a file delivered after the sale' => [3]];
    }

    #[DataProvider('linesOfPaidOrders')]
    public function test_while_the_mode_is_on_the_buyer_gets_a_signed_address_that_streams_the_file_from_the_server(int $line): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME);
        $this->asBuyer();

        $response = $this->askForTheAddressOf(line: $line);

        $response->assertOk()->assertJsonPath('status', 1)->assertJsonPath('file_name', self::NAME);
        $address = (string) $response->json('file_path');
        $this->assertStringStartsWith("/digital-product-download-file/{$line}/" . self::NAME . '?', $address, 'the last part of the path is the name the browser saves the file under');
        $this->assertStringNotContainsString('storage', $address);
        $this->assertSame(404, $this->publicAddressStatus(self::NAME));

        $this->asNobody();
        $this->assertStreamed($this->fetch($address));
    }

    public function test_while_the_mode_is_on_a_file_that_is_still_on_the_public_disk_is_delivered_through_the_same_address(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPublicDisk(self::NAME, 'stored before the switch');
        $this->asBuyer();

        $address = (string) $this->askForTheAddressOf(line: 1)->json('file_path');

        $this->assertStringStartsWith('/digital-product-download-file/1/', $address);
        $this->asNobody();
        $this->assertStreamed($this->fetch($address), content: 'stored before the switch');
    }

    public function test_while_the_mode_is_on_a_file_that_is_on_both_disks_is_delivered_from_the_private_one(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME, 'the private copy');
        $this->putOnPublicDisk(self::NAME, 'the public copy');
        $this->asBuyer();

        $address = (string) $this->askForTheAddressOf(line: 1)->json('file_path');

        $this->assertStreamed($this->fetch($address), content: 'the private copy');
    }

    public function test_an_order_that_is_not_paid_gets_no_address_and_no_file(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME);
        $this->asBuyer();

        $response = $this->askForTheAddressOf(line: 2);

        $response->assertOk()->assertJsonPath('status', 0);
        $this->assertNull($response->json('file_path'));
        $this->assertNoFile($response);
    }

    public function test_a_file_that_is_nowhere_gets_the_answer_it_always_got(): void
    {
        $this->useDigitalDisks(private: true);
        $this->asBuyer();

        $this->askForTheAddressOf(line: 1)->assertOk()->assertJsonPath('status', 0)->assertJsonPath('message', translate('file_not_found'));
    }

    public function test_the_code_that_was_sent_to_the_buyer_gives_the_signed_address_once(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME);
        $this->orderLine(id: 4, customer: null, guest: true);
        $this->codeSentFor(line: 4);

        $response = $this->verifyTheCode(line: 4);

        $response->assertOk()->assertJsonPath('status', 1);
        $address = (string) $response->json('file_path');
        $this->assertStringStartsWith('/digital-product-download-file/4/', $address);
        $this->assertStreamed($this->fetch($address));
        $this->assertSame(0, DB::table('digital_product_otp_verifications')->count(), 'the code is spent');
        $this->verifyTheCode(line: 4)->assertJsonPath('status', 0);
    }

    public function test_while_the_mode_is_off_the_code_gives_the_public_address_as_always(): void
    {
        $this->useDigitalDisks(private: false);
        $this->putOnPublicDisk(self::NAME);
        $this->orderLine(id: 4, customer: null, guest: true);
        $this->codeSentFor(line: 4);

        $response = $this->verifyTheCode(line: 4);

        $response->assertJsonPath('status', 1);
        $this->assertSame(storageLink('product/digital-product', self::NAME, 'public')['path'], $response->json('file_path'));
    }

    public function test_a_wrong_code_gives_nothing(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME);
        $this->orderLine(id: 4, customer: null, guest: true);
        $this->codeSentFor(line: 4);

        $response = $this->verifyTheCode(line: 4, code: '0000');

        $response->assertOk()->assertJsonPath('status', 0);
        $this->assertNull($response->json('file_path'));
        $this->assertSame(1, DB::table('digital_product_otp_verifications')->count(), 'the right code is still there');
    }

    public function test_the_code_of_an_order_that_is_not_paid_gives_no_address_for_a_file_that_is_still_on_the_public_disk(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPublicDisk(self::NAME);
        $this->orderLine(id: 4, payment: 'unpaid', customer: null, guest: true);
        $this->codeSentFor(line: 4);

        $response = $this->verifyTheCode(line: 4);

        $response->assertJsonPath('status', 0);
        $this->assertNull($response->json('file_path'));
        $this->assertNoFile($response);
    }

    // ---- the route that streams the file ---------------------------------------------------------------------------------------------

    private function signedAddress(int $line, string $name = self::NAME, int $minutes = 5): string
    {
        return URL::temporarySignedRoute('digital-product-download-file', now()->addMinutes($minutes), ['id' => $line, 'fileName' => $name], absolute: false);
    }

    public function test_a_signed_address_streams_the_file_to_whoever_holds_it_for_a_paid_order(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME);

        $response = $this->get($this->signedAddress(line: 1));

        $this->assertStreamed($response);
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_an_address_that_is_not_signed_streams_nothing(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME);

        $this->getJson('/digital-product-download-file/1/' . self::NAME)->assertForbidden();
        $this->asBuyer();
        $this->getJson('/digital-product-download-file/1/' . self::NAME)->assertForbidden();
    }

    /** @return array<string, array{0: callable(string): string}> */
    public static function addressesThatWereChanged(): array
    {
        return [
            'another line with the signature of the first' => [static fn (string $address): string => str_replace('/1/', '/3/', $address)],
            'another file name' => [static fn (string $address): string => str_replace(self::NAME, 'other.pdf', $address)],
            'a signature that is not the one' => [static fn (string $address): string => (string) preg_replace('/signature=[0-9a-f]+/', 'signature=' . str_repeat('0', 64), $address)],
            'a later expiry' => [static fn (string $address): string => (string) preg_replace('/expires=\d+/', 'expires=' . (time() + 86400), $address)],
            'no signature' => [static fn (string $address): string => (string) preg_replace('/&signature=[0-9a-f]+/', '', $address)],
        ];
    }

    #[DataProvider('addressesThatWereChanged')]
    public function test_an_address_that_was_changed_streams_nothing(callable $change): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME);
        $this->putOnPrivateDisk('other.pdf', 'another file');

        $response = $this->getJson($change($this->signedAddress(line: 1)));

        $response->assertForbidden();
        $this->assertNoFile($response);
    }

    public function test_a_signed_address_does_not_last(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME);
        $address = $this->signedAddress(line: 1, minutes: 5);

        $this->travel(6)->minutes();

        $this->getJson($address)->assertForbidden();
    }

    public function test_the_stream_asks_again_whether_the_order_is_paid(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME);
        $address = $this->signedAddress(line: 1);
        DB::table('orders')->where('id', 1)->update(['payment_status' => 'unpaid']);

        $response = $this->getJson($address);

        $response->assertForbidden();
        $this->assertNoFile($response);
        $this->getJson($this->signedAddress(line: 2))->assertForbidden();
    }

    public function test_the_stream_answers_not_found_for_a_line_that_is_not_there_and_for_a_file_that_is_nowhere(): void
    {
        $this->useDigitalDisks(private: true);

        $this->getJson($this->signedAddress(line: 99))->assertNotFound();
        $this->getJson($this->signedAddress(line: 1))->assertNotFound();
    }

    // ---- the page the mail of a point of sale order links to --------------------------------------------------------------------------

    /** The addresses the page of the mail offers the buyer of an order, which the browser fetches when the buyer presses a button. */
    private function addressesOfThePage(int $order, string $email = 'buyer@example.test'): array
    {
        if (!defined('VIEW_FILE_NAMES')) {
            define('VIEW_FILE_NAMES', include resource_path('themes/default/file_names.php'));
        }
        View::getFinder()->prependLocation(base_path('tests/Support/views/storefront'));
        View::addLocation(resource_path('themes/default'));
        View::share('web_config', ['web_logo' => ['key' => 'logo', 'path' => '/logo.png', 'status' => 200], 'company_name' => 'Shop', 'meta_description' => 'A shop']);
        DB::table('products')->where('id', 5)->update(['slug' => 'guide']);

        $html = $this->get('/digital-product-download-pos?' . http_build_query(['order_id' => $order, 'email' => $email]))->assertOk()->getContent();
        preg_match_all('/data-file-path="([^"]*)"/', $html, $matches);

        return array_map('html_entity_decode', $matches[1]);
    }

    public function test_while_the_mode_is_off_the_page_of_the_mail_offers_the_public_addresses_as_always(): void
    {
        $this->useDigitalDisks(private: false);
        $this->putOnPublicDisk(self::NAME);

        $this->assertSame([storageLink('product/digital-product', self::NAME, 'public')['path']], $this->addressesOfThePage(order: 1));
        $this->assertSame([storageLink('product/digital-product', self::NAME, 'public')['path']], $this->addressesOfThePage(order: 3));
    }

    #[DataProvider('linesOfPaidOrders')]
    public function test_while_the_mode_is_on_the_page_of_the_mail_offers_signed_addresses_that_stream_the_file(int $line): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME);

        $addresses = $this->addressesOfThePage(order: $line);

        $this->assertCount(1, $addresses);
        $this->assertStringStartsWith("/digital-product-download-file/{$line}/" . self::NAME . '?', $addresses[0]);
        $this->assertStringNotContainsString('storage', $addresses[0]);
        $this->assertStreamed($this->fetch($addresses[0]));
    }

    public function test_the_page_of_the_mail_offers_no_address_to_a_visitor_who_does_not_know_the_email_of_the_buyer(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME);

        $this->assertSame([], $this->addressesOfThePage(order: 1, email: 'someone-else@example.test'));
    }

    public function test_the_page_of_the_mail_offers_no_address_for_an_order_that_is_not_paid(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPublicDisk(self::NAME);

        $addresses = $this->addressesOfThePage(order: 2);

        $this->assertSame([], array_values(array_filter($addresses)), 'a button without an address downloads nothing');
    }

    public function test_the_script_of_the_website_saves_a_fetched_file_under_the_name_in_the_path_and_not_under_the_query_of_a_signed_address(): void
    {
        foreach (['public/assets/front-end/js/custom.js', 'resources/assets/front-end/js/custom.js'] as $script) {
            $source = (string) file_get_contents(base_path($script));

            $this->assertStringContainsString('const filename = url.split("?")[0].split("/").pop();', $source, $script);
            $this->assertStringNotContainsString('url.substring(url.lastIndexOf("/") + 1)', $source, $script);
        }
    }

    // ---- the website: the buyers who get nothing --------------------------------------------------------------------------------------

    /** What the shop sends a visitor who is not the buyer of a paid order of a signed-in customer: the code to the buyer, and no address. */
    private function assertNoAddressWasGiven(TestResponse $response): void
    {
        $this->assertNull($response->json('file_path') ?: null);
        $this->assertNoFile($response);
    }

    public function test_another_customer_gets_no_address_for_the_order_of_the_buyer(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME);
        $this->actingAs(User::findOrFail(6), 'customer');
        $this->seedTheConfigurationThatSendsTheCode();

        $response = $this->askForTheAddressOf(line: 1);

        $response->assertOk()->assertJsonPath('status', 2);
        $this->assertNoAddressWasGiven($response);
    }

    public function test_a_visitor_who_is_not_signed_in_gets_no_address_for_the_order_of_a_customer(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME);
        $this->seedTheConfigurationThatSendsTheCode();

        $response = $this->askForTheAddressOf(line: 1);

        $response->assertOk()->assertJsonPath('status', 2);
        $this->assertNoAddressWasGiven($response);
    }

    /** No mail and no text message are set up, so the code cannot be sent and the shop says so: the answer the website always gave a visitor who is not the buyer. */
    private function seedTheConfigurationThatSendsTheCode(): void
    {
        if (!defined('VIEW_FILE_NAMES')) {
            define('VIEW_FILE_NAMES', include resource_path('themes/default/file_names.php'));
        }
        View::addLocation(resource_path('themes/default'));
        Schema::create('addon_settings', static function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key_name')->nullable();
            $table->string('settings_type')->nullable();
            $table->boolean('is_active')->default(false);
        });
        foreach (['mail_config', 'mail_config_sendgrid'] as $type) {
            DB::table('business_settings')->insert(['type' => $type, 'value' => json_encode(['status' => 0])]);
        }
        Cache::flush();
    }
}
