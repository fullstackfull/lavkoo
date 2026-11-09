<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Enums\OperationalCosts\CostComponent;
use App\Http\Controllers\Controller;
use App\Models\OdooAccountMapping;
use App\Models\SalesChannelAccount;
use App\Services\Accounting\Odoo\OdooClient;
use App\Services\Accounting\Odoo\OdooSchema;
use App\Services\Fulfillment\Support\SafeToast;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * What each of our cost components means in the customer's chart of accounts.
 *
 * **The chart is READ from Odoo and never invented here.** Every id on this screen came back from the instance, which is why
 * reading it is a POST a person presses rather than something a page load does: it is several calls to somebody else's server,
 * and a page that silently called out every time somebody opened it would be rude and slow.
 *
 * Nothing is posted through an unmapped component and nothing is posted through an unapproved mapping. Both are refusals the
 * builder makes by name, and this screen is where a person turns them into decisions.
 *
 * A mapping is per company, and may be narrowed to a channel or a currency; the narrowest match wins, so a general row is a
 * fallback somebody chose rather than a first row the database happened to return.
 */
class OdooMappingController extends Controller
{
    /** How long a read chart is kept. Long enough to map a whole ledger in one sitting, short enough that a changed chart shows. */
    private const CHART_MINUTES = 60;

    public function __construct(private readonly OdooClient $client)
    {
    }

    public function index(): View
    {
        if (!OdooSchema::ready()) {
            return view('admin-views.accounting.odoo.not-migrated', ['migration' => OdooSchema::MIGRATION]);
        }

        $connection = $this->connection();
        $companyId = (int) ($connection?->setting('company_id', 0) ?? 0);

        return view('admin-views.accounting.odoo.mapping', [
            'connection' => $connection,
            'companyId' => $companyId,
            'chart' => $this->chart($connection),
            'facts' => $this->facts(),
            'mappings' => $connection === null ? collect() : OdooAccountMapping::orderBy('fact_kind')->orderBy('fact_code')->get()->groupBy(
                static fn (OdooAccountMapping $row): string => $row->fact_kind . ':' . $row->fact_code,
            ),
        ]);
    }

    /** Reads the chart of accounts, the journals, the taxes and the analytic accounts. Reads only, with writes still off. */
    public function read(): RedirectResponse
    {
        $connection = $this->connection();
        if ($connection === null) {
            SafeToast::warning(translate('odoo_save_the_host_first'));

            return back();
        }

        try {
            $chart = [
                'accounts' => $this->records($connection, 'account.account', ['id', 'code', 'name', 'account_type']),
                'journals' => $this->records($connection, 'account.journal', ['id', 'code', 'name', 'type']),
                'taxes' => $this->records($connection, 'account.tax', ['id', 'name', 'type_tax_use', 'amount']),
                'analytic' => $this->records($connection, 'account.analytic.account', ['id', 'name']),
                'read_at' => now()->toIso8601String(),
            ];
        } catch (ChannelUnavailableException | ChannelPermissionDenied $refused) {
            SafeToast::warning(translate('odoo_chart_could_not_be_read'), $refused->getMessage());

            return back();
        }

        if ($chart['accounts'] === []) {
            // An empty chart is not a chart. Caching it would make the screen look as though the customer's Odoo has no
            // accounts, which is a different claim from "nobody could read them".
            SafeToast::warning(translate('odoo_chart_came_back_empty'), translate('odoo_chart_came_back_empty_help'));

            return back();
        }

        Cache::put($this->chartKey($connection), $chart, now()->addMinutes(self::CHART_MINUTES));
        SafeToast::success(translate('odoo_chart_read'), translate('odoo_chart_read_help', [
            'accounts' => count($chart['accounts']),
            'journals' => count($chart['journals']),
            'taxes' => count($chart['taxes']),
        ]));

        return back();
    }

