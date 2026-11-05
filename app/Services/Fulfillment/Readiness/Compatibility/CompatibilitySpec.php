<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

use App\Services\Fulfillment\Readiness\PreflightCheck;

/**
 * Everything the fulfillment integration assumes about the store's own tables, in one place.
 *
 * `orders`, `order_details`, `products` and their neighbours come from the proprietary installation dump, which is not in
 * the repository: the integration's tests run on a schema inferred from the code that writes them
 * (tests/Support/BuildsFulfillmentSchema.php). These expectations are what the compatibility command checks against the
 * REAL restored database, so that a wrong inference shows up as a named column rather than as a failed order.
 */
final class CompatibilitySpec
{
    /** Statuses the integration understands; any other value found in the data is reported, not guessed. */
    public const ORDER_STATUSES = ['pending', 'confirmed', 'processing', 'out_for_delivery', 'delivered', 'returned', 'failed', 'canceled', 'refunded'];

    /** The integration treats every payment status except "paid" as unpaid. */
    public const PAYMENT_STATUSES = ['paid', 'unpaid'];

    public const ORDER_TYPES = ['default_type', 'POS'];

    public const PAYMENT_METHOD_CASH_ON_DELIVERY = 'cash_on_delivery';

    /** @var array<string, list<array{0: string, 1: string, 2: string, 3: string, 4?: int}>> table => [column, kind, severity, reason, minLength] */
    private static ?array $rows = null;

    /** @return list<ColumnExpectation> the required tables first, then the ones the integration only uses on a best-effort basis */
    public static function columns(): array
    {
        $expectations = [];
        foreach ([false => self::rows(), true => self::optionalRows()] as $optional => $tables) {
            foreach ($tables as $table => $columns) {
                foreach ($columns as $row) {
                    $expectations[] = new ColumnExpectation(table: $table, column: $row[0], kind: $row[1], severity: $row[2], reason: $row[3], minLength: $row[4] ?? null, optionalTable: (bool) $optional);
                }
            }
        }

        return $expectations;
    }

    /** @return array<string, list<string>> table => the columns the integration needs from it (tables it can live without are not listed) */
    public static function requiredColumns(): array
    {
        return array_map(static fn (array $columns): array => array_column($columns, 0), self::rows());
    }

