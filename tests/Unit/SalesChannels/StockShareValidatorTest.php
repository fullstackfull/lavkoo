<?php

namespace Tests\Unit\SalesChannels;

use App\Services\SalesChannels\Inventory\Allocation\StockShareValidator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** What may be typed about a listing's share of a pool and about a pool's reserve and buffer: ranges are checked here, because the database is not strict. */
class StockShareValidatorTest extends TestCase
{
    private StockShareValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new StockShareValidator();
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function goodShares(): iterable
    {
        yield 'nothing typed: an equal part' => [[], ['stock_share_mode' => null, 'stock_share_value' => null, 'stock_priority' => null]];
        yield 'blanks are nothing' => [['stock_share_mode' => '', 'stock_share_value' => '  ', 'stock_priority' => ''], ['stock_share_mode' => null, 'stock_share_value' => null, 'stock_priority' => null]];
        yield 'fixed units' => [['stock_share_mode' => 'fixed', 'stock_share_value' => '5'], ['stock_share_mode' => 'fixed', 'stock_share_value' => 5, 'stock_priority' => null]];
        yield 'no units at all is a share of zero' => [['stock_share_mode' => 'fixed', 'stock_share_value' => 0], ['stock_share_mode' => 'fixed', 'stock_share_value' => 0, 'stock_priority' => null]];
        yield 'the most units' => [['stock_share_mode' => 'fixed', 'stock_share_value' => 1000000], ['stock_share_mode' => 'fixed', 'stock_share_value' => 1000000, 'stock_priority' => null]];
        yield 'zero percent' => [['stock_share_mode' => 'percent', 'stock_share_value' => 0], ['stock_share_mode' => 'percent', 'stock_share_value' => 0, 'stock_priority' => null]];
        yield 'all of it' => [['stock_share_mode' => 'percent', 'stock_share_value' => 100], ['stock_share_mode' => 'percent', 'stock_share_value' => 100, 'stock_priority' => null]];
        yield 'priority needs no value, and one typed is dropped' => [['stock_share_mode' => 'priority', 'stock_share_value' => 7, 'stock_priority' => 1], ['stock_share_mode' => 'priority', 'stock_share_value' => null, 'stock_priority' => 1]];
        yield 'a value typed with no mode is dropped' => [['stock_share_value' => 7, 'stock_priority' => 0], ['stock_share_mode' => null, 'stock_share_value' => null, 'stock_priority' => 0]];
        yield 'the lowest priority number, and the highest' => [['stock_priority' => '1000'], ['stock_share_mode' => null, 'stock_share_value' => null, 'stock_priority' => 1000]];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $expected */
    #[DataProvider('goodShares')]
    public function test_what_is_accepted_is_returned_in_the_shape_it_is_stored(array $input, array $expected): void
    {
        $this->assertSame($expected, $this->validator->share($input));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badShares(): iterable
    {
        yield 'a percentage over a hundred' => [['stock_share_mode' => 'percent', 'stock_share_value' => 101], 'stock_share_value'];
        yield 'a wild percentage' => [['stock_share_mode' => 'percent', 'stock_share_value' => 250], 'stock_share_value'];
        yield 'a negative percentage' => [['stock_share_mode' => 'percent', 'stock_share_value' => -1], 'stock_share_value'];
        yield 'negative units' => [['stock_share_mode' => 'fixed', 'stock_share_value' => -5], 'stock_share_value'];
        yield 'more units than a stock can be' => [['stock_share_mode' => 'fixed', 'stock_share_value' => 1000001], 'stock_share_value'];
        yield 'half a unit' => [['stock_share_mode' => 'fixed', 'stock_share_value' => '1.5'], 'stock_share_value'];
        yield 'units that are not a number' => [['stock_share_mode' => 'fixed', 'stock_share_value' => 'lots'], 'stock_share_value'];
        yield 'a fixed share with no units' => [['stock_share_mode' => 'fixed'], 'stock_share_value'];
        yield 'a percentage with no value' => [['stock_share_mode' => 'percent', 'stock_share_value' => ''], 'stock_share_value'];
        yield 'a mode that does not exist' => [['stock_share_mode' => 'half'], 'stock_share_mode'];
        yield 'a negative priority' => [['stock_priority' => -1], 'stock_priority'];
        yield 'a priority that is a typing mistake' => [['stock_priority' => 1001], 'stock_priority'];
        yield 'a priority that is not whole' => [['stock_priority' => '1.5'], 'stock_priority'];
        yield 'a bad value is refused even when the mode would have dropped it' => [['stock_share_mode' => 'priority', 'stock_share_value' => -3], 'stock_share_value'];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('badShares')]
    public function test_what_is_out_of_range_is_refused_on_the_field_it_was_typed_in(array $input, string $field): void
    {
        try {
            $this->validator->share($input, 'listing_9');
            $this->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
            $this->assertSame('listing_9', $exception->errorBag, 'the error belongs to the listing whose form it was typed in');
        }
    }

    public function test_a_pools_reserve_and_buffer_are_checked_the_same_way(): void
    {
        $this->assertSame(['storefront_reserve_mode' => 'percent', 'storefront_reserve_value' => 50, 'safety_buffer' => 3], $this->validator->pool(['storefront_reserve_mode' => 'percent', 'storefront_reserve_value' => '50', 'safety_buffer' => '3']));
        $this->assertSame(['storefront_reserve_mode' => null, 'storefront_reserve_value' => null, 'safety_buffer' => null], $this->validator->pool(['storefront_reserve_value' => 9]));
        $this->assertSame(['storefront_reserve_mode' => 'fixed', 'storefront_reserve_value' => 0, 'safety_buffer' => 1000000], $this->validator->pool(['storefront_reserve_mode' => 'fixed', 'storefront_reserve_value' => 0, 'safety_buffer' => 1000000]));

        foreach ([
            [['storefront_reserve_mode' => 'percent', 'storefront_reserve_value' => 101], 'storefront_reserve_value'],
            [['storefront_reserve_mode' => 'percent', 'storefront_reserve_value' => -1], 'storefront_reserve_value'],
            [['storefront_reserve_mode' => 'fixed'], 'storefront_reserve_value'],
            [['storefront_reserve_mode' => 'fixed', 'storefront_reserve_value' => 2000000], 'storefront_reserve_value'],
            [['storefront_reserve_mode' => 'most'], 'storefront_reserve_mode'],
            [['safety_buffer' => -2], 'safety_buffer'],
            [['safety_buffer' => 1.5], 'safety_buffer'],
        ] as [$input, $field]) {
            try {
                $this->validator->pool($input);
                $this->fail('Expected a validation error for ' . json_encode($input));
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors(), json_encode($input));
            }
        }
    }
}
