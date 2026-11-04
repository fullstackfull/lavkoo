<?php

namespace App\Services\SellerCenter\Lists;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Services\Marketplace\SlaService;
use App\Services\SellerCenter\Status;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The order queue behind `/seller/orders`.
 *
 * It reads through the same `OrderRepositoryInterface` the seller app's API uses, with the same
 * seller scoping and the same status vocabulary. That is not a convenience: the panel and the app
 * must return the same orders for the same question, and two hand-written queries would eventually
 * disagree about what "ready to ship" means.
 *
 * Saved views are pre-set filter combinations, not separate screens (handoff 01 §6). Their counts
 * are computed by the same query that renders the list, so the number on the tab and the number in
 * the toolbar can never drift apart.
 */
class OrderList
{
    /** Marketplace-provided views. Read-only: a seller may duplicate one, never delete it. */
    public const VIEWS = [
        'ship_today' => ['label' => 'ship_today', 'tone' => 'high', 'statuses' => ['confirmed', 'processing']],
        'sla_risk' => ['label' => 'sla_risk', 'tone' => 'high', 'statuses' => ['pending', 'confirmed', 'processing']],
        'late' => ['label' => 'late', 'tone' => 'critical', 'statuses' => ['pending', 'confirmed', 'processing']],
        'new_orders' => ['label' => 'new_orders', 'tone' => 'neutral', 'statuses' => ['pending']],
        'ready_to_ship' => ['label' => 'ready_to_ship', 'tone' => 'neutral', 'statuses' => ['processing']],
        'shipped' => ['label' => 'shipped', 'tone' => 'neutral', 'statuses' => ['out_for_delivery']],
        'delivered' => ['label' => 'delivered', 'tone' => 'neutral', 'statuses' => ['delivered']],
        'cancelled' => ['label' => 'cancelled', 'tone' => 'neutral', 'statuses' => ['canceled', 'failed']],
        'cod' => ['label' => 'cod', 'tone' => 'neutral', 'statuses' => null],
        'all' => ['label' => 'all', 'tone' => 'neutral', 'statuses' => null],
    ];

    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly SlaService $sla,
    ) {
    }

    public function paginate(int $sellerId, Request $request): LengthAwarePaginator
    {
        $view = (string) $request->query('view', 'all');
        $statuses = $this->statusesFor($view, $request);

        $result = $this->orders->getListWhereIn(
            orderBy: $this->orderBy($request),
            searchValue: trim((string) $request->query('q', '')) ?: null,
            filters: array_filter([
                'filter' => 'all',
                'seller_id' => $sellerId,
                'seller_is' => 'seller',
                'whereIn_order_status' => $statuses ?: null,
                'from' => $request->query('date_from'),
                'to' => $request->query('date_to'),
                'date_type' => $request->query('date_from') || $request->query('date_to') ? 'custom_date' : null,
            ], static fn ($value) => $value !== null),
            whereIn: array_filter([
                'payment_method' => $view === 'cod' ? ['cash_on_delivery'] : null,
            ], static fn ($value) => $value !== null),
            relations: ['customer', 'shipping', 'deliveryMan', 'orderDetails'],
            dataLimit: $this->pageSize($request),
        );

        return $result instanceof LengthAwarePaginator
            ? $result->withQueryString()
            : new LengthAwarePaginator($result, $result->count(), $this->pageSize($request));
    }

    /**
     * The view tabs with their counts.
     *
     * A count that fails to load renders as no badge, never `0` (handoff 05 B4) — so a view whose
     * count query cannot run keeps its tab and loses only its number.
     *
     * @return array<int, array<string, mixed>>
     */
    public function views(int $sellerId, Request $request, string $baseUrl): array
    {
        $current = (string) $request->query('view', 'all');

        return collect(self::VIEWS)->map(function (array $view, string $key) use ($sellerId, $baseUrl, $current) {
            $query = array_filter(['view' => $key === 'all' ? null : $key]);

            return [
                'key' => $key,
                'label' => translate($view['label']),
                'href' => $query === [] ? $baseUrl : $baseUrl . '?' . http_build_query($query),
                'count' => $this->countFor($sellerId, $key),
                'tone' => $view['tone'],
                'current' => $key === $current,
            ];
        })->values()->all();
    }

    /**
     * How an order's ship-by deadline reads (handoff 06 §5).
     *
     * The deadline itself comes from `SlaService`, which is also what raises the SLA issues on the
     * Control Tower — so the alert's count and this column can never tell different stories.
     *
     * @return array{tone: string, glyph: string, state: string, minutes: ?int, deadline: ?\Illuminate\Support\Carbon}
     */
    public function slaFor(object $order): array
    {
        if (!$this->sla->awaitsSeller($order->order_status ?? null)) {
            $met = in_array($order->order_status ?? '', ['delivered', 'out_for_delivery'], true);

            return Status::sla(null, $met) + ['deadline' => null];
        }

        $deadline = $this->sla->processingDeadline($order->created_at ?? null);

        return Status::sla($deadline) + ['deadline' => $deadline];
    }

    /** The filter fields this screen offers, in the panel's grouping order (handoff 05 B2). */
    public function filterFields(): array
    {
        return [
            'status' => ['label' => 'status', 'type' => 'enum', 'group' => 'order', 'options' => [
                ['value' => 'pending', 'label' => translate('pending')],
                ['value' => 'confirmed', 'label' => translate('confirmed')],
                ['value' => 'processing', 'label' => translate('processing')],
                ['value' => 'out_for_delivery', 'label' => translate('out_for_delivery')],
                ['value' => 'delivered', 'label' => translate('delivered')],
                ['value' => 'canceled', 'label' => translate('cancelled')],
                ['value' => 'returned', 'label' => translate('returned')],
            ]],
            'payment' => ['label' => 'payment', 'type' => 'enum', 'group' => 'finance', 'options' => [
                ['value' => 'cash_on_delivery', 'label' => translate('cod')],
                ['value' => 'digital_payment', 'label' => translate('card')],
            ]],
            'date_from' => ['label' => 'placed_from', 'type' => 'date', 'group' => 'dates'],
            'date_to' => ['label' => 'placed_to', 'type' => 'date', 'group' => 'dates'],
        ];
    }

    /** @return array<int, string>|null */
    private function statusesFor(string $view, Request $request): ?array
    {
        $explicit = $request->query('status');
        if ($explicit !== null && $explicit !== '') {
            return is_array($explicit) ? $explicit : [$explicit];
        }

        return self::VIEWS[$view]['statuses'] ?? null;
    }

    private function countFor(int $sellerId, string $view): ?int
    {
        $statuses = self::VIEWS[$view]['statuses'] ?? null;

        $result = $this->orders->getListWhereIn(
            filters: array_filter([
                'filter' => 'all',
                'seller_id' => $sellerId,
                'seller_is' => 'seller',
                'whereIn_order_status' => $statuses,
            ], static fn ($value) => $value !== null),
            dataLimit: 1,
        );

        return $result instanceof LengthAwarePaginator ? $result->total() : $result->count();
    }

    private function orderBy(Request $request): array
    {
        $sort = (string) $request->query('sort', '');
        $direction = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        return match ($sort) {
            'placed' => ['created_at' => $direction],
            'total' => ['order_amount' => $direction],
            'order' => ['id' => $direction],
            // The screen a seller lives in opens on the newest work, not the oldest.
            default => ['id' => 'desc'],
        };
    }

    private function pageSize(Request $request): int
    {
        $size = (int) $request->query('size', 25);

        return in_array($size, [25, 50, 100], true) ? $size : 25;
    }
}
