<?php

namespace App\Services\Fulfillment\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Downloads a product image that a PROVIDER's payload points at.
 *
 * The URL comes from a third party, so it is treated as untrusted: https only, no credentials in it, the
 * host must resolve to public addresses only (no loopback, private or link-local ranges — the server-side
 * request forgery guard) and the connection is pinned to the address that was checked, so a host that answers
 * differently the second time cannot redirect the request inwards. A short timeout, no redirects, a size cap, and
 * the body must really be a JPEG, PNG, GIF or WebP: the type is read from the bytes, never from the response's
 * claim, and SVG is refused outright because a stored SVG can carry script that runs when it is opened.
 * Any failure yields null; an image that cannot be fetched never blocks a product import.
 */
class RemoteImageFetcher
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** @var array<int, array{0: string, 1: string}> detected image type => [extension, mime] */
    private const ACCEPTED_TYPES = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_GIF => ['gif', 'image/gif'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    public function fetch(string $url): ?UploadedFile
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || empty($parts['host'])) {
            return null;
        }

        $addresses = $this->publicAddresses($parts['host']);
        if ($addresses === []) {
            return null;
        }

        try {
            $response = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()
                ->withOptions([
                    'curl' => [CURLOPT_RESOLVE => [$parts['host'] . ':' . ($parts['port'] ?? 443) . ':' . $addresses[0]]],
                    'on_headers' => static function (ResponseInterface $response): void {
                        if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                            throw new RuntimeException('The image is larger than the limit.');
                        }
                    },
                ])
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        $body = $response->body();
        if (!$response->successful() || $body === '' || strlen($body) > self::MAX_BYTES) {
            return null;
        }

        $detected = @getimagesizefromstring($body);
        $accepted = $detected === false ? null : (self::ACCEPTED_TYPES[$detected[2]] ?? null);
        if ($accepted === null) {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'prov');
        file_put_contents($path, $body);

        return new UploadedFile($path, 'provider-image.' . $accepted[0], $accepted[1], null, true);
    }

    /** @return list<string> the host's addresses, or none when ANY of them is not a public address */
    private function publicAddresses(string $host): array
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return [];
            }
        }

        return array_values($addresses);
    }
}
