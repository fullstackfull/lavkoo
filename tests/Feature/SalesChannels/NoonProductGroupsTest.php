<?php

namespace Tests\Feature\SalesChannels;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Noon\NoonProductGroups;
use App\Services\SalesChannels\Support\ChannelOperations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * noon's product groups and bulk SKU map — registered, never called, and found by closing a hole in the hygiene guard.
 *
 * The guard counted an operation as "called" at two mentions in `app/`, because a registry entry was expected to contribute
 * one. `NoonOperations` names each operation TWICE — once in `operations()`, once in the path map beside it — so every noon
 * operation passed whether anything called it or not. `noon.sku_batch_map` sat there with `built => false` in its own
 * capability matrix and nothing said a word.
 *
 * Paths and fields from `noon-docs.noonpartners.dev/docs/api-reference/content/*` and `.../catalog/*`, read 2026-11-22.
 *
 * ## The trap most of this file is about
 *
 * **`attribute_codes` and `sku_parents` REPLACE, they do not merge.** noon: "the list you send replaces the current one, so
 * any product you leave out is removed from the group". An upsert built from "the products I am publishing today" leaves the
 * group and the products intact and silently destroys the grouping that made them one listing.
 */
class NoonProductGroupsTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const API = 'https://noon-api-gateway.noon.partners';

    private const UPSERT = self::API . '/content/v1/product/group/upsert';

    private const GET = self::API . '/content/v1/product/group/get';

    private const DELETE = self::API . '/content/v1/product/group/delete';

    private const MAP = self::API . '/catplat/v1/sku/batch-map';

    private SalesChannelAccount $account;

    /** What the next group read answers. `Http::fake()` appends and the first match wins, so overrides go through state. */
    private mixed $groupPages = [];

    /** Set to make the group read fail, for the same reason: a second `Http::fake` for this URL would never be served. */
    private ?int $groupReadStatus = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        $this->account = $this->noonAccount();
        // Grouping and SKU mapping are product writes, and that approval is not in the fixture's default set.
        $this->account->forceFill([
            'capability_approvals' => array_merge((array) $this->account->capability_approvals, ['product_writes' => ['by' => 1]]),
        ])->save();
        $this->account = $this->account->fresh();
        Http::fake([
            self::API . '/identity/public/v1/api/login' => Http::response(['status' => ['status_id' => 0]], 200, ['Set-Cookie' => 'session=abc; Path=/']),
            self::GET => fn () => $this->groupReadStatus !== null
                ? Http::response('', $this->groupReadStatus)
                : Http::response(array_shift($this->groupPages) ?? ['members' => [], 'attribute_codes' => [], 'total_count' => 0]),
        ]);
    }

    // ---- the replace-not-merge trap ------------------------------------------------------------------------------

    public function test_adding_a_member_sends_the_whole_membership_and_not_just_the_new_one(): void
    {
        // **THE DEFECT THIS PREVENTS.** Sending only the new product removes every product already in the group. noon offers
        // one way round it -- "To add one, send the current list plus the new sku_parent" -- so the group is read first.
        $this->groupPages = [[
            'partner_group_ref' => 'PANADOL-500',
            'attribute_codes' => ['colour_name'],
            'members' => [['sku_parent' => 'P-1', 'attributes' => []], ['sku_parent' => 'P-2', 'attributes' => []]],
            'total_count' => 2,
        ]];
        Http::fake([self::UPSERT => Http::response(['partner_group_ref' => 'PANADOL-500', 'status' => ['status_id' => 0]])]);

        $result = app(NoonProductGroups::class)->addMembers($this->account, 'PANADOL-500', 'P-1', ['P-3']);

        $this->assertTrue($result['ok'], (string) $result['refusal']);
        $this->assertSame(1, $result['added']);
        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== self::UPSERT) {
                return false;
            }
            $sent = $request->data()['sku_parents'] ?? [];
            sort($sent);

            return $sent === ['P-1', 'P-2', 'P-3'];
        });
    }

    public function test_a_group_that_could_not_be_read_is_never_written_to(): void
    {
        // Guessing the current membership from our own records would remove any product noon holds that we do not. So the
        // write does not happen at all.
        $this->groupReadStatus = 503;
        Http::fake([self::UPSERT => Http::response(['partner_group_ref' => 'X', 'status' => ['status_id' => 0]])]);

        $result = app(NoonProductGroups::class)->addMembers($this->account, 'PANADOL-500', 'P-1', ['P-3']);

        $this->assertFalse($result['ok']);
        $this->assertStringStartsWith('noon_group_members_not_added_because_the_group_could_not_be_read', (string) $result['refusal']);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::UPSERT);
    }

    public function test_nothing_is_sent_when_every_product_is_already_in_the_group(): void
    {
        $this->groupPages = [['partner_group_ref' => 'G', 'attribute_codes' => [], 'members' => [['sku_parent' => 'P-1', 'attributes' => []]], 'total_count' => 1]];
        Http::fake([self::UPSERT => Http::response(['partner_group_ref' => 'G', 'status' => ['status_id' => 0]])]);

        $result = app(NoonProductGroups::class)->addMembers($this->account, 'G', 'P-1', ['P-1']);

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['added']);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::UPSERT);
    }

    public function test_an_unchanged_list_is_omitted_rather_than_sent_empty(): void
    {
        // noon treats an absent field and an empty list alike as "leave it unchanged". Omitting is the clearer of the two and
        // means a caller who passes nothing cannot accidentally clear anything.
        Http::fake([self::UPSERT => Http::response(['partner_group_ref' => 'G', 'status' => ['status_id' => 0]])]);

        app(NoonProductGroups::class)->replaceMembership($this->account, 'G', [], ['P-1', 'P-2']);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === self::UPSERT && !array_key_exists('attribute_codes', $data) && ($data['sku_parents'] ?? []) === ['P-1', 'P-2'];
        });
    }

    public function test_an_update_never_carries_a_category_or_a_brand(): void
    {
        // noon: "Neither can be changed afterwards." Sending them on an update is at best ignored and at worst a refusal, and
        // either way it implies something this call cannot do.
        Http::fake([self::UPSERT => Http::response(['partner_group_ref' => 'G', 'status' => ['status_id' => 0]])]);

        app(NoonProductGroups::class)->replaceMembership($this->account, 'G', ['colour_name'], ['P-1']);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return !array_key_exists('category', $data) && !array_key_exists('brand', $data);
        });
    }

    // ---- creating ------------------------------------------------------------------------------------------------

    public function test_a_group_is_created_with_a_category_or_a_brand_and_a_whole_membership(): void
    {
        Http::fake([self::UPSERT => Http::response(['partner_group_ref' => 'PANADOL-500', 'status' => ['status_id' => 0]])]);

        $result = app(NoonProductGroups::class)->create($this->account, 'PANADOL-500', ['colour_name'], ['P-1', 'P-2'], category: 'health-medicine-analgesic');

        $this->assertTrue($result['ok'], (string) $result['refusal']);
        $this->assertSame('PANADOL-500', $result['ref']);
        Http::assertSent(fn (Request $request): bool => ($request->data()['category'] ?? null) === 'health-medicine-analgesic');
    }

    public function test_a_create_with_neither_a_category_nor_a_brand_is_refused(): void
    {
        $result = app(NoonProductGroups::class)->create($this->account, 'G', ['colour_name'], ['P-1']);

        $this->assertFalse($result['ok']);
        $this->assertSame('noon_group_create_needs_a_category_or_a_brand', $result['refusal']);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::UPSERT);
    }

    public function test_a_create_needs_at_least_one_attribute_and_one_product(): void
    {
        $result = app(NoonProductGroups::class)->create($this->account, 'G', [], ['P-1'], brand: 'GSK');

        $this->assertSame('noon_group_create_needs_at_least_one_attribute_and_one_product', $result['refusal']);
    }

    public function test_more_products_than_noon_takes_is_refused_rather_than_truncated(): void
    {
        $many = array_map(static fn (int $i): string => 'P-' . $i, range(1, NoonProductGroups::MOST_MEMBERS + 1));

        $result = app(NoonProductGroups::class)->replaceMembership($this->account, 'G', [], $many);

        $this->assertSame('noon_group_takes_at_most_2000_products:2001', $result['refusal']);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::UPSERT);
    }

    // ---- what noon says came of it -------------------------------------------------------------------------------

    public function test_the_refusal_naming_the_other_group_is_carried_through(): void
    {
        // noon: a product "can belong to one group only. If it is already in another of your groups the request fails and
        // names that group; remove it there first." That name is the one thing a person needs, so it is not flattened away.
        Http::fake([self::UPSERT => Http::response(['partner_group_ref' => 'G', 'status' => ['status_id' => 3, 'status_code' => 'ALREADY_GROUPED', 'message' => 'P-1 already belongs to group OTHER-GROUP']])]);

        $result = app(NoonProductGroups::class)->replaceMembership($this->account, 'G', [], ['P-1']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('OTHER-GROUP', (string) $result['refusal']);
    }

    public function test_a_200_with_no_status_id_is_not_a_success(): void
    {
        // The rule this module already applies to every other noon reply: `status_id` is how noon states the outcome, and a
        // body without it has told us nothing — which a 200 does not change.
        Http::fake([self::UPSERT => Http::response(['partner_group_ref' => 'G'])]);

        $result = app(NoonProductGroups::class)->replaceMembership($this->account, 'G', [], ['P-1']);

        $this->assertFalse($result['ok']);
        $this->assertSame('noon_said_nothing_about_the_group', $result['refusal']);
    }

    // ---- reading a group -----------------------------------------------------------------------------------------

    public function test_every_page_of_a_group_is_read_with_noons_own_token(): void
    {
        // noon: `next_token` is opaque — "a token you build yourself is not rejected, it simply returns the wrong page".
        $this->groupPages = [
            ['partner_group_ref' => 'G', 'attribute_codes' => ['colour_name'], 'members' => [['sku_parent' => 'P-1', 'attributes' => ['colour_name' => 'red']]], 'next_token' => 'TOK-2', 'total_count' => 2],
            ['partner_group_ref' => 'G', 'attribute_codes' => ['colour_name'], 'members' => [['sku_parent' => 'P-2', 'attributes' => ['colour_name' => 'blue']]], 'next_token' => '', 'total_count' => 2],
        ];

        $result = app(NoonProductGroups::class)->read($this->account, 'G', 'P-1');

        $this->assertNull($result['problem']);
        $this->assertSame(['P-1', 'P-2'], array_keys($result['members']));
        $this->assertSame(2, $result['total']);
        $this->assertSame(['colour_name'], $result['attribute_codes']);
        // The first page asks with an empty token, the second with noon's own.
        Http::assertSent(fn (Request $request): bool => $request->url() === self::GET && ($request->data()['next_token'] ?? null) === '');
        Http::assertSent(fn (Request $request): bool => $request->url() === self::GET && ($request->data()['next_token'] ?? null) === 'TOK-2');
    }

    public function test_the_page_size_is_noons_documented_maximum(): void
    {
        app(NoonProductGroups::class)->read($this->account, 'G', 'P-1');

        Http::assertSent(fn (Request $request): bool => (int) ($request->data()['page_size'] ?? 0) === 200);
    }

    public function test_a_read_needs_a_reference_and_a_member(): void
    {
        // Both are required on noon's request: `sku_parent` is "sku_parent of any product in the group", so a reference
        // alone cannot read one.
        $result = app(NoonProductGroups::class)->read($this->account, 'G', '');

        $this->assertSame('noon_group_read_needs_a_reference_and_a_member', $result['problem']);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::GET);
    }

    public function test_a_failed_read_is_never_an_empty_group(): void
    {
        $this->groupReadStatus = 500;

        $result = app(NoonProductGroups::class)->read($this->account, 'G', 'P-1');

        $this->assertSame([], $result['members']);
        $this->assertNotNull($result['problem'], 'a 500 was read as "this group has no products"');
    }

    // ---- deleting ------------------------------------------------------------------------------------------------

    public function test_a_group_is_deleted_by_its_reference_alone(): void
    {
        Http::fake([self::DELETE => Http::response(['partner_group_ref' => 'G', 'status' => ['status_id' => 0]])]);

        $result = app(NoonProductGroups::class)->delete($this->account, 'G');

        $this->assertTrue($result['ok'], (string) $result['refusal']);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::DELETE && $request->data() === ['partner_group_ref' => 'G']);
    }

    // ---- the bulk SKU map ----------------------------------------------------------------------------------------

    public function test_each_sku_gets_its_own_answer_and_a_partial_batch_is_not_a_success(): void
    {
        Http::fake([self::MAP => Http::response(['items' => [
            ['partner_sku' => 'SKU-1', 'nsku_child' => 'N1', 'status' => ['status_id' => 0]],
            ['partner_sku' => 'SKU-2', 'nsku_child' => 'N2', 'status' => ['status_id' => 5, 'status_code' => 'NOT_FOUND', 'message' => 'nsku_child does not exist']],
        ]])]);

        $result = app(NoonProductGroups::class)->mapSkus($this->account, ['SKU-1' => 'N1', 'SKU-2' => 'N2']);

        $this->assertFalse($result['ok'], 'a batch with a failed item was reported as a success');
        $this->assertSame(['SKU-1'], $result['mapped']);
        $this->assertArrayHasKey('SKU-2', $result['failed']);
        $this->assertSame([], $result['unanswered']);
    }

    public function test_a_sku_noon_never_mentioned_is_unanswered_and_not_mapped(): void
    {
        // The asymmetry that matters: a 200 listing two of three items says nothing about the third, and reading silence as
        // success would record a mapping that does not exist.
        Http::fake([self::MAP => Http::response(['items' => [['partner_sku' => 'SKU-1', 'nsku_child' => 'N1', 'status' => ['status_id' => 0]]]])]);

        $result = app(NoonProductGroups::class)->mapSkus($this->account, ['SKU-1' => 'N1', 'SKU-2' => 'N2']);

        $this->assertFalse($result['ok']);
        $this->assertSame(['SKU-1'], $result['mapped']);
        $this->assertSame(['SKU-2'], $result['unanswered']);
    }

    public function test_an_item_with_no_status_id_is_a_failure_and_not_a_mapping(): void
    {
        Http::fake([self::MAP => Http::response(['items' => [['partner_sku' => 'SKU-1', 'nsku_child' => 'N1']]])]);

        $result = app(NoonProductGroups::class)->mapSkus($this->account, ['SKU-1' => 'N1']);

        $this->assertSame([], $result['mapped']);
        $this->assertSame('noon_said_nothing_about_this_sku', $result['failed']['SKU-1']);
    }

    public function test_the_deletion_sentinel_is_refused_unless_a_deletion_was_asked_for(): void
    {
        // **noon documents `#del#` inside the `nsku_child` field's own description as the way to DELETE a mapping.** A value
        // that arrived as that literal from a spreadsheet, a feed or a typo would quietly unmap a live SKU.
        $result = app(NoonProductGroups::class)->mapSkus($this->account, ['SKU-1' => NoonProductGroups::UNMAP]);

        $this->assertFalse($result['ok']);
        $this->assertSame('noon_sku_map_will_not_delete_a_mapping_unless_asked:SKU-1', $result['refusal']);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::MAP);
    }

    public function test_a_deletion_asked_for_in_as_many_words_is_sent(): void
    {
        Http::fake([self::MAP => Http::response(['items' => [['partner_sku' => 'SKU-1', 'nsku_child' => NoonProductGroups::UNMAP, 'status' => ['status_id' => 0]]]])]);

        $result = app(NoonProductGroups::class)->mapSkus($this->account, ['SKU-1' => NoonProductGroups::UNMAP], allowUnmapping: true);

        $this->assertTrue($result['ok'], (string) $result['refusal']);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::MAP && ($request->data()['items'][0]['nsku_child'] ?? null) === '#del#');
    }

    public function test_force_map_is_only_sent_when_the_caller_asked_to_override(): void
    {
        // noon: `force_map` "overrides the existing mapping". Sent by default it would silently repoint a SKU that somebody
        // had mapped on purpose.
        Http::fake([self::MAP => Http::response(['items' => [['partner_sku' => 'SKU-1', 'nsku_child' => 'N1', 'status' => ['status_id' => 0]]]])]);

        app(NoonProductGroups::class)->mapSkus($this->account, ['SKU-1' => 'N1']);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::MAP && !array_key_exists('force_map', $request->data()['items'][0] ?? []));
    }

    public function test_a_reply_without_the_documented_items_list_maps_nothing(): void
    {
        Http::fake([self::MAP => Http::response(['status' => ['status_id' => 0]])]);

        $result = app(NoonProductGroups::class)->mapSkus($this->account, ['SKU-1' => 'N1']);

        $this->assertFalse($result['ok']);
        $this->assertSame('noon_sku_map_reply_is_not_the_documented_shape', $result['refusal']);
    }

    public function test_the_writes_are_writes_and_the_read_is_a_read(): void
    {
        foreach (['noon.product_group_upsert', 'noon.product_group_delete', 'noon.sku_batch_map'] as $operation) {
            $this->assertSame(ChannelOperations::WRITE, ChannelOperations::kindOf($operation, 'POST'), "'{$operation}' is not registered as a write.");
        }
        $this->assertSame(ChannelOperations::READ, ChannelOperations::kindOf('noon.product_group_get', 'POST'));
    }
}
