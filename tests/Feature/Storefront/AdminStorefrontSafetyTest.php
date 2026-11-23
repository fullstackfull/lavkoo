<?php

namespace Tests\Feature\Storefront;

use App\Models\StorefrontLegalDocument;
use App\Services\Storefront\StorefrontLegalPublisher;
use App\Services\Storefront\StorefrontLifecycle;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What keeps the storefront screens safe when something goes wrong or someone does too much. A failure of the database is not a refusal of a service, and its message holds the statement
 * and the values bound to it: it must reach neither the toast nor the page, and the step must leave nothing behind. Every route that changes something has a throttle bucket of its own
 * family, and exceeding one answers 429 without touching the others. The routes, the role check, the requests, the services and the exception handler are the real ones.
 */
class AdminStorefrontSafetyTest extends AdminStorefrontScreenTestCase
{
    private const REASON = 'Decision of the launch meeting';

    private const WORDS = '<p>The terms of Kuwait.</p>';

    /** The throttle bucket of each route that changes something under admin/storefronts: one family for the lifecycle, one for writing a text and one for approving it. */
    private const BUCKET_OF_EACH_STEP = [
        'admin.storefronts.ready' => 'storefront-lifecycle',
        'admin.storefronts.activate' => 'storefront-lifecycle',
        'admin.storefronts.pause' => 'storefront-lifecycle',
        'admin.storefronts.resume' => 'storefront-lifecycle',
        'admin.storefronts.legal-texts.draft' => 'storefront-legal-write',
        'admin.storefronts.legal-texts.new-version' => 'storefront-legal-write',
        'admin.storefronts.legal-texts.edit' => 'storefront-legal-write',
        'admin.storefronts.legal-texts.discard' => 'storefront-legal-write',
        'admin.storefronts.legal-texts.approve' => 'storefront-legal-approve',
        'admin.storefronts.legal-texts.publish' => 'storefront-legal-approve',
        'admin.storefronts.legal-texts.revoke' => 'storefront-legal-approve',
    ];

    /** The attempts a minute each family allows: the number is part of what keeps a screen from being hammered, so a change of it is a change of this test. */
    private const ATTEMPTS_A_MINUTE = ['storefront-lifecycle' => 30, 'storefront-legal-write' => 60, 'storefront-legal-approve' => 30];

    // ---- a failure of the database is not a refusal ------------------------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function stepsOfBothScreens(): array
    {
        return array_map(static fn (string $route): array => [$route], array_combine(array_keys(self::BUCKET_OF_EACH_STEP), array_keys(self::BUCKET_OF_EACH_STEP)));
    }

