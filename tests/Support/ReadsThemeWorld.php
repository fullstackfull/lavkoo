<?php

namespace Tests\Support;

use App\Services\Theme\StorefrontThemeResolver;
use Illuminate\Support\Facades\DB;

/** What a test of the theme code looks at before and after it acts: the rows of the theme tables, and the theme each storefront is served. For a test that uses BuildsThemeWorld. */
trait ReadsThemeWorld
{
    /** @return array<string, list<array<string, mixed>>> every row of the theme tables, as the database holds them */
    protected function rowsOfTheThemeTables(): array
    {
        $rows = [];
        foreach (['themes', 'theme_versions', 'theme_sections', 'theme_blocks'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $rows;
    }

    /** @return array<string, ?string> the name of the theme each of the six storefronts is served, by country: the one it has of its own that is active, or else the default storefront's */
    protected function themeServedToEachStorefront(): array
    {
        $served = [];
        foreach (['SA', 'AE', 'KW', 'QA', 'BH', 'OM'] as $country) {
            $this->serveRequestFromStorefront(country: $country);
            $served[$country] = app(StorefrontThemeResolver::class)->activeTheme()?->name;
        }

        return $served;
    }
}
