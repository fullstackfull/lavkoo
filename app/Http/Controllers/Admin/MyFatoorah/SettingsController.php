<?php

namespace App\Http\Controllers\Admin\MyFatoorah;

use App\Enums\MyFatoorah\AttemptStatus;
use App\Http\Controllers\Controller;
use App\Models\MyFatoorahPaymentAttempt;
use App\Models\MyFatoorahRefund;
use App\Models\MyFatoorahWebhookEvent;
use App\Services\MyFatoorah\ConnectionTester;
use App\Services\MyFatoorah\GatewayAvailability;
use App\Services\MyFatoorah\GatewayConfigRepository;
use App\Services\MyFatoorah\PaymentMethodCatalog;
use App\Services\MyFatoorah\PaymentSettlementService;
use App\Services\MyFatoorah\PaymentVerificationService;
use App\Services\MyFatoorah\ReconciliationService;
use App\Services\MyFatoorah\RefundService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * The MyFatoorah settings and monitoring page.
 *
 * A dedicated page rather than the shared gateway form, for two reasons. The shared form writes what it
 * is given as plaintext into BOTH the live and the test blob at once — so it cannot hold separate
 * sandbox and production keys, and it would store an API key and a webhook secret in the clear. And this
 * gateway needs controls the shared form has nowhere to put: a connection test, the webhook URL to
 * register, the account's real method catalogue, and the payment monitor.
 *
 * The gateway still appears on the normal payment-gateway page with the normal on/off switch, so nothing
 * about how operators turn gateways on changes.
 *
 * Secrets are write-only here. A saved key is never rendered back — not masked, not truncated — and a
 * blank field on save means "leave it alone", which is the normal case because the operator has nothing
 * to retype from.
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly GatewayConfigRepository $configRepo,
        private readonly GatewayAvailability $availability,
        private readonly ConnectionTester $tester,
        private readonly PaymentMethodCatalog $catalog,
        private readonly ReconciliationService $reconciliation,
        private readonly RefundService $refunds,
        private readonly PaymentVerificationService $verification,
        private readonly PaymentSettlementService $settlement,
    ) {
    }

    public function index(Request $request): View
    {
        $config = $this->configRepo->get();
        $availability = $this->availability->check();

        $attempts = MyFatoorahPaymentAttempt::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request['status']))
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = trim((string) $request['search']);
                $q->where(function ($inner) use ($term): void {
                    $inner->where('external_identifier', 'like', '%' . $term . '%')
                        ->orWhere('invoice_id', 'like', '%' . $term . '%')
                        ->orWhere('payment_id', 'like', '%' . $term . '%')
                        ->orWhere('payment_request_id', 'like', '%' . $term . '%');
                });
            })
            ->with('refunds')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin-views.third-party.payment-method.myfatoorah', [
            // Display shape only: no key, no secret, only whether each is present.
            'config' => $config->toDisplayArray(),
            'availability' => $availability,
            'environmentEnabled' => $this->availability->environmentEnabled(),
            'webhookUrl' => route('myfatoorah.webhook'),
            'callbackUrl' => route('myfatoorah.callback', ['reference' => '{reference}']),
            'attempts' => $attempts,
            'attention' => $this->reconciliation->needsAttention(),
            'statuses' => array_map(static fn (AttemptStatus $case): string => $case->value, AttemptStatus::cases()),
            'recentEvents' => MyFatoorahWebhookEvent::query()->latest('id')->limit(15)->get(),
            'requireSignature' => (bool) config('myfatoorah.webhook.require_signature', true),
            'sandboxHost' => (string) config('myfatoorah.hosts.test'),
            'liveHost' => (string) config('myfatoorah.hosts.live'),
        ]);
    }

    /**
     * Save the settings.
     *
     * Note what is NOT validated as `required`: the two API keys and the webhook secret. They are
     * optional on every save because the form cannot show the operator what is already stored, so an
     * empty field means "unchanged". Requiring them would force a re-paste of a live key to change the
     * display title.
     */
    public function update(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'mode' => 'required|in:test,live',
            'gateway_title' => 'required|string|max:120',
            'test_api_key' => 'nullable|string|max:512',
            'live_api_key' => 'nullable|string|max:512',
            'test_webhook_secret' => 'nullable|string|max:512',
            'live_webhook_secret' => 'nullable|string|max:512',
            'enabled_methods' => 'nullable|array',
            'enabled_methods.*' => 'string|max:64',
        ]);

        if ($validator->fails()) {
            ToastMagic::error($validator->errors()->first());

            return back()->withErrors($validator);
        }

        $mode = (string) $request['mode'];

        // Each mode's secrets go into that mode's blob, so saving while in test mode cannot overwrite a
        // production key. Two save calls because the two blobs are written separately.
        $this->configRepo->save(
            mode: $mode,
            settings: [
                'gateway_title' => (string) $request['gateway_title'],
                'enabled_methods' => array_values(array_filter((array) $request['enabled_methods'])),
            ],
            secrets: [
                'api_key' => $request['test_api_key'],
                'webhook_secret' => $request['test_webhook_secret'],
            ],
            secretsMode: 'test',
        );

        $this->configRepo->save(
            mode: $mode,
            secrets: [
                'api_key' => $request['live_api_key'],
                'webhook_secret' => $request['live_webhook_secret'],
            ],
            secretsMode: 'live',
        );

        ToastMagic::success(translate('myfatoorah_settings_saved'));

        return back();
    }

    /**
     * Test the connection for one mode.
     *
     * A read (`GET /v3/payment-methods`), so it proves the key works without leaving a test invoice in
     * the merchant's dashboard — which on a live account would be a real invoice.
     */
    public function testConnection(Request $request): RedirectResponse
    {
        $mode = $request['mode'] === 'live' ? 'live' : 'test';
        $result = $this->tester->test($mode);

        $result['ok']
            ? ToastMagic::success($result['message'])
            : ToastMagic::error($result['message']);

        return back();
    }

    /** Re-read the account's method catalogue, so a method MyFatoorah has just enabled shows up. */
    public function refreshMethods(Request $request): RedirectResponse
    {
        $result = $this->catalog->refresh($request['mode'] === 'live' ? 'live' : 'test');

        $result['ok']
            ? ToastMagic::success(translate('payment_methods_refreshed') . ' (' . count($result['methods']) . ')')
            : ToastMagic::error((string) ($result['error'] ?? translate('could_not_refresh_payment_methods')));

        return back();
    }

    /** Verify one attempt again by hand, for an operator chasing a specific payment. */
    public function reverify(Request $request, int $attempt): RedirectResponse
    {
        $row = MyFatoorahPaymentAttempt::query()->findOrFail($attempt);

        // Straight at this one attempt, not through the sweep: the sweep chooses its own batch by age
        // and last-checked time, and would usually skip the row the operator is actually looking at.
        $verification = $this->verification->verify($row);

        if ($verification['status'] === AttemptStatus::Paid) {
            $this->settlement->settle($verification['attempt']);
        }

        ToastMagic::success($verification['message']);

        return back();
    }

    /**
     * Refund a payment, in whole or in part.
     *
     * An operator action with an explicit amount, and idempotent on the attempt plus that amount: a
     * double-submitted form sends one refund. Returning stock is NOT done here — the store's returns
     * workflow owns that, and money coming back is not evidence that goods did.
     */
    public function refund(Request $request, int $attempt): RedirectResponse
    {
        $row = MyFatoorahPaymentAttempt::query()->findOrFail($attempt);

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'comment' => 'nullable|string|max:200',
        ]);

        if ($validator->fails()) {
            ToastMagic::error($validator->errors()->first());

            return back();
        }

        $amount = round((float) $request['amount'], 2);

        $result = $this->refunds->request(
            attempt: $row,
            amount: $amount,
            comment: (string) ($request['comment'] ?? ''),
            requestedBy: auth('admin')->id(),
            // Keyed on the attempt, the amount and the comment: resubmitting the same form is one refund,
            // while a deliberate second partial refund of the same amount needs a different comment —
            // which the form asks for, so the operator states their intent rather than guessing.
            idempotencyKey: 'admin:' . $row->getKey() . ':' . number_format($amount, 2, '.', '') . ':' . md5((string) ($request['comment'] ?? '')),
        );

        if (!$result['ok']) {
            ToastMagic::error((string) ($result['error'] ?? translate('the_refund_could_not_be_requested')));

            return back();
        }

        ToastMagic::success(translate('refund_requested_myfatoorah_will_execute_it_after_review'));

        return back();
    }

    /** Mark a refund as actually executed, after the operator has confirmed it in the MyFatoorah portal. */
    public function markRefundExecuted(Request $request, int $refund): RedirectResponse
    {
        $row = MyFatoorahRefund::query()->findOrFail($refund);

        if ($row->isExecuted()) {
            ToastMagic::info(translate('this_refund_is_already_marked_as_executed'));

            return back();
        }

        $this->refunds->markExecuted($row, auth('admin')->id());
        ToastMagic::success(translate('refund_marked_as_executed'));

        return back();
    }

    /**
     * Release an attempt whose create call never came back, on an operator's word that the MyFatoorah
     * portal shows no invoice for it.
     *
     * This exists because the API cannot always answer the question. MyFatoorah publishes no literal
     * path for its invoice lookups, so on an account that does not serve them every lookup 404s and
     * "no invoice" is indistinguishable from "no such path". Rather than guess — which would authorise
     * a second invoice for an order that may already have one — the attempt holds its order's live slot
     * and waits for a person who can read the portal.
     *
     * Deliberately not reachable without a human: there is no automatic path to this action.
     */
    public function confirmNeverCreated(Request $request, int $attempt): RedirectResponse
    {
        $row = MyFatoorahPaymentAttempt::query()->findOrFail($attempt);

        if ($row->statusEnum() !== AttemptStatus::Created || $row->payment_url !== null) {
            ToastMagic::error(translate('only_an_attempt_whose_payment_page_never_arrived_can_be_released_this_way'));

            return back();
        }

        $row->forceFill([
            'status' => AttemptStatus::Failed->value,
            // Frees the order's single live slot so the customer can pay again.
            'live_slot' => null,
            'last_error_code' => 'never_created_confirmed',
            'last_error_message' => 'An operator confirmed in the MyFatoorah portal that no invoice exists for this reference.',
        ])->save();

        Log::warning('myfatoorah.attempt_released_by_operator', [
            'attempt' => $row->external_identifier,
            'admin' => auth('admin')->id(),
        ]);

        ToastMagic::success(translate('attempt_released_the_customer_can_start_a_new_payment'));

        return back();
    }

    /**
     * Apply a payment whose success hook began and never finished, on an operator's instruction.
     *
     * Never automatic. A hook that started may have created the order, taken the stock and booked the
     * shipment, or be part way through any of them, and nothing here can tell a dead process from a
     * slow one. The operator checks those four things first; this is the button they press afterwards.
     */
    public function rerunHook(Request $request, int $attempt): RedirectResponse
    {
        $row = MyFatoorahPaymentAttempt::query()->findOrFail($attempt);

        $outcome = $this->settlement->forceRerun($row, auth('admin')->id());

        $outcome['ran']
            ? ToastMagic::success(translate('the_payment_was_applied'))
            : ToastMagic::error($outcome['reason']);

        return back();
    }

    /** Run the reconciliation sweep now. */
    public function reconcile(): RedirectResponse
    {
        $tally = $this->reconciliation->sweep();

        if ($tally['skipped'] !== null) {
            ToastMagic::error($tally['skipped']);

            return back();
        }

        ToastMagic::success(translate('checked') . ': ' . $tally['checked'] . ' · ' . translate('settled') . ': ' . $tally['settled'] . ' · ' . translate('still_pending') . ': ' . $tally['pending']);

        return back();
    }
}
