@extends('layouts.admin.app')

@section('title', translate('employee_list'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{dynamicAsset(path: 'public/assets/back-end/img/employee.png')}}" width="20" alt="">
                {{translate('employee_list')}}
            </h2>
        </div>
        <x-k.data-view :title="translate('employee_table')" :count="$employees->total()"
                       searchName="searchValue" :searchValue="requestString('searchValue')"
                       :searchPlaceholder="translate('search_by_name_or_email_or_phone')">

            <x-slot:actions>
                <form action="{{ url()->current() }}" method="GET" class="k-row">
                    <input type="hidden" name="searchValue" value="{{ requestString('searchValue') }}">
                    <select class="k-input" style="inline-size:auto" name="admin_role_id"
                            aria-label="{{ translate('role') }}">
                        <option value="all" {{ request('admin_role_id') == 'all' ? 'selected' : '' }}>{{translate('all')}}</option>
                        @foreach($employee_roles as $employee_role)
                            <option value="{{ $employee_role['id'] }}" {{ request('admin_role_id') == $employee_role['id'] ? 'selected' : '' }}>
                                {{ ucfirst($employee_role['name']) }}
                            </option>
                        @endforeach
                    </select>
                    <button type="submit" class="k-btn k-btn--secondary">{{ translate('filter') }}</button>
                </form>
                <a class="k-btn k-btn--secondary"
                   href="{{route('admin.employee.export',['role'=>request('admin_role_id'),'searchValue'=>request('searchValue')])}}">
                    <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                </a>
                <a href="{{route('admin.employee.add-new')}}" class="k-btn k-btn--primary">
                    <x-k.icon name="plus" :size="15" /> {{translate('add_new')}}
                </a>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{translate('SL')}}</th>
                    <th>{{translate('name')}}</th>
                    <th>{{translate('email')}}</th>
                    <th>{{translate('phone')}}</th>
                    <th>{{translate('role')}}</th>
                    <th>{{translate('status')}}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach($employees as $key => $employee)
                    <tr>
                        <td><span class="k-num">{{$key+1}}</span></td>
                        <td class="text-capitalize">
                            <div class="k-row">
                                <img alt="" width="40" height="40"
                                     style="border-radius:50%;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)"
                                     src="{{getStorageImages(path: $employee->image_full_url,type:'backend-profile')}}">
                                <span>{{$employee['name']}}</span>
                            </div>
                        </td>
                        <td>
                            @if(!empty($employee['email']))
                                <a href="mailto:{{ $employee['email'] }}" class="text-decoration-none text-dark">{{ $employee['email'] }}</a>
                            @endif
                        </td>
                        <td>
                            @if(!empty($employee['phone']))
                                <a href="tel:{{ $employee['phone'] }}" class="text-decoration-none text-dark k-num">{{ $employee['phone'] }}</a>
                            @endif
                        </td>
                        <td>
                            {{ $employee?->role?->name ? ucwords($employee?->role?->name) : translate('role_not_found') }}
                        </td>
                        <td>
                            @if($employee['id'] == 1)
                                <x-k.badge tone="accent">{{ translate('admin') }}</x-k.badge>
                            @else
                                <form action="{{route('admin.employee.status')}}" method="post" id="employee-id-{{$employee['id']}}-form" class="no-reload-form">
                                    @csrf
                                    <input type="hidden" name="id" value="{{$employee['id']}}">
                                    <label class="switcher mx-auto" for="employee-id-{{$employee['id']}}">
                                        <input
                                            class="switcher_input custom-modal-plugin"
                                            type="checkbox" value="1" name="status"
                                            id="employee-id-{{$employee['id']}}"
                                            {{$employee->status?'checked':''}}
                                            data-modal-type="input-change-form"
                                            data-modal-form="#employee-id-{{$employee['id']}}-form"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/employee-on.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/employee-off.png') }}"
                                            data-on-title = "{{translate('want_to_Turn_ON_Employee_Status').'?'}}"
                                            data-off-title = "{{translate('want_to_Turn_OFF_Employee_Status').'?'}}"
                                            data-on-message = "<p>{{translate('if_enabled_this_employee_can_log_in_to_the_system_and_perform_his_role')}}</p>"
                                            data-off-message = "<p>{{translate('if_disabled_this_employee_can_not_log_in_to_the_system_and_perform_his_role')}}</p>"
                                            data-on-button-text="{{ translate('turn_on') }}"
                                            data-off-button-text="{{ translate('turn_off') }}">
                                        <span class="switcher_control"></span>
                                    </label>
                                </form>
                            @endif
                        </td>
                        <td>
                            @if($employee['id'] == 1)
                                <x-k.badge tone="neutral">{{ translate('default') }}</x-k.badge>
                            @else
                                <div class="k-table__actions">
                                    <a href="{{route('admin.employee.update',[$employee['id']])}}"
                                       class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                       title="{{translate('edit')}}">
                                        <x-k.icon name="edit" :size="15" />
                                    </a>
                                    <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{ translate('view') }}"
                                       href="{{route('admin.employee.view',['id'=>$employee['id']])}}">
                                        <x-k.icon name="eye" :size="15" />
                                    </a>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if(count($employees)==0)
                <x-k.empty icon="customers" :title="translate('no_employee_found')" />
            @endif

            @if ($employees->total() > 0)
                <x-slot:pager>
                    <span class="k-pager__info">
                        {{ translate('showing') }}
                        <span class="k-num">{{ $employees->firstItem() }}–{{ $employees->lastItem() }}</span>
                        {{ translate('of') }} <span class="k-num">{{ $employees->total() }}</span>
                    </span>
                    <div>{!! $employees->appends(request()->except('page'))->links() !!}</div>
                </x-slot:pager>
            @endif
        </x-k.data-view>
    </div>
@endsection
