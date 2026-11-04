<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

/**
 * Static UI/UX audit over the Blade templates (Phase 1.5).
 *
 * A browser sweep can only cover the pages someone remembers to open; this checks EVERY template
 * for the defect classes that actually recur in this codebase — RTL-breaking directional utilities,
 * hardcoded UI strings that bypass translate(), tables that can overflow the page, and missing
 * accessibility attributes.
 *
 * It reports file:line with the offending snippet so each finding is directly actionable, and exits
 * non-zero when errors are found so it can gate CI.
 *
 *   php artisan audit:ui                 # audit everything
 *   php artisan audit:ui --path=admin-views --severity=error
 */
class AuditUiConsistency extends Command
{
    protected $signature = 'audit:ui
        {--path= : Limit to a sub-path of resources/views}
        {--severity=all : all|error|warning}
        {--limit=40 : Max findings to print per rule}
        {--include-styleguide : Also scan the component showcase page (off by default)}';

    protected $description = 'Audit Blade templates for RTL, i18n, accessibility and layout issues';

    /**
     * Paths excluded by default.
     *
     * `layouts/admin/components` is the component SHOWCASE page (route admin/component) — a
     * styleguide whose English demo labels ("Text", "Button", "Dropdown") are deliberate, not
     * untranslated UI. Scanning it produced ~210 false positives that would drown the real
     * findings, so it is opt-in via --include-styleguide.
     */
    private const EXCLUDED_PATHS = ['layouts/admin/components', 'layouts/admin/component-snippets'];

    /**
     * Directional utilities that break in RTL, mapped to their logical equivalents.
     *
     * These are NOT blindly auto-fixable — see PANEL_CSS below. Whether the logical class exists
     * depends on which panel serves the page.
     */
    private const RTL_UNSAFE = [
        'ml-' => 'ms-', 'mr-' => 'me-', 'pl-' => 'ps-', 'pr-' => 'pe-',
        'text-left' => 'text-start', 'text-right' => 'text-end',
        'float-left' => 'float-start', 'float-right' => 'float-end',
        'border-left' => 'border-start', 'border-right' => 'border-end',
    ];

    /**
     * The two panels load DIFFERENT Bootstrap majors:
     *
     *   admin  — layouts/admin/app  → assets/backend/libs/bootstrap  = Bootstrap 5.3.3  (184 views)
     *   vendor — layouts/vendor/app → assets/back-end/css/bootstrap  = Bootstrap 4.5    (46 views)
     *
     * A class absent from a panel's bundle does not fall back to anything — it silently does
     * nothing, so the layout is quietly wrong rather than visibly broken. That is how a BS4
     * `form-row` on an admin page stacked a three-column upload form into one column.
     *
     * The Bootstrap version alone does NOT answer whether a class works, because 6Valley's own
     * stylesheets shim an arbitrary scattered subset back in: on the admin panel `.mr-2` is defined
     * but `.mr-1` and `.mr-4` are not; on vendor `.ps-2` and `.ps-20` exist but `.ps-1` and `.ps-3`
     * do not. So this rule reads the ACTUAL stylesheets each layout loads and checks membership,
     * rather than encoding a version-derived guess.
     */
    private const PANEL_STYLESHEET_SOURCES = [
        'admin' => [
            'resources/views/layouts/admin/partials/_style-partials.blade.php',
            'resources/views/layouts/admin/app.blade.php',
        ],
        'vendor' => [
            'resources/views/layouts/vendor/partials/_style-partials.blade.php',
            'resources/views/layouts/vendor/app.blade.php',
        ],
    ];

    /**
     * Only class tokens matching these shapes are checked against the stylesheets.
     *
     * Deliberately narrow: a template is full of JS hooks, third-party widget classes and bespoke
     * names that legitimately have no CSS rule. These are the Bootstrap-family utilities where
     * "silently defined nowhere" is a real and recurring layout bug.
     */
    private const CHECKED_CLASS_SHAPES = [
        '/^[mp][stebxy]?-(?:n?[0-5]|auto)$/',                       // spacing utilities
        '/^(?:text|float|border)-(?:start|end|left|right)$/',        // directional utilities
        '/^(?:form-row|no-gutters|card-deck|jumbotron|form-inline|btn-block|sr-only|visually-hidden)$/',
    ];

