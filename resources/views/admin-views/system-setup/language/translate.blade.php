@php
    use Illuminate\Support\Facades\Session;
@endphp
@extends('layouts.admin.app')

@section('title', translate('language_Translate'))

@push('css_or_js')
    <link href="{{dynamicAsset(path: 'public/assets/new/back-end/libs/datatables/datatables.min.css')}}" rel="stylesheet">
@endpush

@section('content')
    @php($direction = Session::get('direction') === "rtl" ? 'right' : 'left')
    <div class="content container-fluid">

        <div class="mb-4 pb-2">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{dynamicAsset(path: 'public/assets/back-end/img/language.png')}}" alt="">
                {{translate('View Translations -')}} {{ $languageName . '(' . $lang . ')' }}
            </h2>

            <a href="{{route('admin.system-setup.language.index')}}" class="d-flex mt-2 gap-1 align-items-center text-decoration-none">
                <i class="fi fi-rr-arrow-small-left mt-1 fs-5"></i>
                <span class="text text-capitalize">{{translate('Back to Language Setup')}}</span>
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mb-3">
                    <i class="fi fi-sr-info text-warning"></i>
                    <span>
                        {{ translate('After_change_or_type') }} <strong>{{ translate('_translated_value') }}</strong> {{ translate('_or_click') }} <strong> {{ translate('Auto_Translate') }}</strong>{{ translate('_button') }},{{ translate('_make_sure_you_click') }}<strong> {{ translate('Save_Button') }}</strong> {{ translate('_to_save_the_changes.') }}
                    </span>
                </div>

                <div class="card">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-3 border-0">
                        <h3 class="m-0">{{translate('Language Content Table')}}</h3>
                        <div class="d-flex flex-wrap flex-sm-nowrap align-items-center gap-3">
                            <div>
                                <div class="form-group">
                                    <div class="input-group">
                                        <input id="searchLanguage" type="search" class="form-control" placeholder="{{ translate('Search_language...') }}">
                                        <div class="input-group-append search-submit">
                                            <button type="submit">
                                                <i class="fi fi-rr-search"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @if(config('app.mode') != 'demo')
                                <button class="btn btn-primary text-nowrap" data-bs-toggle="modal" data-bs-target="#warning-modal">
                                    <i class="fi fi-sr-language-exchange"></i>
                                    {{ translate('Translate_All') }}
                                </button>
                            @else
                                <button class="btn btn-primary text-nowrap call-demo-alert">
                                    <i class="fi fi-sr-language-exchange"></i>
                                    {{ translate('Translate_All') }}
                                </button>
                            @endif
                        </div>
                    </div>
                    <div class="card-body pt-0">
                        <div class="k-table-wrap">
                            <table class="k-table" id="dataTable" width="100%" cellspacing="0">
                                <thead class="bg-white">
                                <tr>
                                    <th class="max-width-100px border-0">{{translate('SL')}}</th>
                                    <th class="width-400px border-0">{{translate('key')}}</th>
                                    <th class="max-width-300px border-0">{{translate('value')}}</th>
                                    <th class="max-width-150px border-0">{{translate('auto_translate')}}</th>
                                    <th class="max-width-150px border-0">{{translate('update')}}</th>
                                </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td colspan="5" class="text-center">
                                            <i class="fa fa-spinner fa-spin"></i> {{ translate('Loading') }}...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade language-complete-modal" id="complete-modal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center">
                    <div class="py-5">
                        <div class="mb-4">
                            <img src="{{dynamicAsset('public/assets/back-end/img/language-complete.png')}}" alt="">
                        </div>
                        <h4 class="mb-3">
                            {{ translate('Your_file_has_been_successfully_translated') }}
                        </h4>
                        <p class="mb-4 text-9EADC1 max-w-362px mx-auto translateCountSuccess"></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="warning-modal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body">
                    <div class="d-flex gap-3 align-items-center mb-3">
                        <div>
                            <img src="{{ dynamicAsset('public/assets/back-end/img/invalid-icon.png') }}" alt="">
                        </div>
                        <div class="w-0 flex-grow-1">
                            <h3>{{ translate('warning') }}!</h3>
                            <p class="mb-0">
                                {{ translate('are_you_sure,_want_to_start_auto_translation') }} ?
                            </p>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-3">
                        @if(config('app.mode') != 'demo')
                            <button type="button" class="btn btn-primary" data-bs-dismiss="modal" id="translating-modal-start">
                                {{ translate('Continue') }}
                            </button>
                        @else
                            <button type="button" class="btn btn-primary call-demo-alert" data-bs-dismiss="modal">
                                {{ translate('Continue') }}
                            </button>
                        @endif

                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            {{ translate('cancel') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <div class="modal fade language-complete-modal" id="translating-modal" data-backdrop="static"
         data-keyboard="false" aria-labelledby="languageCompleteModalLabel" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center">
                    <div class="py-5 px-sm-2">
                        <div class="progress-circle-container mb-4">
                            <svg class="progress-circle spinner-border border-0 w-43px h-100" width="100" height="100" viewBox="0 0 100 100">
                                <circle class="bg" cx="50" cy="50" r="45"></circle>
                                <circle class="progress" cx="50" cy="50" r="45"></circle>
                            </svg>
                        </div>
                        <h4 class="mb-2">
                            <?php
                                $getDuration = isset($totalMessages) ? ((($totalMessages / 60) / 60) / 30) : 0;
                                $getHours = floor($getDuration);
                                $getMinutes = round(($getDuration - $getHours) * 60);
                            ?>

                            @if($totalMessages <= 0)
                                {{ translate('All_new_message_are_translated') }}
                            @elseif($getDuration < 1)
                                {{ translate('Translating_may_take_up_to') }} {{ $getMinutes }} {{ translate('minutes') }}
                            @elseif($getDuration >= 1)
                                @if($getMinutes == 0)
                                    {{ translate('Translating_may_take_up_to') }} {{ $getHours }} {{ translate('hour') }}
                                @else
                                    {{ translate('Translating_may_take_up_to') }} {{ $getHours }} {{ translate('hour') }} {{ $getMinutes }} {{ translate('minutes') }}
                                @endif
                            @endif

                        </h4>
                        <p class="mb-4">
                            {{ translate('please_do_not_close_the_browser_or_this_page') }}
                        </p>
                        <div class="max-w-215px mx-auto">
                            <div class="d-flex flex-wrap mb-1 justify-content-between font-semibold text-dark">
                                <span>{{ translate('in_Progress') }}</span>
                                <span class="translating-modal-success-rate">{{ translate('0%') }}</span>
                            </div>
                            <div class="progress mb-3 h-5px">
                                <div class="progress-bar bg-success rounded-pill translating-modal-success-bar" style="width: 0%"></div>
                            </div>
                        </div>
                        <p class="mb-4 text-9EADC1">
                            {{ translate('translating_all_the_content_may_take_some_time.') }} {{ translate('if_you_encounter_any_issues_during_the_translation_process,_please_contact_your_administrator.') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <span id="total-messages-of-current-language"
          data-total="{{ $totalMessages }}"
          data-message-group="{{ $totalMessages > 0 ? $messageGroup : 1 }}"
          @if($totalMessages > 0)
            data-message="{{ translate('your') . ' ' . $totalMessages . ' ' . translate('messages_successfully_translated') }}"
          @else
              data-message="{{ translate('all_messages_are_in_translated') }}"
          @endif
    ></span>
    <span id="get-auto-translate-route-and-text" data-route="{{route('admin.system-setup.language.auto-translate', [$lang])}}"
          data-success-text="{{translate('key_translated_successfully')}}">
    </span>
    <span id="get-auto-translate-all-route-and-text" data-route="{{ route('admin.system-setup.language.auto-translate-all', [$lang]) }}"
          data-success-text="{{translate('all_translated_successfully')}}">
    </span>
    <span id="get-translate-route-and-text" data-route="{{route('admin.system-setup.language.translate-submit', [$lang])}}"
          data-success-text="{{translate('text_updated_successfully')}}">
    </span>
    <span id="get-data-table-route-and-text"
          data-route="{{ route('admin.system-setup.language.translate.list', ['lang' => $lang]) }}"
          data-page-length="{{ getWebConfig(name:'pagination_limit') }}"
          data-info="{{ translate('showing').' '.'_START_'.' '.translate('To').' '.'_END_'.' '.translate('of').' '.'_TOTAL_'.' '.translate('entries') }}"
          data-info-empty="{{ translate('showing').' '. 0 .translate('To').' '. 0 .' ' .translate('of').' '. 0 .' '.translate('entries') }}"
          data-info-filtered="{{ translate('filtered').' '.'_MAX_'.' '.translate('total_entries') }}"
          data-empty-table="{{  translate('no_data_found') }}"
          data-zero-records="{{ translate('no_matching_data_found') }}"
          data-search="{{ translate('search').':' }}"
          data-length-menu="{{ translate('show').'_MENU_'.translate('entries')  }}"
          data-paginate-first="{{translate('first')}}"
          data-paginate-last="{{translate('last')}}"
          data-paginate-next="{{translate('next')}}"
          data-paginate-previous="{{translate('previous')}}"
    ></span>
@endsection

@push('script')

    <script src="{{dynamicAsset(path: 'public/assets/new/back-end/libs/datatables/datatables.min.js')}}"></script>
    <script src="{{dynamicAsset(path: 'public/assets/new/back-end/js/translate.js')}}"></script>
@endpush
