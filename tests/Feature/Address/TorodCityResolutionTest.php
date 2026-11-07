<?php

namespace Tests\Feature\Address;

use App\Enums\Fulfillment\CityResolutionStatus;
use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\ProviderCode;
use App\Jobs\Fulfillment\DispatchProviderFulfillment;
use App\Models\ProviderCity;
use App\Models\ProviderCityAlias;
use App\Models\ProviderCityMapping;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentEvent;
use App\Services\Address\AddressSchema;
use App\Services\Fulfillment\Data\CityResolution;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\FulfillmentRecovery;
use App\Services\Fulfillment\ProviderCityMappingService;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use App\Services\Fulfillment\Providers\TorodPoint\TorodCityResolver;
use App\Services\Fulfillment\Support\RequestFingerprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Which Torod Point city an order goes to, decided from the store's own city and an approved mapping — and what happens, with
 * nothing sent, when there is none.
 */
class TorodCityResolutionTest extends TorodAddressTestCase
{
    private function resolver(): TorodCityResolver
    {
        return app(TorodCityResolver::class);
    }

    private function mappings(): ProviderCityMappingService
    {
        return app(ProviderCityMappingService::class);
    }

    private function dispatcher(): ProviderOrderDispatcher
    {
        return app(ProviderOrderDispatcher::class);
    }

    private function orderFor(string $handle, int $orderId = 100, array $overrides = []): ProviderFulfillment
    {
        return $this->torodFulfillment(['shipping_address_data' => $this->snapshotJson($handle, $overrides)], orderId: $orderId);
    }

    private function approve(string $handle, string $externalId): ProviderCityMapping
    {
        return $this->mappings()->approve(provider: 'torod_point', cityId: $this->ids[$handle], externalCityId: $externalId, adminId: 1, adminName: 'Owner');
    }

    private function fakeOrderAccepted(): void
    {
        Http::fake([self::V5 . '/public/fulfillment/order' => Http::response(['id' => 4455, 'barcode' => 'O0015030400001'])]);
    }

