<?php

namespace App\Http\Controllers\Admin\SalesChannels;

use App\Enums\Fulfillment\ProviderCode;
use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\ExecutionPolicy;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Enums\SalesChannels\SalesChannel;
use App\Http\Controllers\Admin\SalesChannels\Support\ProductDirectory;
use App\Http\Controllers\Admin\SalesChannels\Support\ReadsFilters;
use App\Http\Controllers\Admin\SalesChannels\Support\RunAnnouncer;
use App\Http\Controllers\Admin\SalesChannels\Support\WriteState;
use App\Http\Controllers\Controller;
use App\Models\ExternalOrderMapping;
use App\Models\FulfillmentRoutingDecision;
use App\Models\InventoryLocation;
use App\Models\SalesChannelAccount;
use App\Services\Fulfillment\Support\SafeToast;
use App\Services\SalesChannels\Routing\DynamicFulfillmentRoutingEngine;
use App\Services\SalesChannels\Shipments\ChannelShipmentNotifier;
use App\Services\SalesChannels\Shipments\ExternalShipmentLinker;
use App\Services\SalesChannels\Shipments\ShipmentLinkRefused;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Orders as the channels know them, in one list, and what the application is doing about each: its routing decisions (with the reason in words), the shipment it follows,
 * and whether the channel has been told. Buyer personal data is never stored, so none can be shown; only the city and the country appear.
 */
class ChannelOrderController extends Controller
{
    use ReadsFilters;

    /** The states a shipment link can be in, and `undecided` for an order nobody has decided about yet. */
    public const LINK_FILTERS = [
        ExternalOrderMapping::LINK_UNLINKED,
        ExternalOrderMapping::LINK_LINKED,
        ExternalOrderMapping::LINK_NEEDS_REVIEW,
        ExternalOrderMapping::LINK_CONFLICT,
        ExternalOrderMapping::LINK_NOT_APPLICABLE,
        'undecided',
    ];

    /** The states in which a link can be taken back: a link that is held, or one that waits for a person. */
    private const UNLINKABLE = [ExternalOrderMapping::LINK_LINKED, ExternalOrderMapping::LINK_NEEDS_REVIEW, ExternalOrderMapping::LINK_CONFLICT];

    private const PER_PAGE = 25;

