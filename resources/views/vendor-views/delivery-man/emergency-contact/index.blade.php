@extends('layouts.vendor.app')
@section('title',translate('emergency_Contact'))
@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{dynamicAsset(path: 'public/assets/back-end/img/add-new-delivery-man.png')}}" alt="">
                {{translate('emergency_Contact')}}
            </h2>
        </div>
        <div class="row">
            <div class="col-12">
                <form action="{{route('vendor.delivery-man.emergency-contact.index')}}" method="post">
                    @csrf
                    <div class="card">
                        <div class="card-body">
                            <h5 class="mb-0 page-header-title d-flex align-items-center gap-2 border-bottom pb-3 mb-3">
                                <i class="tio-user"></i>
                                {{translate('add_new_contact_information')}}
                            </h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="title-color d-flex"
                                               for="f_name">{{translate('contact_name')}}</label>
                                        <input type="text" name="name" class="form-control"
                                               placeholder="{{translate('contact_name')}}"
                                               required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="title-color d-flex" for="exampleFormControlInput1">
                                            {{ translate('phone') }}
                                        </label>
                                        <div class="input-group mb-3">
                                            <input class="form-control" type="tel" name="phone"
                                                   value="{{ old('phone') }}"
                                                   placeholder="{{ translate('enter_phone_number') }}" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex gap-3 justify-content-end">
                                <button type="reset" id="reset"
                                        class="btn btn-secondary px-4">{{translate('reset')}}</button>
                                <button type="submit"
                                        class="btn btn--primary px-4">{{translate('submit')}}</button>
                            </div>
                        </div>
                    </div>
                </form>
                <div class="mt-3">
                    <x-k.card :title="translate('contact_information_Table')" :padded="false">
                        <x-slot:actions>
                            <span class="k-tab__count k-num">{{ $contacts->count() }}</span>
                        </x-slot:actions>
                        <div class="k-table-wrap">
                            <table class="k-table">
                                <thead>
                                <tr>
                                    <th>{{translate('SL')}}</th>
                                    <th>{{translate('name')}}</th>
                                    <th>{{translate('phone')}}</th>
                                    <th>{{translate('status')}}</th>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($contacts as $contact)
                                    <tr>
                                        <td><span class="k-num">{{ $contacts->firstItem() + $loop->index }}</span></td>
                                        <td class="text-capitalize">{{ $contact['name'] }}</td>
                                        <td>
                                            <a class="title-color hover-c1 k-num" href="tel:{{ $contact['phone'] }}">
                                                {{ $contact['phone'] }}
                                            </a>
                                        </td>
                                        <td>
                                            <form action="{{route('vendor.delivery-man.emergency-contact.index')}}" method="post" id="contact_status{{$contact['id']}}-form" class="contact_status_form">
                                                @csrf @method('patch')
                                                <input hidden name="id" value="{{$contact['id']}}">
                                                <label class="switcher mx-auto">
                                                    <input type="checkbox" class="switcher_input toggle-switch-message" id="contact_status{{$contact['id']}}" name="status" value="1" {{ $contact->status == 1 ? 'checked':'' }}
                                                        data-modal-id = "toggle-status-modal"
                                                        data-toggle-id = "contact_status{{$contact['id']}}"
                                                        data-on-image = ""
                                                        data-off-image = ""
                                                        data-on-title = "{{translate('are_you_sure').'?'}}"
                                                        data-off-title = "{{translate('are_you_sure').'?'}}"
                                                        data-on-message = "<p>{{translate('want_to_change_status')}}</p>"
                                                        data-off-message = "<p>{{translate('want_to_change_status')}}</p>"
                                                    >
                                                    <span class="switcher_control"></span>
                                                </label>
                                            </form>
                                        </td>
                                        <td>
                                            <div class="k-table__actions">
                                                <button type="button" class="k-btn k-btn--ghost k-btn--sm k-btn--icon emergency-contact-update-view"
                                                        title="{{translate('edit')}}"
                                                        data-action="{{ route('vendor.delivery-man.emergency-contact.update',['id'=>$contact->id]) }}">
                                                    <x-k.icon name="edit" :size="15" />
                                                </button>
                                                <span class="k-btn k-btn--ghost k-btn--sm k-btn--icon delete delete-data" role="button"
                                                      data-id="delete-contact-{{$contact->id}}"
                                                      title="{{ translate('delete')}}">
                                                    <x-k.icon name="trash" :size="15" />
                                                </span>
                                            </div>
                                            <form action="{{route('vendor.delivery-man.emergency-contact.index')}}"
                                                  method="post" id="delete-contact-{{$contact->id}}">
                                                @csrf @method('delete')
                                                <input type="hidden" name="id" value="{{ $contact->id }}">
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if(count($contacts)==0)
                            <x-k.empty icon="customers" :title="translate('no_contact_found')" />
                        @endif
                        @if ($contacts->total() > 0)
                            <x-slot:footer>
                                {!! $contacts->appends(request()->except('page'))->links() !!}
                            </x-slot:footer>
                        @endif
                    </x-k.card>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade emergency-contact-update-modal" tabindex="-1" aria-labelledby="toggle-modal" aria-hidden="true">
    </div>
@endsection

@push('script_2')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/vendor/emergency-contact.js') }}"></script>
@endpush