    private function rehearsal(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['company_id' => '714', 'dry_run' => true]);
    }

    // ---- the resolution states ------------------------------------------------------------------------------------------

    public function test_a_city_with_no_mapping_and_no_candidate_is_unmapped_with_a_code_and_the_city_name(): void
    {
        $resolution = $this->resolver()->resolveForAddress($this->unifiedSnapshot('riyadh'));

        $this->assertSame(CityResolutionStatus::Unmapped, $resolution->status);
        $this->assertNull($resolution->city);
        $this->assertSame('no_mapping|الرياض', $resolution->holdDetail());
    }

    public function test_one_candidate_waiting_for_a_person_is_pending_review(): void
    {
        $this->mappings()->proposeCandidates('torod_point', collect([\App\Models\AddressCity::findOrFail($this->ids['jeddah'])]));

        $resolution = $this->resolver()->resolveForAddress($this->unifiedSnapshot('jeddah'));

        $this->assertSame(CityResolutionStatus::PendingReview, $resolution->status);
        $this->assertSame(['41126'], $resolution->candidateIds);
        $this->assertSame('pending_review|جدة', $resolution->holdDetail());
    }

    public function test_the_duplicate_riyadh_is_ambiguous_and_names_both_candidates(): void
    {
        $this->mappings()->proposeCandidates('torod_point', collect([\App\Models\AddressCity::findOrFail($this->ids['riyadh'])]));

        $resolution = $this->resolver()->resolveForAddress($this->unifiedSnapshot('riyadh'));

        $this->assertSame(CityResolutionStatus::Ambiguous, $resolution->status);
        $this->assertSame(['36189', '230194'], $resolution->candidateIds);
        $this->assertSame('ambiguous:36189,230194|الرياض', $resolution->holdDetail());
        $this->assertSame(['code' => 'ambiguous', 'candidate_ids' => ['36189', '230194'], 'city' => 'الرياض'], CityResolution::parseDetail($resolution->holdDetail()));
    }

    public function test_an_approved_mapping_resolves_to_exactly_the_provider_city_that_was_approved(): void
    {
        $this->approve('riyadh', '230194');

        $resolution = $this->resolver()->resolveForAddress($this->unifiedSnapshot('riyadh'));

        $this->assertTrue($resolution->isResolved());
        $this->assertSame('230194', (string) $resolution->city->external_id);
        $this->assertSame('1265', (string) $resolution->city->region_external_id);
        $this->assertSame('', $resolution->holdDetail());
    }

    public function test_a_mapping_to_a_city_the_provider_dropped_holds_the_order_instead_of_sending_it_there(): void
    {
        $this->approve('jeddah', '41126');
        ProviderCity::query()->where('external_id', '41126')->update(['missing_since' => now()]);

        $resolution = $this->resolver()->resolveForAddress($this->unifiedSnapshot('jeddah'));

        $this->assertFalse($resolution->isResolved());
        $this->assertSame('provider_city_missing|جدة', $resolution->holdDetail());
    }

    public function test_the_hold_detail_carries_a_name_and_never_address_text(): void
    {
        $snapshot = $this->unifiedSnapshot('riyadh', ['address' => 'SECRET STREET 5 near the mosque', 'phone' => '0551234567']);

        $detail = $this->resolver()->resolveForAddress($snapshot)->holdDetail();

        $this->assertSame('no_mapping|الرياض', $detail);
        foreach (['SECRET', 'mosque', '0551234567', 'RAGI2929', 'Al Olaya'] as $personal) {
            $this->assertStringNotContainsString($personal, $detail);
        }
        $typed = $this->resolver()->resolveForAddress(['city' => "  Riyad|North\n City  "])->holdDetail();
        $this->assertSame('no_mapping|Riyad North City', $typed, 'a typed city is one short line without the separator');
    }

    // ---- preflight, held state, release -----------------------------------------------------------------------------------

    public function test_the_duplicate_riyadh_holds_the_order_with_both_candidates_and_nothing_is_sent(): void
    {
        $this->mappings()->proposeCandidates('torod_point', collect([\App\Models\AddressCity::findOrFail($this->ids['riyadh'])]));
        $fulfillment = $this->orderFor('riyadh');
        Http::fake();

        $result = $this->adapter()->preflight($fulfillment);

        $this->assertFalse($result->ok);
        $this->assertSame(HoldReason::CITY_UNMAPPED, $result->holdReason);
        $this->assertSame('ambiguous:36189,230194|الرياض', $result->detail);
        Http::assertNothingSent();
    }

    public function test_a_list_where_the_copy_should_hold_text_is_a_hold_with_a_reason_not_an_exception(): void
    {
        $this->approve('riyadh', '36189');
        Http::fake();

        $orderId = 100;
        foreach (['phone' => HoldReason::PHONE_INVALID, 'address' => HoldReason::ADDRESS_INCOMPLETE] as $key => $reason) {
            $result = $this->adapter()->preflight($this->orderFor('riyadh', $orderId++, [$key => ['not', 'text']]));

            $this->assertFalse($result->ok, $key);
            $this->assertSame($reason, $result->holdReason, $key);
        }
        $resolution = $this->resolver()->resolveForAddress(['city' => ['الرياض']]);
        $this->assertFalse($resolution->isResolved());
        $this->assertSame('no_mapping|', $resolution->holdDetail());
        Http::assertNothingSent();
    }

    public function test_the_dispatcher_keeps_the_held_order_with_that_detail_and_a_recheck_writes_nothing_new(): void
    {
        $this->mappings()->proposeCandidates('torod_point', collect([\App\Models\AddressCity::findOrFail($this->ids['riyadh'])]));
        $fulfillment = $this->orderFor('riyadh');
        Http::fake();

        $this->dispatcher()->dispatch($fulfillment->id);
        $events = ProviderFulfillmentEvent::count();
        $this->dispatcher()->dispatch($fulfillment->id);

        $held = $fulfillment->refresh();
        $this->assertSame(FulfillmentStatus::Held, $held->status);
        $this->assertSame(HoldReason::CITY_UNMAPPED, $held->hold_reason);
        $this->assertSame('ambiguous:36189,230194|الرياض', $held->last_error_message);
        $this->assertSame($events, ProviderFulfillmentEvent::count(), 'the same reason and detail is not written again');
        Http::assertNothingSent();
    }

    public function test_approving_one_candidate_lets_the_sweep_pick_the_held_order_up_and_a_rehearsal_sends_nothing(): void
    {
        $this->mappings()->proposeCandidates('torod_point', collect([\App\Models\AddressCity::findOrFail($this->ids['riyadh'])]));
        $this->rehearsal();
        $fulfillment = $this->orderFor('riyadh');
        Http::fake();
        $this->dispatcher()->dispatch($fulfillment->id);
        $this->assertSame(FulfillmentStatus::Held, $fulfillment->refresh()->status);

        $this->approve('riyadh', '36189');
        app(FulfillmentRecovery::class)->dispatchDue();
        Queue::assertPushed(DispatchProviderFulfillment::class, fn (DispatchProviderFulfillment $job): bool => true);
        $this->dispatcher()->dispatch($fulfillment->id);

        $this->assertSame(FulfillmentStatus::DryRun, $fulfillment->refresh()->status, 'dry run is untouched: it builds the request and stops');
        $this->assertSame('36189', $fulfillment->meta['payload_preview']['receiverAddress']['cityId']);
        Http::assertNothingSent();
    }

    public function test_a_released_order_without_automatic_sending_waits_for_approval_even_after_the_city_is_settled(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['company_id' => '714', 'dry_run' => false, 'auto_dispatch' => false]);
        $this->approve('riyadh', '36189');
        $fulfillment = $this->orderFor('riyadh');
        $this->fakeOrderAccepted();

        $this->dispatcher()->dispatch($fulfillment->id);

        $this->assertSame(HoldReason::AWAITING_APPROVAL, $fulfillment->refresh()->hold_reason, 'a mapping never sends an order by itself');
        Http::assertNothingSent();
    }

    /** @return array<string, array{0: string}> */
    public static function citiesThatCannotBeSentTo(): array
    {
        return [
            'no mapping and no candidate' => ['nothing'],
            'one candidate waiting for a person' => ['pending'],
            'two candidates (Riyadh listed twice)' => ['ambiguous'],
            'an approved mapping to a city the provider dropped' => ['dropped'],
            'an old address with a city nobody knows' => ['typed'],
        ];
    }

    #[DataProvider('citiesThatCannotBeSentTo')]
    public function test_no_order_request_is_ever_made_while_the_city_is_not_settled(string $case): void
    {
        match ($case) {
            'pending' => $this->mappings()->proposeCandidates('torod_point', collect([\App\Models\AddressCity::findOrFail($this->ids['jeddah'])])),
            'ambiguous' => $this->mappings()->proposeCandidates('torod_point', collect([\App\Models\AddressCity::findOrFail($this->ids['riyadh'])])),
            'dropped' => $this->approve('jeddah', '41126'),
            default => null,
        };
        if ($case === 'dropped') {
            ProviderCity::query()->where('external_id', '41126')->update(['missing_since' => now()]);
        }
        $fulfillment = $case === 'typed'
            ? $this->torodFulfillment(['shipping_address_data' => json_encode(['contact_person_name' => 'Sara', 'phone' => '0551234567', 'address' => 'x street', 'city' => 'Nowhereville'])])
            : $this->orderFor(in_array($case, ['pending', 'dropped'], true) ? 'jeddah' : 'riyadh');
        $this->fakeOrderAccepted();

        $this->dispatcher()->dispatch($fulfillment->id);
        $submitted = $this->adapter()->submit($fulfillment->refresh(), dryRun: false);

        $this->assertSame(FulfillmentStatus::Held, $fulfillment->refresh()->status);
        $this->assertSame(HoldReason::CITY_UNMAPPED, $fulfillment->hold_reason);
        $this->assertSame(SubmissionResult::REJECTED, $submitted->outcome);
        $this->assertSame('city_unmapped', $submitted->errorCode);
        Http::assertNothingSent();
    }

    // ---- what is sent -------------------------------------------------------------------------------------------------------

    public function test_the_approved_candidate_is_the_city_and_region_that_are_sent_and_no_national_address_is_invented(): void
    {
        $this->approve('riyadh', '36189');
        $fulfillment = $this->orderFor('riyadh');
        $this->fakeOrderAccepted();

        $result = $this->submitAsClaimed($this->adapter(), $fulfillment);

        $this->assertSame(SubmissionResult::ACCEPTED, $result->outcome);
        Http::assertSent(function (Request $request): bool {
            $address = $request->data()['receiverAddress'];

            return $address === ['cityId' => '36189', 'regionId' => '1204', 'addressLine1' => 'King Fahd Rd 12, Al Olaya']
                && !str_contains(json_encode($request->data()), 'RAGI2929')
                && !str_contains(json_encode($request->data()), '230194')
                && !array_key_exists('villageId', $address) && !array_key_exists('nationalAddress', $address) && !array_key_exists('nationalAddress', $request->data());
        });
    }

    public function test_the_other_riyadh_is_sent_when_that_is_the_one_a_person_approved(): void
    {
        $this->approve('riyadh', '230194');
        $fulfillment = $this->orderFor('riyadh');
        $this->fakeOrderAccepted();

        $this->submitAsClaimed($this->adapter(), $fulfillment);

        Http::assertSent(fn (Request $request): bool => $request->data()['receiverAddress']['cityId'] === '230194' && $request->data()['receiverAddress']['regionId'] === '1265');
    }

    public function test_preflight_and_a_dry_run_make_no_request_at_all_not_even_to_the_city_list(): void
    {
        $this->approve('riyadh', '36189');
        $fulfillment = $this->orderFor('riyadh');
        Http::fake();

        $this->assertTrue($this->adapter()->preflight($fulfillment)->ok);
        $result = $this->adapter()->submit($fulfillment, dryRun: true);

        $this->assertSame(SubmissionResult::DRY_RUN, $result->outcome);
        $this->assertSame('36189', $result->meta['payload_preview']['receiverAddress']['cityId']);
        $this->assertSame(RequestFingerprint::of($this->payloadOf($fulfillment)), $result->meta['payload_hash']);
        Http::assertNothingSent();
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'apisv2'));
    }

    public function test_a_mapping_retired_between_the_claim_and_the_send_is_rejected_without_a_request(): void
    {
        $mapping = $this->approve('riyadh', '36189');
        $fulfillment = $this->orderFor('riyadh');
        $this->assertTrue($this->adapter()->preflight($fulfillment)->ok);
        $this->mappings()->retire(provider: 'torod_point', mappingId: $mapping->id);
        Http::fake();

        $result = $this->adapter()->submit($fulfillment, dryRun: false);

        $this->assertSame(SubmissionResult::REJECTED, $result->outcome);
        Http::assertNothingSent();
    }

    /** @return array<string, mixed> */
    private function payloadOf(ProviderFulfillment $fulfillment): array
    {
        $order = \App\Models\Order::findOrFail($fulfillment->order_id);
        $city = $this->resolver()->resolveForAddress(app(\App\Services\Fulfillment\Providers\TorodPoint\TorodOrderPayloadBuilder::class)->address($order))->city;

        return app(\App\Services\Fulfillment\Providers\TorodPoint\TorodOrderPayloadBuilder::class)->build($fulfillment->loadMissing('items'), $order, app(\App\Services\Fulfillment\ProviderConnectionManager::class)->config(ProviderCode::TorodPoint), $city);
    }

    // ---- an old address, typed text only --------------------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function spellingsOfRiyadh(): array
    {
        return ['english' => ['Riyadh'], 'upper case' => ['RIYADH'], 'padded' => ['  riyadh '], 'arabic' => ['الرياض'], 'without the article' => ['رياض'], 'with diacritics' => ['الرِّيَاض'], 'transliterated article' => ['Ar Riyadh'], 'hyphenated article' => ['Al-Riyadh'], 'city prefix' => ['مدينة الرياض']];
    }

    #[DataProvider('spellingsOfRiyadh')]
    public function test_an_old_address_reaches_the_approved_mapping_through_the_stores_own_city_match(string $typed): void
    {
        $this->approve('riyadh', '36189');

        $resolution = $this->resolver()->resolveForAddress(['city' => $typed, 'address' => 'x', 'phone' => '0551234567']);

        $this->assertSame('36189', (string) $resolution->city?->external_id, $typed);
    }

    public function test_a_name_that_only_contains_ours_or_is_spelled_differently_is_not_matched(): void
    {
        $this->approve('riyadh', '36189');
        $this->approve('khobar', '50001');

        foreach (['Riyadh North', 'Riyad', 'Alkhobar'] as $typed) {
            $this->assertFalse($this->resolver()->resolveForAddress(['city' => $typed])->isResolved(), $typed);
        }
        $this->assertTrue($this->resolver()->resolveForAddress(['city' => 'Al Khobar'])->isResolved());
    }

    public function test_the_region_text_of_an_old_address_filters_and_never_names_the_city(): void
    {
        $this->approve('hafuf_eas', '50002');
        $this->approve('hafuf_ruh', '50003');

        $this->assertFalse($this->resolver()->resolveForAddress(['city' => 'الهفوف'])->isResolved(), 'two cities of ours share the name');
        $this->assertSame('50002', (string) $this->resolver()->resolveForAddress(['city' => 'الهفوف', 'state' => 'المنطقة الشرقية'])->city?->external_id);
        $this->assertSame('50003', (string) $this->resolver()->resolveForAddress(['city' => 'الهفوف', 'state' => 'منطقة الرياض'])->city?->external_id);
        $this->assertFalse($this->resolver()->resolveForAddress(['city' => 'Riyadh', 'state' => 'Makkah'])->isResolved(), 'a region that is not the city\'s is a conflict, not a reason to look elsewhere');
        $this->assertFalse($this->resolver()->resolveForAddress(['city' => 'Unknown', 'state' => 'جدة'])->isResolved(), 'the state is never a second guess at the city');
    }

    public function test_an_administrators_alias_still_wins_for_an_old_address_and_never_for_one_with_our_city(): void
    {
        $this->resolver()->addAlias('Old Riyadh Spelling', '41126', 1);
        $this->approve('riyadh', '36189');

        $this->assertSame('41126', (string) $this->resolver()->resolveForAddress(['city' => 'old riyadh spelling'])->city?->external_id);
        $this->assertSame('36189', (string) $this->resolver()->resolveForAddress($this->unifiedSnapshot('riyadh', ['city' => 'Old Riyadh Spelling']))->city?->external_id, 'an address with a city of ours is never re-read from its text');
    }

    public function test_what_a_customer_typed_into_the_city_box_never_puts_a_phone_number_or_address_in_the_hold(): void
    {
        $resolution = $this->resolver()->resolveForAddress(['city' => "Gotham 0551234567 sara@example.test\nbuilding 5 | flat 3"]);

        $this->assertFalse($resolution->isResolved());
        $this->assertStringStartsWith('no_mapping|Gotham', $resolution->holdDetail());
        $this->assertStringNotContainsString('0551234567', $resolution->holdDetail());
        $this->assertStringNotContainsString('sara@example.test', $resolution->holdDetail());
        $this->assertStringNotContainsString("\n", $resolution->holdDetail());
    }

    public function test_an_alias_to_a_city_torod_no_longer_lists_holds_the_order_like_a_mapping_would(): void
    {
        $this->resolver()->addAlias('Old Jeddah Spelling', '41126', 1);
        ProviderCity::where('external_id', '41126')->update(['missing_since' => now()]);

        $resolution = $this->resolver()->resolveForAddress(['city' => 'old jeddah spelling']);

        $this->assertFalse($resolution->isResolved());
        $this->assertSame('provider_city_missing|old jeddah spelling', $resolution->holdDetail());
    }

    public function test_an_alias_is_a_decision_that_is_audited_and_that_cannot_match_every_blank_city(): void
    {
        $alias = $this->resolver()->addAlias('Old Jeddah Spelling', '41126', 1);

        $entry = DB::table('audit_logs')->where('action', 'provider_city_alias.saved')->sole();
        $this->assertSame([(string) $alias->id, ['provider' => 'torod_point', 'external_city_id' => '41126']], [$entry->subject_id, json_decode($entry->after, true)]);

        foreach (['.', ' - ', ''] as $blank) {
            try {
                $this->resolver()->addAlias($blank, '41126', 1);
                $this->fail("'{$blank}' became an alias");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('city_alias_spelling_empty', $exception->getMessage());
            }
        }
        $this->assertFalse($this->resolver()->resolveForAddress(['city' => ''])->isResolved());
        $this->assertSame(1, ProviderCityAlias::count());
    }

    public function test_an_alias_can_only_name_a_city_torod_lists(): void
    {
        ProviderCity::where('external_id', '41127')->update(['missing_since' => now()]);

        foreach (['99999', '41127'] as $unlisted) {
            try {
                $this->resolver()->addAlias('Somewhere', $unlisted, 1);
                $this->fail("an alias to {$unlisted} was saved");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('city_alias_city_not_listed', $exception->getMessage());
            }
        }
        $this->assertSame(0, ProviderCityAlias::count());
    }

    public function test_without_a_catalog_an_old_address_is_matched_by_the_cached_list_exactly_as_before(): void
    {
        DB::table('address_cities')->delete();
        AddressSchema::flush();

        $this->assertSame('41126', (string) $this->resolver()->resolveForAddress(['city' => 'جدة'])->city?->external_id);
        $this->assertFalse($this->resolver()->resolveForAddress(['city' => 'الرياض'])->isResolved(), 'two cities of that name in the list: refused, as before');
    }

    // ---- dormant until migrated ----------------------------------------------------------------------------------------------

    public function test_before_the_mapping_tables_exist_the_old_name_matching_is_kept_and_the_state_is_never_a_second_guess(): void
    {
        Schema::drop('provider_city_sync_runs');
        Schema::drop('provider_city_mappings');
        AddressSchema::flush();
        Http::fake([self::V2 . '/addresses/cities*' => Http::response(['data' => [['id' => 41126, 'arabicName' => 'جدة', 'englishName' => 'Jeddah', 'regionId' => 1336]]])]);

        $unmapped = $this->resolver()->resolveForAddress(['city' => ' Riyad North ', 'state' => 'جدة']);
        $synced = $this->adapter()->syncCities();

        $this->assertSame('Riyad North', $unmapped->holdDetail(), 'the detail is the city as typed, as it always was');
        $this->assertSame('41126', (string) $this->resolver()->resolveForAddress(['city' => 'جدة', 'city_id' => 999])->city?->external_id, 'a store that has not migrated reads the typed city, whatever else the snapshot says');
        $this->assertSame(['ok' => true, 'count' => 1], $synced, 'the first version of the sync, with its two result keys');
        $this->assertSame(1, ProviderCity::query()->where('external_id', '41126')->count());
    }

    public function test_with_the_tables_but_not_the_directory_columns_the_sync_is_still_the_old_one(): void
    {
        Schema::table('provider_cities', static function ($table): void {
            $table->dropColumn(['region_name_ar', 'region_name_en', 'prefix', 'is_selected', 'last_seen_at', 'missing_since']);
        });
        AddressSchema::flush();
        Http::fake([self::V2 . '/addresses/cities*' => Http::response(['data' => [['id' => 7, 'arabicName' => 'تبوك', 'englishName' => 'Tabuk', 'regionId' => 1]]])]);

        $result = $this->adapter()->syncCities();

        $this->assertSame(['ok' => true, 'count' => 1], $result);
        $this->assertSame(0, DB::table('provider_city_sync_runs')->count());
    }

    // ---- nothing outside the city mapping moves -----------------------------------------------------------------------------------

    public function test_stock_and_shipping_data_are_not_touched_by_any_part_of_the_city_mapping(): void
    {
        $this->approve('riyadh', '36189');
        $fulfillment = $this->orderFor('riyadh');
        $tables = ['products', 'provider_product_mappings', 'provider_catalog_items', 'order_details', 'provider_fulfillment_items', 'currencies'];
        $before = array_map(static fn (string $table): string => json_encode(DB::table($table)->orderBy('id')->get()), $tables);
        $orderBefore = DB::table('orders')->where('id', 100)->first();
        $this->fakeLastmileList($this->lastmileDirectory(3), ['total' => true]);

        $this->adapter()->syncCities();
        $this->adapter()->preflight($fulfillment);
        $this->adapter()->submit($fulfillment, dryRun: true);
        $this->mappings()->proposeCandidates('torod_point');

        $this->assertSame($before, array_map(static fn (string $table): string => json_encode(DB::table($table)->orderBy('id')->get()), $tables));
        $orderAfter = DB::table('orders')->where('id', 100)->first();
        foreach (['order_amount', 'shipping_address_data', 'order_status', 'payment_status'] as $column) {
            $this->assertEquals($orderBefore->{$column}, $orderAfter->{$column}, $column);
        }
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/product') || str_contains($request->url(), 'inventory') || str_contains($request->url(), '/order'));
    }
}
