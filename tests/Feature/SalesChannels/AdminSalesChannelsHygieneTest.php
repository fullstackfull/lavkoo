<?php

namespace Tests\Feature\SalesChannels;

use App\Http\Controllers\Admin\SalesChannels\ChannelLabels;
use App\Http\Controllers\Admin\SalesChannels\Support\AccountForm;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use FilesystemIterator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\Fixtures\TrendyolFixtures as Trendyol;

/** The rules that hold for every screen of the module: no secret anywhere, nothing printed as markup, nothing hard-coded, nothing unguarded. */
class AdminSalesChannelsHygieneTest extends AdminSalesChannelsTestCase
{
    private const WEBHOOK_KEY = 'webhook-key-secret-555';

    /** @return list<string> */
    private function viewFiles(): array
    {
        return $this->files(resource_path('views/admin-views/sales-channels'), '/\.blade\.php$/');
    }

    /** @return list<string> */
    private function controllerFiles(): array
    {
        return $this->files(app_path('Http/Controllers/Admin/SalesChannels'), '/\.php$/');
    }

    /** @return list<string> */
    private function files(string $directory, string $pattern): array
    {
        $found = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (preg_match($pattern, $file->getFilename()) === 1) {
                $found[] = $file->getPathname();
            }
        }
        sort($found);

