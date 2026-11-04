<form action="{{ route('admin.helpTopic.update', ['id'=>$help['id']]) }}" method="post" id="update-form-submit">
    @csrf
    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasFaqEdit-{{ $help['id'] }}" aria-labelledby="offcanvasFaqEditLabel">
        <div class="offcanvas-header bg-body">
            <div>
                <h2 class="mb-1">{{ translate('Edit_FAQ') }}</h2>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <div class="mb-3 mb-sm-20">
                <div class="p-12 p-sm-20 bg-section rounded">
                    <div class="form-group">
                        <label class="form-label" for="">{{ translate('Question') }}
                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Update Question" data-bs-title="Update Question">
                                    <i class="fi fi-sr-info"></i>
                                </span>
                        </label>
                        <input type="text" class="form-control" name="question" id="question-filed" value="{{ $help['question'] }}"
                               placeholder="{{translate('type_Question')}}">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="">{{ translate('Answer') }}
                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Update Answer" data-bs-title="Update Answer">
                                    <i class="fi fi-sr-info"></i>
                                </span>
                        </label>
                        <textarea class="form-control" name="answer"  id="answer-field"
                                  rows="5" placeholder="{{translate('type_Answer')}}">{{ $help['answer'] }}</textarea>
                    </div>
                    <div class="form-group pb-3">
                        <label class="form-label" for="">{{ translate('Priority') }}
                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Update Priority" data-bs-title="Update Priority">
                                    <i class="fi fi-sr-info"></i>
                                </span>
                        </label>
                        <div class="select-wrapper">
                            <select class="form-select bg-white" name="ranking" id="ranking-field">
                                <option value="1" {{ $help['ranking'] == 1 ? 'selected' : '' }}>1</option>
                                <option value="2" {{ $help['ranking'] == 2 ? 'selected' : '' }}>2</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="offcanvas-footer shadow-lg">
            <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
                <button type="reset" class="btn btn-secondary px-3 px-sm-4 flex-grow-1">{{ translate('reset') }}</button>
                <button type="submit" class="btn btn-primary px-3 px-sm-4 flex-grow-1">
                    {{ translate('update') }}
                </button>
            </div>
        </div>
    </div>
</form>
<form action="{{ route('admin.helpTopic.delete') }}" method="post">
    <input type="hidden" name="id" value="{{ $help['id'] }}">
    @csrf
    <div class="modal fade" id="deleteModal{{$help['id']}}" tabindex="-1" aria-labelledby="deleteModal" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                    <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                            data-bs-dismiss="modal" aria-label="Close">
                    </button>
                </div>
                <div class="modal-body px-20 py-0 mb-30">
                    <div class="d-flex flex-column align-items-center text-center mb-30">
                        <img src="{{dynamicAsset(path: 'public/assets/new/back-end/img/modal/delete.png')}}" width="80" class="mb-20" id="" alt="">
                        <h2 class="modal-title mb-3" id="">{{ translate('want_to_delete_this_faq') }}?</h2>
                        <div class="text-center" id="">
                            {{ translate('are_you_sure_to_delete_the_FAQ') }}? {{ translate('once_you_delete_the_FAQ') }},
                            {{ translate('_it_will_be_permanently_removed_and_wont_be_visible_to_customers') }}.
                        </div>
                    </div>
                    <div class="d-flex justify-content-center gap-3">
                        <button type="button" class="btn btn-secondary max-w-120 flex-grow-1"
                                data-bs-dismiss="modal">{{ translate('No') }}</button>
                        <button type="submit" class="btn btn-danger max-w-120 flex-grow-1"
                                data-bs-dismiss="modal">{{ translate('Yes_Delete') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
