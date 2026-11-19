<?php

namespace Tests\Feature\Marketplace;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\VendorLedgerEntry;
use App\Models\VendorSettlement;
use App\Services\Marketplace\SettlementEngine;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsWalletAndVendorLedgerSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\Support\WritesSellerLedger;
use Tests\TestCase;

/**
 * The operator's screens of the settlements (Admin\Marketplace\SettlementController and its two templates): the list, one settlement, and the button that calculates.
 *
 * The real controller, routes and templates are used; the admin shell is a stand-in (tests/Support/views), because the real one needs the installation database, and the request middleware that needs it is off.
 * The platform's currency is SAR, printed as the amount to two decimals followed by the symbol "SAR-symbol", which is what setCurrencySymbol() makes of the settings written in setUp.
 *
 * With `gcc.settlements.one_currency` off the screens print and the button draws exactly what they did before the switch existed (the tests named "current behaviour" were written and run green against the
 * screens as they were, and are not an endorsement: every amount is printed as the platform's money, whatever currency it is in).
 */
class SettlementScreensTest extends TestCase
{
    use BuildsWalletAndVendorLedgerSchema;
    use RestoresLanguageFiles;
    use WritesSellerLedger;

    private const NET_OF_EACH_SETTLEMENT = '#<td class="text-end fw-semibold">(.*?)</td>#s';
    private const WAITING = '#<h3 class="mb-0">(.*?)</h3>#s';
    private const FIGURES_OF_THE_SETTLEMENT = '#<h4 class="mb-0">(.*?)</h4>#s';
    private const CREDIT_OF_EACH_ENTRY = '#<td class="text-end text-success">(.*?)</td>#s';
    private const DEBIT_OF_EACH_ENTRY = '#<td class="text-end text-danger">(.*?)</td>#s';
    private const BALANCE_AFTER_EACH_ENTRY = '#<td class="text-end">(.*?)</td>#s';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildVendorSettlementTables();
        $this->useDefaultCurrency(code: 'SAR');
        $this->storeSetting(type: 'decimal_point_settings', value: '2');
        $this->storeSetting(type: 'currency_symbol_position', value: 'right');
        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        $this->withoutMiddleware([
            AdminMiddleware::class, ActivationCheckMiddleware::class, ModulePermissionMiddleware::class, Localization::class, DetectMobile::class,
            ApplySeoRedirects::class, NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @return array<string, array{bool}> */
    public static function switchPositions(): array
    {
        return ['the switch off, as shipped' => [false], 'the switch on' => [true]];
    }

    /** @return list<string> the text of every place of the page the pattern names, in the order of the page */
    private function shown(string $page, string $pattern): array
    {
        preg_match_all($pattern, $page, $found);

        return array_map(static fn (string $text): string => trim(html_entity_decode($text)), $found[1]);
    }

    private function listPage(): string
    {
        return (string) $this->get(route('admin.marketplace.settlements.index'))->assertOk()->getContent();
    }

    private function pageOf(VendorSettlement $settlement): string
    {
        return (string) $this->get(route('admin.marketplace.settlements.show', $settlement->id))->assertOk()->getContent();
    }

    /** @return list<string> */
    private function toasts(): array
    {
        return array_map(static fn (array $toast): string => (string) ($toast['message'] ?? ''), array_values((array) session('laravel-toaster-magic::messages', [])));
    }

    #[DataProvider('switchPositions')]
    public function test_the_screens_of_a_seller_with_one_currency_print_what_they_printed_before(bool $oneCurrency): void
    {
        config(['gcc.settlements.one_currency' => $oneCurrency]);
        $this->earning(amount: 1000.0);
        $this->charge(amount: 100.0);
        $earlier = app(SettlementEngine::class)->calculate(sellerId: self::SELLER);
        $this->earning(amount: 100.5);
        $this->charge(amount: 10.1235);
        $settlement = app(SettlementEngine::class)->calculate(sellerId: self::SELLER);
        $this->earning(amount: 40.0);
        $this->earning(amount: 30.0, changes: ['sellerId' => 8]);

        $list = $this->listPage();
        $page = $this->pageOf($settlement);

        $this->assertSame(['2', '70.00SAR-symbol'], $this->shown($list, self::WAITING));
        $this->assertSame(['90.38SAR-symbol', '900.00SAR-symbol'], $this->shown($list, self::NET_OF_EACH_SETTLEMENT));
        $this->assertSame(['900.00SAR-symbol', '100.50SAR-symbol', '10.12SAR-symbol', '90.38SAR-symbol', '990.38SAR-symbol'], $this->shown($page, self::FIGURES_OF_THE_SETTLEMENT));
        $this->assertSame(['100.50SAR-symbol', '—'], $this->shown($page, self::CREDIT_OF_EACH_ENTRY));
        $this->assertSame(['—', '10.12SAR-symbol'], $this->shown($page, self::DEBIT_OF_EACH_ENTRY));
        $this->assertSame(['1,000.50SAR-symbol', '990.38SAR-symbol'], $this->shown($page, self::BALANCE_AFTER_EACH_ENTRY));
        $this->assertSame(['0.00SAR-symbol', '1,000.00SAR-symbol', '100.00SAR-symbol', '900.00SAR-symbol', '900.00SAR-symbol'], $this->shown($this->pageOf($earlier), self::FIGURES_OF_THE_SETTLEMENT));
    }

    #[DataProvider('switchPositions')]
    public function test_the_list_with_nothing_waiting_prints_a_zero_in_the_platform_currency(bool $oneCurrency): void
    {
        config(['gcc.settlements.one_currency' => $oneCurrency]);

        $this->assertSame(['0', '0.00SAR-symbol'], $this->shown($this->listPage(), self::WAITING));
    }

    public function test_current_behaviour_with_the_switch_off_every_amount_is_printed_as_the_platform_currency_whatever_it_is_in(): void
    {
        $this->earning(amount: 100.5, currency: 'SAR');
        $this->earning(amount: 12.25, currency: 'KWD');
        $this->charge(amount: 10.0, currency: 'SAR');
        $settlement = app(SettlementEngine::class)->calculate(sellerId: self::SELLER);
        $this->earning(amount: 5.5, currency: 'KWD');
        $this->earning(amount: 30.0, currency: 'SAR', changes: ['sellerId' => 8]);

        $list = $this->listPage();
        $page = $this->pageOf($settlement);

        $this->assertSame(['2', '35.50SAR-symbol'], $this->shown($list, self::WAITING));
        $this->assertSame(['102.75SAR-symbol'], $this->shown($list, self::NET_OF_EACH_SETTLEMENT));
        $this->assertSame(['0.00SAR-symbol', '112.75SAR-symbol', '10.00SAR-symbol', '102.75SAR-symbol', '102.75SAR-symbol'], $this->shown($page, self::FIGURES_OF_THE_SETTLEMENT));
        $this->assertSame(['100.50SAR-symbol', '12.25SAR-symbol', '—'], $this->shown($page, self::CREDIT_OF_EACH_ENTRY));
        $this->assertSame(['—', '—', '10.00SAR-symbol'], $this->shown($page, self::DEBIT_OF_EACH_ENTRY));
        $this->assertSame(['100.50SAR-symbol', '112.75SAR-symbol', '102.75SAR-symbol'], $this->shown($page, self::BALANCE_AFTER_EACH_ENTRY));
    }

    public function test_current_behaviour_with_the_switch_off_the_button_draws_one_settlement_for_each_vendor(): void
    {
        $this->earning(amount: 100.5, currency: 'SAR');
        $this->earning(amount: 12.25, currency: 'KWD');
        $this->earning(amount: 30.0, currency: 'SAR', changes: ['sellerId' => 8]);

        $this->post(route('admin.marketplace.settlements.calculate'))->assertRedirect();

        $this->assertSame([7 => 112.75, 8 => 30.0], VendorSettlement::query()->orderBy('seller_id')->pluck('net_amount', 'seller_id')->all());
        $this->assertSame(['2 Settlement(s) calculated and awaiting approval'], $this->toasts());
        $this->assertSame([], $this->unclaimedEntries());
    }

    public function test_current_behaviour_with_the_switch_off_the_button_for_one_vendor_draws_one_settlement_and_leaves_the_others(): void
    {
        $this->earning(amount: 100.5, currency: 'SAR');
        $this->earning(amount: 12.25, currency: 'KWD');
        $this->earning(amount: 30.0, currency: 'SAR', changes: ['sellerId' => 8]);

        $this->post(route('admin.marketplace.settlements.calculate'), ['seller_id' => 7])->assertRedirect();

        $this->assertSame([7 => 112.75], VendorSettlement::query()->pluck('net_amount', 'seller_id')->all());
        $this->assertSame(['1 Settlement(s) calculated and awaiting approval'], $this->toasts());
        $this->assertSame([8], VendorLedgerEntry::query()->whereNull('settlement_id')->pluck('seller_id')->all());
    }

    public function test_the_button_with_nothing_waiting_says_so_and_draws_nothing(): void
    {
        $this->earning(amount: 250.0, changes: ['status' => VendorLedgerEntry::STATUS_PENDING]);

        $this->post(route('admin.marketplace.settlements.calculate'))->assertRedirect();

        $this->assertSame(['Nothing is waiting to be settled'], $this->toasts());
        $this->assertSame(0, VendorSettlement::count());
    }
}
