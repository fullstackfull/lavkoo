<?php

namespace Tests\Feature\Address;

use App\Enums\Fulfillment\MappingStatus;
use App\Models\AddressCity;
use App\Models\ProviderCity;
use App\Models\ProviderCityMapping;
use App\Models\ProviderCitySyncRun;
use App\Services\Fulfillment\Data\CityCandidate;
use App\Services\Fulfillment\ProviderCityMappingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Our city to the provider's city: candidates by exact name, decisions by a person, and the one automatic approval.
 */
class TorodCityMappingServiceTest extends TorodAddressTestCase
{
    private const PROVIDER = 'torod_point';

    private function service(): ProviderCityMappingService
    {
        return app(ProviderCityMappingService::class);
    }

    private function city(string $handle): AddressCity
    {
        return AddressCity::query()->with('region')->findOrFail($this->ids[$handle]);
    }

    /** @return list<string> */
    private function candidateIds(string $handle): array
    {
        return $this->service()->candidatesFor(self::PROVIDER, $this->city($handle))->map(static fn (CityCandidate $candidate): string => (string) $candidate->city->external_id)->all();
    }

    private function completeSync(string $verdict = 'full', string $evidence = 'envelope_total'): ProviderCitySyncRun
    {
        return ProviderCitySyncRun::create([
            'provider' => self::PROVIDER, 'verdict' => $verdict, 'pages' => 3, 'fetched' => 10, 'distinct_ids' => 10, 'request' => ['evidence' => $evidence],
            'started_at' => now(), 'finished_at' => now(),
        ]);
    }

    private function mappingOf(string $handle): ?ProviderCityMapping
    {
        return $this->service()->approvedFor(self::PROVIDER, $this->ids[$handle]);
    }

    // ---- candidates -----------------------------------------------------------------------------------------------

    public function test_riyadh_has_two_candidates_with_what_tells_them_apart_and_none_is_chosen(): void
    {
        $candidates = $this->service()->candidatesFor(self::PROVIDER, $this->city('riyadh'));

        $this->assertSame(['36189', '230194'], $candidates->map(static fn (CityCandidate $candidate): string => (string) $candidate->city->external_id)->all());
        $this->assertSame(['1204', '1265'], $candidates->map(static fn (CityCandidate $candidate): string => (string) $candidate->city->region_external_id)->all());
        $this->assertSame([CityCandidate::REGION_CONFIRMED, CityCandidate::REGION_CONFIRMED], $candidates->pluck('region')->all());
        $this->assertNull($this->mappingOf('riyadh'), 'two candidates never become a mapping by themselves');
    }

    public function test_proposals_are_pending_rows_with_the_evidence_a_person_needs_and_are_written_once(): void
    {
        $this->assertSame(2, $this->service()->proposeCandidates(self::PROVIDER, collect([$this->city('riyadh')])));
        $this->assertSame(0, $this->service()->proposeCandidates(self::PROVIDER, collect([$this->city('riyadh')])), 'asking again writes nothing');

        $rows = ProviderCityMapping::query()->where('address_city_id', $this->ids['riyadh'])->orderBy('external_city_id')->get();
        $this->assertSame(['36189', '230194'], $rows->pluck('external_city_id')->map(static fn ($id): string => (string) $id)->sort()->values()->all());
        $first = $rows->firstWhere('external_city_id', '36189');
        $this->assertSame(MappingStatus::Pending, $first->status);
        $this->assertNull($first->approved_slot);
        $this->assertSame('1204', $first->external_region_id);
        $this->assertSame('exact_name', $first->method);
        $this->assertSame('RYH', $first->evidence['provider_city']['prefix']);
        $this->assertTrue($first->evidence['provider_city']['is_selected']);
        $this->assertSame('Riyadh', $first->evidence['provider_city']['region_name_en']);
        $this->assertSame('Riyadh', $first->evidence['city']['name_en']);
        $this->assertSame(['36189', '230194'], $first->evidence['candidates_considered']);
        $this->assertSame(CityCandidate::REGION_CONFIRMED, $first->evidence['region_check']);
    }

