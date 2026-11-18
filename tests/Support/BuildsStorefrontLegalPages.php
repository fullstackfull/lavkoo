<?php

namespace Tests\Support;

use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\LogUserBrowsingNavigationMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

/**
 * The business pages a storefront serves, as the platform installs them, and what reading them needs: the page and its banner
 * attachment, the robots rows its lookup reads, and the settings the middleware of the page route read. The page renders in the
 * storefront's stand-in shell, so the real page template is what a test checks. The schema is inferred from the model and the
 * controllers that read it, and rebuilt for every test because MySQL keeps tables between test classes.
 */
trait BuildsStorefrontLegalPages
{
    use BuildsStorefrontLegalDocuments;

    /** The slugs of the seven pages the installation creates, with the title the installer gives each one. */
    private const INSTALLED_PAGE_SLUGS = [
        'about-us', 'terms-and-conditions', 'privacy-policy', 'refund-policy', 'return-policy', 'cancellation-policy', 'shipping-policy',
    ];

    protected function buildBusinessPageTables(): void
    {
        foreach (['business_settings', 'guest_users', 'business_pages', 'attachments', 'robots_meta_contents'] as $table) {
            Schema::dropIfExists($table);
        }

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
            $table->string('slug');
            $table->longText('description')->nullable();
            $table->boolean('status')->default(false);
            $table->boolean('default_status')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
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

    /** The page the installation creates for each slug: a default page (default_status 1), published, with the Saudi text it has always had. */
    protected function insertInstalledBusinessPages(): void
    {
        foreach (self::INSTALLED_PAGE_SLUGS as $slug) {
            $this->insertBusinessPage(slug: $slug, title: ucwords(str_replace('-', ' ', $slug)), description: "<p>Saudi {$slug} text</p>");
        }
    }

    protected function insertBusinessPage(string $slug, string $title, string $description, int $status = 1, int $defaultStatus = 1): void
    {
        DB::table('business_pages')->insert([
            'title' => $title,
            'slug' => $slug,
            'description' => $description,
            'status' => $status,
            'default_status' => $defaultStatus,
            'created_at' => '2026-11-01 10:00:00',
            'updated_at' => '2026-11-01 10:00:00',
        ]);
    }
}
