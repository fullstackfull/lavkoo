<?php

namespace Tests\Feature\Address;

use App\Models\AddressCity;
use App\Models\AddressRegion;
use App\Services\Address\AddressDataFiles;
use Illuminate\Support\Facades\DB;

/**
 * Loading the lists that ship with the application from the admin: a preview that writes nothing, then the import for real. Audited,
 * rate limited, never deleting, and keeping what an administrator changed.
 */
class AdminAddressImportTest extends AdminAddressTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->signIn();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /** @param array<string, mixed> $meta more of the file's own description */
    private function writeFile(array $body, string $source = 'test_source', array $meta = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'addr_') . '.json';
        file_put_contents($path, json_encode(['_meta' => ['source' => $source, 'snapshot_date' => '2026-01-01'] + $meta] + $body, JSON_UNESCAPED_UNICODE));

        return $this->files[] = $path;
    }

    /** Points the application at a small data set instead of the shipped one. */
    private function smallDataSet(array $extraCities = []): void
    {
        config([
            'address.seed.regions' => $this->writeFile(['regions' => [
                ['ref' => '1', 'code' => 'SA-01', 'name_ar' => 'منطقة الرياض', 'name_en' => 'Riyadh', 'sort' => 1],
                ['ref' => '2', 'code' => 'SA-02', 'name_ar' => 'منطقة مكة المكرمة', 'name_en' => 'Makkah', 'sort' => 2],
            ]]),
            'address.seed.cities' => $this->writeFile(['cities' => array_merge([
                ['ref' => '3', 'region' => '1', 'ar' => 'الرياض', 'en' => 'Riyadh', 'lat' => 24.7, 'lon' => 46.73],
                ['ref' => '18', 'region' => '2', 'ar' => 'جدة', 'en' => 'Jeddah', 'lat' => 21.48, 'lon' => 39.18],
            ], $extraCities)]),
            'address.seed.aliases' => $this->writeFile(['aliases' => [['alias' => 'العاصمة', 'city_ref' => '3']]], source: 'curated_test', meta: ['city_source' => 'test_source']),
        ]);
    }

    public function test_the_page_describes_the_files_that_will_be_loaded(): void
    {
        $html = $this->get(route('admin.address-system.import.index'))->assertOk()->getContent();

        foreach (['regions.json', 'cities.json', 'aliases.json', 'nad_snapshot_2022', 'curated_aliases', '2022-01-11', '4,581'] as $expected) {
            $this->assertStringContainsString($expected, $html, $expected);
        }
        $this->assertStringContainsString(route('admin.address-system.import.preview'), $html);
        $this->assertStringNotContainsString(route('admin.address-system.import.apply'), $html, 'the way to write comes after the preview');
    }

    public function test_the_description_of_a_file_survives_a_missing_or_broken_one(): void
    {
        config(['address.seed.cities' => sys_get_temp_dir() . '/no-such-file.json', 'address.seed.aliases' => $this->files[] = tempnam(sys_get_temp_dir(), 'addr_')]);
        file_put_contents(config('address.seed.aliases'), '{broken');

        $files = app(AddressDataFiles::class)->describe();

        $this->assertFalse($files['cities']['readable']);
        $this->assertFalse($files['aliases']['readable']);
        $this->assertTrue($files['regions']['readable']);
        $this->assertSame(13, $files['regions']['rows']);
        $this->get(route('admin.address-system.import.index'))->assertOk()->assertSee(e(translate('address_import_unreadable')), false);
    }

    public function test_a_preview_of_the_shipped_data_reports_what_would_change_and_writes_nothing(): void
    {
        $this->post(route('admin.address-system.import.preview'))->assertRedirect(route('admin.address-system.import.index'));

        $report = session('address_import_report');
        $this->assertFalse($report['applied']);
        $this->assertSame(13, $report['regions']['created']);
        $this->assertSame(4581, $report['cities']['created']);
        $this->assertSame(0, AddressRegion::count());
        $this->assertSame(0, AddressCity::count());
        $this->assertSame([], $this->auditActions(), 'looking is not an action to record');

        $html = $this->withSession(['address_import_report' => $report])->get(route('admin.address-system.import.index'))->assertOk()->getContent();
        $this->assertStringContainsString('4,581', $html);
        $this->assertStringContainsString(e(translate('address_import_result_preview')), $html);
        $this->assertStringContainsString(route('admin.address-system.import.apply'), $html);
        $this->assertStringContainsString('name="confirm"', $html);
    }

    public function test_the_import_needs_the_confirmation_and_without_it_writes_nothing(): void
    {
        $this->smallDataSet();

        $this->post(route('admin.address-system.import.apply'))->assertSessionHasErrors('confirm');
        $this->post(route('admin.address-system.import.apply'), ['confirm' => '0'])->assertSessionHasErrors('confirm');

        $this->assertSame(0, AddressCity::count());
        $this->assertSame([], $this->auditActions());
    }

    public function test_the_import_writes_the_lists_and_is_recorded_against_the_administrator(): void
    {
        $this->smallDataSet();

        $this->post(route('admin.address-system.import.apply'), ['confirm' => '1'])->assertRedirect(route('admin.address-system.import.index'));

        $this->assertSame(2, AddressRegion::count());
        $this->assertSame(2, AddressCity::count());
        $this->assertSame(1, DB::table('address_city_aliases')->count());
        $report = session('address_import_report');
        $this->assertTrue($report['applied']);
        $this->assertSame(2, $report['cities']['created']);

        $audit = DB::table('audit_logs')->where('action', 'address.imported')->sole();
        $this->assertSame('admin', $audit->actor_type);
        $this->assertSame(1, (int) $audit->actor_id);
        $this->assertSame('test_source', json_decode($audit->context, true)['cities_source']);
        $this->assertSame(2, json_decode($audit->after, true)['cities']['created']);
    }

    public function test_after_an_import_the_page_shows_the_result_and_no_second_apply_button(): void
    {
        $this->smallDataSet();
        $this->post(route('admin.address-system.import.apply'), ['confirm' => '1']);

        $html = $this->withSession(['address_import_report' => session('address_import_report')])->get(route('admin.address-system.import.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('address_import_result_applied')), $html);
        $this->assertStringNotContainsString(route('admin.address-system.import.apply'), $html);
    }

    public function test_importing_twice_changes_nothing_the_second_time(): void
    {
        $this->smallDataSet();
        $this->post(route('admin.address-system.import.apply'), ['confirm' => '1']);
        $cities = AddressCity::count();

        $this->post(route('admin.address-system.import.apply'), ['confirm' => '1']);

        $report = session('address_import_report');
        $this->assertSame(0, $report['cities']['created'] + $report['cities']['updated']);
        $this->assertSame($cities, AddressCity::count());
    }

    public function test_an_import_keeps_what_an_administrator_changed_and_never_deletes(): void
    {
        $this->smallDataSet();
        $this->post(route('admin.address-system.import.apply'), ['confirm' => '1']);
        $riyadh = AddressCity::where('source_ref', '3')->firstOrFail();
        $this->post(route('admin.address-system.cities.status', $riyadh->id), ['active' => 0]);
        $this->post(route('admin.address-system.cities.update', $riyadh->id), ['region_id' => $riyadh->region_id, 'name_ar' => 'الرياض (العاصمة)', 'name_en' => 'Riyadh', 'sort_order' => 0]);
        $this->smallDataSet();

        $this->post(route('admin.address-system.import.apply'), ['confirm' => '1']);

        $kept = AddressCity::findOrFail($riyadh->id);
        $this->assertFalse($kept->is_active, 'switching a place off is never an import decision');
        $this->assertSame('الرياض (العاصمة)', $kept->name_ar, 'a name an administrator chose is kept');
        $this->assertSame(2, AddressCity::count());
    }

    public function test_problems_found_in_the_files_are_listed_for_the_administrator(): void
    {
        $this->smallDataSet([['ref' => '99', 'region' => '77', 'ar' => 'مجهولة', 'en' => 'Orphan']]);

        $this->post(route('admin.address-system.import.preview'));
        $report = session('address_import_report');
        $html = $this->withSession(['address_import_report' => $report])->get(route('admin.address-system.import.index'))->assertOk()->getContent();

        $this->assertSame(1, $report['cities']['skipped']);
        $this->assertStringContainsString('ref 99: region 77 is not in the region list', $html);
        $this->assertStringContainsString(e(translate('address_import_problems')), $html);
    }

    public function test_an_unreadable_file_is_reported_not_a_server_error(): void
    {
        config(['address.seed.cities' => sys_get_temp_dir() . '/no-such-cities-file.json']);

        $this->post(route('admin.address-system.import.preview'))->assertRedirect(route('admin.address-system.import.index'));
        $this->post(route('admin.address-system.import.apply'), ['confirm' => '1'])->assertRedirect(route('admin.address-system.import.index'));

        $this->assertSame(0, AddressRegion::count(), 'the regions file alone is not imported behind a failed run');
        $this->assertNotEmpty(array_filter($this->toasts(), static fn (string $toast): bool => str_contains($toast, 'no-such-cities-file.json')));
        $this->assertNull(session('address_import_report'));
    }

    public function test_the_preview_and_the_import_are_rate_limited_each_in_its_own_bucket(): void
    {
        $this->smallDataSet();

        foreach (range(1, 6) as $attempt) {
            $this->post(route('admin.address-system.import.preview'))->assertRedirect();
        }
        $this->post(route('admin.address-system.import.preview'))->assertStatus(429);

        foreach (range(1, 3) as $attempt) {
            $this->post(route('admin.address-system.import.apply'), ['confirm' => '1'])->assertRedirect();
        }
        $this->post(route('admin.address-system.import.apply'), ['confirm' => '1'])->assertStatus(429);
    }

    public function test_the_shipped_data_imports_completely_from_the_admin(): void
    {
        $this->post(route('admin.address-system.import.apply'), ['confirm' => '1'])->assertRedirect();

        $this->assertSame(13, AddressRegion::count());
        $this->assertSame(4581, AddressCity::count());
        $this->assertTrue(AddressCity::query()->where('name_en', 'Riyadh')->where('name_ar', 'الرياض')->exists());
        $this->assertSame([], session('address_import_report')['problems']);
    }
}
