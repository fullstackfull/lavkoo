<?php

namespace App\Services\Staff;

use App\Http\Controllers\Admin\Fulfillment\M5aznProductsController;
use App\Http\Controllers\Admin\Fulfillment\ProviderFulfillmentController;
use App\Http\Controllers\Admin\Fulfillment\TorodMatchingController;
use App\Http\Controllers\Admin\MyFatoorah\SettingsController as MyFatoorahSettingsController;
use App\Http\Controllers\Admin\SalesChannels\ChannelAccountOperationsController;
use App\Http\Controllers\Admin\SalesChannels\ChannelInventoryController;
use App\Http\Controllers\Admin\SalesChannels\ChannelListingController;
use App\Http\Controllers\Admin\SalesChannels\ChannelOrderController;
use App\Http\Controllers\Admin\SalesChannels\ChannelProductController;
use App\Http\Controllers\Admin\SalesChannels\ChannelRoutingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The INTEGRATION screens of slice 6 that a staff country scope covers (GCC-14c; G-08, G-09): the sales channels (the channel listings, our
 * products put on a channel, the stock a channel holds, the unified orders, the routing rules and everything that calls a marketplace), the
 * fulfilment providers (the provider shipments, M5azn's catalogue, the Torod point-of-sale matching) and the payment-gateway monitor.
 *
 * `ScopeIntegrationsToStaffCountries` reads the rule of the action for an employee who is restricted to some countries, and a route with NO
 * rule is REFUSED to that employee rather than let through. The operational costs are the other half of the slice and are
 * `StaffCostRoutes`.
 *
 * What is different here from the five slices before it, and what made the slice wait: these screens use ROUTE MODEL BINDING
 * (`SalesChannelAccount $account`, `ChannelListingDraft $draft`, `InventoryLocation $location`, `FulfillmentRoutingRule $rule`), and
 * `SubstituteBindings` runs in the `web` group BEFORE a controller's own middleware -- so `$request->route('account')` hands back the MODEL.
 * `StaffRouteIds::one()` now reads a bound model's key, which is the whole of the enabling change.
 *
 * Which thing carries the market is not the same on every screen, and the product is the WRONG answer on the ones that look most like it:
 *
 *  - a CHANNEL LISTING belongs to its ACCOUNT, not to the product it points at. `sales_channel_product_mappings.product_id` is nullable and
 *    is null for exactly the rows the screen exists for -- an unlinked listing waiting for somebody to say which of our products it is -- so
 *    a product rule would hide the whole job from every restricted employee. An account sells in one market (`ChannelAccountCountry`), and
 *    one whose settings name no country belongs to no market and is refused, the answer an order with no line gets;
 *  - LINKING a listing to a product is judged by BOTH: the listing's account and the posted product. That is the real security property of
 *    this screen -- a Kuwait employee must not be able to attach a Saudi product to a Kuwait channel listing and start publishing its price
 *    and stock to a marketplace;
 *  - a CHANNEL ORDER (`external_order_mappings`) belongs to its account too: its `order_id` is nullable until we take responsibility for
 *    fulfilling it, so the account is the key it always has;
 *  - a LISTING DRAFT (`channel_listing_drafts.product_id`, not nullable) is one of OUR products going out, so it follows the product;
 *  - a PROVIDER SHIPMENT (`provider_fulfillments.order_id`) is a shipment of an order, so it follows the order, as slice 5's fulfilments do;
 *  - a WAREHOUSE is judged by its own market (`inventory_locations.storefront_country`), which is what `coversEveryInventoryLocation()` was
 *    built for in slice 5 and had no caller for until here;
 *  - a ROUTING RULE is judged by the product or the country it names, and a rule that names neither decides for every market, so it stays on
 *    the list -- it has no market to be hidden by -- and every write of it is refused. That is the rule a global commission rule follows.
 *
 * Three screens are settled by a REFUSAL (`unavailable`, the vocabulary slices 3, 4 and 5 use), each for a reason that is a fact about the
 * data and not a preference:
 *
 *  - the M5AZN catalogue and the TOROD matching are one provider's own catalogue with no market column at all, and what they import or match
 *    becomes a product of the DEFAULT storefront. So they belong to the default market and to no other (`default_market`), which is a
 *    refusal for every employee who does not hold it rather than a narrowing;
 *  - the PAYMENT-GATEWAY MONITOR (MyFatoorah) lists payment ATTEMPTS, and an attempt names a `payment_request_id` and not an order -- the
 *    order does not exist yet when the attempt is made. So no attempt can be told apart by market, while the actions on that screen
 *    REFUND money and re-run webhooks. Every action is refused to a restricted employee rather than letting an employee of one market take
 *    money back on another market's payment;
 *  - the PROVIDER API LOGS are the provider's traffic for the whole platform.
 */
final class StaffIntegrationRoutes
{
    /** @var array<class-string, array<string, list<string>>> */
    private const RULES = [
        // The channel listings the marketplaces sent us, and the one screen where our product is chosen for one.
        ChannelProductController::class => [
            'index' => ['list'],
            'productSearch' => ['list'],
            'link' => ['listing.route:mapping', 'product.input:product_id'],
            'acceptSuggestion' => ['listing.route:mapping'],
            'ignore' => ['listing.route:mapping'],
            'unignore' => ['listing.route:mapping'],
            'settings' => ['listing.route:mapping'],
            'fulfilmentMode' => ['listing.route:mapping'],
        ],
        // Putting one of OUR products on a channel: the product and the account at the start, the draft from then on.
        ChannelListingController::class => [
            'show' => ['product.route:product'],
            'draft' => ['product.route:product', 'account.route:account'],
            'clearance' => ['draft.route:draft'],
            'withdraw' => ['draft.route:draft'],
            'check' => ['draft.route:draft'],
            'identify' => ['draft.route:draft'],
            'chooseCatalogItem' => ['draft.route:draft'],
            'follow' => ['draft.route:draft'],
            'publish' => ['draft.route:draft'],
        ],
        // The stock a channel holds, by warehouse.
        ChannelInventoryController::class => [
            'index' => ['list'],
            'channels' => ['location.route:location'],
        ],
        // The unified orders of every channel.
        ChannelOrderController::class => [
            'index' => ['list'],
            'show' => ['channel_order.route:mapping'],
            'route' => ['channel_order.route:mapping'],
            'dispatchToProvider' => ['channel_order.route:mapping'],
            'linkShipment' => ['channel_order.route:mapping'],
            'unlinkShipment' => ['channel_order.route:mapping'],
            'notify' => ['channel_order.route:mapping'],
        ],
        // The routing rules. Only a rule that names a product or a country belongs to a market.
        ChannelRoutingController::class => [
            'index' => ['none'],
            'store' => ['routing_rule.posted'],
            'update' => ['routing_rule.route:rule', 'routing_rule.posted'],
            'toggle' => ['routing_rule.route:rule'],
        ],
        // Everything that CALLS a marketplace: each one is an account's connection, so each is judged by the account's market.
        ChannelAccountOperationsController::class => [
            'test' => ['account.route:account'],
            'sync' => ['account.route:account'],
            'publish' => ['account.route:account'],
            'webhook' => ['account.route:account'],
            'listWebhooks' => ['account.route:account'],
            'lookup' => ['account.route:account'],
            'addGroupMembers' => ['account.route:account'],
        ],
        // The shipments a fulfilment provider is carrying for us: each is a shipment of one order.
        ProviderFulfillmentController::class => [
            'index' => ['list:' . StaffCountryScope::SUBJECT_PROVIDER_FULFILLMENTS],
            'show' => ['fulfillment.route:id'],
            'action' => ['fulfillment.route:id'],
            'label' => ['fulfillment.route:id'],
            'logs' => ['unavailable'],
        ],
        // M5azn's own catalogue: no market column, and an import becomes a product of the default storefront.
        M5aznProductsController::class => [
            'index' => ['default_market'],
            'import' => ['default_market'],
            'importAll' => ['default_market'],
        ],
        // The Torod point-of-sale matching: the same, and its city decisions are the default storefront's address catalogue.
        TorodMatchingController::class => [
            'index' => ['default_market'],
            'approve' => ['default_market'],
            'retire' => ['default_market'],
            'stock' => ['default_market'],
            'push' => ['default_market'],
            'productSearch' => ['default_market'],
            'alias' => ['default_market'],
            'citySearch' => ['default_market'],
            'internalCitySearch' => ['default_market'],
            'approveCity' => ['default_market'],
            'rejectCity' => ['default_market'],
            'retireCity' => ['default_market'],
            'discoverCity' => ['default_market'],
        ],
        // The payment-gateway monitor: an attempt names a payment request and not an order, and the actions refund money.
        MyFatoorahSettingsController::class => [
            'index' => ['unavailable'],
            'update' => ['unavailable'],
            'testConnection' => ['unavailable'],
            'refreshMethods' => ['unavailable'],
            'reconcile' => ['unavailable'],
            'reverify' => ['unavailable'],
            'confirmNeverCreated' => ['unavailable'],
            'rerunHook' => ['unavailable'],
            'refund' => ['unavailable'],
            'markRefundExecuted' => ['unavailable'],
        ],
    ];

    /** @return list<class-string> the controllers whose actions a staff country scope covers among the integrations of slice 6 */
    public static function controllers(): array
    {
        return array_keys(self::RULES);
    }

    /** @return array<string, list<string>> */
    public static function rulesOf(string $controller): array
    {
        return self::RULES[ltrim($controller, '\\')] ?? [];
    }

    /** @return list<string>|null */
    public static function ruleOf(string $controller, string $method): ?array
    {
        return self::rulesOf(controller: $controller)[$method] ?? null;
    }

    /**
     * What a part of a rule says this request names, as a claim the scope can answer: the products, the orders, the warehouses or the
     * channel accounts it must cover, and whether it needs the default storefront's market.
     *
     * @return array{products?: list<int>, orders?: list<int>, locations?: list<int>, accounts?: list<int>, countries?: list<string>, default_market?: bool}|null
     *         null when the part asks for something that cannot be told apart by market, names a row that does not exist, or is a part
     *         nobody understands
     */
    public static function claimOf(Request $request, string $part): ?array
    {
        if ($part === 'none' || $part === 'list' || str_starts_with($part, 'list:')) {
            return [];
        }

        if ($part === 'unavailable') {
            return null;
        }

        if ($part === 'default_market') {
            return ['default_market' => true];
        }

        [$subject, $where] = array_pad(explode('.', $part, 2), 2, '');

        if ($where === 'posted') {
            return $subject === 'routing_rule'
                ? self::marketOfARoutingRule(productId: $request->input('product_id'), country: $request->input('country'))
                : null;
        }

        [$kind, $name] = array_pad(explode(':', $where, 2), 2, '');
        $optional = str_ends_with($name, '?');
        $name = rtrim($name, '?');

        if ($name === '' || !in_array($kind, ['route', 'input'], true)) {
            return null;
        }

        $value = $kind === 'route' ? $request->route($name) : $request->input($name);

        if ($optional && StaffRouteIds::blank(value: $value)) {
            return [];
        }

        $id = StaffRouteIds::one(value: $value);

        if ($id === null) {
            return null;
        }

        return match ($subject) {
            'product' => ['products' => $id],
            'order' => ['orders' => $id],
            'location' => ['locations' => $id],
            'account' => ['accounts' => $id],
            'listing' => self::accountOfARow(table: 'sales_channel_product_mappings', rowId: $id[0]),
            'channel_order' => self::accountOfARow(table: 'external_order_mappings', rowId: $id[0]),
            'draft' => self::productOfARow(table: 'channel_listing_drafts', rowId: $id[0]),
            'fulfillment' => self::orderOfARow(table: 'provider_fulfillments', rowId: $id[0]),
            'routing_rule' => self::marketOfARoutingRuleRow(ruleId: $id[0]),
            default => null,
        };
    }

    /**
     * What a rule says a screen LISTS through the ORDER its rows belong to. A part that says `list` alone names no subject: the rows the
     * model scopes narrow -- a channel listing by its account, a draft and a cost layer by their product, a warehouse by its own market --
     * are narrowed as soon as the request is bound, so nothing has to ask for them.
     *
     * @param list<string> $rule
     * @return list<string>
     */
    public static function subjectsOf(array $rule): array
    {
        return array_values(array_map(
            static fn (string $part): string => substr($part, strlen('list:')),
            array_filter($rule, static fn (string $part): bool => str_starts_with($part, 'list:'))
        ));
    }

    /** @return array{accounts: list<int>}|null null when the row is gone, so a restricted employee is answered as for something that does not exist */
    private static function accountOfARow(string $table, int $rowId): ?array
    {
        $accountId = DB::table($table)->where('id', $rowId)->value('account_id');

        return $accountId === null ? null : ['accounts' => [(int) $accountId]];
    }

    /** @return array{products: list<int>}|null */
    private static function productOfARow(string $table, int $rowId): ?array
    {
        $productId = DB::table($table)->where('id', $rowId)->value('product_id');

        return $productId === null ? null : ['products' => [(int) $productId]];
    }

    /** @return array{orders: list<int>}|null */
    private static function orderOfARow(string $table, int $rowId): ?array
    {
        $orderId = DB::table($table)->where('id', $rowId)->value('order_id');

        return $orderId === null ? null : ['orders' => [(int) $orderId]];
    }

    /** @return array{products: list<int>}|array{countries: list<string>}|null */
    private static function marketOfARoutingRuleRow(int $ruleId): ?array
    {
        $rule = DB::table('fulfillment_routing_rules')->where('id', $ruleId)->first(['product_id', 'country']);

        return $rule === null ? null : self::marketOfARoutingRule(productId: $rule->product_id, country: $rule->country);
    }

    /**
     * The market a routing rule decides for: the product it names, or the country it names. A rule that names NEITHER decides where every
     * market's parcels go, so a restricted employee may not write it -- the rule a global commission rule follows, for the same reason.
     *
     * A rule naming a country is read as the market of that country, which is what the dispatcher does with it. `country` is the ISO code of
     * the destination, so a rule for Kuwait is a Kuwait employee's to change and nobody else's.
     *
     * @return array{products: list<int>}|array{countries: list<string>}|null
     */
    private static function marketOfARoutingRule(mixed $productId, mixed $country): ?array
    {
        if (!StaffRouteIds::blank(value: $productId)) {
            $id = StaffRouteIds::one(value: $productId);

            return $id === null ? null : ['products' => $id];
        }

        $code = strtoupper(trim((string) ($country ?? '')));

        return $code === '' ? null : ['countries' => [$code]];
    }
}
