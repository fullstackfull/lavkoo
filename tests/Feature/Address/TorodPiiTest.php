<?php

namespace Tests\Feature\Address;

use App\Services\Fulfillment\Readiness\Staging\PersonalDataScrub;
use App\Services\Fulfillment\Readiness\Staging\SqlDialect;
use App\Services\Fulfillment\Support\PiiRedactor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The new address keys of an order snapshot — the National Address, the district, the coordinates — identify a household. A staging
 * copy must not keep them and a provider log must not show them; the city and region ids and names, which identify no one, stay.
 */
class TorodPiiTest extends TorodAddressTestCase
{
    private const CANARIES = ['CANARYNA77', 'CANARY-DISTRICT', 'CANARY-STREET-9', 'CANARY-NAME', '0599900111', '24.713655', '46.675322'];

    /** @return array<string, mixed> */
    private function canarySnapshot(): array
    {
        return $this->unifiedSnapshot('riyadh', [
            'contact_person_name' => 'CANARY-NAME', 'phone' => '0599900111', 'address' => 'CANARY-STREET-9', 'district' => 'CANARY-DISTRICT',
            'national_address' => 'CANARYNA77', 'latitude' => '24.713655', 'longitude' => '46.675322',
        ]);
    }

    private function scrub(): void
    {
        $sql = SqlDialect::forDriver(DB::getDriverName());
        foreach (PersonalDataScrub::tables() as $table) {
            if (!Schema::hasTable($table->table)) {
                continue;
            }
            $statement = $table->statement($sql, Schema::getColumnListing($table->table));
            if ($statement !== null) {
                DB::unprepared($statement);
            }
        }
    }

    private function holdersOfCanaries(): array
    {
        $holders = [];
        foreach (['shipping_addresses', 'billing_addresses', 'orders'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                foreach (self::CANARIES as $canary) {
                    if (str_contains(json_encode($row, JSON_UNESCAPED_UNICODE), $canary)) {
                        $holders[] = "{$table}: {$canary}";
                    }
                }
            }
        }

        return $holders;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('billing_addresses', static function (Blueprint $table): void {
            $table->id();
            $table->string('contact_person_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('zip')->nullable();
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
        });
        if (!Schema::hasColumn('orders', 'billing_address_data')) {
            Schema::table('orders', static fn (Blueprint $table) => $table->longText('billing_address_data')->nullable());
        }

        $snapshot = $this->canarySnapshot();
        DB::table('shipping_addresses')->insert(array_intersect_key($snapshot, array_flip(['contact_person_name', 'phone', 'address', 'city', 'zip', 'latitude', 'longitude', 'district', 'national_address', 'city_id', 'region_id'])));
        DB::table('billing_addresses')->insert(['contact_person_name' => 'CANARY-NAME', 'phone' => '0599900111', 'address' => 'CANARY-STREET-9', 'zip' => '12345', 'latitude' => '24.713655', 'longitude' => '46.675322']);
        $this->makeOrder(300, ['shipping_address_data' => json_encode($snapshot, JSON_UNESCAPED_UNICODE), 'billing_address_data' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);
    }

    public function test_a_staging_copy_keeps_no_national_address_district_or_coordinate_anywhere(): void
    {
        $this->assertNotSame([], $this->holdersOfCanaries(), 'the fixture really holds the canaries');

        $this->scrub();

        $this->assertSame([], $this->holdersOfCanaries());
    }

    public function test_the_scrub_keeps_the_ids_and_the_place_names_that_identify_nobody(): void
    {
        $this->scrub();

        $order = json_decode((string) DB::table('orders')->where('id', 300)->value('shipping_address_data'), true);
        $this->assertSame($this->ids['riyadh'], $order['city_id']);
        $this->assertSame($this->ids['region_RUH'], $order['region_id']);
        $this->assertSame(['الرياض', 'Riyadh', 'منطقة الرياض', 'Riyadh'], [$order['city_name_ar'], $order['city_name_en'], $order['region_name_ar'], $order['region_name_en']]);
        $this->assertSame(['', '', '', ''], [$order['national_address'], $order['district'], $order['latitude'], $order['longitude']]);
        $this->assertSame('', $order['address']);

        $address = DB::table('shipping_addresses')->first();
        $this->assertSame([$this->ids['riyadh'], 'الرياض'], [$address->city_id, $address->city]);
        $this->assertSame(['', ''], [$address->national_address, $address->district]);
    }

    public function test_billing_snapshots_and_a_table_without_the_new_columns_are_scrubbed_too(): void
    {
        $this->scrub();

        $billing = json_decode((string) DB::table('orders')->where('id', 300)->value('billing_address_data'), true);
        $this->assertSame(['', '', ''], [$billing['national_address'], $billing['district'], $billing['address']]);
        $this->assertSame('', DB::table('billing_addresses')->value('address'), 'a table that lacks the new columns is scrubbed for the columns it has');
    }

    public function test_scrubbing_an_old_snapshot_without_the_new_keys_adds_none(): void
    {
        DB::table('orders')->where('id', 300)->update(['shipping_address_data' => json_encode(['contact_person_name' => 'CANARY-NAME', 'phone' => '0599900111', 'address' => 'CANARY-STREET-9', 'city' => 'الرياض', 'zip' => '12345'], JSON_UNESCAPED_UNICODE)]);

        $this->scrub();

        $order = json_decode((string) DB::table('orders')->where('id', 300)->value('shipping_address_data'), true);
        $this->assertEqualsCanonicalizing(['contact_person_name', 'phone', 'address', 'city', 'zip'], array_keys($order), 'MySQL stores the keys of a JSON column in its own order');
    }

    public function test_provider_logs_and_previews_show_the_ids_and_hide_the_household(): void
    {
        $snapshot = $this->canarySnapshot();

        foreach ([false, true] as $strict) {
            $redacted = PiiRedactor::redact($snapshot, $strict);
            foreach (['national_address', 'district', 'address', 'phone', 'latitude', 'longitude', 'contact_person_name'] as $personal) {
                $this->assertSame(PiiRedactor::MASK, $redacted[$personal], ($strict ? 'strict ' : '') . $personal);
            }
            $this->assertSame($this->ids['riyadh'], $redacted['city_id']);
            $this->assertSame($this->ids['region_RUH'], $redacted['region_id']);
        }
        foreach (self::CANARIES as $canary) {
            $this->assertStringNotContainsString($canary, json_encode(PiiRedactor::redact($snapshot)));
            $this->assertStringNotContainsString($canary, json_encode(PiiRedactor::redact($snapshot, true)));
        }
    }

    public function test_the_order_body_keeps_the_provider_ids_and_loses_the_address_line_with_the_district(): void
    {
        $redacted = PiiRedactor::redact(['receiverAddress' => ['cityId' => '36189', 'regionId' => '1204', 'addressLine1' => 'CANARY-STREET-9, CANARY-DISTRICT']]);

        $this->assertSame(['cityId' => '36189', 'regionId' => '1204', 'addressLine1' => PiiRedactor::MASK], $redacted['receiverAddress']);
    }
}
