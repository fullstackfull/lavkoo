<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\BuildsDigitalFileWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The apps of the shop get a digital file from the route that checked them: the code the buyer received, or the signed-in buyer of a paid order. That route
 * streams the file, so it reads it through DigitalProductFileService (the private disk first, then the public one) and never builds an address.
 */
class DigitalFilesApiDownloadTest extends TestCase
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
        RateLimiter::clear('digital-product-download-otp:1');
        DB::table('users')->insert([
            ['id' => 5, 'f_name' => 'Buyer', 'email' => 'buyer@example.test', 'phone' => '0555000005'],
            ['id' => 6, 'f_name' => 'Other', 'email' => 'other@example.test', 'phone' => '0555000006'],
        ]);
        DB::table('products')->insert(['id' => 5, 'name' => 'Guide', 'product_type' => 'digital', 'digital_product_type' => 'ready_product', 'digital_file_ready' => self::NAME, 'thumbnail' => 't.webp', 'status' => 1]);
        $this->orderLine(id: 1);
        $this->orderLine(id: 2, payment: 'unpaid');
        DB::table('digital_product_otp_verifications')->insert(['order_details_id' => '1', 'identity' => 'buyer@example.test', 'token' => self::OTP, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->removeDigitalDisks();
        RateLimiter::clear('digital-product-download-otp:1');
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function orderLine(int $id, string $payment = 'paid'): void
    {
        DB::table('orders')->insert(['id' => $id, 'customer_id' => 5, 'is_guest' => 0, 'payment_status' => $payment]);
        DB::table('order_details')->insert(['id' => $id, 'order_id' => $id, 'product_id' => 5, 'payment_status' => $payment, 'product_details' => json_encode(['product_type' => 'digital', 'digital_product_type' => 'ready_product', 'digital_file_ready' => self::NAME])]);
    }

    /** The code of the buyer, sent with the guest id the app keeps for a visitor who is not signed in, through the middleware of the route. */
    private function verifyTheCode(string $code = self::OTP, int $line = 1, array $extra = ['action' => 'download']): TestResponse
    {
        return $this->getJson('/api/v1/customer/order/digital-product-download-otp-verify?' . http_build_query(['guest_id' => 1, 'otp' => $code, 'order_details_id' => $line] + $extra));
    }

    private function downloadAsTheCustomer(int $line): TestResponse
    {
        return $this->getJson("/api/v1/customer/order/digital-product-download/{$line}?guest_id=1");
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

    // ---- the code of the buyer ---------------------------------------------------------------------------------------------------------

    public function test_while_the_mode_is_on_the_code_downloads_a_file_of_the_private_disk(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME, 'a private file');

        $response = $this->verifyTheCode();

        $this->assertStreamed($response, content: 'a private file');
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(0, DB::table('digital_product_otp_verifications')->count(), 'a download spends the code');
    }

    public function test_while_the_mode_is_on_the_code_downloads_a_file_that_is_still_on_the_public_disk(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPublicDisk(self::NAME, 'stored before the switch');

        $this->assertStreamed($this->verifyTheCode(), content: 'stored before the switch');
    }

    public function test_while_the_mode_is_on_the_private_copy_is_the_one_that_is_delivered(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME, 'the private copy');
        $this->putOnPublicDisk(self::NAME, 'the public copy');

        $this->assertStreamed($this->verifyTheCode(), content: 'the private copy');
    }

    public function test_while_the_mode_is_off_the_code_downloads_the_file_of_the_public_disk_as_always(): void
    {
        $this->useDigitalDisks(private: false);
        $this->putOnPublicDisk(self::NAME);
        $this->putOnPrivateDisk(self::NAME, 'not read while the mode is off');

        $response = $this->verifyTheCode();

        $this->assertStreamed($response);
        $this->assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'), 'the headers of an answer are the ones it always had');
    }

    public function test_while_the_mode_is_off_a_file_that_is_only_on_the_private_disk_is_not_found(): void
    {
        $this->useDigitalDisks(private: false);
        $this->putOnPrivateDisk(self::NAME);

        $this->verifyTheCode()->assertJsonPath('status', 0)->assertJsonPath('message', translate('file_not_found'));
    }

    public function test_an_order_that_is_not_paid_downloads_nothing_even_with_the_right_code_and_a_file_on_the_public_disk(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPublicDisk(self::NAME);
        DB::table('orders')->where('id', 1)->update(['payment_status' => 'unpaid']);

        $response = $this->verifyTheCode();

        $response->assertJsonPath('status', 0);
        $this->assertNoFile($response);
        $this->assertSame(1, DB::table('digital_product_otp_verifications')->count(), 'the code is not spent');
    }

    public function test_a_wrong_code_downloads_nothing(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME);

        $response = $this->verifyTheCode(code: '0000');

        $response->assertForbidden();
        $this->assertNoFile($response);
    }

    public function test_the_code_of_one_order_line_downloads_nothing_of_another(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME);

        $response = $this->verifyTheCode(line: 2);

        $response->assertForbidden();
        $this->assertNoFile($response);
    }

    public function test_a_caller_with_neither_a_token_nor_a_guest_id_downloads_nothing(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME);

        $response = $this->getJson('/api/v1/customer/order/digital-product-download-otp-verify?' . http_build_query(['otp' => self::OTP, 'order_details_id' => 1, 'action' => 'download']));

        $response->assertUnauthorized();
        $this->assertNoFile($response);
        $this->getJson('/api/v1/customer/order/digital-product-download/1')->assertUnauthorized();
    }

    // ---- the signed-in buyer -----------------------------------------------------------------------------------------------------------

    public function test_while_the_mode_is_on_the_signed_in_buyer_of_a_paid_order_downloads_the_file_from_the_route_that_knows_them(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME, 'a private file');
        $this->actingAs(User::findOrFail(5), 'customer');

        $this->assertStreamed($this->downloadAsTheCustomer(line: 1), content: 'a private file');
    }

    public function test_while_the_mode_is_on_a_file_that_is_still_on_the_public_disk_is_delivered_to_the_signed_in_buyer_too(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPublicDisk(self::NAME, 'stored before the switch');
        $this->actingAs(User::findOrFail(5), 'customer');

        $this->assertStreamed($this->downloadAsTheCustomer(line: 1), content: 'stored before the switch');
    }

    public function test_the_signed_in_buyer_of_an_order_that_is_not_paid_downloads_nothing(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME);
        $this->actingAs(User::findOrFail(5), 'customer');

        $response = $this->downloadAsTheCustomer(line: 2);

        $response->assertJsonPath('status', 0);
        $this->assertNoFile($response);
    }

    /** No mail and no text message are set up, so the code cannot be sent: what the app is told when the visitor is not the buyer. */
    private function seedTheConfigurationThatCannotSendTheCode(): void
    {
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

    public function test_another_customer_and_a_visitor_get_no_file_for_the_order_of_the_buyer(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::NAME);
        $this->seedTheConfigurationThatCannotSendTheCode();

        $this->actingAs(User::findOrFail(6), 'customer');
        $another = $this->downloadAsTheCustomer(line: 1);
        Auth::forgetGuards();
        $visitor = $this->downloadAsTheCustomer(line: 1);

        foreach ([$another, $visitor] as $response) {
            $response->assertStatus(403)->assertJsonPath('status', 0);
            $this->assertNoFile($response);
        }
    }
}
