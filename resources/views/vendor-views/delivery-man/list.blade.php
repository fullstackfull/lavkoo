@extends('layouts.vendor.app')
@section('title',translate('deliveryman_List'))
@push('css_or_js')
    <meta name="csrf-token" content="{{ csrf_token() }}">
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{dynamicAsset(path: 'public/assets/back-end/img/deliveryman.png')}}" alt="">
                {{translate('deliveryman_List')}}
                <span class="badge badge-soft-dark radius-50 fz-14">{{ $deliveryMen->total() }}</span>
            </h2>
        </div>

        <x-k.data-view :title="translate('deliveryman_List')" :count="$deliveryMen->total()"
                       searchName="search" :searchValue="requestString('search')"
                       :searchPlaceholder="translate('search_by_name').','.translate('_contact_info')">

            <x-slot:actions>
                <a class="k-btn k-btn--secondary"
                   href="{{route('vendor.delivery-man.export',['search' => request('search'), 'sort_by' => request('sort_by')])}}">
                    <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                </a>
                <div class="dropdown">
                    <button class="k-btn {{ !empty(request('sort_by')) ? 'k-btn--primary' : 'k-btn--secondary' }}"
                            type="button" id="exportDropdown" data-toggle="dropdown"
                            aria-haspopup="true" aria-expanded="false">
                        <x-k.icon name="filter" :size="15" />
                        {{ translate('Sorting') }}
                        <x-k.icon name="chevron-down" :size="13" />
                    </button>
                    <div class="dropdown-menu dropdown-menu-right rounded mt-1 py-2 w-100 min-w-200"
                         aria-labelledby="exportDropdown">
                        @php($deliveryManSortOptions = [
                            'latest' => translate('Default') . ' (' . translate('Recent created') . ')',
                            'oldest' => translate('Show Older First'),
                            'rating' => translate('Top Delivery Man'),
                        ])
                        @foreach($deliveryManSortOptions as $sortKey => $sortLabel)
                            @php($sortActive = $sortKey === 'latest' ? (empty(request('sort_by')) || request('sort_by') == 'latest') : request('sort_by') == $sortKey)
                            <a class="dropdown-item d-flex align-items-center gap-2 px-3 py-2"
                               href="{{ route('vendor.delivery-man.list', ['sort_by' => $sortKey, 'search' => request('search')]) }}">
                                <span class="{{ $sortActive ? 'text-success' : 'text-dark' }} pt-1">
                                    <i class="fi {{ $sortActive ? 'fi-sr-check-circle' : 'fi-sr-circle' }}"></i>
                                </span>
                                {{ $sortLabel }}
                            </a>
                        @endforeach
                    </div>
                </div>
                <a href="{{route('vendor.delivery-man.index')}}" class="k-btn k-btn--primary">
                    <x-k.icon name="plus" :size="15" /> {{translate('add_Delivery_Man')}}
                </a>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{translate('SL')}}</th>
                    <th>{{translate('name')}}</th>
                    <th>{{translate('contact_Info')}}</th>
                    <th class="k-table__num">{{translate('total_Orders')}}</th>
                    <th class="k-table__num">{{translate('rating')}}</th>
                    <th>{{translate('status')}}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody id="set-rows">
                @foreach($deliveryMen as $key=>$deliveryMan)
                    <tr>
                        <td><span class="k-num">{{$deliveryMen->firstitem()+$key}}</span></td>
                        <td>
                            <a title="{{ translate('earning_Statement') }}" class="k-row title-color hover-c1"
                               href="{{ route('vendor.delivery-man.wallet.index', ['id' => $deliveryMan['id']]) }}">
                                <img alt="" width="40" height="40"
                                     style="border-radius:50%;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)"
                                     src="{{getStorageImages($deliveryMan->image_full_url,type:'backend-profile')}}">
                                <span>{{$deliveryMan['f_name'].' '.$deliveryMan['l_name']}}</span>
                            </a>
                        </td>
                        <td>
                            <div><a class="title-color hover-c1"
                                    href="mailto:{{$deliveryMan['email']}}"><strong>{{$deliveryMan['email']}}</strong></a>
                            </div>
                            <a class="title-color hover-c1 k-num"
                               href="tel:{{$deliveryMan['country_code']}}{{$deliveryMan['phone']}}">
                                {{$deliveryMan['country_code'].$deliveryMan['phone']}}</a>
                        </td>
                        <td class="k-table__num">
                            <a href="{{ route('vendor.orders.list', ['all', 'delivery_man_id' => $deliveryMan['id']]) }}"
                               class="k-badge k-badge--accent">
                                <span class="k-num">{{ $deliveryMan->orders_count }}</span>
                            </a>
                        </td>
                        <td class="k-table__num">
                            <a href="{{ route('vendor.delivery-man.rating', ['id' => $deliveryMan['id']]) }}"
                               class="k-badge k-badge--info">
                                <span class="k-num">{{ isset($deliveryMan->rating[0]->average) ? number_format($deliveryMan->rating[0]->average, 2, '.', ' ') : 0 }}</span>
                                <i class="tio-star"></i>
                            </a>
                        </td>
                        <td>
                            <form action="{{route('vendor.delivery-man.update-status',[$deliveryMan['id']])}}"
                                  method="post" id="deliveryman_status{{$deliveryMan['id']}}-form"
                                  class="deliveryman_status_form">
                                @csrf
                                <label class="switcher mx-auto">
                                    <input type="checkbox" class="switcher_input toggle-switch-message"
                                           id="deliveryman_status{{$deliveryMan['id']}}" name="status" value="1"
                                           {{ $deliveryMan->is_active == 1 ? 'checked':'' }}
                                           data-modal-id="toggle-status-modal"
                                           data-toggle-id="deliveryman_status{{$deliveryMan['id']}}"
                                           data-on-image="deliveryman-status-on.png"
                                           data-off-image="deliveryman-status-off.png"
                                           data-on-title="{{translate('Want_to_Turn_ON_Deliveryman_Status').'?'}}"
                                           data-off-title="{{translate('Want_to_Turn_OFF_Deliveryman_Status').'?'}}"
                                           data-on-message="<p>{{translate('if_enabled_this_deliveryman_can_log_in_to_the_system_and_deliver_products')}}</p>"
                                           data-off-message="<p>{{translate('if_disabled_this_deliveryman_cannot_log_in_to_the_system_and_deliver_any_products')}}</p>"
                                    >
                                    <span class="switcher_control"></span>
                                </label>
                            </form>
                        </td>
                        <td>
                            <div class="k-table__actions">
                                <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                   href="{{route('vendor.delivery-man.update',[$deliveryMan['id']])}}"
                                   title="{{translate('edit')}}">
                                    <x-k.icon name="edit" :size="15" />
                                </a>
                                <a title="{{ translate('earning_Statement') }}"
                                   class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                   href="{{ route('vendor.delivery-man.wallet.index', ['id' => $deliveryMan['id']]) }}">
                                    <x-k.icon name="reports" :size="15" />
                                </a>
                                <span class="k-btn k-btn--ghost k-btn--sm k-btn--icon delete-data" role="button"
                                      data-id="delivery-man-{{$deliveryMan['id']}}"
                                      title="{{translate('delete')}}">
                                    <x-k.icon name="trash" :size="15" />
                                </span>
                                <form action="{{route('vendor.delivery-man.delete',[$deliveryMan['id']])}}"
                                      method="post" id="delivery-man-{{$deliveryMan['id']}}">
                                    @csrf @method('delete')
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if(count($deliveryMen)==0)
                <x-k.empty icon="customers" :title="translate('no_delivery_man_found')"
                           :text="request('search') ? null : translate('add_a_delivery_man_to_start_self_delivery')">
                    <x-slot:action>
                        <x-k.button variant="primary" icon="plus" :href="route('vendor.delivery-man.index')">
                            {{ translate('add_Delivery_Man') }}
                        </x-k.button>
                    </x-slot:action>
                </x-k.empty>
            @endif

            @if ($deliveryMen->total() > 0)
                <x-slot:pager>
                    <span class="k-pager__info">
                        {{ translate('showing') }}
                        <span class="k-num">{{ $deliveryMen->firstItem() }}–{{ $deliveryMen->lastItem() }}</span>
                        {{ translate('of') }} <span class="k-num">{{ $deliveryMen->total() }}</span>
                    </span>
                    <div>{!! $deliveryMen->appends(request()->except('page'))->links() !!}</div>
                </x-slot:pager>
            @endif
        </x-k.data-view>
    </div>
    <span id="deliveryman-status-message" data-text="{{translate("status_updated_successfully")}}"></span>
@endsection

@push('script_2')
    <script src="{{dynamicAsset(path: 'public/assets/back-end/js/vendor/deliveryman.js')}}"></script>
@endpush