    public function test_names_match_exactly_after_the_comparison_key_and_never_by_similarity(): void
    {
        $this->torodCity('60001', 'مدينة الخبر', 'Al-Khobar City', '1337');
        $this->torodCity('60002', 'الخبر الشمالية', 'North Khobar', '1337');

        $this->assertSame(['50001', '60001'], $this->candidateIds('khobar'), 'the same name with a kind word or the article; not a name that merely contains it');
        $this->assertSame([], $this->candidateIds('diriyah'), 'a city nobody lists has no candidate: nothing is guessed');
    }

    public function test_a_provider_city_that_names_another_of_our_regions_is_not_a_candidate_at_all(): void
    {
        $this->assertSame(['50003'], $this->candidateIds('hafuf_ruh'));
        $this->assertSame(['50002'], $this->candidateIds('hafuf_eas'));
    }

    public function test_a_region_we_cannot_place_leaves_the_candidate_in_but_marks_it(): void
    {
        $taif = $this->service()->candidatesFor(self::PROVIDER, $this->city('taif'));

        $this->assertSame(['50004'], $taif->map(static fn (CityCandidate $candidate): string => (string) $candidate->city->external_id)->all());
        $this->assertSame(CityCandidate::REGION_UNPLACEABLE, $taif->first()->region);
        $this->assertFalse($taif->first()->canBeApprovedAutomatically());
        $this->assertSame(CityCandidate::REGION_UNKNOWN, $this->service()->candidatesFor(self::PROVIDER, $this->city('khobar'))->first()->region, 'a provider city with no region name has nothing to contradict');
    }

    public function test_a_city_the_provider_no_longer_lists_is_not_offered(): void
    {
        ProviderCity::query()->where('external_id', '230194')->update(['missing_since' => now()]);

        $this->assertSame(['36189'], $this->candidateIds('riyadh'));
    }

    // ---- decisions --------------------------------------------------------------------------------------------------

    public function test_approving_one_candidate_records_who_and_why_and_audits_it(): void
    {
        $mapping = $this->service()->approve(provider: self::PROVIDER, cityId: $this->ids['riyadh'], externalCityId: '36189', adminId: 7, adminName: 'Owner');

        $this->assertSame(MappingStatus::Approved, $mapping->status);
        $this->assertSame(ProviderCityMapping::APPROVED_SLOT, $mapping->approved_slot);
        $this->assertSame(7, $mapping->approved_by);
        $this->assertNotNull($mapping->approved_at);
        $this->assertSame('1204', $mapping->external_region_id, 'the region id is stored as the provider gave it');
        $this->assertSame('manual', $mapping->method);
        $this->assertSame(['id' => 7, 'name' => 'Owner'], $mapping->evidence['decisions'][0]['by']);
        $this->assertSame('approved', $mapping->evidence['decisions'][0]['decision']);
        $this->assertSame($mapping->id, $this->mappingOf('riyadh')->id);

        $audit = DB::table('audit_logs')->where('action', 'provider_city_mapping.approved')->first();
        $this->assertSame((string) $mapping->id, (string) $audit->subject_id);
        $this->assertStringContainsString('36189', (string) $audit->after);
    }

