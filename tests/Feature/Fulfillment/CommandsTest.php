<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderApiLog;
use App\Models\ProviderCatalogItem;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\Support\RemoteImageFetcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CommandsTest extends M5aznTestCase
{
    public function test_every_command_is_a_no_op_while_integrations_are_off(): void
    {
        config(['fulfillment.enabled' => false]);

        foreach (['fulfillment:recover', 'fulfillment:poll', 'fulfillment:sync'] as $command) {
            $this->artisan($command)->expectsOutputToContain('disabled')->assertSuccessful();
        }
        Http::assertNothingSent();
    }

    public function test_the_recovery_sweep_routes_and_dispatches_an_eligible_order_without_a_queue_worker(): void
    {
        // On the sync driver there is no worker: the sweep itself does the dispatching, outside any request.
        config(['queue.default' => 'sync', 'fulfillment.providers.torod_point.adapter' => \Tests\Support\FakeTorodAdapter::class]);
        $this->useFakeAdapters();
        $this->enableProvider(ProviderCode::TorodPoint);
        $this->makeProduct(1, ProviderCode::TorodPoint);
        $this->makeOrder(100);
        $this->addLine(100, 1, 1, 50, ProviderCode::TorodPoint);
        $this->torod->submitQueue = [SubmissionResult::accepted('O-1')];

        $this->artisan('fulfillment:recover')->assertSuccessful();

        $fulfillment = $this->fulfillmentFor(100, ProviderCode::TorodPoint);
        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->status);
        $this->assertSame('O-1', $fulfillment->provider_reference);
        $this->assertCount(1, $this->torod->calls);
    }

    public function test_running_the_sweep_again_changes_nothing(): void
    {
        config(['queue.default' => 'sync']);
        $this->useFakeAdapters();
        $this->enableProvider(ProviderCode::TorodPoint);
        $this->makeProduct(1, ProviderCode::TorodPoint);
        $this->makeOrder(100);
        $this->addLine(100, 1, 1, 50, ProviderCode::TorodPoint);

        $this->artisan('fulfillment:recover')->assertSuccessful();
        $this->artisan('fulfillment:recover')->assertSuccessful();
        $this->artisan('fulfillment:recover')->assertSuccessful();

        $this->assertCount(1, $this->torod->calls);
        $this->assertSame(1, \App\Models\ProviderFulfillment::count());
    }

    public function test_the_sweep_finds_an_order_confirmed_by_a_path_that_never_fired_a_hook(): void
    {
        config(['queue.default' => 'sync']);
        $this->useFakeAdapters();
        $this->enableProvider(ProviderCode::TorodPoint);
        $this->makeProduct(1, ProviderCode::TorodPoint);
        $this->makeOrder(100, ['order_status' => 'pending', 'payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid']);
        $this->addLine(100, 1, 1, 50, ProviderCode::TorodPoint);

        $this->artisan('fulfillment:recover')->assertSuccessful();
        $this->assertSame([], $this->torod->calls, 'not confirmed yet');

        DB::table('orders')->where('id', 100)->update(['order_status' => 'confirmed']);   // a raw write: no hook, no event
        $this->artisan('fulfillment:recover')->assertSuccessful();

        $this->assertCount(1, $this->torod->calls);
    }

    public function test_sync_pulls_m5azn_products_and_imports_new_ones_when_import_is_verified_and_enabled(): void
    {
        $this->app->instance(RemoteImageFetcher::class, new class extends RemoteImageFetcher {
            public function fetch(string $url): ?\Illuminate\Http\UploadedFile
            {
                return null;
            }
        });
        $this->verifyMap('catalog', $this->catalogPaths(), $this->catalogFixture(), itemsPath: 'data');
        Http::fake([
            self::BASE . '/api/v1/auth/login' => $this->loginResponse(),
            self::BASE . '/api/v1/seller/myproductlist*' => Http::response(['data' => $this->catalogFixture()]),
        ]);

        $this->artisan('fulfillment:sync m5azn --only=products')->assertSuccessful();

        $this->assertSame(2, ProviderCatalogItem::where('status', ProviderCatalogItem::STATUS_IMPORTED)->count());
        $this->assertSame(2, DB::table('products')->where('fulfillment_provider', 'm5azn')->count());
        $this->assertSame(0, DB::table('products')->where('status', 1)->count(), 'imported products start unpublished');

        $this->artisan('fulfillment:sync m5azn --only=products')->assertSuccessful();
        $this->assertSame(2, DB::table('products')->count(), 'a second sync never creates duplicates');
    }

    public function test_a_provider_that_has_the_switch_off_is_skipped_not_failed(): void
    {
        $this->enableProvider(ProviderCode::M5azn, ['product_sync_enabled' => false, 'dry_run' => false]);
        Http::fake();

        $this->artisan('fulfillment:sync m5azn --only=products')->expectsOutputToContain('switched_off')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_prune_removes_only_old_api_logs(): void
    {
        ProviderApiLog::create(['provider' => 'm5azn', 'operation' => 'x', 'correlation_id' => 'a', 'method' => 'GET', 'url' => '/', 'success' => true, 'created_at' => now()->subDays(90)]);
        ProviderApiLog::create(['provider' => 'm5azn', 'operation' => 'y', 'correlation_id' => 'b', 'method' => 'GET', 'url' => '/', 'success' => true, 'created_at' => now()->subDays(5)]);

        $this->artisan('fulfillment:prune')->assertSuccessful();

        $this->assertSame(['y'], ProviderApiLog::pluck('operation')->all());
    }

    public function test_the_schedule_contains_the_recovery_poll_sync_and_prune_tasks(): void
    {
        $schedule = new \Illuminate\Console\Scheduling\Schedule();
        \App\Console\ScheduleDefinition::define($schedule);
        $commands = collect($schedule->events())->map(fn ($event): string => (string) $event->command)->implode("\n");

        foreach (['fulfillment:recover', 'fulfillment:poll', 'fulfillment:sync --only=products,inventory', 'fulfillment:sync --only=cities', 'fulfillment:prune'] as $expected) {
            $this->assertStringContainsString($expected, $commands);
        }
    }
}
