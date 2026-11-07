<?php

namespace App\Http\Controllers\Admin\SalesChannels;

use App\Enums\SalesChannels\IntegrationCapability;
use App\Enums\SalesChannels\SalesChannel;
use App\Http\Controllers\Admin\SalesChannels\Support\AccountForm;
use App\Http\Controllers\Admin\SalesChannels\Support\CapabilityLadder;
use App\Http\Controllers\Admin\SalesChannels\Support\SyncResources;
use App\Http\Controllers\Admin\SalesChannels\Support\WriteState;
use App\Http\Controllers\Controller;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\Fulfillment\Support\SafeToast;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\ChannelConnectorRegistry;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Trendyol\TrendyolWebhookManager;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Channel accounts: creating one, editing its settings and secrets, and moving it up the activation ladder one deliberate step at a time.
 *
 * Every rule about what may be switched on lives in ChannelAccountService; a refusal it gives is shown as it is worded. A new account is disabled, may not write, is in
 * rehearsal (dry run) and holds no capability. Secrets are write-only: the page says which are set, never what they are, and a rejected form is sent back without them.
 */
class ChannelAccountController extends Controller
{
    public const SWITCH_ACTIONS = ['enable', 'disable'];
    public const APPROVAL_ACTIONS = ['approve', 'revoke'];

    public function __construct(
        private readonly ChannelAccountService $accounts,
        private readonly ChannelCredentialStore $credentials,
        private readonly ChannelConnectorRegistry $connectors,
        private readonly CapabilityLadder $ladder,
        private readonly SyncResources $syncResources,
        private readonly AccountForm $form,
        private readonly ChannelLabels $labels,
    ) {
    }

