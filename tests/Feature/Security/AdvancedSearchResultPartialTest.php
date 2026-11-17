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

    /** A value that would run a script if the list put it into the page as markup. */
    private const MARKUP = '<img src=x onerror=alert(1)>';

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

    public function test_the_title_of_a_hit_cannot_put_markup_into_the_list(): void
    {
        $html = $this->render(hits: ['users' => [$this->hit(title: self::MARKUP)]], keyword: '');

        $this->assertStringNotContainsString(self::MARKUP, $html);
        $this->assertStringNotContainsString('<img src=x', $html);
    }

    public function test_the_typed_keyword_cannot_put_markup_into_the_list(): void
    {
        $html = $this->render(hits: ['users' => [$this->hit(title: 'Quasarlynx script')]], keyword: '<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_the_name_of_a_group_cannot_put_markup_into_the_list(): void
    {
        $html = $this->render(hits: [self::MARKUP => [$this->hit(title: 'Quasarlynx')]], keyword: '');

        $this->assertStringNotContainsString(self::MARKUP, $html);
    }

    public function test_the_image_of_a_hit_cannot_leave_its_attribute(): void
    {
        $html = $this->render(hits: ['users' => [$this->hit(title: 'Quasarlynx') + ['image' => '"><script>alert(1)</script>']]], keyword: '');

        $this->assertStringNotContainsString('"><script>alert(1)</script>', $html);
    }

    public function test_the_address_of_a_hit_cannot_put_markup_into_the_list(): void
    {
        $html = $this->render(hits: ['zz_stored' => [$this->hit(title: 'Stored hit', uri: self::MARKUP)]], keyword: '');

        $this->assertStringNotContainsString(self::MARKUP, $html);
        $this->assertMatchesRegularExpression('/<p class="text-muted fs-12 mb-0">\s*&lt;img src=x onerror=alert\(1\)&gt;\s*<\/p>/', $html);
    }

    public function test_the_address_of_a_hit_is_highlighted_where_the_keyword_is_in_it(): void
    {
        $html = $this->render(hits: ['users' => [$this->hit(title: 'Quasarlynx Hollenbeck', uri: 'admin/customer/view/4101')]], keyword: 'customer');

        $this->assertStringContainsString('admin/<mark>customer</mark>/view/4101', $html);
    }

    /** @return array<string, mixed> */
    private function hit(string $title, string $uri = 'admin/customer/list'): array
    {
        return ['page_title_value' => $title, 'uri' => $uri, 'priority' => 3, 'type' => 'users'];
    }

    /** @param array<string, list<array<string, mixed>>> $hits */
    private function render(array $hits, string $keyword): string
    {
        return view(self::PARTIAL, ['result' => $hits, 'keyword' => $keyword, 'recent' => false])->render();
    }
}
