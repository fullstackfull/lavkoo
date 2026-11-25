<?php

namespace App\Services\PlatformAdmin;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One market's orders of a window, as a file the operator downloads (L-14).
 *
 * **What it holds, and what it does not.** The order's own id, the day it was placed, its status, the market it recorded, the company that sold it and
 * the currency and amount of its own money. No customer: no name, no address, no telephone number, no e-mail. A file that leaves the platform is the
 * worst place to put a person, and a count of orders by status answers the operational question without one.
 *
 * **Nothing is kept.** The rows are streamed to the caller as they are read, in chunks, and no file is ever written: not to the public disk, not to
 * `storage`, not to the system's temporary directory. There is nothing left behind to be served to the next person who guesses a URL, and nothing to
 * forget to delete.
 *
 * **Every file says who took it.** The first lines are a watermark -- the operator's id and name, the market, the window and the moment -- so a file
 * found on a laptop names the person who asked for it. The watermark is written as comment lines so that a spreadsheet still reads the header row.
 *
 * **One market only.** The market is a parameter the selector has already held to what the operator may see, so a file can never hold a market they
 * may not see; and an export of "all" is refused by the controller, so what left the platform is one named market.
 */
final class PlatformOrderExport
{
    /** Rows read per statement. Bounded so a long window is not loaded into memory to be written out again. */
    private const CHUNK = 500;

    private const COLUMNS = ['order_id', 'placed_on', 'status', 'market', 'legal_entity_id', 'currency', 'amount'];

    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly PlatformMarkets $markets)
    {
    }

    /** The name the file is offered under. It carries the market and the window, so two downloads are not one file twice. */
    public function fileName(string $market, PlatformWindow $window): string
    {
        return sprintf('lavkoo-orders-%s-%s-to-%s.csv', strtolower($market), $window->from->toDateString(), $window->to->toDateString());
    }

    /**
     * How many orders the file will hold. Counted before it is streamed, because the audit line of an export says how many rows left the platform and
     * a line written after the stream has been flushed would be written after the answer had gone.
     */
    public function rowCount(string $market, PlatformWindow $window): int
    {
        return $this->query(market: $market, window: $window)->count();
    }

    /** @param array{id: int, name: string} $operator */
    public function stream(string $market, PlatformWindow $window, array $operator): StreamedResponse
    {
        $query = $this->query(market: $market, window: $window);
        $watermark = $this->watermark(market: $market, window: $window, operator: $operator);

        return response()->stream(function () use ($query, $watermark): void {
            $out = fopen('php://output', 'wb');

            foreach ($watermark as $line) {
                fwrite($out, '# ' . $line . "\n");
            }
            fputcsv($out, self::COLUMNS);

            $query->orderBy('orders.id')->chunk(self::CHUNK, function ($orders) use ($out): void {
                foreach ($orders as $order) {
                    fputcsv($out, [
                        (int) $order->id,
                        (string) $order->created_at,
                        (string) $order->order_status,
                        (string) $order->storefront_country,
                        ($order->legal_entity_id ?? null) === null ? '' : (int) $order->legal_entity_id,
                        (string) ($order->currency_code ?? ''),
                        PlatformAdminPresenter::amount(sum: $order->order_amount) ?? '',
                    ]);
                }
                flush();
            });

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $this->fileName(market: $market, window: $window) . '"',
            // No store, no cache, no proxy copy: a file of a market's orders is not something to leave in a cache for the next reader.
            'Cache-Control' => 'no-store, max-age=0',
            'X-Platform-Admin-Export' => $market,
        ]);
    }

    /**
     * The lines at the head of every file: who asked, which market, which window and when. Plain text with no comma or newline of its own, so the
     * watermark cannot break the file it labels.
     *
     * @param array{id: int, name: string} $operator
     *
     * @return list<string>
     */
    public function watermark(string $market, PlatformWindow $window, array $operator): array
    {
        return [
            'LAVKOO platform export',
            'operator: ' . $operator['id'] . ' ' . PlatformAdminText::clipped(text: str_replace(["\r", "\n", ','], ' ', $operator['name']), limit: 64),
            'market: ' . $market,
            'window: ' . $window->from->toDateString() . ' to ' . $window->to->toDateString(),
            'taken_at: ' . PlatformAdminPresenter::timestamp(moment: now()),
        ];
    }

    /**
     * The orders of this market and window: the order's OWN recorded market, which is the fact for money, as the sales figures read it.
     *
     * The company and the currency are selected only where the table carries them. An installation whose GCC-05 migration has not run has no market
     * on an order at all and no export to make, which the controller refuses before this is reached; this guard is for the installation that has the
     * market and not yet the rest.
     */
    private function query(string $market, PlatformWindow $window): Builder
    {
        $selected = ['orders.id', 'orders.created_at', 'orders.order_status', 'orders.storefront_country', 'orders.order_amount'];

        foreach (['legal_entity_id', 'currency_code'] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                $selected[] = 'orders.' . $column;
            }
        }

        return DB::table('orders')
            ->select($selected)
            ->where('orders.storefront_country', $this->markets->normalise(value: $market))
            ->where('orders.created_at', '>=', $window->startsAt()->format(self::DATE_FORMAT))
            ->where('orders.created_at', '<', $window->endsBefore()->format(self::DATE_FORMAT));
    }
}