    /** Saves one decision: this fact posts to this account, through this journal, with this tax and cost centre. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'fact_kind' => ['required', 'string', 'in:' . OdooAccountMapping::KIND_COST_COMPONENT . ',' . OdooAccountMapping::KIND_COUNTERPART],
            'fact_code' => ['required', 'string', 'max:64'],
            'sales_channel' => ['nullable', 'string', 'max:24'],
            'currency' => ['nullable', 'string', 'max:8'],
            'odoo_account_id' => ['required', 'integer', 'min:1'],
            'odoo_account_code' => ['nullable', 'string', 'max:64'],
            'odoo_journal_id' => ['required', 'integer', 'min:1'],
            'odoo_journal_code' => ['nullable', 'string', 'max:64'],
            'odoo_tax_id' => ['nullable', 'integer', 'min:1'],
            'odoo_analytic_account_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $connection = $this->connection();
        $companyId = (int) ($connection?->setting('company_id', 0) ?? 0);
        if ($companyId < 1) {
            // A mapping is only valid for the company it was read against, so there is nothing to save until one is chosen.
            SafeToast::warning(translate('odoo_choose_a_company_first'), translate('odoo_choose_a_company_first_help'));

            return back();
        }

        OdooAccountMapping::updateOrCreate(
            [
                'seller_id' => 0,
                'odoo_company_id' => $companyId,
                'fact_kind' => $data['fact_kind'],
                'fact_code' => $data['fact_code'],
                // "Any" is the empty string, not null: a unique index does not constrain NULLs on any engine this runs on, so a
                // nullable column would let the general row be saved any number of times.
                'sales_channel' => (string) ($data['sales_channel'] ?? OdooAccountMapping::ANY),
                'currency' => (string) ($data['currency'] ?? OdooAccountMapping::ANY),
            ],
            [
                'odoo_account_id' => $data['odoo_account_id'],
                'odoo_account_code' => $data['odoo_account_code'] ?? null,
                'odoo_journal_id' => $data['odoo_journal_id'],
                'odoo_journal_code' => $data['odoo_journal_code'] ?? null,
                'odoo_tax_id' => $data['odoo_tax_id'] ?? null,
                'odoo_analytic_account_id' => $data['odoo_analytic_account_id'] ?? null,
                // Saving is not approving. A row somebody is still drafting must not start posting into their books, so the
                // approval is a separate, deliberate press.
                'approved_at' => null,
                'approved_by' => null,
                'verified_at' => now(),
            ],
        );

        SafeToast::success(translate('odoo_mapping_saved'), translate('odoo_mapping_saved_help'));

        return back();
    }

    /** Approves one mapping. Until this, the builder refuses the component by name. */
    public function approve(Request $request, OdooAccountMapping $mapping): RedirectResponse
    {
        $mapping->forceFill(['approved_at' => now(), 'approved_by' => (int) ($request->user()?->id ?? 0)])->save();
        SafeToast::success(translate('odoo_mapping_approved'));

        return back();
    }

    /** Withdraws an approval. The row stays, so what was decided and then undecided is visible. */
    public function unapprove(OdooAccountMapping $mapping): RedirectResponse
    {
        $mapping->forceFill(['approved_at' => null, 'approved_by' => null])->save();
        SafeToast::info(translate('odoo_mapping_approval_withdrawn'));

        return back();
    }

    public function destroy(OdooAccountMapping $mapping): RedirectResponse
    {
        $mapping->delete();
        SafeToast::info(translate('odoo_mapping_removed'));

        return back();
    }

    /**
     * Our own vocabulary, as rows to be mapped: every cost component the ledger can produce, plus the counterpart.
     *
     * @return list<array{kind: string, code: string, label: string}>
     */
    private function facts(): array
    {
        $facts = [[
            'kind' => OdooAccountMapping::KIND_COUNTERPART,
            'code' => OdooAccountMapping::CODE_DEFAULT,
            'label' => translate('odoo_counterpart_for_everything'),
        ]];
        foreach (CostComponent::cases() as $component) {
            $facts[] = [
                'kind' => OdooAccountMapping::KIND_COST_COMPONENT,
                'code' => $component->value,
                'label' => translate('cost_component_' . $component->value),
            ];
        }

        return $facts;
    }

    /** @return array<string, mixed> what was last read from Odoo, or an empty chart nobody has read yet */
    private function chart(?SalesChannelAccount $connection): array
    {
        $empty = ['accounts' => [], 'journals' => [], 'taxes' => [], 'analytic' => [], 'read_at' => null];

        return $connection === null ? $empty : (array) Cache::get($this->chartKey($connection), $empty);
    }

    /**
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    private function records(SalesChannelAccount $connection, string $model, array $fields): array
    {
        // A page of a chart of accounts, not the whole history of one: a thousand rows is already more than a person reads.
        return $this->client->searchRead($connection, $model, [], $fields, limit: 1000)->records();
    }

    private function chartKey(SalesChannelAccount $connection): string
    {
        return 'odoo:chart:' . $connection->id . ':' . (int) $connection->setting('company_id', 0);
    }

    private function connection(): ?SalesChannelAccount
    {
        return SalesChannelAccount::where('channel', 'odoo')->orderBy('id')->first();
    }
}
