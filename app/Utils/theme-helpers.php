<?php

if (!function_exists('theme_asset')) {
    function theme_asset($path = null): string
    {
        return dynamicAsset(path: $path);
    }
}

if (!function_exists('theme_root_path')) {
    /**
     * The storefront runs a single Blade theme: resources/themes/default.
     * Look, colors and home-page sections are managed in Admin -> Theme Management,
     * not by swapping theme folders — so this is a constant, not an env lookup.
     */
    function theme_root_path(): string
    {
        return 'default';
    }
}

if (!function_exists('theme_section_breakpoint_css')) {
    /**
     * CSS for a themed section's tablet/mobile overrides, which the Theme Builder saves as
     * `key_tablet` / `key_mobile` beside the desktop value.
     *
     * The desktop values ride on the section's own inline style; this emits a rule only for the
     * breakpoints a merchant actually filled in, so an untouched section adds no CSS at all.
     * Columns and heights travel as custom properties (--tb-cols / --tb-h) that the section's
     * markup already reads, which keeps one mechanism for every section type.
     */
    function theme_section_breakpoint_css(array $settings, string $selector): string
    {
        $formats = [
            'padding_top'    => fn ($value) => 'padding-top:' . max(0, (int) $value) . 'px;',
            'padding_bottom' => fn ($value) => 'padding-bottom:' . max(0, (int) $value) . 'px;',
            // --tb-cols-sm carries the phone count, which the grid prefers over the desktop one.
            'columns'        => fn ($value) => '--tb-cols:' . max(1, (int) $value) . ';--tb-cols-sm:' . max(1, (int) $value) . ';',
            'height'         => fn ($value) => '--tb-h:' . max(0, (int) $value) . 'px;',
        ];
        $css = '';

        foreach (['tablet' => 'max-width:991.98px', 'mobile' => 'max-width:767.98px'] as $breakpoint => $query) {
            $rules = '';
            foreach ($formats as $key => $format) {
                $value = $settings[$key . '_' . $breakpoint] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $rules .= $format($value);
            }
            if (array_key_exists('visible_' . $breakpoint, $settings) && !$settings['visible_' . $breakpoint]) {
                $rules .= 'display:none;';
            }
            if ($rules !== '') {
                $css .= '@media (' . $query . '){' . $selector . '{' . $rules . '}}';
            }
        }

        return $css;
    }
}

if (!function_exists('category_icon_url')) {
    /**
     * A category's icon URL, or NULL when it has no real image.
     *
     * Categories carry `def.png` as a placeholder value, and the storage resolver answers a
     * missing file with a generic grey placeholder — so a strip of sub-categories rendered as a
     * row of identical grey squares, which reads as "the icons do not show". Returning null lets
     * the caller draw a letter chip instead: distinguishable, and honest about there being no
     * artwork.
     */
    function category_icon_url(object|array|null $category): ?string
    {
        $icon = is_array($category) ? ($category['icon'] ?? null) : ($category->icon ?? null);
        if (empty($icon) || $icon === 'def.png') {
            return null;
        }

        $full = is_array($category) ? ($category['icon_full_url'] ?? null) : ($category->icon_full_url ?? null);
        $path = is_array($full) ? ($full['path'] ?? null) : $full;

        return $path ?: null;
    }
}

if (!function_exists('getHexToRGBColorCode')) {
    function getHexToRGBColorCode($hex): ?string
    {
        $result = preg_match('/^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i', $hex, $matches);
        return $result ? hexdec($matches[1]) . ', ' . hexdec($matches[2]) . ', ' . hexdec($matches[3]) : null;
    }
}

if (!function_exists('getSystemDynamicPartials')) {
    function getSystemDynamicPartials($type = null): mixed
    {
        if ($type == 'analytics_script') {
            return view("system-partials._analytics_script");
        }
        return null;
    }
}

if (!function_exists('formatCompactNumber')) {
    function formatCompactNumber(int|float|null $value = 0): string
    {
        if (!is_numeric($value)) {
            return (string) $value;
        }

        if ($value >= 1000000000) {
            return round($value / 1000000000, 2) . 'B+';
        } elseif ($value >= 1000000) {
            return round($value / 1000000, 2) . 'M+';
        } elseif ($value >= 1000) {
            return round($value / 1000, 2) . 'K+';
        }

        return (string) $value;
    }
}
