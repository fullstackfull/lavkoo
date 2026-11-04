<?php

namespace App\Services\SellerCenter;

use App\Services\Marketplace\SellerPrincipal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Global search behind the command palette (handoff 02 §5).
 *
 * Group order is fixed — Orders, Products, Shipments, Returns, Finance, Cases, Issues, Commands —
 * and each group caps at five rows with a "see all" that lands on the module's own list carrying
 * the same query, so the palette is a shortcut into the lists rather than a second search product.
 *
 * Every query is scoped to the principal's shop. A permission the role lacks removes the group
 * entirely: a seller's support staff should not discover the finance vocabulary by typing into a
 * search box.
 */
class Search
{
    private const PER_GROUP = 5;

    /** @return array<int, array<string, mixed>> */
    public function find(SellerPrincipal $principal, string $query): array
    {
        $groups = [];

        foreach ([
            'orders' => ['orders.view', fn () => $this->orders($principal->sellerId(), $query)],
            'products' => ['products.view', fn () => $this->products($principal->sellerId(), $query)],
            'returns' => ['orders.view', fn () => $this->returns($principal->sellerId(), $query)],
            'issues' => [null, fn () => $this->issues($principal->sellerId(), $query)],
        ] as $key => [$permission, $finder]) {
            if ($permission !== null && !$principal->can($permission)) {
                continue;
            }

            $group = $finder();
            if (($group['rows'] ?? []) !== []) {
                $groups[] = $group + ['key' => $key];
            }
        }

        $commands = $this->commands($principal, $query);
        if ($commands !== []) {
            $groups[] = ['label' => translate('commands'), 'key' => 'commands', 'rows' => $commands];
        }

        return $groups;
    }

    /**
     * The palette's initial state and its command vocabulary.
     *
     * A command a role cannot perform is not listed — offering it and refusing it on click is worse
     * than not offering it (handoff 02 §5).
     *
     * @return array<int, array{label: string, href: string, icon: string, group: string}>
     */
    public function commands(SellerPrincipal $principal, string $query = ''): array
    {
        $candidates = [
            ['label' => translate('go_to_control_tower'), 'route' => 'seller.control-tower', 'icon' => 'activity', 'permission' => null],
            ['label' => translate('open_ship_today_queue'), 'route' => 'seller.orders.index', 'params' => ['view' => 'ship_today'], 'icon' => 'receipt', 'permission' => 'orders.view'],
            ['label' => translate('open_issue_center'), 'route' => 'seller.issues.index', 'icon' => 'activity', 'permission' => null],
            ['label' => translate('review_inventory'), 'route' => 'seller.inventory.index', 'icon' => 'stack', 'permission' => 'products.view'],
            ['label' => translate('create_stock_adjustment'), 'route' => 'seller.inventory.index', 'params' => ['action' => 'adjust'], 'icon' => 'sliders-horizontal', 'permission' => 'inventory.manage'],
            ['label' => translate('request_payout'), 'route' => 'seller.finance.index', 'params' => ['action' => 'payout'], 'icon' => 'bank', 'permission' => 'payouts.request'],
            ['label' => translate('create_automation'), 'route' => 'seller.automation.index', 'params' => ['action' => 'new'], 'icon' => 'gear-six', 'permission' => 'products.manage'],
            ['label' => translate('new_report'), 'route' => 'seller.reports.builder', 'icon' => 'file-text', 'permission' => null],
        ];

        $needle = mb_strtolower(trim($query));
        $commands = [];

        foreach ($candidates as $candidate) {
            if ($candidate['permission'] !== null && !$principal->can($candidate['permission'])) {
                continue;
            }
            if (!Route::has($candidate['route'])) {
                continue;
            }
            if ($needle !== '' && !str_contains(mb_strtolower($candidate['label']), $needle)) {
                continue;
            }

            $commands[] = [
                'label' => $candidate['label'],
                'href' => route($candidate['route'], $candidate['params'] ?? []),
                'icon' => $candidate['icon'],
                'group' => translate('commands'),
            ];
        }

        return $commands;
    }