    /** Suggested replacement for the classes we can name one for. */
    private const CLASS_REPLACEMENTS = [
        'form-row' => 'row', 'no-gutters' => 'g-0', 'card-deck' => 'row row-cols-*',
        'jumbotron' => 'a plain .p-5.bg-light block', 'form-inline' => 'd-flex on the form',
        'sr-only' => 'visually-hidden', 'visually-hidden' => 'sr-only', 'btn-block' => 'w-100',
    ];

    /** @var array<string, array<string, true>> panel => set of class names defined in its stylesheets */
    private array $definedClassCache = [];

    /** @var array<int, string>|null every Blade template path, resolved once per run */
    private ?array $allTemplatesCache = null;

    public function handle(): int
    {
        $base = resource_path('views');
        $path = $this->option('path') ? $base . '/' . trim((string) $this->option('path'), '/') : $base;

        if (!is_dir($path)) {
            $this->error("Path not found: {$path}");
            return self::FAILURE;
        }

        $findings = [];
        $files = Finder::create()->files()->in($path)->name('*.blade.php');
        $scanned = 0;

        foreach ($files as $file) {
            $relative = str_replace(base_path() . '/', '', $file->getRealPath());

            if (!$this->option('include-styleguide') && $this->isExcluded($relative)) {
                continue;
            }

            $scanned++;
            $content = (string) file_get_contents($file->getRealPath());
            $lines = preg_split('/\R/', $content) ?: [];

            $panel = $this->panelOf($relative);
            [$definedClasses, $cssScope] = $this->resolveStylesheetScope($content, $panel);
            $mirroredClasses = $this->classesAlreadyMirrored($content);

            foreach ($lines as $i => $line) {
                $lineNo = $i + 1;
                foreach ($this->inspect($line, $panel, $definedClasses, $cssScope, $mirroredClasses) as $issue) {
                    $findings[$issue['rule']][] = $issue + ['file' => $relative, 'line' => $lineNo];
                }
            }

            // whole-file checks.
            //
            // This rule's premise is "a wide table makes the PAGE scroll sideways", which only holds
            // for a panel page in a browser viewport. Two kinds of template are exempt because the
            // premise does not apply, not because the finding is inconvenient:
            //   - Email templates: tables ARE the layout mechanism, and .table-responsive does
            //     nothing in a mail client.
            //   - Invoices / PDFs / print views: a printed page has no viewport to scroll, and
            //     overflow:auto would clip content off the sheet rather than make it reachable.
            $isEmailTemplate = str_contains($relative, 'email-template') || str_contains($relative, 'mail-template');
            $isPrintDocument = $this->isStandaloneDocument($content) || $this->isPrintTemplate($relative);

            if (!$isEmailTemplate
                && !$isPrintDocument
                && preg_match('/<table[\s>]/i', $content)
                && !str_contains($content, 'table-responsive')
                && !str_contains($content, 'x-ui.data-table')
                && $this->canOverflowHorizontally($content)
                && !$this->isWrappedByEveryIncludeSite($relative)) {
                // Report only what can actually be established. A partial has no container of its
                // own, so the claim "this scrolls the page sideways" depends on how it is reached:
                //   - rendered from PHP for AJAX injection -> the container is whatever the JS
                //     drops it into, which is not visible statically;
                //   - no reference anywhere -> the template may simply be unused, and wrapping it
                //     would be editing dead code.
                [$message, $fix] = match (true) {
                    $this->isRenderedFromPhp($relative) => [
                        'Bare table in a partial rendered from PHP for AJAX injection — the container is not statically visible.',
                        'Check that the element the response is injected into carries .table-responsive.',
                    ],
                    !$this->hasAnyReference($relative) => [
                        'Bare table in a template nothing appears to reference — it may be unused.',
                        'Confirm whether this template is still reached before wrapping it.',
                    ],
                    default => [
                        'Table without a responsive wrapper — can scroll the whole page sideways.',
                        'Wrap it in <x-ui.data-table> or a .table-responsive container.',
                    ],
                };

                $findings['table_overflow'][] = [
                    'rule' => 'table_overflow', 'severity' => 'warning', 'file' => $relative, 'line' => 0,
                    'message' => $message,
                    'fix' => $fix,
                    'snippet' => '<table>',
                ];
            }
        }

        return $this->report($findings, $scanned);
    }

