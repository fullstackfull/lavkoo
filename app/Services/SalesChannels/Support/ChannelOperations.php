<?php

namespace App\Services\SalesChannels\Support;

use App\Enums\SalesChannels\IntegrationCapability as Capability;

/**
 * Every call this module can make to a sales channel, and what it does THERE.
 *
 * The transport (`ChannelHttp`) consults this before anything leaves the host, and it fails closed: an operation that is not listed, or is
 * sent with a method it is not listed for, is refused outright. A developer who adds a call and forgets to register it cannot make it reach a channel,
 * and `ChannelOperationsTest` fails until it is classified.
 *
 *  READ   asks a question; nothing changes at the channel.
 *  AUTH   a sign-in that only returns a token.
 *  WRITE  creates or changes something at the channel: a price, a quantity, a listing, a shipment, a webhook.
 *
 * `capabilities`: the account must have AT LEAST ONE of these switched on for the call to be made.
 * `scopes`: for a WRITE, the approval that must have been given by a person (see ChannelAccountService) for this kind of write.
 * `retrySafe`: repeating the same request after a timeout or 5xx cannot do harm, so the transport may retry it (reads, and writes that set an
 * absolute value). Everything else is sent once; whether it may be sent again is for the caller to decide from the state it can read.
 * `people`: whether what goes out or comes back may describe a person (an order, a buyer, an address); such logs are redacted strictly.
 */
final class ChannelOperations
{
    public const READ = 'read';
    public const AUTH = 'auth';
    public const WRITE = 'write';

    public const SCOPE_PRODUCT_WRITES = 'product_writes';
    public const SCOPE_WEBHOOK_MANAGEMENT = 'webhook_management';
    public const SCOPE_NOTIFICATION_MANAGEMENT = 'notification_management';

    private const READ_CAPABILITIES = [Capability::ReadOnlySync, Capability::ProductSync, Capability::OrderImport, Capability::FinancialReconciliation];

    /**
     * @var array<string, array{kind: string, methods: list<string>, capabilities: list<Capability>, scopes: list<string>, retrySafe: bool, people: bool, requiredQuery?: array<string, string>}>
     */
    private static array $registry = [];

    private static bool $loaded = false;

    /** Adds operations to the registry (the connectors' own lists are loaded from `sales_channels.operation_providers` the first time it is read). */
    public static function register(array $operations): void
    {
        foreach ($operations as $name => $entry) {
            self::$registry[$name] = $entry + ['scopes' => [], 'retrySafe' => $entry['kind'] !== self::WRITE, 'people' => false];
        }
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        foreach ((array) config('sales_channels.operation_providers', []) as $provider) {
            if (class_exists($provider)) {
                self::register($provider::operations());
            }
        }
    }

    /**
     * @param list<Capability> $capabilities
     * @param array<string, string> $requiredQuery query values that MUST be present: what makes a non-GET call a read (a validation preview that submits nothing)
     */
    public static function read(array $methods = ['GET'], array $capabilities = self::READ_CAPABILITIES, bool $people = false, array $requiredQuery = [], bool $retrySafe = true): array
    {
        return ['kind' => self::READ, 'methods' => $methods, 'capabilities' => $capabilities, 'scopes' => [], 'retrySafe' => $retrySafe, 'people' => $people, 'requiredQuery' => $requiredQuery];
    }

    public static function auth(array $methods = ['POST']): array
    {
        return ['kind' => self::AUTH, 'methods' => $methods, 'capabilities' => [], 'scopes' => [], 'retrySafe' => true, 'people' => true];
    }

    /**
     * @param list<Capability> $capabilities
     * @param list<string> $scopes
     */
    public static function write(array $methods, array $capabilities, array $scopes, bool $retrySafe = false, bool $people = false): array
    {
        return ['kind' => self::WRITE, 'methods' => $methods, 'capabilities' => $capabilities, 'scopes' => $scopes, 'retrySafe' => $retrySafe, 'people' => $people];
    }

    public static function isRegistered(string $operation): bool
    {
        self::load();

        return isset(self::$registry[$operation]);
    }

    /** One of READ, AUTH or WRITE. An unexpected method on a registered operation is a WRITE. @throws \OutOfBoundsException for an unregistered operation */
    public static function kindOf(string $operation, string $method): string
    {
        $entry = self::entry($operation);

        return in_array(strtoupper($method), $entry['methods'], true) ? $entry['kind'] : self::WRITE;
    }

    public static function methodAllowed(string $operation, string $method): bool
    {
        return in_array(strtoupper($method), self::entry($operation)['methods'], true);
    }

    /** @return list<Capability> */
    public static function capabilities(string $operation): array
    {
        return self::entry($operation)['capabilities'];
    }

    /** @return list<string> */
    public static function scopes(string $operation): array
    {
        return self::entry($operation)['scopes'];
    }

    public static function isRetrySafe(string $operation): bool
    {
        return self::entry($operation)['retrySafe'];
    }

    /** @return array<string, string> */
    public static function requiredQuery(string $operation): array
    {
        return self::entry($operation)['requiredQuery'] ?? [];
    }

    public static function describesPeople(string $operation): bool
    {
        return !self::isRegistered($operation) || self::entry($operation)['people'];
    }

    /** @return list<string> */
    public static function registered(): array
    {
        self::load();

        return array_keys(self::$registry);
    }

    /** @return list<string> */
    public static function operationsOfKind(string $kind): array
    {
        self::load();

        return array_keys(array_filter(self::$registry, static fn (array $entry): bool => $entry['kind'] === $kind));
    }

    /** For tests: forget everything registered. */
    public static function flush(): void
    {
        self::$registry = [];
        self::$loaded = false;
    }

    /** @return array{kind: string, methods: list<string>, capabilities: list<Capability>, scopes: list<string>, retrySafe: bool, people: bool} */
    private static function entry(string $operation): array
    {
        self::load();

        return self::$registry[$operation] ?? throw new \OutOfBoundsException("Operation '{$operation}' is not registered.");
    }
}