        return $found;
    }

    // ---- secrets ------------------------------------------------------------------------------------------

    public function test_no_page_of_the_module_ever_shows_a_stored_secret(): void
    {
        $trendyol = $this->connected($this->trendyolAccount());
        $amazon = $this->connected($this->amazonAccount());
        app(ChannelCredentialStore::class)->save($trendyol, ['webhook_api_key' => self::WEBHOOK_KEY]);
        $product = $this->product();
        $listing = $this->listing($trendyol, ['status' => 'mapped', 'product_id' => $product]);
        $order = $this->routable($trendyol);
        $this->routingRule();
        $this->routingDecision($order);
        Http::fake([self::TY . '/*' => Http::response(Trendyol::stream([]))]);
        $this->post(route('admin.sales-channels.accounts.test', $trendyol));

        $pages = [
            route('admin.sales-channels.index'),
            route('admin.sales-channels.accounts.create', ['channel' => 'amazon']),
            route('admin.sales-channels.accounts.create', ['channel' => 'trendyol']),
            route('admin.sales-channels.accounts.show', $trendyol),
            route('admin.sales-channels.accounts.show', $amazon),
            route('admin.sales-channels.products.index'),
            route('admin.sales-channels.orders.index'),
            route('admin.sales-channels.orders.show', $order),
            route('admin.sales-channels.routing.index'),
            route('admin.sales-channels.logs.index'),
            route('admin.sales-channels.logs.index', ['tab' => 'events']),
        ];
        foreach ($pages as $url) {
            $content = $this->get($url)->assertOk()->getContent();
            $this->assertNoSecretIn($content, $url);
            $this->assertStringNotContainsString(self::WEBHOOK_KEY, $content, $url);
            $this->assertStringNotContainsString(base64_encode('key-abc-123:secret-xyz-789'), $content, $url);
        }
        $this->assertNotNull($listing->id);
    }

    public function test_no_secret_is_left_in_the_session_by_any_form_of_the_module(): void
    {
        $account = $this->trendyolAccount();
        $typed = ['api_key' => 'typed-api-key-1111', 'api_secret' => 'typed-api-secret-2222'];
        $form = [
            'name' => '', 'external_account_id' => '1234567', 'currency' => 'SAR',
            'settings' => ['storefront_code' => 'SA', 'integration_name' => 'PharmacyHub', 'accept_language' => 'ar', 'torod_reference_field' => 'order_number', 'carrier_codes' => ''],
            'secrets' => $typed,
        ];

        $this->from(route('admin.sales-channels.accounts.show', $account))->post(route('admin.sales-channels.accounts.update', $account), $form);
        $this->from(route('admin.sales-channels.accounts.create'))->post(route('admin.sales-channels.accounts.store'), ['channel' => 'trendyol', 'environment' => 'production'] + $form);
        $this->post(route('admin.sales-channels.accounts.update', $account), ['name' => 'Renamed'] + $form);

        $session = json_encode(session()->all());
        foreach ([...array_values($typed), ...self::SECRETS] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $session);
        }
    }

    public function test_the_secrets_the_form_accepts_are_exactly_the_ones_the_connectors_use(): void
    {
        foreach (AccountForm::SECRET_FIELDS as $channel => $fields) {
            $this->assertSame([], array_diff($fields, ChannelCredentialStore::SECRET_KEYS[$channel]), "{$channel}: the form offers a secret the store would refuse");
        }
    }

    // ---- what is printed ----------------------------------------------------------------------------------

    public function test_nothing_is_printed_unescaped_and_no_address_can_run_a_script(): void
    {
        foreach ($this->viewFiles() as $file) {
            $source = (string) file_get_contents($file);
            $name = str_replace(base_path() . '/', '', $file);

            $this->assertStringNotContainsString('{!!', $source, "{$name} prints something unescaped");
            $this->assertDoesNotMatchRegularExpression('/href\s*=\s*"\s*javascript:/i', $source, "{$name} links to a script");
            $this->assertDoesNotMatchRegularExpression('/\bonclick\s*=/i', $source, "{$name} has an inline click handler");
        }
    }

    public function test_the_views_use_logical_utilities_only_so_that_arabic_mirrors(): void
    {
        $physical = '/class\s*=\s*"[^"]*(?<![\w-])(ml-\d|mr-\d|pl-\d|pr-\d|text-left|text-right|float-left|float-right|border-left|border-right)(?![\w-])[^"]*"/';

        foreach ($this->viewFiles() as $file) {
            $this->assertDoesNotMatchRegularExpression($physical, (string) file_get_contents($file), str_replace(base_path() . '/', '', $file) . ' uses a left/right utility');
        }
    }

    public function test_every_table_sits_in_a_scrolling_wrapper_and_no_view_names_a_class_by_its_full_path(): void
    {
        foreach ($this->viewFiles() as $file) {
            $source = (string) file_get_contents($file);
            $name = str_replace(base_path() . '/', '', $file);

            $this->assertSame(substr_count($source, '<table'), substr_count($source, 'k-table-wrap'), "{$name}: a table without .k-table-wrap");
            $this->assertDoesNotMatchRegularExpression('/(?<![\\\\\w])\\\\(App|Illuminate)\\\\/', $source, "{$name} writes a class name in full instead of importing it");
        }
    }

    public function test_the_ui_audit_finds_nothing_to_report_in_the_module(): void
    {
        $exitCode = Artisan::call('audit:ui', ['--path' => 'admin-views/sales-channels']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('No UI issues found.', $output, $output);
    }

    public function test_no_text_of_the_views_is_hard_coded_outside_a_translation(): void
    {
        $tag = '/<(button|h[1-6]|th|label|summary|legend|option|a|span|p|div|li|dt|dd|td)\b[^>]*>\s*([A-Za-z][A-Za-z ,.\']{2,})\s*<\/\1>/';

        foreach ($this->viewFiles() as $file) {
            foreach (file($file) as $number => $line) {
                if (str_contains($line, 'translate(') || str_contains($line, '{{--')) {
                    continue;
                }
                $visible = preg_replace(['/\{\{.*?\}\}/s', '/\{!!.*?!!\}/s'], "\x01", $line);
                if (preg_match($tag, (string) $visible, $match) === 1) {
                    $this->fail(str_replace(base_path() . '/', '', $file) . ':' . ($number + 1) . ' prints "' . trim($match[2]) . '" without translate()');
                }
            }
        }
        $this->addToAssertionCount(1);
    }

    // ---- toasts -------------------------------------------------------------------------------------------

    /**
     * Every toast either shows one of our own translations or goes through SafeToast. A raw toast that carries a variable is how text from a channel, a service or a person
     * reaches the browser unescaped: the library prints its message as HTML.
     */
    public function test_no_toast_of_the_module_carries_unescaped_dynamic_text(): void
    {
        $ours = '/^ToastMagic::(success|info|warning|error)\(translate\((\$[\w>-]+ \? )?\'[A-Za-z_&]+\'( : \'[A-Za-z_&]+\')?\)\);$/';
        $offenders = [];

        foreach ($this->controllerFiles() as $file) {
            foreach (file($file) as $number => $line) {
                if (str_contains($line, 'ToastMagic::') && !str_contains($line, 'use ') && preg_match($ours, trim($line)) !== 1 && !str_contains(trim($line), '//')) {
                    $offenders[] = basename($file) . ':' . ($number + 1) . ' ' . trim($line);
                }
            }
        }

        $this->assertSame([], $offenders, 'These toasts print dynamic text without escaping it; use SafeToast.');
    }

    // ---- guards -------------------------------------------------------------------------------------------

    public function test_every_route_of_the_module_is_guarded_and_every_state_change_is_a_post(): void
    {
        $routes = array_filter(Route::getRoutes()->getRoutes(), static fn ($route): bool => str_starts_with((string) $route->getName(), 'admin.sales-channels.'));

        $this->assertGreaterThan(30, count($routes));
        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            foreach (['admin', 'actch:admin_panel', 'module:sales_channels'] as $required) {
                $this->assertContains($required, $middleware, $route->getName());
            }
            $this->assertTrue(in_array('GET', $route->methods(), true) xor in_array('POST', $route->methods(), true), $route->getName() . ' must be a read or a write, not both');
            $this->assertNotContains('PUT', $route->methods());
        }
    }

    public function test_every_id_in_a_path_is_a_number(): void
    {
        $routes = array_filter(Route::getRoutes()->getRoutes(), static fn ($route): bool => str_starts_with((string) $route->getName(), 'admin.sales-channels.'));

        foreach ($routes as $route) {
            foreach (['account', 'mapping', 'rule'] as $parameter) {
                if (in_array($parameter, $route->parameterNames(), true)) {
                    $this->assertSame('[0-9]+', $route->wheres[$parameter] ?? null, $route->getName() . ' accepts a non-numeric {' . $parameter . '}');
                }
            }
        }
    }

    // ---- words --------------------------------------------------------------------------------------------

    public function test_the_label_families_and_the_list_of_their_keys_say_the_same_thing(): void
    {
        $expanded = [];
        foreach (ChannelLabels::FAMILIES as $prefix => $codes) {
            foreach ($codes as $code) {
                $expanded[] = $prefix . $code;
            }
        }

        $this->assertEqualsCanonicalizing($expanded, ChannelLabels::TRANSLATION_KEYS, 'a family and TRANSLATION_KEYS have drifted apart');
        $this->assertSame(count(ChannelLabels::TRANSLATION_KEYS), count(array_unique(ChannelLabels::TRANSLATION_KEYS)), 'a key is listed twice');
    }

    public function test_a_code_this_version_does_not_know_is_shown_as_it_came_and_never_becomes_a_translation_key(): void
    {
        $labels = app(ChannelLabels::class);

        $this->assertSame('a_reason_added_next_year', $labels->orderReason('a_reason_added_next_year'));
        $this->assertSame('exotic', $labels->listingStatus('exotic'));
        $this->assertSame('a_skip_reason_added_next_year', $labels->publishSkip('a_skip_reason_added_next_year'));
        $this->assertSame(ChannelLabels::NONE, $labels->orderReason(null));
        $this->assertSame(ChannelLabels::NONE, $labels->matchMethod(''));
        $this->assertSame('weird_action', $labels->routingAction('weird_action'));
        $this->assertSame(ChannelLabels::NONE, $labels->executionPolicy(null));
    }

    public function test_a_publish_skip_reason_that_carries_the_stock_quality_is_spelled_out(): void
    {
        $labels = app(ChannelLabels::class);

        $this->assertSame(translate('publish_skip_stock_not_confirmed') . ' (' . translate('stock_quality_stale') . ')', $labels->publishSkip('stock_not_confirmed:stale'));
        $this->assertSame(translate('publish_skip_unchanged'), $labels->publishSkip('unchanged'));
    }

    public function test_a_count_of_zero_says_nothing_and_a_run_with_nothing_to_report_says_so(): void
    {
        $labels = app(ChannelLabels::class);

        $this->assertSame(translate('run_stat_created') . ': 2 · ' . translate('run_stat_skipped') . ': ' . translate('publish_skip_unchanged') . ' ×3', $labels->statsLine(['pages' => 0, 'created' => 2, 'updated' => 0, 'skipped' => ['unchanged' => 3], 'dry_run' => true]));
        $this->assertSame(translate('run_stats_none'), $labels->statsLine(['pages' => 0, 'created' => 0, 'dry_run' => false]));
    }

    private function routable(SalesChannelAccount $account): ExternalOrderMapping
    {
        return $this->channelOrder($account, [], [['product_id' => $this->product()]]);
    }
}
