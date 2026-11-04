<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\SellerApiKey;
use App\Models\SellerAutomationRule;
use App\Models\SellerWebhook;
use App\Services\AuditLogger;
use App\Services\Marketplace\SellerOperationsOverview;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * What the sellers are doing with the platform, from the marketplace's side.
 *
 * Everything the Seller Center gained is something an operator has to be able to see across every
 * shop at once: rules that change catalogues unattended, keys that act as a shop without a person,
 * endpoints the platform calls, staff who are not the account holder, bulk operations. Not to run
 * the shops — that is the seller's job and their screens do it better — but because these are the
 * things that go wrong at three in the morning, and the question is always "how many shops is this
 * happening to, and which".
 *
 * The three interventions here exist because the marketplace is the only party who can make them:
 * stopping a rule that is damaging a catalogue, killing a key that has leaked, and switching off an
 * endpoint that is being used to hammer somebody. Each one is recorded with who did it. Nothing
 * here edits a seller's rule, rewrites their configuration, or acts on their behalf — an operator
 * who needs that should be asking the seller, and a panel that quietly allowed it would turn every
 * seller's shop into shared property.
 */
class SellerOperationsController extends BaseController
{
    public function __construct(
        private readonly SellerOperationsOverview $overview,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request|null $request = null, ?string $type = null): View
    {
        $summary = $this->overview->summary();
        // Read once and used twice: the table and the name lookup are the same rows.
        $issues = $this->overview->issuesBySeller();

        return view('admin-views.marketplace.seller-operations.index', [
            'summary' => $summary,
            'attention' => $this->overview->attentionItems($summary),
            'issuesBySeller' => $issues,
            'deliveryHealth' => $this->overview->deliveryHealth(),
            'sellers' => $this->overview->sellersFor(collect($issues)->pluck('seller_id')),
        ]);
    }

    /**
     * The issues themselves, which is where an operator ends up after the overview.
     *
     * Read-only on purpose: an issue is resolved when the condition that produced
     * it stops being true, which the detector decides by ceasing to report it.
     * A button here that marked one resolved would let the marketplace declare a
     * seller's problem over while it was still happening.
     */
    public function issues(Request $request): View
    {
        $issues = $this->overview->issues(
            sellerId: $this->sellerFilter($request),
            severity: $request->input('severity'),
            category: $request->input('category'),
        );

        return view('admin-views.marketplace.seller-operations.issues', [
            'summary' => $this->overview->summary(),
            'issues' => $issues,
            'categories' => $this->overview->issueCategories(),
            'sellers' => $this->overview->sellersFor(collect($issues?->items() ?? [])->pluck('seller_id')),
        ]);
    }

    public function automation(Request $request): View
    {
        $rules = $this->overview->rules(
            sellerId: $this->sellerFilter($request),
            status: $request->input('status'),
        );
        $activity = $this->overview->automationActivity(sellerId: $this->sellerFilter($request), perPage: 15);

        return view('admin-views.marketplace.seller-operations.automation', [
            'summary' => $this->overview->summary(),
            'rules' => $rules,
            'activity' => $activity,
            'sellers' => $this->overview->sellersFor(collect($rules?->items() ?? [])->pluck('seller_id')
                ->merge(collect($activity?->items() ?? [])->pluck('seller_id'))),
        ]);
    }

    public function integrations(Request $request): View
    {
        $keys = $this->overview->keys(sellerId: $this->sellerFilter($request));
        $webhooks = $this->overview->webhooks(sellerId: $this->sellerFilter($request));

        return view('admin-views.marketplace.seller-operations.integrations', [
            'summary' => $this->overview->summary(),
            'keys' => $keys,
            'webhooks' => $webhooks,
            'health' => $this->overview->deliveryHealth(),
            'sellers' => $this->overview->sellersFor(collect($keys?->items() ?? [])->pluck('seller_id')
                ->merge(collect($webhooks?->items() ?? [])->pluck('seller_id'))),
        ]);
    }

    public function team(Request $request): View
    {
        $staff = $this->overview->staff(sellerId: $this->sellerFilter($request));

        return view('admin-views.marketplace.seller-operations.team', [
            'summary' => $this->overview->summary(),
            'staff' => $staff,
            'sellers' => $this->overview->sellersFor(collect($staff?->items() ?? [])->pluck('seller_id')),
        ]);
    }

    public function bulkJobs(Request $request): View
    {
        $jobs = $this->overview->bulkJobs(sellerId: $this->sellerFilter($request));

        return view('admin-views.marketplace.seller-operations.bulk-jobs', [
            'summary' => $this->overview->summary(),
            'jobs' => $jobs,
            'sellers' => $this->overview->sellersFor(collect($jobs?->items() ?? [])->pluck('seller_id')),
        ]);
    }

