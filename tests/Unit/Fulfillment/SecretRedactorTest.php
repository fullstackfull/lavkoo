<?php

namespace Tests\Unit\Fulfillment;

use App\Services\Fulfillment\Support\SecretRedactor;
use PHPUnit\Framework\TestCase;

class SecretRedactorTest extends TestCase
{
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
            'a password in a url' => ['fetching https://admin:s3cr3tvalue99@example.test/api', 's3cr3tvalue99'],
            'a private key block' => ["-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEAs3cr3tvalue99\n-----END RSA PRIVATE KEY-----", 's3cr3tvalue99'],
            'a private key block cut short' => ["-----BEGIN PRIVATE KEY-----\nMIIEowIBAAKCAQEAs3cr3tvalue99", 's3cr3tvalue99'],
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
            SecretRedactor::redactString('-----BEGIN PRIVATE KEY-----' . str_repeat('A', 30000));
            SecretRedactor::redactString(str_repeat('https://a:', 3000));
            $milliseconds = (hrtime(true) - $started) / 1_000_000;
        } finally {
            ini_set('pcre.jit', (string) $jit);
        }

        $this->assertLessThan(2000, $milliseconds);
    }
}
