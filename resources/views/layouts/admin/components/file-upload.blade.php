<div class="card shadow-lg mt-3">
    <div class="card-body">
        <h2 class="text-primary text-uppercase mb-3">File Upload</h2>
        <div class="row g-4">
            <div class="col-lg-6">
                <h3 class="text-primary mb-3">Single File</h3>

                <div class="component-snippets-container">
                    <div class="component-snippets-preview">
                        <div id="liveAlertPlaceholder">
                            <div></div>
                        </div>
                        <div class="bg-section rounded-8 p-12 p-sm-20">
                            <div class="file-upload-parent">
                                <div class="custom-file-upload mb-4">
                                    <input type="file" accept=".zip" data-max-file-size="10MB" name="test" />
                                    <div class="text-center">
                                        <div class="mb-20">
                                            <i class="fi fi-rr-cloud-upload-alt fs-1 text-black-50"></i>
                                        </div>
                                        <p class="mb-0 fs-14 mb-1">Select a file or <span class="fw-semibold">Drag & Drop</span> here</p>
                                        <div class="mb-0 fs-12">Total file size no more than 10MB</div>
                                        <div class="btn btn-outline-primary mt-30 trigger_input_btn">
                                            Select File
                                        </div>
                                    </div>
                                </div>
                                <div class="file-preview-list d-flex flex-column gap-4"></div>
                                <div id="file-upload-config"
                                    data-icon-src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/icons/file-view.png') }}"></div>
                            </div>
                        </div>
                    </div>
                    <div class="position-relative snippets-code-hover">
                        <div class="component-snippets-code-header">
                            <ul class="nav nav-tabs" id="myTab" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="home-tab" data-bs-toggle="tab" data-bs-target="#html-tab-pane"
                                        type="button" role="tab" aria-controls="html-tab-pane" aria-selected="true">Html</button>
                            </ul>
                            <button class="btn btn-icon copy-button">
                                <i class="fi fi-rr-copy"></i>
                            </button>
                        </div>
                        <div class="tab-content content position-absolute start-0 top-100 shadow-lg bg-white p-1 z-3 mw-100"
                            id="myTabContent">
                            <div class="tab-pane fade show active" id="html-tab-pane" role="tabpanel" aria-labelledby="home-tab"
                                tabindex="0">
                                <div class="component-snippets-code-container">
<pre><code><div class="bg-section rounded-8 p-12 p-sm-20">
    <div class="file-upload-parent">
        <div class="custom-file-upload mb-4">
            <input type="file" accept=".zip" data-max-file-size="10MB" name="test" />
            <div class="text-center">
                <div class="mb-20">
                    <i class="fi fi-rr-cloud-upload-alt fs-1 text-black-50"></i>
                </div>
                <p class="mb-0 fs-14 mb-1">Select a file or <span class="fw-semibold">Drag & Drop</span> here</p>
                <div class="mb-0 fs-12">Total file size no more than 10MB</div>
                <div class="btn btn-outline-primary mt-30 trigger_input_btn">
                    Select File
                </div>
            </div>
        </div>
        <div class="file-preview-list d-flex flex-column gap-4"></div>
        <div id="file-upload-config"
            data-icon-src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/icons/file-view.png') }}"></div>
    </div>
</div></code></pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <div class="col-lg-6">
                <h3 class="text-primary mb-3">Multple File</h3>

                <div class="component-snippets-container">
                    <div class="component-snippets-preview">
                        <div id="liveAlertPlaceholder">
                            <div></div>
                        </div>
                        <div class="bg-section rounded-8 p-12 p-sm-20">
                            <div class="file-upload-parent">
                                <div class="custom-file-upload mb-4">
                                    <input type="file" accept=".zip" data-max-file-size="10MB" name="test[]" multiple />
                                    <div class="text-center">
                                        <div class="mb-20">
                                            <i class="fi fi-rr-cloud-upload-alt fs-1 text-black-50"></i>
                                        </div>
                                        <p class="mb-0 fs-14 mb-1">Select a file or <span class="fw-semibold">Drag & Drop</span> here</p>
                                        <div class="mb-0 fs-12">Total file size no more than 10MB</div>
                                        <div class="btn btn-outline-primary mt-30 trigger_input_btn">
                                            Select File
                                        </div>
                                    </div>
                                </div>
                                <div class="file-preview-list d-flex flex-column gap-4"></div>
                                <div id="file-upload-config"
                                    data-icon-src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/icons/file-view.png') }}"></div>
                            </div>
                        </div>
                    </div>
                    <div class="position-relative snippets-code-hover">
                        <div class="component-snippets-code-header">
                            <ul class="nav nav-tabs" id="myTab" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="home-tab" data-bs-toggle="tab" data-bs-target="#html-tab-pane"
                                        type="button" role="tab" aria-controls="html-tab-pane" aria-selected="true">Html</button>
                            </ul>
                            <button class="btn btn-icon copy-button">
                                <i class="fi fi-rr-copy"></i>
                            </button>
                        </div>
                        <div class="tab-content content position-absolute start-0 top-100 shadow-lg bg-white p-1 z-3 mw-100"
                            id="myTabContent">
                            <div class="tab-pane fade show active" id="html-tab-pane" role="tabpanel" aria-labelledby="home-tab"
                                tabindex="0">
                                <div class="component-snippets-code-container">
