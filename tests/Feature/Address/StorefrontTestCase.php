<?php

namespace Tests\Feature\Address;

use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsStorefrontAddressSchema;

/**
 * Base for the website's address tests: the address book with its real migrations, the catalog of seedAddressCatalog(), the few
 * store tables the checkout and the address book read, a stand-in storefront shell (the real one needs the installation database)
 * and no way out to a real provider.
 *
 * translate() appends a key it does not know to the tracked language files, as a placeholder (the key spelled out). After every
 * test those placeholders are taken out again and nothing else is touched: the working tree is shared with other processes that
 * edit the same files, so a snapshot is never written back over them.
 */
abstract class StorefrontTestCase extends AddressTestCase
{
    use BuildsStorefrontAddressSchema;

    /** @var array<string, int> */
    protected array $ids;

    /** @var array<string, list<int|string>> language file => the keys it held before the test */
    private array $languageKeys = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['en', 'sa', 'ae'] as $locale) {
            $path = base_path("resources/lang/{$locale}/new-messages.php");
            $messages = $this->messagesIn(path: $path);
            if ($messages !== null) {
                $this->languageKeys[$path] = array_keys($messages);
            }
        }

        if (!defined('VIEW_FILE_NAMES')) {
            define('VIEW_FILE_NAMES', include resource_path('themes/default/file_names.php'));
        }

        Http::preventStrayRequests();
        View::getFinder()->prependLocation(base_path('tests/Support/views/storefront'));
        View::addLocation(resource_path('themes/default'));
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);

        $this->createStorefrontStoreTables();
        $this->ids = $this->seedAddressCatalog();
    }

    protected function tearDown(): void
    {
        foreach ($this->languageKeys as $path => $keysBefore) {
            $messages = $this->messagesIn(path: $path);
            $placeholders = array_filter(
                array: array_diff_key($messages ?? [], array_flip($keysBefore)),
                callback: fn (mixed $text, int|string $key): bool => $text === ucfirst(str_replace('_', ' ', removeSpecialCharacters((string) $key))),
                mode: ARRAY_FILTER_USE_BOTH,
            );

            if ($placeholders !== []) {
                $this->writeLanguageFile(path: $path, messages: array_diff_key($messages, $placeholders));
            }
        }

        parent::tearDown();
    }

    /** The messages of a language file, or null when it cannot be read: another process rewrites these files without locking them. */
    private function messagesIn(string $path): ?array
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $messages = include $path;
            } catch (\Throwable) {
                $messages = null;
            }

            if (is_array($messages)) {
                return $messages;
            }

            usleep(microseconds: 100_000);
        }

        return null;
    }

    /** Written the way translate() writes it, and moved into place in one step so that nobody reads half a file. */
    private function writeLanguageFile(string $path, array $messages): void
    {
        $contents = "<?php\n\nreturn [\n";
        foreach ($messages as $key => $text) {
            $contents .= "\t" . var_export((string) $key, true) . ' => ' . var_export((string) $text, true) . ",\n";
        }

        $staging = $path . '.' . getmypid() . '.tmp';
        file_put_contents($staging, $contents . "];\n");
        chmod($staging, fileperms($path) & 0777);
        rename($staging, $path);
    }

    protected function signIn(?User $customer = null): User
    {
        $customer ??= $this->makeCustomer();
        $this->actingAs($customer, 'customer');

        return $customer;
    }
}