    public function create(Request $request): View
    {
        $channel = SalesChannel::tryFrom((string) $request->query('channel'));
        $channel = $channel?->isExternal() ? $channel : null;

        return view('admin-views.sales-channels.account-create', [
            'labels' => $this->labels,
            'channel' => $channel,
            'channels' => SalesChannel::connectable(),
            'settings' => $channel === null ? [] : $this->form->settingsFor($channel, null),
            'mayDecideReturns' => $this->form->mayChange('auto_restock_accepted_returns'),
            'secretFields' => $channel === null ? [] : AccountForm::SECRET_FIELDS[$channel->value],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $channel = SalesChannel::tryFrom((string) $request->input('channel'));
        abort_unless($channel?->isExternal() === true, 404);

        $validator = $this->form->validator($request, $channel);
        if ($validator->fails()) {
            return $this->rejected($request, $validator, route('admin.sales-channels.accounts.create', ['channel' => $channel->value]));
        }
        $payload = $this->form->payload($validator->validated(), $channel, creating: true);

        try {
            $account = $this->accounts->create($payload['data'] + ['channel' => $channel->value], $payload['secrets'], auth('admin')->id());
        } catch (InvalidArgumentException $exception) {
            return $this->rejected($request, $exception->getMessage(), route('admin.sales-channels.accounts.create', ['channel' => $channel->value]));
        }

        ToastMagic::success(translate('channel_account_created_disabled'));

        return redirect()->route('admin.sales-channels.accounts.show', $account);
    }

    public function show(SalesChannelAccount $account): View
    {
        $channel = $account->channelCode();
        $secretFields = AccountForm::SECRET_FIELDS[$channel->value];

        return view('admin-views.sales-channels.account', [
            'labels' => $this->labels,
            'account' => $account,
            'channel' => $channel,
            'writeState' => WriteState::of($account),
            'globalEnabled' => (bool) config('sales_channels.enabled'),
            'externalWritesEnabled' => (bool) config('sales_channels.external_writes_enabled'),
            'rungs' => $this->ladder->rungs($account),
            'extraScopes' => $this->ladder->extraScopeApprovals($account),
            'settings' => $this->form->settingsFor($channel, $account),
            'mayDecideReturns' => $this->form->mayChange('auto_restock_accepted_returns'),
            'carrierCodesText' => $this->form->carrierCodesText($account->setting('carrier_codes', [])),
            'secretFields' => $secretFields,
            'secretsSet' => Arr::only($this->credentials->get($account)->presence(), [...$secretFields, 'webhook_api_key']),
            'syncStates' => $account->syncStates->keyBy('resource'),
            'syncResources' => SyncResources::all(),
            'syncSupported' => $this->syncResources->supportedBy($account),
            'publishing' => $this->publishing($account),
            'matrix' => $this->connectors->for($account)->capabilityMatrix(),
            'webhook' => $channel === SalesChannel::Trendyol ? $this->webhook($account) : null,
            'now' => now(),
        ]);
    }

    public function update(Request $request, SalesChannelAccount $account): RedirectResponse
    {
        $channel = $account->channelCode();
        $back = route('admin.sales-channels.accounts.show', $account);

        $validator = $this->form->validator($request, $channel, $account);
        if ($validator->fails()) {
            return $this->rejected($request, $validator, $back);
        }
        $payload = $this->form->payload($validator->validated(), $channel, creating: false);
        $identityBefore = $this->identity($account, $channel);

        try {
            $this->accounts->update($account, $payload['data'], $payload['secrets'], $payload['clear'], auth('admin')->id());
        } catch (InvalidArgumentException|ChannelUnavailableException $exception) {
            return $this->rejected($request, $exception->getMessage(), $back);
        }

        ToastMagic::success(translate('settings_saved_successfully'));
        if ($identityBefore !== $this->identity($account, $channel)) {
            SafeToast::warning(translate('account_identity_changed_withdrawn'));
        }

        return redirect($back);
    }

    public function toggle(SalesChannelAccount $account, string $action): RedirectResponse
    {
        $enable = $action === 'enable';
        $this->accounts->setEnabled($account, $enable, auth('admin')->id());
        ToastMagic::success(translate($enable ? 'account_enabled' : 'account_disabled'));

        return $this->toAccount($account);
    }

    public function capability(Request $request, SalesChannelAccount $account, string $capability, string $action): RedirectResponse
    {
        $capability = IntegrationCapability::from($capability);
        $adminId = auth('admin')->id();

        if ($action === 'enable') {
            $reason = $capability->needsApproval() ? $this->approvalReason($request, 'capability_' . $capability->value) : null;
            $this->attempt(function () use ($account, $capability, $adminId, $reason): void {
                $this->accounts->enableCapability($account, $capability, $adminId, $reason);
                ToastMagic::success(translate('capability_switched_on'));
            });

            return $this->toAccount($account);
        }

        $offToo = $this->heldAmong($account, [$capability, ...$this->ladder->dependentsOf($capability)]);
        $this->accounts->disableCapability($account, $capability, $adminId);
        SafeToast::success(translate('capability_switched_off'), implode(', ', array_map(static fn (IntegrationCapability $off): string => translate($off->labelKey()), $offToo)));

        return $this->toAccount($account);
    }

    public function approval(Request $request, SalesChannelAccount $account, string $scope, string $action): RedirectResponse
    {
        $adminId = auth('admin')->id();

        if ($action === 'revoke') {
            $this->accounts->revokeScope($account, $scope, $adminId);
            ToastMagic::success(translate('approval_withdrawn'));

            return $this->toAccount($account);
        }

        $reason = $this->approvalReason($request, 'approval_' . $scope);
        if ($scope === ChannelOperations::SCOPE_BUYER_DATA_TRANSFER && (int) auth('admin')->user()?->admin_role_id !== 1) {
            throw ValidationException::withMessages(['approval_reason' => translate('buyer_data_approval_master_admin_only')])->errorBag('approval_' . $scope);
        }
        $this->attempt(function () use ($account, $scope, $adminId, $reason): void {
            $capability = IntegrationCapability::tryFrom($scope);
            $capability === null
                ? $this->accounts->approveScope($account, $scope, (int) $adminId, $reason)
                : $this->accounts->enableCapability($account, $capability, $adminId, $reason);
            ToastMagic::success(translate('approval_recorded'));
        });

        return $this->toAccount($account);
    }

    public function writes(Request $request, SalesChannelAccount $account): RedirectResponse
    {
        $allowed = $request->boolean('allowed');
        $this->attempt(function () use ($account, $allowed): void {
            $this->accounts->setWriteEnabled($account, $allowed, auth('admin')->id());
            ToastMagic::success(translate($allowed ? 'writes_allowed_for_account' : 'writes_forbidden_for_account'));
        });

        return $this->toAccount($account);
    }

    /** Dry run is "rehearsal only: nothing is sent". Turning it OFF is the last step before real writes, so it has to be confirmed. */
    public function dryRun(Request $request, SalesChannelAccount $account): RedirectResponse
    {
        $dryRun = $request->boolean('dry_run');
        if (!$dryRun) {
            $request->validateWithBag('dry_run', ['confirm_live' => ['accepted']], ['confirm_live.accepted' => translate('confirm_that_writes_may_leave_this_host')]);
        }
        $this->accounts->setDryRun($account, $dryRun, auth('admin')->id());
        ToastMagic::success(translate($dryRun ? 'dry_run_switched_on' : 'dry_run_switched_off'));

        return $this->toAccount($account);
    }

    /** A person approves a write with a reason that is kept in the audit trail; the reason is not optional. */
    private function approvalReason(Request $request, string $bag): string
    {
        return $request->validateWithBag($bag, ['approval_reason' => ['required', 'string', 'min:5', 'max:500']])['approval_reason'];
    }

    /** Runs a step the service may refuse, and shows the refusal as the service worded it. */
    private function attempt(callable $step): void
    {
        try {
            $step();
        } catch (ChannelUnavailableException|InvalidArgumentException $exception) {
            SafeToast::error('', $exception->getMessage());
        }
    }

    /** A rejected form goes back to the form it came from, with what was typed except the secrets. */
    private function rejected(Request $request, Validator|string $problem, string $to): RedirectResponse
    {
        $redirect = redirect($to)->withInput($request->except(AccountForm::SECRETS_KEY));

        return $problem instanceof Validator ? $redirect->withErrors($problem) : $redirect->withErrors(['settings' => $problem]);
    }

    private function toAccount(SalesChannelAccount $account): RedirectResponse
    {
        return redirect()->route('admin.sales-channels.accounts.show', $account);
    }

    /**
     * @param list<IntegrationCapability> $capabilities
     * @return list<IntegrationCapability> those of them the account holds
     */
    private function heldAmong(SalesChannelAccount $account, array $capabilities): array
    {
        return array_values(array_filter($capabilities, static fn (IntegrationCapability $capability): bool => $account->hasCapability($capability)));
    }

    /** @return array<string, mixed> what identifies the seller account the connection was tested against */
    private function identity(SalesChannelAccount $account, SalesChannel $channel): array
    {
        return [$account->external_account_id, ...array_map(fn (string $setting): mixed => $account->setting($setting), AccountForm::IDENTITY_SETTINGS[$channel->value])];
    }

    /** @return array{listings: int, mapped: int, stock: int, price: int} */
    private function publishing(SalesChannelAccount $account): array
    {
        $listings = SalesChannelProductMapping::where('account_id', $account->id);

        return [
            'listings' => (clone $listings)->count(),
            'mapped' => (clone $listings)->mapped()->count(),
            'stock' => (clone $listings)->mapped()->where('inventory_publish', true)->count(),
            'price' => (clone $listings)->mapped()->where('price_publish', true)->count(),
        ];
    }

    /** @return array{address: string, statuses: list<string>} */
    private function webhook(SalesChannelAccount $account): array
    {
        return ['address' => route('sales_channels.webhooks.receive', $account->id), 'statuses' => TrendyolWebhookManager::STATUSES];
    }
}
