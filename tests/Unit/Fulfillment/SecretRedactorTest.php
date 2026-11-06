<?php

namespace Tests\Unit\Fulfillment;

use App\Services\Fulfillment\Support\SecretRedactor;
use PHPUnit\Framework\TestCase;

class SecretRedactorTest extends TestCase
{
    /**
     * Made at run time, so that no line of this file looks like the thing it exercises (a repository scanner for credentials reads
     * source lines): a private key block, and a URL with a user and a password in it.
     */
    private static function keyBlock(string $label, string $body, bool $closed): string
    {
        $end = $closed ? "\n-----" . 'END ' . $label . '-----' : '';

        return '-----' . 'BEGIN ' . $label . '-----' . "\n" . $body . $end;
    }

    private static function urlWithPassword(string $user, string $password): string
    {
        return 'https' . '://' . $user . ':' . $password . '@example.test/api';
    }

    /** @return array<string, array{string, string}> text, and the secret that must not be left in it */
    public static function credentialsWrittenIntoText(): array
    {
        return [
            'a basic credential in a header' => ['Authorization: Basic dXNlcjpwYXNzd29yZA==', 'dXNlcjpwYXNzd29yZA'],
            'a basic credential on its own' => ['sent Basic dXNlcjpwYXNzd29yZA== to the gateway', 'dXNlcjpwYXNzd29yZA'],
            'a token credential' => ['rejected Token abc123def456ghi789jkl', 'abc123def456ghi789jkl'],
            'a digest credential' => ['rejected Digest 0123456789abcdef0123', '0123456789abcdef0123'],
            'a bearer credential' => ['rejected Bearer abc.def-123456', 'abc.def-123456'],
            'an api token' => ['failed: api_token=s3cr3tvalue99', 's3cr3tvalue99'],
            'an access token with a prefix' => ['my_access_token: s3cr3tvalue99', 's3cr3tvalue99'],
            'a client secret' => ['client_secret=s3cr3tvalue99', 's3cr3tvalue99'],
            'an upper-case client secret' => ['CLIENT_SECRET=s3cr3tvalue99', 's3cr3tvalue99'],
            'a database password' => ['DB_PASSWORD=s3cr3tvalue99', 's3cr3tvalue99'],
            'an application key' => ['APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'],
            'a private key setting' => ['private_key=s3cr3tvalue99', 's3cr3tvalue99'],
            'a key in a query string' => ['GET /orders?page=2&key=s3cr3tvalue99&sort=id', 's3cr3tvalue99'],
            'a session id' => ['PHPSESSID=s3cr3tvalue99', 's3cr3tvalue99'],
            'a password in a url' => ['fetching ' . self::urlWithPassword('admin', 's3cr3tvalue99'), 's3cr3tvalue99'],
            'a private key block' => [self::keyBlock('RSA PRIVATE KEY', 'MIIEowIBAAKCAQEAs3cr3tvalue99', closed: true), 's3cr3tvalue99'],
            'a private key block cut short' => [self::keyBlock('PRIVATE KEY', 'MIIEowIBAAKCAQEAs3cr3tvalue99', closed: false), 's3cr3tvalue99'],
            'a private key block after the name of a private key' => ['private_key=' . self::keyBlock('PRIVATE KEY', "MIIEvQIBADANs3cr3tvalue99\nabcDEF+/xyz==", closed: true) . ' and more', 's3cr3tvalue99'],
            'a private key block after the name of a secret' => ['secret: ' . self::keyBlock('PRIVATE KEY', 'MIIEowIBAAKCAQEAs3cr3tvalue99', closed: true), 's3cr3tvalue99'],
            'a private key block in a json string' => [(string) json_encode(['private_key' => self::keyBlock('PRIVATE KEY', "MIIEvQIBADANs3cr3tvalue99\nabcDEF+/xyz==", closed: true), 'other' => 'x']), 's3cr3tvalue99'],
            'a private key block in a json string with escaped slashes and letters' => [(string) json_encode(['key' => self::keyBlock('PRIVATE KEY', "MIIEvQIB/s3cr3tvalue99/abc+def==", closed: true)], JSON_HEX_TAG | JSON_HEX_AMP), 's3cr3tvalue99'],
            'a pgp private key block' => [self::keyBlock('PGP PRIVATE KEY BLOCK', 'lQVYBFs3cr3tvalue99', closed: true), 's3cr3tvalue99'],
            'a private key block longer than eight thousand characters' => [self::keyBlock('RSA PRIVATE KEY', str_repeat('QUJDREVGR0g', 1500) . 's3cr3tvalue99', closed: false), 's3cr3tvalue99'],
            'a base64 credential with no digit, sign or padding' => ['request failed for Basic dXNlcjpwYXNz at host', 'dXNlcjpwYXNz'],
            'a long lower-case basic credential' => ['sent Basic abcdefghijklmnop to host', 'abcdefghijklmnop'],
            'a long lower-case token' => ['header was Token abcdefghijklmnopqrstuv', 'abcdefghijklmnopqrstuv'],
            'a token of eighteen letters' => ['Token abcdefabcdefabcdef', 'abcdefabcdefabcdef'],
        ];
    }

