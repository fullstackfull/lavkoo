@extends('layouts.admin.app')

@section('title', translate('employee_details'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{dynamicAsset(path: 'public/assets/back-end/img/employee.png')}}" width="20" alt="">
                {{translate('employee_details')}}
            </h2>
        </div>
        <div class="card mt-3">
            <div class="card-body">
                <h3 class="mb-3 text-primary">{{'#'.translate('EMP').' '.$employee['id']}}</h3>
                <div class="row g-2">
                    <div class="col-lg-7 col-xl-8">
                        <div class="media align-items-center flex-wrap flex-sm-nowrap gap-3">
                            <img width="250" class="rounded border"
                            src="{{getStorageImages(path: $employee->image_full_url,type:'backend-profile')}}" alt="{{translate('image_Description')}}">
                            <div class="media-body">
                                <div class="text-capitalize mb-4">
                                    <h3 class="mb-2">{{$employee->name}}</h3>
                                    <p>{{ isset($employee->role) ? $employee->role->name : translate('role_not_found')}}</p>
                                </div>

                                <ul class="d-flex flex-column gap-2 px-0">
                                    <li class="d-flex gap-2 align-items-center">
                                        <i class="fi fi-rr-phone-flip"></i>
                                        <a href="tel:{{$employee->phone}}" class="text-dark">{{$employee->phone}}</a>
                                    </li>
                                    <li class="d-flex gap-2 align-items-center">
                                        <i class="fi fi-rr-envelope"></i>
                                        <a href="mailto:{{$employee->email}}" class="text-dark">{{$employee->email}}</a>
                                    </li>
                                    @if (!empty($employee->identify_type))
                                        <li class="d-flex gap-2 align-items-center">
                                            <i class="fi fi-rr-calendar-day"></i>
                                            <span class="text-dark text-uppercase">{{$employee->identify_type.' '.'-'.' '.$employee?->identify_number ?? translate('identify_number_not_found')}}</span>
                                        </li>
                                    @endif
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5 col-xl-4">
                        <div class="bg-primary-light rounded p-3">
                            <div class="bg-white rounded p-3">
                                <div class="d-flex gap-2 align-items-center">
                                    <i class="fi fi-rr-calendar-day"></i>
                                    <span class="text-dark">{{translate('join').' '.':'.' '.date('d/m/Y',strtotime($employee->created_at))}}</span>
                                </div>
                            </div>
                            <div class="bg-white rounded p-3 mt-3">
                                <div class="d-flex justify-content-between gap-3">
                                    <div class="d-flex flex-column gap-4">
                                        <div class="d-flex gap-2 align-items-center">
                                            <i class="fi fi-rr-portrait"></i>
                                            <h4 class="text-dark mb-0 text-capitalize">{{ translate('access_available').' : '.' '}}</h4>
                                        </div>
                                        @if (isset($employee->role))
                                            <div class="tags d-flex gap-2 flex-wrap text-capitalize">
                                                @foreach (json_decode($employee->role->module_access) ?? [] as $key=>$value)
                                                    <span class="badge badge-info text-bg-info">{{str_replace('_' ,' ',$value)}}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                    <a href="{{route('admin.employee.update',[$employee['id']])}}">
                                        <i class="fi fi-rr-pencil" data-bs-toggle="tooltip" data-bs-title="{{translate('you_can_create_or_edit_role_form_employee_role_setup')}}"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="d-flex justify-content-end">
                            <a href="{{route('admin.employee.update',[$employee['id']])}}" class="btn btn-primary">
                                <i class="fi fi-rr-pencil"></i>
                                {{translate('edit')}}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
