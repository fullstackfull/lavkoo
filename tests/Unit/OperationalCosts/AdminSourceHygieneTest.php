<?php

namespace Tests\Unit\OperationalCosts;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The rules of the operational-cost admin screens that a person reviewing them would otherwise have to check by eye: every phrase goes through translate() with a
 * literal key (so the translations can be written from the source), nothing is laid out with a direction baked in (the pages are mirrored for Arabic), money never
 * passes through a float, and a toast never carries text that is not ours unescaped.
 */
class AdminSourceHygieneTest extends TestCase
{
    /** @return list<string> */
    private function files(string $directory, string $pattern): array
    {
        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (preg_match($pattern, $file->getPathname()) === 1) {
                $found[] = $file->getPathname();
            }
        }
        sort($found);

        return $found;
    }

    /** @return list<string> */
    private function views(): array
    {
        return $this->files(dirname(__DIR__, 3) . '/resources/views/admin-views/operational-costs', '/\.blade\.php$/');
    }

    /** @return list<string> */
    private function code(): array
    {
        $root = dirname(__DIR__, 3);

        return array_merge(
            $this->files($root . '/app/Http/Controllers/Admin/OperationalCosts', '/\.php$/'),
            $this->files($root . '/app/Http/Requests/Admin/OperationalCosts', '/\.php$/'),
            $this->files($root . '/app/Services/OperationalCosts/Reports', '/\.php$/'),
            [$root . '/app/Services/OperationalCosts/Invoices/InvoiceLineParser.php', $root . '/app/Services/OperationalCosts/Invoices/ParsedInvoiceLines.php'],
        );
    }

    /** @return list<string> the argument of every translate() call in a file */
    private function translateArguments(string $source): array
    {
        $arguments = [];
        $offset = 0;
        while (($at = strpos($source, 'translate(', $offset)) !== false) {
            $offset = $at + strlen('translate(');
            if ($at > 0 && preg_match('/[A-Za-z0-9_>:$]/', $source[$at - 1]) === 1) {
                continue;
            }
            $depth = 1;
            $cursor = $offset;
            while ($cursor < strlen($source) && $depth > 0) {
                $depth += match ($source[$cursor]) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };
                $cursor++;
            }
            $arguments[] = trim(substr($source, $offset, $cursor - $offset - 1));
        }

        return $arguments;
    }

    public function test_the_screens_were_found(): void
    {
        $this->assertGreaterThan(20, count($this->views()));
        $this->assertGreaterThan(30, count($this->code()));
    }

    public function test_every_phrase_goes_through_translate_with_a_literal_snake_case_key_or_an_enum_label(): void
    {
        $offenders = [];
        foreach (array_merge($this->views(), $this->code()) as $file) {
            $isLabels = basename($file) === 'CostLabels.php';
            foreach ($this->translateArguments((string) file_get_contents($file)) as $argument) {
                $literal = preg_match("/^'[a-z][a-z0-9]*(_[a-z0-9]+)*'$/", $argument) === 1;
                $enumLabel = preg_match('/->labelKey\(\)$/', $argument) === 1;
                $guardedFamilyKey = $isLabels && $argument === '$key';
                if (!$literal && !$enumLabel && !$guardedFamilyKey) {
                    $offenders[] = basename($file) . ': translate(' . $argument . ')';
                }
            }
        }

        $this->assertSame([], $offenders, 'These phrases would not be found by the translations: use translate(\'a_literal_key\') or an enum\'s labelKey().');
    }

    public function test_no_template_lays_anything_out_with_a_direction_baked_in(): void
    {
        $offenders = [];
        foreach ($this->views() as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('/class="([^"]*)"/', $source, $classes);
            foreach ($classes[1] as $classList) {
                if (preg_match('/(?<![\w-])(m[lr]|p[lr])-(\d|auto)|(?<![\w-])(text|float|border|rounded)-(left|right)(?![\w-])/', $classList) === 1) {
                    $offenders[] = basename($file) . ': ' . $classList;
                }
            }
            if (preg_match('/style="[^"]*(margin|padding|border)-(left|right)|style="[^"]*text-align:\s*(left|right)|style="[^"]*(?<![\w-])(left|right):/', $source) === 1) {
                $offenders[] = basename($file) . ': an inline style with a physical direction';
            }
        }

        $this->assertSame([], $offenders, 'Use the logical utilities (ms-, me-, ps-, pe-, text-start, text-end, float-start, float-end).');
    }

    public function test_no_template_prints_unescaped_html_or_names_a_class_inline(): void
    {
        $offenders = [];
        foreach ($this->views() as $file) {
            $source = (string) file_get_contents($file);
            if (str_contains($source, '{!!')) {
                $offenders[] = basename($file) . ': unescaped output';
            }
            if (preg_match('/(?<![\w\'"])\\\\App\\\\/', $source) === 1) {
                $offenders[] = basename($file) . ': an inline fully qualified class name (use @use)';
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_every_full_page_extends_the_admin_layout_and_no_partial_does(): void
    {
        foreach ($this->views() as $file) {
            $source = (string) file_get_contents($file);
            $isPartial = str_contains($file, '/partials/');

            $this->assertSame(!$isPartial, str_contains($source, "@extends('layouts.admin.app')"), basename($file));
        }
    }

    public function test_money_never_passes_through_a_float(): void
    {
        $offenders = [];
        foreach (array_merge($this->views(), $this->code()) as $file) {
            if (basename($file) === 'CsvExporter.php') {
                continue; // it names the float to refuse it
            }
            foreach (file($file) as $number => $line) {
                if (preg_match('/\(float\)|\(double\)|floatval\(|number_format\(|\bround\(|toFloat\(|\bfloor\(|\bceil\(/', $line) === 1) {
                    $offenders[] = basename($file) . ':' . ($number + 1) . ' ' . trim($line);
                }
            }
        }

        $this->assertSame([], $offenders, 'Use Money (exact decimals) and toDisplay().');
    }

    public function test_every_toast_shows_our_own_translation_or_goes_through_safe_toast(): void
    {
        $offenders = [];
        foreach ($this->code() as $file) {
            foreach (file($file) as $number => $line) {
                if (preg_match_all('/ToastMagic::\w+\(([^;]*)/', $line, $matches) === 0) {
                    continue;
                }
                foreach ($matches[1] as $argument) {
                    if (preg_match("/^translate\\('[a-z0-9_]+'\\)\\)?[,)]?\\s*$/", trim($argument)) !== 1) {
                        $offenders[] = basename($file) . ':' . ($number + 1) . ' ' . trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'These toasts print dynamic text without escaping it; use SafeToast.');
    }

    public function test_a_toast_that_shows_a_service_message_escapes_it(): void
    {
        foreach ($this->code() as $file) {
            $source = (string) file_get_contents($file);
            if (str_contains($source, 'getMessage()')) {
                $this->assertDoesNotMatchRegularExpression('/ToastMagic::\w+\([^;]*getMessage\(\)/', $source, basename($file));
                $this->assertMatchesRegularExpression('/SafeToast::error\(\'\', \$exception->getMessage\(\)\)/', $source, basename($file));
            }
        }
    }

    public function test_the_rule_form_inputs_for_money_are_numbers_and_no_template_names_a_default_price(): void
    {
        foreach ($this->views() as $file) {
            $source = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression('/<input[^>]*(name|id)="(amount|percent|min|max|unit_cost|total|subtotal|tax_total)"[^>]*value="\d/', $source, basename($file) . ' fills in a figure.');
            $this->assertDoesNotMatchRegularExpression('/placeholder="\d/', $source, basename($file) . ' suggests a figure.');
        }
    }
}
