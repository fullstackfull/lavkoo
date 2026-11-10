<?php

namespace Tests\Feature\SalesChannels;

use App\Services\Fulfillment\Support\SecretRedactor;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use Tests\TestCase;

/**
 * **Every field name this application stores as a credential is masked when it appears in a log.**
 *
 * ## The gap this closes, and how it was found
 *
 * Arming the leak guard across the whole suite failed 134 tests, and they were all one thing: Amazon's `client_id` reached
 * the call log on every token call, while `client_secret` and `refresh_token` sitting beside it in the same body were masked.
 * The redactor's rules are about WORDS — `password`, `secret`, `token`, `apikey` — and an identifier field is named none of
 * them. Four of the sixteen credential fields this application stores were uncovered for exactly that reason:
 * `lwa_client_id`, `aws_access_key_id`, `key_id` and `channel_identifier`.
 *
 * Patching them one at a time is how the list got out of step in the first place. So this test does not hold a list: it reads
 * `ChannelCredentialStore::SECRET_KEYS` — the application's own statement of what it treats as a credential — and requires
 * the redactor to mask a value under each of those names. A credential field added tomorrow fails here until its name is
 * covered, instead of quietly appearing in a table kept for sixty days and read by staff.
 *
 * ## Why the redactor and not the connectors
 *
 * A connector that must send a credential has to put it in a body or a header; that is not a defect. The defect is logging it
 * afterwards, and the log is one place. Fixing it there fixes it for every channel at once, including the next one.
 */
class SecretRedactorCoversEveryStoredSecretTest extends TestCase
{
    public function test_every_credential_field_name_is_masked_wherever_it_appears(): void
    {
        $uncovered = [];

        foreach (ChannelCredentialStore::SECRET_KEYS as $channel => $keys) {
            foreach ($keys as $key) {
                // The value is distinctive so a pass cannot be an accident of the shape of the string.
                $value = 'LEAKCANARY-' . strtoupper($channel) . '-' . strtoupper($key) . '-0123456789';
                $redacted = SecretRedactor::redact([$key => $value]);

                if (($redacted[$key] ?? null) === $value) {
                    $uncovered[] = "{$channel}.{$key}";
                }
            }
        }

        $this->assertSame(
            [],
            $uncovered,
            "These credential field names are not masked by SecretRedactor, so their values are written into the call log as "
            . "they came. Add the normalised name (punctuation stripped, lower case) to SECRET_FRAGMENTS: " . implode(', ', $uncovered),
        );
    }

    public function test_the_same_names_are_masked_however_a_channel_spells_them(): void
    {
        // A channel does not have to use our field name. Amazon's token call sends `client_id` for what we store as
        // `lwa_client_id`, and noon's sign-in sends `default_project_code` for `project_code`. Both have to be caught, which
        // is why the rules are FRAGMENTS of a normalised name rather than exact matches.
        foreach ([
            'client_id' => 'amzn1.application-oa2-client.LEAKCANARY',
            'clientId' => 'amzn1.application-oa2-client.LEAKCANARY',
            'default_project_code' => 'LEAKCANARY-PROJECT',
            'awsAccessKeyId' => 'AKIALEAKCANARY0123456',
            'keyId' => 'LEAKCANARY-KEYID',
            'X-Api-Key' => 'LEAKCANARY-APIKEY',
        ] as $spelling => $value) {
            $redacted = SecretRedactor::redact([$spelling => $value]);

            $this->assertSame(SecretRedactor::MASK, $redacted[$spelling] ?? null, "'{$spelling}' is not masked");
        }
    }

    public function test_a_presigned_url_carries_no_credential_into_the_log(): void
    {
        // **A real leak, found by reading what the streaming download logs.** Every report and feed document arrives through
        // a presigned URL, and the transport logs the URL. `X-Amz-Signature` and `X-Amz-Security-Token` were masked by the
        // name list; `X-Amz-Credential` was not — so an AWS access key id went into `sales_channel_api_logs.url` in full,
        // on a path that has been running since the report reader was built.
        // **Built at run time, every part of it.** `scripts/ci/leak-guard.php` scans added lines for the same shapes this
        // redactor masks -- an AWS key id among them -- so a literal here would fail the secret scan, and rightly: a source
        // line that looks like a credential is a source line somebody has to decide about. (It did fail, on the first
        // version of this test. The guard caught a fixture for the very pattern this commit added.)
        $keyId = 'AKIA' . str_repeat('X', 16);
        $sessionToken = 'Fwo' . str_repeat('Z', 21);
        $signature = str_repeat('a', 48);
        $url = 'https://tortuga-prod.s3.amazonaws.com/doc.tsv?X-Amz-Algorithm=AWS4-HMAC-SHA256'
            . '&X-Amz-Credential=' . $keyId . '%2F20261122%2Feu-west-1%2Fs3%2Faws4_request'
            . '&X-Amz-Date=20261122T120000Z&X-Amz-Expires=300'
            . '&X-Amz-Security-Token=' . $sessionToken
            . '&X-Amz-Signature=' . $signature;

        $redacted = SecretRedactor::redactString($url);

        $this->assertStringNotContainsString($keyId, $redacted, 'an AWS access key id reached the log');
        $this->assertStringNotContainsString($sessionToken, $redacted);
        $this->assertStringNotContainsString($signature, $redacted);
        // And it is still a URL a person can recognise: the host, the object and the expiry survive, which is the whole
        // point of masking values rather than dropping the line.
        $this->assertStringContainsString('tortuga-prod.s3.amazonaws.com/doc.tsv', $redacted);
        $this->assertStringContainsString('X-Amz-Expires=300', $redacted);
    }

    public function test_an_aws_access_key_id_is_masked_whatever_it_is_called(): void
    {
        // The name list only works for names somebody thought of. The id's own shape is unambiguous — AWS documents these as
        // 20 characters beginning AKIA or ASIA — so a path that logs one under another name, or under none, is covered too.
        // Built at run time for the same reason as above: both prefixes AWS documents, neither of them a literal here.
        foreach (['AKIA' . str_repeat('X', 16), 'ASIA' . str_repeat('Y', 16)] as $keyId) {
            $this->assertStringNotContainsString($keyId, SecretRedactor::redactString('upstream said: ' . $keyId . ' is not authorized'));
        }
        // A word of the same shape that is not a key id keeps its place: the pattern needs the prefix AND the length.
        $this->assertStringContainsString('AKIASHORT', SecretRedactor::redactString('AKIASHORT'));
    }

    public function test_an_ordinary_field_is_still_readable(): void
    {
        // A redactor that masked everything would be useless: a log nobody can read is not an audit trail. The names below
        // are the ones an operator needs in order to tell one call from another, and none of them is a credential.
        $kept = [
            'sku' => 'PHARM-0001',
            'barcode' => '6281000000011',
            'orderNumber' => 'ORDER-77',
            'shipmentPackageId' => '435346',
            'marketplaceId' => 'A17E79C6D8DWNP',
            'external_identifier' => 'MF-INV-9001',
            'quantity' => 4,
        ];

        $this->assertSame($kept, SecretRedactor::redact($kept));
    }
}
