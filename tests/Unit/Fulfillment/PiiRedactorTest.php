<?php

namespace Tests\Unit\Fulfillment;

use App\Services\Fulfillment\Support\PiiRedactor;
use PHPUnit\Framework\TestCase;

/** What identifies a person is removed from provider data before it is kept or leaves the application; what identifies a parcel stays. */
class PiiRedactorTest extends TestCase
{
    private const MASK = PiiRedactor::MASK;

    public function test_the_documented_add_order_body_keeps_the_parcel_and_loses_the_person(): void
    {
        $body = [
            'receiverName' => 'Sara Alharbi', 'receiverPhone' => '00966551234567', 'receiverPhone2' => '',
            'receiverAddress' => ['cityId' => '41125', 'regionId' => '1335', 'addressLine1' => 'King Fahd Rd 12'],
            'notes' => 'Leave with the guard', 'weight' => 1, 'receiverBusinessName' => 'Alharbi Pharmacy',
            'shipmentType' => 'COD', 'codCollectionMethod' => 'CASH', 'cod' => '72.50',
            'items' => [['productId' => 1421521, 'sku' => '13222', 'price' => 30.0, 'quantity' => 2]], 'invoiceNumber' => '100-TP1',
        ];

        $redacted = PiiRedactor::redact($body);

        $this->assertSame(self::MASK, $redacted['receiverName']);
        $this->assertSame(self::MASK, $redacted['receiverPhone']);
        $this->assertSame(self::MASK, $redacted['receiverAddress']['addressLine1']);
        $this->assertSame(self::MASK, $redacted['notes']);
        $this->assertSame(self::MASK, $redacted['receiverBusinessName']);
        $this->assertSame(['cityId' => '41125', 'regionId' => '1335', 'addressLine1' => self::MASK], $redacted['receiverAddress'], 'the city is what a debugger needs, and it is not a person');
        $this->assertSame('100-TP1', $redacted['invoiceNumber']);
        $this->assertSame('COD', $redacted['shipmentType']);
        $this->assertSame('72.50', $redacted['cod']);
        $this->assertSame($body['items'], $redacted['items']);
    }

    public function test_a_phone_number_or_an_email_under_a_key_nobody_expected_is_still_found(): void
    {
        $redacted = PiiRedactor::redact(['extra' => '0551234567', 'whoKnows' => 'sara@example.test', 'free' => 'call +966 55 123 4567 please', 'fine' => 'Riyadh']);

        $this->assertSame(self::MASK, $redacted['extra']);
        $this->assertSame(self::MASK, $redacted['whoKnows']);
        $this->assertStringNotContainsString('966 55', $redacted['free']);
        $this->assertSame('Riyadh', $redacted['fine']);
    }

    public function test_codes_barcodes_and_tracking_numbers_survive_even_though_they_are_long_digit_strings(): void
    {
        $redacted = PiiRedactor::redact(['barcode' => '6281000000011', 'sku' => '13222', 'trackingNumber' => 'KSA100422577363', 'awb' => '123456789012', 'orderId' => 4455, 'customerId' => 99, 'status' => 'OUT_FOR_DELIVERY']);

        $this->assertSame(['barcode' => '6281000000011', 'sku' => '13222', 'trackingNumber' => 'KSA100422577363', 'awb' => '123456789012', 'orderId' => 4455, 'customerId' => 99, 'status' => 'OUT_FOR_DELIVERY'], $redacted, 'an identifier is not a person');
    }

    public function test_a_phone_or_address_key_is_personal_even_when_it_ends_like_a_code(): void
    {
        $redacted = PiiRedactor::redact(['phoneCode' => '0551234567', 'emailId' => 'a@b.test', 'addressId' => 'King Fahd Rd']);

        $this->assertSame(self::MASK, $redacted['phoneCode']);
        $this->assertSame(self::MASK, $redacted['emailId']);
        $this->assertSame(self::MASK, $redacted['addressId']);
    }

    public function test_a_list_of_personal_values_is_masked_value_by_value(): void
    {
        $this->assertSame(['phones' => [self::MASK, self::MASK]], PiiRedactor::redact(['phones' => ['0551111111', '0552222222']]));
    }