    /**
     * Utility classes in a class attribute that this panel's stylesheets never define.
     *
     * @return array<int, string>
     */
    private function undefinedUtilityClasses(string $classAttribute, ?array $defined): array
    {
        if ($defined === null) {
            return []; // stylesheet scope unknown — stay silent rather than report noise
        }

        $undefined = [];
        // Blade can interpolate into a class attribute; only fixed tokens can be checked.
        foreach (preg_split('/\s+/', preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', ' ', $classAttribute) ?? '') as $token) {
            if ($token === '' || isset($defined[$token])) {
                continue;
            }
            foreach (self::CHECKED_CLASS_SHAPES as $shape) {
                if (preg_match($shape, $token)) {
                    $undefined[] = $token;
                    break;
                }
            }
        }

        return $undefined;
    }

    /**
     * Which stylesheets actually apply to this template, and a label for the message.
     *
     * Two kinds of template need different answers, and conflating them produced 30 false
     * positives:
     *
     *  - A PANEL page/partial is rendered inside layouts/{admin,vendor}/app and gets that panel's
     *    bundle, plus anything it adds in its own <style> block.
     *  - A STANDALONE document (invoice, PDF, print view) is a complete <html> page that links its
     *    own CSS and never sees the panel bundle at all. order_transaction_summary_report_pdf
     *    links admin/order-transaction.css, which defines the .text-left it was flagged for.
     *
     * @return array{0: array<string, true>|null, 1: string} [defined classes or null to skip, label]
     */
    private function resolveStylesheetScope(string $content, ?string $panel): array
    {
        $own = $this->classesDefinedInline($content) + $this->classesFromLinkedStylesheets($content);

        if ($this->isStandaloneDocument($content)) {
            return $own === [] ? [null, ''] : [$own, 'this document'];
        }

        if ($panel === null) {
            return [null, '']; // shared/storefront template — the serving layout is unknown
        }

        $bundle = $this->definedClasses($panel);

        return $bundle === [] ? [null, ''] : [$bundle + $own, "the {$panel} panel"];
    }

    /** A full HTML document renders on its own rather than inside a panel layout. */
    private function isStandaloneDocument(string $content): bool
    {
        return (bool) preg_match('/<html\b/i', $content);
    }

    /**
     * Whether every template that @includes this partial already wraps it in a scroll container.
     *
     * A partial cannot be judged in isolation. `_edit-sku-combinations.blade.php` holds a bare
     * <table>, but all three of its include sites wrap it in
     * `<div class="sku_combination table-responsive ...">`. Reporting it made me add a SECOND
     * wrapper inside the first, which is worse than the finding — nested scroll containers give the
     * user two scrollbars.
     *
     * Proximity-based by design: parsing Blade's block structure would be far more brittle. The
     * lookback is 8 lines because the include is often nested inside an @if/@else under the
     * wrapper, as in _update-stock.blade.php.
     */
    private function isWrappedByEveryIncludeSite(string $relativePath): bool
    {
        // resources/views/admin-views/product/partials/_x.blade.php -> admin-views.product.partials._x
        $viewName = str_replace('/', '.', preg_replace(
            ['#^resources/views/#', '#\.blade\.php$#'], '', $relativePath
        ) ?? '');

        if ($viewName === '') {
            return false;
        }

        $sites = 0;
        $wrapped = 0;
        foreach ($this->allTemplates() as $path) {
            $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];
            foreach ($lines as $i => $line) {
                if (!str_contains($line, "@include('{$viewName}'") && !str_contains($line, "@include(\"{$viewName}\"")) {
                    continue;
                }
                $sites++;
                $start = max(0, $i - 8);
                $lookback = implode("\n", array_slice($lines, $start, $i - $start + 1));
                if (str_contains($lookback, 'table-responsive') || str_contains($lookback, 'x-ui.data-table')) {
                    $wrapped++;
                }
            }
        }

        return $sites > 0 && $sites === $wrapped;
    }

    /**
     * Directional classes this page already makes direction-aware, so the RTL rule must stay quiet.
     *
     * Two mechanisms are in use here, both legitimate:
     *   - the template redefines the class in its own <style> using the direction variable
     *     (admin-views/order/invoice.blade.php);
     *   - a stylesheet it loads carries a [dir="rtl"] override for it (the printed statements).
     *
     * @return array<string, true> keyed by the RTL_UNSAFE key ("ml-", "text-left", …)
     */
    private function classesAlreadyMirrored(string $content): array
    {
        $css = '';
        if (preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $content, $blocks)) {
            $css .= implode("\n", $blocks[1]);
        }
        if (preg_match_all('#public/assets/[^"\']*\.css#', $content, $hrefs)) {
            foreach (array_unique($hrefs[0]) as $href) {
                $path = base_path($href);
                if (is_file($path)) {
                    $css .= "\n" . file_get_contents($path);
                }
            }
        }

