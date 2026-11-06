<?php

namespace App\Http\Controllers\Admin\SalesChannels;

use App\Enums\Fulfillment\ProviderCode;
use App\Enums\SalesChannels\ExecutionPolicy;
use App\Enums\SalesChannels\RoutingAction;
use App\Enums\SalesChannels\SalesChannel;
use App\Http\Controllers\Admin\SalesChannels\Support\ProductDirectory;
use App\Http\Controllers\Admin\SalesChannels\Support\ReadsFilters;
use App\Http\Controllers\Admin\SalesChannels\Support\RoutingRuleForm;
use App\Http\Controllers\Admin\SalesChannels\Support\RuleSummary;
use App\Http\Controllers\Controller;
use App\Models\FulfillmentRoutingDecision;
use App\Models\FulfillmentRoutingRule;
use App\Models\InventoryLocation;
use App\Models\SalesChannelAccount;
use App\Services\AuditLogger;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Dynamic routing: the rules an administrator writes, and the decisions the engine took. With no rule an order waits for manual review, and the engine's own invariants come
 * first whatever a rule says. A rule is never deleted, only switched off: the decisions that used it keep the rule as it was.
 */
class ChannelRoutingController extends Controller
{
    use ReadsFilters;

    private const DECISIONS_PER_PAGE = 25;

    private const AUDITED = [
        'name', 'is_active', 'priority', 'seller_id', 'sales_channel', 'account_id', 'fulfillment_mode', 'execution_policy', 'product_id', 'sku', 'inventory_location_id',
        'product_provider', 'country', 'city', 'requires_confirmed_stock', 'conditions', 'action', 'target_provider', 'target_location_id', 'note', 'valid_from', 'valid_to',
    ];

    public function __construct(
        private readonly RoutingRuleForm $form,
        private readonly RuleSummary $summary,
        private readonly ProductDirectory $products,
        private readonly AuditLogger $audit,
        private readonly ChannelLabels $labels,
    ) {
    }

    public function index(Request $request): View
    {
        $rules = $this->inEngineOrder(FulfillmentRoutingRule::all());
        $editing = $request->integer('edit') > 0 ? $rules->firstWhere('id', $request->integer('edit')) : null;
        $accounts = SalesChannelAccount::orderBy('name')->pluck('name', 'id');
        $locations = InventoryLocation::orderBy('name')->pluck('name', 'id');
        $products = $this->products->named($rules->pluck('product_id'));
        $filters = $this->filters();

        return view('admin-views.sales-channels.routing', [
            'labels' => $this->labels,
            'rules' => $rules->map(fn (FulfillmentRoutingRule $rule): array => [
                'rule' => $rule,
                'chips' => $this->summary->chips($rule, $accounts, $locations, $products),
            ])->all(),
            'editing' => $editing,
            'values' => $this->form->values($editing),
            'newValues' => $this->form->values(null),
            'accounts' => $accounts,
            'locations' => $locations,
            'channels' => SalesChannel::connectable(),
            'modes' => RoutingRuleForm::ruleModes(),
            'policies' => ExecutionPolicy::cases(),
            'actions' => RoutingAction::cases(),
            'providers' => ProviderCode::cases(),
            'managedDispatchEnabled' => (bool) config('sales_channels.managed_dispatch_enabled'),
            'filters' => $filters,
            'decisions' => FulfillmentRoutingDecision::query()
                ->when($filters['action'], static fn ($query, string $action) => $query->where('action', $action))
                ->when($filters['channel'], static fn ($query, string $channel) => $query->where('sales_channel', $channel))
                ->orderByDesc('id')
                ->paginate(self::DECISIONS_PER_PAGE)
                ->withQueryString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validator = $this->form->validator($request);
        if ($validator->fails()) {
            return redirect()->route('admin.sales-channels.routing.index')->withErrors($validator, 'rule_new')->withInput();
        }

        $rule = FulfillmentRoutingRule::create($this->form->columns($validator->validated()) + ['created_by' => auth('admin')->id(), 'updated_by' => auth('admin')->id()]);
        $this->audit->record(action: 'sales_channel.routing_rule_created', subject: $rule, after: $rule->only(self::AUDITED), context: ['by' => auth('admin')->id()]);
        ToastMagic::success(translate('routing_rule_created'));

        return redirect()->route('admin.sales-channels.routing.index');
    }

    public function update(Request $request, FulfillmentRoutingRule $rule): RedirectResponse
    {
        $validator = $this->form->validator($request);
        if ($validator->fails()) {
            return redirect()->route('admin.sales-channels.routing.index', ['edit' => $rule->id])->withErrors($validator, 'rule_edit')->withInput();
        }

        $before = $rule->only(self::AUDITED);
        $rule->forceFill($this->form->columns($validator->validated()) + ['updated_by' => auth('admin')->id()])->save();
        $this->audit->record(action: 'sales_channel.routing_rule_updated', subject: $rule, before: $before, after: $rule->only(self::AUDITED), context: ['by' => auth('admin')->id()]);
        ToastMagic::success(translate('routing_rule_saved'));

        return redirect()->route('admin.sales-channels.routing.index');
    }

    /** A rule is switched off, never deleted. */
    public function toggle(FulfillmentRoutingRule $rule): RedirectResponse
    {
        $rule->forceFill(['is_active' => !$rule->is_active, 'updated_by' => auth('admin')->id()])->save();
        $this->audit->record(action: $rule->is_active ? 'sales_channel.routing_rule_activated' : 'sales_channel.routing_rule_deactivated', subject: $rule, context: ['by' => auth('admin')->id()]);
        ToastMagic::success(translate($rule->is_active ? 'routing_rule_activated' : 'routing_rule_deactivated'));

        return redirect()->route('admin.sales-channels.routing.index');
    }

    /**
     * The order the engine tries rules in: lowest priority number first, the more specific rule first among equals, the older first among those. Rules of different sellers
     * never compete (the engine reads only the order's own seller's rules), so they are listed seller by seller.
     *
     * @param Collection<int, FulfillmentRoutingRule> $rules
     * @return Collection<int, FulfillmentRoutingRule>
     */
    private function inEngineOrder(Collection $rules): Collection
    {
        return $rules->sort(static fn (FulfillmentRoutingRule $a, FulfillmentRoutingRule $b): int => [$a->seller_id, $a->priority, $b->specificity(), $a->id] <=> [$b->seller_id, $b->priority, $a->specificity(), $b->id])->values();
    }

    /** @return array{action: string|null, channel: string|null} */
    private function filters(): array
    {
        return [
            'action' => $this->oneOf('action', array_map(static fn (RoutingAction $action): string => $action->value, RoutingAction::cases())),
            'channel' => $this->oneOf('channel', array_map(static fn (SalesChannel $channel): string => $channel->value, SalesChannel::connectable())),
        ];
    }
}