    public function test_a_product_name_is_kept_in_a_catalogue_but_hidden_in_strict_mode(): void
    {
        $item = ['id' => 7, 'name' => 'Vitamin C 1000mg', 'sku' => 'VC1000', 'stock' => 40, 'price' => 19.0, 'description' => 'Tablets, 30 per box'];

        $this->assertSame($item, PiiRedactor::redact($item));

        $strict = PiiRedactor::redact($item, strict: true);
        $this->assertSame(self::MASK, $strict['name']);
        $this->assertSame(self::MASK, $strict['description']);
        $this->assertSame(['id' => 7, 'sku' => 'VC1000', 'stock' => 40, 'price' => 19.0], array_intersect_key($strict, array_flip(['id', 'sku', 'stock', 'price'])));
    }

    public function test_strict_mode_hides_any_string_that_is_not_a_short_code_word(): void
    {
        $redacted = PiiRedactor::redact(['status' => 'PICKED', 'deliveryInstructions' => 'Ring twice, the bell is broken', 'zone' => 'RUH-12', 'count' => 3, 'flag' => true, 'nothing' => null], strict: true);

        $this->assertSame(['status' => 'PICKED', 'deliveryInstructions' => self::MASK, 'zone' => 'RUH-12', 'count' => 3, 'flag' => true, 'nothing' => null], $redacted);
    }

    public function test_arabic_text_is_hidden_in_strict_mode_and_kept_in_a_catalogue(): void
    {
        $this->assertSame(self::MASK, PiiRedactor::redact(['label' => 'سارة الحربي'], strict: true)['label']);
        $this->assertSame('الرياض', PiiRedactor::redact(['cityName' => 'الرياض'])['cityName']);
    }

    public function test_text_is_masked_for_emails_and_phone_numbers_only(): void
    {
        $text = PiiRedactor::redactText('Invalid phone 0551234567 for sara@example.test on order KSA100422577363');

        $this->assertStringNotContainsString('0551234567', $text);
        $this->assertStringNotContainsString('sara@example.test', $text);
        $this->assertStringContainsString('KSA100422577363', $text);
        $this->assertStringContainsString('Invalid phone', $text);
    }

    public function test_redacting_leaves_the_shape_intact_and_changes_nothing_it_does_not_need_to(): void
    {
        $data = ['data' => [['id' => 1, 'sku' => 'A', 'stock' => 5, 'nested' => ['deep' => ['receiverEmail' => 'x@y.test', 'ok' => 'fine']]]], 'total' => 1];

        $redacted = PiiRedactor::redact($data);

        $this->assertSame([['id' => 1, 'sku' => 'A', 'stock' => 5, 'nested' => ['deep' => ['receiverEmail' => self::MASK, 'ok' => 'fine']]]], $redacted['data']);
        $this->assertSame(1, $redacted['total']);
    }

    public function test_everything_under_a_personal_parent_is_hidden_whatever_its_key_is_called_except_codes(): void
    {
        $redacted = PiiRedactor::redact([
            'receiver' => ['name' => 'Sara Alharbi', 'line1' => 'King Fahd Rd 12, Olaya', 'city' => 'Riyadh', 'id' => 77, 'deep' => ['floor' => 'second', 'cityId' => 41125]],
            'consignee' => ['title' => 'Mrs Sara Alharbi'],
            'shipTo' => ['status' => 'ACTIVE'],
        ]);

        $this->assertSame(['name' => self::MASK, 'line1' => self::MASK, 'city' => self::MASK, 'id' => 77, 'deep' => ['floor' => self::MASK, 'cityId' => 41125]], $redacted['receiver'], 'an unknown key under a receiver is not a reason to keep a name');
        $this->assertSame(['title' => self::MASK], $redacted['consignee']);
        $this->assertSame(['status' => 'ACTIVE'], $redacted['shipTo'], 'a parent that does not name a person leaves its children alone');
    }

