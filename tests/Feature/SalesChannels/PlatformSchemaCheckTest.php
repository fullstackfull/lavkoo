<?php

namespace Tests\Feature\SalesChannels;

use App\Services\SalesChannels\Support\PlatformSchemaCheck;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The check the owner runs on a copy of the real database before anything is switched on. */
class PlatformSchemaCheckTest extends DispatchTestCase
{
    public function test_the_tables_the_module_was_built_against_pass(): void
    {
        $report = app(PlatformSchemaCheck::class)->run();

        $this->assertTrue(PlatformSchemaCheck::passes($report), json_encode($report));
        $this->artisan('channels:check-platform-schema')->expectsOutputToContain('orders: ok')->assertSuccessful();
    }

    public function test_every_column_the_dispatcher_writes_is_declared(): void
    {
        $this->dispatcher()->dispatch($this->placed());

        foreach (['orders', 'order_details'] as $table) {
            $row = (array) DB::table($table)->first();
            $written = array_keys(array_filter($row, static fn (mixed $value): bool => $value !== null));
            $declared = [...PlatformSchemaCheck::TABLES[$table]['columns'], ...PlatformSchemaCheck::TABLES[$table]['optional']];
            $this->assertSame([], array_values(array_diff(array_intersect($declared, array_keys($row)), $written)), "{$table}: a declared column was not written");
        }
    }

    public function test_a_missing_table_or_column_and_a_column_the_table_insists_on_are_reported_and_fail_the_command(): void
    {
        Schema::drop('provider_sync_states');
        Schema::table('products', static fn (Blueprint $table) => $table->dropColumn('purchase_price'));

        $report = app(PlatformSchemaCheck::class)->run();

        $this->assertFalse($report['provider_sync_states']['exists']);
        $this->assertSame(['purchase_price'], $report['products']['missing']);
        $this->assertFalse(PlatformSchemaCheck::passes($report));
        $this->artisan('channels:check-platform-schema')->expectsOutputToContain('products: columns missing: purchase_price')->assertFailed();
    }
}
