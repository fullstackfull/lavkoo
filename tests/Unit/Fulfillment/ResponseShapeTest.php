<?php

namespace Tests\Unit\Fulfillment;

use App\Services\Fulfillment\Support\PiiRedactor;
use App\Services\Fulfillment\Verification\ResponseShape;
use PHPUnit\Framework\TestCase;

/** The "real response schema" that is recorded as evidence: what it keeps, what it never keeps, and the answers read out of it. */
class ResponseShapeTest extends TestCase
{
    /** @return array<string, mixed> an ASSUMED warehouse product list: the real schema is exactly what the verification run records */
    private function productList(): array
    {
        return [
            'success' => true,
            'data' => [
                ['id' => 1421521, 'name' => 'Vitamin C 1000mg', 'sku' => '13222', 'barcode' => '6281000000011', 'stock' => 40, 'reserved' => 3, 'price' => 19.5, 'status' => 'ACTIVE'],
                ['id' => 1421522, 'name' => 'Hand Sanitizer', 'sku' => '13223', 'barcode' => '6281000000028', 'stock' => 0, 'reserved' => 0, 'price' => 9, 'status' => 'ACTIVE'],
                ['id' => 1421523, 'name' => 'Cough Syrup', 'sku' => '13224', 'barcode' => null, 'stock' => 12, 'reserved' => 1, 'price' => 14.25, 'status' => 'INACTIVE'],
            ],
            'meta' => ['total' => 1042, 'page' => 1, 'pageSize' => 50, 'hasNext' => true],
        ];
    }

    public function test_the_profile_records_types_presence_ranges_and_examples(): void
    {
        $profile = ResponseShape::profile($this->productList());

        $item = $profile['keys']['data']['items'];
        $this->assertSame(3, $item['present']);
        $this->assertSame('integer', $item['keys']['stock']['types']);
        $this->assertSame(['min' => 0.0, 'max' => 40.0, 'sum' => 52.0, 'non_zero' => 2], $item['keys']['stock']['numeric']);
        $this->assertSame(['string' => 2, 'null' => 1], $item['keys']['barcode']['types']);
        $this->assertSame(['ACTIVE' => 2, 'INACTIVE' => 1], $item['keys']['status']['values']);
        $this->assertSame(['min' => 3, 'max' => 3], $profile['keys']['data']['count']);
    }

    public function test_the_findings_locate_the_records_the_pagination_the_stock_fields_and_the_statuses(): void
    {
        $findings = ResponseShape::findings(ResponseShape::profile($this->productList()));

        $this->assertSame('data', $findings['items_path']);
        $this->assertSame(3, $findings['item_count']);
        $this->assertSame(['meta.total' => 1042.0, 'meta.page' => 1.0, 'meta.pageSize' => 50.0, 'meta.hasNext' => 'true'], $findings['pagination']);
        $this->assertSame(['id', 'sku', 'barcode'], array_column($findings['identifier_fields'], 'field'));
        $this->assertTrue($findings['identifier_fields'][0]['unique']);
        $stock = array_column($findings['stock_fields'], null, 'field');
        $this->assertSame(['stock', 'reserved'], array_keys($stock), 'the numeric fields whose names say stock, with their ranges: which one means "available" is for a human with a physical count');
        $this->assertSame(40.0, $stock['stock']['max']);
        $this->assertSame([['field' => 'status', 'values' => ['ACTIVE' => 2, 'INACTIVE' => 1]]], $findings['status_fields']);
        $this->assertSame(['success' => 'boolean', 'data' => 'the list of records', 'meta' => 'object'], $findings['envelope']);
    }

    public function test_a_response_that_is_a_list_has_its_records_at_the_root(): void
    {
        $findings = ResponseShape::findings(ResponseShape::profile([['id' => 1, 'sku' => 'A'], ['id' => 2, 'sku' => 'B']]));

        $this->assertSame('', $findings['items_path']);
        $this->assertSame(2, $findings['item_count']);
    }

    public function test_a_nested_envelope_is_followed_and_the_longest_list_wins(): void
    {
        $findings = ResponseShape::findings(ResponseShape::profile(['result' => ['content' => [['id' => 1], ['id' => 2], ['id' => 3]], 'tags' => [['t' => 'a']]]]));

        $this->assertSame('result.content', $findings['items_path']);
    }

