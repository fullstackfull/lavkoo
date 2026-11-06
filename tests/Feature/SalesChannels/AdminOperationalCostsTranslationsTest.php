<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\OperationalCosts\CalcMethod;
use App\Enums\OperationalCosts\ContractStatus;
use App\Enums\OperationalCosts\CostClass;
use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Enums\SalesChannels\SalesChannel;
use App\Http\Controllers\Admin\OperationalCosts\CostLabels;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every phrase the operational-cost screens show is translated, in English and in the two Arabic locales.
 *
 * The platform's translate() helper silently WRITES a humanised English placeholder into resources/lang/{locale}/new-messages.php the first time it meets an unknown key, so
 * a missing translation never fails a page: it shows English in an Arabic store and dirties the repository. This test turns that fallback into a failure and lists every key
 * that is missing. It reads the language files and the sources only, and it fails until the translations are written.
 */
class AdminOperationalCostsTranslationsTest extends TestCase
{
    private const LOCALES = ['en', 'sa', 'ae'];

    /** Where the module's user-visible text is written. */
    private const SOURCES = [
        'resources/views/admin-views/operational-costs', 'app/Http/Controllers/Admin/OperationalCosts', 'app/Http/Requests/Admin/OperationalCosts', 'app/Services/OperationalCosts/Reports',
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
            preg_match_all("/(?<![\\w>:$])translate\\(\\s*'([a-z][a-z0-9_]*)'\\s*\\)/", (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = [];
        foreach (self::SOURCES as $relative) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->path($relative), FilesystemIterator::SKIP_DOTS)) as $file) {
                if (preg_match('/\.php$/', $file->getFilename()) === 1) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /** @return array<string, list<string>> each sentence CostLabels::filled() puts numbers into, and the placeholders it fills */
    private function placeholderSentences(): array
    {
        $sentences = [];
        foreach ($this->sourceFiles() as $file) {
            foreach (file($file) as $line) {
                if (preg_match_all("/CostLabels::filled\\(translate\\('([a-z][a-z0-9_]*)'\\),\\s*\\[(.*)\\]\\)/", $line, $calls, PREG_SET_ORDER) === 0) {
                    continue;
                }
                foreach ($calls as [, $key, $values]) {
                    preg_match_all("/'([a-z]+)'\\s*=>/", $values, $names);
                    $sentences[$key] = array_map(static fn (string $name): string => ':' . $name, $names[1]);
                }
            }
        }

        return $sentences;
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

    public function test_every_key_built_from_a_stored_value_exists_in_every_locale(): void
    {
        $this->assertTranslated(CostLabels::TRANSLATION_KEYS, 'label families');
    }

    public function test_every_label_of_an_enum_the_screens_show_exists_in_every_locale(): void
    {
        $keys = [];
        foreach ([CostState::cases(), CostComponent::cases(), CostClass::cases(), CalcMethod::cases(), ContractStatus::cases(), SalesChannel::cases()] as $cases) {
            foreach ($cases as $case) {
                $keys[] = $case->labelKey();
            }
        }

        $this->assertTranslated($keys, 'enum labels');
    }

    public function test_a_sentence_that_gets_numbers_put_into_it_keeps_every_placeholder_in_every_locale(): void
    {
        $sentences = $this->placeholderSentences();

        $this->assertGreaterThanOrEqual(6, count($sentences), 'The sentences with numbers in them were not found.');
        foreach (self::LOCALES as $locale) {
            foreach ($sentences as $key => $placeholders) {
                foreach ($placeholders as $placeholder) {
                    $this->assertStringContainsString($placeholder, $this->catalogue[$locale][$key] ?? '', "{$locale}: {$key} must keep {$placeholder}, or its number is lost.");
                }
            }
        }
    }

    public function test_what_an_administrator_reads_in_the_arabic_locales_is_arabic(): void
    {
        foreach (['sa', 'ae'] as $locale) {
            foreach ([
                'operational_costs_overview', 'cost_state_missing', 'cost_state_estimated', 'missing_reason_no_contract', 'estimated_and_actual_are_never_added_together_note',
                'a_purchase_is_stock_not_an_expense_help', 'calc_method_help_percent', 'cost_component_shipping', 'party_type_carrier', 'reconciliation_status_variance',
                'an_approved_entry_is_never_edited_note', 'a_marketplace_payout_is_not_revenue_note',
            ] as $key) {
                $this->assertMatchesRegularExpression('/\p{Arabic}/u', $this->catalogue[$locale][$key] ?? '', "{$locale}: {$key} must be Arabic");
            }
        }
    }
}
