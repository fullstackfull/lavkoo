<?php

namespace App\Http\Controllers\Admin\SalesChannels\Support;

use App\Enums\Fulfillment\ProviderCode;
use App\Enums\SalesChannels\ExecutionPolicy;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Enums\SalesChannels\RoutingAction;
use App\Enums\SalesChannels\SalesChannel;
use App\Models\FulfillmentRoutingRule;
use App\Models\SalesChannelAccount;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * What an administrator may set on a routing rule, and how it becomes the columns of `fulfillment_routing_rules`.
 *
 * A rule matches only when every condition it sets holds, so a field left empty means "any". The conditions on the order total and on cash on delivery are kept together in
 * the rule's `conditions`, where the matcher reads them. A rule is never deleted, only switched off.
 */
final class RoutingRuleForm
{
    /** How the "cash on delivery" condition is chosen: `any` sets no condition. */
    public const COD_ANY = 'any';
    public const COD_ONLY = 'yes';
    public const COD_EXCLUDED = 'no';

    /** The actions that do not use a target provider: a value there would suggest an effect it does not have. */
    private const ACTIONS_WITHOUT_TARGET = [RoutingAction::ManualReview, RoutingAction::Hold, RoutingAction::ChannelFulfills];

    public function __construct(private readonly ValidationFactory $validation)
    {
    }