    public function test_a_second_approved_mapping_for_a_city_is_refused_until_the_first_is_named_as_replaced(): void
    {
        $first = $this->service()->approve(provider: self::PROVIDER, cityId: $this->ids['riyadh'], externalCityId: '36189', adminId: 7, adminName: 'Owner');

        foreach ([null, $first->id + 100] as $replacing) {
            try {
                $this->service()->approve(provider: self::PROVIDER, cityId: $this->ids['riyadh'], externalCityId: '230194', replacing: $replacing);
                $this->fail('the second mapping was accepted');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('already has an approved mapping', $exception->getMessage());
            }
        }
        $this->assertSame('36189', (string) $this->mappingOf('riyadh')->external_city_id, 'nothing was demoted silently');

        $second = $this->service()->approve(provider: self::PROVIDER, cityId: $this->ids['riyadh'], externalCityId: '230194', adminId: 7, adminName: 'Owner', replacing: $first->id);

        $this->assertSame($second->id, $this->mappingOf('riyadh')->id);
        $this->assertSame(MappingStatus::Retired, $first->refresh()->status);
        $this->assertNull($first->approved_slot);
        $this->assertSame(1, ProviderCityMapping::query()->approved()->where('address_city_id', $this->ids['riyadh'])->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'provider_city_mapping.retired')->count());
    }

    public function test_approving_the_pair_that_is_already_approved_changes_nothing(): void
    {
        $first = $this->service()->approve(provider: self::PROVIDER, cityId: $this->ids['jeddah'], externalCityId: '41126', adminId: 1, adminName: 'A');
        $again = $this->service()->approve(provider: self::PROVIDER, cityId: $this->ids['jeddah'], externalCityId: '41126', adminId: 2, adminName: 'B');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, $again->approved_by);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'provider_city_mapping.approved')->count());
    }

    public function test_rejecting_keeps_a_candidate_from_being_proposed_again_and_an_approved_one_is_retired_not_rejected(): void
    {
        $this->service()->proposeCandidates(self::PROVIDER, collect([$this->city('riyadh')]));

        $rejected = $this->service()->reject(provider: self::PROVIDER, cityId: $this->ids['riyadh'], externalCityId: '230194', adminId: 7, adminName: 'Owner');

        $this->assertSame(MappingStatus::Rejected, $rejected->status);
        $this->assertSame(0, $this->service()->proposeCandidates(self::PROVIDER, collect([$this->city('riyadh')])));
        $this->assertSame(MappingStatus::Rejected, ProviderCityMapping::query()->where('external_city_id', '230194')->first()->status);

        $this->service()->approve(provider: self::PROVIDER, cityId: $this->ids['riyadh'], externalCityId: '36189');
        $this->expectException(InvalidArgumentException::class);
        $this->service()->reject(provider: self::PROVIDER, cityId: $this->ids['riyadh'], externalCityId: '36189');
    }

    public function test_retiring_ends_a_mapping_and_only_an_approved_one_can_be_retired(): void
    {
        $mapping = $this->service()->approve(provider: self::PROVIDER, cityId: $this->ids['jeddah'], externalCityId: '41126', adminId: 7, adminName: 'Owner');

        $this->service()->retire(provider: self::PROVIDER, mappingId: $mapping->id, adminId: 7, adminName: 'Owner');

        $this->assertNull($this->mappingOf('jeddah'));
        $this->assertSame(MappingStatus::Retired, $mapping->refresh()->status);
        $this->assertSame('retired', collect($mapping->evidence['decisions'])->last()['decision']);
        try {
            $this->service()->retire(provider: self::PROVIDER, mappingId: $mapping->id);
            $this->fail('a retired mapping was retired again');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('approved', $exception->getMessage());
        }
        $this->assertSame(MappingStatus::Approved, $this->service()->approve(provider: self::PROVIDER, cityId: $this->ids['jeddah'], externalCityId: '41126')->status, 'a person can approve it again');
    }

    public function test_a_mapping_of_another_provider_cannot_be_retired_through_this_one(): void
    {
        $mapping = $this->service()->approve(provider: self::PROVIDER, cityId: $this->ids['jeddah'], externalCityId: '41126');

        try {
            $this->service()->retire(provider: 'm5azn', mappingId: $mapping->id);
            $this->fail('a mapping was retired under another provider\'s name');
        } catch (ModelNotFoundException) {
            $this->assertNotNull($this->mappingOf('jeddah'), 'it is still approved');
        }
    }

    public function test_a_mapping_can_only_point_at_a_city_the_provider_lists_now(): void
    {
        foreach ([['jeddah', '99999999'], ['jeddah', '']] as [$handle, $externalId]) {
            try {
                $this->service()->approve(provider: self::PROVIDER, cityId: $this->ids[$handle], externalCityId: $externalId);
                $this->fail("{$externalId} was accepted");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('provider\'s city list', $exception->getMessage());
            }
        }

        ProviderCity::query()->where('external_id', '41126')->update(['missing_since' => now()]);
        $this->expectExceptionMessage('no longer lists');
        $this->service()->approve(provider: self::PROVIDER, cityId: $this->ids['jeddah'], externalCityId: '41126');
    }

    public function test_an_id_of_one_provider_is_never_taken_for_another_providers(): void
    {
        $this->torodCity('36189', 'مدينة أخرى', 'Another Place', '5', provider: 'acme_courier');
        $this->torodCity('777', 'الرياض', 'Riyadh', '9', provider: 'acme_courier');

        $this->service()->approve(provider: self::PROVIDER, cityId: $this->ids['riyadh'], externalCityId: '36189');

        $this->assertNull($this->service()->approvedFor('acme_courier', $this->ids['riyadh']), 'the same number at another provider is another city');
        $this->assertSame(['777'], $this->service()->candidatesFor('acme_courier', $this->city('riyadh'))->map(static fn (CityCandidate $candidate): string => (string) $candidate->city->external_id)->all());
        try {
            $this->service()->approve(provider: 'acme_courier', cityId: $this->ids['jeddah'], externalCityId: '41126');
            $this->fail('a Torod Point id was accepted for another provider');
        } catch (InvalidArgumentException) {
            $this->assertNull($this->service()->approvedFor('acme_courier', $this->ids['jeddah']));
        }
        $this->assertSame('36189', (string) $this->mappingOf('riyadh')->external_city_id);
    }

    public function test_the_database_itself_allows_one_approved_mapping_per_provider_and_city(): void
    {
        $this->service()->approve(provider: self::PROVIDER, cityId: $this->ids['riyadh'], externalCityId: '36189');
        $rival = new ProviderCityMapping(['provider' => self::PROVIDER, 'address_city_id' => $this->ids['riyadh'], 'external_city_id' => '230194']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $rival->forceFill(['status' => MappingStatus::Approved, 'approved_slot' => ProviderCityMapping::APPROVED_SLOT])->save();
    }

    // ---- the automatic approval ---------------------------------------------------------------------------------------

    public function test_nothing_is_approved_automatically_unless_the_last_synchronisation_was_complete(): void
    {
        $this->assertSame(['approved' => 0, 'skipped' => 'sync_not_complete'], $this->service()->autoApproveUnique(self::PROVIDER), 'no synchronisation at all');

        $this->completeSync('partial');
        $this->assertSame(['approved' => 0, 'skipped' => 'sync_not_complete'], $this->service()->autoApproveUnique(self::PROVIDER));
        $this->assertSame(0, ProviderCityMapping::query()->approved()->count());

        $this->completeSync('failed');
        $this->assertSame('sync_not_complete', $this->service()->autoApproveUnique(self::PROVIDER)['skipped'], 'a failed attempt does not make the earlier partial list complete');

        $this->completeSync('full');
        $this->assertSame(['approved' => 3, 'skipped' => null], $this->service()->autoApproveUnique(self::PROVIDER));
    }

    public function test_a_list_that_is_only_long_enough_to_look_complete_never_approves_anything_by_itself(): void
    {
        foreach (['distinct_floor', 'none', ''] as $weak) {
            $this->completeSync('full', $weak);
            $this->assertSame(['approved' => 0, 'skipped' => 'completeness_not_proven'], $this->service()->autoApproveUnique(self::PROVIDER), "evidence: '{$weak}'");
        }
        ProviderCitySyncRun::create(['provider' => self::PROVIDER, 'verdict' => 'full', 'pages' => 1, 'fetched' => 10, 'distinct_ids' => 10, 'started_at' => now(), 'finished_at' => now()]);
        $this->assertSame('completeness_not_proven', $this->service()->autoApproveUnique(self::PROVIDER)['skipped'], 'a run that recorded no evidence');
        $this->assertSame(0, ProviderCityMapping::query()->approved()->count());

        foreach (['envelope_total', 'empty_page', 'envelope_last_page'] as $strong) {
            $this->completeSync('full', $strong);
            $this->assertNull($this->service()->autoApproveUnique(self::PROVIDER)['skipped'], "evidence: '{$strong}'");
            ProviderCityMapping::query()->delete();
        }
    }

    public function test_after_a_complete_sync_only_the_one_clean_candidate_is_approved(): void
    {
        $run = $this->completeSync();

        $this->service()->autoApproveUnique(self::PROVIDER);

        $approved = ProviderCityMapping::query()->approved()->get()->mapWithKeys(static fn (ProviderCityMapping $mapping): array => [$mapping->address_city_id => (string) $mapping->external_city_id]);
        $this->assertEquals([
            $this->ids['jeddah'] => '41126',
            $this->ids['makkah'] => '50006',
            $this->ids['khobar'] => '50001',
        ], $approved->all(), 'each has exactly one candidate that nothing contradicts, whose name the provider lists once, and that it does not say is not selected');
        $this->assertNull($this->mappingOf('dammam'), 'Torod says its Dammam is not selected: a person looks at it');
        $this->assertNull($this->mappingOf('hafuf_ruh'), 'Torod lists the name twice, even though the regions tell the two apart: a person approves each');
        $this->assertNull($this->mappingOf('hafuf_eas'));
        $this->assertNull($this->mappingOf('riyadh'), 'Riyadh has two candidates: a person decides');
        $this->assertNull($this->mappingOf('taif'), 'its region name is none of ours: a person looks at it');
        $this->assertNull($this->mappingOf('umm_a'));
        $this->assertNull($this->mappingOf('umm_b'), 'one Torod city for two of ours is nobody\'s to take');
        $this->assertNull($this->mappingOf('diriyah'), 'no candidate at all');

        $mapping = $this->mappingOf('jeddah');
        $this->assertSame('auto_unique', $mapping->method);
        $this->assertNull($mapping->approved_by);
        $this->assertSame($run->id, $mapping->evidence['sync_run_id']);
        $this->assertSame('unique_exact_name', $mapping->evidence['why']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'provider_city_mapping.auto_approved')->count());
    }

    public function test_a_unique_candidate_whose_region_contradicts_ours_is_left_for_a_person(): void
    {
        ProviderCity::query()->where('external_id', '41126')->update(['region_name_en' => 'Eastern Province', 'region_name_ar' => 'المنطقة الشرقية']);
        $this->completeSync();

        $this->service()->autoApproveUnique(self::PROVIDER);

        $this->assertNull($this->mappingOf('jeddah'), 'Torod puts its Jeddah in the Eastern Province; ours is in Makkah');
        $this->assertSame([], $this->candidateIds('jeddah'));
    }

    public function test_a_name_the_provider_lists_twice_is_never_approved_automatically_whatever_its_regions_say(): void
    {
        ProviderCity::query()->where('external_id', '230194')->update(['region_name_en' => 'Makkah', 'region_name_ar' => 'مكة المكرمة']);
        $this->completeSync();

        $this->assertSame(['36189'], $this->candidateIds('riyadh'), 'the second record names another of our regions, so it is no candidate for our Riyadh');
        $this->service()->autoApproveUnique(self::PROVIDER);

        $this->assertNull($this->mappingOf('riyadh'), 'but it is still a second Riyadh in Torod\'s list: nothing is chosen for a person');
    }

    public function test_a_provider_city_that_is_not_selected_is_left_for_a_person_and_one_with_no_say_is_not(): void
    {
        $this->completeSync();
        ProviderCity::query()->where('external_id', '50001')->update(['is_selected' => false]);
        ProviderCity::query()->where('external_id', '41127')->update(['is_selected' => null]);

        $this->service()->autoApproveUnique(self::PROVIDER);

        $this->assertNull($this->mappingOf('khobar'));
        $this->assertSame('41127', (string) $this->mappingOf('dammam')?->external_city_id);
    }

    public function test_a_rejection_a_person_records_while_the_candidates_are_worked_out_is_never_overwritten(): void
    {
        $this->completeSync();
        $this->service()->proposeCandidates(self::PROVIDER, collect([$this->city('jeddah')]));
        $pending = ProviderCityMapping::query()->where('address_city_id', $this->ids['jeddah'])->sole();
        $this->beforeTheNextMappingUpdate(static fn () => DB::table('provider_city_mappings')->where('id', $pending->id)->update(['status' => MappingStatus::Rejected->value]));

        $this->service()->autoApproveUnique(self::PROVIDER);

        $this->assertSame(MappingStatus::Rejected, $pending->fresh()->status, 'the person\'s decision stands');
        $this->assertNull($this->mappingOf('jeddah'));
        $this->assertNotNull($this->mappingOf('khobar'), 'the rest of the run goes on');
    }

    public function test_a_candidate_a_person_approved_for_the_same_city_meanwhile_does_not_break_the_run(): void
    {
        $this->completeSync();
        $this->service()->proposeCandidates(self::PROVIDER, collect([$this->city('jeddah')]));
        $pending = ProviderCityMapping::query()->where('address_city_id', $this->ids['jeddah'])->sole();
        $this->beforeTheNextMappingUpdate(function (): void {
            $this->torodCity('41999', 'جدة', 'Jeddah Old Town', '1336', 'Makkah', 'مكة المكرمة', true, 'JOT');
            $this->service()->approve(provider: self::PROVIDER, cityId: $this->ids['jeddah'], externalCityId: '41999', adminId: 3, adminName: 'Owner');
        });

        $result = $this->service()->autoApproveUnique(self::PROVIDER);

        $this->assertNull($result['skipped']);
        $this->assertSame('41999', (string) $this->mappingOf('jeddah')?->external_city_id, 'the person\'s approval is the mapping');
        $this->assertSame(MappingStatus::Pending, $pending->fresh()->status, 'the proposal it replaced is left as it was');
        $this->assertNotNull($this->mappingOf('khobar'), 'the rest of the run goes on');
    }

    /** Runs this once, right before the first UPDATE of the mapping table the code under test makes: another person acting at that moment. */
    private function beforeTheNextMappingUpdate(callable $action): void
    {
        $done = false;
        DB::beforeExecuting(static function (string $query) use (&$done, $action): void {
            if ($done || preg_match('/^update\s+[`"]provider_city_mappings[`"]/i', $query) !== 1) {
                return;
            }

            $done = true;
            $action();
        });
    }

    public function test_a_person_decision_is_never_overridden_by_the_automatic_approval(): void
    {
        $this->completeSync();
        $jeddah = $this->service()->approve(provider: self::PROVIDER, cityId: $this->ids['jeddah'], externalCityId: '41126');
        $this->service()->retire(provider: self::PROVIDER, mappingId: $jeddah->id);
        $this->service()->reject(provider: self::PROVIDER, cityId: $this->ids['khobar'], externalCityId: '50001');

        $this->service()->autoApproveUnique(self::PROVIDER);

        $this->assertNull($this->mappingOf('jeddah'), 'someone retired it on purpose');
        $this->assertNull($this->mappingOf('khobar'), 'someone rejected it on purpose');
    }

    public function test_a_provider_city_a_person_mapped_to_another_of_our_cities_is_not_taken_again_automatically(): void
    {
        $this->completeSync();
        $this->service()->approve(provider: self::PROVIDER, cityId: $this->ids['umm_a'], externalCityId: '50005');

        $this->service()->autoApproveUnique(self::PROVIDER);

        $this->assertNull($this->mappingOf('umm_b'), 'Torod\'s Umm Sulaym is already the mapping of the other one, which a person chose');
        $this->assertSame(1, ProviderCityMapping::query()->approved()->where('external_city_id', '50005')->count());
    }

    public function test_the_automatic_approval_is_one_summary_in_the_audit_log_and_a_record_on_every_mapping(): void
    {
        $this->completeSync();

        $this->service()->autoApproveUnique(self::PROVIDER);

        $this->assertSame(0, DB::table('audit_logs')->where('action', 'provider_city_mapping.approved')->count());
        $summary = DB::table('audit_logs')->where('action', 'provider_city_mapping.auto_approved')->first();
        $this->assertSame(['provider' => self::PROVIDER, 'approved' => 3], json_decode((string) $summary->after, true));
        $this->assertSame(3, ProviderCityMapping::query()->where('method', 'auto_unique')->count());
    }

    public function test_cities_with_several_candidates_are_listed_as_conflicts_until_one_is_approved(): void
    {
        $this->service()->proposeCandidates(self::PROVIDER);

        $this->assertSame([$this->ids['riyadh']], $this->service()->conflictedCityIds(self::PROVIDER));

        $this->service()->approve(provider: self::PROVIDER, cityId: $this->ids['riyadh'], externalCityId: '36189');
        $this->assertSame([], $this->service()->conflictedCityIds(self::PROVIDER));
    }

    public function test_nothing_in_the_mapping_service_reaches_a_provider(): void
    {
        Http::fake();
        $this->completeSync();

        $this->service()->proposeCandidates(self::PROVIDER);
        $this->service()->autoApproveUnique(self::PROVIDER);
        $this->service()->approve(provider: self::PROVIDER, cityId: $this->ids['riyadh'], externalCityId: '36189');

        Http::assertNothingSent();
    }
}
