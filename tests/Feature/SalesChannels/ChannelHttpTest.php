<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\SalesChannels\IntegrationCapability;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelApiLog;
use App\Services\SalesChannels\Data\ChannelResponse;
use App\Services\SalesChannels\Exceptions\ChannelRateLimitedException;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Support\ChannelHttp;
use App\Services\SalesChannels\Support\ChannelOperations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/** Every URL here is a made-up .example host: no test can reach a real channel (stray requests are refused). */
class ChannelHttpTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const READ = 'trendyol.orders_list';
    private const WRITE = 'trendyol.price_inventory_update';
    private const LOOKUP = 'trendyol.brands';

    private ChannelHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        $this->http = app(ChannelHttp::class);
    }

    private function account(array $overrides = []): SalesChannelAccount
    {
        return SalesChannelAccount::create($overrides + [
            'channel' => 'trendyol', 'name' => 'Test', 'enabled' => true, 'write_enabled' => true, 'dry_run' => false,
            'capabilities' => ['connection_test', 'read_only_sync', 'order_import', 'price_publishing', 'inventory_publishing'],
            'capability_approvals' => ['price_publishing' => ['by' => 1], 'inventory_publishing' => ['by' => 1]],
        ]);
    }

    private function callRead(SalesChannelAccount $account, string $operation = self::READ): ChannelResponse
    {
        return $this->http->send($account, $operation, 'GET', 'https://channel.example/orders', json: null, query: ['page' => 0]);
    }

    private function callWrite(SalesChannelAccount $account): ChannelResponse
    {
        return $this->http->send($account, self::WRITE, 'POST', 'https://channel.example/stock', json: ['items' => [['barcode' => 'B1', 'quantity' => 4]]], scope: 'inventory_publishing');
    }

    private function assertRefused(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected the call to be refused with '{$reason}'.");
        } catch (ChannelUnavailableException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
        Http::assertNothingSent();
    }

    /** Refused, and nothing more left the host than had already left before the call. */
    private function assertRefusedAfterEarlierCalls(string $reason, callable $call): void
    {
        $sentBefore = count(Http::recorded());
        try {
            $call();
            $this->fail("Expected the call to be refused with '{$reason}'.");
        } catch (ChannelUnavailableException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
        Http::assertSentCount($sentBefore);
    }

    public function test_nothing_is_sent_while_the_global_switch_is_off(): void
    {
        config(['sales_channels.enabled' => false]);
        $this->assertRefused(ChannelUnavailableException::GLOBAL_OFF, fn () => $this->callRead($this->account()));
    }

    public function test_nothing_is_sent_for_a_disabled_account(): void
    {
        $this->assertRefused(ChannelUnavailableException::ACCOUNT_OFF, fn () => $this->callRead($this->account(['enabled' => false])));
    }

    public function test_a_call_needs_a_capability_the_account_actually_holds(): void
    {
        $account = $this->account(['capabilities' => ['connection_test']]);
        $this->assertRefused(ChannelUnavailableException::CAPABILITY_OFF, fn () => $this->callRead($account));
    }

    public function test_an_unregistered_operation_or_a_wrong_method_is_refused_not_treated_as_harmless(): void
    {
        $account = $this->account();
        $this->assertRefused(ChannelUnavailableException::UNREGISTERED_OPERATION, fn () => $this->http->send($account, 'trendyol.made_up', 'GET', 'https://channel.example/x'));
        $this->assertRefused(ChannelUnavailableException::UNREGISTERED_OPERATION, fn () => $this->http->send($account, self::READ, 'DELETE', 'https://channel.example/x'));
    }

    /** The account object a run was started with, and the same account as a person changes it while that run is going on. */
    private function changedByAPersonMeanwhile(SalesChannelAccount $held, array $changes): void
    {
        SalesChannelAccount::whereKey($held->id)->firstOrFail()->forceFill($changes)->save();
    }

    public function test_switching_the_account_off_stops_the_next_call_of_a_run_that_is_already_going(): void
    {
        Http::fake(['channel.example/*' => Http::response(['content' => []])]);
        $held = $this->account();
        $this->assertTrue($this->callRead($held)->ok());

        $this->changedByAPersonMeanwhile($held, ['enabled' => false]);

        $this->assertRefusedAfterEarlierCalls(ChannelUnavailableException::ACCOUNT_OFF, fn () => $this->callRead($held));
        $this->assertFalse($held->enabled, 'the object the run holds learns it too');
        Http::assertSentCount(1);
    }

    public function test_forbidding_writes_withdrawing_an_approval_or_switching_the_capability_off_stops_a_run_that_is_already_going(): void
    {
        Http::fake(['channel.example/*' => Http::response(['batchRequestId' => 'abc'])]);
        $held = $this->account();
        $this->assertTrue($this->callWrite($held)->ok());

        $this->changedByAPersonMeanwhile($held, ['write_enabled' => false]);
        $this->assertRefusedAfterEarlierCalls(ChannelUnavailableException::WRITES_OFF_ACCOUNT, fn () => $this->callWrite($held));

        $this->changedByAPersonMeanwhile($held, ['write_enabled' => true, 'capability_approvals' => []]);
        $this->assertRefusedAfterEarlierCalls(ChannelUnavailableException::NOT_APPROVED, fn () => $this->callWrite($held));

        $this->changedByAPersonMeanwhile($held, ['capability_approvals' => ['inventory_publishing' => ['by' => 1]], 'capabilities' => ['connection_test']]);
        $this->assertRefusedAfterEarlierCalls(ChannelUnavailableException::CAPABILITY_OFF, fn () => $this->callWrite($held));
        Http::assertSentCount(1);
    }

    public function test_going_back_to_a_rehearsal_turns_the_next_write_of_a_running_job_into_a_rehearsal(): void
    {
        Http::fake(['channel.example/*' => Http::response(['batchRequestId' => 'abc'])]);
        $held = $this->account();
        $this->assertTrue($this->callWrite($held)->ok());

        $this->changedByAPersonMeanwhile($held, ['dry_run' => true]);

        $this->assertTrue($this->callWrite($held)->isDryRun());
        Http::assertSentCount(1);
    }

    public function test_an_account_deleted_while_a_run_holds_it_is_refused_and_unsaved_changes_to_other_fields_survive_the_refresh(): void
    {
        Http::fake(['channel.example/*' => Http::response(['content' => []])]);
        $held = $this->account();
        $held->name = 'Renamed in memory';
        $this->assertTrue($this->callRead($held)->ok());
        $this->assertSame('Renamed in memory', $held->name);
        $this->assertTrue($held->isDirty('name'));

        SalesChannelAccount::whereKey($held->id)->delete();

        $this->assertRefusedAfterEarlierCalls(ChannelUnavailableException::ACCOUNT_OFF, fn () => $this->callRead($held));
        Http::assertSentCount(1);
    }

    public function test_reads_go_out_when_writes_are_off_everywhere(): void
    {
        config(['sales_channels.external_writes_enabled' => false]);
        Http::fake(['channel.example/*' => Http::response(['content' => []])]);

        $response = $this->callRead($this->account(['write_enabled' => false, 'dry_run' => true]));

        $this->assertTrue($response->ok());
        Http::assertSentCount(1);
    }

    public function test_a_write_is_refused_while_the_environment_forbids_writes(): void
    {
        config(['sales_channels.external_writes_enabled' => false]);
        $this->assertRefused(ChannelUnavailableException::WRITES_OFF_ENVIRONMENT, fn () => $this->callWrite($this->account()));
    }

    public function test_a_write_is_refused_while_the_account_forbids_writes(): void
    {
        $this->assertRefused(ChannelUnavailableException::WRITES_OFF_ACCOUNT, fn () => $this->callWrite($this->account(['write_enabled' => false])));
    }

    public function test_a_write_is_refused_until_a_person_approved_that_kind_of_write(): void
    {
        $this->assertRefused(ChannelUnavailableException::NOT_APPROVED, fn () => $this->callWrite($this->account(['capability_approvals' => []])));
        $this->assertRefused(ChannelUnavailableException::NOT_APPROVED, fn () => $this->callWrite($this->account(['capability_approvals' => ['price_publishing' => ['by' => 1]]])));
    }

    public function test_a_write_declared_under_the_wrong_scope_is_refused(): void
    {
        $account = $this->account();
        $this->assertRefused(ChannelUnavailableException::NOT_APPROVED, fn () => $this->http->send($account, self::WRITE, 'POST', 'https://channel.example/stock', json: [], scope: 'something_else'));
    }

    public function test_a_dry_run_logs_the_request_it_would_have_made_and_sends_nothing_even_with_writes_off(): void
    {
        config(['sales_channels.external_writes_enabled' => false]);
        $account = $this->account(['dry_run' => true, 'write_enabled' => false, 'capability_approvals' => []]);

        $response = $this->callWrite($account);

        $this->assertTrue($response->isDryRun());
        Http::assertNothingSent();
        $log = SalesChannelApiLog::first();
        $this->assertTrue($log->dry_run);
        $this->assertSame('B1', $log->request_summary['body']['items'][0]['barcode']);
    }

    public function test_an_approved_write_on_a_live_account_goes_out(): void
    {
        Http::fake(['channel.example/*' => Http::response(['batchRequestId' => 'abc'])]);

        $response = $this->callWrite($this->account());

        $this->assertTrue($response->ok());
        $this->assertSame('abc', $response->get('batchRequestId'));
        Http::assertSentCount(1);
    }

    public function test_a_read_is_retried_with_back_off_on_a_rate_limit_and_the_channels_own_wait_is_honoured(): void
    {
        Http::fake(['channel.example/*' => Http::sequence()
            ->push(['errors' => [['key' => 'too.many.requests']]], 429, ['Retry-After' => '2'])
            ->push(['errors' => [['key' => 'too.many.requests']]], 429)
            ->push(['content' => ['ok']], 200)]);

        $response = $this->callRead($this->account());

        $this->assertTrue($response->ok());
        $this->assertSame(3, $response->attempts);
        Sleep::assertSleptTimes(2);
        Sleep::assertSlept(fn ($duration): bool => $duration->totalMilliseconds === 2000.0, 1);
        Sleep::assertSlept(fn ($duration): bool => $duration->totalMilliseconds >= 1000.0 && $duration->totalMilliseconds <= 1250.0, 1);
    }

    public function test_a_read_gives_up_after_the_configured_retries_and_says_it_was_rate_limited(): void
    {
        config(['sales_channels.http.retries' => 2]);
        Http::fake(['channel.example/*' => Http::response([], 429)]);

        $response = $this->callRead($this->account());

        $this->assertSame(ChannelResponse::RATE_LIMITED, $response->kind);
        $this->assertSame(3, $response->attempts);
    }

    public function test_a_write_that_sets_absolute_values_is_retried_but_a_creating_write_is_sent_once(): void
    {
        Http::fake(['channel.example/*' => Http::response([], 503)]);
        $account = $this->account(['capabilities' => ['connection_test', 'read_only_sync', 'product_sync', 'price_publishing', 'inventory_publishing'], 'capability_approvals' => ['price_publishing' => ['by' => 1], 'inventory_publishing' => ['by' => 1], 'product_writes' => ['by' => 1]]]);

        $absolute = $this->callWrite($account);
        $creating = $this->http->send($account, 'trendyol.products_create', 'POST', 'https://channel.example/create', json: ['items' => []]);

        $this->assertSame(4, $absolute->attempts, 'Setting a quantity again is harmless.');
        $this->assertSame(1, $creating->attempts, 'A create that may have been accepted is never blindly repeated.');
    }

    public function test_a_dropped_connection_after_sending_is_ambiguous_and_one_before_sending_is_not_sent(): void
    {
        Http::fake(['channel.example/a' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds'),
            'channel.example/b' => fn () => throw new ConnectionException('cURL error 7: Failed to connect to channel.example')]);
        $account = $this->account(['capability_approvals' => ['price_publishing' => ['by' => 1], 'inventory_publishing' => ['by' => 1], 'product_writes' => ['by' => 1]], 'capabilities' => ['connection_test', 'read_only_sync', 'product_sync']]);

        $ambiguous = $this->http->send($account, 'trendyol.products_create', 'POST', 'https://channel.example/a', json: []);
        $notSent = $this->http->send($account, 'trendyol.products_create', 'POST', 'https://channel.example/b', json: []);

        $this->assertSame(ChannelResponse::AMBIGUOUS, $ambiguous->kind);
        $this->assertFalse($ambiguous->certainlyNotProcessed());
        $this->assertSame(ChannelResponse::NOT_SENT, $notSent->kind);
        $this->assertTrue($notSent->certainlyNotProcessed());
    }

    public function test_the_log_row_never_holds_a_secret_or_a_personal_detail(): void
    {
        Http::fake(['channel.example/*' => Http::response(['content' => [['customerEmail' => 'buyer@example.test', 'shipmentAddress' => ['phone' => '0555555555', 'fullAddress' => 'Somewhere 1']]]])]);

        $this->http->send($this->account(), self::READ, 'GET', 'https://channel.example/orders?apiKey=topsecretvalue99', headers: ['Authorization' => 'Basic dG9wc2VjcmV0OnZhbHVl', 'User-Agent' => '1 - SelfIntegration'], query: ['page' => 0]);

        $log = SalesChannelApiLog::first();
        $stored = json_encode($log->toArray());
        $this->assertStringNotContainsString('dG9wc2VjcmV0OnZhbHVl', $stored);
        $this->assertStringNotContainsString('topsecretvalue99', $stored);
        $this->assertStringNotContainsString('buyer@example.test', $stored);
        $this->assertStringNotContainsString('0555555555', $stored);
        $this->assertStringNotContainsString('Somewhere 1', $stored);
        $this->assertTrue($log->success);
    }

    public function test_our_own_limiter_makes_a_short_wait_and_hands_back_a_long_one(): void
    {
        config(['sales_channels.rate_limits.trendyol.operations' => [self::READ => ['max' => 1, 'per_seconds' => 3600]], 'sales_channels.http.max_wait_seconds' => 3]);
        Http::fake(['channel.example/*' => Http::response(['content' => []])]);
        $account = $this->account();

        $this->callRead($account);

        $this->expectException(ChannelRateLimitedException::class);
        $this->callRead($account);
    }

    public function test_a_long_url_or_error_still_fits_its_log_columns_cut_marks_included(): void
    {
        Http::fake(['channel.example/*' => Http::response(str_repeat('x', 5000), 500)]);

        $this->http->send($this->account(), self::READ, 'GET', 'https://channel.example/orders/' . str_repeat('a', 900), query: ['page' => 0]);

        $log = SalesChannelApiLog::firstOrFail();
        $this->assertLessThanOrEqual(500, mb_strlen($log->url));
        $this->assertLessThanOrEqual(500, mb_strlen((string) $log->error_message));
    }

    public function test_a_request_that_is_not_a_get_is_a_gated_write_except_exactly_the_sign_in_and_the_request_for_a_report(): void
    {
        $exceptions = ['amazon.lwa_token', 'amazon.reports_create'];
        $notGatedAsWrites = [];
        foreach (ChannelOperations::registered() as $operation) {
            foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                if (ChannelOperations::methodAllowed($operation, $method) && ChannelOperations::kindOf($operation, $method) !== ChannelOperations::WRITE) {
                    $notGatedAsWrites[$operation] = true;
                }
            }
        }

        $this->assertSame($exceptions, array_keys($notGatedAsWrites), 'Sign-in only returns a token, and a report request asks Amazon to prepare data (nothing calls it); every other call that is not a GET goes through every write gate.');
    }

    public function test_every_registered_operation_is_classified_and_every_write_names_its_approval(): void
    {
        $this->assertNotEmpty(ChannelOperations::registered());
        foreach (ChannelOperations::operationsOfKind(ChannelOperations::WRITE) as $operation) {
            $this->assertNotEmpty(ChannelOperations::scopes($operation), "{$operation} is a write and must name the approval it needs.");
            $this->assertNotEmpty(ChannelOperations::capabilities($operation), "{$operation} must belong to a capability.");
        }
        foreach (ChannelOperations::operationsOfKind(ChannelOperations::READ) as $operation) {
            $this->assertSame([], ChannelOperations::scopes($operation), "{$operation} is a read and needs no approval.");
        }
        foreach (ChannelOperations::registered() as $operation) {
            foreach (IntegrationCapability::cases() as $capability) {
                $this->assertNotNull($capability->labelKey());
            }
            $this->assertContains(ChannelOperations::kindOf($operation, ChannelOperations::methodAllowed($operation, 'GET') ? 'GET' : 'POST'), [ChannelOperations::READ, ChannelOperations::WRITE, ChannelOperations::AUTH], $operation);
        }
    }
}
