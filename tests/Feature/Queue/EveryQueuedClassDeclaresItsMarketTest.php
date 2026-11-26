<?php

namespace Tests\Feature\Queue;

use App\Jobs\Middleware\ServeInItsStorefront;
use FilesystemIterator;
use Illuminate\Contracts\Queue\ShouldQueue;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use SplFileInfo;

/**
 * Where every queued class gets its market from — one list, enforced in both directions (M-504, M-768, M-888).
 *
 * The three criteria this closes were all recorded against one measurement: "none of 27 ShouldQueue files mentions a storefront". A count
 * is the wrong shape of answer, because the right number is not 27 -- and the census reads 28 now, because `OrderStatusListener` named the
 * contract nowhere and so was in nobody's 27 while it sent a push inside every order status change. Some of this work belongs to one
 * market and must carry it; some belongs to every market at once and must NOT, because binding the dispatcher's market to a sweep over
 * every country would narrow the very thing it exists to sweep. So the question per class is "where does its market come from", and "from
 * nowhere, and here is why" is one of the lawful answers.
 *
 * The list is enforced rather than asserted. A queued class added to `app/Jobs` or `app/Listeners` fails this test until somebody
 * classifies it, and a class whose declaration stops matching what the class does fails too: an entry claiming a market is built and asked
 * for its queue middleware, and an entry claiming none is built and refused one. That second direction is what a hand-written inventory
 * cannot do on its own — {@see \Tests\Support\StaffJobInventory} said in prose that the five fulfilment jobs "already carry the storefront
 * of the request that queued it" while not one of them did, and nothing failed.
 *
 * Every class is built generically from its constructor's types, so adding a class costs one line here and no factory.
 *
 * **What this test does not prove.** That the market a class binds is the RIGHT one — that is
 * {@see QueuedWorkRunsInTheMarketOfItsRecordTest} and {@see \Tests\Feature\Storefront\QueuedWorkCarriesItsStorefrontTest}. And for the
 * order notifications it reads the source for the one call that binds their market, because that market is bound inside `handle()` rather
 * than in queue middleware; what those listeners actually send is proved by the order-notification tests under tests/Feature/Storefront.
 */
class EveryQueuedClassDeclaresItsMarketTest extends QueuedMarketTestCase
{
    /** It is run in the market of the request that queued it: somebody acting on their own account, in the market they are shopping. */
    private const THE_DISPATCHING_REQUEST = 'the dispatching request';

    /** It is run in the market of the record its payload names, because the process that queued it is serving another market or none. */
    private const THE_RECORD_IT_NAMES = 'the record it names';

    /** It is run in the market the ORDER it is about was sold in, bound inside handle() by OrderNotificationContext. */
    private const THE_ORDER_IT_IS_ABOUT = 'the order it is about';

    /** It belongs to no one market by design. Binding one would narrow a sweep, or claim a market for a recipient nobody chose one for. */
    private const NO_MARKET_OF_ITS_OWN = 'no market of its own';

    private const QUEUED_DIRECTORIES = ['app/Jobs', 'app/Listeners'];

