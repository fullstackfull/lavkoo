<?php

namespace App\Services\SalesChannels\Listings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The store's own product photographs, as URLs a channel can fetch — or a named refusal, never a guess.
 *
 * ## Why this is not `getStorageImages()`
 *
 * That helper exists for a web page, and there it does the right thing: when an image is missing it returns a PLACEHOLDER so
 * the page shows something rather than a broken frame. Handing the same helper a channel payload would publish our grey
 * placeholder PNG as somebody's product photograph, on a real marketplace, and it would look deliberate. **So nothing here
 * falls back.** A product whose images cannot be resolved is refused by name and its listing stays a draft.
 *
 * The URL itself comes from the store's own `dynamicStorage()`, because where a file is served from depends on whether the
 * domain points at `public/` and that is the store's answer to give, not ours to restate. What is added here is the checking.
 *
 * ## A channel fetches the URL, which a page never has to care about
 *
 * Trendyol's documentation says image URLs must be "SSL sertifikalı \"https\" formatında" — https with a valid certificate.
 * So two things are refused before anything is sent:
 *
 *  - a URL that is not https, because the channel has said it will not take one;
 *  - a host that cannot be reached from outside (localhost, a bare IP, a `.local`/`.test` name), because a staging install
 *    would otherwise create REAL listings whose photographs never load. No documentation states that rule, so it is OURS and
 *    is named as such: the alternative is a live listing with broken images and nothing to explain why.
 *
 * Nothing here fetches anything. Whether the channel can really reach a URL only the channel can know; these are the cases
 * visible without asking, and each is reported as itself rather than as "no images".
 */
class ChannelProductImages
{
    /** The product has no image file we could resolve at all. */
    public const NO_IMAGES = 'product_has_no_usable_image';

    /** Images exist, and the store serves them over http. A channel that requires https would refuse them. */
    public const NOT_HTTPS = 'store_serves_images_over_http_and_the_channel_requires_https';

    /** Images exist, and the store's host is not one a marketplace can reach. */
    public const NOT_PUBLIC = 'store_host_is_not_reachable_from_outside_so_images_would_be_broken';

    /** Where a product's own images and its thumbnail live under the public disk. */
    private const IMAGE_PREFIX = 'product/';

    private const THUMBNAIL_PREFIX = 'product/thumbnail/';

    /** Hosts that mean "this machine" by definition, so this is not a guess about anybody's network. */
    private const PRIVATE_HOSTS = ['localhost', '0.0.0.0', 'host.docker.internal'];

    /** Suffixes reserved for local and documentation use, so a name ending in one is never a public site. */
    private const PRIVATE_SUFFIXES = ['.local', '.localhost', '.test', '.invalid', '.example'];

    /**
     * The absolute https URLs of one product's images, the shop's own lead picture first.
     *
     * @param int $limit how many the channel accepts (Trendyol documents a maximum of 8 per barcode)
     * @return array{urls: list<string>, refusal: string|null}
     */
    public function forProduct(int $productId, int $limit = 8): array
    {
        $product = DB::table('products')->where('id', $productId)->first(['id', 'images', 'thumbnail']);
        if ($product === null) {
            return ['urls' => [], 'refusal' => self::NO_IMAGES];
        }

        $candidates = [
            // The thumbnail leads where there is one. Trendyol does not document which image is primary, so a channel that
            // takes the first as the main one gets the picture the shop itself chose to lead with rather than whichever was
            // uploaded first.
            ...array_map(static fn (string $path): array => [$path, self::THUMBNAIL_PREFIX], $this->pathsOf($product->thumbnail ?? null)),
            ...array_map(static fn (string $path): array => [$path, self::IMAGE_PREFIX], $this->pathsOf($product->images ?? null)),
        ];

        $urls = [];
        $refusals = [];
        foreach ($candidates as [$path, $prefix]) {
            $resolved = $this->resolve($path, $prefix);
            if ($resolved['url'] === null) {
                if ($resolved['refusal'] !== null) {
                    $refusals[$resolved['refusal']] = true;
                }

                continue;
            }
            if (!in_array($resolved['url'], $urls, true)) {
                $urls[] = $resolved['url'];
            }
            if (count($urls) >= max(1, $limit)) {
                break;
            }
        }

        if ($urls !== []) {
            return ['urls' => $urls, 'refusal' => null];
        }

        // The product HAS images and not one of them can be sent: say which wall was hit, because "no usable image" would send
        // somebody looking at the product when the answer is the store's own URL.
        foreach ([self::NOT_HTTPS, self::NOT_PUBLIC] as $reason) {
            if (isset($refusals[$reason])) {
                return ['urls' => [], 'refusal' => $reason];
            }
        }

        return ['urls' => [], 'refusal' => self::NO_IMAGES];
    }

    /**
     * One stored name as a URL a channel may be given.
     *
     * A name that is already an absolute URL is taken as it is (an image on a CDN is the normal case for a shop of any size)
     * and still checked. A stored file is checked to EXIST before its URL is built: a name whose file is gone would otherwise
     * become a link the channel fetches and fails on, which is the placeholder problem in another costume.
     *
     * @return array{url: string|null, refusal: string|null}
     */
    private function resolve(string $path, string $prefix): array
    {
        if (preg_match('#^https?://#i', $path) === 1) {
            return $this->checked($path);
        }

        $relative = $prefix . ltrim($path, '/');
        try {
            if (!Storage::disk('public')->exists($relative)) {
                return ['url' => null, 'refusal' => null];
            }
        } catch (Throwable) {
            // A disk that cannot be asked is not a file that exists. Refusing is the only safe reading of it.
            return ['url' => null, 'refusal' => null];
        }

        // The store's own answer for where a stored file is served from, which depends on how the domain is pointed.
        return $this->checked(dynamicStorage('storage/app/public/' . $relative));
    }

    /**
     * Is this a URL a marketplace could fetch?
     *
     * @return array{url: string|null, refusal: string|null}
     */
    private function checked(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return ['url' => null, 'refusal' => self::NOT_PUBLIC];
        }
        if ($scheme !== 'https') {
            return ['url' => null, 'refusal' => self::NOT_HTTPS];
        }
        if (in_array($host, self::PRIVATE_HOSTS, true) || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ['url' => null, 'refusal' => self::NOT_PUBLIC];
        }
        foreach (self::PRIVATE_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return ['url' => null, 'refusal' => self::NOT_PUBLIC];
            }
        }

        return ['url' => $url, 'refusal' => null];
    }

    /**
     * The image file names a product column holds.
     *
     * The column is JSON in this store and its entries have had two shapes over the years (`{"image_name": ...}` and a bare
     * string), so both are read — the same two `ProductListingReadiness` reads, because the two have to agree about what counts
     * as an image or a product would pass its own readiness check and then be refused here.
     *
     * @return list<string>
     */
    private function pathsOf(mixed $column): array
    {
        $text = trim((string) $column);
        if ($text === '') {
            return [];
        }
        if (!str_starts_with($text, '[') && !str_starts_with($text, '{')) {
            return [$text];
        }

        $decoded = json_decode($text, true);
        $paths = [];
        foreach (is_array($decoded) ? $decoded : [] as $entry) {
            $image = is_array($entry) ? ($entry['image_name'] ?? $entry['image'] ?? null) : $entry;
            if (is_string($image) && trim($image) !== '') {
                $paths[] = trim($image);
            }
        }

        return $paths;
    }
}