        if ($css === '') {
            return [];
        }

        $mirrored = [];
        foreach (array_keys(self::RTL_UNSAFE) as $class) {
            // "ml-" covers ml-1…ml-auto, so match the prefix rather than an exact token.
            $name = preg_quote($class, '/') . (str_ends_with($class, '-') ? '[\w-]+' : '');

            // (a) a [dir="rtl"] rule for it
            if (preg_match('/\[dir\s*=\s*["\']?rtl["\']?\][^{]*\.' . $name . '\b/i', $css)) {
                $mirrored[$class] = true;
                continue;
            }
            // (b) redefined with a direction-dependent value (Blade emits the branch inline)
            if (preg_match('/\.' . $name . '\s*\{[^}]*\$direction[^}]*\}/i', $css)) {
                $mirrored[$class] = true;
            }
        }

        return $mirrored;
    }

    /**
     * Whether any template names this view — @include, @extends, @each or a component reference.
     *
     * Only a hint: a view name assembled at runtime from a variable is invisible here, so this
     * returning false means "nothing found", not "definitely unused". The finding it produces is
     * worded accordingly.
     */
    private function hasAnyReference(string $relativePath): bool
    {
        $viewName = $this->viewNameOf($relativePath);
        if ($viewName === '') {
            return false;
        }

        foreach ($this->allTemplates() as $path) {
            if (str_contains((string) file_get_contents($path), $viewName)) {
                return true;
            }
        }

        return $this->isRenderedFromPhp($relativePath);
    }

    /** resources/views/a/b/_c.blade.php -> a.b._c */
    private function viewNameOf(string $relativePath): string
    {
        return str_replace('/', '.', preg_replace(
            ['#^resources/views/#', '#\.blade\.php$#'], '', $relativePath
        ) ?? '');
    }

    /** Whether PHP renders this view directly, e.g. `view('...')->render()` for an AJAX response. */
    private function isRenderedFromPhp(string $relativePath): bool
    {
        $viewName = str_replace('/', '.', preg_replace(
            ['#^resources/views/#', '#\.blade\.php$#'], '', $relativePath
        ) ?? '');

        if ($viewName === '') {
            return false;
        }

        foreach (['app', 'Modules'] as $directory) {
            $path = base_path($directory);
            if (!is_dir($path)) {
                continue;
            }
            foreach (Finder::create()->files()->in($path)->name('*.php') as $file) {
                if (str_contains((string) file_get_contents($file->getRealPath()), "'{$viewName}'")) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<int, string> absolute paths of every Blade template */
    private function allTemplates(): array
    {
        if ($this->allTemplatesCache !== null) {
            return $this->allTemplatesCache;
        }

        $paths = [];
        foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
            $paths[] = $file->getRealPath();
        }

        return $this->allTemplatesCache = $paths;
    }

    /**
     * Whether a template's widest table could actually overflow a viewport.
     *
     * Two shapes cannot, and wrapping them would be cargo-cult rather than a fix:
     *   - role="presentation": the author has explicitly declared it a layout table, not data.
     *   - Three columns or fewer: this codebase renders "label : value" description lists as
     *     tables (contacts/view, product-gallery, brand-view). A two- or three-column key/value
     *     list has nothing to scroll.
     */
    private function canOverflowHorizontally(string $content): bool
    {
        if (str_contains($content, 'role="presentation"')) {
            return false;
        }

        // A table with no cells at all has nothing to overflow. payment/paytm.blade.php wraps a set
        // of hidden inputs in a <table> purely to auto-submit a redirect form.
        if (!preg_match('/<t[hd]\b/i', $content)) {
            return false;
        }

        $widest = 0;
        if (preg_match_all('#<tr\b[^>]*>(.*?)</tr>#is', $content, $rows)) {
            foreach ($rows[1] as $row) {
                $widest = max($widest, preg_match_all('/<t[hd]\b/i', $row));
            }
        }

        // Cells exist but no parseable rows (a table assembled in JS) — keep reporting rather than
        // assume it is safe.
        return $widest === 0 || $widest > 3;
    }

    /**
     * A print-destined fragment, which is not a full document but is still never scrolled.
     *
     * pos/order/invoice.blade.php has no <html> of its own — it is included into a print modal —
     * yet it is printed on paper, so the "page scrolls sideways" premise does not apply to it.
     */
    private function isPrintTemplate(string $relativePath): bool
    {
        foreach (['invoice', '_pdf', 'pdf.blade', 'print', 'barcode'] as $marker) {
            if (str_contains($relativePath, $marker)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Class names a template defines in its own <style> block.
     *
     * @return array<string, true>
     */
    private function classesDefinedInline(string $content): array
    {
        if (!preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $content, $blocks)) {
            return [];
        }

        return $this->classNamesIn(implode("\n", $blocks[1]));
    }

    /**
     * Class names from stylesheets the template links itself.
     *
     * @return array<string, true>
     */
    private function classesFromLinkedStylesheets(string $content): array
    {
        if (!preg_match_all('#public/assets/[^"\']*\.css#', $content, $matches)) {
            return [];
        }

        $defined = [];
        foreach (array_unique($matches[0]) as $href) {
            $path = base_path($href);
            if (is_file($path)) {
                $defined += $this->classNamesIn((string) file_get_contents($path));
            }
        }

        return $defined;
    }

    /** @return array<string, true> */
    private function classNamesIn(string $css): array
    {
        preg_match_all('/\.(-?[A-Za-z_][\w-]*)/', $css, $matches);

        $defined = [];
        foreach ($matches[1] as $class) {
            $defined[$class] = true;
        }

        return $defined;
    }

    /**
     * Every class name defined by the stylesheets a panel's layout actually links.
     *
     * @return array<string, true>
     */
    private function definedClasses(string $panel): array
    {
        if (isset($this->definedClassCache[$panel])) {
            return $this->definedClassCache[$panel];
        }

        $hrefs = [];
        foreach (self::PANEL_STYLESHEET_SOURCES[$panel] ?? [] as $source) {
            $path = base_path($source);
            if (!is_file($path)) {
                continue;
            }
            preg_match_all('#public/assets/[^"\']*\.css#', (string) file_get_contents($path), $matches);
            $hrefs = array_merge($hrefs, $matches[0]);
        }

        $defined = [];
        foreach (array_unique($hrefs) as $href) {
            $cssPath = base_path($href);
            if (!is_file($cssPath)) {
                continue;
            }
            preg_match_all('/\.(-?[A-Za-z_][\w-]*)/', (string) file_get_contents($cssPath), $classMatches);
            foreach ($classMatches[1] as $class) {
                $defined[$class] = true;
            }
        }

        return $this->definedClassCache[$panel] = $defined;
    }

    /** The logical/physical counterpart of a directional class, when there is an obvious one. */
    private function mirroredClass(string $class): ?string
    {
        $pairs = ['s' => 'l', 'e' => 'r', 'l' => 's', 'r' => 'e',
                  'start' => 'left', 'end' => 'right', 'left' => 'start', 'right' => 'end'];

        if (preg_match('/^([mp])([sler])(-.*)$/', $class, $m)) {
            return $m[1] . $pairs[$m[2]] . $m[3];
        }
        if (preg_match('/^(text|float|border)-(start|end|left|right)$/', $class, $m)) {
            return $m[1] . '-' . $pairs[$m[2]];
        }
        return null;
    }

    /**
     * Panel-specific advice for an RTL finding.
     *
     * The generic "check the Bootstrap first" warning was true but useless — it left every one of
     * these findings requiring manual investigation. Since the panel determines the stylesheet
     * bundle, and the bundles have been measured, the answer can just be stated.
     */
    private function rtlFixAdvice(string $bad, string $good, ?array $defined, string $cssScope): string
    {
        if ($defined === null || $defined === []) {
            return "Use \"{$good}\", but verify first: the stylesheets serving this template could "
                . "not be resolved, so whether the logical class exists here is unknown.";
        }

        if (str_ends_with($good, '-')) {
            // Only the prefix is known here (e.g. "ms-"), not the step, so answer for the family —
            // the spacing scale is exactly what differs between the two panels.
            $available = array_values(array_filter(
                array_map(fn ($n) => $good . $n, range(0, 5)),
                fn ($c) => isset($defined[$c])
            ));

            return $available === []
                ? "Do NOT swap to \"{$good}*\" here: {$cssScope} defines none of {$good}0…{$good}5, so "
                . "the spacing would disappear instead of mirroring. Add the rule to the stylesheet "
                . "this page loads, or migrate it to Bootstrap 5 first."
                : "Use \"{$good}*\" — {$cssScope} defines " . implode(', ', $available)
                . ". Confirm the step you need is in that list.";
        }

        return isset($defined[$good])
            ? "Use \"{$good}\" — verified present in the stylesheets {$cssScope} loads, so this swap is safe."
            : "Do NOT swap to \"{$good}\" here: {$cssScope} does not define it, so the rule would "
            . "silently do nothing. Add it to the stylesheet this page loads first.";
    }

    /**
     * Which panel serves this template, and therefore which Bootstrap it gets.
     * Returns null for shared/storefront templates, where no panel-specific claim is safe.
     */
    private function panelOf(string $relativePath): ?string
    {
        if (str_contains($relativePath, 'admin-views/') || str_contains($relativePath, 'layouts/admin/')) {
            return 'admin';
        }
        if (str_contains($relativePath, 'vendor-views/') || str_contains($relativePath, 'layouts/vendor/')) {
            return 'vendor';
        }
        return null;
    }

    /** @return array<int, array{rule:string, severity:string, message:string, fix:string, snippet:string}> */
    private function inspect(
        string $line,
        ?string $panel = null,
        ?array $definedClasses = null,
        string $cssScope = '',
        array $mirroredClasses = []
    ): array {
        $issues = [];
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '{{--')) {
            return $issues;
        }

        // Tag-shape rules must see HTML, not Blade. `{{ $asset->url }}` contains a literal ">" from
        // the object arrow, which terminates a tag as far as a regex is concerned — so
        // `<img src="{{ $a->b }}" alt="…">` looked like an unterminated <img> with no alt and was
        // reported as missing alt text. Every Blade img src has an arrow in it, so this rule was
        // largely reporting itself.
        $html = $this->stripBladeExpressions($line);

        // 1. RTL-unsafe directional utilities inside class attributes
        if (preg_match('/class\s*=\s*"([^"]*)"/i', $line, $m)) {
            foreach (self::RTL_UNSAFE as $bad => $good) {
                // Skip a class the page's own CSS already mirrors. invoice.blade.php emits
                // `.text-left { text-align: {{ $direction === "rtl" ? 'right' : 'left' }} }`, and the
                // printed statements now carry [dir="rtl"] overrides — in both cases the physical
                // name is direction-aware, so demanding a logical class would be wrong.
                if (isset($mirroredClasses[$bad])) {
                    continue;
                }

                $pattern = str_ends_with($bad, '-')
                    ? '/(^|\s)' . preg_quote($bad, '/') . '\d/'
                    : '/(^|\s)' . preg_quote($bad, '/') . '(\s|$)/';
                if (preg_match($pattern, $m[1])) {
                    $issues[] = [
                        'rule' => 'rtl_directional', 'severity' => 'warning',
                        'message' => "Directional class \"{$bad}\" does not mirror in RTL (Arabic).",
                        'fix' => $this->rtlFixAdvice($bad, $good, $definedClasses, $cssScope),
                        'snippet' => $this->snippet($trimmed),
                    ];
                    break;
                }
            }

            // 1b. Utility classes none of this page's stylesheets define — silently no-ops.
            foreach ($this->undefinedUtilityClasses($m[1], $definedClasses) as $class) {
                $replacement = self::CLASS_REPLACEMENTS[$class] ?? $this->mirroredClass($class);
                $issues[] = [
                    'rule' => 'class_not_defined', 'severity' => 'warning',
                    'message' => "\"{$class}\" is not defined by any stylesheet {$cssScope} loads — it does nothing.",
                    'fix' => $replacement ? "Use \"{$replacement}\", or add the rule to a stylesheet this page loads."
                        : "Remove it, or add the rule to a stylesheet this page loads.",
                    'snippet' => $this->snippet($trimmed),
                ];
                break; // one finding per line is enough to make it actionable
            }
        }

        // 2. Hardcoded user-facing button/heading text (bypasses translate())
        if (preg_match('/<(button|h[1-6]|th|label)[^>]*>\s*([A-Za-z][A-Za-z ]{2,40})\s*<\/\1>/', $html, $m)) {
            $text = trim($m[2]);
            if (!str_contains($line, 'translate(') && !str_contains($line, '__(')) {
                $issues[] = [
                    'rule' => 'hardcoded_string', 'severity' => 'error',
                    'message' => "Hardcoded UI text \"{$text}\" is never translated.",
                    'fix' => 'Wrap it in translate(\'key\').',
                    'snippet' => $this->snippet($trimmed),
                ];
            }
        }

        // 3. Images without alt text
        if (preg_match('/<img\b(?![^>]*\balt=)[^>]*>/i', $html)) {
            $issues[] = [
                'rule' => 'missing_alt', 'severity' => 'warning',
                'message' => 'Image without an alt attribute (accessibility + image SEO).',
                'fix' => 'Add alt="" for decorative images, or a description.',
                'snippet' => $this->snippet($trimmed),
            ];
        }

        // 4. Icon-only buttons/links with no accessible name
        if (preg_match('/<(button|a)\b[^>]*>\s*<i\b[^>]*><\/i>\s*<\/\1>/i', $html)
            && !preg_match('/aria-label|title=/i', $line)) {
            $issues[] = [
                'rule' => 'icon_button_no_label', 'severity' => 'warning',
                'message' => 'Icon-only control with no accessible name — unusable with a screen reader.',
                'fix' => 'Add aria-label="{{ translate(\'…\') }}" or a title.',
                'snippet' => $this->snippet($trimmed),
            ];
        }

        // 5. Inline onclick handlers (CSP-hostile, untestable)
        if (preg_match('/\bonclick\s*=/i', $line) && !str_contains($line, 'confirm(')) {
            $issues[] = [
                'rule' => 'inline_handler', 'severity' => 'warning',
                'message' => 'Inline onclick handler — blocks a strict CSP and is hard to test.',
                'fix' => 'Bind the handler in a @push(\'script\') block instead.',
                'snippet' => $this->snippet($trimmed),
            ];
        }

        return $issues;
    }

    private function isExcluded(string $relativePath): bool
    {
        foreach (self::EXCLUDED_PATHS as $excluded) {
            if (str_contains($relativePath, $excluded)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Replace Blade expressions with a single non-markup placeholder so the tag-shape rules see the
     * HTML skeleton only.
     *
     * \x01 is deliberate: it is neither "<", ">" nor a letter, so it can neither terminate a tag nor
     * be mistaken for untranslated user-facing text.
     */
    private function stripBladeExpressions(string $line): string
    {
        return (string) preg_replace(
            ['/\{!!.*?!!\}/s', '/\{\{.*?\}\}/s'],
            "\x01",
            $line
        );
    }

    private function snippet(string $line): string
    {
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;
        return mb_strlen($line) > 100 ? mb_substr($line, 0, 100) . '…' : $line;
    }

    private function report(array $findings, int $scanned): int
    {
        $severity = (string) $this->option('severity');
        $limit = max(1, (int) $this->option('limit'));

        $this->info("Scanned {$scanned} Blade templates.");
        $this->newLine();

        $totals = ['error' => 0, 'warning' => 0];

        foreach ($findings as $rule => $items) {
            $filtered = $severity === 'all' ? $items : array_values(array_filter($items, fn ($i) => $i['severity'] === $severity));
            if ($filtered === []) {
                continue;
            }

            $first = $filtered[0];
            $this->line(sprintf('<comment>%s</comment> [%s] — %d finding(s)', $rule, $first['severity'], count($filtered)));
            $this->line('  ' . $first['message']);
            $this->line('  <info>Fix:</info> ' . $first['fix']);

            foreach (array_slice($filtered, 0, $limit) as $item) {
                $this->line(sprintf('   %s:%d  %s', $item['file'], $item['line'], $item['snippet']));
            }
            if (count($filtered) > $limit) {
                $this->line(sprintf('   … and %d more (raise --limit to see them)', count($filtered) - $limit));
            }
            $this->newLine();

            foreach ($filtered as $item) {
                $totals[$item['severity']] = ($totals[$item['severity']] ?? 0) + 1;
            }
        }

        if (array_sum($totals) === 0) {
            $this->info('No UI issues found.');
            return self::SUCCESS;
        }

        $this->line(sprintf('<comment>Totals:</comment> %d error(s), %d warning(s)', $totals['error'] ?? 0, $totals['warning'] ?? 0));

        // Non-zero only on errors, so warnings can be burned down without blocking CI.
        return ($totals['error'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
