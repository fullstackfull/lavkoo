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

    public function test_a_key_that_is_a_phone_number_or_an_email_address_is_not_kept_as_a_key_and_hiding_it_merges_nothing(): void
    {
        $redacted = PiiRedactor::redact(['data' => ['0551234567' => ['status' => 'NEW'], 'sara@example.test' => ['status' => 'OLD'], 'KSA100' => ['status' => 'KEPT']]]);

        $this->assertSame([self::MASK, self::MASK . '#2', 'KSA100'], array_keys($redacted['data']));
        $this->assertSame(['NEW', 'OLD', 'KEPT'], array_column($redacted['data'], 'status'), 'two hidden keys are two entries, not one');
        $this->assertStringNotContainsString('0551234567', json_encode($redacted));
        $this->assertStringNotContainsString('sara@example.test', json_encode($redacted));
    }

    public function test_a_phone_number_that_php_turned_into_an_integer_key_is_not_kept_either(): void
    {
        $decoded = json_decode('{"966551234567": {"status": "A"}, "551234567": {"status": "B"}, "00966551234567": {"status": "C"}, "+966551234567": {"status": "D"}, "7": {"status": "E"}}', true);

        foreach ([false, true] as $strict) {
            $redacted = PiiRedactor::redact(['byPhone' => $decoded], $strict);

            $this->assertSame([self::MASK, self::MASK . '#2', self::MASK . '#3', self::MASK . '#4', 7], array_keys($redacted['byPhone']), 'strict: ' . var_export($strict, true));
            $this->assertStringNotContainsString('551234567', json_encode($redacted));
        }
    }

    public function test_a_lone_name_under_a_key_nobody_expected_is_hidden_in_strict_mode_while_codes_and_enumerations_stay(): void
    {
        $people = [
            'courierName' => 'Khalid', 'courier_name' => 'Khalid', 'riderName' => 'Sara', 'agentName' => 'Alharbi', 'deliveryAgent' => 'Khalid',
            'deliveryMan' => 'Khalid', 'deliveredTo' => 'Sara', 'handledBy' => 'Alharbi', 'signedBy' => 'Alharbi', 'captain' => 'Khalid',
            'supplier' => 'Khalid', 'surname' => 'Alharbi', 'family' => 'Alharbi', 'given' => 'Sara', 'name_en' => 'Khalid', 'nameAr' => 'خالد',
            'handle' => 'sara.alharbi', 'label' => 'Khalid', 'who' => 'Sara', 'greeting' => 'Hello',
            // Capitals do not make a name a code: many courier systems write names that way.
            'pickedBy' => 'OMAR', 'assignee' => 'AHMED', 'signatory' => 'SARA', 'rep' => 'ALHARBI', 'staff' => 'ALI', 'by' => 'SARA', 'representative' => 'MOHAMMED', 'deliveryPerson' => 'KHALID', 'receivedFrom' => 'SARA',
            'serviceLevel' => 'EXPRESS',
        ];
        $kept = ['currency' => 'SAR', 'shipmentType' => 'REGULAR', 'event' => 'order_created', 'zone' => 'RUH-12', 'paymentMethod' => 'cod', 'branch' => 'RUH1', 'responsible' => 'KHALID2', 'orderMode' => 'DROPSHIP'];

        $redacted = PiiRedactor::redact($people + $kept, strict: true);

        foreach (array_keys($people) as $key) {
            $this->assertSame(self::MASK, $redacted[$key], $key);
        }
        foreach ($kept as $key => $value) {
            $this->assertSame($value, $redacted[$key], $key);
        }
        $this->assertSame('Khalid', PiiRedactor::redact(['courierName' => 'Khalid'])['courierName'], 'a catalogue has no couriers, and the plain mode keeps what it cannot suspect');
    }

    public function test_a_key_that_names_a_person_hides_its_value_in_strict_mode_even_when_the_value_is_shaped_like_a_code(): void
    {
        $people = [
            'nickName' => 'KHALID', 'vendorName' => 'ACME1', 'deliveredTo' => 'SARA2', 'handledBy' => 'KHALID', 'signedBy' => 'KHALID2', 'receivedBy' => 'SARA',
            'captain' => 'KHALID', 'family' => 'ALHARBI', 'given' => 'SARA', 'handle' => 'sara_1', 'deliveryMan' => 'KHALID', 'rider' => 'KHALID_2',
        ];

        $strict = PiiRedactor::redact($people, strict: true);
        $plain = PiiRedactor::redact($people);

        foreach ($people as $key => $value) {
            $this->assertSame(self::MASK, $strict[$key], $key);
            $this->assertSame($value, $plain[$key], "{$key}: a catalogue has no couriers to hide");
        }
    }

    public function test_only_a_key_that_ends_in_status_state_or_stage_makes_free_text_a_status_word(): void
    {
        $redacted = PiiRedactor::redact([
            'status' => 'Delivered to Sara Alharbi', 'status_message' => 'Left with neighbour', 'statusDescription' => 'Ahmed', 'delivery_state' => 'تم التسليم إلى سارة الحربي',
            'newStatus' => 'Out for delivery', 'orderStage' => 'Picked', 'status_long' => 'Waiting', 'currentStatus' => 'A status word that is far too long to be one',
            'status_name' => 'In transit', 'statusLabel' => 'Out for delivery', 'statusText' => 'Delivered to Sara Alharbi',
        ], strict: true);

        $this->assertSame(self::MASK, $redacted['status'], 'four words are a sentence, not a status');
        $this->assertSame(self::MASK, $redacted['status_message']);
        $this->assertSame(self::MASK, $redacted['statusDescription']);
        $this->assertSame(self::MASK, $redacted['delivery_state']);
        $this->assertSame('Out for delivery', $redacted['newStatus']);
        $this->assertSame('Picked', $redacted['orderStage']);
        $this->assertSame(self::MASK, $redacted['status_long'], 'the key must END in status, state or stage');
        $this->assertSame(self::MASK, $redacted['currentStatus']);
        $this->assertSame('In transit', $redacted['status_name'], 'the name of a status is a status word, although most keys that end in name are people');
        $this->assertSame('Out for delivery', $redacted['statusLabel']);
        $this->assertSame(self::MASK, $redacted['statusText'], 'four words are a sentence');
    }

    public function test_postal_and_zip_codes_are_personal_even_though_they_end_in_code(): void
    {
        $postal = ['postal_code' => '12345-6789', 'zipCode' => '12345', 'postcode' => '12345', 'zip' => '12345', 'whatsappNumber' => '0551234567'];

        foreach ([false, true] as $strict) {
            foreach (PiiRedactor::redact($postal, $strict) as $key => $value) {
                $this->assertSame(self::MASK, $value, $key . ' (strict: ' . var_export($strict, true) . ')');
            }
        }
    }

    public function test_timestamps_and_expiry_times_are_kept_in_strict_mode_although_they_are_as_long_as_a_phone_number(): void
    {
        $redacted = PiiRedactor::redact(['occurred_at' => 1730000000000, 'eta' => 1730000000, 'expires' => 1730000000, 'valid_until' => 1730000000, 'since' => 1730000000, 'reach' => 1730000000], strict: true);

        $this->assertSame(['occurred_at' => 1730000000000, 'eta' => 1730000000, 'expires' => 1730000000, 'valid_until' => 1730000000, 'since' => 1730000000, 'reach' => self::MASK], $redacted);
    }

    public function test_contact_details_are_masked_in_a_request_shown_for_approval_while_names_addresses_and_codes_stay(): void
    {
        $request = [
            'receiverName' => 'Sara Alharbi', 'receiverAddress' => ['addressLine1' => 'King Fahd Rd 12, call 0551234567', 'cityId' => 41125],
            'notes' => 'Write to sara@example.test or call 0501234567', 'items' => [['sku' => '6281234567890', 'name' => 'Basic Care Cream', 'quantity' => 2]], 'trackingEmail' => 'x@y.test',
        ];

        $masked = PiiRedactor::maskContactDetails($request);

        $this->assertSame('Sara Alharbi', $masked['receiverName']);
        $this->assertSame('King Fahd Rd 12, call ' . self::MASK, $masked['receiverAddress']['addressLine1']);
        $this->assertSame('Write to ' . self::MASK . ' or call ' . self::MASK, $masked['notes']);
        $this->assertSame('6281234567890', $masked['items'][0]['sku'], 'a long barcode is a code, not a telephone number');
        $this->assertSame('Basic Care Cream', $masked['items'][0]['name']);
        $this->assertSame(self::MASK, $masked['trackingEmail'], 'an e-mail address is masked even under a key that ends like a code');
        $this->assertSame(41125, $masked['receiverAddress']['cityId']);
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

    public function test_lists_of_ids_quantities_and_long_identifiers_are_not_mistaken_for_telephone_numbers(): void
    {
        foreach ([
            'skipped ids 1421521 1421522 1421523', 'quantities 10 20 30 40 50 60 70 80 90 are invalid', 'Order 1234567890123456789 not found',
            'tracking 20261105102315123 was created',
        ] as $text) {
            $this->assertSame($text, PiiRedactor::redactText($text), $text);
        }
    }

    public function test_the_children_of_a_personal_parent_are_hidden_even_under_keys_that_name_an_enumeration(): void
    {
        $personal = ['receiver' => ['unit' => 'Flat 12 Tower 2', 'kind' => 'Sara', 'mode' => '0551234567', 'channel' => 'whatsapp:+966551234567', 'currency' => 'Sara Alharbi', 'method' => '0551234567']];

        foreach ([false, true] as $strict) {
            $redacted = PiiRedactor::redact($personal, $strict);

            $this->assertSame(array_fill_keys(array_keys($personal['receiver']), self::MASK), $redacted['receiver'], 'strict: ' . var_export($strict, true));
        }
    }

    public function test_a_telephone_number_under_a_key_that_names_an_enumeration_is_still_found_outside_a_personal_parent(): void
    {
        $redacted = PiiRedactor::redact(['paymentMode' => '0551234567', 'channel' => 'whatsapp:+966551234567', 'currency' => 'SAR']);

        $this->assertSame(self::MASK, $redacted['paymentMode']);
        $this->assertStringNotContainsString('966551234567', (string) $redacted['channel']);
        $this->assertSame('SAR', $redacted['currency']);
    }

    public function test_a_status_enumeration_is_kept_under_a_status_key_and_a_phrase_only_up_to_three_words(): void
    {
        $kept = [
            'status' => 'APPROVED_BY_CUSTOMER_CARE_AND_WAITING_FOR_DISPATCHER', 'newStatus' => 'MOVED_TO_SHELF_AND_OUT_OF_HANDLER_CUSTODY', 'orderState' => 'قيد التوصيل للعميل',
            'deliveryStage' => 'Out for delivery', 'pickup_status' => 'Picked up', 'order_status_label' => 'In transit',
        ];
        // A courier's status often names the person it was handed to, so a longer phrase is hidden (and counted: see ResponseShape).
        $hidden = [
            'delivery_status' => 'Delivered to Sara Alharbi', 'currentStatus' => str_repeat('A', 70), 'status_message' => 'Delivered to Sara',
            'sentenceStatus' => 'The courier called the customer on 0551234567', 'fourWordsStatus' => 'Out for delivery today', 'arabicStatus' => 'تم تسليم الطلب للعميل',
            'threeLongWordsStatus' => 'Awaiting international clearance',
        ];

        $redacted = PiiRedactor::redact($kept + $hidden, strict: true);

        foreach ($kept as $key => $value) {
            $this->assertSame($value, $redacted[$key], $key);
        }
        foreach (array_keys($hidden) as $key) {
            $this->assertSame(self::MASK, $redacted[$key], $key);
        }
    }

    public function test_telephone_numbers_written_in_groups_of_two_to_four_digits_are_all_found(): void
    {
        $leaks = [];
        foreach ([[4, 2, 4], [3, 4, 3], [2, 3, 2, 3], [4, 2, 3, 2], [3, 3, 2, 2, 2], [2, 4, 2, 4], [4, 4, 2], [2, 2, 2, 2, 2], [3, 2, 4, 3], [4, 3, 2, 2]] as $groupSizes) {
            foreach (['-', '.', '/', ' ', "\u{2013}"] as $separator) {
                foreach (['call ', 'call +966 ', 'tel: '] as $prefix) {
                    $digits = '501234567890123';
                    $groups = [];
                    foreach ($groupSizes as $size) {
                        $groups[] = substr($digits, array_sum(array_slice($groupSizes, 0, count($groups))), $size);
                    }
                    $text = $prefix . implode($separator, $groups);
                    if (preg_match('/\d{3}/', PiiRedactor::redactText($text)) === 1) {
                        $leaks[] = $text;
                    }
                }
            }
        }

        $this->assertSame([], $leaks);
    }

    public function test_a_date_beside_a_short_number_is_still_not_a_phone_number(): void
    {
        $text = 'between 2026-11-05 18 and 2026-11-06 07, order #4455';

        $this->assertSame($text, PiiRedactor::redactText($text));
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

    /** @return array<string, array{string, list<string>}> text, and every number in it that must be gone */
    public static function numbersWrittenNextToEachOther(): array
    {
        return [
            'two numbers with a space' => ['0501234567 0551234567', ['0501234567', '0551234567']],
            'a slash' => ['0501234567/0551234567', ['0501234567', '0551234567']],
            'a slash with spaces' => ['0501234567 / 0551234567', ['0501234567', '0551234567']],
            'a dash' => ['0501234567 - 0551234567', ['0501234567', '0551234567']],
            'international then local' => ['+966501234567 / 0551234567', ['966501234567', '0551234567']],
            'twelve digits twice' => ['966501234567 966551234567', ['966501234567', '966551234567']],
            'a number then a date' => ['0501234567 2026-11-05', ['0501234567']],
            'a date then a number' => ['2026-11-05 0501234567', ['0501234567']],
            'a number then an order number' => ['0501234567 / 100234', ['0501234567']],
            'three numbers' => ['0501234567 0551234567 0561234567', ['0501234567', '0551234567', '0561234567']],
            'a tab' => ["0501234567\t0551234567", ['0501234567', '0551234567']],
            'a line break' => ["0501234567\n0551234567", ['0501234567', '0551234567']],
            'an en dash' => ["0501234567 \u{2013} 0551234567", ['0501234567', '0551234567']],
            'an em dash' => ["0501234567\u{2014}0551234567", ['0501234567', '0551234567']],
            'a minus sign' => ["055\u{2212}123\u{2212}4567", ['4567']],
            'a non-breaking hyphen' => ["055\u{2011}123\u{2011}4567", ['4567']],
            'a narrow no-break space' => ["055\u{202F}123\u{202F}4567", ['4567']],
            'a thin space' => ["055\u{2009}123\u{2009}4567", ['4567']],
            'grouped with a pair of digits in the middle' => ['call +966-5012-34-5678', ['5012-34-5678']],
            'grouped 4-2-3' => ['+966 5010-35-099', ['5010-35-099']],
            'groups of two and four' => ['50-0006-87-90', ['0006-87-90']],
            'dots between groups' => ['50.2304.90.06', ['2304.90.06']],
            'a group that looks like a date prefix' => ['call 0501-23-4567', ['0501-23-4567']],
            'a number split by zero-width characters' => ["call 050\u{200B}123\u{200B}4567", ['4567']],
            'a number split by a non-joiner' => ["call 0501\u{200C}234567", ['234567']],
            'a number split by a soft hyphen' => ["call 05\u{00AD}01234567", ['01234567']],
            'a number split by a word joiner' => ["call 0501234\u{2060}567", ['0501234']],
            'a number split by an Arabic letter mark' => ["call 050\u{061C}1234567", ['1234567']],
            'two numbers written in groups after a long dash' => ['055 123 4567 \u{2014} 0501234567', ['055 123 4567', '0501234567']],
        ];
    }

    /**
     * @param list<string> $gone
     * @dataProvider numbersWrittenNextToEachOther
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('numbersWrittenNextToEachOther')]
    public function test_every_number_in_a_run_of_numbers_is_masked_not_only_a_run_that_is_one_number(string $text, array $gone): void
    {
        foreach ([PiiRedactor::redactText($text), PiiRedactor::scrub($text)] as $masked) {
            foreach ($gone as $number) {
                $this->assertStringNotContainsString($number, $masked, $text);
            }
        }
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
