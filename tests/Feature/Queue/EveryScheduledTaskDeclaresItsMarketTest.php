<?php

namespace Tests\Feature\Queue;

use App\Console\ScheduleDefinition;
use App\Services\Monitoring\Collectors\SchedulerCollector;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\Support\KeepsTranslateOffLanguageFiles;
use Tests\TestCase;

/**
 * Where every scheduled task gets its market from — one list, enforced, the way {@see EveryQueuedClassDeclaresItsMarketTest} is for the
 * queue (the open half of M-888, M2-18).
 *
 * The criterion was recorded against a measurement — "`ScheduleDefinition` holds 45 entries classified by nothing" — and the previous
 * round declined to classify them, because four of the entries belong to axes it had not read and "a declaration written from a reading of
 * what the code MEANT is a hypothesis dressed as a record". That reason was right, and the answer to it is to read those four rather than
 * to leave the list unwritten: `theme:publish-due`, `commerce:campaigns-tick`, `myfatoorah:reconcile` and `monitoring:evaluate` are read
 * here and each declaration below cites the line that decides it.
 *
 * A schedule declared platform-wide is a lawful answer — most of these are — but it has to be DECLARED, and the declaration has to be
 * enforced rather than asserted. So, as with the queue list:
 *
 * - an entry added to `ScheduleDefinition` fails this test until somebody classifies it, and a declaration naming an entry that is no
 *   longer scheduled fails too;
 * - a declaration claiming the market comes from a record has to name the mechanism that reads it, read out of the source of the thing
 *   that does the reading rather than out of this file;
 * - **a declaration may say the market is MISSING**, which is what {@see self::A_RECORDED_GAP} is for. The set of gaps is pinned exactly,
 *   so it can neither grow unnoticed nor shrink without the proof being brought here. A register that can only say "fine" is not a
 *   register.
 *
 * Why this lives beside the queue list rather than in a directory of its own: it asks the same question of the same kind of unattended
 * work, it cross-checks the queue list's own declarations (the fulfillment sweep's answer is only true because four jobs are declared
 * `the record it names` over there), and `tests/Feature/Queue` is already named in a real-engine CI group — a new directory would be
 * invisible to the real engines until `.github/workflows/ci.yml` named it, which is a shared file every track conflicts in.
 *
 * **What this test does not prove.** That a platform-wide sweep is the RIGHT answer for a given task: that is a judgement, and each
 * declaration's reason is written so it can be disagreed with. And nothing here runs a scheduled task: `schedule:run` on a real clock is
 * the server's, not a test's.
 */
class EveryScheduledTaskDeclaresItsMarketTest extends TestCase
{
    use KeepsTranslateOffLanguageFiles;

    /** It sweeps the whole platform on purpose. Narrowing it to one market would leave the other five markets' work undone. */
    private const EVERY_MARKET_AT_ONCE = 'every market at once';

    /** It sweeps rows of every market, and each unit of work it starts takes the market of the row it is for. */
    private const THE_RECORDS_IT_SWEEPS = 'the records it sweeps';

    /** It runs once across every market and asks each market's own switches as it goes. */
    private const EACH_MARKETS_OWN_SWITCHES = "each market's own switches";

    /** Nothing it touches carries a market, and nothing in it is read per market, so there is nothing to narrow. */
    private const THE_DATA_HAS_NO_MARKET = 'the data has no market';

    /** The market matters here and this entry has none. Declared, counted and pinned — not quietly filed as platform-wide. */
    private const A_RECORDED_GAP = 'a recorded gap';

    /**
     * The three entries `ScheduleDefinition` registers only while a switch is on, both of which ship OFF. They are the difference between
     * the 42 entries a default install runs and the 45 the criterion counted.
     */
    private const CONDITIONAL_ENTRIES = ['costs:refresh --days=3', 'costs:accrue-storage', 'myfatoorah:reconcile'];

