<?php

namespace Tests\Feature\Accounting;

use App\Enums\SalesChannels\IntegrationCapability;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Support\ChannelHttp;
use App\Services\SalesChannels\Support\ChannelOperations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * Odoo's switches are its own, and switching the sales channels on does not open them.
 *
 * Odoo uses the SHARED protection engine -- the operation allow-list, the failure classification, the rehearsal mode, the
 * redacted logs, the recorded approvals, the audit trail -- and its own POLICY. An operation names the config family whose
 * switches govern it, so there are two sets of switches in front of one mechanism rather than two mechanisms.
 *
 * Why that separation matters more here than anywhere else in this application: every other switch in it guards a price, a
 * quantity or a listing, all of which can be corrected by sending the right value again. These guard somebody's accounting
 * records, where a wrong line stays in the audit trail even after it is reversed.
 *
 * The four are four because they guard four different-sized mistakes: reaching the instance at all, writing anything, writing a
 * DRAFT entry (still a row an accountant sees), and the irreversible final posting.
 */
class OdooSwitchesTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const VERSION = 'odoo.version';

    private const READ = 'odoo.search_read';

    private const WRITE = 'odoo.create_draft_entry';

    private SalesChannelAccount $account;

    private ChannelHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->account = $this->accountingConnection();
        $this->http = app(ChannelHttp::class);
    }

    // ---- neither module switches the other on --------------------------------------------------------------------

    public function test_the_sales_channels_being_on_does_not_permit_a_single_odoo_call(): void
    {
        // THE point of the separation. Amazon and noon fully switched on, writes allowed, and Odoo still refuses everything,
        // because turning a marketplace on is not a decision about somebody's accounts.
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        config(['odoo.enabled' => false]);
        Http::fake();

        $this->assertRefused(ChannelUnavailableException::GLOBAL_OFF, self::VERSION, 'GET');
        $this->assertRefused(ChannelUnavailableException::GLOBAL_OFF, self::READ, 'POST');
        $this->assertRefused(ChannelUnavailableException::GLOBAL_OFF, self::WRITE, 'POST');
        Http::assertNothingSent();
    }

    public function test_odoo_being_on_does_not_permit_a_channel_call(): void
    {
        // And the other direction, which matters just as much: an accountant connecting Odoo must not thereby enable a
        // marketplace write.
        config(['odoo.enabled' => true, 'odoo.external_writes_enabled' => true]);
        config(['sales_channels.enabled' => false]);
        Http::fake();

        $this->assertRefused(ChannelUnavailableException::GLOBAL_OFF, 'amazon.listings_put', 'PUT');
        Http::assertNothingSent();
    }

    public function test_the_odoo_master_switch_off_stops_even_a_version_read(): void
    {
        // With this off nothing leaves at all -- not a connection test, not a chart of accounts. There is no "read-only but
        // reachable" state below it.
        config(['odoo.enabled' => false, 'sales_channels.enabled' => true]);
        Http::fake();

        $this->assertRefused(ChannelUnavailableException::GLOBAL_OFF, self::VERSION, 'GET');
        Http::assertNothingSent();
    }

    public function test_reads_are_allowed_while_writes_are_not(): void
    {
        // The state the whole mapping is built in: connect the instance, run the preflight, read its accounts and taxes, decide
        // the mapping -- all with writing still refused. That is the only order in which the work is safe to do.
        config(['odoo.enabled' => true, 'odoo.external_writes_enabled' => false]);
        Http::fake(['*' => Http::response(['result' => []])]);

        $this->assertSame(false, $this->http->assertAllowed($this->account, self::READ, 'POST', IntegrationCapability::FinancialReconciliation));
        $this->assertRefused(ChannelUnavailableException::WRITES_OFF_ENVIRONMENT, self::WRITE, 'POST');
    }

    public function test_the_write_switch_on_still_needs_the_recorded_approval(): void
    {
        // A switch is the operator's decision; the approval is a person's. Both are required, and the switch does not stand in
        // for the approval.
        config(['odoo.enabled' => true, 'odoo.external_writes_enabled' => true]);
        $this->account->forceFill(['capability_approvals' => []])->save();
        Http::fake();

        $this->assertRefused(ChannelUnavailableException::NOT_APPROVED, self::WRITE, 'POST', $this->account->fresh());
        Http::assertNothingSent();
    }

    public function test_with_every_switch_and_the_approval_in_place_a_write_is_allowed(): void
    {
        // The positive case, so the tests above are known to be testing the switches and not a permanent refusal.
        config(['odoo.enabled' => true, 'odoo.external_writes_enabled' => true]);

        $this->assertSame(
            false,
            $this->http->assertAllowed($this->account, self::WRITE, 'POST', IntegrationCapability::FinancialReconciliation, ChannelOperations::SCOPE_ACCOUNTING_POSTING),
            'allowed, and not merely rehearsed',
        );
    }

    public function test_a_rehearsing_connection_sends_no_write_even_with_everything_on(): void
    {
        config(['odoo.enabled' => true, 'odoo.external_writes_enabled' => true]);
        $this->account->forceFill(['dry_run' => true])->save();

        $this->assertTrue(
            $this->http->assertAllowed($this->account->fresh(), self::WRITE, 'POST', IntegrationCapability::FinancialReconciliation, ChannelOperations::SCOPE_ACCOUNTING_POSTING),
            'a rehearsal is reported as such so the call is logged and not sent',
        );
    }

    // ---- a switch thrown while a worker is mid-run ---------------------------------------------------------------

    public function test_a_switch_thrown_off_mid_run_stops_the_very_next_write(): void
    {
        // A worker holds its objects for a whole run. Somebody who switches posting off during that run means it to stop NOW,
        // not at the end of the batch -- so the gate is re-read per call rather than once at the start.
        config(['odoo.enabled' => true, 'odoo.external_writes_enabled' => true]);
        Http::fake();

        $allowedBefore = $this->http->assertAllowed($this->account, self::WRITE, 'POST', IntegrationCapability::FinancialReconciliation, ChannelOperations::SCOPE_ACCOUNTING_POSTING);
        $this->assertFalse($allowedBefore, 'allowed while the switch was on');

        // The operator throws the switch, mid-run, with the same objects still in hand.
        config(['odoo.external_writes_enabled' => false]);

        $this->assertRefused(ChannelUnavailableException::WRITES_OFF_ENVIRONMENT, self::WRITE, 'POST');
        Http::assertNothingSent();
    }

    public function test_the_master_switch_thrown_off_mid_run_stops_reads_too(): void
    {
        config(['odoo.enabled' => true]);
        Http::fake();
        $this->http->assertAllowed($this->account, self::READ, 'POST', IntegrationCapability::FinancialReconciliation);

        config(['odoo.enabled' => false]);

        $this->assertRefused(ChannelUnavailableException::GLOBAL_OFF, self::READ, 'POST');
        Http::assertNothingSent();
    }

    public function test_the_connection_disabled_mid_run_stops_the_next_call(): void
    {
        // The same property for the account record rather than the config: `refreshGates()` re-reads it, so a connection
        // disabled in the panel stops a run that is already going.
        config(['odoo.enabled' => true, 'odoo.external_writes_enabled' => true]);
        Http::fake();
        $this->http->assertAllowed($this->account, self::WRITE, 'POST', IntegrationCapability::FinancialReconciliation, ChannelOperations::SCOPE_ACCOUNTING_POSTING);

        SalesChannelAccount::whereKey($this->account->id)->update(['enabled' => false]);

        $this->assertRefused(ChannelUnavailableException::ACCOUNT_OFF, self::WRITE, 'POST');
        Http::assertNothingSent();
    }

    public function test_the_approval_withdrawn_mid_run_stops_the_next_write(): void
    {
        config(['odoo.enabled' => true, 'odoo.external_writes_enabled' => true]);
        Http::fake();
        $this->http->assertAllowed($this->account, self::WRITE, 'POST', IntegrationCapability::FinancialReconciliation, ChannelOperations::SCOPE_ACCOUNTING_POSTING);

        SalesChannelAccount::whereKey($this->account->id)->update(['capability_approvals' => json_encode([])]);

        $this->assertRefused(ChannelUnavailableException::NOT_APPROVED, self::WRITE, 'POST');
        Http::assertNothingSent();
    }

    // ---- the two switches above the transport --------------------------------------------------------------------

    public function test_draft_creation_and_final_posting_are_separate_switches_and_both_ship_off(): void
    {
        // Creating a draft and committing it to the books are different decisions, and only the second cannot be undone. The
        // defaults are read from the file rather than from the test's own config, so this asserts what SHIPS.
        $shipped = require config_path('odoo.php');

        $this->assertFalse($shipped['enabled']);
        $this->assertFalse($shipped['external_writes_enabled']);
        $this->assertFalse($shipped['draft_creation_enabled']);
        $this->assertFalse($shipped['final_posting_enabled']);
    }

    public function test_odoo_is_not_a_sales_channel_and_cannot_become_one(): void
    {
        // It must never appear as a source of sales or orders. The enum is what the screens, the order importer and the
        // reports read, so its absence from there is the guarantee.
        $this->assertNull(\App\Enums\SalesChannels\SalesChannel::tryFrom('odoo'));
        foreach (\App\Enums\SalesChannels\SalesChannel::cases() as $case) {
            $this->assertNotSame('odoo', $case->value);
        }
    }

    // ---- helpers -------------------------------------------------------------------------------------------------

    private function assertRefused(string $reason, string $operation, string $method, ?SalesChannelAccount $account = null): void
    {
        try {
            $this->http->assertAllowed(
                $account ?? $this->account,
                $operation,
                $method,
                $operation === self::WRITE || str_starts_with($operation, 'odoo.') ? IntegrationCapability::FinancialReconciliation : IntegrationCapability::ProductSync,
                $operation === self::WRITE ? ChannelOperations::SCOPE_ACCOUNTING_POSTING : null,
            );
            $this->fail("{$operation} should have been refused with '{$reason}'");
        } catch (ChannelUnavailableException $exception) {
            $this->assertSame($reason, $exception->reason, $exception->getMessage());
        }
    }

    /**
     * The ACCOUNTING connection, kept apart from any marketing account.
     *
     * It holds only the reconciliation capability and only the posting approval: it cannot publish a price, read an order or
     * create a listing, because none of those is accounting work.
     */
    private function accountingConnection(): SalesChannelAccount
    {
        return SalesChannelAccount::create([
            'channel' => 'odoo',
            'name' => 'Odoo accounting',
            'enabled' => true,
            'write_enabled' => true,
            'dry_run' => false,
            'accounting_destination' => 'odoo',
            'capabilities' => ['connection_test', 'financial_reconciliation'],
            'capability_approvals' => [ChannelOperations::SCOPE_ACCOUNTING_POSTING => ['by' => 1, 'at' => '2026-11-09T00:00:00Z', 'reason' => 'owner approved draft entries']],
        ]);
    }
}
