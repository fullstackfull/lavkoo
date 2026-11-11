<?php

namespace Tests\Feature\SalesChannels;

use App\Services\SalesChannels\Listings\ChannelProductImages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\TestCase;

/**
 * The shop's photographs on their way to a marketplace — and the one thing this must never do.
 *
 * `getStorageImages()`, which the whole admin uses, returns a PLACEHOLDER when an image is missing. That is right for a web
 * page and catastrophic for a channel payload: it would publish our grey placeholder PNG as somebody's product photograph, on
 * a real marketplace, and it would look deliberate. **So the first thing asserted here is that nothing falls back.**
 *
 * The rest is what a channel needs that a page never does. Trendyol's documentation says image URLs must be
 * "SSL sertifikalı \"https\" formatında", so an http store is refused by name. A host no marketplace can reach — localhost, a
 * bare IP, a reserved documentation or test domain — is refused too, and that rule is OURS rather than Trendyol's: without it a
 * staging install would create REAL listings whose pictures never load, with nothing on the screen to explain why.
 *
 * Each refusal is reported as ITSELF. "This product has no image" and "your store serves images over http" send a person to
 * two completely different places, and a single code for both would send them to the wrong one.
 */
class ChannelProductImagesTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use BuildsStockPools;

    private const PRODUCT = 410;

    private const PUBLIC_STORE = 'https://pharmacyksa.sa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->storeProduct(self::PRODUCT, provider: null);
        $this->storeIsServedAt(self::PUBLIC_STORE);
    }

    /**
     * The store's own public address.
     *
     * `config(['app.url' => ...])` alone is not enough: the URL generator takes its root when the application boots, so
     * `asset()` -- which `dynamicStorage()` uses, and which is the store's own answer for where a file is served from -- would
     * keep answering with the old one. Forcing the root is what a deployment does by having APP_URL set before boot.
     */
    private function storeIsServedAt(string $url): void
    {
        config(['app.url' => $url]);
        URL::forceRootUrl($url);
        URL::forceScheme(str_starts_with($url, 'https://') ? 'https' : 'http');
    }

    // ---- the store's own disk, which is the default deployment ----------------------------------------------------

    public function test_a_file_on_the_stores_disk_becomes_an_absolute_https_url(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('product/front-410.png', 'png-bytes');
        $this->images(['front-410.png']);

        $resolved = app(ChannelProductImages::class)->forProduct(self::PRODUCT);

        $this->assertNull($resolved['refusal']);
        $this->assertSame([self::PUBLIC_STORE . '/storage/product/front-410.png'], $resolved['urls']);
    }

    public function test_the_shops_own_lead_picture_comes_first(): void
    {
        // Trendyol does not document which image is primary, so a channel that takes the first as the main one should get the
        // picture the shop itself chose to lead with rather than whichever file was uploaded first.
        Storage::fake('public');
        Storage::disk('public')->put('product/thumbnail/thumb-410.png', 'png');
        Storage::disk('public')->put('product/front-410.png', 'png');
        $this->images(['front-410.png'], thumbnail: 'thumb-410.png');

        $resolved = app(ChannelProductImages::class)->forProduct(self::PRODUCT);

        $this->assertSame([
            self::PUBLIC_STORE . '/storage/product/thumbnail/thumb-410.png',
            self::PUBLIC_STORE . '/storage/product/front-410.png',
        ], $resolved['urls']);
    }

    public function test_a_name_whose_file_is_gone_is_left_out_rather_than_linked(): void
    {
        // **THE PLACEHOLDER PROBLEM IN ANOTHER COSTUME.** A name in the column whose file has been deleted would otherwise
        // become a URL the channel fetches and fails on -- a listing with a broken picture instead of none.
        Storage::fake('public');
        Storage::disk('public')->put('product/front-410.png', 'png');
        $this->images(['front-410.png', 'deleted-410.png']);

        $resolved = app(ChannelProductImages::class)->forProduct(self::PRODUCT);

        $this->assertSame([self::PUBLIC_STORE . '/storage/product/front-410.png'], $resolved['urls']);
    }

    public function test_a_product_whose_every_file_is_gone_is_refused_and_never_given_a_placeholder(): void
    {
        Storage::fake('public');
        $this->images(['gone-410.png']);

        $resolved = app(ChannelProductImages::class)->forProduct(self::PRODUCT);

        $this->assertSame([], $resolved['urls']);
        $this->assertSame(ChannelProductImages::NO_IMAGES, $resolved['refusal']);
        // The guarantee stated plainly: not one placeholder path reaches a channel.
        $this->assertStringNotContainsStringIgnoringCase('placeholder', implode(' ', $resolved['urls']));
    }

    // ---- what a channel needs and a page never does ---------------------------------------------------------------

    public function test_an_image_already_on_a_cdn_is_taken_as_it_is(): void
    {
        $this->images(['https://cdn.pharmacyksa.sa/front-410.png']);

        $resolved = app(ChannelProductImages::class)->forProduct(self::PRODUCT);

        $this->assertSame(['https://cdn.pharmacyksa.sa/front-410.png'], $resolved['urls']);
        $this->assertNull($resolved['refusal']);
    }

    public function test_a_store_served_over_http_is_refused_by_name(): void
    {
        // Trendyol: URLs must be "SSL sertifikalı \"https\" formatında". Sending an http URL would have the batch refused for a
        // reason a person then has to decipher from a batch result.
        $this->storeIsServedAt('http://pharmacyksa.sa');
        Storage::fake('public');
        Storage::disk('public')->put('product/front-410.png', 'png');
        $this->images(['front-410.png']);

        $resolved = app(ChannelProductImages::class)->forProduct(self::PRODUCT);

        $this->assertSame([], $resolved['urls']);
        $this->assertSame(ChannelProductImages::NOT_HTTPS, $resolved['refusal'], 'and not "no images": the product has them');
    }

    /**
     * @param string $host a host no marketplace on the internet can reach
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unreachableHosts')]
    public function test_a_host_no_marketplace_can_reach_is_refused_by_name(string $host): void
    {
        $this->storeIsServedAt($host);
        Storage::fake('public');
        Storage::disk('public')->put('product/front-410.png', 'png');
        $this->images(['front-410.png']);

        $resolved = app(ChannelProductImages::class)->forProduct(self::PRODUCT);

        $this->assertSame(ChannelProductImages::NOT_PUBLIC, $resolved['refusal']);
    }

    /** @return array<string, array{0: string}> */
    public static function unreachableHosts(): array
    {
        return [
            'this machine' => ['https://localhost'],
            'this machine by number' => ['https://127.0.0.1'],
            'any address' => ['https://0.0.0.0'],
            'a bare IP, which may be a private one' => ['https://10.0.0.7'],
            'a name reserved for local use' => ['https://shop.local'],
            'a name reserved for testing' => ['https://shop.test'],
            'a name reserved for documentation' => ['https://shop.example'],
        ];
    }

    public function test_no_more_images_are_offered_than_the_channel_takes(): void
    {
        // "Bir barkod için maksimum 8 adet görsel" -- a ninth would have the whole batch refused.
        Storage::fake('public');
        $names = [];
        foreach (range(1, 11) as $index) {
            $names[] = "shot-{$index}.png";
            Storage::disk('public')->put("product/shot-{$index}.png", 'png');
        }
        $this->images($names);

        $resolved = app(ChannelProductImages::class)->forProduct(self::PRODUCT);

        $this->assertCount(8, $resolved['urls']);
    }

    public function test_the_same_file_named_twice_is_offered_once(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('product/front-410.png', 'png');
        $this->images(['front-410.png', 'front-410.png']);

        $this->assertCount(1, app(ChannelProductImages::class)->forProduct(self::PRODUCT)['urls']);
    }

    public function test_a_product_nobody_has_is_refused_rather_than_resolved(): void
    {
        $this->assertSame(ChannelProductImages::NO_IMAGES, app(ChannelProductImages::class)->forProduct(999_999)['refusal']);
    }

    public function test_a_bare_string_column_is_read_as_one_image(): void
    {
        // The column has held two shapes over the years, and both have to be read: a product that passed its own readiness
        // check and were then refused here would be a product nothing could explain.
        DB::table('products')->where('id', self::PRODUCT)->update(['images' => 'https://cdn.pharmacyksa.sa/single-410.png', 'thumbnail' => null]);

        $this->assertSame(['https://cdn.pharmacyksa.sa/single-410.png'], app(ChannelProductImages::class)->forProduct(self::PRODUCT)['urls']);
    }

    /** @param list<string> $names */
    private function images(array $names, ?string $thumbnail = null): void
    {
        DB::table('products')->where('id', self::PRODUCT)->update([
            'thumbnail' => $thumbnail,
            'images' => json_encode(array_map(static fn (string $name): array => ['image_name' => $name, 'storage' => 'public'], $names)),
        ]);
    }
}
