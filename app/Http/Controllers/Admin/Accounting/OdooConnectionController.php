<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Models\SalesChannelAccount;
use App\Services\Accounting\Odoo\OdooPreflight;
use App\Services\Accounting\Odoo\OdooPreflightRun;
use App\Services\Accounting\Odoo\OdooSchema;
use App\Services\Accounting\Odoo\OdooTransport;
use App\Services\Fulfillment\Support\SafeToast;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Odoo accounting connection: where the instance is, what it turned out to be, and what it is not ready for.
 *
 * **Everything this screen does is a READ of Odoo.** The connection test runs the preflight, which asks the instance for its
 * version, its models, its journal-entry field names and its company, and asks for nothing else — because the whole point of
 * that order is that a person can connect an instance and build the mapping with every write switch still off.
 *
 * The four switches are shown and **not operable from here**. They are environment settings (`ODOO_ENABLED`,
 * `ODOO_EXTERNAL_WRITES_ENABLED`, `ODOO_DRAFT_CREATION_ENABLED`, `ODOO_FINAL_POSTING_ENABLED`), so a button that appeared to
 * flip one would be lying about what it did; the screen names each one and where it lives.
 *
 * The API key is write-only, as every credential in this application is: no response built here ever carries one back, and the
 * field is empty on every load.
 */
class OdooConnectionController extends Controller
{
    public function __construct(
        private readonly OdooPreflightRun $preflight,
        private readonly ChannelCredentialStore $credentials,
    ) {
    }

    public function index(): View
    {
        if (!OdooSchema::ready()) {
            return view('admin-views.accounting.odoo.not-migrated', ['migration' => OdooSchema::MIGRATION]);
        }

        $connection = $this->connection();

        return view('admin-views.accounting.odoo.connection', [
            'connection' => $connection,
            'transport' => $connection === null ? OdooTransport::Unknown : $this->transportOf($connection),
            'serverVersion' => $connection === null ? null : $this->credentials->get($connection)->secret('server_version'),
            'hasKey' => $connection !== null && trim((string) $this->credentials->get($connection)->secret('api_key')) !== '',
            'switches' => $this->switches(),
            'readiness' => (array) session('odoo_readiness', []),
        ]);
    }

    /** Saves where the instance is. It contacts nobody: the test is a separate, deliberate press. */
    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'base_url' => ['required', 'url', 'max:255'],
            'database' => ['nullable', 'string', 'max:120'],
            'login' => ['nullable', 'string', 'max:160'],
            'company_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $connection = $this->connection();
        $settings = array_merge((array) ($connection?->settings ?? []), array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== ''));

        if ($connection === null) {
            SalesChannelAccount::create([
                // `odoo` is NOT in the SalesChannel enum, on purpose: this row is somewhere to keep an accounting connection
                // safely -- switches, approvals, encrypted credentials, the audit trail -- and never a source of sales.
                'channel' => 'odoo',
                'name' => 'Odoo accounting',
                'accounting_destination' => 'odoo',
                'enabled' => false,
                'write_enabled' => false,
                // A new connection rehearses until somebody decides otherwise.
                'dry_run' => true,
                'capabilities' => ['connection_test', 'financial_reconciliation'],
                'settings' => $settings,
            ]);
        } else {
            $connection->forceFill(['settings' => $settings])->save();
        }

        SafeToast::success(translate('odoo_connection_saved'), translate('odoo_connection_saved_help'));

        return back();
    }

    /** Stores the API key. Write-only: never read back to a screen, and an empty field changes nothing. */
    public function key(Request $request): RedirectResponse
    {
        $request->validate(['api_key' => ['required', 'string', 'min:8', 'max:255']]);
        $connection = $this->connection();
        if ($connection === null) {
            SafeToast::warning(translate('odoo_save_the_host_first'));

            return back();
        }

        $this->credentials->save($connection, ['api_key' => (string) $request->input('api_key')]);
        // The key IS the authorisation, so what was learned under the old one is no longer known to be true of this connection.
        foreach (['uid', 'transport', 'server_version'] as $learned) {
            $this->credentials->putRuntime($connection, $learned, null);
        }

        SafeToast::success(translate('odoo_key_saved'), translate('odoo_key_saved_help'));

        return back();
    }

    /**
     * Asks the instance what it is.
     *
     * Reads only, and the one place the transport and the user id are discovered: a guess at either fails in a way nobody can
     * read, so nothing else in this integration sends anything until this has run.
     */
    public function test(): RedirectResponse
    {
        $connection = $this->connection();
        if ($connection === null) {
            SafeToast::warning(translate('odoo_save_the_host_first'));

            return back();
        }

        try {
            $preflight = $this->preflight->run($connection);
        } catch (ChannelUnavailableException | ChannelPermissionDenied $refused) {
            SafeToast::warning(translate('odoo_connection_test_refused'), $refused->getMessage());

            return back();
        }

        $codes = $preflight->readinessCodes();
        $preflight->couldBuildAnEntry()
            ? SafeToast::success(translate('odoo_connection_test_passed'), $this->readFigures($preflight))
            : SafeToast::warning(translate('odoo_connection_test_incomplete'), implode(', ', $codes));

        return back()->with('odoo_readiness', $codes);
    }

    /** The connection, or null while nobody has saved one. There is one: an accountant does not keep two sets of books. */
    private function connection(): ?SalesChannelAccount
    {
        return SalesChannelAccount::where('channel', 'odoo')->orderBy('id')->first();
    }

    private function transportOf(SalesChannelAccount $connection): OdooTransport
    {
        return OdooTransport::tryFrom((string) ($this->credentials->get($connection)->secret('transport') ?? '')) ?? OdooTransport::Unknown;
    }

    /**
     * What the four switches are, and which environment variable each one is.
     *
     * @return list<array{key: string, env: string, on: bool}>
     */
    private function switches(): array
    {
        return [
            ['key' => 'odoo_switch_enabled', 'env' => 'ODOO_ENABLED', 'on' => (bool) config('odoo.enabled')],
            ['key' => 'odoo_switch_external_writes', 'env' => 'ODOO_EXTERNAL_WRITES_ENABLED', 'on' => (bool) config('odoo.external_writes_enabled')],
            ['key' => 'odoo_switch_draft_creation', 'env' => 'ODOO_DRAFT_CREATION_ENABLED', 'on' => (bool) config('odoo.draft_creation_enabled')],
            ['key' => 'odoo_switch_final_posting', 'env' => 'ODOO_FINAL_POSTING_ENABLED', 'on' => (bool) config('odoo.final_posting_enabled')],
        ];
    }

    /** What was read, as a line a person can check against the instance they meant to connect. */
    private function readFigures(OdooPreflight $preflight): string
    {
        return implode(' · ', [
            translate('odoo_company') . ': ' . (string) ($preflight->company['name'] ?? ''),
            translate('odoo_accounts_read') . ': ' . $preflight->accountsRead,
            translate('odoo_taxes_read') . ': ' . $preflight->taxesRead,
            translate('odoo_journals_read') . ': ' . $preflight->journalsRead,
            // The codes rather than only the count: a person can see at a glance whether the currency a supplier bills in is
            // one this instance offers, which is the difference between a foreign-currency cost posting and being refused.
            translate('odoo_currencies_read') . ': ' . ($preflight->currencies === []
                ? (string) $preflight->currenciesRead
                : implode(', ', array_keys($preflight->currencies))),
        ]);
    }
}