    public function test_keys_that_name_an_identity_a_place_or_a_network_address_are_personal_in_every_mode(): void
    {
        $personal = ['national_id' => '1012345678', 'iqama_id' => '2012345678', 'identity_id' => '1012345678', 'passport' => 'A1234567', 'iban' => 'SA0380000000608010167519', 'contact_no' => 966551234567, 'tel' => 551234567, 'lat' => 24.7136, 'lng' => 46.6753, 'ip' => '10.1.2.3'];

        foreach ([false, true] as $strict) {
            $redacted = PiiRedactor::redact($personal, $strict);
            foreach ($personal as $key => $ignored) {
                $this->assertSame(self::MASK, $redacted[$key], "{$key} (strict: " . var_export($strict, true) . ')');
            }
        }
    }

    public function test_strict_mode_hides_people_by_key_a_long_number_and_a_network_address_but_keeps_counts_codes_and_times(): void
    {
        $redacted = PiiRedactor::redact([
            'courier' => 'Khalid', 'createdBy' => 'admin', 'user' => 'sara', 'reach' => 966551234567, 'count' => 12, 'created_at' => 1699999999,
            'order_id' => 123456789012, 'host' => '192.168.1.5', 'weight' => 1.5, 'tracking' => 'KSA100422577363',
        ], strict: true);

        $this->assertSame(['courier' => self::MASK, 'createdBy' => self::MASK, 'user' => self::MASK, 'reach' => self::MASK, 'count' => 12, 'created_at' => 1699999999, 'order_id' => 123456789012, 'host' => self::MASK, 'weight' => 1.5, 'tracking' => 'KSA100422577363'], $redacted);
    }

    public function test_a_key_that_is_a_phone_number_or_an_email_address_is_not_kept_as_a_key(): void
    {
        $redacted = PiiRedactor::redact(['data' => ['0551234567' => ['status' => 'NEW'], 'sara@example.test' => ['status' => 'OLD'], 'KSA100' => ['status' => 'KEPT']]]);

        $this->assertSame([self::MASK, 'KSA100'], array_keys($redacted['data']));
        $this->assertStringNotContainsString('0551234567', json_encode($redacted));
        $this->assertStringNotContainsString('sara@example.test', json_encode($redacted));
    }

    public function test_a_status_word_in_any_language_survives_strict_mode_under_a_status_key_only(): void
    {
        $redacted = PiiRedactor::redact([
            'status' => 'Out for delivery', 'deliveryStatus' => 'تم التوصيل', 'orderState' => 'قيد الشحن', 'status_text' => 'Called 0551234567 twice',
            'remarks' => 'تم التوصيل', 'label' => 'Out for delivery', 'title' => 'Out for delivery',
        ], strict: true);

        $this->assertSame('Out for delivery', $redacted['status']);
        $this->assertSame('تم التوصيل', $redacted['deliveryStatus']);
        $this->assertSame('قيد الشحن', $redacted['orderState']);
        $this->assertSame(self::MASK, $redacted['status_text'], 'a status that carries a phone number is not a status word');
        $this->assertSame(self::MASK, $redacted['remarks']);
        $this->assertSame(self::MASK, $redacted['label'], 'free text under another key is hidden');
    }

    /** @return array<string, array{string}> */
    public static function contactDetailsInEveryFormPeopleWriteThem(): array
    {
        return [
            'dots' => ['call 055.123.4567 now'], 'international with dots' => ['+966.55.123.4567'], 'slashes' => ['055/123/4567'],
            'brackets' => ['(055) 123-4567'], 'non-breaking spaces' => ["055\u{A0}123\u{A0}4567"], 'plain' => ['0551234567'],
            'Arabic-Indic digits' => ['٠٥٥١٢٣٤٥٦٧'], 'Arabic-Indic international' => ['+٩٦٦٥٥١٢٣٤٥٦٧'], 'Extended Arabic-Indic' => ['۰۵۵۱۲۳۴۵۶۷'],
            'full-width' => ['０５５１２３４５６７'], 'a unicode local part' => ['أحمد@example.com'], 'an IDN domain' => ['ahmed@مثال.com'], 'an ordinary address' => ['sara.alharbi+x@example.test'],
        ];
    }

