<?php

namespace Tests\Feature\SalesChannels;

use App\Models\ChannelReturnDecision;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Returns\ChannelReturnDecisions;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Trendyol\TrendyolConnector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * Deciding a customer's return — the only write in this module that moves a customer's money with no order behind it.
 *
 * **Trendyol documents no idempotency for either decision.** Its pages describe `claims/{claimId}/items/approve` and
 * `claims/{claimId}/issue` with no client-supplied key, no rejection of a repeated request, and nothing about what a second
 * identical call does. So what a second approval does to a refund is not knowable from the documentation, and the design here
 * is one where that question never has to be answered:
 *
 *  - one decision per claim item, ever, by a unique key — so an item cannot be approved twice, and cannot be approved AND
 *    rejected, which is the worse of the two because the requests go to different endpoints and nothing at Trendyol connects
 *    them;
 *  - the row says "may have been sent" BEFORE the request leaves;
 *  - and a fenced row is **never retried and never asked about**, because Trendyol publishes no way to ask what became of one
 *    claim-item decision. That is this file's most important assertion and the honest cost of the design.
 *
 * The shapes asserted are Trendyol's own: approve takes a JSON array, reject is multipart with a COMMA-SEPARATED id string, and
 * a rejection's reason must be an id the instance itself listed.
 */
class TrendyolReturnDecisionsTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const SELLER = '123456';

    private const BASE = 'https://apigw.trendyol.com/integration';

    private const REASONS = self::BASE . '/order/claim-issue-reasons';

    private const APPROVE = self::BASE . '/order/sellers/' . self::SELLER . '/claims/*/items/approve';

    private const REJECT = self::BASE . '/order/sellers/' . self::SELLER . '/claims/*/issue';

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 09:00:00 UTC'));
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        $this->account = app(ChannelAccountService::class)->create(
            ['channel' => 'trendyol', 'name' => 'Trendyol KSA', 'external_account_id' => self::SELLER, 'currency' => 'SAR', 'settings' => ['storefront_code' => 'SA', 'integration_name' => 'PharmacyHub']],
            ['api_key' => 'key-abc-123', 'api_secret' => 'secret-xyz-789'],
            adminId: 1,
        );
        $this->account->forceFill([
            'enabled' => true, 'write_enabled' => true, 'dry_run' => false,
            'capabilities' => ['connection_test', 'read_only_sync', 'order_import'],
            'capability_approvals' => [ChannelOperations::SCOPE_RETURN_DECISIONS => ['by' => 1, 'at' => '2026-11-17T00:00:00Z']],
        ])->save();
        $this->account = $this->account->fresh();
    }

    // ---- one decision per item, ever ------------------------------------------------------------------------------

    public function test_an_item_decided_twice_keeps_the_first_decision(): void
    {
        $decisions = app(ChannelReturnDecisions::class);

        $first = $decisions->decide($this->account, 'CLAIM-1', ['ITEM-A'], ChannelReturnDecision::APPROVE, adminId: 1);
        $again = $decisions->decide($this->account, 'CLAIM-1', ['ITEM-A'], ChannelReturnDecision::APPROVE, adminId: 2);

        $this->assertCount(1, $first['rows']);
        $this->assertSame([], $again['rows'], 'nothing new was decided');
        $this->assertCount(1, $again['already']);
        $this->assertSame(1, ChannelReturnDecision::count());
        $this->assertSame(1, (int) ChannelReturnDecision::firstOrFail()->decided_by, 'and it is the FIRST person who decided');
    }

    public function test_an_item_already_approved_cannot_then_be_rejected(): void
    {
        // The dangerous one: the two decisions go to DIFFERENT endpoints, so nothing at Trendyol would connect them and a
        // customer could be refunded and refused for the same unit.
        $this->fakeReasons();
        $decisions = app(ChannelReturnDecisions::class);
        $decisions->decide($this->account, 'CLAIM-1', ['ITEM-A'], ChannelReturnDecision::APPROVE, adminId: 1);

        $rejected = $decisions->decide($this->account, 'CLAIM-1', ['ITEM-A'], ChannelReturnDecision::REJECT, adminId: 1, reasonId: 51, description: 'the seal is broken');

        $this->assertSame([], $rejected['rows']);
        $this->assertSame(ChannelReturnDecision::APPROVE, ChannelReturnDecision::firstOrFail()->decision);
    }

    // ---- the shapes are Trendyol's --------------------------------------------------------------------------------

    public function test_an_approval_sends_one_call_for_every_item_of_one_claim(): void
    {
        // The endpoints take a LIST of claim items, and the limit is five calls a minute, so three items of one return are one
        // call rather than three.
        Http::fake([self::APPROVE => Http::response('', 200)]);
        $decisions = app(ChannelReturnDecisions::class);
        $decisions->decide($this->account, 'CLAIM-1', ['ITEM-A', 'ITEM-B', 'ITEM-C'], ChannelReturnDecision::APPROVE, adminId: 1);

        $stats = $decisions->send($this->account);

        $this->assertSame(1, $stats['done']);
        $sent = $this->sent(self::APPROVE);
        $this->assertCount(1, $sent, 'one call, three items');
        $this->assertSame('PUT', $sent[0]->method());
        $this->assertSame(['ITEM-A', 'ITEM-B', 'ITEM-C'], json_decode($sent[0]->body(), true)['claimLineItemIdList']);
        $this->assertSame(3, ChannelReturnDecision::where('state', ChannelReturnDecision::DONE)->count());
    }

    public function test_a_rejection_is_multipart_with_a_comma_separated_id_list(): void
    {
        // Trendyol's own two shapes, and they differ from the approval's: multipart rather than JSON, and ONE comma-separated
        // string rather than an array.
        $this->fakeReasons();
        Http::fake([self::REJECT => Http::response('', 200)]);
        $decisions = app(ChannelReturnDecisions::class);
        $decisions->decide($this->account, 'CLAIM-2', ['ITEM-A', 'ITEM-B'], ChannelReturnDecision::REJECT, adminId: 1, reasonId: 51, description: 'the seal is broken');

        $decisions->send($this->account);

        $sent = $this->sent(self::REJECT);
        $this->assertCount(1, $sent);
        $this->assertSame('POST', $sent[0]->method());
        $body = $sent[0]->body();
        $this->assertStringContainsString('multipart/form-data', (string) $sent[0]->header('Content-Type')[0]);
        $this->assertStringContainsString('name="claimItemIdList"', $body);
        $this->assertStringContainsString('ITEM-A,ITEM-B', $body, 'one comma-separated string, not an array');
        $this->assertStringContainsString('name="claimIssueReasonId"', $body);
        $this->assertStringContainsString('51', $body);
    }

    public function test_a_description_longer_than_trendyol_takes_is_cut_rather_than_refused(): void
    {
        $this->fakeReasons();
        Http::fake([self::REJECT => Http::response('', 200)]);
        $decisions = app(ChannelReturnDecisions::class);
        $decisions->decide($this->account, 'CLAIM-3', ['ITEM-A'], ChannelReturnDecision::REJECT, adminId: 1, reasonId: 51, description: str_repeat('x', 900));

        $decisions->send($this->account);

        $this->assertSame(500, mb_strlen((string) ChannelReturnDecision::firstOrFail()->description));
    }

    // ---- a reason is Trendyol's, never ours -----------------------------------------------------------------------

    public function test_a_rejection_reason_that_trendyol_does_not_offer_is_refused_before_anything_is_sent(): void
    {
        // Trendyol's documented example list is not described as complete, so the list is READ from the instance. An id from
        // memory would be refused by Trendyol -- or worse, accepted as a different reason than the person chose.
        $this->fakeReasons();

        $refused = app(ChannelReturnDecisions::class)->decide(
            $this->account, 'CLAIM-4', ['ITEM-A'], ChannelReturnDecision::REJECT, adminId: 1, reasonId: 9999, description: 'because',
        );

        $this->assertSame('return_rejection_reason_is_not_one_trendyol_offers', $refused['refusal']);
        $this->assertSame(0, ChannelReturnDecision::count(), 'and nothing was recorded');
    }

    public function test_a_rejection_with_no_description_is_refused_because_trendyol_requires_one(): void
    {
        $refused = app(ChannelReturnDecisions::class)->decide(
            $this->account, 'CLAIM-5', ['ITEM-A'], ChannelReturnDecision::REJECT, adminId: 1, reasonId: 51, description: '  ',
        );

        $this->assertSame('return_rejection_needs_a_description', $refused['refusal']);
        Http::assertNothingSent();
    }

    public function test_the_reasons_are_read_from_the_instance_and_a_failure_is_not_an_empty_list(): void
    {
        // "We could not ask what the reasons are" must never be read as "there are no reasons", which would refuse every
        // rejection for the wrong cause.
        Http::fake([self::REASONS => Http::response('', 503)]);

        $this->expectException(\RuntimeException::class);
        app(TrendyolConnector::class)->returnRejectReasons($this->account);
    }

    // ---- the fence: a customer is never refunded twice ------------------------------------------------------------

    public function test_a_decision_whose_outcome_is_unknown_waits_for_a_person_and_is_never_sent_again(): void
    {
        // **THE ASSERTION THIS FILE EXISTS FOR.** A 5xx leaves the decision possibly taken, and Trendyol publishes NO endpoint
        // that reports what became of one claim-item decision -- so unlike every other outbox here, there is nothing to ask.
        // Retrying might refund a customer twice; dropping it might leave their return unanswered. A person decides.
        Http::fake([self::APPROVE => Http::response('', 503)]);
        $decisions = app(ChannelReturnDecisions::class);
        $decisions->decide($this->account, 'CLAIM-6', ['ITEM-A'], ChannelReturnDecision::APPROVE, adminId: 1);

        $first = $decisions->send($this->account);

        $this->assertSame(1, $first['needs_review']);
        $row = ChannelReturnDecision::firstOrFail();
        $this->assertSame(ChannelReturnDecision::NEEDS_REVIEW, $row->state);
        $this->assertNotNull($row->sent_at, 'the marker was written BEFORE the request');

        // A second run, however long after, must not send it again.
        $this->travel(2)->hours();
        $again = app(ChannelReturnDecisions::class)->send($this->account);

        $this->assertSame(0, $again['sent']);
        $this->assertCount(1, $this->sent(self::APPROVE), 'exactly one request, ever');
    }

    public function test_a_request_that_never_left_this_host_may_be_decided_again(): void
    {
        // The only road back from the fence: proof that nothing was processed. A connection that was never established is that
        // proof, so the row goes back and nothing is lost.
        Http::fake([self::APPROVE => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect')]);
        $decisions = app(ChannelReturnDecisions::class);
        $decisions->decide($this->account, 'CLAIM-7', ['ITEM-A'], ChannelReturnDecision::APPROVE, adminId: 1);

        $decisions->send($this->account);

        $row = ChannelReturnDecision::firstOrFail();
        $this->assertSame(ChannelReturnDecision::PENDING, $row->state, 'nothing was processed, so it may be sent again');
        $this->assertSame('request_never_left_this_host', $row->failure_code);
    }

    public function test_a_refusal_from_trendyol_rests_the_row_rather_than_retrying_it(): void
    {
        // Trendyol arrived at an answer. Repeating the same request would be refused again, and the refusal is proof the
        // decision was not taken, so the fence comes down and the row rests with the reason.
        Http::fake([self::APPROVE => Http::response(['errors' => [['message' => 'claim is not in WaitingInAction']]], 400)]);
        $decisions = app(ChannelReturnDecisions::class);
        $decisions->decide($this->account, 'CLAIM-8', ['ITEM-A'], ChannelReturnDecision::APPROVE, adminId: 1);

        $stats = $decisions->send($this->account);

        $this->assertSame(1, $stats['failed']);
        $row = ChannelReturnDecision::firstOrFail();
        $this->assertSame(ChannelReturnDecision::FAILED, $row->state);
        $this->assertSame('trendyol_refused_400', $row->failure_code);
    }

    public function test_without_the_approval_nothing_is_sent_and_the_row_keeps_its_decision(): void
    {
        // The scope is its own because this write moves a customer's money. Withdrawing it must stop the sending without
        // losing what a person decided.
        $this->account->forceFill(['capability_approvals' => []])->save();
        Http::fake([self::APPROVE => Http::response('', 200)]);
        $decisions = app(ChannelReturnDecisions::class);
        $decisions->decide($this->account->fresh(), 'CLAIM-9', ['ITEM-A'], ChannelReturnDecision::APPROVE, adminId: 1);

        $stats = $decisions->send($this->account->fresh());

        $this->assertNotNull($stats['stopped']);
        $this->assertSame([], $this->sent(self::APPROVE));
        $row = ChannelReturnDecision::firstOrFail();
        $this->assertSame(ChannelReturnDecision::PENDING, $row->state, 'the decision survives; only the sending stopped');
    }

    // ---- the race the review found, on every engine -------------------------------------------------------------

    public function test_a_decision_another_worker_finished_is_never_claimed_again(): void
    {
        // The state another worker leaves behind: `done`, and `claimed_by` CLEARED -- which is exactly what settling does. The
        // claim used to test only whether the claim was free, so this row matched and was sent a second time.
        Http::fake([self::APPROVE => Http::response('', 200)]);
        $decisions = app(ChannelReturnDecisions::class);
        $decisions->decide($this->account, 'CLAIM-DONE', ['ITEM-A'], ChannelReturnDecision::APPROVE, adminId: 1);
        ChannelReturnDecision::query()->update(['state' => ChannelReturnDecision::DONE, 'claimed_by' => null, 'claimed_at' => null, 'settled_at' => now()]);

        $stats = app(ChannelReturnDecisions::class)->send($this->account);

        $this->assertSame(0, $stats['sent']);
        $this->assertSame([], $this->sent(self::APPROVE), 'a finished decision is never sent again');
        $this->assertSame(ChannelReturnDecision::DONE, ChannelReturnDecision::firstOrFail()->state);
    }

    /**
     * @param string $state a state a decision has already reached
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('settledStates')]
    public function test_the_fence_refuses_to_reopen_a_decision_that_already_reached_an_outcome(string $state): void
    {
        // **A DIRECT TEST OF THE GUARD THAT WAS MISSING.** `aboutToSend()` checked the owner and the lease and not the STATE,
        // so a worker still holding a live claim could move a DONE, FAILED or NEEDS_REVIEW row back to `may_have_sent` and
        // send a second refund. It is private because nothing outside may fence a decision; it is reached by reflection here
        // because a guard whose whole purpose is to refuse deserves a test that asks it to.
        $decisions = app(ChannelReturnDecisions::class);
        $decisions->decide($this->account, 'CLAIM-SETTLED', ['ITEM-A'], ChannelReturnDecision::APPROVE, adminId: 1);
        $row = ChannelReturnDecision::firstOrFail();
        // The row is still OWNED by this run and its lease is live: only the state says it is finished.
        $row->forceFill(['state' => $state, 'claimed_by' => $decisions->runId(), 'claimed_at' => now()])->save();

        // `fenced()` is how the flow asks -- it wraps the conditional update and turns an all-or-nothing rollback into a
        // plain "no".
        $fence = new \ReflectionMethod($decisions, 'fenced');

        $this->assertFalse($fence->invoke($decisions, [$row->fresh()]), 'a settled decision may not be fenced');
        $this->assertSame($state, ChannelReturnDecision::firstOrFail()->state, 'and its state was not touched');
    }

    /** @return array<string, array{0: string}> */
    public static function settledStates(): array
    {
        return [
            'already taken' => [ChannelReturnDecision::DONE],
            'refused by Trendyol' => [ChannelReturnDecision::FAILED],
            'waiting for a person' => [ChannelReturnDecision::NEEDS_REVIEW],
        ];
    }

    public function test_a_group_that_cannot_be_fenced_whole_is_not_fenced_at_all(): void
    {
        // All or nothing. Without the transaction, the rows that COULD be fenced were, and then the send was abandoned -- and
        // `may_have_sent` is not a state anything picks up again, so those rows sat for ever while the customer's return stayed
        // undecided.
        $decisions = app(ChannelReturnDecisions::class);
        $decisions->decide($this->account, 'CLAIM-PARTIAL', ['ITEM-A', 'ITEM-B'], ChannelReturnDecision::APPROVE, adminId: 1);
        $rows = ChannelReturnDecision::orderBy('id')->get();
        ChannelReturnDecision::query()->update(['claimed_by' => $decisions->runId(), 'claimed_at' => now()]);
        // One of the two has already reached an outcome; the other is untouched.
        $rows[1]->forceFill(['state' => ChannelReturnDecision::DONE])->save();

        $fence = new \ReflectionMethod($decisions, 'fenced');

        $this->assertFalse($fence->invoke($decisions, [$rows[0]->fresh(), $rows[1]->fresh()]));
        $this->assertSame(ChannelReturnDecision::PENDING, ChannelReturnDecision::whereKey($rows[0]->id)->value('state'), 'the other row was NOT left fenced');
        $this->assertNull(ChannelReturnDecision::whereKey($rows[0]->id)->value('sent_at'));
    }

    public function test_two_items_refused_in_different_words_each_carry_their_own_words(): void
    {
        // Grouping by claim, decision and reason alone sent the FIRST row's text for the whole group, so a person's reason for
        // refusing one customer was put on another item. A differing description is a different call.
        $this->fakeReasons();
        Http::fake([self::REJECT => Http::response('', 200)]);
        $decisions = app(ChannelReturnDecisions::class);
        $decisions->decide($this->account, 'CLAIM-WORDS', ['ITEM-A'], ChannelReturnDecision::REJECT, adminId: 1, reasonId: 51, description: 'the seal is broken');
        $decisions->decide($this->account, 'CLAIM-WORDS', ['ITEM-B'], ChannelReturnDecision::REJECT, adminId: 1, reasonId: 51, description: 'the blister is empty');

        $decisions->send($this->account);

        $sent = $this->sent(self::REJECT);
        $this->assertCount(2, $sent, 'two calls, because the words differ');
        $bodies = implode("\n", array_map(static fn ($request): string => $request->body(), $sent));
        $this->assertStringContainsString('the seal is broken', $bodies);
        $this->assertStringContainsString('the blister is empty', $bodies);
        // And each body carries exactly ONE item, so neither description travelled with the other item.
        foreach ($sent as $request) {
            $this->assertStringNotContainsString('ITEM-A,ITEM-B', $request->body());
        }
    }

    public function test_two_items_refused_in_the_same_words_are_still_one_call(): void
    {
        // The common case must not get slower: five calls a minute is the limit.
        $this->fakeReasons();
        Http::fake([self::REJECT => Http::response('', 200)]);
        $decisions = app(ChannelReturnDecisions::class);
        $decisions->decide($this->account, 'CLAIM-SAME', ['ITEM-A', 'ITEM-B'], ChannelReturnDecision::REJECT, adminId: 1, reasonId: 51, description: 'the seal is broken');

        $decisions->send($this->account);

        $sent = $this->sent(self::REJECT);
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('ITEM-A,ITEM-B', $sent[0]->body());
    }

    // ---- the approval has to be grantable, not merely demanded ---------------------------------------------------

    public function test_a_person_can_actually_grant_the_return_decisions_approval(): void
    {
        // **THE GAP MY OTHER TESTS HID.** Every test above writes `capability_approvals` straight onto the account, which
        // proves the gate CLOSES and proves nothing about whether it can be opened. The scope was demanded by two operations
        // and was missing from `ChannelAccountService::EXTRA_SCOPES`, so `approveScope()` -- the only path a person has --
        // threw "is not a kind of write that is approved on its own". The approval could never be granted, so the decisions
        // could never be sent at all, and not one test noticed.
        //
        // It took merging this branch with PR-F, whose hygiene guard compares every scope an operation DEMANDS against the
        // scopes a person can be OFFERED, to make it visible. So this test goes through the real path.
        $account = $this->account->fresh();
        $account->forceFill(['capability_approvals' => [], 'connection_status' => 'ok', 'connection_checked_at' => now()])->save();

        app(ChannelAccountService::class)->approveScope($account->fresh(), ChannelOperations::SCOPE_RETURN_DECISIONS, adminId: 1, reason: 'the pharmacist decides returns');

        $this->assertArrayHasKey(ChannelOperations::SCOPE_RETURN_DECISIONS, (array) $account->fresh()->capability_approvals);
        $this->assertContains(ChannelOperations::SCOPE_RETURN_DECISIONS, ChannelAccountService::EXTRA_SCOPES, 'and it is offered, not only demanded');
    }

    public function test_every_scope_the_return_operations_demand_is_one_a_person_can_be_asked_for(): void
    {
        // The general form of the same thing, kept here as well as in the hygiene guard on another branch: an operation that
        // demands an approval nobody can grant is an operation that can never be made.
        foreach (['trendyol.claims_approve', 'trendyol.claims_reject'] as $operation) {
            foreach (ChannelOperations::scopes($operation) as $scope) {
                $this->assertContains($scope, ChannelAccountService::EXTRA_SCOPES, "{$operation} demands '{$scope}', which nobody can be asked to approve");
            }
        }
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    private function fakeReasons(): void
    {
        // The shape Trendyol documents: a bare array of {id, name}, names in Turkish.
        Http::fake([self::REASONS => Http::response([
            ['id' => 1, 'name' => 'Ürün kusurlu/hasarlı'],
            ['id' => 51, 'name' => 'Ürün kullanılmış'],
            ['id' => 201, 'name' => 'Eksik parça'],
        ])]);
    }

    /** @return list<Request> */
    private function sent(string $pattern): array
    {
        $prefix = rtrim(explode('*', $pattern)[0], '/');

        return Http::recorded(static fn (Request $request): bool => str_starts_with($request->url(), $prefix) && $request->method() !== 'GET')
            ->values()->map(static fn (array $pair): Request => $pair[0])->all();
    }
}
