@php use Carbon\Carbon; @endphp
@extends('layouts.admin.app')

@section('title', translate('support_Ticket'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{dynamicAsset(path: 'public/assets/back-end/img/support_ticket.png')}}" alt="">
                {{translate('support_ticket')}}
                <span class="badge badge-soft-dark radius-50 fz-14">{{ $tickets->total() }}</span>
            </h2>
        </div>

        <div class="row mt-20">
            <div class="col-md-12">
                <div class="">
                    <div class="py-3 mb-3 border-bottom">
                        <div class="d-flex flex-wrap justify-content-between gap-3 align-items-center">
                            <div class="flex-grow-1 max-w-300 min-w-100-mobile">
                                <form action="{{ url()->current() }}" method="GET">
                                    <div class="form-group mb-0">
                                        <div class="input-group">
                                            <input type="search" value="{{ requestString('searchValue') }}" name="searchValue" class="form-control" placeholder="{{translate('search_ticket_by_subject_or_status').'...'}}">
                                            <div class="input-group-append search-submit">
                                                <button type="submit">
                                                    <i class="fi fi-rr-search"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <div class="d-flex flex-wrap gap-3 align-items-center justify-content-start justify-content-md-end min-w-100-mobile">
                                @php($priority=request()->has('priority')?request()->input('priority'):'')
                                <div class="select-wrapper flex-grow-1 max-w-200 min-w-100-mobile">
                                    <select class="form-select fs-12 filter-tickets" data-value="priority">
                                        <option value="all">{{translate('all_Priority')}}</option>
                                        <option value="low" {{$priority=='low'?'selected':''}}>{{translate('low')}}</option>
                                        <option value="medium" {{$priority=='medium'?'selected':''}}>{{translate('medium')}}</option>
                                        <option value="high" {{$priority=='high'?'selected':''}}>{{translate('high')}}</option>
                                        <option value="urgent" {{$priority=='urgent'?'selected':''}}>{{translate('urgent')}}</option>
                                    </select>
                                </div>

                                @php($status=request()->has('status') ? request()->input('status'):'')
                                <div class="select-wrapper flex-grow-1 max-w-200 min-w-100-mobile">
                                    <select class="form-select fs-12 filter-tickets" data-value="status">
                                        <option value="all">{{translate('all_Status')}}</option>
                                        <option value="open" {{$status=='open'?'selected':''}}>{{translate('open')}}</option>
                                        <option value="close" {{$status=='close'?'selected':''}}>{{translate('close')}}</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    @foreach($tickets as $key => $ticket)
                        <div class="border-bottom mb-3 pb-3">
                            <div class="card">
                                <div class="card-body align-items-center d-flex flex-wrap justify-content-between gap-3 border-bottom">
                                    <div class="d-flex gap-3">
                                        @if($ticket->customer)
                                        <img width="50" height="50" class="rounded object-fit-cover flex-shrink-0" src="{{ getStorageImages(path: $ticket->customer->image_full_url??"", type: 'backend-profile') }}" alt="">

                                        <div class="flex-grow-1">
                                            <h5 class="mb-0">{{$ticket->customer->f_name??""}} {{$ticket->customer->l_name??""}}</h5>
                                            <div class="mb-2 fs-12">{{$ticket->customer->email??""}}</div>
                                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                                <span class="badge text-bg-danger badge-danger badge-sm">
                                                    {{ translate(str_replace('_',' ',$ticket->priority)) }}
                                                </span>
                                                <span class="badge text-bg-info badge-info badge-sm">
                                                    {{ translate(str_replace('_',' ', $ticket->status)) }}
                                                </span>
                                                <h6 class="mb-0">
                                                    {{ translate(str_replace('_',' ',$ticket->type)) }}
                                                </h6>
                                            </div>
                                            <div class="text-nowrap fs-12 mt-2">
                                                @if ($ticket->created_at->diffInDays(Carbon::now()) < 7)
                                                    {{ date('D h:i:A',strtotime($ticket->created_at)) }}
                                                @else
                                                    {{ date('d M Y h:i:A',strtotime($ticket->created_at)) }}
                                                @endif
                                            </div>
                                        </div>
                                        @else
                                            <h6>{{ translate('customer_not_found').'!' }}</h6>
                                        @endif
                                    </div>

                                    <form action="{{route('admin.support-ticket.status')}}" method="post"
                                          id="support-ticket-{{ $ticket['id'] }}-form" class="no-reload-form">
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $ticket['id'] }}">
                                        <label class="switcher ms-auto">
                                            <input
                                                class="switcher_input custom-modal-plugin"
                                                type="checkbox" name="status"
                                                value="{{ $ticket['status'] == 'open' ? 'close' : 'open' }}"
                                                {{ $ticket['status'] == 'open' ? 'checked':'' }}
                                                data-modal-type="input-change-form"
                                                data-modal-form="#support-ticket-{{ $ticket['id'] }}-form"
                                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/support-ticket-on.png') }}"
                                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/support-ticket-off.png') }}"
                                                data-on-title="{{ translate('Want_to_Turn_ON_Support_Ticket_Status').'?' }}"
                                                data-off-title="{{ translate('Want_to_Turn_OFF_Support_Ticket_Status').'?' }}"
                                                data-on-message="<p>{{ translate('if_enabled_this_support_ticket_will_be_active') }}</p>"
                                                data-off-message="<p>{{ translate('if_disabled_this_support_ticket_will_be_inactive') }}</p>">
                                            <span class="switcher_control"></span>
                                        </label>
                                    </form>
                                </div>
                                <div class="card-body align-items-center d-flex flex-wrap flex-md-nowrap justify-content-between gap-4">
                                    <div class="fs-12">
                                        {{$ticket->description}}
                                    </div>
                                    <div class="text-nowrap">
                                        <a class="btn btn-primary"
                                           href="{{route('admin.support-ticket.singleTicket',$ticket['id'])}}">
                                           <i class="fi fi-rr-arrow-up-right-from-square"></i> {{translate('view')}}
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4">
                    <div class="px-4 d-flex justify-content-center justify-content-md-end">
                        {{$tickets->links()}}
                    </div>
                </div>
                @if(count($tickets)==0)
                    @include('layouts.admin.partials._empty-state',['text'=>'no_support_ticket_found'],['image'=>'default'])
                @endif
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/back-end/js/admin/support-tickets.js')}}"></script>
@endpush
