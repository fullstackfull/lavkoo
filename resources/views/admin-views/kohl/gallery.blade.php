@extends('layouts.admin.app')

@section('title', translate('Design_System'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('Kohl_Design_System')"
                         :subtitle="translate('every_primitive_screens_are_built_from_verified_in_light_dark_ltr_and_rtl')">
            <x-slot:actions>
                <x-k.button variant="secondary" icon="refresh" onclick="Kohl.setTheme('light')">{{ translate('light') }}</x-k.button>
                <x-k.button variant="secondary" icon="refresh" onclick="Kohl.setTheme('dark')">{{ translate('dark') }}</x-k.button>
                <x-k.button variant="ghost" onclick="Kohl.setTheme('system')">{{ translate('system') }}</x-k.button>
            </x-slot:actions>
        </x-k.page-header>

        <div class="k-stack">

            {{-- KPI tiles: a number with no movement beside it is not information. --}}
            <x-k.card :title="translate('stat_tiles')">
                <div class="k-stats">
                    <x-k.stat :label="translate('total_revenue')" value="24,540" icon="trend-up"
                              :delta="18.2" :caption="translate('vs_last_30_days')" />
                    <x-k.stat :label="translate('orders')" value="1,248" icon="orders"
                              :delta="12.4" :caption="translate('vs_last_30_days')" />
                    <x-k.stat :label="translate('customers')" value="8,549" icon="customers"
                              :delta="-3.1" :caption="translate('vs_last_30_days')" />
                    <x-k.stat :label="translate('conversion_rate')" value="3.45%" icon="reports"
                              :delta="0" :caption="translate('unchanged')" />
                </div>
            </x-k.card>

            <x-k.card :title="translate('buttons')">
                <div class="k-row" style="flex-wrap:wrap;gap:var(--k-size-2)">
                    <x-k.button variant="primary" icon="plus">{{ translate('add_product') }}</x-k.button>
                    <x-k.button variant="secondary" icon="download">{{ translate('export') }}</x-k.button>
                    <x-k.button variant="ghost" icon="filter">{{ translate('filter') }}</x-k.button>
                    <x-k.button variant="danger" icon="trash">{{ translate('delete') }}</x-k.button>
                    <x-k.button variant="secondary" size="sm" icon="edit">{{ translate('edit') }}</x-k.button>
                    <x-k.button variant="secondary" icon="chevron-end" />
                    <x-k.button variant="primary" disabled>{{ translate('disabled') }}</x-k.button>
                </div>
            </x-k.card>

            <x-k.card :title="translate('status_badges')">
                <div class="k-row" style="flex-wrap:wrap;gap:var(--k-size-2)">
                    <x-k.badge tone="success">{{ translate('delivered') }}</x-k.badge>
                    <x-k.badge tone="warning">{{ translate('pending') }}</x-k.badge>
                    <x-k.badge tone="danger">{{ translate('canceled') }}</x-k.badge>
                    <x-k.badge tone="info">{{ translate('processing') }}</x-k.badge>
                    <x-k.badge tone="accent">{{ translate('new') }}</x-k.badge>
                    <x-k.badge>{{ translate('draft') }}</x-k.badge>
                </div>
                <p class="k-field__hint" style="margin-block-start:var(--k-size-3)">
                    {{ translate('each_status_carries_a_dot_as_well_as_a_colour_so_it_survives_greyscale_and_colour_blind_vision') }}
                </p>
            </x-k.card>

            <x-k.card :title="translate('form_fields')">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:var(--k-size-4)">
                    <x-k.field :label="translate('product_name')" name="k-demo-name" :required="true"
                               :hint="translate('shown_to_customers_on_the_storefront')">
                        <input id="k-demo-name" class="k-input" type="text" value="Advanced Night Repair">
                    </x-k.field>

                    <x-k.field :label="translate('price')" name="k-demo-price"
                               :error="translate('enter_a_price_greater_than_zero')">
                        <input id="k-demo-price" class="k-input" type="text" value="0">
                    </x-k.field>

                    <x-k.field :label="translate('category')" name="k-demo-cat">
                        <select id="k-demo-cat" class="k-select">
                            <option>{{ translate('skincare') }}</option>
                            <option>{{ translate('fragrance') }}</option>
                        </select>
                    </x-k.field>

                    <x-k.field :label="translate('search')" name="k-demo-search">
                        <div class="k-search">
                            <x-k.icon name="search" :size="15" />
                            <input id="k-demo-search" class="k-input" type="search" placeholder="{{ translate('search_products') }}">
                        </div>
                    </x-k.field>

                    <x-k.field :label="translate('description')" name="k-demo-desc">
                        <textarea id="k-demo-desc" class="k-textarea" rows="3">{{ translate('a_short_description') }}</textarea>
                    </x-k.field>

                    <x-k.field :label="translate('published')">
                        <label class="k-row" style="gap:var(--k-size-2)">
                            <input type="checkbox" class="k-switch" checked>
                            <span class="k-field__hint">{{ translate('visible_on_the_storefront') }}</span>
                        </label>
                    </x-k.field>
                </div>
            </x-k.card>

            {{-- The data view: one surface replacing 142 hand-rolled tables. --}}
            <x-k.data-view :title="translate('orders')" :count="4" :selectable="true"
                           :searchPlaceholder="translate('search_by_order_id')">
                <x-slot:tabs>
                    <a class="k-tab" href="#" aria-selected="true">{{ translate('all') }}<span class="k-tab__count k-num">4</span></a>
                    <a class="k-tab" href="#" aria-selected="false">{{ translate('pending') }}<span class="k-tab__count k-num">1</span></a>
                    <a class="k-tab" href="#" aria-selected="false">{{ translate('awaiting_payment') }}<span class="k-tab__count k-num">1</span></a>
                    <a class="k-tab" href="#" aria-selected="false">{{ translate('delivered') }}<span class="k-tab__count k-num">2</span></a>
                </x-slot:tabs>

                <x-slot:actions>
                    <x-k.button variant="secondary" icon="filter">{{ translate('filter') }}</x-k.button>
                    <x-k.button variant="secondary" icon="download">{{ translate('export') }}</x-k.button>
                    <x-k.button variant="primary" icon="plus">{{ translate('new_order') }}</x-k.button>
                </x-slot:actions>

                <x-slot:filters>
                    <x-k.chip :key="translate('status')">{{ translate('pending') }}</x-k.chip>
                    <x-k.chip :key="translate('store')">Pharmacy Syria</x-k.chip>
                    <x-k.chip :key="translate('date')">{{ translate('last_30_days') }}</x-k.chip>
                </x-slot:filters>

                <x-slot:bulk>
                    <x-k.button variant="secondary" size="sm" icon="check">{{ translate('mark_as_shipped') }}</x-k.button>
                    <x-k.button variant="secondary" size="sm" icon="download">{{ translate('export_selected') }}</x-k.button>
                    <x-k.button variant="danger" size="sm" icon="trash">{{ translate('cancel_orders') }}</x-k.button>
                </x-slot:bulk>

                <table class="k-table">
                    <thead>
                        <tr>
                            <th style="inline-size:44px">
                                <input type="checkbox" data-k-select-all aria-label="{{ translate('select_all') }}">
                            </th>
                            <th>{{ translate('order') }}</th>
                            <th>{{ translate('customer') }}</th>
                            <th>{{ translate('date') }}</th>
                            <th>{{ translate('status') }}</th>
                            <th class="k-table__num">{{ translate('total') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ([
                            ['id' => '100249', 'name' => 'Wade Warren', 'date' => '12 Jul 2026', 'tone' => 'success', 'status' => translate('delivered'), 'total' => '125.00'],
                            ['id' => '100248', 'name' => 'Esther Howard', 'date' => '12 Jul 2026', 'tone' => 'info', 'status' => translate('processing'), 'total' => '320.00'],
                            ['id' => '100247', 'name' => 'Cameron Williamson', 'date' => '11 Jul 2026', 'tone' => 'warning', 'status' => translate('pending'), 'total' => '85.00'],
                            ['id' => '100246', 'name' => 'Brooklyn Simmons', 'date' => '11 Jul 2026', 'tone' => 'success', 'status' => translate('delivered'), 'total' => '210.00'],
                        ] as $row)
                            <tr>
                                <td><input type="checkbox" data-k-row-select value="{{ $row['id'] }}"
                                           aria-label="{{ translate('select_order') }} {{ $row['id'] }}"></td>
                                <td><a href="#" style="font-weight:500">#<span class="k-num">{{ $row['id'] }}</span></a></td>
                                <td>
                                    <span class="k-row">
                                        <x-k.avatar :name="$row['name']" />
                                        <span class="k-truncate">{{ $row['name'] }}</span>
                                    </span>
                                </td>
                                <td class="k-text-muted">{{ $row['date'] }}</td>
                                <td><x-k.badge :tone="$row['tone']">{{ $row['status'] }}</x-k.badge></td>
                                <td class="k-table__num k-num">{{ $row['total'] }}</td>
                                <td>
                                    <div class="k-table__actions">
                                        <x-k.button variant="ghost" size="sm" icon="eye" :aria-label="translate('view')" />
                                        <x-k.button variant="ghost" size="sm" icon="edit" :aria-label="translate('edit')"
                                                    data-k-drawer-open="k-demo-drawer" />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <x-slot:pager>
                    <span class="k-pager__info">{{ translate('showing') }} <span class="k-num">1–4</span> {{ translate('of') }} <span class="k-num">4</span></span>
                    <div class="k-pager__controls">
                        <x-k.button variant="secondary" size="sm" icon="chevron-start" :directional="true" disabled />
                        <x-k.button variant="secondary" size="sm" icon="chevron-end" :directional="true" disabled />
                    </div>
                </x-slot:pager>
            </x-k.data-view>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:var(--k-size-4)">
                <x-k.card :title="translate('loading_states')">
                    <div class="k-stack" style="gap:var(--k-size-2)">
                        <div class="k-row" style="gap:var(--k-size-3)">
                            <x-k.skeleton variant="avatar" />
                            <div class="k-grow">
                                <x-k.skeleton variant="title" />
                                <x-k.skeleton variant="text" width="80%" />
                            </div>
                        </div>
                        <x-k.skeleton variant="row" :count="3" />
                    </div>
                </x-k.card>

                <x-k.card :title="translate('empty_state')" :padded="false">
                    <x-k.empty icon="orders" :title="translate('no_orders_yet')"
                               :text="translate('orders_will_appear_here_as_soon_as_a_customer_checks_out')">
                        <x-slot:action>
                            <x-k.button variant="primary" icon="plus">{{ translate('create_a_test_order') }}</x-k.button>
                        </x-slot:action>
                    </x-k.empty>
                </x-k.card>

                <x-k.card :title="translate('notifications')">
                    <div class="k-row" style="flex-wrap:wrap;gap:var(--k-size-2)">
                        <x-k.button variant="secondary" size="sm"
                                    onclick="Kohl.toast({title: @js(translate('draft_saved')), tone: 'success'})">
                            {{ translate('success') }}
                        </x-k.button>
                        <x-k.button variant="secondary" size="sm"
                                    onclick="Kohl.toast({title: @js(translate('low_stock')), text: @js(translate('one_product_is_below_its_threshold')), tone: 'warning'})">
                            {{ translate('warning') }}
                        </x-k.button>
                        <x-k.button variant="secondary" size="sm"
                                    onclick="Kohl.toast({title: @js(translate('order_canceled')), tone: 'danger', action: {label: @js(translate('undo')), onClick: function(){ Kohl.toast({title: @js(translate('restored')), tone: 'success'}); }}})">
                            {{ translate('with_undo') }}
                        </x-k.button>
                    </div>
                    <p class="k-field__hint" style="margin-block-start:var(--k-size-3)">
                        {{ translate('one_queue_for_the_whole_panel_replacing_three_notification_libraries') }}
                    </p>
                </x-k.card>

                <x-k.card :title="translate('drawer')">
                    <x-k.button variant="primary" data-k-drawer-open="k-demo-drawer">
                        {{ translate('open_drawer') }}
                    </x-k.button>
                    <p class="k-field__hint" style="margin-block-start:var(--k-size-3)">
                        {{ translate('edits_happen_beside_the_context_below_640px_it_becomes_a_bottom_sheet') }}
                    </p>
                </x-k.card>
            </div>
        </div>

        <x-k.drawer id="k-demo-drawer" :title="translate('edit_order')">
            <div class="k-stack">
                <x-k.field :label="translate('order_status')" name="k-drawer-status">
                    <select id="k-drawer-status" class="k-select">
                        <option>{{ translate('pending') }}</option>
                        <option>{{ translate('processing') }}</option>
                        <option>{{ translate('delivered') }}</option>
                    </select>
                </x-k.field>
                <x-k.field :label="translate('internal_note')" name="k-drawer-note"
                           :hint="translate('only_staff_can_see_this')">
                    <textarea id="k-drawer-note" class="k-textarea" rows="4"></textarea>
                </x-k.field>
            </div>
            <x-slot:footer>
                <x-k.button variant="primary">{{ translate('save') }}</x-k.button>
                <x-k.button variant="ghost" data-k-drawer-close>{{ translate('cancel') }}</x-k.button>
            </x-slot:footer>
        </x-k.drawer>

    </div>
@endsection
