<?php

namespace Tests\Feature\Storefront;

use App\Models\Product;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontCatalogueReadiness;
use App\Services\Storefront\StorefrontPreflight;
use App\Services\Theme\StorefrontThemeReadiness;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsThemeWorld;
use Tests\TestCase;

/**
 * I2-12's last criterion, the two prerequisites it named that nothing checked: a market has a catalogue, and its pages render.
 *
 * **What the preflight asked, and what it did not.** It asked a market about its legal texts, their independent review, its currency, its
 * selling company, its tax and -- once D2-05 landed -- its gateway. A market with all six and an EMPTY CATALOGUE opens and sells nothing:
 * every page answers an empty list, and the only thing that reports it is a customer. A market with no published theme is the same failure
 * one layer out.
 *
 * **Both ship OFF**, for the reason `needs_a_payment_account` ships off: what "ready to sell" includes beyond the legal texts is the
 * owner's open decision on D1-16, and a market must not be refused by a prerequisite nobody has been asked about. The first two tests are
 * that claim, and they are the ones that matter most -- a prerequisite switched on by accident refuses every market that sells today.
 *
 * **And a defect found while wiring them, which is why the report is read from the preflight's own list.** `gcc:storefront:state status`
 * printed its lines from a list of its own that named five of the six prerequisites, so a market blocked on its payment account was
 * reported as BLOCKED with every line it printed reading "ok" -- an operator told to fix something and shown nothing to fix.
 *
 * Engine: SQLite (PHP 8.3.6) and MariaDB 10.11.14 (`phpunit.mysql.xml`, database `pharmacy_testing_id`).
 */
class AMarketIsNotReadyWithoutACatalogueOrAThemeTest extends TestCase
{
    use BuildsThemeWorld;

    private const MARKET = 'KW';

    private const MARKET_CURRENCY = 'KWD';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTheProductsTable();
        $this->buildTheSettingsTable();
        $this->buildThemeWorld();
        $this->forgetWhatTheThemeResolverLearnt();
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();
        Schema::dropIfExists('products');