    /** @var array<string, array{market: string, reason: string}> keyed by the task name the monitoring page shows */
    private const WHERE_ITS_MARKET_COMES_FROM = [
        'analytics:rollup' => [
            'market' => self::A_RECORDED_GAP,
            'reason' => 'Folds raw analytics events into analytics_daily, which has no market column at all. Its `country` dimension is the VISITOR\'s geography (VisitorContext::country reads the request), not the storefront the visit was served from, so a shopper in Kuwait on the Saudi storefront is counted under KW — which looks exactly like a market dimension and is not one. An operator arranging one storefront reads these figures, so every one of them is a platform total presented as that market\'s.',
        ],
        'analytics:rollup --days=2 --prune' => [
            'market' => self::A_RECORDED_GAP,
            'reason' => 'The nightly rebuild of the same rollup, with the raw rows past retention deleted. It inherits the gap above exactly, and the pruning half makes it permanent: once the raw events are gone, a market dimension can no longer be derived for those days even after the column exists.',
        ],
        'api:snapshot' => [
            'market' => self::THE_DATA_HAS_NO_MARKET,
            'reason' => 'Captures the shape of the API surface — routes, parameters, responses — and diffs it against the last snapshot. One deployment serves every market from one set of routes, so there is no per-market surface to snapshot; a country path, if gcc.storefronts.paths.enabled is ever turned on, would be a prefix on the same routes.',
        ],
        'cart:remind-abandoned' => [
            'market' => self::EACH_MARKETS_OWN_SWITCHES,
            'reason' => 'Sweeps every market\'s abandoned carts in one pass and drops the candidates of a market that may not be marketed to, through AbandonedCartService::withoutStoppedStorefronts and StorefrontMarketingStop::stoppedCartGroups. The market also decides the unsubscribe link, which the command reads per reminder rather than per run.',
        ],
        'cart:remind-abandoned --stage=2' => [
            'market' => self::EACH_MARKETS_OWN_SWITCHES,
            'reason' => 'The second-touch reminder of the same sweep, through the same service and the same per-market marketing stop. A market stopped between the two stages is dropped from the second, which is the point of asking per run rather than per campaign.',
        ],
        'channels:amazon-reports' => [
            'market' => self::THE_RECORDS_IT_SWEEPS,
            'reason' => 'Polls and reads the Amazon report documents already requested, account by account. A sales-channel account carries its own market in its settings (ChannelSettings::MARKET = storefront_country), so the market comes from the account whose report is being read, not from the process reading it.',
        ],
        'channels:amazon-reports --discover' => [
            'market' => self::THE_RECORDS_IT_SWEEPS,
            'reason' => 'Asks Amazon what reports it holds for each account — the only way a settlement report is reached, since Amazon schedules those itself. Per account, so per that account\'s own market, exactly as the polling run above.',
        ],
        'channels:decisions' => [
            'market' => self::THE_RECORDS_IT_SWEEPS,
            'reason' => 'Carries out the return decisions and package splits a person recorded on a screen. Each decision names the channel order it is about, and that order belongs to one account, which carries its own market; a write happens only where that account already allows one.',
        ],
        'channels:dispatch' => [
            'market' => self::THE_RECORDS_IT_SWEEPS,
            'reason' => 'Brings dispatched channel orders in step with their provider shipment. Both ends name a record — the channel order and the provider fulfillment — and each of those belongs to one account and one order, which is where the market is.',
        ],
        'channels:dispatch-sync' => [
            'market' => self::THE_RECORDS_IT_SWEEPS,
            'reason' => 'The same work as channels:dispatch run in the foreground rather than in the background, for a host with no worker. Same records, same accounts, same markets: the two entries exist because of how they are run, not because they read anything different.',
        ],
        'channels:find-shipments' => [
            'market' => self::THE_RECORDS_IT_SWEEPS,
            'reason' => 'Looks for the provider shipment of channel orders whose shipping the provider arranges directly and links the ones it can prove. The proof is per order, and an order belongs to one account and one market.',
        ],
        'channels:prune' => [
            'market' => self::EVERY_MARKET_AT_ONCE,
            'reason' => 'Deletes old sales-channel API call logs and the raw text of old webhook deliveries. Retention is one platform-wide policy and the rows are housekeeping, not commerce; pruning only one market\'s logs would leave the other five growing without limit and is not what any retention setting asks for.',
        ],
        'channels:publish --only=allocate' => [
            'market' => self::THE_RECORDS_IT_SWEEPS,
            'reason' => 'Divides the stock pools whose channel orders changed and sends what lowers a quantity. A pool is keyed by location, product, variant AND market (PoolKey carries a market; InventoryPool.storefront_country), so the division is per market by construction — a sweep narrowed to one market would simply stop dividing the others.',
        ],
        'channels:publish --only=settle' => [
            'market' => self::THE_RECORDS_IT_SWEEPS,
            'reason' => 'Settles what a channel reported against the account it reported for. Money, so the account is the only honest source of the market and the currency: a settlement read in the market of the process would be read in no market at all, because this is a console run.',
        ],
        'channels:publish --only=stock,price,shipments' => [
            'market' => self::THE_RECORDS_IT_SWEEPS,
            'reason' => 'Publishes confirmed stock, prices and shipments to each account that allows a write. The account carries its market and its own write switch, and the environment write switch gates the whole command, so nothing is sent for a market whose account is not set up.',
        ],
        'channels:purge-buyer-data' => [
            'market' => self::EVERY_MARKET_AT_ONCE,
            'reason' => 'Removes the buyer details of dispatched channel orders once the provider holds the shipment. A data-minimisation duty applies to every market the platform holds data in, and running it for one market would leave five markets\' buyer details in place — the opposite of what the duty asks.',
        ],
        'channels:stock-events' => [
            'market' => self::EVERY_MARKET_AT_ONCE,
            'reason' => 'Drains the stock outbox: the net beneath the queued DrainStockEvents job, and the whole of that work on a host with no worker. The outbox holds the rows of every market, and that job is declared `no market of its own` for exactly this reason — narrowing the drain would leave another market\'s stock unsent.',
        ],
        'channels:sync --due' => [
            'market' => self::THE_RECORDS_IT_SWEEPS,
            'reason' => 'Reads orders, listings, channel-held stock and statements from each account that is due. Read-only, per account, and each account honours its own switches and carries its own market; an account of a market nobody has configured is simply not due.',
        ],
        'commerce:campaigns-tick' => [
            'market' => self::EVERY_MARKET_AT_ONCE,
            'reason' => 'Tidies campaign statuses and flushes the delivery caches at the window transition. It sweeps every market because ExperienceCampaign\'s storefront_visibility global scope is inert in a console process (StorefrontCatalog narrows nothing while runningInConsole), and CampaignResolver::forget deliberately clears the key of every storefront plus the unbound one. The serve path obeys the window rather than the status, so a market this missed still ends its campaign on time.',
        ],
        'commerce:metrics-refresh' => [
            'market' => self::A_RECORDED_GAP,
            'reason' => 'Recomputes the per-product engagement summary that dynamic collections rank by, and reads views_30d and carted_30d out of analytics_daily. So it inherits the analytics gap above and turns it into a RANKING: a product\'s position in a market\'s collection is computed from every market\'s views of it.',
        ],
        'costs:accrue-storage' => [
            'market' => self::THE_RECORDS_IT_SWEEPS,
            'reason' => 'Records what it cost to keep stock at each provider warehouse on a day, location by location, from that location\'s own contract. A warehouse holds the boxes of one country, and the cost is read from the contract of that location rather than from a platform rate. Ships off: both OPERATIONAL_COSTS_ENABLED and OPERATIONAL_COSTS_SCHEDULE_ENABLED are needed to register it at all.',
        ],
        'costs:refresh --days=3' => [
            'market' => self::THE_RECORDS_IT_SWEEPS,
            'reason' => 'Brings the cost ledger of recently placed or changed sales up to date, sale by sale, and never rewrites an approved entry. The sale is the record; the operational-costs base currency being SAR is a Saudi assumption recorded against axis L (M2-19) rather than something this sweep decides. Ships off, as above.',
        ],
        'fulfillment:poll' => [
            'market' => self::THE_RECORDS_IT_SWEEPS,
            'reason' => 'Asks each provider where its open fulfillments stand — the net under a missed webhook. A provider connection carries the catalogue country it works against (ProviderStorefrontCountry, B-05) and each shipment names its order, so neither the asking nor the answering reads the market of the process.',
        ],
        'fulfillment:prune' => [
            'market' => self::EVERY_MARKET_AT_ONCE,
            'reason' => 'Deletes old provider API call logs and what providers sent that is no longer needed, keeping the fulfillment history itself. Retention is one platform-wide policy over housekeeping rows, as with channels:prune, and a per-market prune would leave five markets\' logs growing.',
        ],
        'fulfillment:recover' => [
            'market' => self::THE_RECORDS_IT_SWEEPS,
            'reason' => 'The safety net under every event-driven fulfillment path: it finds work by STATE across every market and queues it. Each of the four jobs it can queue — RouteOrderFulfillment, DispatchProviderFulfillment, ReconcileProviderFulfillment, CancelProviderFulfillment — is declared `the record it names` in the queue list, so the market of each unit of work is the order\'s, whatever the sweep was serving.',
        ],
        'fulfillment:sync --only=cities' => [
            'market' => self::THE_RECORDS_IT_SWEEPS,
            'reason' => 'Pulls each provider\'s city list. Cities are mapped to the central address tables through provider_city_mappings per connection, and a connection serves one catalogue country, so the pull belongs to that connection rather than to the weekly run that started it.',
        ],
        'fulfillment:sync --only=products,inventory --due' => [
            'market' => self::THE_RECORDS_IT_SWEEPS,
            'reason' => 'Pulls provider products and stock for the connections whose own interval says they are due. A connection whose settings name a country the platform has no storefront for serves no catalogue of ours and is matched against nothing (ProviderStorefrontCountry::servesNoCatalogueOfOurs), which is the whole reason the connection and not the process is the source of the market.',
        ],
        'marketplace:evaluate-sla' => [
            'market' => self::EVERY_MARKET_AT_ONCE,
            'reason' => 'Evaluates seller SLA thresholds into the breach ledger. A seller is one account for the whole platform rather than a country-owned record (the same reading that makes DeliverSellerWebhook\'s market always null), so a breach is the seller\'s and the thresholds are the marketplace\'s; narrowing it would mean a seller had six SLA records and no answer to "is this shop in breach".',
        ],
        'marketplace:settle --release' => [
            'market' => self::THE_RECORDS_IT_SWEEPS,
            'reason' => 'Matures vendor earnings out of the return window and rolls up settlements. It is already per currency rather than per platform: a run draws one settlement for each currency a vendor has money waiting in and both reports name the currency of every line instead of adding two (SettlementCurrency, gcc.settlements.one_currency). Nothing is paid — settlements land `calculated` for a person to approve.',
        ],
        'monitoring:evaluate' => [
            'market' => self::THE_DATA_HAS_NO_MARKET,
            'reason' => 'Compares the monitoring alert rules against what was measured this minute. What is measured is one server — request counters, queue depth, gauges — and one server serves every market, so these rules have no market to be narrowed to. The per-market alerts an operator wants (stock_zero, orders_pending_too_long, legal_not_ready) are a different chain that already exists per market in the platform-admin overview.',
        ],
        'monitoring:flush' => [
            'market' => self::THE_DATA_HAS_NO_MARKET,
            'reason' => 'Drains the request counters web requests have been incrementing and samples the system gauges. The collection heartbeat of the monitoring module: if it stops, the dashboard reports that monitoring has gone blind rather than showing stale numbers as current. Server-wide measurements, so there is nothing per market in them.',
        ],
        'monitoring:rollup' => [
            'market' => self::THE_DATA_HAS_NO_MARKET,
            'reason' => 'Folds monitoring minutes into hours and days. The same server-wide series as the flush above, at a coarser resolution; a rollup cannot acquire a dimension the series it folds does not have.',
        ],
        'monitoring:rollup --prune' => [
            'market' => self::THE_DATA_HAS_NO_MARKET,
            'reason' => 'The nightly fold plus the deletion of rows past each resolution\'s retention. Retention is one platform-wide policy over server-wide series, so neither half has a market.',
        ],
        'myfatoorah:reconcile' => [
            'market' => self::THE_RECORDS_IT_SWEEPS,
            'reason' => 'Asks MyFatoorah about every payment attempt still in flight and settles the ones that turn out to be paid, through the SAME success hook a webhook uses — and that hook is wrapped in PaymentStorefront::within, which puts back the market the payment was started in and restores what the process held afterwards. So a recovered Kuwaiti payment builds a Kuwaiti order even though the sweep is bound to no market. Ships off: MYFATOORAH_ENABLED and MYFATOORAH_SCHEDULE_ENABLED are both needed to register it.',
        ],
        'scheduler-heartbeat' => [
            'market' => self::THE_DATA_HAS_NO_MARKET,
            'reason' => 'Writes the last scheduler run time into business_settings so the dashboard can warn when the server cron has stopped firing. One cron drives one scheduler for the whole deployment, so there is exactly one such fact; six per-market heartbeats would be six readings of the same cron.',
        ],
        'search:reindex-products' => [
            'market' => self::EVERY_MARKET_AT_ONCE,
            'reason' => 'Reconciles the storefront search index against the whole catalogue, for the case where a bulk import bypassed the model observer. The queued RebuildProductSearchIndex is declared `no market of its own` for the same reason: narrowing the rebuild would leave the index half built. What makes that safe is that a SEARCH is narrowed at read time instead — StorefrontCatalog::restrictProductIds runs before the 500-id cap (ProductSearchService::baseQuery).',
        ],
        'seller:escalate-issues' => [
            'market' => self::EVERY_MARKET_AT_ONCE,
            'reason' => 'Raises the severity of seller issues nobody has answered. Escalation measures how long something has stood, which is a property of the issue and not of a market, and the issue belongs to a seller account that is not country-owned.',
        ],
        'seller:refresh-insights' => [
            'market' => self::EVERY_MARKET_AT_ONCE,
            'reason' => 'Recomputes what each seller should be looking at — stock about to run out, an order approaching its deadline. Computed per seller, and a seller sees their whole shop rather than one market of it, so the answer is the seller\'s across every market their products are in.',
        ],
        'seller:retry-webhooks' => [
            'market' => self::EVERY_MARKET_AT_ONCE,
            'reason' => 'Re-queues the seller webhook deliveries that are due for another attempt. Nothing is rendered at delivery: the body POSTed is the payload stored when the event happened, which is why DeliverSellerWebhook is declared to carry the dispatching market and that market is in practice always null — honest, and not useful until somebody decides whether a seller webhook belongs to a market at all.',
        ],
        'seller:run-automation' => [
            'market' => self::EVERY_MARKET_AT_ONCE,
            'reason' => 'Asks which seller automation rules are due and evaluates them. A rule is the seller\'s and its cooldown is measured against this sweep\'s fifteen minutes; two sweeps are kept from acting on one listing twice by withoutOverlapping rather than by any market boundary.',
        ],
        'seller:run-stuck-bulk-jobs' => [
            'market' => self::A_RECORDED_GAP,
            'reason' => 'Runs the bulk jobs no worker picked up, and runs them by calling SellerBulkJobService::run in the scheduler\'s own process — the same call RunSellerBulkJob::handle makes, but without the ServeInItsStorefront middleware that job carries. So the market a seller\'s bulk price change was entered in is honoured when a worker exists and lost when one does not, which is precisely the state this sweep exists for. Closing it needs the market on the seller_bulk_jobs row: the payload that carried it is in the queue table this sweep never reads.',
        ],
        'telemetry:rollup' => [
            'market' => self::THE_DATA_HAS_NO_MARKET,
            'reason' => 'Compresses raw request telemetry into daily rollups. Request-level measurements of one deployment — latency, status codes, throughput — which belong to the server rather than to any market whose page happened to be served.',
        ],
        'telemetry:rollup --date=yesterday' => [
            'market' => self::THE_DATA_HAS_NO_MARKET,
            'reason' => 'Yesterday, once it is over: the hourly run only ever covers the current day, so its last pass left the final fifty-three minutes in no daily row at all. Same server-wide series, same absence of a market, and the entry exists to close a hole in time rather than in space.',
        ],
        'telemetry:rollup --prune' => [
            'market' => self::THE_DATA_HAS_NO_MARKET,
            'reason' => 'Deletes raw telemetry rows past the retention window. One platform-wide retention policy over the server-wide series above; there is no market whose telemetry could be kept longer than another\'s.',
        ],
        'theme:publish-due' => [
            'market' => self::EVERY_MARKET_AT_ONCE,
            'reason' => 'Publishes the theme versions whose scheduled moment has come, validating each the way a manual publish is validated. It asks StorefrontThemeResolver::keepsThemesByStorefront, which is the per-storefront theme feature and ships dormant, so today every theme is the platform\'s one theme and every due version is published. The question re-opens the day that switch goes on, which is why the entry reads the resolver rather than assuming a single theme.',
        ],
    ];

