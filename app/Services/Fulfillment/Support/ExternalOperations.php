<?php

namespace App\Services\Fulfillment\Support;

/**
 * Every operation the integration can send to a provider, and what it does THERE.
 *
 * The transport (`ProviderHttp`) consults this before anything leaves the host. The rule is fail-closed: an
 * operation that is not listed, or that is listed but is being sent with a method it is not allowed to use, is
 * treated as a WRITE and refused while external writes are off. A developer who adds a new call and forgets to
 * register it therefore cannot make it reach a provider by accident, and a test (`ExternalWriteGuardTest`) fails
 * until it is classified.
 *
 *  READ   asks a question; nothing changes at the provider.
 *  AUTH   a login that only returns a token (it changes nothing a customer or the warehouse can see).
 *  WRITE  creates, changes or cancels something at the provider: an order, a product, a package.
 */
final class ExternalOperations
{
    public const READ = 'read';
    public const AUTH = 'auth';
    public const WRITE = 'write';

    /** @var array<string, array{0: string, 1: list<string>}> operation => [kind, allowed HTTP methods] */
    private const REGISTRY = [
        // M5AZN
        'login' => [self::AUTH, ['POST']],
        'catalog_list' => [self::READ, ['GET']],
        'my_products' => [self::READ, ['GET']],
        'product_details' => [self::READ, ['GET']],
        'qty_changed' => [self::READ, ['GET']],
        'categories' => [self::READ, ['GET']],
        'order_list' => [self::READ, ['GET']],
        'order_details' => [self::READ, ['GET']],
        'create_order' => [self::WRITE, ['POST']],

        // Torod Point: Fulfillment API v5
        'fulfillment_product_list' => [self::READ, ['GET']],
        'fulfillment_product_categories' => [self::READ, ['GET']],
        'fulfillment_order_list' => [self::READ, ['GET']],
        'fulfillment_villages' => [self::READ, ['GET']],
        'fulfillment_product_upsert' => [self::WRITE, ['POST']],
        'fulfillment_order_create' => [self::WRITE, ['POST']],

        // Torod Point: Lastmile API v2
        'lastmile_cities' => [self::READ, ['GET']],
        'lastmile_package_status' => [self::READ, ['GET']],
        // A POST, but it only asks for the URL of an air waybill that already exists: nothing changes at LogesTechs.
        'lastmile_awb' => [self::READ, ['POST']],
        'lastmile_package_cancel' => [self::WRITE, ['PUT']],
    ];

    /** Operations whose answers are catalogues or reference data (products, categories, cities): no person is in them. */
    private const ABOUT_THINGS = [
        'catalog_list', 'my_products', 'product_details', 'qty_changed', 'categories',
        'fulfillment_product_list', 'fulfillment_product_categories', 'fulfillment_villages', 'fulfillment_product_upsert', 'lastmile_cities',
    ];

    /**
     * Whether what goes to, or comes back from, this operation may describe a person: a login, an order, a package. Everything
     * that is not a known catalogue or reference call does, so a new operation is redacted strictly until someone says otherwise.
     */
    public static function describesPeople(string $operation): bool
    {
        return !in_array($operation, self::ABOUT_THINGS, true);
    }

    /** One of self::READ, self::AUTH or self::WRITE. Unknown operations and unexpected methods are WRITE. */
    public static function kindOf(string $operation, string $method): string
    {
        $entry = self::REGISTRY[$operation] ?? null;
        if ($entry === null || !in_array(strtoupper($method), $entry[1], true)) {
            return self::WRITE;
        }

        return $entry[0];
    }

    public static function isWrite(string $operation, string $method): bool
    {
        return self::kindOf($operation, $method) === self::WRITE;
    }

    /** @return list<string> the HTTP methods a registered operation may use (none for an unknown operation) */
    public static function methodsOf(string $operation): array
    {
        return self::REGISTRY[$operation][1] ?? [];
    }

    /** @return list<string> every registered operation name */
    public static function registered(): array
    {
        return array_keys(self::REGISTRY);
    }

    /** @return list<string> the operations classified as the given kind */
    public static function operationsOfKind(string $kind): array
    {
        return array_keys(array_filter(self::REGISTRY, static fn (array $entry): bool => $entry[0] === $kind));
    }
}
