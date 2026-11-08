<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\ConnectionStatus;
use App\Enums\SalesChannels\ExecutionPolicy;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Enums\SalesChannels\IntegrationCapability;
use App\Enums\SalesChannels\RoutingAction;
use App\Enums\SalesChannels\SalesChannel;
use App\Http\Controllers\Admin\SalesChannels\ChannelLabels;
use App\Http\Controllers\Admin\SalesChannels\Support\ListingLabels;
use App\Http\Controllers\Admin\SalesChannels\Support\WriteState;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every phrase the sales-channel screens show is translated, in English and in the two Arabic locales.
 *
 * The platform's translate() helper silently WRITES a humanised English placeholder into resources/lang/{locale}/new-messages.php the first time it meets an unknown key,
 * so a missing translation never fails a page: it shows English in an Arabic store and dirties the repository. This test turns that fallback into a failure, and lists
 * every key that is missing. It reads the language files and the sources only.
 */
class AdminSalesChannelsTranslationsTest extends TestCase
{
    private const LOCALES = ['en', 'sa', 'ae'];

    /** Where the module's user-visible text is written — including the card it adds to the platform's own product form. */
    private const SOURCES = [
        'resources/views/admin-views/sales-channels',
        'app/Http/Controllers/Admin/SalesChannels',
        'resources/views/admin-views/partials/_sales-channels-product.blade.php',
    ];

    /** @var array<string, array<string, string>> */
    private array $catalogue = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::LOCALES as $locale) {
            $this->catalogue[$locale] = array_merge(
                include $this->path("resources/lang/{$locale}/messages.php"),
                include $this->path("resources/lang/{$locale}/new-messages.php"),
            );
        }
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }

    /** @return list<string> every literal key written inside a translate() call of the module */
    private function literalKeys(): array
    {
        $keys = [];
        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);
            $offset = 0;
            while (($position = strpos($source, 'translate(', $offset)) !== false) {
                $start = $position + strlen('translate');
                $offset = $start + 1;
                if (preg_match_all('/(?<![\[\w])([\'"])([A-Za-z][A-Za-z0-9_&]*)\1(?!\s*\])/', $this->balancedArguments($source, $start), $matches) > 0) {
                    foreach ($matches[2] as $key) {
                        if (!str_ends_with($key, '_')) {
                            $keys[$key] = true;
                        }
                    }
                }
            }
        }

        return array_keys($keys);
    }

    private function balancedArguments(string $source, int $openParen): string
    {
        $depth = 0;
        for ($index = $openParen, $length = strlen($source); $index < $length; $index++) {
            if ($source[$index] === '(') {
                $depth++;
            } elseif ($source[$index] === ')' && --$depth === 0) {
                return substr($source, $openParen + 1, $index - $openParen - 1);
            }
        }

        return '';
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = [];
        foreach (self::SOURCES as $relative) {
            $path = $this->path($relative);
            if (is_file($path)) {
                $files[] = $path;
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (preg_match('/\.php$/', $file->getFilename()) === 1) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /** @param list<string> $keys */
    private function assertTranslated(array $keys, string $what): void
    {
        $this->assertNotEmpty($keys, "no keys found for {$what}");

        foreach (self::LOCALES as $locale) {
            $missing = array_values(array_filter(array_unique($keys), fn (string $key): bool => !array_key_exists($key, $this->catalogue[$locale])));
            $this->assertSame([], $missing, "{$what}: keys missing from resources/lang/{$locale}: " . implode(', ', $missing));
        }
    }

    public function test_every_literal_key_of_the_module_exists_in_every_locale(): void
    {
        $this->assertTranslated($this->literalKeys(), 'literal translate() keys');
    }

    public function test_every_key_built_from_a_code_exists_in_every_locale(): void
    {
        $this->assertTranslated(ChannelLabels::TRANSLATION_KEYS, 'label families');
    }

    public function test_every_listing_code_the_screens_put_words_to_exists_in_every_locale(): void
    {
        $this->assertTranslated(ListingLabels::translationKeys(), 'listing labels');
    }

    public function test_every_label_of_an_enum_the_screens_show_exists_in_every_locale(): void
    {
        $keys = [];
        foreach ([IntegrationCapability::cases(), ExecutionPolicy::cases(), FulfillmentMode::cases(), RoutingAction::cases(), ConnectionStatus::cases(), ChannelOrderStatus::cases(), SalesChannel::connectable()] as $cases) {
            foreach ($cases as $case) {
                $keys[] = $case->labelKey();
            }
        }
        foreach (WriteState::cases() as $state) {
            $keys[] = $state->labelKey();
            $keys[] = $state->helpKey();
        }

        $this->assertTranslated($keys, 'enum labels');
    }

    public function test_what_an_administrator_reads_in_the_arabic_locales_is_arabic(): void
    {
        foreach (['sa', 'ae'] as $locale) {
            foreach (['Channels_Hub', 'activation_ladder', 'rehearsal_only_nothing_is_sent', 'write_state_rehearsal', 'secret_is_set', 'managed_dispatch_not_available_explained', 'with_no_rule_the_answer_is_manual_review'] as $key) {
                $this->assertMatchesRegularExpression('/\p{Arabic}/u', $this->catalogue[$locale][$key] ?? '', "{$locale}: {$key} must be Arabic");
            }
        }
    }
}
