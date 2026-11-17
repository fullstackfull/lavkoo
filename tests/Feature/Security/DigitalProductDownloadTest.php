<?php

namespace Tests\Feature\Security;

use App\Utils\PlainFileName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A digital product is downloaded with the code the shop sent to the buyer.
 *
 * The name of the file comes from the product row, and a seller wrote it: the seller APIs stored whatever string they were sent. The download
 * joined it to the digital-product directory with the file system's own functions, so `../../../../../.env` made an anonymous request download
 * the shop's secrets (the code is four digits, and nothing counted wrong ones). The download also never asked whether the order was paid, and
 * the code could be requested for any order line, which mails it to its buyer.
 */
class DigitalProductDownloadTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private const OTP = '4821';

    /** @var list<string> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
        });
        Schema::create('digital_product_otp_verifications', function ($table) {
            $table->id();
            $table->string('order_details_id')->nullable();
            $table->string('identity')->nullable();
            $table->string('token')->nullable();
            $table->tinyInteger('otp_hit_count')->default(0);
            $table->boolean('is_temp_blocked')->default(false);
            $table->timestamp('temp_block_time')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        RateLimiter::clear('digital-product-download-otp:1');
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->createdFiles, 'file_exists'));
        RateLimiter::clear('digital-product-download-otp:1');
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function fileOnDisk(string $path, string $content): string
    {
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
        $this->createdFiles[] = $path;

        return $path;
    }

    /** A paid order line for a ready digital product whose file name is $storedName, with the code the buyer received. */
    private function orderLineOf(string $storedName, string $paymentStatus = 'paid'): void
    {
        DB::table('products')->insert(['id' => 1, 'name' => 'Guide', 'product_type' => 'digital', 'digital_product_type' => 'ready_product', 'digital_file_ready' => $storedName]);
        DB::table('orders')->insert(['id' => 1, 'customer_id' => null, 'is_guest' => 1, 'payment_status' => $paymentStatus]);
        DB::table('order_details')->insert(['id' => 1, 'order_id' => 1, 'product_id' => 1, 'payment_status' => $paymentStatus]);
        DB::table('digital_product_otp_verifications')->insert(['order_details_id' => '1', 'identity' => 'buyer@example.test', 'token' => self::OTP, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function download(string $otp = self::OTP, array $extra = []): TestResponse
    {
        return $this->withoutMiddleware()->getJson('/api/v1/customer/order/digital-product-download-otp-verify?' . http_build_query(['guest_id' => 1, 'otp' => $otp, 'order_details_id' => 1] + $extra));
    }

    private function directory(): string
    {
        return storage_path('app/public/product/digital-product');
    }

    public function test_the_buyer_downloads_the_file_with_the_code_they_received(): void
    {
        $this->fileOnDisk($this->directory() . '/guide.pdf', 'the guide');
        $this->orderLineOf('guide.pdf');

        $response = $this->download(extra: ['action' => 'download']);

        $response->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertSame('the guide', file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->assertSame(0, DB::table('digital_product_otp_verifications')->count(), 'a download spends the code');
    }

    /** @return array<string, array{0: string}> */
    public static function namesThatLeaveTheDirectory(): array
    {
        return [
            'the project root, five levels up' => ['../../../../../canary-secret.txt'],
            'a file next to the uploads' => ['../canary-secret.txt'],
            'an absolute path' => ['/etc/hostname'],
            'a windows path' => ['..\\..\\canary-secret.txt'],
            'a name that hides the way up behind a directory' => ['guides/../../canary-secret.txt'],
            'only the way up' => ['..'],
            'a name that ends the path early' => ["canary-secret.txt\0.pdf"],
        ];
    }

    #[DataProvider('namesThatLeaveTheDirectory')]
    public function test_a_name_that_leaves_the_directory_downloads_nothing(string $storedName): void
    {
        $this->fileOnDisk(base_path('canary-secret.txt'), 'APP_KEY=base64:canary');
        $this->orderLineOf($storedName);

        $response = $this->download(extra: ['action' => 'download']);

        $this->assertNotInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertStringNotContainsString('canary', $response->getContent());
        $response->assertJsonPath('status', 0);
    }

    public function test_an_order_that_is_not_paid_downloads_nothing_even_with_the_right_code(): void
    {
        $this->fileOnDisk($this->directory() . '/guide.pdf', 'the guide');
        $this->orderLineOf('guide.pdf', paymentStatus: 'unpaid');

        $response = $this->download(extra: ['action' => 'download']);

        $this->assertNotInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $response->assertJsonPath('status', 0);
        $this->assertSame(1, DB::table('digital_product_otp_verifications')->count(), 'the code is not spent');
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $this->fileOnDisk($this->directory() . '/guide.pdf', 'the guide');
        $this->orderLineOf('guide.pdf');

        $this->download(otp: '0000')->assertStatus(403);
    }

    public function test_the_fifth_wrong_code_locks_the_order_line_and_the_right_code_is_refused_while_it_is_locked(): void
    {
        $this->fileOnDisk($this->directory() . '/guide.pdf', 'the guide');
        $this->orderLineOf('guide.pdf');

        foreach (range(1, 5) as $attempt) {
            $this->download(otp: sprintf('%04d', $attempt))->assertStatus(403);
        }

        $locked = $this->download(extra: ['action' => 'download']);

        $locked->assertStatus(429);
        $this->assertNotInstanceOf(BinaryFileResponse::class, $locked->baseResponse);
    }

    public function test_a_few_wrong_codes_do_not_stop_the_buyer_who_then_types_the_right_one(): void
    {
        $this->fileOnDisk($this->directory() . '/guide.pdf', 'the guide');
        $this->orderLineOf('guide.pdf');

        foreach (range(1, 4) as $attempt) {
            $this->download(otp: sprintf('%04d', $attempt))->assertStatus(403);
        }

        $this->assertInstanceOf(BinaryFileResponse::class, $this->download(extra: ['action' => 'download'])->baseResponse);
        RateLimiter::clear('digital-product-download-otp:1');
    }

    public function test_a_list_where_the_app_sends_a_code_is_refused_and_is_no_server_error(): void
    {
        $this->orderLineOf('guide.pdf');

        $this->withoutMiddleware()->getJson('/api/v1/customer/order/digital-product-download-otp-verify?guest_id=1&otp[]=1&order_details_id=1')->assertStatus(403);
        $this->withoutMiddleware()->getJson('/api/v1/customer/order/digital-product-download-otp-verify?guest_id=1&otp=' . self::OTP . '&order_details_id[]=1')->assertStatus(403);
    }

    public function test_a_code_is_not_sent_for_an_order_that_is_not_paid_and_nobody_is_mailed(): void
    {
        $this->orderLineOf('guide.pdf', paymentStatus: 'unpaid');
        DB::table('digital_product_otp_verifications')->delete();

        $response = $this->withoutMiddleware()->postJson('/api/v1/customer/order/digital-product-download-otp-resend', ['guest_id' => 1, 'order_details_id' => 1]);

        $response->assertJsonPath('status', 0);
        $this->assertSame(0, DB::table('digital_product_otp_verifications')->count());
    }

    public function test_a_code_is_not_sent_for_an_order_line_that_does_not_exist(): void
    {
        $response = $this->withoutMiddleware()->postJson('/api/v1/customer/order/digital-product-download-otp-resend', ['guest_id' => 1, 'order_details_id' => 999]);

        $response->assertOk()->assertJsonPath('status', 0);
        $this->assertSame(0, DB::table('digital_product_otp_verifications')->count());
    }

    // ---- the rule for a stored name ---------------------------------------------------------------------------------------------

    public function test_the_names_the_platform_writes_are_plain_and_nothing_else_is(): void
    {
        foreach (['2026-11-16-6afb27ee9e4cf.pdf', 'def.png', 'a b.pdf', 'file.name.with.dots.zip', 'a..b.zip'] as $name) {
            $this->assertTrue(PlainFileName::isPlain($name), $name);
            $this->assertSame($name, PlainFileName::of($name));
        }

        foreach ([null, '', '.', '..', '../x', 'a/b', '/etc/passwd', 'a\\b', "a\0b", "a\nb", str_repeat('a', 256), ['a'], 5, false] as $notAName) {
            $this->assertFalse(PlainFileName::isPlain($notAName), var_export($notAName, true));
            $this->assertNull(PlainFileName::of($notAName));
        }
    }

    public function test_a_path_that_goes_up_does_not_stay_inside_the_disk(): void
    {
        foreach (['product/digital-product/a.pdf', 'a.pdf', 'product/..a/b.pdf', 'product/a..b/c.pdf', '/product/a.pdf'] as $path) {
            $this->assertTrue(PlainFileName::staysInside($path), $path);
        }

        foreach (['../a.pdf', 'product/../shop/a.pdf', 'product/digital-product/..', 'product\\..\\shop\\a.pdf', "product/a\0.pdf"] as $path) {
            $this->assertFalse(PlainFileName::staysInside($path), $path);
        }
    }
}