    private function orders(int $sellerId, string $query): array
    {
        if (!Schema::hasTable('orders')) {
            return ['label' => translate('nav_orders'), 'rows' => []];
        }

        $builder = DB::table('orders')
            ->where(['seller_is' => 'seller', 'seller_id' => $sellerId])
            ->where(function ($where) use ($query) {
                $where->where('id', 'like', $query . '%')
                    ->orWhere('order_amount', 'like', $query . '%');
            });

        $total = (clone $builder)->count();
        $rows = $builder->orderByDesc('id')->limit(self::PER_GROUP)->get(['id', 'order_status', 'order_amount']);

        return [
            'label' => translate('nav_orders'),
            'total' => $total,
            'moreHref' => $total > self::PER_GROUP && Route::has('seller.orders.index')
                ? route('seller.orders.index', ['q' => $query]) : null,
            'rows' => $rows->map(fn ($order) => [
                'label' => '#' . $order->id,
                'meta' => translate($order->order_status ?? ''),
                'href' => Route::has('seller.orders.show') ? route('seller.orders.show', $order->id) : '#',
            ])->all(),
        ];
    }

    private function products(int $sellerId, string $query): array
    {
        if (!Schema::hasTable('products')) {
            return ['label' => translate('nav_products'), 'rows' => []];
        }

        $builder = DB::table('products')
            ->where(['added_by' => 'seller', 'user_id' => $sellerId])
            ->where(function ($where) use ($query) {
                $where->where('name', 'like', '%' . $query . '%')
                    ->orWhere('code', 'like', $query . '%');
            });

        $total = (clone $builder)->count();
        $rows = $builder->orderByDesc('id')->limit(self::PER_GROUP)->get(['id', 'name', 'code']);

        return [
            'label' => translate('nav_products'),
            'total' => $total,
            'moreHref' => $total > self::PER_GROUP && Route::has('seller.products.index')
                ? route('seller.products.index', ['q' => $query]) : null,
            'rows' => $rows->map(fn ($product) => [
                'label' => $product->name,
                'meta' => $product->code,
                'href' => Route::has('seller.products.index') ? route('seller.products.index', ['q' => $product->code ?: $product->name]) : '#',
            ])->all(),
        ];
    }

    private function returns(int $sellerId, string $query): array
    {
        if (!Schema::hasTable('return_shipments')) {
            return ['label' => translate('nav_returns'), 'rows' => []];
        }

        $rows = DB::table('return_shipments')
            ->where('seller_id', $sellerId)
            ->where(function ($where) use ($query) {
                $where->where('reference', 'like', '%' . $query . '%')
                    ->orWhere('tracking_number', 'like', '%' . $query . '%');
            })
            ->orderByDesc('id')
            ->limit(self::PER_GROUP)
            ->get(['id', 'reference', 'status']);

        return [
            'label' => translate('nav_returns'),
            'rows' => $rows->map(fn ($return) => [
                'label' => $return->reference ?: ('#' . $return->id),
                'meta' => translate($return->status ?? ''),
                'href' => Route::has('seller.returns.index') ? route('seller.returns.index', ['q' => $return->reference]) : '#',
            ])->all(),
        ];
    }

    private function issues(int $sellerId, string $query): array
    {
        if (!Schema::hasTable('seller_insights')) {
            return ['label' => translate('nav_issue_center'), 'rows' => []];
        }

        $rows = DB::table('seller_insights')
            ->where('seller_id', $sellerId)
            ->whereIn('status', ['open', 'in_progress', 'monitoring'])
            ->where(function ($where) use ($query) {
                $where->where('title', 'like', '%' . $query . '%')
                    ->orWhere('type', 'like', '%' . $query . '%');
            })
            ->orderByDesc('impact_score')
            ->limit(self::PER_GROUP)
            ->get(['id', 'title', 'type', 'severity']);

        return [
            'label' => translate('nav_issue_center'),
            'rows' => $rows->map(fn ($issue) => [
                'label' => translate($issue->title),
                'meta' => $issue->type,
                'href' => Route::has('seller.issues.show') ? route('seller.issues.show', $issue->id) : '#',
            ])->all(),
        ];
    }
}
