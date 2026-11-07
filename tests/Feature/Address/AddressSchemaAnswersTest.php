<?php

namespace Tests\Feature\Address;

use App\Models\AddressCity;
use App\Services\Address\AddressSchema;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the address system remembers about the database. A web request lives for seconds, but a queue worker or a scheduler lives for days
 * and may have been started before the migrations or the import: it must notice them without a restart, while a long-lived worker is not
 * made to ask the database again and again.
 */
class AddressSchemaAnswersTest extends AddressTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_no_is_asked_again_after_half_a_minute_and_a_yes_about_the_schema_is_kept(): void
    {
        Schema::drop('address_city_aliases');
        Schema::drop('address_cities');
        Schema::drop('address_regions');
        AddressSchema::flush();
        Carbon::setTestNow('2026-11-07 10:00:00');

        $this->assertFalse(AddressSchema::mastersReady());
        (require database_path('migrations/2026_11_07_100000_create_address_master_tables.php'))->up();
        $this->assertFalse(AddressSchema::mastersReady(), 'the "no" is remembered for the rest of the request');

        Carbon::setTestNow('2026-11-07 10:00:29');
        $this->assertFalse(AddressSchema::mastersReady());

        Carbon::setTestNow('2026-11-07 10:00:31');
        $this->assertTrue(AddressSchema::mastersReady(), 'a worker started before the migration notices it');

        Schema::drop('address_city_aliases');
        Carbon::setTestNow('2026-11-07 12:00:00');
        $this->assertTrue(AddressSchema::mastersReady(), 'a "yes" about the schema is not asked again');
    }

    public function test_a_worker_started_before_the_import_notices_the_cities_and_the_last_one_being_switched_off(): void
    {
        $this->assertFalse(AddressSchema::hasCities(), 'nothing imported yet');
        $region = DB::table('address_regions')->insertGetId([
            'country_code' => 'SA', 'code' => 'SA-RUH', 'name_ar' => 'منطقة الرياض', 'name_en' => 'Riyadh', 'normalized_ar' => 'منطقهالرياض', 'normalized_en' => 'riyadh',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('address_cities')->insert([
            'region_id' => $region, 'name_ar' => 'الرياض', 'name_en' => 'Riyadh', 'normalized_ar' => 'رياض', 'normalized_en' => 'riyadh',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Carbon::setTestNow(now()->addSeconds(10));
        $this->assertFalse(AddressSchema::hasCities(), 'still the answer of a few seconds ago');

        Carbon::setTestNow(now()->addSeconds(25));
        $this->assertTrue(AddressSchema::hasCities(), 'the import was noticed without a restart');

        AddressCity::query()->update(['is_active' => false]);
        $this->assertTrue(AddressSchema::hasCities(), 'the same answer again within half a minute');

        Carbon::setTestNow(now()->addSeconds(31));
        $this->assertFalse(AddressSchema::hasCities(), 'and the switch-off is noticed too');
    }

    public function test_the_directory_columns_are_a_question_of_their_own(): void
    {
        $this->assertFalse(AddressSchema::directoryColumnsReady(), 'a store without the provider list');

        $columns = AddressSchema::DIRECTORY_COLUMNS;
        $lastColumn = array_pop($columns);
        Schema::create('provider_cities', function (Blueprint $table) use ($columns) {
            $table->id();
            foreach ($columns as $column) {
                $table->string($column)->nullable();
            }
        });
        AddressSchema::flush();
        $this->assertFalse(AddressSchema::directoryColumnsReady(), 'the list is there but one of the columns the sync promotes is not');

        Schema::table('provider_cities', function (Blueprint $table) use ($lastColumn) {
            $table->string($lastColumn)->nullable();
        });
        AddressSchema::flush();
        $this->assertTrue(AddressSchema::directoryColumnsReady());
    }
}
