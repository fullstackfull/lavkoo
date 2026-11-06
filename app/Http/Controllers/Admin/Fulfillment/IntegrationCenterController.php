<?php

namespace App\Http\Controllers\Admin\Fulfillment;

use App\Services\Fulfillment\Support\SafeToast;
use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Http\Controllers\Controller;
use App\Models\ProviderApiLog;
use App\Models\ProviderFulfillment;
use App\Models\ProviderSyncState;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\FieldMapDefinitions;
use App\Services\Fulfillment\FieldMapStore;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\Providers\TorodPoint\TorodInvoiceRoundTrip;
use App\Services\Fulfillment\Providers\TorodPoint\TorodStockConfirmation;
use App\Services\Fulfillment\Readiness\FulfillmentPreflight;
use App\Services\Fulfillment\Readiness\GoLiveItem;
use App\Services\Fulfillment\Readiness\GoLiveOverride;
use App\Services\Fulfillment\Readiness\GoLiveReadiness;
use App\Services\Fulfillment\ProviderRegistry;
use App\Services\Fulfillment\ProviderSettingsForm;
use App\Services\Fulfillment\ProviderSettingsSaver;
use App\Services\Fulfillment\ProviderSyncService;
use App\Services\Fulfillment\Support\FieldMap;
use App\Services\Fulfillment\Verification\EvidenceStore;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** The Integration Center: every fulfillment provider's connection, switches, health and operational logs in one place. */
class IntegrationCenterController extends Controller
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ProviderConnectionManager $connections,
        private readonly ProviderSettingsForm $form,
        private readonly FieldMapStore $fieldMaps,
        private readonly FieldMapDefinitions $definitions,
        private readonly ProviderSyncService $sync,
        private readonly CategoryRepositoryInterface $categories,
        private readonly FulfillmentPreflight $preflight,
        private readonly EvidenceStore $evidence,
        private readonly GoLiveReadiness $goLive,
        private readonly ProviderSettingsSaver $saver,
        private readonly TorodInvoiceRoundTrip $invoiceRoundTrip,
        private readonly TorodStockConfirmation $stockConfirmation,
    ) {
    }

    public function index(Request $request): View
    {
        $cards = collect(ProviderCode::cases())->map(fn (ProviderCode $code): array => $this->card($code));

        return view('admin-views.fulfillment.index', [
            'cards' => $cards,
            'integrationMode' => $this->connections->mode(),
            'systemChecks' => $this->preflight->runtime(request: $request),
        ]);
    }

    public function show(string $provider): View
    {
        $code = $this->resolve($provider);
        $config = $this->connections->config($code);
        $maps = [];
        foreach ($this->definitions->for($code) as $capability => $definition) {
            $stored = $this->fieldMaps->get($code, $capability);
            $maps[$capability] = $definition + ['stored' => $stored];
        }

        return view('admin-views.fulfillment.provider', [
            'card' => $this->card($code),
            'integrationMode' => $this->connections->mode(),
            'goLive' => $this->goLive->report($code),
            'canOverrideGoLive' => $this->isMasterAdmin(),
            'verificationRuns' => $this->evidence->recent($code->value),
            'latestVerification' => ($latest = $this->evidence->recent($code->value, 1)[0] ?? null) === null ? null : $this->evidence->find($code->value, $latest['run_id']),
            'code' => $code,
            'config' => $config,
            'secrets' => $config->toDisplayArray()['secrets_configured'],
            'defaults' => $this->registry->defaultSettings($code),
            'maps' => $maps,
            'stockField' => $code === ProviderCode::TorodPoint ? ['path' => $this->stockConfirmation->path(), 'stated' => $this->stockConfirmation->isStated($config)] : null,
            'invoiceRoundTrip' => $code === ProviderCode::TorodPoint
                ? ['confirmation' => $this->invoiceRoundTrip->confirmation($config), 'path' => $this->invoiceRoundTrip->invoicePath(), 'minEvidence' => TorodInvoiceRoundTrip::MIN_EVIDENCE_LENGTH, 'maxEvidence' => TorodInvoiceRoundTrip::MAX_EVIDENCE_LENGTH]
                : null,
            'webhookUrls' => $this->webhookUrls($code, $config->secret('webhook_secret')),
            'revealedSecret' => session('webhook_secret_once'),
            'recentLogs' => ProviderApiLog::where('provider', $code->value)->latest('id')->limit(15)->get(),
            'statuses' => ['pending', 'confirmed', 'processing', 'out_for_delivery'],
            'defaultCompanyId' => config('fulfillment.providers.' . $code->value . '.default_company_id'),
            'importCategories' => $code === ProviderCode::M5azn ? $this->categories->getListWhere(filters: ['position' => 0], dataLimit: 'all') : collect(),
        ]);
    }

    public function update(Request $request, string $provider): RedirectResponse
    {
        $code = $this->resolve($provider);
        $request->validate($this->form->rules($code) + [
            'go_live_override' => ['nullable', 'boolean'],
            'go_live_reason' => ['nullable', 'required_if:go_live_override,1', 'string', 'min:10', 'max:500'],
        ]);

        $data = $this->form->extract($code, $request, $this->connections->config($code));
        $admission = $this->saver->save($code, $data, $this->override($request));

        $admission->wasBlocked()
            ? SafeToast::warning(translate('settings_saved_automatic_sending_not_turned_on'), $this->labels($admission->blocked))
            : ToastMagic::success(translate('settings_saved_successfully'));

        return redirect()->route('admin.fulfillment-providers.show', $code->slug());
    }

    public function test(string $provider): RedirectResponse
    {
        $code = $this->resolve($provider);

        try {
            $result = $this->registry->adapter($code)->testConnection();
        } catch (ProviderUnavailableException $exception) {
            SafeToast::warning('', $exception->getMessage());

            return back();
        }

        $result->ok ? SafeToast::success(translate('connection_test_passed'), $result->message) : SafeToast::error(translate('connection_test_failed'), $result->message);

        return back();
    }

    public function rotateSecret(string $provider): RedirectResponse
    {
        $code = $this->resolve($provider);
        $secret = $this->connections->rotateWebhookSecret($code);

        ToastMagic::success(translate('webhook_secret_generated_copy_the_urls_now'));

        return redirect()->route('admin.fulfillment-providers.show', $code->slug())->with('webhook_secret_once', $secret);
    }

    public function sync(string $provider, string $task): RedirectResponse
    {
        $code = $this->resolve($provider);
        abort_unless(in_array($task, [ProviderSyncService::PRODUCTS, ProviderSyncService::INVENTORY, ProviderSyncService::CITIES], true), 404);

        $result = $this->sync->run($code, [$task])[$task];
        $ok = (bool) ($result['ok'] ?? false);
        $detail = $ok ? json_encode($result['stats'] ?? $result['count'] ?? []) : (string) ($result['error'] ?? '');
        $ok ? SafeToast::success(translate('sync_completed'), $detail) : SafeToast::warning(translate('sync_did_not_run'), $detail);

        return back();
    }

    /** POST …/field-map/{capability}/sample — a read-only look at what the provider really returns. */
    public function fieldMapSample(string $provider, string $capability): JsonResponse
    {
        $code = $this->resolve($provider);
        abort_unless($this->definitions->has($code, $capability), 404);

        try {
            $adapter = $this->registry->adapter($code);
            abort_unless(method_exists($adapter, 'sample'), 404);

            return response()->json($adapter->sample($capability));
        } catch (ProviderUnavailableException $exception) {
            return response()->json(['ok' => false, 'error' => $exception->getMessage()], 409);
        }
    }

    /** POST …/field-map/{capability} — save the map; with `verify`, check it against a FRESH sample fetched here. */
    public function fieldMapSave(Request $request, string $provider, string $capability): RedirectResponse
    {
        $code = $this->resolve($provider);
        abort_unless($this->definitions->has($code, $capability), 404);
        $definition = $this->definitions->for($code)[$capability];

        $validated = $request->validate([
            'paths' => ['nullable', 'array'],
            'paths.*' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_.\-\[\]]*$/'],
            'items_path' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_.\-]*$/'],
        ]);

        $paths = array_intersect_key((array) ($validated['paths'] ?? []), array_flip($definition['fields']));
        $this->fieldMaps->save($code, $capability, $paths, $validated['items_path'] ?? null);

        if (!$request->boolean('verify')) {
            ToastMagic::success(translate('field_map_saved_not_verified'));

            return back();
        }

        try {
            $sample = $this->registry->adapter($code)->sample($capability);
        } catch (ProviderUnavailableException $exception) {
            SafeToast::warning('', $exception->getMessage());

            return back();
        }

        $items = $sample['ok'] ? FieldMap::extractItems(['data' => $sample['items']], 'data') ?? [] : [];
        $problems = $this->fieldMaps->verify($code, $capability, $items, $definition['required'], $definition['nullable'] ?? []);
        if ($problems !== []) {
            SafeToast::error(translate('field_map_not_verified'), implode(', ', $problems));

            return back();
        }

        $unfilled = $this->unfilledNullableFields($code, $capability, $definition['nullable'] ?? []);
        $unfilled === []
            ? ToastMagic::success(translate('field_map_verified'))
            : SafeToast::warning(translate('field_map_verified_shape_only'), implode(', ', array_map(static fn (string $logical): string => translate('map_field_' . $logical), $unfilled)));

        return back();
    }

    /** POST …/torod-point/invoice-round-trip — a master administrator records that Torod Point returns the invoice number we send, and the evidence. */
    public function confirmInvoiceRoundTrip(Request $request): RedirectResponse
    {
        if (!$this->isMasterAdmin()) {
            throw ValidationException::withMessages(['confirm' => translate('invoice_round_trip_master_admin_only')]);
        }

        $validated = $request->validate([
            'confirm' => ['accepted'],
            'evidence' => ['required', 'string', 'min:' . TorodInvoiceRoundTrip::MIN_EVIDENCE_LENGTH, 'max:' . TorodInvoiceRoundTrip::MAX_EVIDENCE_LENGTH],
        ]);

        if ($this->invoiceRoundTrip->invoicePath() === null) {
            SafeToast::warning(translate('invoice_round_trip_not_confirmed'), translate('invoice_round_trip_needs_invoice_path'));

            return back();
        }

        $admin = auth('admin')->user();
        $this->invoiceRoundTrip->confirm(adminId: (int) $admin->id, adminName: (string) $admin->name, evidence: $validated['evidence']);
        ToastMagic::success(translate('invoice_round_trip_confirmed'));

        return back();
    }

    public function withdrawInvoiceRoundTrip(): RedirectResponse
    {
        if (!$this->isMasterAdmin()) {
            throw ValidationException::withMessages(['confirm' => translate('invoice_round_trip_master_admin_only')]);
        }

        $this->invoiceRoundTrip->withdraw();
        ToastMagic::success(translate('invoice_round_trip_withdrawn'));

        return back();
    }

    /**
     * The nullable fields of a map that the verification sample did not fill in for every record it looked at.
     *
     * @param list<string> $nullable
     * @return list<string>
     */
    private function unfilledNullableFields(ProviderCode $code, string $capability, array $nullable): array
    {
        $stored = $this->fieldMaps->get($code, $capability);
        $sampled = (int) ($stored->coverage['sampled'] ?? 0);

        return array_values(array_filter($nullable, static fn (string $logical): bool => ($stored->filledIn($logical) ?? 0) < $sampled));
    }

    /** @return array<string, mixed> */
    private function card(ProviderCode $code): array
    {
        $config = $this->connections->config($code);
        $readiness = $this->registry->adapter($code)->readiness();
        $states = ProviderSyncState::where('provider', $code->value)->get()->keyBy('capability');
        $byStatus = ProviderFulfillment::where('provider', $code->value)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'code' => $code,
            'slug' => $code->slug(),
            'label' => $code->label(),
            'role' => config('fulfillment.providers.' . $code->value . '.role'),
            'enabled' => $config->enabled,
            'dispatchEnabled' => $config->bool('dispatch_enabled'),
            'autoDispatch' => $config->bool('auto_dispatch'),
            'dryRun' => $config->bool('dry_run', true),
            'writesBlocked' => !$this->connections->externalWritesEnabled(),
            'goLiveAt' => $config->get('go_live_at'),
            'baseUrl' => $config->baseUrl,
            'companyId' => $config->string('company_id'),
            'readiness' => $readiness,
            'documented' => (array) config('fulfillment.providers.' . $code->value . '.documented'),
            'states' => $states,
            'open' => (int) collect(FulfillmentStatus::activeValues())->sum(static fn (string $status): int => (int) ($byStatus[$status] ?? 0)),
            'held' => (int) ($byStatus[FulfillmentStatus::Held->value] ?? 0),
            'failed' => (int) ($byStatus[FulfillmentStatus::Failed->value] ?? 0),
            'unknown' => (int) ($byStatus[FulfillmentStatus::ReconcilePending->value] ?? 0),
            'lastError' => $states->filter(static fn ($state): bool => $state->last_error !== null)->sortByDesc('last_failure_at')->first(),
            'webhookConfigured' => $config->hasSecret('webhook_secret'),
        ];
    }

    /** @return array<string, string> type => URL; the secret is masked unless it was just generated */
    private function webhookUrls(ProviderCode $code, ?string $secret): array
    {
        if ($code !== ProviderCode::TorodPoint || $secret === null) {
            return [];
        }

        $reveal = (string) session('webhook_secret_once', '');
        $shown = $reveal !== '' ? $reveal : '••••••••' . substr($secret, -4);
        $urls = [];
        foreach (['lastmile', 'fulfillment'] as $type) {
            $urls[$type] = url("/api/fulfillment-webhooks/{$code->slug()}/{$shown}/{$type}");
        }

        return $urls;
    }

    /** Going ahead with unmet items is the master admin's decision, and it comes with a reason that is kept. */
    private function override(Request $request): ?GoLiveOverride
    {
        if (!$request->boolean('go_live_override')) {
            return null;
        }
        if (!$this->isMasterAdmin()) {
            throw ValidationException::withMessages(['go_live_override' => translate('go_live_override_master_admin_only')]);
        }

        $admin = auth('admin')->user();

        return new GoLiveOverride(adminId: $admin->id, adminName: (string) $admin->name, reason: trim((string) $request->input('go_live_reason')));
    }

    private function isMasterAdmin(): bool
    {
        return (int) auth('admin')->user()?->admin_role_id === 1;
    }

    /** @param list<GoLiveItem> $items */
    private function labels(array $items): string
    {
        return implode(', ', array_map(static fn (GoLiveItem $item): string => translate($item->labelKey()), $items));
    }

    private function resolve(string $provider): ProviderCode
    {
        return ProviderCode::fromSlug($provider) ?? abort(404);
    }
}