    /** The categories whose entries must name, in the source of the thing that does it, the mechanism that reads a record's market. */
    private const MUST_NAME_THE_MECHANISM = [
        'cart:remind-abandoned' => [\App\Services\Retention\AbandonedCartService::class, 'withoutStoppedStorefronts'],
        'cart:remind-abandoned --stage=2' => [\App\Services\Retention\AbandonedCartService::class, 'withoutStoppedStorefronts'],
        'myfatoorah:reconcile' => ['app/Utils/module-helper.php', 'PaymentStorefront::within('],
        'commerce:campaigns-tick' => [\App\Services\Commerce\CampaignResolver::class, 'cacheKeysForEveryStorefront'],
        'theme:publish-due' => [\App\Console\Commands\ThemePublishDue::class, 'keepsThemesByStorefront'],
        'search:reindex-products' => [\App\Services\Search\ProductSearchService::class, 'StorefrontCatalog::restrictProductIds'],
    ];

    /** The jobs `fulfillment:recover` queues, each of which the queue list has to declare as taking its market from its own record. */
    private const QUEUED_BY_THE_FULFILLMENT_SWEEP = [
        \App\Jobs\Fulfillment\RouteOrderFulfillment::class,
        \App\Jobs\Fulfillment\DispatchProviderFulfillment::class,
        \App\Jobs\Fulfillment\ReconcileProviderFulfillment::class,
        \App\Jobs\Fulfillment\CancelProviderFulfillment::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->keepTranslateOffLanguageFiles();
    }

