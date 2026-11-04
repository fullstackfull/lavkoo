@extends('layouts.admin.app')

@section('title', translate('withdraw_method_list'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-4 pb-2">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/3rd-party.png') }}" alt="">
                {{ translate('Payment & Withdraw Methods Setup') }}
            </h2>
        </div>

        @include('admin-views.third-party._third-party-payment-method-menu')

        <x-k.data-view class="mt-3" :title="translate('Withdraw_Method_List')" :count="$withdrawalMethods->total()"
                       searchName="searchValue" :searchValue="requestString('searchValue')"
                       :searchPlaceholder="translate('Search_by_withdraw_method_name')">

            <x-slot:actions>
                <a href="{{route('admin.third-party.withdraw-method.add')}}" class="k-btn k-btn--primary">
                    <x-k.icon name="plus" :size="15" /> {{translate('Add_New_Method')}}
                </a>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                    <tr>
                        <th>{{translate('SL')}}</th>
                        <th>{{translate('method_name')}}</th>
                        <th>{{ translate('method_fields') }}</th>
                        <th>{{ translate('method_for') }}</th>
                        <th>{{translate('default_method')}}</th>
                        <th>{{translate('status')}}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($withdrawalMethods as $key => $withdrawalMethod)
                        <tr>
                            <td><span class="k-num">{{$withdrawalMethods->firstitem() + $key}}</span></td>
                            <td>{{$withdrawalMethod['method_name']}}</td>
                            <td>
                                @foreach($withdrawalMethod['method_fields'] as $methodField)
                                <span class="fs-12">
                                    {{ translate($methodField['input_name']) }}
                                    @if($methodField['is_required'])
                                        <span class="text-danger">*</span>
                                    @endif
                                    @if(!$loop->last)
                                        ,
                                    @endif
                                </span>
                                @endforeach
                            </td>
                            <td>
                                @if($withdrawalMethod['vendor_status'])
                                    {{ translate('Vendor') }}
                                @endif
                                @if($withdrawalMethod['vendor_status'] && $withdrawalMethod['customer_status'])
                                    ,
                                @endif
                                @if($withdrawalMethod['customer_status'] && getWebConfig(name: 'auction_feature_status'))
                                        {{ translate('Customer') }}
                                @endif
                                @if(!$withdrawalMethod['vendor_status'] && (!$withdrawalMethod['customer_status'] || !getWebConfig(name: 'auction_feature_status')))
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <form action="{{route('admin.third-party.withdraw-method.default-status')}}" method="post"
                                        id="withdrawal-method-default{{$withdrawalMethod['id']}}-form"
                                        class="no-reload-form reload-true">
                                    @csrf
                                    <input type="hidden" name="id" value="{{$withdrawalMethod['id']}}">
                                    <label class="switcher mx-auto" for="withdrawal-method-default{{$withdrawalMethod['id']}}">
                                        <input
                                            class="switcher_input custom-modal-plugin"
                                            type="checkbox" value="1" name="status"
                                            id="withdrawal-method-default{{$withdrawalMethod['id']}}"
                                            {{ $withdrawalMethod['is_default'] == 1 ? 'checked':'' }}
                                            data-modal-type="input-change-form"
                                            data-modal-form="#withdrawal-method-default{{$withdrawalMethod['id']}}-form"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/wallet-on.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/wallet-off.png') }}"
                                            data-on-title = "{{translate('do_you_want_to_turn_on_this_as_the_default_withdraw_method').'?'}}"
                                            data-off-title = "{{translate('do_you_want_to_turn_off_this_as_the_default_withdraw_method').'?'}}"
                                            data-on-message = "<p>{{translate('if_you_enable_this_Withdraw_method_will_be_set_as_Default_Withdraw_Method_in_the_vendor_app_and_vendor_panel')}}</p>"
                                            data-off-message = "<p>{{translate('if_you_disable_this_Withdraw_method_will_be_remove_as_Default_Withdraw_Method_in_the_vendor_app_and_vendor_panel')}}</p>"
                                            data-on-button-text="{{ translate('turn_on') }}"
                                            data-off-button-text="{{ translate('turn_off') }}">
                                        <span class="switcher_control"></span>
                                    </label>
                                </form>
                            </td>
                            <td>
                                <form action="{{route('admin.third-party.withdraw-method.status-update')}}"
                                        method="post" id="withdrawal-method-status{{$withdrawalMethod['id']}}-form"
                                        class="no-reload-form">
                                    @csrf
                                    <input type="hidden" name="id" value="{{$withdrawalMethod['id']}}">
                                    <label class="switcher mx-auto" for="withdrawal-method-status{{$withdrawalMethod['id']}}">
                                        <input
                                            class="switcher_input custom-modal-plugin"
                                            type="checkbox" value="1" name="status"
                                            id="withdrawal-method-status{{$withdrawalMethod['id']}}"
                                            {{ $withdrawalMethod['is_active'] == 1 ? 'checked':'' }}
                                            data-modal-type="input-change-form"
                                            data-modal-form="#withdrawal-method-status{{$withdrawalMethod['id']}}-form"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/wallet-on.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/wallet-off.png') }}"
                                            data-on-title = "{{translate('want_to_Turn_ON_This_Withdraw_Method').'?'}}"
                                            data-off-title = "{{translate('want_to_Turn_OFF_This_Withdraw_Method').'?'}}"
                                            data-on-message = "<p>{{translate('if_you_enable_this_Withdraw_method_will_be_set_as_Default_Withdraw_Method_in_the_vendor_app_and_vendor_panel')}}</p>"
                                            data-off-message = "<p>{{translate('if_you_disable_this_Withdraw_method_will_be_remove_as_Default_Withdraw_Method_in_the_vendor_app_and_vendor_panel')}}</p>"
                                            data-on-button-text="{{ translate('turn_on') }}"
                                            data-off-button-text="{{ translate('turn_off') }}">
                                        <span class="switcher_control"></span>
                                    </label>
                                </form>

                            </td>
                            <td>
                                <div class="k-table__actions">
                                    <a href="{{route('admin.third-party.withdraw-method.edit',[$withdrawalMethod->id])}}"
                                       class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{ translate('edit') }}">
                                        <x-k.icon name="edit" :size="15" />
                                    </a>
                                    @if(!$withdrawalMethod->is_default)
                                        <span class="k-btn k-btn--ghost k-btn--sm k-btn--icon delete-data" role="button" title="{{translate('delete')}}" data-id="delete-{{$withdrawalMethod->id}}">
                                            <x-k.icon name="trash" :size="15" />
                                        </span>
                                    @endif
                                </div>
                                <form action="{{route('admin.third-party.withdraw-method.delete',[$withdrawalMethod->id])}}" method="post" id="delete-{{$withdrawalMethod->id}}">
                                    @csrf @method('delete')
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>

            @if(count($withdrawalMethods)==0)
                <x-k.empty icon="reports" :title="translate('no_withdraw_method_found')" />
            @endif

            @if ($withdrawalMethods->total() > 0)
                <x-slot:pager>
                    <span class="k-pager__info">
                        {{ translate('showing') }}
                        <span class="k-num">{{ $withdrawalMethods->firstItem() }}–{{ $withdrawalMethods->lastItem() }}</span>
                        {{ translate('of') }} <span class="k-num">{{ $withdrawalMethods->total() }}</span>
                    </span>
                    <div>{!! $withdrawalMethods->appends(request()->except('page'))->links() !!}</div>
                </x-slot:pager>
            @endif
        </x-k.data-view>
    </div>
    <span id="get-withdrawal-method-default-text"
          data-success="{{translate('default_method_updated_successfully').'.'}}"
          data-error="{{translate('default_Method_updated_failed').'!!'}}">
    </span>
@endsection