    /** @dataProvider contactDetailsInEveryFormPeopleWriteThem */
    #[\PHPUnit\Framework\Attributes\DataProvider('contactDetailsInEveryFormPeopleWriteThem')]
    public function test_a_phone_number_or_an_email_address_is_found_in_every_form_people_write_it(string $text): void
    {
        $this->assertSame(1, preg_match('/' . preg_quote(self::MASK, '/') . '/u', PiiRedactor::redactText($text)), $text);
        $this->assertSame(1, preg_match('/' . preg_quote(self::MASK, '/') . '/u', PiiRedactor::scrub($text)), $text);
    }

    public function test_dates_times_short_numbers_and_parcel_codes_are_not_mistaken_for_contact_details(): void
    {
        $text = 'created 2026-11-05 18:33:15 and 2026/11/05, order #4455, 3 items, weight 1.5, barcode KSA100422577363';

        $this->assertSame($text, PiiRedactor::redactText($text));
    }

    public function test_text_without_anything_to_mask_comes_back_exactly_as_it_was_digits_included(): void
    {
        $this->assertSame('فيتامين ١٠٠٠ مجم', PiiRedactor::redactText('فيتامين ١٠٠٠ مجم'));
    }

    public function test_a_pattern_that_cannot_run_hides_the_text_instead_of_letting_it_through(): void
    {
        $backtrack = ini_get('pcre.backtrack_limit');
        $jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');

        try {
            $this->assertSame(self::MASK, PiiRedactor::redactText(str_repeat('a', 400) . '@example.test and 0551234567'));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $backtrack);
            ini_set('pcre.jit', (string) $jit);
        }
    }

    public function test_hostile_input_is_scanned_in_bounded_time_even_without_the_regex_jit(): void
    {
        $jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');

        try {
            $started = hrtime(true);
            PiiRedactor::redactText(str_repeat('a', 32000) . '@' . str_repeat('a.', 16000), phones: false);
            PiiRedactor::redactText(str_repeat('a', 19000) . '@' . str_repeat('a.', 400), phones: false);
            PiiRedactor::scrub(str_repeat('1 ', 30000));
            $milliseconds = (hrtime(true) - $started) / 1_000_000;
        } finally {
            ini_set('pcre.jit', (string) $jit);
        }

        $this->assertLessThan(1500, $milliseconds, 'the same input took 11 seconds when the scan was quadratic');
    }

    public function test_text_that_is_not_valid_utf8_is_still_scanned(): void
    {
        $this->assertStringNotContainsString('0551234567', PiiRedactor::redactText("call 0551234567 \xC3\x28 now"));
    }

    public function test_scrub_removes_credentials_written_as_headers_or_pairs_and_control_characters(): void
    {
        $text = "Authorization: Basic dXNlcjpwYXNzd29yZA==\nCookie: sid=abc123; theme=dark\nX-Api-Key: key-0123456789\nfailed: password=hunter2&api_key=ABCDEF123456 \e[2J ok";

        $scrubbed = PiiRedactor::scrub($text, 1000);

        foreach (['dXNlcjpwYXNzd29yZA', 'sid=abc123', 'key-0123456789', 'hunter2', 'ABCDEF123456', "\e"] as $leaked) {
            $this->assertStringNotContainsString($leaked, $scrubbed);
        }
        $this->assertStringContainsString('failed:', $scrubbed);
    }

    public function test_scrub_removes_credentials_and_contact_details_and_shortens(): void
    {
        $jwt = rtrim(strtr(base64_encode('{"alg":"RS256"}'), '+/', '-_'), '=') . '.' . rtrim(strtr(base64_encode('{"sub":"1"}'), '+/', '-_'), '=') . '.' . str_repeat('s', 20);

        $scrubbed = PiiRedactor::scrub("HTTP 422: invalid receiver phone 0551234567 for sara@example.test, Authorization: Bearer abc.def-123, token {$jwt}");

        foreach (['0551234567', 'sara@example.test', 'abc.def-123', $jwt] as $leaked) {
            $this->assertStringNotContainsString($leaked, $scrubbed);
        }
        $this->assertStringContainsString('HTTP 422', $scrubbed, 'what a person debugging needs is kept');
        $this->assertLessThanOrEqual(300 + strlen('…[truncated]'), strlen(PiiRedactor::scrub(str_repeat('a long line of nothing personal ', 40))));
    }
}
