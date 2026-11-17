<?php

namespace Tests\Feature\Security;

use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The list the search box of the admin header shows, rendered from the hits a search controller hands it. The controllers answer with it (`htmlView`), and a
 * hit can come from the database or, when the box lists recent searches, from what the employee's browser posted earlier.
 */
class AdvancedSearchResultPartialTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private const PARTIAL = 'layouts.admin.partials._advance-search-result';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    public function test_the_list_can_be_rendered_more_than_once_in_one_process(): void
    {
        $hits = ['users' => [['page_title_value' => 'Quasarlynx Hollenbeck', 'uri' => 'admin/customer/list', 'priority' => 3, 'type' => 'users']]];

        $first = $this->render(hits: $hits, keyword: 'quasar');
        $second = $this->render(hits: $hits, keyword: 'quasar');

        $this->assertStringContainsString('<mark>Quasar</mark>lynx Hollenbeck', $first);
        $this->assertSame($first, $second);
    }

    /** @param array<string, list<array<string, mixed>>> $hits */
    private function render(array $hits, string $keyword, bool $recent = false): string
    {
        return view(self::PARTIAL, ['result' => $hits, 'keyword' => $keyword, 'recent' => $recent])->render();
    }
}
