<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * GET /image-proxy fetches a public image for a page and answers it from the shop's own origin. The upstream picks the Content-Type, and a
 * page or an SVG answered from that origin runs script there, so only raster images pass, and the answer carries headers that stop it
 * being read as anything else.
 */
class ImageProxyTest extends TestCase
{
    private const PUBLIC_IMAGE = 'http://93.184.216.34/photo.png';

    private function proxy(string $url): \Illuminate\Testing\TestResponse
    {
        return $this->withoutMiddleware()->get('/image-proxy?url=' . urlencode($url));
    }

    /** @return array<string, array{0: string}> */
    public static function rasterTypes(): array
    {
        return [
            'png' => ['image/png'],
            'jpeg' => ['image/jpeg'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'a type with a parameter and capitals' => ['Image/PNG; charset=binary'],
        ];
    }

    #[DataProvider('rasterTypes')]
    public function test_a_raster_image_is_proxied_and_cannot_be_read_as_anything_else(string $contentType): void
    {
        Http::fake([self::PUBLIC_IMAGE => Http::response('image-bytes', 200, ['Content-Type' => $contentType])]);

        $response = $this->proxy(self::PUBLIC_IMAGE);

        $response->assertOk();
        $this->assertSame('image-bytes', $response->getContent());
        $this->assertSame(strtolower(explode(';', $contentType)[0]), $response->headers->get('Content-Type'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        $response->assertHeader('Content-Disposition', 'inline');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function pagesAnUpstreamCanSendAsAnImage(): array
    {
        return [
            'an svg' => ['image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(document.domain)"><script>alert(1)</script></svg>'],
            'an svg with a parameter' => ['image/svg+xml; charset=utf-8', '<svg onload="alert(1)"></svg>'],
            'an svg in capitals' => ['IMAGE/SVG+XML', '<svg onload="alert(1)"></svg>'],
            'html' => ['text/html', '<script>alert(1)</script>'],
            'xml' => ['application/xml', '<x/>'],
            'text' => ['text/plain', 'hello'],
            'a type that only starts like an image' => ['image/svg', '<svg/>'],
            'nothing' => ['', 'bytes'],
        ];
    }

    #[DataProvider('pagesAnUpstreamCanSendAsAnImage')]
    public function test_an_svg_a_page_or_anything_that_is_not_a_raster_image_is_refused(string $contentType, string $body): void
    {
        Http::fake([self::PUBLIC_IMAGE => Http::response($body, 200, $contentType === '' ? [] : ['Content-Type' => $contentType])]);

        $response = $this->proxy(self::PUBLIC_IMAGE);

        $response->assertStatus(415);
        $this->assertStringNotContainsString('script', $response->getContent());
    }

    public function test_a_private_destination_is_refused_before_anything_is_fetched(): void
    {
        Http::fake();

        foreach (['http://169.254.169.254/latest/meta-data/', 'http://127.0.0.1/x.png', 'http://10.0.0.5/x.png', 'file:///etc/passwd'] as $url) {
            $this->proxy($url)->assertStatus(403);
        }

        Http::assertNothingSent();
    }

    public function test_an_image_over_five_megabytes_is_refused(): void
    {
        Http::fake([self::PUBLIC_IMAGE => Http::response(str_repeat('a', 5 * 1024 * 1024 + 1), 200, ['Content-Type' => 'image/png'])]);

        $this->proxy(self::PUBLIC_IMAGE)->assertStatus(413);
    }

    public function test_a_missing_address_is_a_bad_request(): void
    {
        $this->withoutMiddleware()->get('/image-proxy')->assertStatus(400);
    }
}