    #[DataProvider('stepsOfBothScreens')]
    public function test_a_failure_of_the_database_in_any_step_is_an_error_page_that_shows_neither_the_statement_nor_its_values_and_leaves_nothing_behind(string $route): void
    {
        [$admin, $url, $form] = $this->theRequestOf($route);
        $this->signInAs($admin);
        config(['app.debug' => false, 'logging.default' => 'null']);
        $failure = $this->theDatabaseFailsOnTheNextWriteOfTheStorefrontTables();
        $before = $this->whatIsKept();

        $response = $this->post($url, $form);

        $response->assertStatus(500);
        $this->assertNotNull($failure->exception, 'the step reached the database');
        $valuesOfTheStatement = array_filter($failure->exception->getBindings(), static fn (mixed $value): bool => is_string($value) && strlen($value) >= 12);
        foreach ([$failure->exception->getMessage(), $failure->exception->getSql(), $failure->exception->getPrevious()->getMessage(), 'SQLSTATE', 'Deadlock', ...$valuesOfTheStatement] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent(), "the error page shows '{$leak}'");
            $this->assertStringNotContainsString($leak, $this->toasts(), "a toast shows '{$leak}'");
        }
        $this->assertSame('', $this->toasts(), 'a failure of the database is not shown as a refusal');
        $this->assertSame($before, $this->whatIsKept(), 'the step left nothing behind: no row changed and no audit line was written');
    }

    /** What the steps write: the legal texts, the lifecycle records and the audit log. */
    private function whatIsKept(): array
    {
        $rowsOf = static fn ($query): array => $query->get()->map(static fn (object $row): array => (array) $row)->all();

        return [
            $rowsOf(DB::table('storefront_legal_documents')->orderBy('id')),
            $rowsOf(DB::table('business_settings')->where('type', 'like', 'storefront_%')->orderBy('type')),
            $rowsOf(DB::table('audit_logs')->orderBy('id')),
        ];
    }

    /**
     * The first insert or update into the tables the screens write fails the way a deadlock does: with the statement, the values bound to it and the driver's words in the message. The
     * object that is returned holds that exception once it was thrown.
     */
    private function theDatabaseFailsOnTheNextWriteOfTheStorefrontTables(): object
    {
        $failure = (object) ['exception' => null];

        DB::beforeExecuting(function (string $statement, array $bindings) use ($failure): void {
            $isAWrite = preg_match('/^\s*(insert|update)\b/i', $statement) === 1;
            $isOfTheStorefrontTables = str_contains($statement, 'storefront_legal_documents') || str_contains($statement, 'business_settings');

            if ($isAWrite && $isOfTheStorefrontTables && $failure->exception === null) {
                $failure->exception = new QueryException(
                    connectionName: 'mysql',
                    sql: $statement,
                    bindings: $bindings,
                    previous: new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'),
                );

                throw $failure->exception;
            }
        });

        return $failure;
    }

    /**
     * The admin, the address and the form of one step, and the state it needs, built the way the screens build it (through the services) before the failure is armed.
     *
     * @return array{int, string, array<string, mixed>}
     */
    private function theRequestOf(string $route): array
    {
        $writer = self::LEGAL_WRITER;
        $approver = self::LEGAL_APPROVER;

        return match ($route) {
            'admin.storefronts.legal-texts.draft' => [$writer, route($route, 'KW'), ['document_type' => 'terms', 'locale' => 'en', 'template' => '<p>TEMPLATE: terms.</p>']],
            'admin.storefronts.legal-texts.edit' => [$writer, route($route, $this->aDraft()->id), ['body' => self::WORDS]],
            'admin.storefronts.legal-texts.discard' => [$writer, route($route, $this->aDraft()->id), ['reason' => 'Wrong template']],
            'admin.storefronts.legal-texts.approve' => [$approver, route($route, $text = $this->anEditedDraft()->id), ['body_hash' => $this->hashOf($text)]],
            'admin.storefronts.legal-texts.publish' => [$approver, route($route, $text = $this->anApprovedText()->id), ['body_hash' => $this->hashOf($text)]],
            'admin.storefronts.legal-texts.revoke' => [$approver, route($route, $this->aPublishedText()->id), ['reason' => 'A clause is wrong']],
            'admin.storefronts.legal-texts.new-version' => [$writer, route($route, $this->aPublishedText()->id), []],
            'admin.storefronts.ready' => [self::LIFECYCLE_ADMIN, route($route, $this->aStorefrontIn(StorefrontLifecycle::DRAFT)), ['reason' => self::REASON]],
            'admin.storefronts.activate' => [self::LIFECYCLE_ADMIN, route($route, $this->aStorefrontIn(StorefrontLifecycle::READY)), ['reason' => self::REASON, 'reference' => 'Minutes of the launch meeting']],
            'admin.storefronts.pause' => [self::LIFECYCLE_ADMIN, route($route, $this->aStorefrontIn(StorefrontLifecycle::ACTIVE)), ['mode' => StorefrontLifecycle::BROWSE_ONLY, 'reason' => self::REASON]],
            'admin.storefronts.resume' => [self::LIFECYCLE_ADMIN, route($route, $this->aStorefrontIn(StorefrontLifecycle::PAUSED)), ['reason' => self::REASON]],
        };
    }

    private function publisher(): StorefrontLegalPublisher
    {
        return app(StorefrontLegalPublisher::class);
    }

    private function hashOf(int $textId): string
    {
        return (string) StorefrontLegalDocument::query()->findOrFail($textId)->body_hash;
    }

    private function aDraft(): StorefrontLegalDocument
    {
        return $this->publisher()->draftFromTemplate(country: 'KW', documentType: 'terms', locale: 'en', template: '<p>TEMPLATE: terms.</p>', author: self::LEGAL_WRITER);
    }

    private function anEditedDraft(): StorefrontLegalDocument
    {
        $draft = $this->aDraft();

        return $this->publisher()->edit(textId: $draft->id, body: self::WORDS, editor: self::LEGAL_WRITER);
    }

    private function anApprovedText(): StorefrontLegalDocument
    {
        $draft = $this->anEditedDraft();

        return $this->publisher()->approve(textId: $draft->id, approver: self::LEGAL_APPROVER);
    }

    private function aPublishedText(): StorefrontLegalDocument
    {
        $approved = $this->anApprovedText();

        return $this->publisher()->publish(textId: $approved->id, publisher: self::LEGAL_APPROVER);
    }

    /** A storefront in the state a step needs; its address. */
    private function aStorefrontIn(string $state, string $country = 'KW'): string
    {
        $lifecycle = app(StorefrontLifecycle::class);
        $this->meetThePrerequisitesOf($country);
        $stepsToGetThere = [
            fn () => $lifecycle->markReady(country: $country, by: 'The head of the market', reason: self::REASON),
            fn () => $lifecycle->activate(country: $country, by: 'The head of the market', reference: 'Minutes of the launch meeting', reason: self::REASON),
            fn () => $lifecycle->pause(country: $country, mode: StorefrontLifecycle::BROWSE_ONLY, by: 'The head of the market', reason: self::REASON),
        ];

        foreach (array_slice($stepsToGetThere, 0, (int) array_search($state, [StorefrontLifecycle::DRAFT, StorefrontLifecycle::READY, StorefrontLifecycle::ACTIVE, StorefrontLifecycle::PAUSED], true)) as $step) {
            $step();
        }

        return $country;
    }

    // ---- every route that changes something is throttled --------------------------------------------------------------------------------------

    public function test_every_route_that_changes_something_under_the_storefront_screens_has_the_throttle_bucket_of_its_family(): void
    {
        $changing = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RegisteredRoute $route): bool => str_starts_with($route->uri(), 'admin/storefronts') && array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()) !== [])
            ->mapWithKeys(fn (RegisteredRoute $route): array => [(string) $route->getName() => $route]);

        $this->assertEqualsCanonicalizing(array_keys(self::BUCKET_OF_EACH_STEP), $changing->keys()->all(), 'a route that changes something was added or removed: name its bucket here');

        foreach ($changing as $name => $route) {
            $throttles = array_values(array_filter($route->gatherMiddleware(), static fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle:')));

            $this->assertCount(1, $throttles, "{$name} has one throttle middleware");
            $bucket = self::BUCKET_OF_EACH_STEP[$name];
            $this->assertSame('throttle:' . self::ATTEMPTS_A_MINUTE[$bucket] . ",1,{$bucket}", $throttles[0], "{$name} allows the attempts a minute of its family, in the bucket of its family");
        }
    }

    public function test_exceeding_a_bucket_answers_429_and_the_other_buckets_are_not_touched(): void
    {
        $this->signInAs(self::MASTER);

        for ($attempt = 1; $attempt <= self::ATTEMPTS_A_MINUTE['storefront-lifecycle']; $attempt++) {
            $this->post(route('admin.storefronts.resume', 'KW'), ['reason' => self::REASON])->assertRedirect();
        }
        $this->assertToastContains('only a paused storefront can be resumed');

        $this->post(route('admin.storefronts.resume', 'KW'), ['reason' => self::REASON])->assertStatus(429);
        $this->post(route('admin.storefronts.pause', 'KW'), ['mode' => StorefrontLifecycle::BROWSE_ONLY, 'reason' => self::REASON])->assertStatus(429);

        $this->post(route('admin.storefronts.legal-texts.draft', 'KW'), ['document_type' => 'terms', 'locale' => 'en', 'template' => '<p>TEMPLATE: terms.</p>'])->assertRedirect();
        $this->assertSame(1, StorefrontLegalDocument::query()->count(), 'the bucket of writing a text is not the bucket of the lifecycle');
    }
}