    protected function tearDown(): void
    {
        $this->assertLanguageFilesUntouched();
        app()->forgetInstance(StorefrontContext::class);

        parent::tearDown();
    }

    /**
     * The 45 the criterion counted is the whole set INCLUDING three entries that two switches keep out of a default install, and a default
     * install runs 42. Both numbers are pinned, because reporting the schedule as 45 entries describes a deployment nobody has.
     */
    public function test_the_schedule_is_42_entries_as_shipped_and_45_with_its_three_conditional_entries_on(): void
    {
        $this->assertCount(42, $this->scheduledTaskNames(), 'the entries a default install registers');

        $this->switchOnTheConditionalEntries();
        $withSwitchesOn = $this->scheduledTaskNames();

        $this->assertCount(45, $withSwitchesOn, 'the whole set, which is what the criterion counted');
        $this->assertSame(
            self::CONDITIONAL_ENTRIES,
            array_values(array_diff($withSwitchesOn, $this->scheduledTaskNamesWithTheSwitchesAsShipped())),
            'exactly these three entries are registered only while a switch is on',
        );
    }

    public function test_every_scheduled_entry_says_where_its_market_comes_from(): void
    {
        $this->switchOnTheConditionalEntries();

        $undeclared = array_diff($this->scheduledTaskNames(), array_keys(self::WHERE_ITS_MARKET_COMES_FROM));
        $gone = array_diff(array_keys(self::WHERE_ITS_MARKET_COMES_FROM), $this->scheduledTaskNames());

        $this->assertSame([], array_values($undeclared), 'a scheduled entry has to say where its market comes from: add it to WHERE_ITS_MARKET_COMES_FROM');
        $this->assertSame([], array_values($gone), 'a declaration naming an entry that is no longer scheduled is stale');
    }