    public function test_a_response_with_no_list_of_records_says_so(): void
    {
        $findings = ResponseShape::findings(ResponseShape::profile(['status' => 'ok', 'message' => 'hello']));

        $this->assertArrayNotHasKey('items_path', $findings);
        $this->assertSame([['field' => 'status', 'values' => ['ok' => 1]]], $findings['status_fields']);
    }

    public function test_no_person_and_no_credential_reaches_the_profile(): void
    {
        $order = [
            'data' => [[
                'id' => 4455, 'barcode' => 'O0015030400001', 'invoiceNumber' => '100-TP1', 'status' => 'PICKED',
                'receiverName' => 'Sara Alharbi', 'receiverPhone' => '0551234567', 'receiverEmail' => 'sara@example.test',
                'receiverAddress' => ['cityId' => 41125, 'addressLine1' => 'King Fahd Rd 12'], 'notes' => 'Leave with the guard',
                'deliveryInstructions' => 'Ring twice, the bell is broken', 'accessToken' => 'CANARY-ACCESS-TOKEN', 'password' => 'CANARY-PASSWORD',
            ]],
        ];

        $encoded = (string) json_encode(ResponseShape::profile($order, strict: true), JSON_UNESCAPED_UNICODE);

        foreach (['Sara Alharbi', '0551234567', 'sara@example.test', 'King Fahd Rd', 'Leave with the guard', 'Ring twice', 'CANARY-ACCESS-TOKEN', 'CANARY-PASSWORD'] as $private) {
            $this->assertStringNotContainsString($private, $encoded, "'{$private}' is in the evidence");
        }
        $this->assertStringContainsString('O0015030400001', $encoded, 'a parcel reference is kept');
        $this->assertStringContainsString('41125', $encoded, 'a city id is kept');
        $profile = ResponseShape::profile($order, strict: true);
        $this->assertTrue($profile['keys']['data']['items']['keys']['receiverName']['redacted']);
        $this->assertSame('string', $profile['keys']['data']['items']['keys']['receiverName']['types'], 'the field is known to exist and what type it is');
        $this->assertArrayNotHasKey('examples', $profile['keys']['data']['items']['keys']['receiverName']);
        $this->assertSame(['PICKED' => 1], $profile['keys']['data']['items']['keys']['status']['values']);
    }

    public function test_a_jwt_under_an_innocent_key_is_not_kept(): void
    {
        $jwt = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiI1OTY1MyJ9.signature-signature';

        $encoded = (string) json_encode(ResponseShape::profile(['data' => ['session' => $jwt, 'userId' => 59653]]));

        $this->assertStringNotContainsString($jwt, $encoded);
    }

    public function test_the_profile_is_bounded(): void
    {
        $many = [];
        foreach (range(1, 500) as $index) {
            $many[] = array_fill_keys(array_map(static fn (int $key): string => "field{$key}", range(1, 120)), $index);
        }

        $profile = ResponseShape::profile(['data' => $many]);

        $this->assertSame(25, $profile['keys']['data']['items']['present'], 'at most 25 records are examined');
        $this->assertCount(80, $profile['keys']['data']['items']['keys'], 'at most 80 keys per object');
        $this->assertSame(500, $profile['keys']['data']['count']['max'], 'but the real length is recorded');
        $this->assertLessThan(150_000, strlen((string) json_encode($profile)));
    }

    public function test_examples_are_few_short_and_distinct(): void
    {
        $profile = ResponseShape::profile(['data' => array_map(static fn (int $n): array => ['label' => str_repeat('x', 100) . $n, 'n' => $n % 2], range(1, 10))]);

        $item = $profile['keys']['data']['items']['keys'];
        $this->assertLessThanOrEqual(3, count($item['label']['examples']));
        $this->assertLessThanOrEqual(40, mb_strlen($item['label']['examples'][0]));
        $this->assertSame([1, 0], $item['n']['examples']);
        $this->assertSame(PiiRedactor::MASK, PiiRedactor::MASK);
    }
}
