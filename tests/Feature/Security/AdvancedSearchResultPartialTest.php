<?php

namespace Tests\Feature\Security;

use DOMDocument;
use DOMElement;
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

    public function test_the_list_does_not_grow_with_the_number_of_hits_times_the_size_of_the_whole_result(): void
    {
        $few = $this->render(hits: ['users' => $this->hits(count: 20)], keyword: '');
        $many = $this->render(hits: ['users' => $this->hits(count: 40)], keyword: '');

        $this->assertLessThan(2.4, strlen($many) / strlen($few), 'Twice the hits make the list ' . round(strlen($many) / strlen($few), 1) . ' times as long (' . strlen($few) . ' and ' . strlen($many) . ' bytes)');
        $this->assertLessThan(3000, strlen($many) / 40, 'Bytes of the list for each hit');
    }

    public function test_the_form_of_a_hit_posts_the_fields_the_recent_search_reads_and_no_others(): void
    {
        $html = $this->render(hits: ['users' => $this->hits(count: 3)], keyword: 'customer');

        $forms = $this->formsOf(html: $html);

        $this->assertCount(3, $forms);
        foreach ($this->hits(count: 3) as $position => $hit) {
            $form = $forms[$position];
            $this->assertSame(route('admin.advanced-search-recent'), $form['action']);
            $this->assertEqualsCanonicalizing(['_token', 'routeName', 'routeUri', 'routeFullUrl', 'searchKeyword', 'response'], array_keys($form['fields']));
            $this->assertSame('customer', $form['fields']['searchKeyword']);
            $this->assertSame($hit['page_title_value'], $form['fields']['routeName']);
            $this->assertSame($hit['uri'], $form['fields']['routeUri']);
            $this->assertSame(url($hit['uri']), $form['fields']['routeFullUrl']);
            $this->assertSame($hit, json_decode($form['fields']['response'], true));
        }
    }

    /** @return array<string, mixed> */
    private function hit(string $title, string $uri = 'admin/customer/list'): array
    {
        return ['page_title_value' => $title, 'uri' => $uri, 'priority' => 3, 'type' => 'users'];
    }

    /** @return list<array<string, mixed>> a number of different hits of the customers */
    private function hits(int $count): array
    {
        $hits = [];
        for ($position = 1; $position <= $count; $position++) {
            $hits[] = $this->hit(title: 'Customer ' . $position, uri: 'admin/customer/view/' . $position) + ['image' => 'https://example.test/customer/' . $position . '.png'];
        }

        return $hits;
    }

    /** @return list<array{action: string, fields: array<string, string>}> the forms of the list, with the fields a browser posts: the value of every input that has a name */
    private function formsOf(string $html): array
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $forms = [];
        foreach ($document->getElementsByTagName('form') as $form) {
            $fields = [];
            foreach ($form->getElementsByTagName('input') as $input) {
                /** @var DOMElement $input */
                $fields[$input->getAttribute('name')] = $input->getAttribute('value');
            }
            $forms[] = ['action' => $form->getAttribute('action'), 'fields' => $fields];
        }

        return $forms;
    }

    /** @param array<string, list<array<string, mixed>>> $hits */
    private function render(array $hits, string $keyword): string
    {
        return view(self::PARTIAL, ['result' => $hits, 'keyword' => $keyword, 'recent' => false])->render();
    }
}
