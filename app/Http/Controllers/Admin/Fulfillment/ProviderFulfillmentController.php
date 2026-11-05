<?php

namespace App\Http\Controllers\Admin\Fulfillment;

use App\Services\Fulfillment\Support\SafeToast;
use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Http\Controllers\Controller;
use App\Models\ProviderApiLog;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Contracts\ProvidesShippingLabel;
use App\Services\Fulfillment\Data\CancelLease;
use App\Services\Fulfillment\Data\CancellationResult;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\FulfillmentCancellation;
use App\Services\Fulfillment\OperatorActions;
use App\Services\Fulfillment\ProviderRegistry;
use App\Services\Fulfillment\ShipmentTracker;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/** Every provider fulfillment in one place, with the recovery controls an operator needs. */
class ProviderFulfillmentController extends Controller
{
    private const ACTIONS = ['release', 'confirm-not-created', 'link', 'mark-delivered', 'mark-cancelled', 'cancel', 'redispatch', 'refresh'];

    /** Actions that assert something a person checked: they must say what and why. */
    private const NEEDS_REASON = ['confirm-not-created', 'link', 'mark-delivered', 'mark-cancelled', 'redispatch'];

    public function __construct(
        private readonly OperatorActions $actions,
        private readonly ProviderRegistry $registry,
        private readonly ShipmentTracker $tracker,
    ) {
    }

    public function index(Request $request): View
    {
        $query = ProviderFulfillment::with('items')
            ->when($request->filled('provider'), static fn ($builder) => $builder->where('provider', $request->query('provider')))
            ->when($request->filled('status'), static fn ($builder) => $builder->where('status', $request->query('status')))
            ->when($request->boolean('attention'), static fn ($builder) => $builder->where(static fn ($attention) => $attention
                ->whereIn('status', [
                    FulfillmentStatus::Held->value, FulfillmentStatus::Failed->value, FulfillmentStatus::ReconcilePending->value,
                    FulfillmentStatus::NeedsAttention->value, FulfillmentStatus::DeliveryFailed->value, FulfillmentStatus::DeliveryPostponed->value,
                ])
                ->orWhere(static fn ($blockedCancel) => $blockedCancel
                    ->whereNotNull('cancel_blocked_reason')
                    ->whereIn('status', FulfillmentStatus::activeValues()))))
            ->when($request->filled('q'), static fn ($builder) => $builder->where(static fn ($inner) => $inner
                ->where('reference', 'like', '%' . $request->query('q') . '%')->orWhere('order_id', $request->query('q'))->orWhere('tracking_number', 'like', '%' . $request->query('q') . '%')));

        return view('admin-views.fulfillment.fulfillments', [
            'fulfillments' => $query->orderByDesc('id')->paginate(25)->withQueryString(),
            'filters' => $request->only(['provider', 'status', 'q', 'attention']),
            'statuses' => FulfillmentStatus::cases(),
            'providers' => ProviderCode::cases(),
        ]);
    }

    public function show(int $id): View
    {
        $fulfillment = ProviderFulfillment::with(['items', 'events' => static fn ($query) => $query->latest('id')->limit(50)])->findOrFail($id);

        return view('admin-views.fulfillment.fulfillment-show', [
            'fulfillment' => $fulfillment,
            'logs' => ProviderApiLog::where('provider_fulfillment_id', $id)->latest('id')->limit(20)->get(),
            'canLabel' => $this->registry->adapter($fulfillment->provider) instanceof ProvidesShippingLabel && $fulfillment->provider_secondary_reference !== null,
        ]);
    }

