<?php

namespace Tests\Support;

use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\LogUserBrowsingNavigationMiddleware;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

/**
 * What a business page reads when it is requested or listed: the page and its banner attachment, the robots rows its lookup
 * reads, and the settings the layout reads for the language. The page renders in the storefront's stand-in shell, so the real page
 * template is what a test checks. The schema is inferred from the model and the controllers that read it.
 */
trait BuildsBusinessPages
{
    protected function buildBusinessPageTables(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });
        Schema::create('guest_users', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });
        Schema::create('business_pages', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('description')->nullable();
            $table->unsignedTinyInteger('status')->default(0);
            $table->unsignedTinyInteger('default_status')->default(0);
            $table->timestamps();
        });
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');
            $table->string('file_type')->nullable();
            $table->string('file_name')->nullable();
            $table->string('storage_disk')->nullable();
            $table->timestamps();
        });
        Schema::create('robots_meta_contents', function (Blueprint $table) {
            $table->id();
            $table->string('page_name')->nullable();
            $table->timestamps();
        });
    }

    protected function prepareBusinessPageRendering(): void
    {
        if (!defined('VIEW_FILE_NAMES')) {
            define('VIEW_FILE_NAMES', include resource_path('themes/default/file_names.php'));
        }
        View::getFinder()->prependLocation(base_path('tests/Support/views/storefront'));
        View::addLocation(resource_path('themes/default'));
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, LogUserBrowsingNavigationMiddleware::class,
        ]);
    }

    protected function insertBusinessPage(string $slug, int $status, string $title): void
    {
        DB::table('business_pages')->insert([
            'title' => $title,
            'slug' => $slug,
            'description' => '<p>Body of ' . $title . '</p>',
            'status' => $status,
            'default_status' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