    /** @dataProvider credentialsWrittenIntoText */
    #[\PHPUnit\Framework\Attributes\DataProvider('credentialsWrittenIntoText')]
    public function test_a_credential_written_into_text_is_removed_in_the_forms_people_and_frameworks_write_it(string $text, string $secret): void
    {
        $this->assertStringNotContainsString($secret, SecretRedactor::redactString($text));
    }

    /** @return array<string, array{string}> */
    public static function wordsThatOnlyLookLikeCredentials(): array
    {
        return [
            ['Basic information about the order'], ['Token generation failed'], ['{"name":"Token Pharma 500mg"}'], ['Basic Care Cream 250ml'],
            ['Digest of the day'], ['the token was refreshed'], ['password reset requested'],
            ['Basic Moisturizing lotion'], ['Digest compatibility check'], ['Basic Care Cream'], ['Token Pharma'], ['Basic hydration routine for dry skin'],
        ];
    }

    /** @dataProvider wordsThatOnlyLookLikeCredentials */
    #[\PHPUnit\Framework\Attributes\DataProvider('wordsThatOnlyLookLikeCredentials')]
    public function test_ordinary_text_that_starts_with_the_name_of_a_credential_is_left_alone(string $text): void
    {
        $this->assertSame($text, SecretRedactor::redactString($text));
    }

    public function test_a_header_line_inside_one_value_does_not_swallow_the_rest_of_the_document(): void
    {
        $redacted = SecretRedactor::redact(['message' => 'Cookie: consent required', 'data' => [['id' => 1, 'name' => 'Basic Care Cream', 'sku' => 'BC']], 'barcode' => 'O001']);

        $this->assertSame('Cookie: ***', $redacted['message']);
        $this->assertSame('Basic Care Cream', $redacted['data'][0]['name']);
        $this->assertSame('O001', $redacted['barcode']);
        $this->assertJson((string) json_encode($redacted));
    }

    public function test_hostile_text_is_scanned_in_bounded_time_even_without_the_regex_jit(): void
    {
        $jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');

        try {
            $started = hrtime(true);
            SecretRedactor::redactString(str_repeat('a_', 10000));
            SecretRedactor::redactString(str_repeat('a-', 10000) . 'token');
            SecretRedactor::redactString(str_repeat('Basic aaaaaaaaaaaa', 1000));
            SecretRedactor::redactString(self::keyBlock('PRIVATE KEY', str_repeat('A', 30000), closed: false));
            SecretRedactor::redactString(str_repeat('https://a:', 3000));
            $milliseconds = (hrtime(true) - $started) / 1_000_000;
        } finally {
            ini_set('pcre.jit', (string) $jit);
        }

        $this->assertLessThan(2000, $milliseconds);
    }
}
