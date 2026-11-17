<?php

namespace Tests\Unit\Security;

use App\Packages\AdvanceSearch\AdvanceSearch;
use App\Services\AdvancedSearchService;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Support\SpoiledStoredHits;
use Tests\TestCase;

/**
 * The recent searches that the box of the admin header lists are rows of `recent_searches`, and the hit in each is whatever the browser of the employee
 * posted when they clicked on it: nothing about its shape can be taken for granted. AdvancedSearchService::getSortRecentSearchByType() is where both header
 * searches read them. It keeps a stored hit only if the search itself could have given it (a type the search produces, an address, a title and a priority as
 * the search stores them) and leaves out the rest, so that the list never calls translate() on a group name an employee chose and never breaks on a row.
 */
class AdvancedSearchStoredHitsTest extends TestCase
{
    /** @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>}> how a stored hit is spoiled */
    public static function hitsTheSearchCouldNotHaveGiven(): array
    {
        return SpoiledStoredHits::all();
    }

    /** @return array<string, array{0: mixed}> what a row can hold in `response` that is no hit at all */
    public static function responsesThatAreNoHit(): array
    {
        return [
            'text that is no JSON' => ['not json at all'],
            'a JSON list' => ['[1,2]'],
            'JSON null' => ['null'],
            'a JSON string' => ['"just a string"'],
            'a JSON number' => ['42'],
            'an empty text' => [''],
            'nothing' => [null],
            'a number' => [42],
        ];
    }

    #[DataProvider('hitsTheSearchCouldNotHaveGiven')]
    public function test_a_stored_hit_the_search_could_not_have_given_is_left_out_and_the_others_are_kept(callable $makeTheHit): void
    {
        $kept = $this->hit(type: 'users', priority: 3, uri: 'admin/customer/list', title: 'Customers');
        $rows = [$this->row(response: json_encode($makeTheHit($this->hit(type: 'users', priority: 3, uri: 'admin/customer/view/4101', title: 'Quasarlynx')))), $this->row(response: json_encode($kept))];

        $this->assertSame(['users' => [$kept]], $this->service()->getSortRecentSearchByType(searchData: $rows));
    }

    #[DataProvider('responsesThatAreNoHit')]
    public function test_a_stored_response_that_is_no_hit_is_left_out_and_the_others_are_kept(mixed $response): void
    {
        $kept = $this->hit(type: 'menu', priority: '1', uri: 'admin/orders/list/all', title: 'Orders');

        $this->assertSame(['menu' => [$kept]], $this->service()->getSortRecentSearchByType(searchData: [$this->row(response: $response), $this->row(response: json_encode($kept))]));
    }

    public function test_a_hit_of_each_group_the_search_gives_comes_back_as_it_was_stored_in_the_order_of_its_priority(): void
    {
        $menu = $this->hit(type: 'menu', priority: '1', uri: 'admin/orders/list/all', title: 'Orders') + ['module' => 'orders', 'keywords' => 'orders, all'];
        $page = $this->hit(type: 'page', priority: 2, uri: 'admin/settings', title: 'Settings') + ['productNames' => [], 'items_count' => 1];
        $hits = [$page, $menu];
        foreach (array_keys((new AdvanceSearch(type: 'admin', keyword: ''))->getModels()) as $group) {
            $hits[] = $this->hit(type: $group, priority: 3, uri: 'admin/' . $group . '/list', title: 'A ' . $group) + ['image' => 'https://example.test/a.png', 'relations' => []];
        }

        $expected = ['menu' => [$menu], 'page' => [$page]];
        foreach (array_slice($hits, 2) as $hit) {
            $expected[$hit['type']] = [$hit];
        }

        $stored = array_map(fn (array $hit): stdClass => $this->row(response: json_encode($hit)), $hits);
        $this->assertSame($expected, $this->service()->getSortRecentSearchByType(searchData: $stored), 'The hits as the rows of the table give them');
        $posted = array_map(fn (array $hit): stdClass => $this->row(response: $hit), $hits);
        $this->assertSame($expected, $this->service()->getSortRecentSearchByType(searchData: $posted), 'The hits as an array posted as the response give them');
    }

    public function test_the_groups_the_search_gives_are_the_menu_the_pages_and_the_models(): void
    {
        $search = new AdvanceSearch(type: 'admin', keyword: '');

        $this->assertEqualsCanonicalizing(['menu', 'page', ...array_keys($search->getModels())], $search->getResultTypes());
        $this->assertSame(array_values(array_unique($search->getResultTypes())), $search->getResultTypes());
    }

    public function test_nothing_stored_means_nothing_listed(): void
    {
        $this->assertSame([], $this->service()->getSortRecentSearchByType(searchData: []));
    }

    /** @return array<string, mixed> a hit as the search stores it */
    private function hit(string $type, string|int $priority, string $uri, string $title): array
    {
        return ['page_title' => $title, 'page_title_value' => $title, 'uri' => $uri, 'type' => $type, 'priority' => $priority];
    }

    private function row(mixed $response): stdClass
    {
        $row = new stdClass();
        $row->response = $response;

        return $row;
    }

    private function service(): AdvancedSearchService
    {
        return app(AdvancedSearchService::class);
    }
}