    /** @var array<class-string, array{market: string, reason: string}> */
    private const WHERE_ITS_MARKET_COMES_FROM = [
        \App\Jobs\DeliverSellerWebhook::class => [
            'market' => self::THE_DISPATCHING_REQUEST,
            'reason' => 'POSTs a payload that was already built when the delivery row was written, so nothing market-dependent is rendered in the worker. A seller is not country-owned, and the retry sweep that re-queues a due delivery is a console run belonging to no market, so the answer is null for every delivery the sweep picks up.',
        ],
        \App\Jobs\Fulfillment\CancelProviderFulfillment::class => [
            'market' => self::THE_RECORD_IT_NAMES,
            'reason' => 'One shipment, cancelled from a panel that sees every country or by the recovery sweep. The market is the order\'s.',
        ],
        \App\Jobs\Fulfillment\DispatchProviderFulfillment::class => [
            'market' => self::THE_RECORD_IT_NAMES,
            'reason' => 'As the cancel: the money, the phone rule and the sender of a shipment are the selling market\'s, and the dispatching process is serving none of them.',
        ],
        \App\Jobs\Fulfillment\ProcessProviderEvent::class => [
            'market' => self::THE_RECORD_IT_NAMES,
            'reason' => 'Queued from api/fulfillment-webhooks, which is served across every country on purpose, so the dispatching request can say nothing at all. The event names the shipment, the shipment the order, the order the market.',
        ],
        \App\Jobs\Fulfillment\ReconcileProviderFulfillment::class => [
            'market' => self::THE_RECORD_IT_NAMES,
            'reason' => 'Queued by the recovery pass, which is a console run over every market. The shipment it reconciles belongs to one.',
        ],
        \App\Jobs\Fulfillment\RouteOrderFulfillment::class => [
            'market' => self::THE_RECORD_IT_NAMES,
            'reason' => 'Splits one order into shipments. The order records the market it was sold in; the status change that queued it may have come from any panel.',
        ],
        \App\Jobs\RebuildProductSearchIndex::class => [
            'market' => self::NO_MARKET_OF_ITS_OWN,
            'reason' => 'Rebuilds the index of every product of every country, from the monitoring screen or the weekly schedule. Binding the dispatcher\'s market would narrow the rebuild and leave the index half built; its control is the permission on that screen.',
        ],
        \App\Jobs\RunSellerBulkJob::class => [
            'market' => self::THE_DISPATCHING_REQUEST,
            'reason' => 'A seller\'s own bulk price or stock change, queued from the seller panel in the market they were working in.',
        ],
        \App\Jobs\SalesChannels\DrainStockEvents::class => [
            'market' => self::NO_MARKET_OF_ITS_OWN,
            'reason' => 'Drains the stock outbox for every marketplace channel, queued by the outbox itself when rows appear. The rows are of every market, so narrowing it would leave another market\'s stock unsent.',
        ],
        \App\Jobs\SendEmailJob::class => [
            'market' => self::THE_DISPATCHING_REQUEST,
            'reason' => 'A mailable renders in the worker, so its amounts and links are the market\'s. It carries no record to read a market off. Nothing in this repository dispatches it.',
        ],
        \App\Listeners\AddFundToWalletListener::class => [
            'market' => self::THE_DISPATCHING_REQUEST,
            'reason' => 'Tells one customer their wallet was topped up, in the market the top-up happened in.',
        ],
        \App\Listeners\CustomerRegistrationListener::class => [
            'market' => self::THE_DISPATCHING_REQUEST,
            'reason' => 'Welcomes the customer who has just signed up, in the storefront they signed up on.',
        ],
        \App\Listeners\CustomerStatusUpdateListener::class => [
            'market' => self::NO_MARKET_OF_ITS_OWN,
            'reason' => 'An administrator blocking or unblocking a customer, and the market of the person pressing the button is not the market of the person written to. A customer is one identity across every country (GCC-13), so their account carries no market to read either: there is nothing to bind. The language is still the recipient\'s, through MessageLanguage::asTheRecipientReads, which reads the address rather than the request.',
        ],
        \App\Listeners\DeliverymanPasswordResetListener::class => [
            'market' => self::THE_DISPATCHING_REQUEST,
            'reason' => 'Sends the code one delivery man asked for on their own account, from the app they asked on.',
        ],
        \App\Listeners\DigitalProductDownloadListener::class => [
            'market' => self::THE_ORDER_IT_IS_ABOUT,
            'reason' => 'Sends the download link of one digital line of one order. The order records its market; the point of sale that fired it serves every country.',
        ],
        \App\Listeners\DigitalProductOtpVerificationListener::class => [
            'market' => self::THE_DISPATCHING_REQUEST,
            'reason' => 'Sends the one-time code a customer asked for while downloading what they bought, in the storefront they asked from.',
        ],
        \App\Listeners\EmailVerificationListener::class => [
            'market' => self::THE_DISPATCHING_REQUEST,
            'reason' => 'Verifies the address somebody signed up with, in the storefront they signed up on.',
        ],
        \App\Listeners\MaintenanceModeNotificationListener::class => [
            'market' => self::NO_MARKET_OF_ITS_OWN,
            'reason' => 'Announces maintenance of the whole platform, which is one setting reaching every country. A market\'s own maintenance is a different announcement with a topic of its own (StorefrontPushTopics, StorefrontLifecycle), not this one narrowed.',
        ],
        \App\Listeners\OrderEditDuePaymentListener::class => [
            'market' => self::THE_ORDER_IT_IS_ABOUT,
            'reason' => 'Tells the customer what their edited order still owes, in the money that order was sold in.',
        ],
        \App\Listeners\OrderEditListener::class => [
            'market' => self::THE_ORDER_IT_IS_ABOUT,
            'reason' => 'Tells the customer their order changed, in that order\'s market and language.',
        ],
        \App\Listeners\OrderEditReturnPaymentListener::class => [
            'market' => self::THE_ORDER_IT_IS_ABOUT,
            'reason' => 'Tells the customer what was returned on one order, in that order\'s money.',
        ],
        \App\Listeners\OrderPlacedListener::class => [
            'market' => self::THE_ORDER_IT_IS_ABOUT,
            'reason' => 'Confirms one order. The order is the record, so a confirmation sent from a panel or by a gateway callback still reads the market the order was sold in.',
        ],
        \App\Listeners\OrderStatusListener::class => [
            'market' => self::THE_ORDER_IT_IS_ABOUT,
            'reason' => 'Tells the buyer, the shop or the delivery man that one order changed status, in that order\'s money and language. Fired from 38 places in 6 controllers -- both panels, the seller API v2 and v3 and the delivery-man API -- and a panel sees every market, so the order is the only record that can answer.',
        ],
        \App\Listeners\PasswordResetListener::class => [
            'market' => self::THE_DISPATCHING_REQUEST,
            'reason' => 'Sends the reset link for the account somebody asked about, from the storefront they asked on.',
        ],
        \App\Listeners\RefundListener::class => [
            'market' => self::THE_ORDER_IT_IS_ABOUT,
            'reason' => 'Tells a customer about a refund of one order, in that order\'s money. A refund stays open after a market has stopped selling, so it must not read the market of the request.',
        ],
        \App\Listeners\RequestProductRestockListener::class => [
            'market' => self::THE_DISPATCHING_REQUEST,
            'reason' => 'Records that the customer who asked wants to hear when a product is back, from the storefront they asked in.',
        ],
        \App\Listeners\RestockProductNotificationListener::class => [
            'market' => self::THE_RECORD_IT_NAMES,
            'reason' => 'Tells the customers who asked that one product is back. Stock returning is noticed by ProductService on a seller or admin request bound to no market, and a product belongs to exactly one country, so the product is the record.',
        ],
        \App\Listeners\VendorRegistrationListener::class => [
            'market' => self::THE_DISPATCHING_REQUEST,
            'reason' => 'Welcomes the seller account created on that request, in the market the administrator created it in.',
        ],
    ];

