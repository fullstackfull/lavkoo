<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Data\CapabilityState;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\FieldMapDefinitions;
use App\Services\Fulfillment\FieldMapStore;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use App\Services\Fulfillment\Providers\TorodPoint\TorodFieldMaps;
use App\Services\Fulfillment\Providers\TorodPoint\TorodInvoiceRoundTrip;
use App\Services\Fulfillment\Readiness\GoLiveItem;
use App\Services\Fulfillment\Readiness\GoLiveReadiness;
use App\Services\Fulfillment\ReconciliationService;
use App\Services\Fulfillment\Support\ProviderConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The orders map of a warehouse whose historical orders carry no invoice number, and what must still hold:
 * the barcode stays a strict identifier, a verified map is only a statement about the SHAPE of the order list,
 * no real order is created until the invoice number has been confirmed to come back, and an order whose answer was lost
 * is found again by OUR invoice number or not at all.
 */
class TorodOrdersFieldMapTest extends TorodTestCase
{
    private const PATHS = ['invoice_number' => 'invoiceNumber', 'reference' => 'barcode', 'status' => 'status'];

    /** @return list<array<string, mixed>> orders the way the warehouse lists the historical ones: a barcode, a status, and no invoice number */
    private function historicalOrders(int $count = 5): array
    {
        return array_map(static fn (int $n): array => ['id' => 9000 + $n, 'barcode' => 'O00' . $n, 'invoiceNumber' => null, 'status' => 'DELIVERED'], range(1, $count));
    }

    /** @return list<string> the required fields that did not resolve */
    private function verifyOrders(array $paths, array $sample, ?string $itemsPath = null): array
    {
        $definition = (new FieldMapDefinitions())->for(ProviderCode::TorodPoint)[TorodFieldMaps::ORDERS];
        $store = app(FieldMapStore::class);
        $store->save(ProviderCode::TorodPoint, TorodFieldMaps::ORDERS, $paths, $itemsPath);

        return $store->verify(ProviderCode::TorodPoint, TorodFieldMaps::ORDERS, $sample, $definition['required'], $definition['nullable']);
    }

    /** @var array{pages: list<list<array<string, mixed>>>, createStatus: int, createBody: array<string, mixed>, listStatus: int}|null */
    private ?array $warehouse = null;

    private int $listCalls = 0;