    public function test_every_declaration_names_a_category_and_gives_a_reason(): void
    {
        $categories = [
            self::EVERY_MARKET_AT_ONCE, self::THE_RECORDS_IT_SWEEPS, self::EACH_MARKETS_OWN_SWITCHES,
            self::THE_DATA_HAS_NO_MARKET, self::A_RECORDED_GAP,
        ];

        foreach (self::WHERE_ITS_MARKET_COMES_FROM as $task => $declared) {
            $this->assertContains($declared['market'], $categories, "{$task} names a category that does not exist");
            $this->assertGreaterThan(120, strlen($declared['reason']), "{$task} needs a reason a person can disagree with, not a label");
        }
    }

    /**
     * The premise every `every market at once` declaration rests on: the process that runs the schedule is bound to no market, so a sweep
     * really does see all six. If anything ever bound the default storefront in a console process, 13 declarations here would silently
     * become narrowed sweeps while still reading as platform-wide.
     */
    public function test_a_scheduled_run_is_a_console_process_bound_to_no_market(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true]);

        $schedule = new Schedule();
        ScheduleDefinition::define($schedule);

        $this->assertFalse(app()->bound(StorefrontContext::class), 'defining the schedule must not bind a storefront');
        $this->assertNull(StorefrontCatalog::country(), 'a console process is narrowed to no market, which is what lets a sweep see every market');

        // And the other direction, so the assertion above is not true merely because storefronts resolve to nothing at all.
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->contextOfRecordedCountry(countryCode: 'KW'));
        $this->assertSame('KW', StorefrontCatalog::country(), 'a bound context is readable, so the null above is the console rule and not a dead resolver');
    }

    /**
     * A scheduled entry naming a command this application does not register is a task that fails silently every time the clock comes round,
     * for as long as nobody reads the scheduler page — and the market classification of such an entry would be a classification of nothing.
     */
    public function test_every_scheduled_command_is_a_command_this_application_registers(): void
    {
        $this->switchOnTheConditionalEntries();
        $registered = Artisan::all();
        $missing = [];

        foreach ($this->scheduledTaskNames() as $task) {
            // The heartbeat is a closure rather than a command, and is named by its own ->name() call.
            if ($task === 'scheduler-heartbeat') {
                continue;
            }

            if (!isset($registered[explode(' ', $task)[0]])) {
                $missing[] = $task;
            }
        }

        $this->assertSame([], $missing, 'a scheduled entry naming an unregistered command fails every run and reports nothing');
    }

    /**
     * No scheduled command accepts a market today, which is why every declaration above is about where the market comes from IMPLICITLY.
     * The day a command takes one, this fails and the entry's declaration has to be re-read — a command that can be pointed at a market is
     * a command that could be registered once per market, which is a different schedule from this one.
     */
    public function test_no_scheduled_command_takes_a_market_option_yet(): void
    {
        $this->switchOnTheConditionalEntries();
        $registered = Artisan::all();
        $withAMarketOption = [];

        foreach ($this->scheduledTaskNames() as $task) {
            $command = $registered[explode(' ', $task)[0]] ?? null;

            if ($command === null) {
                continue;
            }

            foreach (['country', 'storefront', 'market'] as $option) {
                if ($command->getDefinition()->hasOption($option)) {
                    $withAMarketOption[] = "{$task} --{$option}";
                }
            }
        }

        $this->assertSame(
            [],
            $withAMarketOption,
            'a scheduled command that takes a market can be registered per market: re-read its declaration in WHERE_ITS_MARKET_COMES_FROM',
        );
    }

    /**
     * The half a prose inventory cannot do: an entry whose declaration names a mechanism is checked against the SOURCE of the thing that
     * does the reading, not against this file. Take the call out of that file and the entry's claim fails here.
     */
    public function test_every_entry_that_claims_a_mechanism_names_it_in_the_source_that_does_the_reading(): void
    {
        foreach (self::MUST_NAME_THE_MECHANISM as $task => [$where, $needle]) {
            $path = str_ends_with($where, '.php') ? base_path($where) : (string) (new ReflectionClass($where))->getFileName();

            $this->assertStringContainsString(
                $needle,
                (string) file_get_contents($path),
                "{$task} says its market is read by {$needle}, and {$where} does not do that",
            );
        }
    }

    /**
     * The fulfillment sweep's answer is only true because of four declarations in another file. Cross-checked rather than repeated: if one
     * of those jobs is ever reclassified, the sweep's claim that each unit of work takes the order's market stops holding and this says so.
     */
    public function test_the_fulfillment_sweeps_claim_rests_on_four_jobs_the_queue_list_declares_as_taking_their_records_market(): void
    {
        $this->assertSame(
            self::THE_RECORDS_IT_SWEEPS,
            self::WHERE_ITS_MARKET_COMES_FROM['fulfillment:recover']['market'],
        );

        $queueList = (string) file_get_contents(
            (string) (new ReflectionClass(EveryQueuedClassDeclaresItsMarketTest::class))->getFileName(),
        );

        foreach (self::QUEUED_BY_THE_FULFILLMENT_SWEEP as $job) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($job, '/') . '::class => \[\s*\n\s*\'market\' => self::THE_RECORD_IT_NAMES,/',
                $queueList,
                "fulfillment:recover queues {$job} and says the order's market is used; the queue list must declare that job the same way",
            );
        }
    }

    /**
     * The gaps, pinned exactly. A register that can only say "fine" is not a register, and a gap that is merely mentioned in prose is a
     * gap that quietly becomes four. Adding one fails this; closing one fails this too, which is the moment to bring the evidence here.
     */
    public function test_the_entries_whose_market_is_missing_are_exactly_these_four(): void
    {
        $this->switchOnTheConditionalEntries();

        $gaps = array_keys(array_filter(
            self::WHERE_ITS_MARKET_COMES_FROM,
            static fn (array $declared): bool => $declared['market'] === self::A_RECORDED_GAP,
        ));
        sort($gaps);

        $this->assertSame(
            ['analytics:rollup', 'analytics:rollup --days=2 --prune', 'commerce:metrics-refresh', 'seller:run-stuck-bulk-jobs'],
            $gaps,
        );

        // Every gap is really scheduled: a gap recorded against an entry nobody runs is not a gap.
        foreach ($gaps as $gap) {
            $this->assertContains($gap, $this->scheduledTaskNames(), "{$gap} is declared a gap and is not scheduled");
        }
    }

    /**
     * The stuck-bulk-job gap, reproduced from the two call sites rather than described.
     *
     * `RunSellerBulkJob::handle` and `RunStuckSellerBulkJobs::handle` make the same call — `SellerBulkJobService::run($job)` — and only the
     * job carries `ServeInItsStorefront`. So a seller's bulk price change is applied in the market they entered it in when a worker exists,
     * and in no market when the scheduler picks it up instead. It is PINNED rather than fixed, because binding a market here needs the
     * market on the `seller_bulk_jobs` row (a schema change on a money path), and because making the two agree changes what a bulk price
     * change means on the fallback path — a decision, not a repair.
     */
    public function test_the_stuck_bulk_job_sweep_runs_the_same_work_the_queued_job_binds_a_market_for_and_binds_none(): void
    {
        $job = new ReflectionClass(\App\Jobs\RunSellerBulkJob::class);
        $sweep = new ReflectionClass(\App\Console\Commands\RunStuckSellerBulkJobs::class);

        $jobSource = (string) file_get_contents((string) $job->getFileName());
        $sweepSource = (string) file_get_contents((string) $sweep->getFileName());

        foreach ([$jobSource, $sweepSource] as $source) {
            $this->assertStringContainsString('->run($job)', $source, 'both paths run the bulk job through the same service call');
        }

        $this->assertStringContainsString('CarriesItsStorefront', $jobSource, 'the queued path carries the market of the request that queued it');
        $this->assertStringNotContainsString('Storefront', $sweepSource, 'the scheduled path reads no market at all, which is the gap');

        $this->assertTrue(
            $job->newInstance(1)->middleware() !== [],
            'the queued path has queue middleware; the command has none, because a command is not a job',
        );
    }

    /**
     * The monitoring page and this register must describe the same schedule. The page enumerates the definition the same way, so a drift
     * between them means one of the two is reading a schedule the server does not run.
     */
    public function test_the_monitoring_page_and_this_register_name_the_same_tasks(): void
    {
        $this->switchOnTheConditionalEntries();

        $collected = app(SchedulerCollector::class)->collect();
        $onThePage = array_column((array) $collected['tasks']->value, 'task');
        sort($onThePage);

        $declared = array_keys(self::WHERE_ITS_MARKET_COMES_FROM);
        sort($declared);

        $this->assertSame($declared, $onThePage, 'the scheduler page and this market register must enumerate one schedule');
    }

    private function switchOnTheConditionalEntries(): void
    {
        config([
            'operational_costs.enabled' => true,
            'operational_costs.schedule_enabled' => true,
            'myfatoorah.enabled' => true,
            'myfatoorah.schedule_enabled' => true,
        ]);
    }

    private function scheduledTaskNamesWithTheSwitchesAsShipped(): array
    {
        config(['operational_costs.enabled' => false, 'myfatoorah.enabled' => false]);

        return $this->scheduledTaskNames();
    }

    /**
     * Every entry the definition registers right now, named the way the monitoring collector names it.
     *
     * Built by applying the definition to a throwaway Schedule and asking each event, rather than by reading the file for strings: an
     * entry inside an `if` is only there when its switch is on, and the option string a task is distinguished by is assembled by Laravel.
     *
     * @return list<string>
     */
    private function scheduledTaskNames(): array
    {
        $schedule = new Schedule();
        ScheduleDefinition::define($schedule);

        return array_map(static function (Event $event): string {
            $command = (string) ($event->command ?? '');

            if ($command === '') {
                return (string) $event->description;
            }

            return trim(str_replace("'", '', preg_replace("/^.*'artisan'\s*/", '', $command) ?? $command));
        }, $schedule->events());
    }
}