    public function __construct(
        private readonly DynamicFulfillmentRoutingEngine $routing,
        private readonly ExternalShipmentLinker $linker,
        private readonly ChannelShipmentNotifier $notifier,
        private readonly ProductDirectory $products,
        private readonly RunAnnouncer $announcer,
        private readonly ChannelLabels $labels,
    ) {
    }

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $orders = ExternalOrderMapping::with('account')
            ->when($filters['channel'], static fn ($query, string $channel) => $query->where('sales_channel', $channel))
            ->when($filters['account'], static fn ($query, int $account) => $query->where('account_id', $account))
            ->when($filters['status'], static fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['mode'], static fn ($query, string $mode) => $mode === FulfillmentMode::Unknown->value
                ? $query->where(static fn ($inner) => $inner->where('fulfillment_mode', $mode)->orWhereNull('fulfillment_mode'))
                : $query->where('fulfillment_mode', $mode))
            ->when($filters['policy'], static fn ($query, string $policy) => $query->where('execution_policy', $policy))
            ->when($filters['review'], static fn ($query) => $query->whereNotNull('review_reason'))
            ->when($filters['link'], static fn ($query, string $link) => $link === 'undecided' ? $query->whereNull('shipment_link_state') : $query->where('shipment_link_state', $link))
            ->when($filters['from'], static fn ($query, string $from) => $query->whereDate('purchased_at', '>=', $from))
            ->when($filters['to'], static fn ($query, string $to) => $query->whereDate('purchased_at', '<=', $to))
            ->when($filters['q'], static fn ($query, string $term) => $query->where(static fn ($inner) => $inner
                ->where('external_order_id', 'like', "%{$term}%")->orWhere('external_package_id', 'like', "%{$term}%")
                ->orWhere('tracking_number', 'like', "%{$term}%")->orWhere('shipment_reference', 'like', "%{$term}%")))
            ->orderByDesc('purchased_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin-views.sales-channels.orders', [
            'labels' => $this->labels,
            'orders' => $orders,
            'filters' => $filters,
            'channels' => SalesChannel::connectable(),
            'accounts' => SalesChannelAccount::orderBy('name')->get(['id', 'name', 'channel']),
            'statuses' => ChannelOrderStatus::cases(),
            'modes' => FulfillmentMode::cases(),
            'policies' => ExecutionPolicy::cases(),
            'linkStates' => self::LINK_FILTERS,
        ]);
    }

    public function show(ExternalOrderMapping $mapping): View
    {
        $mapping->load(['account', 'lines']);
        $decisions = FulfillmentRoutingDecision::where('external_order_mapping_id', $mapping->id)->orderByDesc('version')->get();

        return view('admin-views.sales-channels.order', [
            'labels' => $this->labels,
            'mapping' => $mapping,
            'account' => $mapping->account,
            'writeState' => WriteState::of($mapping->account),
            'decisions' => $decisions,
            'products' => $this->products->named($mapping->lines->pluck('product_id')),
            'locations' => InventoryLocation::whereIn('id', $mapping->lines->pluck('inventory_location_id')->filter()->unique())->pluck('name', 'id'),
            'providers' => ProviderCode::cases(),
            'cod' => $mapping->snapshot['is_cod'] ?? null,
            'destination' => (array) ($mapping->snapshot['destination'] ?? []),
            'notified' => $mapping->snapshot['channel_notified'] ?? null,
            'notifyError' => $mapping->snapshot['channel_notify_error'] ?? null,
            'canUnlink' => in_array($mapping->shipment_link_state, self::UNLINKABLE, true),
        ]);
    }

    /** Decides again now, with the rules as they are. It records a decision (and what it applies to the order); it sends nothing to a channel or a provider. */
    public function route(ExternalOrderMapping $mapping): RedirectResponse
    {
        $decision = $this->routing->route($mapping, 'admin', auth('admin')->id());
        if ($decision === null) {
            SafeToast::info(translate('order_already_dispatched_decision_stands'));

            return back();
        }

        $what = $this->labels->routingAction($decision->action) . ' · ' . $this->labels->orderReason($decision->reason_code);
        $decision->wasRecentlyCreated
            ? SafeToast::success(translate('routing_decision_recorded'), $what)
            : SafeToast::info(translate('routing_decision_unchanged'), $what);

        return back();
    }

    public function linkShipment(Request $request, ExternalOrderMapping $mapping): RedirectResponse
    {
        $validated = $request->validateWithBag('link_shipment', [
            'provider' => ['required', Rule::in(ProviderCode::values())],
            'reference' => ['required', 'string', 'max:120'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'carrier' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $this->linker->link($mapping, $validated['provider'], $validated['reference'], auth('admin')->id(), $validated['tracking_number'] ?? null, $validated['carrier'] ?? null);
        } catch (ShipmentLinkRefused $refused) {
            SafeToast::error('', $refused->getMessage());

            return back();
        }
        ToastMagic::success(translate('shipment_linked'));

        return back();
    }

    public function unlinkShipment(Request $request, ExternalOrderMapping $mapping): RedirectResponse
    {
        $reason = $request->validateWithBag('unlink_shipment', ['reason' => ['required', 'string', 'min:5', 'max:255']])['reason'];
        if (!in_array($mapping->shipment_link_state, self::UNLINKABLE, true)) {
            SafeToast::warning(translate('no_shipment_link_to_take_back'));

            return back();
        }

        $this->linker->unlink($mapping, auth('admin')->id(), $reason);
        ToastMagic::success(translate('shipment_unlinked'));

        return back();
    }

    /** Tells the channel the linked shipment's tracking number. A write: it needs the dispatch approval, and a real send needs the administrator's confirmation. */
    public function notify(Request $request, ExternalOrderMapping $mapping): RedirectResponse
    {
        if (WriteState::of($mapping->account)->sendsForReal()) {
            $request->validateWithBag('notify', ['confirm_live' => ['accepted']], ['confirm_live.accepted' => translate('confirm_that_this_is_sent_for_real')]);
        }

        $result = $this->notifier->notify($mapping);
        $this->announcer->announceNotification($result, $result['outcome'] === ChannelShipmentNotifier::FAILED ? ($mapping->fresh()->snapshot['channel_notify_error']['error'] ?? null) : null);

        return back();
    }

    /**
     * @return array{channel: string|null, account: int|null, status: string|null, mode: string|null, policy: string|null, review: bool, link: string|null, from: string|null, to: string|null, q: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'channel' => $this->oneOf('channel', array_map(static fn (SalesChannel $channel): string => $channel->value, SalesChannel::connectable())),
            'account' => $request->integer('account') ?: null,
            'status' => $this->oneOf('status', array_map(static fn (ChannelOrderStatus $status): string => $status->value, ChannelOrderStatus::cases())),
            'mode' => $this->oneOf('mode', array_map(static fn (FulfillmentMode $mode): string => $mode->value, FulfillmentMode::cases())),
            'policy' => $this->oneOf('policy', array_map(static fn (ExecutionPolicy $policy): string => $policy->value, ExecutionPolicy::cases())),
            'review' => $request->boolean('review'),
            'link' => $this->oneOf('link', self::LINK_FILTERS),
            'from' => $this->date('from'),
            'to' => $this->date('to'),
            'q' => trim(requestString('q')) ?: null,
        ];
    }
}
