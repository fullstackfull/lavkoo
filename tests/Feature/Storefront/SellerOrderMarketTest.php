<?php

namespace Tests\Feature\Storefront;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Support\BuildsSellerMarketWorld;
use Tests\TestCase;

/**
 * K-04, the first half: the order list and the order detail of the seller app (api/v3/seller) carry the storefront and the currency each order was
 * sold in, and the list can be narrowed to one market. Everything here is additive and off by default: while storefronts are switched off, both
 * endpoints answer exactly what they answered before this work, whatever the app sends.
 *
 * The off answers were recorded from the controller as it was before it was touched, and are compared as recorded. To record them again (only
 * for a change the owner approved): RECORD_SELLER_MARKET_GOLDEN=1 vendor/bin/phpunit tests/Feature/Storefront/SellerOrderMarketTest.php
 */
class SellerOrderMarketTest extends TestCase
{
    use BuildsSellerMarketWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerMarketWorld();
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerMarketWorld();

        parent::tearDown();
    }

    /** @return array<string, array{bool, string}> */
    public static function ordersMigrationStates(): array
    {
        return [
            'before the orders migration has run' => [false, 'before_migration'],
            'after the orders migration has run' => [true, 'after_migration'],
        ];
    }

    #[DataProvider('ordersMigrationStates')]
    public function test_while_storefronts_are_off_the_order_list_answers_what_it_answered_before(bool $migrated, string $state): void
    {
        $this->buildSellerMarketWorld(ordersRecordTheirStorefront: $migrated);

        $this->assertRecordedAnswer(name: "order_list_{$state}", response: $this->sellerApi('POST', 'orders/list', ['limit' => 10, 'offset' => 1]));
    }

    #[DataProvider('ordersMigrationStates')]
    public function test_while_storefronts_are_off_a_market_filter_is_ignored(bool $migrated, string $state): void
    {
        $this->buildSellerMarketWorld(ordersRecordTheirStorefront: $migrated);

        foreach (['AE', 'ZZ', ['AE']] as $market) {
            $this->assertRecordedAnswer(
                name: "order_list_{$state}",
                response: $this->sellerApi('POST', 'orders/list', ['limit' => 10, 'offset' => 1, 'storefront' => $market]),
            );
        }
    }

    #[DataProvider('ordersMigrationStates')]
    public function test_while_storefronts_are_off_the_order_detail_answers_what_it_answered_before(bool $migrated, string $state): void
    {
        $this->buildSellerMarketWorld(ordersRecordTheirStorefront: $migrated);

        $this->assertRecordedAnswer(name: "order_detail_{$state}", response: $this->sellerApi('GET', 'orders/102'));
    }

    /**
     * What an answer was before, as text. The one value an engine hands back differently is the rate of the snapshot, a decimal the order model does
     * not cast (SQLite says 1, MySQL "1.00000000"), so it is written the short way on both sides; everything else, every key and its order, is exact.
     */
    private function assertRecordedAnswer(string $name, TestResponse $response): void
    {
        $this->assertSame(200, $response->getStatusCode());

        $answer = json_decode($response->getContent());
        $encoded = json_encode($this->withTheRateWrittenTheShortWay($answer), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $path = dirname(__DIR__, 2) . "/Support/Fixtures/SellerMarket/{$name}.json";

        if (getenv('RECORD_SELLER_MARKET_GOLDEN') === '1') {
            file_put_contents($path, $encoded . "\n");
            $this->markTestSkipped("recorded {$name}");
        }

        $this->assertFileExists($path, 'no recorded answer: record it from the controller as it was');
        $this->assertSame(rtrim((string) file_get_contents($path)), $encoded, "the answer of {$name} changed");
    }

    private function withTheRateWrittenTheShortWay(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->withTheRateWrittenTheShortWay(...), $value);
        }

        if (!$value instanceof stdClass) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value->{$key} = $key === 'currency_rate' && $item !== null ? rtrim(rtrim((string) $item, '0'), '.') : $this->withTheRateWrittenTheShortWay($item);
        }

        return $value;
    }
}
