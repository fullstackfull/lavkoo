<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tables a decision about a storefront is recorded in (its tax approval, the legal review of its texts): the business settings that hold it and the audit log that traces
 * it, built when the world has none, and the settings it wrote taken away when the test ends, because a real server keeps the tables and the rows of one test class for the next.
 */
trait BuildsStorefrontDecisionTables
{
    use BuildsAuditLogTable;

    /** @var array<string, true> */
    private array $cleanedSettingPrefixes = [];

    private function buildTheTablesOfAStorefrontDecision(string $settingPrefix): void
    {
        if (!Schema::hasTable('business_settings')) {
            Schema::create('business_settings', static function (Blueprint $table): void {
                $table->id();
                $table->string('type')->nullable();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
            $this->beforeApplicationDestroyed(static fn () => Schema::dropIfExists('business_settings'));
        }

        if (!Schema::hasTable('audit_logs')) {
            $this->buildAuditLogTable();
            $this->beforeApplicationDestroyed(static fn () => Schema::dropIfExists('audit_logs'));
        }

        if (!isset($this->cleanedSettingPrefixes[$settingPrefix])) {
            $this->cleanedSettingPrefixes[$settingPrefix] = true;
            $this->beforeApplicationDestroyed(static function () use ($settingPrefix): void {
                if (Schema::hasTable('business_settings')) {
                    DB::table('business_settings')->where('type', 'like', $settingPrefix . '%')->delete();
                }
            });
        }
    }
}
