@if(isset($fileItem))
    <div class="">
        <div class="view-img-wrap d-flex flex-column justify-content-center gallary-card aspect-1 overflow-hidden rounded position-relative hover-wrap">

            @if($fileItem['type'] == 'application' && $fileItem['extension'] == 'zip')
                <img class="w-100 border object-fit-contain rounded bg-white ratio-1 p-4"
                     src="{{ dynamicAsset(path: 'public/assets/backend/media/file-icon-type/file-type-zip.svg') }}"
                     alt="{{ $fileItem['name'] }}">
            @elseif($fileItem['type'] == 'application' && $fileItem['extension'] == 'pdf')
                <img class="w-100 border object-fit-contain rounded bg-white ratio-1 p-4"
                     src="{{ dynamicAsset(path: 'public/assets/backend/media/file-icon-type/file-type-pdf.svg') }}"
                     alt="{{ $fileItem['name'] }}">
            @elseif($fileItem['type'] == 'application' && $fileItem['extension'] == 'xlsx')
                <img class="w-100 border object-fit-contain rounded bg-white ratio-1 p-4"
                     src="{{ dynamicAsset(path: 'public/assets/backend/media/file-icon-type/file-type-xlsx.svg') }}"
                     alt="{{ $fileItem['name'] }}">
            @elseif($fileItem['type'] == 'application' && $fileItem['extension'] == 'docx')
                <img class="w-100 border object-fit-contain rounded bg-white ratio-1 p-4"
                     src="{{ dynamicAsset(path: 'public/assets/backend/media/file-icon-type/file-type-docx.svg') }}"
                     alt="{{ $fileItem['name'] }}">
            @elseif($fileItem['type'] == 'application' && ($fileItem['extension'] == 'pptx' || $fileItem['extension'] == 'ppt'))
                <img class="w-100 border object-fit-contain rounded bg-white ratio-1 p-4"
                     src="{{ dynamicAsset(path: 'public/assets/backend/media/file-icon-type/file-type-ppt.svg') }}"
                     alt="{{ $fileItem['name'] }}">
            @elseif($fileItem['type'] == 'image')
                <img class="w-100 border object-fit-contain rounded bg-white ratio-1"
                     src="{{ storageLinkForGallery(path: str_replace('public/','', $fileItem['path']), type: $storage) }}"
                     alt="{{ $fileItem['name'] }}">
            @elseif($fileItem['type'] == 'video')
                <img class="w-100 border object-fit-contain rounded bg-white ratio-1 p-4"
                     src="{{ dynamicAsset(path: 'public/assets/backend/media/file-icon-type/file-type-video.svg') }}"
                     alt="{{ $fileItem['name'] }}">
            @elseif($fileItem['type'] == 'audio')
                <img class="w-100 border object-fit-contain rounded bg-white ratio-1 p-4"
                     src="{{ dynamicAsset(path: 'public/assets/backend/media/file-icon-type/file-type-audio.svg') }}"
                     alt="{{ $fileItem['name'] }}">
            @elseif($fileItem['type'] == 'text')
                <img class="w-100 border object-fit-contain rounded bg-white ratio-1 p-4"
                     src="{{ dynamicAsset(path: 'public/assets/backend/media/file-icon-type/file-type-text.svg') }}"
                     alt="{{ $fileItem['name'] }}">
            @else
                <img class="w-100 border object-fit-contain rounded bg-white ratio-1 p-4"
                     src="{{ dynamicAsset(path: 'public/assets/backend/media/file-icon-type/file-type-common.svg') }}"
                     alt="{{ $fileItem['name'] }}">
            @endif

            <div class="d-flex justify-content-end p-2 position-absolute top-0 inset-inline-start-0 w-100 h-100 bg-dark bg-opacity-40 hover-inner">
                <div class="d-flex flex-column gap-2 justify-content-center align-items-center">

                    <button class="btn bg-white text-info icon-btn copy-path"
                            data-bs-toggle="tooltip" data-bs-title="{{ translate('Copy_Path') }}"
                            data-bs-placement="left" data-bs-trigger="hover"
                            data-path="{{ $fileItem['db_path'] }}"
                    >
                        <i class="fi fi-sr-link-alt"></i>
                    </button>

                    @if($fileItem['type'] == 'image')
                        <button class="btn bg-white text-warning icon-btn view-image-in-modal"
                                data-bs-toggle="tooltip" data-bs-title="{{ translate('View') }}"
                                data-bs-placement="left" data-bs-trigger="hover"
                                data-title="{{ $fileItem['name'] }}"
                                data-src="{{ storageLinkForGallery(path: str_replace('public/','', $fileItem['path']), type: $storage) }}"
                                data-link="{{ storageLinkForGallery(path: str_replace('public/','', $fileItem['path']), type: $storage) }}"
                                data-path="{{ $fileItem['db_path'] }}">
                            <i class="fi fi-sr-picture"></i>
                        </button>
                    @endif

                    @if($fileItem['extension'] == 'pdf')
                        <a class="btn bg-white text-warning icon-btn"
                           data-bs-toggle="tooltip" data-bs-title="{{ translate('View') }}"
                           data-bs-placement="left" data-bs-trigger="hover"
                           target="_blank"
                           href="{{ storageLinkForGallery(path: str_replace('public/','', $fileItem['path']), type: $storage) }}">
                            <i class="fi fi-sr-picture"></i>
                        </a>
                    @endif

                    <a class="btn bg-white text-success icon-btn file-manager-download" download
                       data-bs-toggle="tooltip" data-bs-title="{{ translate('Download') }}"
                       data-bs-placement="left" data-bs-trigger="hover"
                       href="{{ storageLinkForGallery(path: str_replace('public/','', $fileItem['path']), type: $storage) }}"
                    >
                        <i class="fi fi-rr-down-to-line"></i>
                    </a>

                </div>
            </div>
        </div>
        <p class="shortname fs-12 mt-1 text-center">{{ $fileItem['name'] }}</p>
    </div>
@endif