    /**
     * The warehouse: Add Order answers with the given status, the order list with the given pages (the last one repeats).
     * Calling it again changes what the warehouse says from then on; the first registered fake would otherwise keep answering.
     */
    private function fakeWarehouse(array $listPages = [], int $createStatus = 200, array $createBody = ['id' => 4455, 'barcode' => 'O0015030400001'], int $listStatus = 200): void
    {
        $registered = $this->warehouse !== null;
        $this->warehouse = ['pages' => $listPages, 'createStatus' => $createStatus, 'createBody' => $createBody, 'listStatus' => $listStatus];
        $this->listCalls = 0;
        if ($registered) {
            return;
        }

        Http::fake([self::V5 . '/public/fulfillment/order*' => function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response($this->warehouse['createBody'], $this->warehouse['createStatus']);
            }

            $pages = $this->warehouse['pages'];

            return Http::response(['data' => $pages[min($this->listCalls++, max(0, count($pages) - 1))] ?? []], $this->warehouse['listStatus']);
        }]);
    }

    private function createCalls(): int
    {
        return count(array_filter(Http::recorded()->all(), static fn (array $pair): bool => $pair[0]->method() === 'POST' && str_ends_with((string) parse_url($pair[0]->url(), PHP_URL_PATH), '/order')));
    }

    private function dispatcher(): ProviderOrderDispatcher
    {
        return app(ProviderOrderDispatcher::class);
    }

    private function roundTrip(): TorodInvoiceRoundTrip
    {
        return app(TorodInvoiceRoundTrip::class);
    }

    private function config(): ProviderConfig
    {
        return app(ProviderConnectionManager::class)->config(ProviderCode::TorodPoint);
    }

    // ---- the orders map -------------------------------------------------------------------------------------------------------

    public function test_historical_orders_with_a_null_invoice_number_verify_the_orders_map(): void
    {
        $problems = $this->verifyOrders(self::PATHS, $this->historicalOrders());

        $stored = app(FieldMapStore::class)->get(ProviderCode::TorodPoint, TorodFieldMaps::ORDERS);
        $this->assertSame([], $problems);
        $this->assertTrue($stored->verified);
        $this->assertSame(0, $stored->filledIn('invoice_number'), 'the verification keeps the fact that no sampled order carried a value');
        $this->assertSame(5, $stored->coverage['sampled']);
    }

    public function test_the_definition_lets_only_the_invoice_number_be_empty(): void
    {
        $definition = (new FieldMapDefinitions())->for(ProviderCode::TorodPoint)[TorodFieldMaps::ORDERS];

        $this->assertSame(['invoice_number', 'reference'], $definition['required']);
        $this->assertSame(['invoice_number'], $definition['nullable']);
        $this->assertArrayNotHasKey('nullable', (new FieldMapDefinitions())->for(ProviderCode::TorodPoint)[TorodFieldMaps::PRODUCTS], 'the products map is as strict as before');
    }

    public function test_the_barcode_stays_strict_so_orders_without_one_do_not_verify(): void
    {
        $withoutBarcodes = array_map(static fn (array $order): array => ['barcode' => null] + $order, $this->historicalOrders());

        $this->assertSame(['reference'], $this->verifyOrders(self::PATHS, $withoutBarcodes));
        $this->assertFalse(app(FieldMapStore::class)->get(ProviderCode::TorodPoint, TorodFieldMaps::ORDERS)->verified);
    }

    public function test_a_path_that_does_not_exist_in_the_orders_does_not_verify_even_though_null_would_be_allowed(): void
    {
        $problems = $this->verifyOrders(['invoice_number' => 'invoiceNo'] + self::PATHS, $this->historicalOrders());

        $this->assertSame(['invoice_number'], $problems, 'a field that is not there is not a field that is empty');
    }

    public function test_the_barcode_or_id_cannot_stand_in_for_the_invoice_number(): void
    {
        $this->roundTrip()->withdraw();

        $orders = array_map(static fn (array $order): array => ['orderBarcode' => $order['barcode']] + $order, $this->historicalOrders());

        foreach (['barcode', 'id', 'orderBarcode'] as $substitute) {
            $problems = $this->verifyOrders(['invoice_number' => $substitute] + self::PATHS, $orders);

            $this->assertSame([], $problems, 'structurally it would pass; what it can never do is be confirmed, so no real order is created');
            $this->assertNotContains($substitute, TorodFieldMaps::CANDIDATES[TorodFieldMaps::ORDERS]['invoice_number']);
            $this->assertNull($this->roundTrip()->invoicePath(), $substitute);
            try {
                $this->roundTrip()->confirm(adminId: 1, adminName: 'Owner', evidence: 'The provider says the barcode comes back as the invoice number.');
                $this->fail('a confirmation for ' . $substitute . ' must be refused');
            } catch (InvalidArgumentException) {
                $this->assertFalse($this->roundTrip()->isConfirmed($this->config()));
            }
        }
    }

    public function test_the_invoice_number_cannot_read_the_same_field_as_the_reference(): void
    {
        $this->roundTrip()->withdraw();
        $this->verifyOrders(['invoice_number' => 'status', 'reference' => 'status'], $this->historicalOrders());

        $this->assertNull($this->roundTrip()->invoicePath());
        $this->assertFalse($this->roundTrip()->isConfirmed($this->config()));
    }

    public function test_a_confirmation_cannot_survive_the_map_being_pointed_at_an_identifier(): void
    {
        $this->assertTrue($this->roundTrip()->isConfirmed($this->config()));

        app(FieldMapStore::class)->save(ProviderCode::TorodPoint, TorodFieldMaps::ORDERS, ['invoice_number' => 'barcode', 'reference' => 'barcode'], null);
        $fulfillment = $this->torodFulfillment();
        $this->fakeWarehouse();
        $this->dispatcher()->dispatch($fulfillment->id);

        $this->assertFalse($this->roundTrip()->isConfirmed($this->config()));
        $this->assertSame(HoldReason::PROVIDER_NOT_READY, $fulfillment->fresh()->hold_reason);
        $this->assertSame(0, $this->createCalls());
    }

    public function test_valid_invoice_numbers_verify_and_are_counted(): void
    {
        $orders = $this->historicalOrders();
        $orders[0]['invoiceNumber'] = '100-TP1';
        $orders[1]['invoiceNumber'] = '101-TP1';

        $this->assertSame([], $this->verifyOrders(self::PATHS, $orders));
        $this->assertSame(2, app(FieldMapStore::class)->get(ProviderCode::TorodPoint, TorodFieldMaps::ORDERS)->filledIn('invoice_number'));
    }

    public function test_changing_a_path_withdraws_the_verification_and_what_it_showed(): void
    {
        $this->verifyOrders(self::PATHS, $this->historicalOrders());
        $store = app(FieldMapStore::class);

        $store->save(ProviderCode::TorodPoint, TorodFieldMaps::ORDERS, ['invoice_number' => 'invoice_number'] + self::PATHS, null);

        $stored = $store->get(ProviderCode::TorodPoint, TorodFieldMaps::ORDERS);
        $this->assertFalse($stored->verified);
        $this->assertSame([], $stored->coverage);
        $this->assertNull($stored->filledIn('invoice_number'));
    }

    // ---- structural verification authorises nothing ------------------------------------------------------------------------------

    public function test_a_verified_map_does_not_release_a_real_order_without_the_round_trip_confirmation(): void
    {
        $this->roundTrip()->withdraw();
        $this->assertSame([], $this->verifyOrders(self::PATHS, $this->historicalOrders()));
        $fulfillment = $this->torodFulfillment();
        $this->fakeWarehouse();

        $outcome = $this->dispatcher()->dispatch($fulfillment->id);

        $fresh = $fulfillment->fresh();
        $this->assertSame(ProviderOrderDispatcher::SKIPPED, $outcome);
        $this->assertSame(FulfillmentStatus::Held, $fresh->status);
        $this->assertSame(HoldReason::PROVIDER_NOT_READY, $fresh->hold_reason);
        $this->assertSame(0, $this->createCalls(), 'nothing was created at the provider');
        Http::assertNothingSent();
    }

    public function test_a_person_sending_one_order_is_held_by_the_same_rule(): void
    {
        $this->roundTrip()->withdraw();
        $fulfillment = $this->torodFulfillment();
        $this->fakeWarehouse();

        $outcome = $this->dispatcher()->dispatch($fulfillment->id, manual: true);

        $this->assertSame(ProviderOrderDispatcher::SKIPPED, $outcome);
        $this->assertSame(HoldReason::PROVIDER_NOT_READY, $fulfillment->fresh()->hold_reason);
        Http::assertNothingSent();
    }

    public function test_a_rehearsal_is_not_held_by_it(): void
    {
        $this->roundTrip()->withdraw();
        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => true, 'company_id' => '714']);
        $fulfillment = $this->torodFulfillment();
        $this->fakeWarehouse();

        $outcome = $this->dispatcher()->dispatch($fulfillment->id);

        $this->assertSame(SubmissionResult::DRY_RUN, $outcome);
        $this->assertSame(FulfillmentStatus::DryRun, $fulfillment->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_the_confirmation_releases_the_held_order_and_it_is_created_once(): void
    {
        $this->roundTrip()->withdraw();
        $fulfillment = $this->torodFulfillment();
        $this->fakeWarehouse();
        $this->dispatcher()->dispatch($fulfillment->id);
        $this->assertSame(FulfillmentStatus::Held, $fulfillment->fresh()->status);

        $this->confirmInvoiceRoundTrip();
        $outcome = $this->dispatcher()->dispatch($fulfillment->id);

        $this->assertSame(SubmissionResult::ACCEPTED, $outcome);
        $this->assertSame(1, $this->createCalls());
        $this->assertSame('O0015030400001', $fulfillment->fresh()->provider_reference);
    }

    public function test_the_adapter_itself_refuses_a_real_send_without_the_confirmation_and_sends_nothing(): void
    {
        $this->roundTrip()->withdraw();
        $fulfillment = $this->torodFulfillment();
        $this->fakeWarehouse();

        $result = $this->adapter()->submit($fulfillment, dryRun: false);

        $this->assertSame(SubmissionResult::REJECTED, $result->outcome);
        $this->assertSame('invoice_round_trip_unconfirmed', $result->errorCode);
        Http::assertNothingSent();
        $this->assertSame(SubmissionResult::DRY_RUN, $this->adapter()->submit($fulfillment, dryRun: true)->outcome, 'a rehearsal builds the request without it');
    }

    public function test_changing_the_invoice_path_closes_the_gate_again(): void
    {
        $this->assertTrue($this->roundTrip()->isConfirmed($this->config()));

        app(FieldMapStore::class)->save(ProviderCode::TorodPoint, TorodFieldMaps::ORDERS, ['invoice_number' => 'invoiceNo', 'reference' => 'barcode'], null);

        $this->assertFalse($this->roundTrip()->isConfirmed($this->config()), 'the statement was about another path');
        $fulfillment = $this->torodFulfillment();
        $this->fakeWarehouse();
        $this->dispatcher()->dispatch($fulfillment->id);
        $this->assertSame(HoldReason::PROVIDER_NOT_READY, $fulfillment->fresh()->hold_reason);
    }

    public function test_the_gate_is_reported_as_a_capability_and_a_go_live_item_for_torod_only(): void
    {
        $confirmed = $this->adapter()->readiness()->capability('live_submission');
        $this->assertSame(CapabilityState::ENABLED, $confirmed->state);

        $this->roundTrip()->withdraw();
        $capability = $this->adapter()->readiness()->capability('live_submission');
        $this->assertSame(CapabilityState::UNVERIFIED, $capability->state);
        $this->assertSame('torod_invoice_round_trip_unconfirmed', $capability->reason);

        $report = app(GoLiveReadiness::class)->report(ProviderCode::TorodPoint);
        $item = collect($report->items)->firstWhere('key', 'live_submission_proven');
        $this->assertSame(GoLiveItem::UNMET, $item->status, 'it holds automatic sending back');
        $this->assertContains('live_submission_proven', array_map(static fn (GoLiveItem $unmet): string => $unmet->key, $report->unmet()));

        $this->assertNull(collect(app(GoLiveReadiness::class)->report(ProviderCode::M5azn)->items)->firstWhere('key', 'live_submission_proven'), 'M5AZN has no such item');
    }

    public function test_the_confirmation_needs_a_path_and_evidence(): void
    {
        $this->roundTrip()->withdraw();

        try {
            $this->roundTrip()->confirm(adminId: 1, adminName: 'Owner', evidence: 'too short');
            $this->fail('evidence of fewer than ten characters must be refused');
        } catch (InvalidArgumentException) {
            $this->assertFalse($this->roundTrip()->isConfirmed($this->config()));
        }

        app(FieldMapStore::class)->save(ProviderCode::TorodPoint, TorodFieldMaps::ORDERS, ['reference' => 'barcode'], null);
        $this->expectException(InvalidArgumentException::class);
        $this->roundTrip()->confirm(adminId: 1, adminName: 'Owner', evidence: 'Written answer from the provider, dated today.');
    }

    public function test_the_confirmation_records_who_when_where_and_why(): void
    {
        $this->roundTrip()->withdraw();

        $this->roundTrip()->confirm(adminId: 7, adminName: 'Salma', evidence: '  LogesTechs support wrote that invoiceNumber is stored as sent.  ');

        $record = $this->roundTrip()->confirmation($this->config());
        $this->assertSame(7, $record['confirmed_by']);
        $this->assertSame('Salma', $record['confirmed_by_name']);
        $this->assertSame('invoiceNumber', $record['invoice_path']);
        $this->assertSame('LogesTechs support wrote that invoiceNumber is stored as sent.', $record['evidence']);
        $this->assertNotSame('', $record['confirmed_at']);
    }

    // ---- finding an order again by OUR invoice number -------------------------------------------------------------------------

    private function reconcilable(?array $sample = null): ProviderFulfillment
    {
        $this->assertSame([], $this->verifyOrders(self::PATHS, $sample ?? $this->historicalOrders()));

        return $this->torodFulfillment();
    }

    public function test_an_order_carrying_our_invoice_number_is_found_and_its_barcode_is_the_reference(): void
    {
        $fulfillment = $this->reconcilable();
        $this->fakeWarehouse([[...$this->historicalOrders(), ['id' => 9100, 'barcode' => 'O777', 'invoiceNumber' => $fulfillment->reference, 'status' => 'CREATED']]]);

        $result = $this->adapter()->reconcileSubmission($fulfillment);

        $this->assertSame(SubmissionResult::ACCEPTED, $result->outcome);
        $this->assertSame('O777', $result->providerReference);
        $this->assertSame('CREATED', $result->providerStatus);
        $this->assertSame(0, $this->createCalls());
    }

    public function test_orders_with_no_invoice_number_never_match_and_the_answer_stays_unknown(): void
    {
        $fulfillment = $this->reconcilable();
        $this->fakeWarehouse([$this->historicalOrders()]);

        $result = $this->adapter()->reconcileSubmission($fulfillment);

        $this->assertSame(SubmissionResult::AMBIGUOUS, $result->outcome, 'not found is not proof it was not created');
        $this->assertSame('not_found_in_order_list', $result->errorCode);
    }

    public function test_a_barcode_or_an_id_equal_to_our_reference_is_not_our_invoice_number(): void
    {
        $fulfillment = $this->reconcilable();
        $this->fakeWarehouse([[['id' => $fulfillment->reference, 'barcode' => $fulfillment->reference, 'invoiceNumber' => null, 'status' => 'CREATED']]]);

        $this->assertSame('not_found_in_order_list', $this->adapter()->reconcileSubmission($fulfillment)->errorCode);
    }

    #[DataProvider('blankInvoiceNumbers')]
    public function test_a_blank_invoice_number_never_matches(mixed $blank): void
    {
        $fulfillment = $this->reconcilable();
        $this->fakeWarehouse([[['id' => 1, 'barcode' => 'O1', 'invoiceNumber' => $blank, 'status' => 'CREATED']]]);

        $this->assertSame(SubmissionResult::AMBIGUOUS, $this->adapter()->reconcileSubmission($fulfillment)->outcome);
    }

    /** @return iterable<string, array{mixed}> */
    public static function blankInvoiceNumbers(): iterable
    {
        yield 'null' => [null];
        yield 'empty text' => [''];
        yield 'blanks' => ['   '];
        yield 'a list' => [['100-TP1']];
        yield 'false' => [false];
    }

    public function test_a_match_without_a_barcode_is_not_acceptance(): void
    {
        $fulfillment = $this->reconcilable();
        $this->fakeWarehouse([[['id' => 1, 'barcode' => null, 'invoiceNumber' => $fulfillment->reference, 'status' => 'CREATED']]]);

        $this->assertSame(SubmissionResult::AMBIGUOUS, $this->adapter()->reconcileSubmission($fulfillment)->outcome, 'there is nothing to link the shipment to');
    }

    public function test_two_different_orders_with_our_invoice_number_are_never_guessed_between(): void
    {
        $fulfillment = $this->reconcilable();
        $this->fakeWarehouse([[
            ['id' => 1, 'barcode' => 'O1', 'invoiceNumber' => $fulfillment->reference, 'status' => 'CREATED'],
            ['id' => 2, 'barcode' => 'O2', 'invoiceNumber' => $fulfillment->reference, 'status' => 'CREATED'],
        ]]);

        $result = $this->adapter()->reconcileSubmission($fulfillment);

        $this->assertSame(SubmissionResult::AMBIGUOUS, $result->outcome);
        $this->assertSame('duplicate_invoice_in_order_list', $result->errorCode);
    }

    public function test_the_same_order_listed_on_every_page_is_one_order(): void
    {
        $fulfillment = $this->reconcilable();
        $page = array_map(static fn (int $n): array => ['id' => $n, 'barcode' => 'O' . $n, 'invoiceNumber' => null, 'status' => 'CREATED'], range(1, 49));
        $page[] = ['id' => 50, 'barcode' => 'O50', 'invoiceNumber' => $fulfillment->reference, 'status' => 'CREATED'];
        $this->fakeWarehouse([$page]);

        $result = $this->adapter()->reconcileSubmission($fulfillment);

        $this->assertSame(SubmissionResult::ACCEPTED, $result->outcome, 'a server that ignores the page number does not make one order two');
        $this->assertSame('O50', $result->providerReference);
    }

    public function test_an_unverified_map_cannot_reconcile_and_asks_nothing_of_the_provider(): void
    {
        $fulfillment = $this->torodFulfillment();
        app(FieldMapStore::class)->save(ProviderCode::TorodPoint, TorodFieldMaps::ORDERS, self::PATHS, null);
        $this->fakeWarehouse([$this->historicalOrders()]);

        $result = $this->adapter()->reconcileSubmission($fulfillment);

        $this->assertSame('cannot_reconcile', $result->errorCode);
        Http::assertNothingSent();
    }

    public function test_without_a_reference_to_look_for_nothing_is_asked(): void
    {
        $fulfillment = $this->reconcilable();
        $fulfillment->reference = '';
        $this->fakeWarehouse([[['id' => 1, 'barcode' => 'O1', 'invoiceNumber' => '', 'status' => 'CREATED']]]);

        $this->assertSame('cannot_reconcile', $this->adapter()->reconcileSubmission($fulfillment)->errorCode, 'an empty reference must not match an empty invoice number');
        Http::assertNothingSent();
    }

    public function test_an_unreadable_order_list_is_unknown_not_empty(): void
    {
        $fulfillment = $this->reconcilable();
        $this->fakeWarehouse([[]], listStatus: 500);

        $this->assertSame('order_list_unreadable', $this->adapter()->reconcileSubmission($fulfillment)->errorCode);
    }

    // ---- duplicate-order prevention ---------------------------------------------------------------------------------------------

    public function test_a_lost_answer_is_never_answered_with_a_second_order_and_the_list_of_old_orders_settles_nothing(): void
    {
        $fulfillment = $this->reconcilable();
        $this->fakeWarehouse([$this->historicalOrders()], createStatus: 504, createBody: []);

        $first = $this->dispatcher()->dispatch($fulfillment->id);
        $this->assertSame(SubmissionResult::AMBIGUOUS, $first);
        $this->assertSame(FulfillmentStatus::ReconcilePending, $fulfillment->fresh()->status);

        $this->assertSame(ProviderOrderDispatcher::SKIPPED, $this->dispatcher()->dispatch($fulfillment->id), 'an order whose outcome is unknown is not sent again');
        $this->assertSame(ProviderOrderDispatcher::SKIPPED, $this->dispatcher()->dispatch($fulfillment->id, manual: true));

        $this->assertSame('still_unknown', app(ReconciliationService::class)->reconcile($fulfillment->id));
        $this->assertSame(FulfillmentStatus::ReconcilePending, $fulfillment->fresh()->status);
        $this->assertSame(1, $this->createCalls());

        $this->fakeWarehouse([[...$this->historicalOrders(), ['id' => 9100, 'barcode' => 'O777', 'invoiceNumber' => $fulfillment->reference, 'status' => 'CREATED']]], createStatus: 504, createBody: []);
        $this->assertSame('accepted', app(ReconciliationService::class)->reconcile($fulfillment->id));

        $fresh = $fulfillment->fresh();
        $this->assertSame('O777', $fresh->provider_reference);
        $this->assertSame(FulfillmentStatus::Submitted, $fresh->status);
        $this->assertSame(1, $this->createCalls(), 'found by our invoice number, never created twice');
    }

    public function test_an_order_the_provider_already_holds_is_never_sent_again(): void
    {
        $fulfillment = $this->torodFulfillment();
        ProviderFulfillment::whereKey($fulfillment->id)->update(['provider_reference' => 'O-ALREADY']);
        $this->fakeWarehouse();

        $this->assertSame(ProviderOrderDispatcher::SKIPPED, $this->dispatcher()->dispatch($fulfillment->id));
        $this->assertSame(HoldReason::ALREADY_WITH_PROVIDER, $fulfillment->fresh()->hold_reason);
        Http::assertNothingSent();
    }
}
