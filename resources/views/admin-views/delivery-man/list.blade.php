@extends('layouts.admin.app')

@section('title',translate('deliveryman_List'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{dynamicAsset(path: 'public/assets/back-end/img/deliveryman.png')}}" width="20" alt="">
                {{translate('delivery_man')}} <span class="badge badge-soft-dark radius-50 fs-12">{{ $deliveryMens->total() }}</span>
            </h2>
        </div>

        <x-k.data-view :title="translate('delivery_man')" :count="$deliveryMens->total()"
                       searchName="searchValue" :searchValue="requestString('searchValue')"
                       :searchPlaceholder="translate('search_by_name').','.translate('_contact_info')">

            <x-slot:actions>
                <a class="k-btn k-btn--secondary"
                   href="{{ route('admin.delivery-man.export', ['searchValue' => request('searchValue'), 'sort_by' => request('sort_by')]) }}">
                    <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                </a>
                <div class="dropdown">
                    <button type="button" class="k-btn {{ !empty(request('sort_by')) ? 'k-btn--primary' : 'k-btn--secondary' }}"
                            data-bs-toggle="dropdown">
                        <x-k.icon name="filter" :size="15" />
                        {{ translate('Sorting') }}
                        <x-k.icon name="chevron-down" :size="13" />
                    </button>
                    <ul class="dropdown-menu dropdown-menu-right">
                        @php($deliveryManSortOptions = [
                            'latest' => translate('Default') . ' (' . translate('Recent created') . ')',
                            'oldest' => translate('Show Older First'),
                            'rating' => translate('Top Delivery Man'),
                        ])
                        @foreach($deliveryManSortOptions as $sortKey => $sortLabel)
                            @php($sortActive = $sortKey === 'latest' ? (empty(request('sort_by')) || request('sort_by') == 'latest') : request('sort_by') == $sortKey)
                            <li>
                                <a class="dropdown-item d-flex align-items-center gap-2"
                                   href="{{ route('admin.delivery-man.list', ['sort_by' => $sortKey, 'searchValue' => request('searchValue')]) }}">
                                    <span class="{{ $sortActive ? 'text-success' : 'text-dark' }} pt-1">
                                        <i class="fi {{ $sortActive ? 'fi-sr-check-circle' : 'fi-sr-circle' }}"></i>
                                    </span>
                                    {{ $sortLabel }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <a href="{{route('admin.delivery-man.add')}}" class="k-btn k-btn--primary">
                    <x-k.icon name="plus" :size="15" /> {{ translate('Add_Delivery_Man') }}
                </a>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{translate('SL')}}</th>
                    <th>{{translate('name')}}</th>
                    <th>{{translate('contact info')}}</th>
                    <th class="k-table__num">{{translate('total_Orders')}}</th>
                    <th class="k-table__num">{{translate('rating')}}</th>
                    <th>{{translate('status')}}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody id="set-rows">
                @foreach($deliveryMens as $key => $deliveryMen)
                    <tr>
                        <td><span class="k-num">{{$deliveryMens->firstitem()+$key}}</span></td>
                        <td>
                            <a title="{{ $deliveryMen['f_name'].' '.$deliveryMen['l_name'] }}" class="k-row text-dark text-hover-primary"
                               href="{{ route('admin.delivery-man.earning-statement-overview', ['id' => $deliveryMen['id']]) }}">
                                <img alt="" width="40" height="40"
                                     style="border-radius:50%;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)"
                                     src="{{getStorageImages(path:$deliveryMen->image_full_url,type:'backend-profile')}}">
                                <span>{{ $deliveryMen['f_name'].' '.$deliveryMen['l_name'] }}</span>
                            </a>
                        </td>
                        <td>
                            <div><a class="text-dark text-hover-primary" href="mailto:{{$deliveryMen['email']}}"><strong>{{$deliveryMen['email']}}</strong></a></div>
                            <a class="text-dark text-hover-primary k-num" href="tel:{{$deliveryMen['country_code']}}{{$deliveryMen['phone']}}">
                                {{ $deliveryMen['country_code'].$deliveryMen['phone'] }}</a>
                        </td>
                        <td class="k-table__num">
                            <a href="{{ route('admin.orders.list', ['all', 'delivery_man_id' => $deliveryMen['id']]) }}" class="k-badge k-badge--accent">
                                <span class="k-num">{{ $deliveryMen->orders_count }}</span>
                            </a>
                        </td>
                        <td class="k-table__num">
                            <a href="{{ route('admin.delivery-man.rating', ['id' => $deliveryMen['id']]) }}" class="k-badge k-badge--info">
                                <span class="k-num">{{ isset($deliveryMen->rating[0]->average) ? number_format($deliveryMen->rating[0]->average, 2, '.', ' ') : 0 }}</span>
                                <i class="fi fi-sr-star"></i>
                            </a>
                        </td>
                        <td>
                            <form action="{{route('admin.delivery-man.status-update')}}"
                                  method="post" id="deliveryman_status{{$deliveryMen['id']}}-form"
                                  class="no-reload-form">
                                @csrf
                                <input type="hidden" name="id" value="{{$deliveryMen['id']}}">
                                <label class="switcher mx-auto" for="deliveryman_status{{$deliveryMen['id']}}">
                                    <input
                                        class="switcher_input custom-modal-plugin"
                                        type="checkbox" value="1" name="status"
                                        id="deliveryman_status{{$deliveryMen['id']}}"
                                        {{ $deliveryMen->is_active == 1 ? 'checked':'' }}
                                        data-modal-type="input-change-form"
                                        data-modal-form="#deliveryman_status{{$deliveryMen['id']}}-form"
                                        data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/deliveryman-status-on.png') }}"
                                        data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/deliveryman-status-off.png') }}"
                                        data-on-title = "{{translate('Want_to_Turn_ON_Deliveryman_Status').'?'}}"
                                        data-off-title = "{{translate('Want_to_Turn_OFF_Deliveryman_Status').'?'}}"
                                        data-on-message = "<p>{{translate('if_enabled_this_deliveryman_can_log_in_to_the_system_and_deliver_products')}}</p>"
                                        data-off-message = "<p>{{translate('if_disabled_this_deliveryman_cannot_log_in_to_the_system_and_deliver_any_products')}}</p>"
                                        data-on-button-text="{{ translate('turn_on') }}"
                                        data-off-button-text="{{ translate('turn_off') }}">
                                    <span class="switcher_control"></span>
                                </label>
                            </form>
                        </td>
                        <td>
                            <div class="k-table__actions">
                                <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon edit" title="{{translate('edit')}}" href="{{route('admin.delivery-man.edit',[$deliveryMen['id']])}}">
                                    <x-k.icon name="edit" :size="15" />
                                </a>
                                <a title="{{ translate('earning_Statement') }}" class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                   href="{{ route('admin.delivery-man.earning-statement-overview', ['id' => $deliveryMen['id']]) }}">
                                    <x-k.icon name="reports" :size="15" />
                                </a>
                                <span class="k-btn k-btn--ghost k-btn--sm k-btn--icon delete delete-data" role="button"
                                      data-id="delivery-man-{{$deliveryMen['id']}}" title="{{ translate('delete')}}">
                                    <x-k.icon name="trash" :size="15" />
                                </span>
                            </div>
                            <form action="{{route('admin.delivery-man.delete',[$deliveryMen['id']])}}"
                                    method="post" id="delivery-man-{{$deliveryMen['id']}}">
                                @csrf @method('delete')
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>

            @if(count($deliveryMens)==0)
                <x-k.empty icon="customers" :title="translate('no_delivery_man_found')">
                    <x-slot:action>
                        <x-k.button variant="primary" icon="plus" :href="route('admin.delivery-man.add')">
                            {{ translate('Add_Delivery_Man') }}
                        </x-k.button>
                    </x-slot:action>
                </x-k.empty>
            @endif

            @if ($deliveryMens->total() > 0)
                <x-slot:pager>
                    <span class="k-pager__info">
                        {{ translate('showing') }}
                        <span class="k-num">{{ $deliveryMens->firstItem() }}–{{ $deliveryMens->lastItem() }}</span>
                        {{ translate('of') }} <span class="k-num">{{ $deliveryMens->total() }}</span>
                    </span>
                    <div>{!! $deliveryMens->appends(request()->except('page'))->links() !!}</div>
                </x-slot:pager>
            @endif
        </x-k.data-view>
    </div>
@endsection

@push('script_2')
    <script src="{{dynamicAsset(path: 'public/assets/back-end/js/admin/deliveryman.js')}}"></script>
@endpush
