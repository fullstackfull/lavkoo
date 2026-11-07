<?php

namespace Tests\Feature\Address;

use App\Models\AddressCity;
use App\Models\AddressCityAlias;
use App\Models\AddressRegion;
use App\Services\Address\AddressText;
use App\Services\Address\LegacyReview;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The queue of old free-text cities: grouped by what they say, read with the matcher's verdict and candidates, settled one text at a time
 * by a person (an alias plus the city for every address typed that way, in one step), or applied in bulk where one city is certain.
 */
class AdminLegacyReviewTest extends AdminAddressTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->signInWithTheCatalog();
    }

    private function confirm(string $text, int|string $cityId)
    {
        return $this->post(route('admin.address-system.legacy.confirm'), ['text' => $text, 'city_id' => $cityId]);
    }

    /** @return array<string, int> */
    private function queueFixtures(): array
    {
        return [
            'riyadh 1' => $this->address(['city' => 'الرياض']),
            'riyadh 2' => $this->address(['city' => 'الرياض']),
            'riyadh spelled differently' => $this->address(['city' => 'رياض']),
            'hafuf 1' => $this->address(['city' => 'الهفوف']),
            'hafuf 2' => $this->address(['city' => 'الهفوف ']),
            'hafuf east' => $this->address(['city' => 'الهفوف', 'state' => 'المنطقة الشرقية']),
            'jeddah in makkah' => $this->address(['city' => 'جدة', 'state' => 'منطقة الرياض']),
            'gotham' => $this->address(['city' => 'Gotham']),
        ];
    }

    // ---- the queue ----------------------------------------------------------------------------------------------------------------

    public function test_the_queue_groups_spellings_by_their_normalised_text_with_row_counts_and_the_matchers_reading(): void
    {
        $this->queueFixtures();
        $this->address(['city' => 'الرياض', 'country' => 'United Arab Emirates']);
        $this->address(['city' => 'جدة', 'city_id' => $this->ids['jeddah'], 'region_id' => $this->ids['region_MAK']]);

        $groups = app(LegacyReview::class)->groups();

        $this->assertSame([3, 3, 1, 1], $groups->map(fn ($group) => $group->rows)->all(), 'riyadh, hafuf, jeddah, gotham; other countries and structured addresses are not in the queue');
        $byKey = $groups->keyBy('key');
        $this->assertEqualsCanonicalizing(['رياض', 'هفوف', 'جده', 'gotham'], $groups->pluck('key')->all());
        $this->assertSame(3, $byKey[AddressText::key('الرياض')]->rows, 'the same text typed with and without the article is one group');
        $this->assertSame('الرياض', $byKey[AddressText::key('الرياض')]->sample(), 'the most used spelling names the group');

        $read = fn (string $text) => app(LegacyReview::class)->read($byKey[AddressText::key($text)])->match;
        $this->assertSame('matched', $read('الرياض')->status->value);
        $this->assertSame($this->ids['riyadh'], $read('الرياض')->city->id);
        $this->assertSame('ambiguous', $read('الهفوف')->status->value, 'two states and no state: the most cautious reading of the group');
        $this->assertEqualsCanonicalizing([$this->ids['hafuf_ruh'], $this->ids['hafuf_eas']], $read('الهفوف')->candidates->pluck('id')->all());
        $this->assertSame('conflict', $read('جدة')->status->value);
        $this->assertSame([$this->ids['jeddah']], $read('جدة')->candidates->pluck('id')->all());
        $this->assertSame('none', $read('Gotham')->status->value);
        $this->assertTrue($read('Gotham')->candidates->isEmpty());
    }

    public function test_the_page_shows_each_group_with_its_verdict_candidates_and_a_way_to_choose_another_city(): void
    {
        $this->queueFixtures();

        $html = $this->get(route('admin.address-system.legacy.index'))->assertOk()->getContent();

        $this->assertStringContainsString('الرياض', $html);
        $this->assertStringContainsString('Gotham', $html);
        foreach (['matched', 'ambiguous', 'conflict', 'none'] as $status) {
            $this->assertStringContainsString(e(translate("address_match_{$status}")), $html, $status);
        }
        $this->assertStringContainsString('name="city_id" value="' . $this->ids['hafuf_ruh'] . '"', $html, 'a one-click button for each candidate');
        $this->assertStringContainsString('name="city_id" value="' . $this->ids['hafuf_eas'] . '"', $html);
        $this->assertStringContainsString(route('admin.address-system.legacy.confirm'), $html);
        $this->assertStringContainsString('data-city-picker', $html);
        $this->assertStringContainsString('data-url="' . route('address.cities') . '"', $html, 'the picker reuses the public city search');
        $this->assertStringContainsString(route('admin.address-system.legacy.apply'), $html);
    }

    public function test_a_text_that_each_state_reads_as_a_different_city_offers_no_one_click_choice_for_all_of_them(): void
    {
        $this->address(['city' => 'الهفوف', 'state' => 'المنطقة الشرقية']);
        $this->address(['city' => 'الهفوف', 'state' => 'منطقة الرياض']);

        $group = app(LegacyReview::class)->read(app(LegacyReview::class)->groups()->sole());
        $html = $this->get(route('admin.address-system.legacy.index'))->assertOk()->getContent();

        $this->assertSame('matched', $group->match->status->value);
        $this->assertNull($group->match->city, 'there is no single city to confirm');
        $this->assertEqualsCanonicalizing([$this->ids['hafuf_ruh'], $this->ids['hafuf_eas']], $group->match->candidates->pluck('id')->all());
        $this->assertStringContainsString(e(translate('address_legacy_several_cities_note')), $html);
        $this->assertStringNotContainsString('name="city_id" value="' . $this->ids['hafuf_eas'] . '"', $html);
        $this->assertStringContainsString('data-city-picker', $html, 'a person can still choose one city for the whole text');
    }

    public function test_a_candidate_that_has_been_switched_off_is_shown_but_cannot_be_chosen(): void
    {
        AddressCity::whereKey($this->ids['hafuf_eas'])->update(['is_active' => false]);
        $this->address(['city' => 'الهفوف']);

        $html = $this->get(route('admin.address-system.legacy.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('address_candidate_switched_off')), $html);
        $this->assertStringContainsString('disabled', $this->candidateForm($html, $this->ids['hafuf_eas']));
        $this->assertStringNotContainsString('disabled', $this->candidateForm($html, $this->ids['hafuf_ruh']), 'the active candidate stays choosable');
    }

    /** The one-click form of a candidate city: from its opening tag to its closing tag. */
    private function candidateForm(string $html, int $cityId): string
    {
        foreach (explode('<form', $html) as $form) {
            if (str_contains($form, 'name="city_id" value="' . $cityId . '"')) {
                return substr($form, 0, (int) strpos($form, '</form>'));
            }
        }

        $this->fail("no form for city {$cityId}");
    }

    public function test_the_page_summarises_the_addresses_by_outcome_and_notes_the_ones_without_any_city_text(): void
    {
        $this->queueFixtures();
        $this->address(['city' => '']);
        $this->address(['city' => null]);

        $html = $this->get(route('admin.address-system.legacy.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('address_legacy_blank_rows')) . ': 2', $html);
        $this->assertSame(2, app(LegacyReview::class)->blankRows());
        $this->assertSame(['gotham', 'جده', 'رياض', 'هفوف'], app(LegacyReview::class)->groups()->pluck('key')->sort()->values()->all(), 'the blank texts are not a group to decide on');
    }

    public function test_the_queue_is_searchable_and_paged(): void
    {
        foreach (range(1, 30) as $number) {
            foreach (range(1, 31 - $number) as $copy) {
                $this->address(['city' => 'قرية ' . $this->letters($number)]);
            }
        }

        $first = $this->get(route('admin.address-system.legacy.index'))->assertOk()->getContent();
        $second = $this->get(route('admin.address-system.legacy.index', ['page' => 2]))->assertOk()->getContent();

        $this->assertSame(25, $this->pickers($first));
        $this->assertSame(5, $this->pickers($second));
        $this->assertStringContainsString('قرية ' . $this->letters(1), $first, 'the text with the most addresses comes first');
        $this->assertStringNotContainsString('قرية ' . $this->letters(30), $first);

        $found = $this->get(route('admin.address-system.legacy.index', ['q' => 'قرية ' . $this->letters(7)]))->assertOk()->getContent();
        $this->assertSame(1, $this->pickers($found));
    }

    /** How many groups a page lists: each carries one city picker form (the script names the attribute too, without a space before it). */
    private function pickers(string $html): int
    {
        return substr_count($html, ' data-city-picker');
    }

    /** A made-up place name that differs in letters, not in digits (digits would fold to one text). */
    private function letters(int $number): string
    {
        $alphabet = ['ا', 'ب', 'ت', 'ث', 'ج', 'ح', 'خ', 'د', 'ذ', 'ر', 'ز', 'س', 'ش', 'ص', 'ض', 'ط', 'ظ', 'ع', 'غ', 'ف', 'ق', 'ك', 'ل', 'م', 'ن', 'ه', 'و', 'ي', 'ءا', 'ءب'];

        return 'ز' . $alphabet[$number - 1] . 'ي';
    }

    public function test_an_empty_queue_says_there_is_nothing_to_review(): void
    {
        $this->address(['city' => 'الرياض', 'city_id' => $this->ids['riyadh'], 'region_id' => $this->ids['region_RUH'], 'national_address' => 'RAGI2929']);

        $html = $this->get(route('admin.address-system.legacy.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('address_legacy_nothing')), $html);
        $this->assertSame(0, $this->pickers($html));
    }

    // ---- settling one text ---------------------------------------------------------------------------------------------------------

    public function test_confirming_a_city_remembers_the_spelling_and_gives_the_city_to_every_address_typed_that_way(): void
    {
        $ids = $this->queueFixtures();
        $other = $this->address(['city' => 'الخبر']);
        $abroad = $this->address(['city' => 'الهفوف', 'country' => 'United Arab Emirates']);
        $structured = $this->address(['city' => 'الهفوف', 'city_id' => $this->ids['jeddah'], 'region_id' => $this->ids['region_MAK'], 'national_address' => 'JEDD1234']);
        $before = DB::table('shipping_addresses')->orderBy('id')->get()->keyBy('id')->map(fn ($row) => (array) $row)->all();

        $this->confirm('الهفوف', $this->ids['hafuf_eas'])->assertRedirect();

        $after = DB::table('shipping_addresses')->orderBy('id')->get()->keyBy('id')->map(fn ($row) => (array) $row)->all();
        foreach (['hafuf 1', 'hafuf 2', 'hafuf east'] as $label) {
            $id = $ids[$label];
            $this->assertSame($this->ids['hafuf_eas'], (int) $after[$id]['city_id'], $label);
            $this->assertSame($this->ids['region_EAS'], (int) $after[$id]['region_id'], $label);
            $changed = array_keys(array_diff_assoc($after[$id], $before[$id]));
            sort($changed);
            $this->assertSame(['city_id', 'region_id'], $changed, "{$label}: the typed city, state, ZIP, National Address and timestamps stay");
        }
        foreach ([$other, $abroad, $structured, $ids['riyadh 1'], $ids['gotham']] as $id) {
            $this->assertSame($before[$id], $after[$id], 'every other address is untouched');
        }

        $alias = AddressCityAlias::query()->where('normalized_alias', AddressText::key('الهفوف'))->sole();
        $this->assertSame($this->ids['hafuf_eas'], (int) $alias->city_id);
        $this->assertSame('legacy_review', $alias->source);
        $this->assertSame(1, (int) $alias->created_by);
        $this->assertSame('الهفوف', $alias->alias);

        $audit = DB::table('audit_logs')->where('action', 'address.legacy_city_confirmed')->sole();
        $this->assertSame(AddressCity::class, $audit->subject_type);
        $this->assertSame((string) $this->ids['hafuf_eas'], $audit->subject_id);
        $this->assertSame(['alias_id' => $alias->id, 'alias_created' => true, 'addresses' => 3], json_decode($audit->after, true), 'the trail points at the alias; the typed text is not copied into it');
        $this->assertContains(translate('address_legacy_confirmed') . ' ' . translate('address_addresses_updated') . ': 3', $this->toasts());
    }

    public function test_the_same_text_typed_with_other_spellings_of_it_is_covered_too(): void
    {
        $withArticle = $this->address(['city' => 'الهفوف']);
        $withoutArticle = $this->address(['city' => 'هفوف']);
        $withDiacritics = $this->address(['city' => 'الهُفوف']);
        $english = $this->address(['city' => 'Hafuf']);

        $this->confirm('الهفوف', $this->ids['hafuf_ruh'])->assertRedirect();

        foreach ([$withArticle, $withoutArticle, $withDiacritics] as $id) {
            $this->assertSame($this->ids['hafuf_ruh'], (int) $this->addressRow($id)['city_id']);
        }
        $this->assertNull($this->addressRow($english)['city_id'], 'a different text (another language) is a different decision');
    }

    public function test_a_person_can_settle_a_conflict_and_the_state_text_stays_as_the_customer_typed_it(): void
    {
        $address = $this->address(['city' => 'جدة', 'state' => 'منطقة الرياض']);
        $row = app(LegacyReview::class)->groups()->first();
        $this->assertSame('conflict', app(LegacyReview::class)->read($row)->match->status->value);

        $this->confirm('جدة', $this->ids['jeddah'])->assertRedirect();

        $saved = $this->addressRow($address);
        $this->assertSame($this->ids['jeddah'], (int) $saved['city_id']);
        $this->assertSame($this->ids['region_MAK'], (int) $saved['region_id']);
        $this->assertSame('منطقة الرياض', $saved['state']);
        $this->assertSame('جدة', $saved['city']);
    }

    public function test_a_person_can_choose_a_city_for_a_text_nobody_listed(): void
    {
        $address = $this->address(['city' => 'العاصمة']);

        $this->confirm('العاصمة', $this->ids['riyadh'])->assertRedirect();

        $this->assertSame($this->ids['riyadh'], (int) $this->addressRow($address)['city_id']);
        $match = app(\App\Services\Address\LegacyCityMatcher::class)->match('العاصمة');
        $this->assertTrue($match->matched(), 'from now on the spelling is understood');
        $this->assertSame($this->ids['riyadh'], $match->city->id);
    }

    public function test_confirming_again_finds_nothing_left_and_adds_no_second_alias(): void
    {
        $this->address(['city' => 'الهفوف']);
        $this->confirm('الهفوف', $this->ids['hafuf_eas'])->assertRedirect();

        $this->confirm('الهفوف', $this->ids['hafuf_eas'])->assertSessionHasErrors('text');

        $this->assertSame(1, AddressCityAlias::count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'address.legacy_city_confirmed')->count());
    }

    public function test_a_spelling_already_confirmed_for_one_city_cannot_be_given_to_another(): void
    {
        DB::table('address_city_aliases')->insert(['city_id' => $this->ids['hafuf_ruh'], 'alias' => 'الهفوف', 'normalized_alias' => AddressText::key('الهفوف'), 'source' => 'curated', 'created_at' => now(), 'updated_at' => now()]);
        $address = $this->address(['city' => 'الهفوف']);

        $this->confirm('الهفوف', $this->ids['hafuf_eas'])->assertSessionHasErrors('city_id');
        $this->assertNull($this->addressRow($address)['city_id']);
        $this->assertSame($this->ids['hafuf_ruh'], (int) AddressCityAlias::sole()->city_id, 'the earlier decision stands');

        $this->confirm('الهفوف', $this->ids['hafuf_ruh'])->assertSessionHasNoErrors();
        $this->assertSame($this->ids['hafuf_ruh'], (int) $this->addressRow($address)['city_id']);
        $this->assertSame(1, AddressCityAlias::count(), 'agreeing with the earlier decision adds no row');
        $this->assertSame('curated', AddressCityAlias::sole()->source);
    }

    public function test_a_city_or_region_that_is_switched_off_cannot_be_chosen(): void
    {
        $address = $this->address(['city' => 'Gotham']);
        AddressCity::whereKey($this->ids['dammam'])->update(['is_active' => false]);
        AddressRegion::whereKey($this->ids['region_MAK'])->update(['is_active' => false]);

        $this->confirm('Gotham', $this->ids['dammam'])->assertSessionHasErrors('city_id');
        $this->confirm('Gotham', $this->ids['jeddah'])->assertSessionHasErrors('city_id');

        $this->assertNull($this->addressRow($address)['city_id']);
        $this->assertSame(0, AddressCityAlias::count());
    }

    public function test_bad_input_is_refused_and_changes_nothing(): void
    {
        $address = $this->address(['city' => 'Gotham']);

        $bad = [
            'a city that does not exist' => ['Gotham', 987654],
            'a blank text' => ['', $this->ids['riyadh']],
            'a text with no letters in it' => ['---', $this->ids['riyadh']],
            'a city id that is not a number' => ['Gotham', 'riyadh'],
            'no city' => ['Gotham', ''],
            'a negative city id' => ['Gotham', -3],
            'a text that is far too long' => [str_repeat('م', 1001), $this->ids['riyadh']],
        ];
        foreach ($bad as $label => [$text, $city]) {
            $this->confirm($text, $city)->assertSessionHasErrors(null, null, $label);
        }
        $this->post(route('admin.address-system.legacy.confirm'))->assertSessionHasErrors(['text', 'city_id']);

        $this->assertNull($this->addressRow($address)['city_id']);
        $this->assertSame(0, AddressCityAlias::count());
        $this->assertSame([], $this->auditActions());
    }

    public function test_a_text_too_long_to_be_a_name_cannot_become_an_alias(): void
    {
        $long = str_repeat('م', 200);
        $this->address(['city' => $long]);

        $this->confirm($long, $this->ids['riyadh'])->assertSessionHasErrors('text');

        $this->assertSame(0, AddressCityAlias::count());
    }

    public function test_the_alias_and_every_address_are_written_in_one_transaction(): void
    {
        $ids = [];
        foreach (range(1, 650) as $number) {
            $ids[] = ['customer_id' => (string) $number, 'is_guest' => 0, 'city' => 'الهفوف', 'country' => 'Saudi Arabia', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($ids, 150) as $chunk) {
            DB::table('shipping_addresses')->insert($chunk);
        }
        $last = (int) DB::table('shipping_addresses')->max('id');
        DB::unprepared("CREATE TRIGGER fail_last_address BEFORE UPDATE OF city_id ON shipping_addresses WHEN NEW.id = {$last} BEGIN SELECT RAISE(ABORT, 'simulated failure'); END");

        try {
            app(LegacyReview::class)->confirm(typedText: 'الهفوف', cityId: $this->ids['hafuf_eas'], adminId: 1);
            $this->fail('the second chunk was meant to fail');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('simulated failure', $exception->getMessage());
        }

        $this->assertSame(0, AddressCityAlias::count(), 'no alias without the addresses');
        $this->assertSame(650, DB::table('shipping_addresses')->whereNull('city_id')->count(), 'and no address changed, not even the ones of the chunk that went through');
        $this->assertSame([], $this->auditActions());

        DB::unprepared('DROP TRIGGER fail_last_address');
        $this->assertSame(650, app(LegacyReview::class)->confirm(typedText: 'الهفوف', cityId: $this->ids['hafuf_eas'], adminId: 1));
    }

    // ---- applying every certain match ----------------------------------------------------------------------------------------------

    public function test_applying_all_certain_matches_leaves_ambiguous_and_conflicting_texts_for_a_person(): void
    {
        $ids = $this->queueFixtures();

        $this->post(route('admin.address-system.legacy.apply'))->assertRedirect();

        foreach (['riyadh 1', 'riyadh 2', 'riyadh spelled differently'] as $label) {
            $this->assertSame($this->ids['riyadh'], (int) $this->addressRow($ids[$label])['city_id'], $label);
        }
        $this->assertSame($this->ids['hafuf_eas'], (int) $this->addressRow($ids['hafuf east'])['city_id'], 'the state text settles this one');
        foreach (['hafuf 1', 'hafuf 2', 'jeddah in makkah', 'gotham'] as $label) {
            $this->assertNull($this->addressRow($ids[$label])['city_id'], $label);
        }
        $this->assertContains(translate('address_addresses_updated') . ': 4', $this->toasts());
        $this->assertSame(['address.legacy_backfilled'], $this->auditActions());
    }

    public function test_applying_when_there_is_nothing_certain_says_so(): void
    {
        $this->address(['city' => 'الهفوف']);

        $this->post(route('admin.address-system.legacy.apply'))->assertRedirect();

        $this->assertContains(translate('address_backfill_nothing_to_apply'), $this->toasts());
        $this->assertSame([], $this->auditActions());
    }

    public function test_what_a_person_settled_leaves_the_queue_and_the_rest_stays(): void
    {
        $ids = $this->queueFixtures();
        $this->confirm('Gotham', $this->ids['riyadh'])->assertRedirect();

        $keys = app(LegacyReview::class)->groups()->pluck('key')->all();

        $this->assertNotContains('gotham', $keys);
        $this->assertCount(3, $keys);
        $this->assertSame($this->ids['riyadh'], (int) $this->addressRow($ids['gotham'])['city_id']);
    }
}
