<?php

namespace Tests\Support;

use App\Events\OrderEditEvent;
use App\Models\OrderDetail;
use App\Models\OrderEditHistory;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use stdClass;

/**
 * What a saved order edit writes, and when it tells anybody: for the tests that make a late write of the save fail and then require everything it had
 * already written to be as it was, and that require the notifications to be sent only once the writes are committed. They also share what the copy of a
 * product, which a line of the order keeps, may say.
 */
trait RecordsWhatAnOrderEditWrites
{
    /** @return iterable<string, array{0: array<string, mixed>}> what the copy of a deleted product may say besides what the row of the line says */
    public static function copiesOfTheProductOfALineWithoutAProduct(): iterable
    {
        yield 'a copy that agrees with the line' => [[]];
        yield 'a copy that says another price and a discount' => [['unit_price' => 120, 'discount' => 10, 'discount_type' => 'flat']];
    }

    /** @return iterable<string, array{0: Closure(Closure): mixed}> a write late in the save, and how to make it fail */
    public static function lateWritesThatFail(): iterable
    {
        yield 'the edit history is created' => [static fn (Closure $fail) => OrderEditHistory::creating($fail)];
        yield 'a detail row is written' => [static fn (Closure $fail) => OrderDetail::saving($fail)];
        yield 'the transaction of the order is updated' => [static fn (Closure $fail) => DB::listen(static function (QueryExecuted $query) use ($fail): void {
            if (str_starts_with($query->sql, 'update') && str_contains($query->sql, 'order_transactions')) {
                $fail();
            }
        })];
    }

    /** @return array<string, list<array<string, mixed>>> every table a saved edit writes to, as it is now */
    protected function whatTheSaveWrites(): array
    {
        $tables = ['orders', 'order_taxes', 'order_details', 'order_details_rewards', 'products', 'order_edit_histories', 'order_transactions'];

        return array_combine($tables, array_map(
            static fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            $tables,
        ));
    }

    /** The transaction level at which each notification of an edit is sent. */
    protected function listenForTheEditsNotifications(): stdClass
    {
        $notifications = new stdClass();
        $notifications->levels = [];
        Event::listen(OrderEditEvent::class, static function () use ($notifications): void {
            $notifications->levels[] = DB::transactionLevel();
        });

        return $notifications;
    }
}