        parent::tearDown();
    }

    // ---- both ship off ----------------------------------------------------------------------------------------------------------------

    public function test_both_checks_ship_off_so_a_market_with_no_product_and_no_theme_is_not_refused_for_either(): void
    {
        self::assertFalse((bool) config('gcc.storefronts.sale_gate.needs_a_catalogue'), 'the catalogue check ships off');
        self::assertFalse((bool) config('gcc.storefronts.sale_gate.needs_a_theme'), 'the theme check ships off');

        self::assertSame(StorefrontCatalogueReadiness::NOT_ASKED, app(StorefrontCatalogueReadiness::class)->reasonFor(self::MARKET));
        self::assertSame(StorefrontThemeReadiness::NOT_ASKED, app(StorefrontThemeReadiness::class)->reasonFor(self::MARKET));

        $unmet = $this->whatTheMarketIsMissing();
        self::assertNotContains(StorefrontPreflight::CATALOGUE, $unmet, 'a market was refused for a record nobody has been asked for');
        self::assertNotContains(StorefrontPreflight::THEME, $unmet);
    }

    public function test_with_them_off_neither_check_asks_the_database_anything(): void
    {
        $this->theMarketHasOneSellableProduct();
        $queries = $this->queriesOf(function (): void {
            app(StorefrontCatalogueReadiness::class)->isReady(self::MARKET);
            app(StorefrontThemeReadiness::class)->isReady(self::MARKET);
        });

        self::assertSame([], $queries, 'while they are off each costs one config read and nothing else');
    }

    // ---- the catalogue ----------------------------------------------------------------------------------------------------------------

    public function test_a_market_with_no_product_of_its_own_is_not_ready(): void
    {
        $this->askForACatalogue();
        $this->productOf(country: 'AE', status: 1);

        self::assertSame(StorefrontCatalogueReadiness::NO_SELLABLE_PRODUCT, app(StorefrontCatalogueReadiness::class)->reasonFor(self::MARKET));
        self::assertContains(StorefrontPreflight::CATALOGUE, $this->whatTheMarketIsMissing(), 'another market\'s catalogue is not this market\'s');
    }

    public function test_a_market_whose_every_product_is_switched_off_is_not_ready_either(): void
    {
        $this->askForACatalogue();
        $this->productOf(country: self::MARKET, status: 0);

        self::assertSame(StorefrontCatalogueReadiness::NO_SELLABLE_PRODUCT, app(StorefrontCatalogueReadiness::class)->reasonFor(self::MARKET));
        self::assertSame(0, app(StorefrontCatalogueReadiness::class)->sellableProductCountOf(self::MARKET));
    }

    public function test_one_product_a_customer_of_the_market_could_buy_is_enough(): void
    {
        $this->askForACatalogue();
        $this->theMarketHasOneSellableProduct();

        self::assertSame(StorefrontCatalogueReadiness::READY, app(StorefrontCatalogueReadiness::class)->reasonFor(self::MARKET));
        self::assertSame(1, app(StorefrontCatalogueReadiness::class)->sellableProductCountOf(self::MARKET));
        self::assertNotContains(StorefrontPreflight::CATALOGUE, $this->whatTheMarketIsMissing());
    }

    public function test_before_the_catalogue_migration_has_run_every_market_has_the_one_catalogue_and_is_ready(): void
    {
        $this->askForACatalogue();
        Schema::table('products', static fn (Blueprint $table) => $table->dropColumn(StorefrontCatalog::COLUMN));
        StorefrontCatalog::flush();

        self::assertSame(
            StorefrontCatalogueReadiness::ONE_CATALOGUE_FOR_THE_PLATFORM,
            app(StorefrontCatalogueReadiness::class)->reasonFor(self::MARKET),
            'the Saudi platform of today: one catalogue, so the question has no per-market answer to give',
        );
        self::assertNotContains(StorefrontPreflight::CATALOGUE, $this->whatTheMarketIsMissing());
        self::assertNull(app(StorefrontCatalogueReadiness::class)->sellableProductCountOf(self::MARKET));
    }

    // ---- the theme --------------------------------------------------------------------------------------------------------------------

    public function test_a_market_whose_theme_has_no_published_version_is_not_ready(): void
    {
        $this->askForATheme();
        $this->switchStorefrontThemesOn();
        $this->giveTheme(name: 'Kuwaiti', country: self::MARKET, active: true);

        self::assertSame(StorefrontThemeReadiness::NO_PUBLISHED_THEME, app(StorefrontThemeReadiness::class)->reasonFor(self::MARKET));
        self::assertContains(StorefrontPreflight::THEME, $this->whatTheMarketIsMissing());
    }

    public function test_a_market_served_the_default_storefronts_published_theme_is_ready(): void
    {
        $this->askForATheme();
        $this->switchStorefrontThemesOn();
        $saudi = $this->giveTheme(name: 'Saudi', country: 'SA', active: true);
        $this->givePublishedVersion(theme: $saudi, revision: 1, spacerHeight: 10);

        // The resolver falls back to the default storefront's published theme, so this market really does render. Refusing it would
        // refuse every market that has not been given a design of its own, which is every market this platform has.
        self::assertSame(StorefrontThemeReadiness::READY, app(StorefrontThemeReadiness::class)->reasonFor(self::MARKET));
        self::assertNotContains(StorefrontPreflight::THEME, $this->whatTheMarketIsMissing());
    }

    public function test_the_market_is_ready_on_its_own_published_theme(): void
    {
        $this->askForATheme();
        $this->switchStorefrontThemesOn();
        $kuwaiti = $this->giveTheme(name: 'Kuwaiti', country: self::MARKET, active: true);
        $this->givePublishedVersion(theme: $kuwaiti, revision: 4, spacerHeight: 12);

        self::assertSame(StorefrontThemeReadiness::READY, app(StorefrontThemeReadiness::class)->reasonFor(self::MARKET));
    }

    public function test_the_readiness_is_the_renderers_own_answer_and_not_a_second_opinion(): void
    {
        $this->askForATheme();
        $this->openEveryStorefront();
        $this->switchStorefrontThemesOn();
        $kuwaiti = $this->giveTheme(name: 'Kuwaiti', country: self::MARKET, active: true);
        $this->givePublishedVersion(theme: $kuwaiti, revision: 4, spacerHeight: 12);

        $this->serveRequestFromStorefront(country: self::MARKET);

        self::assertSame(
            app(\App\Services\Theme\StorefrontThemeResolver::class)->publishedVersion()?->id,
            app(\App\Services\Theme\StorefrontThemeResolver::class)->publishedVersionServedBy(storefront: self::MARKET)?->id,
            'the check asks the same question the renderer answers, so it cannot call a market ready that renders nothing',
        );
    }

    public function test_while_themes_are_not_kept_per_storefront_the_one_platform_theme_answers_for_every_market(): void
    {
        $this->askForATheme();
        $platformTheme = $this->giveTheme(name: 'Platform', country: null, active: true);
        $this->givePublishedVersion(theme: $platformTheme, revision: 1, spacerHeight: 8);

        self::assertFalse((bool) config('gcc.storefronts.themes.enabled'));
        self::assertSame(StorefrontThemeReadiness::READY, app(StorefrontThemeReadiness::class)->reasonFor(self::MARKET));
    }

    // ---- the report cannot leave a prerequisite out again -----------------------------------------------------------------------------

    /**
     * **One teeth check here came back INERT, and it is written in rather than left as false coverage.** Making the status loop read
     * `HOW_TO_MEET` again -- the defect itself -- fails NOTHING, because the two lists now hold the same eight prerequisites and either
     * order prints the same set. So the loop's source is not what keeps a prerequisite from going unprinted: the assertion below that the
     * two lists agree is, and the loop reading the preflight's own list is the belt-and-braces half (a prerequisite with no words is
     * printed, named, rather than silently left out). Measured, not assumed.
     */
    public function test_every_prerequisite_the_preflight_asks_about_is_one_the_report_names(): void
    {
        $prerequisites = app(StorefrontPreflight::class)->prerequisites();

        self::assertSame([
            StorefrontPreflight::LEGAL_TEXTS,
            StorefrontPreflight::LEGAL_REVIEW,
            StorefrontPreflight::CURRENCY,
            StorefrontPreflight::LEGAL_ENTITY,
            StorefrontPreflight::TAX,
            StorefrontPreflight::PAYMENT_ACCOUNT,
            StorefrontPreflight::CATALOGUE,
            StorefrontPreflight::THEME,
        ], $prerequisites, 'a prerequisite added without a line in the report is how one went unprinted for a whole release');

        $howToMeet = $this->instructionsOfTheStatusCommand();

        foreach ($prerequisites as $prerequisite) {
            self::assertArrayHasKey($prerequisite, $howToMeet, "gcc:storefront:state status has no instruction for {$prerequisite}");
        }
    }

    // ---- the world --------------------------------------------------------------------------------------------------------------------

    private function whatTheMarketIsMissing(): array
    {
        return app(StorefrontPreflight::class)->unmet(country: self::MARKET, currencyCode: self::MARKET_CURRENCY);
    }

    private function askForACatalogue(): void
    {
        config(['gcc.storefronts.sale_gate.needs_a_catalogue' => true]);
    }

    private function askForATheme(): void
    {
        config(['gcc.storefronts.sale_gate.needs_a_theme' => true]);
    }

    private function theMarketHasOneSellableProduct(): void
    {
        $this->productOf(country: self::MARKET, status: 1);
    }

    private function productOf(string $country, int $status): void
    {
        Product::query()->withoutGlobalScopes()->insert([
            'name' => "Product of {$country}",
            'slug' => 'product-of-' . strtolower($country) . '-' . $status,
            'added_by' => 'admin',
            'product_type' => 'physical',
            'status' => $status,
            StorefrontCatalog::COLUMN => $country,
        ]);
    }

    /** The settings the other prerequisites of the preflight read, so that asking it about a market reaches the two under test. */
    private function buildTheSettingsTable(): void
    {
        if (Schema::hasTable('business_settings')) {
            return;
        }

        Schema::create('business_settings', static function (Blueprint $table): void {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });
    }

    /** The products table as this check reads it: the storefront column and the four the active scope asks about. */
    private function buildTheProductsTable(): void
    {
        Schema::dropIfExists('products');
        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('added_by')->nullable();
            $table->string('product_type')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->integer('status')->default(1);
            $table->integer('request_status')->default(1);
            $table->string(StorefrontCatalog::COLUMN, 2)->nullable();
            $table->timestamps();
        });
        StorefrontCatalog::flush();

        // `Product::active()` asks whether a seller product's shop is approved, which is exactly why the check reuses that scope rather
        // than counting rows -- so the table it joins has to be here.
        if (!Schema::hasTable('sellers')) {
            Schema::create('sellers', static function (Blueprint $table): void {
                $table->id();
                $table->string('status')->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }
    }

    /** @return array<string, string> the instructions `gcc:storefront:state status` prints, read out of its own source */
    private function instructionsOfTheStatusCommand(): array
    {
        $reflection = new \ReflectionClass(\App\Console\Commands\StorefrontLifecycleCommand::class);

        return $reflection->getConstant('HOW_TO_MEET');
    }

    /** @return list<string> the statements a closure runs */
    private function queriesOf(callable $work): array
    {
        $statements = [];
        \Illuminate\Support\Facades\DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $work();

        return $statements;
    }
}
