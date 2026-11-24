<?php

namespace Tests\Feature\StaffScope;

use Tests\Support\StaffJobInventory;
use Tests\Support\StaffJobScanner;
use Tests\TestCase;

/**
 * G-11: every queued job, queued listener and bulk route of the admin side is classified, and a new one fails the build.
 *
 * A country scope that stops at the controller is not a scope: a queue worker is a console process with no request and no employee, so
 * work an employee starts runs with no scope unless it carries one. This walks app/Jobs, app/Listeners and the real route table, and
 * fails for anything StaffJobInventory does not account for -- so adding a job that reads a list across countries, or a bulk route that
 * writes one, is a decision somebody has to write down rather than a thing that happens.
 *
 * It says what is classified, not that every classification is right: that each entry's reason is true is a person's reading, and the two
 * entries reached through a service rather than a controller say so themselves.
 */
class StaffJobInventoryTest extends TestCase
{
    private StaffJobScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scanner = new StaffJobScanner();
    }

    public function test_every_queued_job_and_listener_is_classified(): void
    {
        $unclassified = array_values(array_diff(array_keys($this->scanner->queuedClasses()), array_keys(StaffJobInventory::QUEUED)));

        $this->assertSame([], $unclassified, "Queued and not in StaffJobInventory. Say how an employee can start it and what the scope has to do about it:\n - " . implode("\n - ", $unclassified));
    }

    public function test_nothing_classified_has_stopped_being_queued(): void
    {
        $gone = array_values(array_diff(array_keys(StaffJobInventory::QUEUED), array_keys($this->scanner->queuedClasses())));

        $this->assertSame([], $gone, "Listed, but no longer a queued job or listener. Take it off the list, or the next real one hides behind it:\n - " . implode("\n - ", $gone));
    }

    public function test_every_bulk_route_of_the_admin_panel_is_classified(): void
    {
        $unclassified = array_values(array_diff(array_keys($this->scanner->bulkRoutes()), array_keys(StaffJobInventory::BULK_ROUTES)));

        $this->assertSame([], $unclassified, "A bulk route the admin panel answers that nothing classifies:\n - " . implode("\n - ", $unclassified));
    }

    public function test_no_classified_bulk_route_has_been_removed_or_renamed(): void
    {
        $gone = array_values(array_diff(array_keys(StaffJobInventory::BULK_ROUTES), array_keys($this->scanner->bulkRoutes())));

        $this->assertSame([], $gone, "Listed, but the admin panel no longer answers it:\n - " . implode("\n - ", $gone));
    }

    public function test_every_entry_names_a_category_the_list_defines_and_gives_a_reason(): void
    {
        $queuedCategories = [StaffJobInventory::ONE_RECORD, StaffJobInventory::PLATFORM_WIDE, StaffJobInventory::NOT_STAFF_STARTED];

        foreach (StaffJobInventory::QUEUED as $class => $entry) {
            $this->assertContains($entry['class'], $queuedCategories, "{$class} names a category this list does not define");
            $this->assertNotSame('', trim($entry['started']), "{$class} does not say how it is started");
            $this->assertGreaterThan(40, strlen(trim($entry['reason'])), "{$class} has no reason worth reading");
        }

        foreach (StaffJobInventory::BULK_ROUTES as $route => $entry) {
            $this->assertContains($entry['class'], [StaffJobInventory::SCOPED_ELSEWHERE, StaffJobInventory::NOT_YET_SCOPED], "{$route} names a category this list does not define");
            $this->assertGreaterThan(40, strlen(trim($entry['reason'])), "{$route} has no reason worth reading");
        }
    }

    /** The reach of the scan, asserted rather than assumed: a root renamed or narrowed fails here instead of making the tests above pass by reading less. */
    public function test_the_scan_reads_both_job_directories_and_finds_work_in_each(): void
    {
        $this->assertSame(['app/Jobs', 'app/Listeners'], $this->scanner->roots());

        $found = array_keys($this->scanner->queuedClasses());
        foreach (['App\Jobs\\', 'App\Listeners\\'] as $namespace) {
            $this->assertNotSame([], array_filter($found, static fn (string $class): bool => str_starts_with($class, $namespace)), "the scan found nothing queued under {$namespace}");
        }
    }

    /**
     * The finding, counted so that it cannot grow quietly: four bulk routes an employee can run across countries with nothing narrowing
     * them. Scoping one takes it off this list by changing its entry, which is a deliberate act; adding a fifth fails this test.
     */
    public function test_the_bulk_routes_that_narrow_nothing_are_the_four_already_known(): void
    {
        $open = [];
        foreach (StaffJobInventory::BULK_ROUTES as $route => $entry) {
            if ($entry['class'] === StaffJobInventory::NOT_YET_SCOPED) {
                $open[] = $route;
            }
        }

        $this->assertSame([
            'POST admin/coupon/bulk-status',
            'POST admin/customer/bulk-status',
            'POST admin/marketplace/exchange-rates/bulk-update',
            'POST admin/vendors/bulk-status',
        ], $open, 'the bulk routes that narrow nothing changed: scope one and change its entry, or say why a new one may narrow nothing');
    }

    /**
     * The schedule: no employee, no request, nothing to narrow. The count is pinned so a new entry is read by a person, and the one
     * non-command entry stays the named heartbeat -- a closure can close over anything a command cannot, a staff scope included.
     */
    public function test_the_schedule_has_no_actor_and_binds_no_scope(): void
    {
        $entries = $this->scanner->scheduledEntries();

        $this->assertCount(StaffJobInventory::SCHEDULE_ENTRIES, $entries, "the schedule changed. Classify the new entry: a scheduled run has no employee, so it runs for every country:\n - " . implode("\n - ", $entries));
        $this->assertSame(
            [StaffJobInventory::SCHEDULE_CLOSURE],
            array_values(array_filter($entries, static fn (string $entry): bool => !str_starts_with($entry, 'command: '))),
            'a scheduled closure other than the heartbeat: read what it closes over before listing it',
        );
        $this->assertDoesNotMatchRegularExpression('/StaffCountryScope|staffScope/', $this->scanner->scheduleSource(), 'the schedule must bind no employee scope: there is no employee behind a scheduled run');
    }

    /** And no queued work reads a list across countries today, which is why G-11's staff job context has no first caller yet. */
    public function test_no_queued_job_is_classified_as_needing_a_scope_it_does_not_carry(): void
    {
        $oneRecord = array_keys(array_filter(StaffJobInventory::QUEUED, static fn (array $entry): bool => $entry['class'] === StaffJobInventory::ONE_RECORD));

        $this->assertSame([
            'App\Listeners\AddFundToWalletListener',
            'App\Listeners\CustomerRegistrationListener',
            'App\Listeners\CustomerStatusUpdateListener',
            'App\Listeners\DigitalProductDownloadListener',
            'App\Listeners\OrderEditReturnPaymentListener',
            'App\Listeners\VendorRegistrationListener',
        ], $oneRecord, 'the queued work an employee starts changed: a job that reads a list across countries is the first caller of a staff job context, and it must carry the actor and re-read the scope');
    }
}
