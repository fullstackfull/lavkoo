@extends('layouts.admin.app')

@section('title', translate('contact_View'))

@section('content')
    <div class="content container-fluid">
        <div class="container">
            <div class="mb-3">
                <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                    <img width="20" src="{{dynamicAsset(path: 'public/assets/back-end/img/message.png')}}" alt="">
                    {{translate('message_view')}}
                </h2>
            </div>

            <div class="row">
                <div class="col-lg-6">
                    <div class="card mb-3">
                        <div class="card-header d-flex gap-2 flex-wrap align-items-center justify-content-between">
                            <h4 class="mb-0 text-capitalize d-flex gap-2 align-items-center text-capitalize">
                                <i class="fi fi-sr-user"></i>
                                {{translate('user_details')}}
                            </h4>
                            <form action="{{route('admin.contact.update',$contact->id)}}" method="post" id="submit-form">
                                @csrf
                                <div class="d-flex justify-content-end">
                                    @if($contact->seen==0)
                                        <button type="button" class="btn btn-success form-alert" data-id="submit-form" data-message="{{translate('want_check_this_message').'?'}}">
                                            <i class="fi fi-rr-check-circle"></i> {{translate('check')}}
                                        </button>
                                    @else
                                        <button type="button" class="btn btn-info text-capitalize" disabled>
                                            <i class="fi fi-rr-check-circle"></i> {{translate('already_check')}}
                                        </button>
                                    @endif
                                </div>
                            </form>
                        </div>
                        <div class="card-body">
                            <div class="ps-2 d-flex gap-2 align-items-center mb-3">
                                <strong class="">{{$contact->subject}}</strong>
                                @if($contact->seen==1)
                                    <label class="badge text-bg-info badge-info mb-0">{{translate('seen')}}</label>
                                @else
                                    <label class="badge badge-soft-info mb-0 text-capitalize">{{translate('not_seen_yet')}}</label>
                                @endif
                            </div>
                            <table class="table table-sm table-user-information table-borderless mb-0">
                                <tbody>
                                    <tr>
                                        <td>{{translate('name')}}:</td>
                                        <td>{{$contact['name']}}</td>
                                    </tr>
                                    <tr>
                                        <td>{{translate('mobile_no')}}:</td>
                                        <td>{{$contact['mobile_number']}}</td>
                                    </tr>
                                    <tr>
                                        <td>{{translate('email')}}:</td>
                                        <td>{{$contact['email']}}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card mb-3">
                        <div class="card-header justify-content-center">
                            <h4 class="mb-0 text-capitalize text-center">
                                {{translate('message_Log')}}
                            </h4>
                        </div>
                        <div class="card-body d-flex flex-column gap-2">
                            <div class="mb-3">
                                <h5 class="badge text-bg-info badge-info rounded mb-3 d-flex">{{ $contact->name }}</h5>
                                <div class="d-flex gap-2 mb-1">
                                    <strong>{{translate('subject')}}: </strong>
                                    <div><strong>{{$contact->subject}}</strong></div>
                                </div>
                                <div class="d-flex gap-2">
                                    <strong>{{translate('message')}}: </strong>
                                    <div>{{$contact->message}}</div>
                                </div>
                            </div>
                            <div>
                                <h5 class="badge text-bg-warning badge-warning rounded mb-3 d-flex">{{translate('admin')}}</h5>
                                @if($contact['reply']!=null)
                                    @php($data=json_decode($contact['reply'],true))
                                    <div class="d-flex gap-2 mb-1">
                                        <strong>{{translate('subject')}}: </strong>
                                        <div><strong>{{$data['subject']}}</strong></div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <strong>{{translate('message')}}: </strong>
                                        <div>{{$data['body']}}</div>
                                    </div>
                                @else
                                    <label class="badge text-white bg-danger">{{translate('no_reply')}}.</label>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-body mt-3 mx-lg-4">
                            <div class="row text-start">
                                <div class="col-12">
                                    <div class="d-flex justify-content-center">
                                        <h3>{{translate('send_Mail')}}</h3>
                                        <?php
                                            $emailServices_smtp = getWebConfig(name: 'mail_config');
                                            if ($emailServices_smtp['status'] == 0) {
                                                $emailServices_smtp = getWebConfig(name: 'mail_config_sendgrid');
                                            }
                                        ?>
                                        @if($emailServices_smtp['status'] != 1)
                                            <label class="badge-soft-danger px-1">{{translate('configure_your_mail_setup_first').'.'}}</label>
                                        @endif
                                    </div>
                                    <form action="{{route('admin.contact.send-mail', $contact->id)}}" method="post">
                                        @csrf
                                        <div class="form-group mt-2">
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <label class="mb-2">{{translate('subject')}}</label>
                                                    <input class="form-control" name="subject" required placeholder="{{translate('subject')}}">
                                                </div>
                                                <div class="col-md-12 mt-3">
                                                    <label class="mb-2">{{translate('mail_Body')}}</label>
                                                    <textarea class="form-control h-100" name="mail_body"
                                                              placeholder="{{translate('please_send_a_Feedback')}}" required></textarea>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-end pt-3 mt-5">
                                            <button type="submit" class="btn btn-primary">
                                            {{translate('send')}} <i class="fi fi-rr-paper-plane-top"></i>
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
