<?php

namespace App\Http\Controllers\Admin\SalesChannels;

use App\Enums\SalesChannels\SalesChannel;
use App\Http\Controllers\Admin\SalesChannels\Support\RunAnnouncer;
use App\Http\Controllers\Admin\SalesChannels\Support\WriteState;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use App\Models\SalesChannelAccount;
use App\Services\Fulfillment\Support\SafeToast;
use App\Services\SalesChannels\ChannelConnectionTester;
use App\Services\SalesChannels\Publishing\StockPricePublisher;
use App\Services\SalesChannels\Shipments\ChannelShipmentNotifier;
use App\Services\SalesChannels\Sync\ChannelSyncService;
use App\Services\SalesChannels\Amazon\AmazonFinanceGroups;
use App\Services\SalesChannels\Noon\NoonProductGroups;
use App\Services\SalesChannels\Trendyol\TrendyolLookups;
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

    /**
     * The reads a person can ask a channel for, and which channel answers each.
     *
     * **These are reads and nothing else.** Each one existed, tested, with no caller: the code could answer but nobody
     * could ask. They are gathered behind one action because they share every rule that matters -- the channel must match,
     * the request leaves only when a button is pressed, the answer is a bounded sample rather than a dump, and a channel
     * that will not tell us says so instead of being guessed at.
     *
     * What is deliberately NOT here: `NoonProductGroups::replaceMembership()`, `delete()` and `mapSkus()` with unmapping
     * allowed. noon's own documentation is explicit that the membership list you send REPLACES the current one, so any of
     * those three can remove products from a live group in one request. They stay out of reach of a screen until there is a
     * form that shows what would be removed and asks for it by name; `addMembers()` is the additive one and is offered.
     */
    public const LOOKUPS = [
        'carrier-codes' => SalesChannel::Trendyol,
        'seller-addresses' => SalesChannel::Trendyol,
        'attribute-values' => SalesChannel::Trendyol,
        'price-and-stock' => SalesChannel::Trendyol,
        'product-group' => SalesChannel::Noon,
        'finance-groups' => SalesChannel::Amazon,
    ];

    /** A sample is a sample: enough to decide with, never the whole catalogue in a flash bag. */
    private const MOST_ROWS_SHOWN = 50;

    /** The webhook details worth showing; whatever else the channel returns is dropped. */
    private const WEBHOOK_FIELDS = ['id', 'url', 'status', 'authenticationType', 'subscribedStatuses', 'countryCodes'];

    public function __construct(
        private readonly ChannelConnectionTester $tester,
        private readonly ChannelSyncService $sync,
        private readonly StockPricePublisher $publisher,
        private readonly ChannelShipmentNotifier $notifier,
        private readonly TrendyolWebhookManager $webhooks,
        private readonly TrendyolLookups $lookups,
        private readonly NoonProductGroups $groups,
        private readonly AmazonFinanceGroups $financeGroups,
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
        $this->announcer->announce($this->sync->runResource($account, $resource));

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

    /**
     * Ask the channel one read-only question. Nothing is written and nothing is published.
     *
     * The answer is flashed as rows of plain strings, like `listWebhooks` does, so the account page can show it without
     * holding a channel's object graph in the session.
     */
    public function lookup(Request $request, SalesChannelAccount $account, string $kind): RedirectResponse
    {
        abort_unless(array_key_exists($kind, self::LOOKUPS), 404);
        abort_unless($account->channel === self::LOOKUPS[$kind]->value, 404);

        try {
            [$rows, $problem, $note] = $this->askTheChannel($request, $account, $kind);
        } catch (RuntimeException $exception) {
            SafeToast::error(translate('channel_lookup_failed'), $exception->getMessage());

            return $this->toAccount($account);
        }

        if ($problem !== null) {
            // The channel refused, or could not be asked. That is an answer and it is shown as one: a lookup that failed
            // must never leave the page looking as though the list it shows is complete.
            SafeToast::error(translate('channel_lookup_failed'), $problem);

            return $this->toAccount($account);
        }

        return $this->toAccount($account)->with('lookup', ['kind' => $kind, 'rows' => array_slice($rows, 0, self::MOST_ROWS_SHOWN), 'shown' => min(count($rows), self::MOST_ROWS_SHOWN), 'total' => count($rows), 'note' => $note]);
    }

    /**
     * Add products to a noon group, and only add.
     *
     * noon offers no way to add one member: the list you send replaces the group, so `addMembers()` READS the group first
     * and sends what is there plus what is new, and refuses rather than guessing if the read did not finish. That is why
     * this is a write with the write gate and the live-send tick on it, even though a person is only adding.
     */
    public function addGroupMembers(Request $request, SalesChannelAccount $account): RedirectResponse
    {
        abort_unless($account->channel === SalesChannel::Noon->value, 404);

        $validated = $request->validate([
            'group_ref' => ['required', 'string', 'max:120'],
            'known_sku_parent' => ['required', 'string', 'max:120'],
            'sku_parents' => ['required', 'array', 'min:1', 'max:200'],
            'sku_parents.*' => ['string', 'max:120'],
        ]);

        $state = WriteState::of($account);
        $this->requireConfirmationOfRealWrite($request, $state, 'group_members');

        $result = $this->groups->addMembers($account, $validated['group_ref'], $validated['known_sku_parent'], array_values(array_unique($validated['sku_parents'])));

        $result['ok']
            ? SafeToast::success(translate('group_members_added'), (string) $result['added'])
            : SafeToast::error(translate('group_members_not_added'), (string) ($result['refusal'] ?? ''));

        return $this->toAccount($account);
    }

    /**
     * @return array{0: list<array<string, string>>, 1: string|null, 2: string|null} the rows, the channel's refusal, a note
     */
    private function askTheChannel(Request $request, SalesChannelAccount $account, string $kind): array
    {
        if ($kind === 'carrier-codes') {
            $answer = $this->lookups->carrierCodes($account);

            return [$this->pairs((array) ($answer['providers'] ?? [])), $answer['problem'], null];
        }

        if ($kind === 'seller-addresses') {
            $answer = $this->lookups->sellerAddresses($account);
            $rows = array_map(static fn (array $address): array => array_map(static fn (mixed $value): string => is_bool($value) ? ($value ? '1' : '0') : (string) $value, $address), (array) ($answer['addresses'] ?? []));

            return [array_values($rows), $answer['problem'], 'default_shipment_id:' . (string) ($answer['default_shipment_id'] ?? '')];
        }

        if ($kind === 'attribute-values') {
            $validated = $request->validate(['category_id' => ['required', 'integer', 'min:1'], 'attribute_id' => ['required', 'integer', 'min:1']]);
            $answer = $this->lookups->attributeValues($account, (int) $validated['category_id'], (int) $validated['attribute_id']);

            return [$this->pairs((array) ($answer['values'] ?? [])), $answer['problem'], 'pages_read:' . (string) $answer['pages_read']];
        }

        if ($kind === 'price-and-stock') {
            $validated = $request->validate(['barcodes' => ['required', 'array', 'min:1', 'max:100'], 'barcodes.*' => ['string', 'max:120']]);
            $answer = $this->lookups->approvedPriceAndStock($account, array_values(array_unique($validated['barcodes'])));
            $rows = [];
            foreach ((array) ($answer['found'] ?? []) as $barcode => $figures) {
                $rows[] = ['barcode' => (string) $barcode, 'sale_price' => (string) $figures['sale_price'], 'quantity' => (string) $figures['quantity']];
            }

            // Trendyol answers for APPROVED products only, so a barcode missing from the answer is not a barcode Trendyol
            // lacks. The note says so rather than letting a short list read as an absence.
            return [$rows, $answer['problem'], 'approved_only'];
        }

        if ($kind === 'finance-groups') {
            // Amazon's own limits are the class's to enforce and it reports them as a `problem`: a window wider than 180
            // days answers with zero groups and a clean 200, and both ends must be older than two minutes. Nothing is
            // assumed here beyond the dates being dates.
            $validated = $request->validate(['after' => ['required', 'date'], 'before' => ['required', 'date', 'after:after']]);
            $answer = $this->financeGroups->opened($account, CarbonImmutable::parse($validated['after']), CarbonImmutable::parse($validated['before']));
            $rows = array_map(static fn (array $group): array => [
                'id' => (string) $group['id'],
                'status' => (string) $group['status'],
                'transfer_status' => (string) $group['transfer_status'],
                'transferred_at' => (string) $group['transferred_at'],
            ], (array) ($answer['groups'] ?? []));

            return [$rows, $answer['problem'], $answer['next_token'] === null ? null : 'more_pages'];
        }

        $validated = $request->validate(['group_ref' => ['required', 'string', 'max:120'], 'known_sku_parent' => ['required', 'string', 'max:120']]);
        $answer = $this->groups->read($account, $validated['group_ref'], $validated['known_sku_parent']);
        $rows = array_map(static fn (string $sku): array => ['sku_parent' => $sku], array_keys((array) $answer['members']));

        return [$rows, $answer['problem'], 'attributes:' . implode(',', $answer['attribute_codes'])];
    }

    /** @param array<array-key, string> $map @return list<array<string, string>> */
    private function pairs(array $map): array
    {
        $rows = [];
        foreach ($map as $code => $name) {
            $rows[] = ['code' => (string) $code, 'name' => (string) $name];
        }

        return $rows;
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