    /**
     * Stop a seller's rule.
     *
     * Suspended rather than deleted or paused: suspended is the state the platform owns, it carries
     * a reason, and only a person clears it. Pausing would be indistinguishable from the seller
     * having done it themselves, and deleting would destroy the configuration they would need to
     * see in order to fix it.
     */
    public function suspendRule(Request $request): RedirectResponse
    {
        $rule = SellerAutomationRule::find($request->input('id'));

        if (!$rule) {
            ToastMagic::error(translate('automation_rule_not_found'));

            return back();
        }

        $reason = trim((string) $request->input('reason')) ?: 'automation_suspended_by_marketplace';

        $rule->forceFill([
            'status' => SellerAutomationRule::STATUS_SUSPENDED,
            'suspended_at' => now(),
            'suspension_reason' => mb_substr($reason, 0, 191),
            // Marked as ours, so the seller's own console cannot clear it by editing the rule.
            'suspended_by' => SellerAutomationRule::SUSPENDED_BY_MARKETPLACE,
        ])->save();

        $this->audit->record(
            action: 'seller.automation_rule_suspended',
            subject: ['type' => 'seller_automation_rule', 'id' => $rule->id],
            context: ['seller_id' => $rule->seller_id, 'reason' => $reason, 'by' => 'marketplace'],
        );

        ToastMagic::success(translate('automation_rule_updated'));

        return back();
    }

    /**
     * Let a rule the marketplace stopped be run again.
     *
     * Left paused rather than started: the marketplace is lifting its objection, not deciding that
     * the rule should run tonight. That call belongs to the seller, who can now make it.
     */
    public function releaseRule(Request $request): RedirectResponse
    {
        $rule = SellerAutomationRule::find($request->input('id'));

        if (!$rule || !$rule->isSuspended()) {
            ToastMagic::error(translate('automation_rule_not_found'));

            return back();
        }

        $reason = $rule->suspension_reason;

        $rule->forceFill([
            'status' => SellerAutomationRule::STATUS_PAUSED,
            'suspended_at' => null,
            'suspension_reason' => null,
            'suspended_by' => null,
            'consecutive_failures' => 0,
        ])->save();

        $this->audit->record(
            action: 'seller.automation_rule_released',
            subject: ['type' => 'seller_automation_rule', 'id' => $rule->id],
            before: ['status' => SellerAutomationRule::STATUS_SUSPENDED, 'suspension_reason' => $reason],
            after: ['status' => SellerAutomationRule::STATUS_PAUSED],
            context: ['seller_id' => $rule->seller_id, 'by' => 'marketplace'],
        );

        ToastMagic::success(translate('automation_rule_updated'));

        return back();
    }

    /** Kill a key. Takes effect on the very next request that carries it. */
    public function revokeKey(Request $request): RedirectResponse
    {
        $key = SellerApiKey::find($request->input('id'));

        if (!$key) {
            ToastMagic::error(translate('api_key_not_found'));

            return back();
        }

        if ($key->revoked_at === null) {
            $key->forceFill(['revoked_at' => now()])->save();

            $this->audit->record(
                action: 'seller.api_key_revoked',
                subject: ['type' => 'seller_api_key', 'id' => $key->id],
                before: ['name' => $key->name, 'prefix' => $key->prefix],
                context: ['seller_id' => $key->seller_id, 'by' => 'marketplace'],
            );
        }

        ToastMagic::success(translate('api_key_revoked'));

        return back();
    }

    /** Switch off an endpoint the platform should stop calling. */
    public function disableWebhook(Request $request): RedirectResponse
    {
        $webhook = SellerWebhook::find($request->input('id'));

        if (!$webhook) {
            ToastMagic::error(translate('webhook_not_found'));

            return back();
        }

        $reason = trim((string) $request->input('reason')) ?: 'webhook_disabled_by_marketplace';

        $webhook->forceFill([
            'status' => SellerWebhook::STATUS_DISABLED,
            'disabled_at' => now(),
            'disabled_reason' => mb_substr($reason, 0, 191),
        ])->save();

        $this->audit->record(
            action: 'seller.webhook_disabled',
            subject: ['type' => 'seller_webhook', 'id' => $webhook->id],
            context: ['seller_id' => $webhook->seller_id, 'reason' => $reason, 'by' => 'marketplace'],
        );

        ToastMagic::success(translate('webhook_updated'));

        return back();
    }

    private function sellerFilter(Request $request): ?int
    {
        $sellerId = $request->input('seller_id');

        return is_numeric($sellerId) ? (int) $sellerId : null;
    }
}
