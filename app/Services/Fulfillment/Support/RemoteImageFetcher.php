<?php

namespace App\Services\Fulfillment\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Downloads a product image that a PROVIDER's payload points at.
 *
 * The URL comes from a third party, so it is treated as untrusted: https only, no credentials in it, the
 * host must resolve to public addresses only (no loopback, private or link-local ranges — the server-side
 * request forgery guard), a short timeout, no redirects, a size cap, and the body must really be an image.
 * Any failure yields null; an image that cannot be fetched never blocks a product import.
 */
class RemoteImageFetcher
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function fetch(string $url): ?UploadedFile
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || empty($parts['host'])) {
            return null;
        }
        if (!$this->hostIsPublic($parts['host'])) {
            return null;
        }

        try {
            $response = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()->get($url);
        } catch (Throwable) {
            return null;
        }

        $body = $response->body();
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if (!$response->successful() || strlen($body) === 0 || strlen($body) > self::MAX_BYTES || !str_starts_with($mime, 'image/')) {
            return null;
        }
        if (@getimagesizefromstring($body) === false && $mime !== 'image/svg+xml') {
            return null;
        }

        $extension = match ($mime) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            default => 'jpg',
        };
        $path = tempnam(sys_get_temp_dir(), 'prov');
        file_put_contents($path, $body);

        return new UploadedFile($path, 'provider-image.' . $extension, $mime, null, true);
    }

    private function hostIsPublic(string $host): bool
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }
}