<pre><code><div class="bg-section rounded-8 p-12 p-sm-20">
    <div class="file-upload-parent">
        <div class="custom-file-upload mb-4">
            <input type="file" accept=".zip" data-max-file-size="10MB" name="test[]" multiple />
            <div class="text-center">
                <div class="mb-20">
                    <i class="fi fi-rr-cloud-upload-alt fs-1 text-black-50"></i>
                </div>
                <p class="mb-0 fs-14 mb-1">Select a file or <span class="fw-semibold">Drag & Drop</span> here</p>
                <div class="mb-0 fs-12">Total file size no more than 10MB</div>
                <div class="btn btn-outline-primary mt-30 trigger_input_btn">
                    Select File
                </div>
            </div>
        </div>
        <div class="file-preview-list d-flex flex-column gap-4"></div>
        <div id="file-upload-config"
            data-icon-src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/icons/file-view.png') }}"></div>
    </div>
</div></code></pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <div class="col-lg-6">
                <h3 class="text-primary mb-3">Multiple File Two</h3>

                <div class="component-snippets-container">
                    <div class="component-snippets-preview">
                        <div id="liveAlertPlaceholder">
                            <div></div>
                        </div>
                        <div class="file_upload_wrapper d-flex flex-column gap-20">
                            <div class="d-flex flex-column gap-20 bg-section p-20 w-100">
                                <label for="" class="form-label fw-semibold mb-0 text-center">
                                    {{ translate('Choose_Image') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="upload-file multiple_file">
                                    <input type="file" name="thumbnail[]" class="upload-file__input" accept=".zip" value="" multiple
                                        data-max-size="2mb" data-max-count="5" data-max-total-size="128mb">
                                    <label class="upload-file__wrapper">
                                        <div class="upload-file-textbox text-center">
                                            <img width="34" height="34" class="svg"
                                                src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}"
                                                alt="image upload">
                                            <h6 class="mt-1 fw-medium lh-base text-center">
                                                <span class="text-info">{{ translate('Click to upload') }}</span>
                                                <br>{{ translate('or drag and drop') }}
                                            </h6>
                                        </div>
                                    </label>
                                </div>
                            </div>
                            <div class="file-view-list d-flex flex-column gap-20"></div>
                        </div>
                    </div>
                    <div class="position-relative snippets-code-hover">
                        <div class="component-snippets-code-header">
                            <ul class="nav nav-tabs" id="myTab" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="home-tab" data-bs-toggle="tab" data-bs-target="#html-tab-pane"
                                        type="button" role="tab" aria-controls="html-tab-pane" aria-selected="true">Html</button>
                            </ul>
                            <button class="btn btn-icon copy-button">
                                <i class="fi fi-rr-copy"></i>
                            </button>
                        </div>
                        <div class="tab-content content position-absolute start-0 top-100 shadow-lg bg-white p-1 z-3 mw-100"
                            id="myTabContent">
                            <div class="tab-pane fade show active" id="html-tab-pane" role="tabpanel" aria-labelledby="home-tab"
                                tabindex="0">
                                <div class="component-snippets-code-container">
<pre><code><div class="file_upload_wrapper d-flex flex-column gap-20">
    <div class="d-flex flex-column gap-20 bg-section p-20 w-100">
        <label for="" class="form-label fw-semibold mb-0 text-center">
            {{ translate('Choose_Image') }}
            <span class="text-danger">*</span>
        </label>
        <div class="upload-file multiple_file">
            <input type="file" name="thumbnail[]" class="upload-file__input" accept=".zip" value="" multiple
                data-max-size="2mb" data-max-count="5" data-max-total-size="128mb">
            <label class="upload-file__wrapper">
                <div class="upload-file-textbox text-center">
                    <img width="34" height="34" class="svg"
                        src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}"
                        alt="image upload">
                    <h6 class="mt-1 fw-medium lh-base text-center">
                        <span class="text-info">{{ translate('Click to upload') }}</span>
                        <br>{{ translate('or drag and drop') }}
                    </h6>
                </div>
            </label>
        </div>
    </div>
    <div class="file-view-list d-flex flex-column gap-20"></div>
</div></code></pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
