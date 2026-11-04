<div class="modal fade" tabindex="-1" role="dialog" id="sitemap-upload-modal" data-bs-backdrop="static"
     data-bs-keyboard="false">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title w-100 text-center">{{ translate('Upload_File') }}</h2>
                <div type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none" data-bs-dismiss="modal" aria-label="Close">
                </div>
            </div>
            <div class="modal-body">
                <form action="{{ config('app.mode') == 'demo' ? 'javascript:' :  route('admin.seo-settings.sitemap-manual-upload') }}" method="POST"
                      id="xml_file_upload_form" enctype="multipart/form-data" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
                    @csrf

                    <div>
                        <div class="mb-3">
                            <div class="d-flex flex-column align-items-center gap-3">
                                <div class="mx-auto text-center max-w-360 w-100">

                                    <div id="xml_file_upload_container">
                                        <div id="xml-file-upload-placeholder">
                                            <label
                                                class="custom_upload_input d-flex mx-2 cursor-pointer align-items-center justify-content-center border-dashed border-2 border-light-subtle">
                                                <input type="file" name="xml_file" id="xml_file_input"
                                                       class="custom-file-input d-none"
                                                         data-required-msg="{{ translate('please_select_a_file') }}"
                                                            data-max-size="{{ getFileUploadMaxSize(type: 'file') }}"
                                                       accept=".xml"
                                                >
                                                <div class="placeholder-image py-3">
                                                    <div
                                                        class="d-flex flex-column justify-content-center align-items-center aspect-1">
                                                        <img alt="" width="33"
                                                             src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/product-upload-icon.svg') }}">
                                                        <h3 class="text-muted fz-12">{{ translate('upload_file') }}</h3>
                                                    </div>
                                                </div>
                                            </label>
                                            <p class="text-muted mt-2 fz-12 m-0">
                                                {{ translate('upload_your_sitemap_file_here') }}
                                            </p>
                                        </div>

                                        <div id="xml_file_upload_progress" class="d-none">
                                            <div class="p-3 border rounded">
                                                <div class="d-flex justify-content-between">
                                                <span class="progress-text"
                                                      data-progress="{{ translate('Uploading') }}"
                                                      data-complete="{{ translate('Uploaded') }}"
                                                >
                                                    0% {{ translate('Uploading') }}...
                                                </span>
                                                    <span
                                                        class="text-danger font-weight-bold cursor-pointer xml_file_upload_cancel_icon">x</span>
                                                </div>
                                                <div class="progress mt-2">
                                                    <div class="progress-bar" role="progressbar" style="width: 0%"
                                                         aria-valuenow="100" aria-valuemin="0"
                                                         aria-valuemax="100"></div>
                                                </div>
                                            </div>
                                            <p class="mb-0 py-2 text-muted">
                                                {{ translate('if_you_submit_this_file_the_previous_file_will_be_automatically_replaced_by_this_file_in_the_server.') }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3 d-flex justify-content-center gap-3">
                        <button type="reset" id="xml_file_upload_cancel" class="btn btn-outline-danger font-weight-bold"
                                data-bs-dismiss="modal" aria-label="Close">
                            {{ translate('cancel') }}
                        </button>

                        <button type="{{ getDemoModeFormButton(type: 'button') }}" id="xml_file_upload_submit" class="btn btn-primary font-weight-bold {{ getDemoModeFormButton(type: 'class') }}"
                                disabled>
                            {{ translate('submit') }}
                        </button>
                    </div>
                </form>
            </div>
            <div class="btn--container"></div>
        </div>
    </div>
</div>