    /** @return array<string, list<array{0: string, 1: string, 2: string, 3: string, 4?: int}>> */
    private static function rows(): array
    {
        $fail = PreflightCheck::FAIL;
        $warn = PreflightCheck::WARN;

        return self::$rows ??= [
            'orders' => [
                ['id', ColumnExpectation::ID, $fail, 'Every provider_* table stores the order id as a bigint, with no foreign key.'],
                ['customer_id', ColumnExpectation::REFERENCE, $fail, 'Scopes the customer-facing shipment API to the signed-in customer or guest.'],
                ['is_guest', ColumnExpectation::FLAG, $warn, 'Separates a guest from a customer who happens to share the id.'],
                ['order_status', ColumnExpectation::STRING, $fail, 'Compared with the closed and dispatchable statuses before every send.', 20],
                ['payment_status', ColumnExpectation::STRING, $fail, 'Decides prepaid versus cash on delivery for each shipment.', 10],
                ['payment_method', ColumnExpectation::STRING, $fail, 'Decides prepaid versus cash on delivery for each shipment.', 20],
                ['order_amount', ColumnExpectation::MONEY, $fail, 'The cash a provider is told to collect is derived from it.'],
                ['paid_amount', ColumnExpectation::MONEY, $fail, 'Part-paid cash on delivery asks the courier for the remainder only.'],
                ['shipping_cost', ColumnExpectation::MONEY, $warn, 'Shared across shipments in proportion to their lines.'],
                ['shipping_address_data', ColumnExpectation::TEXT, $fail, 'The delivery address sent to the provider is read from this JSON.'],
                ['order_type', ColumnExpectation::STRING, $warn, 'POS orders are never sent to a provider.'],
                ['order_note', ColumnExpectation::STRING, $warn, 'Forwarded to the provider as the delivery note (truncated to 250 characters).'],
                ['is_pause', ColumnExpectation::FLAG, $warn, 'A paused order is not dispatched.'],
                ['seller_id', ColumnExpectation::INTEGER, $warn, 'Shown on the shipment page.'],
                ['seller_is', ColumnExpectation::STRING, $warn, 'Shown on the shipment page.'],
                ['created_at', ColumnExpectation::DATETIME, $warn, 'Orders placed before a provider went live are held, by comparing this date.'],
                ['updated_at', ColumnExpectation::DATETIME, $warn, 'Touched by the status writers the integration guards.'],
            ],
            'order_details' => [
                ['id', ColumnExpectation::ID, $fail, 'Each ownership snapshot is tied to the order line it was taken from.'],
                ['order_id', ColumnExpectation::ID, $fail, 'Joins a line to its order.'],
                ['product_id', ColumnExpectation::ID, $fail, 'Decides which provider fulfils the line.'],
                ['seller_id', ColumnExpectation::INTEGER, $warn, 'Shown with the line.'],
                ['product_details', ColumnExpectation::TEXT, $fail, 'The product as it was when ordered; read to name the line for the provider.'],
                ['qty', ColumnExpectation::INTEGER, $fail, 'Quantity sent to the provider.'],
                ['price', ColumnExpectation::MONEY, $fail, 'Unit price: the shipment total is built from it.'],
                ['discount', ColumnExpectation::MONEY, $warn, 'Part of the line total.'],
                ['tax', ColumnExpectation::MONEY, $warn, 'Part of the line total, inclusive or exclusive according to tax_model.'],
                ['tax_model', ColumnExpectation::STRING, $warn, 'Tells whether tax is already inside the price.'],
                ['variant', ColumnExpectation::STRING, $warn, 'Identifies the variant when a product has several.'],
                ['variation', ColumnExpectation::TEXT, $warn, 'Variant detail as ordered.'],
                ['delivery_status', ColumnExpectation::STRING, $fail, 'Read by the delivery guards.', 20],
            ],
            'products' => [
                ['id', ColumnExpectation::ID, $fail, 'Stored as an integer in the ownership snapshot and the provider mappings.'],
                ['name', ColumnExpectation::STRING, $warn, 'Matched against provider catalogs and shown on shipments.'],
                ['slug', ColumnExpectation::STRING, $warn, 'Generated for imported products.'],
                ['code', ColumnExpectation::STRING, $warn, 'The SKU: provider products are matched on it.'],
                ['barcode', ColumnExpectation::STRING, $warn, 'Provider products are matched on it.'],
                ['added_by', ColumnExpectation::STRING, $fail, 'Only products added by the admin can be given to a provider.'],
                ['user_id', ColumnExpectation::INTEGER, $warn, 'Owner of the product.'],
                ['shop_id', ColumnExpectation::INTEGER, $warn, 'Written for imported products.'],
                ['product_type', ColumnExpectation::STRING, $fail, 'Only physical products can be given to a provider.'],
                ['category_ids', ColumnExpectation::TEXT, $warn, 'Written for imported products.'],
                ['category_id', ColumnExpectation::INTEGER, $warn, 'Written for imported products.'],
                ['sub_category_id', ColumnExpectation::INTEGER, $warn, 'Written for imported products.'],
                ['sub_sub_category_id', ColumnExpectation::INTEGER, $warn, 'Written for imported products.'],
                ['brand_id', ColumnExpectation::INTEGER, $warn, 'Written for imported products.'],
                ['unit', ColumnExpectation::STRING, $warn, 'Written for imported products.'],
                ['details', ColumnExpectation::TEXT, $warn, 'Description of an imported product (sanitised first).'],
                ['colors', ColumnExpectation::TEXT, $warn, 'Written for imported products.'],
                ['choice_options', ColumnExpectation::TEXT, $warn, 'Written for imported products.'],
                ['attributes', ColumnExpectation::TEXT, $warn, 'Written for imported products.'],
                ['variation', ColumnExpectation::TEXT, $fail, 'Variant list as JSON; a provider variant is matched to it.'],
                ['unit_price', ColumnExpectation::MONEY, $fail, 'The selling price: a product with no price is never sellable.'],
                ['purchase_price', ColumnExpectation::MONEY, $warn, 'Holds the supplier cost of an imported product.'],
                ['tax', ColumnExpectation::MONEY, $warn, 'Written for imported products.'],
                ['tax_type', ColumnExpectation::STRING, $warn, 'Written for imported products.'],
                ['tax_model', ColumnExpectation::STRING, $warn, 'Written for imported products.'],
                ['discount', ColumnExpectation::MONEY, $warn, 'Written for imported products.'],
                ['discount_type', ColumnExpectation::STRING, $warn, 'Written for imported products.'],
                ['current_stock', ColumnExpectation::INTEGER, $fail, 'Stock the store sells from; the M5AZN stock sync writes it.'],
                ['minimum_order_qty', ColumnExpectation::INTEGER, $warn, 'Written for imported products.'],
                ['video_provider', ColumnExpectation::STRING, $warn, 'Written for imported products.'],
                ['status', ColumnExpectation::INTEGER, $fail, 'An imported product stays unpublished (0) until reviewed.'],
                ['request_status', ColumnExpectation::INTEGER, $warn, 'Written for imported products.'],
                ['shipping_cost', ColumnExpectation::MONEY, $warn, 'Written for imported products.'],
                ['multiply_qty', ColumnExpectation::INTEGER, $warn, 'Written for imported products.'],
                ['color_image', ColumnExpectation::TEXT, $warn, 'Written for imported products.'],
                ['images', ColumnExpectation::TEXT, $warn, 'Written for imported products.'],
                ['thumbnail', ColumnExpectation::STRING, $warn, 'Written for imported products.'],
                ['thumbnail_storage_type', ColumnExpectation::STRING, $warn, 'Written for imported products.'],
                ['meta_title', ColumnExpectation::STRING, $warn, 'Written for imported products.'],
            ],
            'business_settings' => [
                ['id', ColumnExpectation::ID, $fail, 'Settings are read by type.'],
                ['type', ColumnExpectation::STRING, $fail, 'The setting name (currency model, default currency, decimal places).'],
                ['value', ColumnExpectation::TEXT, $fail, 'The setting value, often JSON.'],
            ],
            'currencies' => [
                ['id', ColumnExpectation::ID, $fail, 'The default currency is referenced by id.'],
                ['code', ColumnExpectation::STRING, $fail, 'SAR is looked up by code.'],
                ['exchange_rate', ColumnExpectation::MONEY, $fail, 'Amounts sent to a provider are converted to riyals with it.'],
            ],
            'admins' => [
                ['id', ColumnExpectation::ID, $fail, 'Recorded as the actor of operator actions.'],
                ['admin_role_id', ColumnExpectation::INTEGER, $fail, 'Decides who can open the Integration Center.'],
            ],
            'admin_roles' => [
                ['id', ColumnExpectation::ID, $fail, 'Joined from admins.'],
                ['module_access', ColumnExpectation::TEXT, $fail, 'Holds the "3rd_party_setup" permission the Integration Center requires.'],
                ['status', ColumnExpectation::FLAG, $warn, 'An inactive role grants nothing.'],
            ],
            'categories' => [
                ['id', ColumnExpectation::ID, $fail, 'Imported products are filed under one.'],
                ['name', ColumnExpectation::STRING, $warn, 'Shown in the import category picker.'],
                ['parent_id', ColumnExpectation::INTEGER, $warn, 'Only top-level categories are offered for import.'],
                ['position', ColumnExpectation::INTEGER, $warn, 'Zero marks a top-level category.'],
            ],
            'carts' => [
                ['id', ColumnExpectation::ID, $fail, 'Read by the checkout cash-on-delivery guard.'],
                ['customer_id', ColumnExpectation::REFERENCE, $fail, 'The guard reads the cart of the customer or guest who is paying.'],
                ['cart_group_id', ColumnExpectation::STRING, $fail, 'The guard reads the lines of the cart groups being paid.'],
                ['product_id', ColumnExpectation::ID, $fail, 'The guard asks which provider owns each line before offering cash on delivery.'],
                ['is_checked', ColumnExpectation::FLAG, $fail, 'Only the ticked lines are being bought.'],
            ],
            'attributes' => [
                ['id', ColumnExpectation::ID, $warn, 'Variant attributes of an imported product are created on demand.'],
                ['name', ColumnExpectation::STRING, $warn, 'Matched by name before a new attribute is created.'],
            ],
        ];
    }

    /** @return array<string, list<array{0: string, 1: string, 2: string, 3: string, 4?: int}>> */
    private static function optionalRows(): array
    {
        $warn = PreflightCheck::WARN;

        return [
            'stock_movements' => [
                ['product_id', ColumnExpectation::ID, $warn, 'Each stock change the inventory sync makes is recorded here; without it the sync still works but leaves no history.'],
                ['seller_id', ColumnExpectation::INTEGER, $warn, 'Owner of the movement.'],
                ['type', ColumnExpectation::STRING, $warn, 'The sync records an "adjustment".'],
                ['qty_change', ColumnExpectation::INTEGER, $warn, 'Signed change written by the sync.'],
                ['balance_after', ColumnExpectation::INTEGER, $warn, 'Stock after the change.'],
                ['reason', ColumnExpectation::STRING, $warn, 'Why the stock changed.'],
                ['reference_type', ColumnExpectation::STRING, $warn, 'What caused it (an order, a provider sync).'],
                ['reference_id', ColumnExpectation::INTEGER, $warn, 'Which one.'],
                ['note', ColumnExpectation::TEXT, $warn, 'Free note.'],
            ],
        ];
    }
}
