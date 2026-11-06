<?php

namespace App\Http\Controllers\Admin\SalesChannels;

use App\Enums\SalesChannels\SalesChannel;
use App\Http\Controllers\Admin\SalesChannels\Support\RunAnnouncer;
use App\Http\Controllers\Admin\SalesChannels\Support\WriteState;
use App\Http\Controllers\Controller;
use App\Models\SalesChannelAccount;
use App\Services\Fulfillment\Support\SafeToast;
use App\Services\SalesChannels\ChannelConnectionTester;
use App\Services\SalesChannels\Publishing\StockPricePublisher;
use App\Services\SalesChannels\Shipments\ChannelShipmentNotifier;
use App\Services\SalesChannels\Sync\ChannelSyncService;
use App\Services\SalesChannels\Trendyol\TrendyolWebhookManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

/**
 * Everything on an account page that calls the channel: the connection test, a sync, a publish, the webhook. Each is a POST behind its own rate limit.
 *
 * Nothing here decides whether a call may be made: the transport and ChannelAccountService do, and a refusal is reported as what it is (nothing was sent), never as done.
 * A call that would change something at the channel for real needs the administrator's explicit confirmation as well; a rehearsal (dry run) needs none and sends nothing.
 */
class ChannelAccountOperationsController extends Controller
{
    public const PUBLISH_KINDS = ['stock', 'price', 'settle', 'shipments'];

    /** The webhook details worth showing; whatever else the channel returns is dropped. */
    private const WEBHOOK_FIELDS = ['id', 'url', 'status', 'authenticationType', 'subscribedStatuses', 'countryCodes'];

    public function __construct(
        private readonly ChannelConnectionTester $tester,
        private readonly ChannelSyncService $sync,
        private readonly StockPricePublisher $publisher,
        private readonly ChannelShipmentNotifier $notifier,
        private readonly TrendyolWebhookManager $webhooks,
        private readonly RunAnnouncer $announcer,
    ) {
    }

    /** A read-only test. It never switches anything on, however well it goes. */
    public function test(SalesChannelAccount $account): RedirectResponse
    {
        $result = $this->tester->test($account);
        match (true) {
            !$result->ok() => SafeToast::error(translate('connection_test_failed'), $result->message),
            $result->missing !== [] => SafeToast::warning(translate('connection_test_passed_with_gaps'), $result->message),
            default => SafeToast::success(translate('connection_test_passed'), $result->message),
        };

        return $this->toAccount($account);
    }

    public function sync(SalesChannelAccount $account, string $resource): RedirectResponse
    {
        $this->announcer->announce(match ($resource) {
            ChannelSyncService::ORDERS => $this->sync->syncOrders($account),
            ChannelSyncService::RETURNS => $this->sync->syncReturns($account),
            ChannelSyncService::LISTINGS => $this->sync->syncListings($account),
            ChannelSyncService::INVENTORY => $this->sync->syncChannelInventory($account),
            ChannelSyncService::FINANCES => $this->sync->syncFinances($account),
        });

        return $this->toAccount($account);
    }

    public function publish(Request $request, SalesChannelAccount $account, string $kind): RedirectResponse
    {
        $state = WriteState::of($account);
        if ($kind !== 'settle') {
            $this->requireConfirmationOfRealWrite($request, $state, 'publish_' . $kind);
        }

        match ($kind) {
            'stock' => $this->announcer->announce($this->publisher->publishStock($account)),
            'price' => $this->announcer->announce($this->publisher->publishPrices($account)),
            'settle' => $this->settle($account),
            'shipments' => $this->announcer->announceShipments($this->notifier->notifyDue($account), $state),
        };

        return $this->toAccount($account);
    }

    /** Registers the order-package webhook at Trendyol, at the address the application serves it on. A write: it needs its own approval. */
    public function webhook(Request $request, SalesChannelAccount $account): RedirectResponse
    {
        abort_unless($account->channel === SalesChannel::Trendyol->value, 404);
        $this->requireConfirmationOfRealWrite($request, WriteState::of($account), 'webhook');
        $statuses = $request->validateWithBag('webhook', [
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', Rule::in(TrendyolWebhookManager::STATUSES)],
        ])['statuses'] ?? [];
        $address = route('sales_channels.webhooks.receive', $account->id);

        try {
            $registered = $this->webhooks->register($account, $address, $statuses);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            SafeToast::error('', $exception->getMessage());

            return $this->toAccount($account);
        }

        $registered === null
            ? SafeToast::info(translate('publish_rehearsed_nothing_sent'), $address)
            : SafeToast::success(translate('webhook_registered'), $registered);

        return $this->toAccount($account);
    }

    /** Asks Trendyol which webhooks it holds. Only when the button is pressed: the account page never calls a channel by being opened. */
    public function listWebhooks(SalesChannelAccount $account): RedirectResponse
    {
        abort_unless($account->channel === SalesChannel::Trendyol->value, 404);

        try {
            $held = $this->webhooks->list($account);
        } catch (RuntimeException $exception) {
            SafeToast::error(translate('webhook_list_failed'), $exception->getMessage());

            return $this->toAccount($account);
        }

        return $this->toAccount($account)->with('loaded_webhooks', array_map(fn (array $webhook): array => $this->shown($webhook), $held));
    }

    private function settle(SalesChannelAccount $account): void
    {
        try {
            $this->announcer->announceSettled($this->publisher->settle($account));
        } catch (RuntimeException $exception) {
            SafeToast::error(translate('run_failed_explained'), $exception->getMessage());
        }
    }

    /** What really sends needs a tick in the box next to the button; a rehearsal and a refused call send nothing and need none. */
    private function requireConfirmationOfRealWrite(Request $request, WriteState $state, string $bag): void
    {
        if ($state->sendsForReal()) {
            $request->validateWithBag($bag, ['confirm_live' => ['accepted']], ['confirm_live.accepted' => translate('confirm_that_this_is_sent_for_real')]);
        }
    }

    /**
     * One webhook as the page shows it: the fields worth showing, each as plain text.
     *
     * @param array<string, mixed> $webhook
     * @return array<string, string>
     */
    private function shown(array $webhook): array
    {
        return array_map(
            static fn (mixed $value): string => is_array($value) ? implode(', ', array_map('strval', Arr::flatten($value))) : (string) $value,
            Arr::only($webhook, self::WEBHOOK_FIELDS),
        );
    }

    private function toAccount(SalesChannelAccount $account): RedirectResponse
    {
        return redirect()->route('admin.sales-channels.accounts.show', $account);
    }
}
