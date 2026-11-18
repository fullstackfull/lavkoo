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
use App\Models\StorefrontLegalDocument;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;

/**
 * The business pages a storefront serves, as the platform installs them, and what reading them needs: the page and its banner
 * attachment, the robots rows its lookup reads, and the settings the middleware of the page route read. The page renders in the
 * storefront's stand-in shell, so the real page template is what a test checks. The schema is inferred from the model and the
 * controllers that read it, and rebuilt for every test because MySQL keeps tables between test classes.
 */
trait BuildsStorefrontLegalPages
{
    use BuildsStorefrontCatalog;
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

    /** Storefronts on, with these countries active: a country that is configured but not active is never chosen. */
    protected function activateStorefronts(string ...$countries): void
    {
        config(['gcc.storefronts.enabled' => true]);
        foreach ($countries as $country) {
            config(["gcc.storefronts.countries.{$country}.active" => true]);
        }
        Cache::flush();
    }

    /** What a country's text says, so a test can tell whose text and which language an answer carries. */
    protected function storefrontTextBody(string $country, string $type, string $locale): string
    {
        return "<p>{$country} {$type} text in {$locale}</p>";
    }

    protected function publishStorefrontText(string $country, string $type, string $locale, array $overrides = []): void
    {
        $body = $this->storefrontTextBody(country: $country, type: $type, locale: $locale);

        $this->publishLegalDocument(country: $country, type: $type, locale: $locale, overrides: $overrides + ['body' => $body, 'body_hash' => hash('sha256', $body)]);
    }

    /**
     * Every document type the storefront must have, in the given locales.
     *
     * @param list<string> $locales
     * @param list<string> $withheldTypes document types left out, for a test that publishes its own text of that type
     */
    protected function publishStorefrontTexts(string $country, array $locales = ['en', 'ar'], array $withheldTypes = []): void
    {
        foreach (array_diff(config('gcc.storefronts.legal.required_documents'), $withheldTypes) as $type) {
            foreach ($locales as $locale) {
                $this->publishStorefrontText(country: $country, type: $type, locale: $locale);
            }
        }
    }

    /** @return array<string, array{0: bool, 1: string, 2: array<string, string>}> storefronts on or off, the query string, the headers */
    public static function requestsOfTheDefaultStorefront(): array
    {
        return [
            'storefronts off' => [false, '', []],
            'storefronts off, another storefront asked for' => [false, 'storefront=KW', ['X-Storefront' => 'KW']],
            'storefronts on, no choice made' => [true, '', []],
            'storefronts on, Saudi chosen in the address' => [true, 'storefront=SA', []],
            'storefronts on, Saudi chosen in the header' => [true, '', ['X-Storefront' => 'SA']],
            'storefronts on, Kuwait asked for while it is not active' => [true, 'storefront=KW', ['X-Storefront' => 'KW']],
        ];
    }

    /** @return array<string, array{0: string}> the ways the text a storefront currently has can be unfit to show: it then shows nothing, and no older text takes its place */
    public static function unfitCurrentTexts(): array
    {
        return self::namedCases('revoked', 'not approved', 'approved by its own author', 'approver unknown', 'blank', 'unedited template');
    }

    /** @return array<string, array{0: string}> rows that are never the current text: they change nothing for the text that is current */
    public static function textsThatAreNotCurrent(): array
    {
        return self::namedCases('draft', 'archived', 'scheduled', 'no effective date');
    }

    /** @return array<string, array{0: string}> */
    private static function namedCases(string ...$names): array
    {
        return array_combine($names, array_map(fn (string $name) => [$name], $names));
    }

    /** @return array<string, mixed> the columns of a published text that make it unfit or not current, by the names above */
    protected function overridesOf(string $defect, string $body): array
    {
        return match ($defect) {
            'revoked' => ['revoked_at' => now()->subHour()],
            'not approved' => ['approved_at' => null, 'approved_by' => null],
            'approved by its own author' => ['created_by' => 1, 'approved_by' => 1],
            'approver unknown' => ['approved_by' => null],
            'blank' => ['body' => '   ', 'body_hash' => hash('sha256', '   ')],
            'unedited template' => ['template_hash' => hash('sha256', $body)],
            'draft' => ['status' => StorefrontLegalDocument::STATUS_DRAFT],
            'archived' => ['status' => StorefrontLegalDocument::STATUS_ARCHIVED],
            'scheduled' => ['effective_from' => now()->addDay()],
            'no effective date' => ['effective_from' => null],
        };
    }

    /** What an app of the storefront receives from GET /api/v1/business-pages, in the language it asks for ('sa' and 'us' are the country codes older apps send). */
    protected function listBusinessPagesAs(string $storefront, string $language = 'en', string $query = ''): TestResponse
    {
        $uri = '/api/v1/business-pages' . ($query === '' ? '' : "?{$query}");
        $headers = ['X-Storefront' => $storefront, 'lang' => $language];

        $this->serveAsHttpRequest(uri: $uri, headers: $headers);

        return $this->getJson($uri, $headers)->assertOk();
    }

    /** The body a list of business pages carries for a page, or null when the list has no such page. */
    protected function descriptionOf(TestResponse $response, string $slug): ?string
    {
        return collect($response->json())->firstWhere('slug', $slug)['description'] ?? null;
    }

    /** The languages the store offers, filed under the codes of a country's flag as the admin's language screen does: Arabic is 'sa'. */
    protected function storeOffersEnglishAndArabic(): void
    {
        DB::table('business_settings')->insert([
            'type' => 'language',
            'value' => json_encode([
                ['id' => 1, 'name' => 'english', 'direction' => 'ltr', 'code' => 'en', 'status' => 1, 'default' => true],
                ['id' => 2, 'name' => 'Arabic', 'direction' => 'rtl', 'code' => 'sa', 'status' => 1, 'default' => false],
            ]),
        ]);
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