    /** The categories whose classes bind a market through queue middleware, and so can be built and asked for one. */
    private const BOUND_IN_QUEUE_MIDDLEWARE = [self::THE_DISPATCHING_REQUEST, self::THE_RECORD_IT_NAMES];

    /**
     * The census is 28 files, and all 28 classes implement what they name. The three criteria were recorded against 27.
     *
     * The number has moved twice, and the two moves are different in kind, which is why both are written down:
     *
     *  - it read 26 of 27 until `RefundListener` was fixed. That file NAMED the contract and implemented nothing, so the census saw it and
     *    reported the gap -- which is what a census is for;
     *  - it reads 28 of 28 since `OrderStatusListener` was queued, and that one is the opposite case and the worse one. That file named
     *    the contract NOWHERE, so it was never in the 27 at all: a listener sending a push inside every order status change on every
     *    surface, invisible to the one test that counts queued work. A census of the files that mention something cannot see a file that
     *    mentions nothing, and the 27 was never the number of listeners that ought to be queued.
     *
     * The 27 is kept in this docblock rather than in an assertion because that is the figure the three criteria quote, and a reader
     * comparing the register with the code needs to know which number went where.
     */
    public function test_every_file_that_names_the_queue_contract_really_is_queued(): void
    {
        $named = $this->everyClassThatNamesTheQueue();
        $run = $this->everyClassTheQueueRuns();

        $this->assertCount(28, $named, 'the census was 27 files naming ShouldQueue, plus OrderStatusListener, which named it nowhere');
        $this->assertCount(10, array_filter($named, static fn (string $class): bool => str_starts_with($class, 'App\\Jobs\\')));
        $this->assertCount(18, array_filter($named, static fn (string $class): bool => str_starts_with($class, 'App\\Listeners\\')));
        $this->assertCount(28, $run, 'a file that names the queue contract and does not implement it is not queued at all');
    }