    public function validator(Request $request): Validator
    {
        $validator = $this->validation->make($request->all(), $this->rules(), [], $this->attributes());
        $validator->after(function (Validator $validator): void {
            $input = $validator->getData();
            $this->checkOrdered($validator, $input);
            $this->checkAccount($validator, $input);
        });

        return $validator;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed> the columns to store
     */
    public function columns(array $validated): array
    {
        $account = filled($validated['account_id'] ?? null) ? SalesChannelAccount::find((int) $validated['account_id']) : null;
        $action = RoutingAction::from($validated['action']);

        return [
            'name' => $validated['name'],
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'priority' => (int) $validated['priority'],
            'seller_id' => filled($validated['seller_id'] ?? null) ? (int) $validated['seller_id'] : (int) ($account->seller_id ?? 0),
            'sales_channel' => $validated['sales_channel'] ?? null,
            'account_id' => $account?->id,
            'fulfillment_mode' => $validated['fulfillment_mode'] ?? null,
            'execution_policy' => $validated['execution_policy'] ?? null,
            'product_id' => filled($validated['product_id'] ?? null) ? (int) $validated['product_id'] : null,
            'sku' => $validated['sku'] ?? null,
            'inventory_location_id' => filled($validated['inventory_location_id'] ?? null) ? (int) $validated['inventory_location_id'] : null,
            'product_provider' => $validated['product_provider'] ?? null,
            'country' => filled($validated['country'] ?? null) ? strtoupper($validated['country']) : null,
            'city' => $validated['city'] ?? null,
            'requires_confirmed_stock' => (bool) ($validated['requires_confirmed_stock'] ?? false),
            'conditions' => $this->conditions($validated),
            'action' => $action->value,
            'target_provider' => in_array($action, self::ACTIONS_WITHOUT_TARGET, true) ? null : ($validated['target_provider'] ?? null),
            'target_location_id' => in_array($action, self::ACTIONS_WITHOUT_TARGET, true) || !filled($validated['target_location_id'] ?? null) ? null : (int) $validated['target_location_id'],
            'note' => $validated['note'] ?? null,
            'valid_from' => filled($validated['valid_from'] ?? null) ? CarbonImmutable::parse($validated['valid_from'])->startOfDay() : null,
            'valid_to' => filled($validated['valid_to'] ?? null) ? CarbonImmutable::parse($validated['valid_to'])->endOfDay() : null,
        ];
    }

    /** @param array<string, mixed> $input */
    private function checkOrdered(Validator $validator, array $input): void
    {
        $min = $input['min_order_total'] ?? null;
        $max = $input['max_order_total'] ?? null;
        if (is_numeric($min) && is_numeric($max) && Money::of((string) $max)->compareTo((string) $min) < 0) {
            $validator->errors()->add('max_order_total', translate('max_order_total_below_minimum'));
        }
        $from = $input['valid_from'] ?? null;
        $to = $input['valid_to'] ?? null;
        if (!$validator->errors()->hasAny(['valid_from', 'valid_to']) && filled($from) && filled($to) && CarbonImmutable::parse($to)->lt(CarbonImmutable::parse($from))) {
            $validator->errors()->add('valid_to', translate('valid_to_before_valid_from'));
        }
    }

    /** @param array<string, mixed> $input */
    private function checkAccount(Validator $validator, array $input): void
    {
        $account = filled($input['account_id'] ?? null) ? SalesChannelAccount::find((int) $input['account_id']) : null;
        if ($account === null) {
            return;
        }
        if (filled($input['sales_channel'] ?? null) && $input['sales_channel'] !== $account->channel) {
            $validator->errors()->add('account_id', translate('rule_account_is_of_another_channel'));
        }
        if (filled($input['seller_id'] ?? null) && (int) $input['seller_id'] !== (int) $account->seller_id) {
            $validator->errors()->add('seller_id', translate('rule_seller_differs_from_account'));
        }
    }

    /**
     * The rule as the form shows it (a new rule starts with the defaults).
     *
     * @return array<string, mixed>
     */
    public function values(?FulfillmentRoutingRule $rule): array
    {
        $conditions = (array) ($rule?->conditions ?? []);

        return [
            'name' => $rule?->name,
            'is_active' => $rule?->is_active ?? true,
            'priority' => $rule?->priority ?? 100,
            'seller_id' => $rule?->seller_id,
            'sales_channel' => $rule?->sales_channel,
            'account_id' => $rule?->account_id,
            'fulfillment_mode' => $rule?->fulfillment_mode,
            'execution_policy' => $rule?->execution_policy,
            'product_id' => $rule?->product_id,
            'sku' => $rule?->sku,
            'inventory_location_id' => $rule?->inventory_location_id,
            'product_provider' => $rule?->product_provider,
            'country' => $rule?->country,
            'city' => $rule?->city,
            'requires_confirmed_stock' => (bool) $rule?->requires_confirmed_stock,
            'min_order_total' => isset($conditions['min_order_total']) ? Money::of((string) $conditions['min_order_total'])->toScale(2) : null,
            'max_order_total' => isset($conditions['max_order_total']) ? Money::of((string) $conditions['max_order_total'])->toScale(2) : null,
            'cod' => match (true) {
                !array_key_exists('cod', $conditions) => self::COD_ANY,
                (bool) $conditions['cod'] => self::COD_ONLY,
                default => self::COD_EXCLUDED,
            },
            'action' => $rule?->action,
            'target_provider' => $rule?->target_provider,
            'target_location_id' => $rule?->target_location_id,
            'note' => $rule?->note,
            'valid_from' => $rule?->valid_from?->format('Y-m-d'),
            'valid_to' => $rule?->valid_to?->format('Y-m-d'),
        ];
    }

    /** @return list<string> the fulfilment modes a rule can name: an unknown mode is never routed, so a rule for it could never apply */
    public static function ruleModes(): array
    {
        return array_values(array_map(
            static fn (FulfillmentMode $mode): string => $mode->value,
            array_filter(FulfillmentMode::cases(), static fn (FulfillmentMode $mode): bool => $mode !== FulfillmentMode::Unknown),
        ));
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        $location = Rule::exists('inventory_locations', 'id');
        $actionsWithoutTarget = implode(',', array_map(static fn (RoutingAction $action): string => $action->value, self::ACTIONS_WITHOUT_TARGET));

        return [
            'name' => ['required', 'string', 'max:160'],
            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['required', 'integer', 'min:0', 'max:1000000'],
            'seller_id' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
            'sales_channel' => ['nullable', Rule::in(array_map(static fn (SalesChannel $channel): string => $channel->value, SalesChannel::connectable()))],
            'account_id' => ['nullable', 'integer', Rule::exists('sales_channel_accounts', 'id')],
            'fulfillment_mode' => ['nullable', Rule::in(self::ruleModes())],
            'execution_policy' => ['nullable', Rule::enum(ExecutionPolicy::class)],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'sku' => ['nullable', 'string', 'max:191'],
            'inventory_location_id' => ['nullable', 'integer', $location],
            'product_provider' => ['nullable', Rule::in(ProviderCode::values())],
            'country' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'city' => ['nullable', 'string', 'max:120'],
            'requires_confirmed_stock' => ['sometimes', 'boolean'],
            'min_order_total' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'max_order_total' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'cod' => ['nullable', Rule::in([self::COD_ANY, self::COD_ONLY, self::COD_EXCLUDED])],
            'action' => ['required', Rule::enum(RoutingAction::class)],
            'target_provider' => ['nullable', 'prohibited_if:action,' . $actionsWithoutTarget, Rule::in(ProviderCode::values())],
            'target_location_id' => ['nullable', 'integer', 'prohibited_if:action,' . $actionsWithoutTarget, $location],
            'note' => ['nullable', 'string', 'max:500'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date'],
        ];
    }

    /** @param array<string, mixed> $validated @return array<string, mixed>|null */
    private function conditions(array $validated): ?array
    {
        $conditions = [];
        foreach (['min_order_total', 'max_order_total'] as $bound) {
            if (filled($validated[$bound] ?? null)) {
                $conditions[$bound] = Money::of((string) $validated[$bound])->toStorage();
            }
        }
        $cod = $validated['cod'] ?? self::COD_ANY;
        if ($cod !== self::COD_ANY) {
            $conditions['cod'] = $cod === self::COD_ONLY;
        }

        return $conditions === [] ? null : $conditions;
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return [
            'name' => translate('rule_name'),
            'is_active' => translate('active'),
            'priority' => translate('priority'),
            'seller_id' => translate('seller_id_platform_is_zero'),
            'sales_channel' => translate('channel'),
            'account_id' => translate('account'),
            'fulfillment_mode' => translate('fulfillment_mode'),
            'execution_policy' => translate('execution_policy'),
            'product_id' => translate('product'),
            'sku' => translate('sku'),
            'inventory_location_id' => translate('stock_location'),
            'product_provider' => translate('product_provider'),
            'country' => translate('country_code'),
            'city' => translate('city'),
            'min_order_total' => translate('min_order_total'),
            'max_order_total' => translate('max_order_total'),
            'cod' => translate('cash_on_delivery'),
            'action' => translate('rule_action'),
            'target_provider' => translate('target_provider'),
            'target_location_id' => translate('target_location'),
            'note' => translate('note'),
            'valid_from' => translate('valid_from'),
            'valid_to' => translate('valid_to'),
        ];
    }
}