    public function action(Request $request, int $id, string $action): RedirectResponse
    {
        abort_unless(in_array($action, self::ACTIONS, true), 404);

        $validated = $request->validate([
            'reason' => [in_array($action, self::NEEDS_REASON, true) ? 'required' : 'nullable', 'string', 'max:255'],
            'reference' => [$action === 'link' ? 'required' : 'nullable', 'string', 'max:120'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
        ]);
        $reason = (string) ($validated['reason'] ?? '');

        try {
            match ($action) {
                'release' => $this->actions->release($id),
                'confirm-not-created' => $this->actions->confirmNotCreated($id, $reason),
                'link' => $this->actions->linkReference($id, (string) $validated['reference'], $validated['tracking_number'] ?? null, $reason),
                'mark-delivered' => $this->actions->markDelivered($id, $reason),
                'mark-cancelled' => $this->actions->markCancelled($id, $reason),
                'cancel' => $this->announceCancellation($this->actions->requestCancel($id)),
                'redispatch' => $this->actions->redispatchAsNewGeneration($id, $reason),
                'refresh' => $this->refresh($id),
            };
            if ($action !== 'cancel') {
                ToastMagic::success(translate('action_done'));
            }
        } catch (InvalidArgumentException|ProviderUnavailableException $exception) {
            SafeToast::error('', $exception->getMessage());
        }

        return back();
    }

    /** Say what a cancel request really did: "requested" is not "cancelled", and some providers cannot cancel at all. */
    private function announceCancellation(string $outcome): void
    {
        [$tone, $key] = match ($outcome) {
            FulfillmentCancellation::CANCELLED_LOCALLY => ['success', 'cancel_result_cancelled_locally'],
            CancellationResult::REQUESTED => ['success', 'cancel_result_requested'],
            FulfillmentCancellation::DEFERRED => ['info', 'cancel_result_deferred'],
            OperatorActions::CANCEL_QUEUED => ['info', 'cancel_result_queued'],
            CancelLease::ALREADY_REQUESTED => ['info', 'cancel_result_already_requested'],
            CancelLease::WAITING => ['info', 'cancel_result_waiting'],
            CancellationResult::FAILED, FulfillmentCancellation::UNAVAILABLE => ['warning', 'cancel_result_retry'],
            CancellationResult::UNSUPPORTED, CancellationResult::REFUSED, CancelLease::BLOCKED => ['warning', 'cancel_result_manual'],
            default => ['info', 'action_done'],
        };

        ToastMagic::{$tone}(translate($key));
    }

    /** Download the air waybill. The label URL is fetched from the provider on demand, never stored. */
    public function label(int $id): RedirectResponse
    {
        $fulfillment = ProviderFulfillment::findOrFail($id);
        $adapter = $this->registry->adapter($fulfillment->provider);

        try {
            $url = $adapter instanceof ProvidesShippingLabel ? $adapter->labelUrl($fulfillment) : null;
        } catch (ProviderUnavailableException $exception) {
            SafeToast::warning('', $exception->getMessage());

            return back();
        }

        if ($url === null) {
            ToastMagic::warning(translate('label_not_available_yet'));

            return back();
        }

        return redirect()->away($url);
    }

    public function logs(Request $request): View
    {
        $logs = ProviderApiLog::query()
            ->when($request->filled('provider'), static fn ($query) => $query->where('provider', $request->query('provider')))
            ->when($request->filled('operation'), static fn ($query) => $query->where('operation', 'like', $request->query('operation') . '%'))
            ->when($request->query('result') === 'failed', static fn ($query) => $query->where('success', false))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        return view('admin-views.fulfillment.logs', ['logs' => $logs, 'filters' => $request->only(['provider', 'operation', 'result']), 'providers' => ProviderCode::cases()]);
    }

    private function refresh(int $id): void
    {
        $fulfillment = ProviderFulfillment::findOrFail($id);
        $adapter = $this->registry->adapter($fulfillment->provider);
        $snapshot = $adapter->fetchStatus($fulfillment);
        $status = $snapshot === null ? null : $adapter->mapStatus($snapshot->providerStatus);

        if ($snapshot === null || $status === null) {
            throw new InvalidArgumentException(translate('provider_status_not_available'));
        }

        $this->tracker->applyProviderStatus($id, $status, $snapshot->observedAt, 'poll', $snapshot->providerStatus);
    }
}
