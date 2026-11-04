<form action="{{ route('admin.pages-and-media.social-media-update',  ['id'=>$socialMediaLink['id']]) }}" method="post" id="">
    @csrf
    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSocialMediaEdit-{{ $socialMediaLink['id'] }}" aria-labelledby="offcanvasSocialMediaEditLabel">
        <div class="offcanvas-header bg-body">
            <h2 class="mb-0">{{ translate('edit_social_media_link') }}</h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <div class="mb-3 mb-sm-20">
                <div class="p-12 p-sm-20 bg-section rounded">
                    <div class="form-group">
                        <label for="name" class="form-label">
                            {{ translate('select_social_media') }}
                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Select Social Media" data-bs-title="{{ translate('Select_social_media') }}">
                                    <i class="fi fi-sr-info"></i>
                                </span>
                        </label>
                        <select class="custom-select" data-placeholder="Select social media" name="name" required>
                            <option></option>
                            <option value="instagram" {{ $socialMediaLink['name'] == 'instagram' ? 'selected' : '' }}>{{ translate('instagram') }}</option>
                            <option value="facebook" {{ $socialMediaLink['name'] == 'facebook' ? 'selected' : '' }}>{{ translate('facebook') }}</option>
                            <option value="twitter" {{ $socialMediaLink['name'] == 'twitter' ? 'selected' : '' }}>{{ translate('twitter') }}</option>
                            <option value="linkedin" {{ $socialMediaLink['name'] == 'linkedin' ? 'selected' : '' }}>{{ translate('linkedIn') }}</option>
                            <option value="pinterest" {{ $socialMediaLink['name'] == 'pinterest' ? 'selected' : '' }}>{{ translate('pinterest') }}</option>
                            <option value="google-plus" {{ $socialMediaLink['name'] == 'google-plus' ? 'selected' : '' }}>{{ translate('google_plus') }}</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="name" class="form-label">
                            {{ translate('social_media_link') }}
                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Enter Social Media Link" data-bs-title="{{ translate('Enter_social_media_link') }}">
                                <i class="fi fi-sr-info"></i>
                            </span>
                        </label>
                        <input type="url" name="link" class="form-control" id="link" value="{{ $socialMediaLink['link'] }}"
                               placeholder="{{ translate('Enter_Social_Media_Link') }}" required>
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

<form action="{{ route('admin.pages-and-media.social-media-delete') }}" method="post">
    <input type="hidden" name="id" value="{{ $socialMediaLink['id'] }}">
    @csrf
    <div class="modal fade" id="deleteModal{{$socialMediaLink['id']}}" tabindex="-1" aria-labelledby="deleteModal" aria-hidden="true">
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
                        <h2 class="modal-title mb-3" id="">{{ translate('are_you_sure_to_delete_the_social_media') }}?</h2>
                        <div class="text-center" id=""> {{ translate('once_you_delete_this_social_media') }}, {{ translate('_it_will_be_permanently_removed_and_wont_be_visible_to_customers') }} </div>
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
