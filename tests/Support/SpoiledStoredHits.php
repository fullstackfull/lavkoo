<?php

namespace Tests\Support;

/**
 * What a stored hit of the header search can be spoiled with. The hit of a recent search is whatever the employee's browser posted, so these are the shapes a
 * hit may arrive in that the search itself never gives. Each takes the hit it is made of and returns it spoiled in one way.
 */
final class SpoiledStoredHits
{
    /** @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>}> how the hit is spoiled, by what is wrong with it */
    public static function all(): array
    {
        return [
            'a type nobody knows' => [static fn (array $hit): array => ['type' => 'zz_forged'] + $hit],
            'a type that differs by its case' => [static fn (array $hit): array => ['type' => 'Users'] + $hit],
            'no type' => [self::without(field: 'type')],
            'an array as the type' => [static fn (array $hit): array => ['type' => ['users']] + $hit],
            'a number as the type' => [static fn (array $hit): array => ['type' => 5] + $hit],
            'no address' => [self::without(field: 'uri')],
            'an array as the address' => [static fn (array $hit): array => ['uri' => ['admin/customer/list']] + $hit],
            'a number as the address' => [static fn (array $hit): array => ['uri' => 5] + $hit],
            'a null address' => [static fn (array $hit): array => ['uri' => null] + $hit],
            'no title' => [self::without(field: 'page_title_value')],
            'an array as the title' => [static fn (array $hit): array => ['page_title_value' => ['Customers']] + $hit],
            'a number as the title' => [static fn (array $hit): array => ['page_title_value' => 5] + $hit],
            'no priority' => [self::without(field: 'priority')],
            'an array as the priority' => [static fn (array $hit): array => ['priority' => [3]] + $hit],
            'a priority that is no number' => [static fn (array $hit): array => ['priority' => 'high'] + $hit],
            'an array as the image' => [static fn (array $hit): array => ['image' => ['a.png']] + $hit],
        ];
    }

    /** @return callable(array<string, mixed>): array<string, mixed> */
    private static function without(string $field): callable
    {
        return static function (array $hit) use ($field): array {
            unset($hit[$field]);

            return $hit;
        };
    }
}