    /**
     * Naming the queue contract and implementing it is one claim, and this test is the only thing that joins them.
     *
     * `RefundListener` imported `ShouldQueue` and `InteractsWithQueue`, used neither, and so was NOT queued: telling a customer or a seller
     * about a refund sent a push -- preceded, once an hour, by an OAuth round trip to Google -- inside the request that recorded the refund
     * decision. Every neighbouring mail listener had been queued for exactly that fault, and `CustomerStatusUpdateListener`'s own comment
     * justified its queueing by saying it "follows RefundListener, which this project already runs on the queue", which was false.
     *
     * What this test asserted while the defect stood, kept so the fix is legible:
     *
     *     $this->assertSame(['App\\Listeners\\RefundListener'], array_values(array_diff($named, $run)), ...);
     *     $this->assertFalse((new ReflectionClass(RefundListener::class))->implementsInterface(ShouldQueue::class), ...);
     *
     * Both are now the other way round, and the set is asserted in BOTH directions so the next file to import the contract without
     * implementing it is reported by name rather than by a count that happens to still add up.
     *
     * That the refund notification really waits on the queue, rather than merely declaring that it does, is
     * {@see \Tests\Feature\Notifications\RefundNotificationWaitsOnTheQueueTest}: this test reads a declaration, that one drives the event.
     *
     * `OrderStatusListener` was the same fault one step further on: it named the contract NOWHERE, so this test's own scan could not see it
     * and the census it belongs in was never wrong by its own arithmetic. Both are asserted below, because a listener that is missing from
     * a list of files that mention a thing is the one shape a mention-scan cannot report.
     */
    public function test_no_file_names_the_queue_contract_without_implementing_it(): void
    {
        $this->assertSame(
            [],
            array_values(array_diff($this->everyClassThatNamesTheQueue(), $this->everyClassTheQueueRuns())),
            'this file names ShouldQueue and does not implement it, so nothing it does is queued',
        );

        $this->assertTrue(
            (new ReflectionClass(\App\Listeners\RefundListener::class))->implementsInterface(ShouldQueue::class),
            'the refund notification is no longer sent inside the request that recorded the refund',
        );
        $this->assertSame(1, (new \App\Listeners\RefundListener())->tries, 'a push has no idempotency key, so a worker retry would send a second one');

        // And the listener the census could not see at all, which named the contract nowhere while sending a push inside every order
        // status change. `config/queue.php` sets `after_commit` on no connection, so the property is the only thing that keeps a
        // notification out of a transaction that may yet roll back; that it really does is
        // {@see \Tests\Feature\Notifications\OrderStatusNotificationWaitsOnTheQueueTest}, which drives the event.
        $this->assertTrue(
            (new ReflectionClass(\App\Listeners\OrderStatusListener::class))->implementsInterface(ShouldQueue::class),
            'an order status notification is no longer sent inside the request that changed the status',
        );
        $this->assertSame(1, (new \App\Listeners\OrderStatusListener())->tries, 'one status change must not become three pushes on a worker retry');
        $this->assertTrue((new \App\Listeners\OrderStatusListener())->afterCommit, 'and must not be sent for a status change that rolls back');
    }

    /**
     * `SendEmailJob` says in its own declaration above that nothing in this repository dispatches it. Nothing enforced that, and a claim of
     * unreachability is exactly the kind that rots: the first caller to arrive would make the sentence false and nothing would notice.
     *
     * It is pinned RATHER than wired, because wiring it would bypass four mechanisms that were each built on purpose. The real mail path is
     * `EmailTemplateTrait::sendingMail` -> `MessageLanguage::asTheRecipientReads` -> `writeAndSendMail` -> `SendsWithinABudget::send` ->
     * `MailAccount::forThisMessage` -> `StorefrontCurrency::withinOrderCurrency` -> `Mail::to()->send(new SendMail(...))`. All of that runs
     * inside the already-queued listener, and a Mailable is RENDERED at `send()` time. So moving the send into this job would resolve the
     * subject, the body, the money and the sending account in a SECOND worker that carries only `storefrontCountry` -- losing the
     * recipient's language (I1-03), the market's own mail account (I1-01) and the order's currency, and recording the attempt
     * (`NotificationAttemptLog`, I1-09) against the queueing rather than against the send, which is the accounting the empty catch block
     * was fixed to provide. A second queue hop would also mean a mail waiting on two workers instead of one.
     *
     * So the decision is the owner's, and until it is taken the class stays unreachable and that is now a fact a test holds. Deleting it is
     * the other option and is not taken here either: the queued-class count above is what three criteria are measured against, and
     * {@see \Tests\Support\StaffJobInventory} names this class.
     */
    public function test_nothing_in_the_application_dispatches_the_one_queued_class_declared_unreachable(): void
    {
        $this->assertStringContainsString(
            'Nothing in this repository dispatches it.',
            self::WHERE_ITS_MARKET_COMES_FROM[\App\Jobs\SendEmailJob::class]['reason'],
            'this test enforces that sentence, so it has to still be the claim',
        );

        $mentions = [];

        foreach (['app', 'Modules', 'database', 'routes', 'config'] as $directory) {
            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());

                if ($file->getExtension() !== 'php' || $path === 'app/Jobs/SendEmailJob.php') {
                    continue;
                }

                // Comments are stripped, so a file that EXPLAINS why it does not use this job does not read as a caller: the same rule the
                // TLS and secret scanners of this suite follow, and the fourth time in this programme that a scan read its own explanation.
                if (str_contains($this->withoutComments((string) file_get_contents($file->getPathname())), 'SendEmailJob')) {
                    $mentions[] = $path;
                }
            }
        }

        sort($mentions);

        $this->assertSame(
            [],
            $mentions,
            'SendEmailJob is declared unreachable: a caller makes that declaration false and raises the language, account, currency and attempt-log questions in this test\'s docblock',
        );
    }

    /** PHP's own tokeniser rather than a regular expression, so a string containing `//` is not mistaken for a comment. */
    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            $code .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1]) : $token;
        }

        return $code;
    }

    public function test_every_queued_class_says_where_its_market_comes_from(): void
    {
        $undeclared = array_diff($this->everyClassThatNamesTheQueue(), array_keys(self::WHERE_ITS_MARKET_COMES_FROM));
        $gone = array_diff(array_keys(self::WHERE_ITS_MARKET_COMES_FROM), $this->everyClassThatNamesTheQueue());

        $this->assertSame([], array_values($undeclared), 'a queued class has to say where its market comes from: add it to WHERE_ITS_MARKET_COMES_FROM');
        $this->assertSame([], array_values($gone), 'a declaration naming a class that is no longer queued is stale');
    }

    public function test_every_declaration_names_a_category_and_gives_a_reason(): void
    {
        $categories = [self::THE_DISPATCHING_REQUEST, self::THE_RECORD_IT_NAMES, self::THE_ORDER_IT_IS_ABOUT, self::NO_MARKET_OF_ITS_OWN];

        foreach (self::WHERE_ITS_MARKET_COMES_FROM as $class => $declared) {
            $this->assertContains($declared['market'], $categories, "{$class} names a category that does not exist");
            $this->assertGreaterThan(60, strlen($declared['reason']), "{$class} needs a reason a person can disagree with, not a label");
        }
    }

    /**
     * The half a prose inventory cannot do: a class that says it binds a market is built and asked for one, with the switches ON, and a
     * class that says it binds none is refused one.
     */
    public function test_a_class_that_claims_a_market_binds_one_and_a_class_that_claims_none_does_not(): void
    {
        $this->switchOnTheMarketOfTheRecord();

        foreach (self::WHERE_ITS_MARKET_COMES_FROM as $class => $declared) {
            if ($declared['market'] === self::THE_ORDER_IT_IS_ABOUT) {
                continue;
            }

            $binds = $this->bindsAMarketInItsQueueMiddleware($class);

            in_array($declared['market'], self::BOUND_IN_QUEUE_MIDDLEWARE, true)
                ? $this->assertTrue($binds, "{$class} says its market comes from {$declared['market']} and its queue middleware binds none")
                : $this->assertFalse($binds, "{$class} says it has no market of its own and its queue middleware binds one");
        }
    }

    /**
     * The order notifications bind their market inside `handle()`, where no queue middleware can be read, so the one call that does it is
     * read from the source. It is the mechanism and not a hint: OrderNotificationContext::within is what puts the order's market, money,
     * language and selling company in force together.
     */
    public function test_every_listener_about_an_order_binds_that_orders_market_through_the_one_service_that_does_it(): void
    {
        $about = array_keys(array_filter(
            self::WHERE_ITS_MARKET_COMES_FROM,
            static fn (array $declared): bool => $declared['market'] === self::THE_ORDER_IT_IS_ABOUT,
        ));

        $this->assertNotEmpty($about);

        foreach ($about as $class) {
            $source = (string) file_get_contents((new ReflectionClass($class))->getFileName());

            $this->assertStringContainsString(
                'OrderNotificationContext::within(',
                $source,
                "{$class} says its market is the order's and does not read the order's market",
            );
        }
    }

    /** Whether this class's queue middleware puts a market in force. Built from the constructor's own types, so a new class needs no factory. */
    private function bindsAMarketInItsQueueMiddleware(string $class): bool
    {
        $reflection = new ReflectionClass($class);

        if (!$reflection->hasMethod('middleware')) {
            return false;
        }

        $instance = $reflection->newInstanceArgs($this->argumentsFor($reflection));
        $event = (object) ['data' => []];
        $middleware = $reflection->getMethod('middleware')->getNumberOfParameters() > 0
            ? $instance->middleware($event)
            : $instance->middleware();

        foreach ($middleware as $one) {
            if ($one instanceof ServeInItsStorefront) {
                return true;
            }
        }

        return false;
    }

    /**
     * Values for the constructor's required parameters, by type. Every queued class of this platform takes scalars or nothing, and an
     * optional parameter is left to its default, so one rule builds all of them; a class that takes something else fails here and has to
     * be looked at rather than guessed about.
     *
     * @return list<mixed>
     */
    private function argumentsFor(ReflectionClass $reflection): array
    {
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return [];
        }

        return array_map(function (ReflectionParameter $parameter) use ($reflection): mixed {
            $type = $parameter->getType();
            $name = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';

            return match ($name) {
                'int' => 1,
                'string' => 'a',
                'bool' => false,
                'float' => 1.0,
                'array' => [],
                'mixed', 'null' => null,
                default => $this->fail("{$reflection->getName()} takes a {$name}, which this test cannot build: say how, rather than guessing."),
            };
        }, array_filter($constructor->getParameters(), static fn (ReflectionParameter $parameter): bool => !$parameter->isOptional()));
    }

    /** @return list<class-string> every class under app/Jobs and app/Listeners whose file names the queue contract: the criteria's 27 and one more */
    private function everyClassThatNamesTheQueue(): array
    {
        $classes = [];

        foreach (self::QUEUED_DIRECTORIES as $directory) {
            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php' || !str_contains((string) file_get_contents($file->getPathname()), 'ShouldQueue')) {
                    continue;
                }

                $class = 'App\\' . str_replace([base_path('app') . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, '.php'], ['', '\\', ''], $file->getPathname());

                if (class_exists($class)) {
                    $classes[] = $class;
                }
            }
        }

        sort($classes);

        return $classes;
    }

    /** @return list<class-string> the subset the queue really runs: those that implement the contract rather than only naming it */
    private function everyClassTheQueueRuns(): array
    {
        return array_values(array_filter(
            $this->everyClassThatNamesTheQueue(),
            static fn (string $class): bool => (new ReflectionClass($class))->implementsInterface(ShouldQueue::class),
        ));
    }
}
