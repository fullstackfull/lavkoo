@extends('layouts.admin.app')

@section('title',translate('gallery'))

@section('content')
    <div class="content container-fluid">
        <h2 class="mb-3 h1 mb-0 text-capitalize"> {{ translate('file_manager') }} </h2>

        <ul class="nav nav-pills nav--tab mb-4">
            <li class="nav-item">
                <a class="nav-link {{ $storage == 'public' ? 'active' : '' }}"
                   href="{{ route('admin.system-setup.file-manager.index', ['storage' => 'public']) }}">
                    {{ translate('local_storage') }}
                </a>
            </li>
            @if($storageConnectionType == 's3')
                <li class="nav-item">
                    <a class="nav-link {{ $storage == 's3' ? 'active' : '' }}"
                       href="{{ route('admin.system-setup.file-manager.index', ['storage' => 's3']) }}">
                        {{ translate('s3_storage') }}
                    </a>
                </li>
            @endif
        </ul>

        @if($storage == 'public')
            <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mb-4">
                <i class="fi fi-sr-lightbulb-on text-info"></i>
                <span>
                    {{ translate('currently_you_are_using_local_storage,_if_you_want_to_use_3rd_party_storage,_need_to_setup_connection_with') }}
                    <a href="{{ route('admin.third-party.storage-connection-settings.index') }}" target="_blank">
                        {{ translate('3rd_Party_Storage') }}
                    </a>
                </span>
            </div>
        @endif

        <div class="card mb-3">
            <div class="card-header">
                <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2">

                    <div class="breadcrumb">
                        @foreach ($breadcrumb as $item)
                            <a class="fw-bold" href="{{ route('admin.system-setup.file-manager.index', ['storage' => request('storage', 'public'), 'targetFolder' => $item['path']]) }}">
                                {{ ucwords(translate($item['name'])) }}
                            </a>
                            @if (count($breadcrumb) > 1 && !$loop->last)
                                <span class="px-1 fw-bold">/</span>
                            @endif
                        @endforeach
                        <span class="badge bg-primary rounded mx-2" id="itemCount">
                            {{ $allItemList->total() }}
                        </span>
                    </div>

                    <div class="d-flex flex-wrap flex-sm-nowrap align-items-center justify-content-sm-end gap-2 flex-grow-1">
                        <form action="{{ url()->current() }}" method="get" class="flex-grow-1 max-w-300 min-w-100-mobile">
                            <div class="input-group">
                                <input type="search" name="search" value="{{ requestString('search') }}" class="form-control"
                                       placeholder="{{ translate('Search_with_item_name') }}">
                                <input name="targetFolder" value="{{ requestString('targetFolder') }}" hidden
                                       data-value="{{ base64_decode(request('targetFolder', '')) }}">
                                <input name="storage" value="{{ requestString('storage', 'public') }}" hidden>
                                <div class="input-group-append search-submit">
                                    <button type="submit">
                                        <i class="fi fi-rr-search"></i>
                                    </button>
                                </div>
                            </div>
                        </form>

                        <button type="button" class="btn btn-sm btn-primary text-nowrap modalTrigger"
                                data-bs-toggle="offcanvas" data-bs-target="#offcanvasImageUpload">
                            <i class="fi fi-sr-picture"></i>
                            <span class="text text-capitalize">{{ translate('add_Image') }}</span>
                        </button>

                        <button type="button" class="btn btn-sm btn-primary text-nowrap modalTrigger"
                                data-bs-toggle="offcanvas" data-bs-target="#offcanvasZipUpload">
                            <i class="fi fi-sr-file-zipper"></i>
                            <span class="text text-capitalize">{{ translate('add_ZIP') }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="card-body">

                <div class="p-20 bg-section rounded">
                    <div class="d-grid grid-folder flex-wrap gap-3 gap-xl-4">
                        @foreach($allItemList as $singleItem)
                            @if($singleItem['type'] == 'Folder' || $singleItem['type'] == 'folder')
                                @include("admin-views.file-manager.partials._folders-list", ['folderItem' => $singleItem, 'storageType' => request('storage', 'public')])
                            @else
                                @include("admin-views.file-manager.partials._files-list", ['fileItem' => $singleItem, 'storageType' => request('storage', 'public')])
                            @endif
                        @endforeach
                    </div>

                    @if(count($allItemList) <= 0)
                        <div class="d-flex justify-content-center py-5">
                            {{ translate('No_items_found') }}
                        </div>
                    @else
                        <div class="d-flex justify-content-end pt-5">
                            {!! $allItemList->links() !!}
                        </div>
                    @endif
                </div>
            </div>
        </div>

        @if(count($recentFiles) > 0 && (!request('targetFolder') || base64_decode(request('targetFolder')) == '/' || base64_decode(request('targetFolder')) == 'public'))
            <div class="card">
                <div class="card-body d-flex flex-column gap-20">
                    <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                        <h3 class="mb-0">{{ translate('Recently_Added_10_Items') }}</h3>
                    </div>
                    <div class="k-table-wrap">
                        <table class="k-table">
                            <thead>
                            <tr>
                                <th>{{ translate('SL') }}</th>
                                <th class="text-center">{{ translate('Icon') }}</th>
                                <th>{{ translate('File_Name') }}</th>
                                <th>{{ translate('File_Type') }}</th>
                                <th class="text-end">{{ translate('File_Size') }}</th>
                                <th class="text-center">{{ translate('Uploaded') }}</th>
                                <th class="text-center">{{ translate('Action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($recentFiles as $key => $recentFile)
                                <tr>
                                    <th>{{ $key + 1 }}</th>
                                    <td class="text-center">
                                        @if($recentFile['type'] == 'application' && $recentFile['extension'] == 'zip')
                                            <img class="w-100 max-w-50 border object-fit-contain rounded bg-white ratio-1"
                                                 src="{{ dynamicAsset(path: 'public/assets/backend/media/file-icon-type/file-type-zip.svg') }}"
                                                 alt="{{ $recentFile['name'] }}">
                                        @elseif($recentFile['type'] == 'application' && $recentFile['extension'] == 'pdf')
                                            <img class="w-100 max-w-50 border object-fit-contain rounded bg-white ratio-1"
                                                 src="{{ dynamicAsset(path: 'public/assets/backend/media/file-icon-type/file-type-pdf.svg') }}"
                                                 alt="{{ $recentFile['name'] }}">
                                        @elseif($recentFile['type'] == 'application' && $recentFile['extension'] == 'xlsx')
                                            <img class="w-100 max-w-50 border object-fit-contain rounded bg-white ratio-1"
                                                 src="{{ dynamicAsset(path: 'public/assets/backend/media/file-icon-type/file-type-xlsx.svg') }}"
                                                 alt="{{ $recentFile['name'] }}">
                                        @elseif($recentFile['type'] == 'application' && $recentFile['extension'] == 'docx')
                                            <img class="w-100 max-w-50 border object-fit-contain rounded ratio-1"
                                                 src="{{ dynamicAsset(path: 'public/assets/backend/media/file-icon-type/file-type-docx.svg') }}"
                                                 alt="{{ $recentFile['name'] }}">
                                        @elseif($recentFile['type'] == 'application' && ($recentFile['extension'] == 'pptx' || $recentFile['extension'] == 'ppt'))
                                            <img class="w-100 max-w-50 border object-fit-contain rounded bg-white ratio-1"
                                                 src="{{ dynamicAsset(path: 'public/assets/backend/media/file-icon-type/file-type-ppt.svg') }}"
                                                 alt="{{ $recentFile['name'] }}">
                                        @elseif($recentFile['type'] == 'image')
                                            <img class="w-100 max-w-50 border object-fit-contain rounded bg-white ratio-1"
                                                 src="{{ storageLinkForGallery(path: str_replace('public/','', $recentFile['path']), type: $storage) }}"
                                                 alt="{{ $recentFile['name'] }}">
                                        @elseif($recentFile['type'] == 'video')
                                            <img class="w-100 max-w-50 border object-fit-contain rounded bg-white ratio-1"
                                                 src="{{ dynamicAsset(path: 'public/assets/backend/media/file-icon-type/file-type-video.svg') }}"
                                                 alt="{{ $recentFile['name'] }}">
                                        @elseif($recentFile['type'] == 'audio')
                                            <img class="w-100 max-w-50 border object-fit-contain rounded bg-white ratio-1"
                                                 src="{{ dynamicAsset(path: 'public/assets/backend/media/file-icon-type/file-type-audio.svg') }}"
                                                 alt="{{ $recentFile['name'] }}">
                                        @elseif($recentFile['type'] == 'text')
                                            <img class="w-100 max-w-50 border object-fit-contain rounded bg-white ratio-1"
                                                 src="{{ dynamicAsset(path: 'public/assets/backend/media/file-icon-type/file-type-text.svg') }}"
                                                 alt="{{ $recentFile['name'] }}">
                                        @else
                                            <img class="w-100 max-w-50 border object-fit-contain rounded bg-white ratio-1"
                                                 src="{{ dynamicAsset(path: 'public/assets/backend/media/file-icon-type/file-type-common.svg') }}"
                                                 alt="{{ $recentFile['name'] }}">
                                        @endif
                                    </td>
                                    <td>{{ $recentFile['name'] }}</td>
                                    <td>{{ $recentFile['extension'] }}</td>
                                    <td class="text-end">{{ $recentFile['size'] }}</td>
                                    <td class="text-center">{{ $recentFile['last_modified']->diffForHumans() }}</td>
                                    <td>
                                        <div class="d-flex justify-content-center gap-3">

                                            @if($recentFile['type'] == 'image')
                                                <button class="btn btn-outline-info btn-outline-info-dark icon-btn view-image-in-modal"
                                                        data-bs-toggle="tooltip" data-bs-title="{{ translate('View') }}"
                                                        data-bs-placement="left"
                                                        data-title="{{ $recentFile['name'] }}"
                                                        data-src="{{ storageLinkForGallery(path: str_replace('public/','', $recentFile['path']), type: $storage) }}"
                                                        data-link="{{ storageLinkForGallery(path: str_replace('public/','', $recentFile['path']), type: $storage) }}"
                                                        data-path="{{ $recentFile['db_path'] }}">
                                                    <i class="fi fi-sr-eye"></i>
                                                </button>
                                            @endif

                                            @if($recentFile['extension'] == 'pdf')
                                                <a class="btn btn-outline-info btn-outline-info-dark icon-btn" target="_blank"
                                                   href="{{ storageLinkForGallery(path: str_replace('public/','', $recentFile['path']), type: $storage) }}">
                                                    <i class="fi fi-sr-eye"></i>
                                                </a>
                                            @endif

                                            <a download class="btn btn-outline-success btn-outline-success-dark icon-btn" title="{{ translate('Download') }}"
                                               href="{{ storageLinkForGallery(path: str_replace('public/','', $recentFile['path']), type: $storage) }}">
                                                <i class="fi fi-sr-down-to-line"></i>
                                            </a>

                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        @include("admin-views.file-manager.partials._upload-offcanvas", ['storage' => $storage])

    </div>
    <span id="get-file-copy-success-message" data-success="{{ translate('file_path_copied_successfully') }}"></span>

    @include("layouts.admin.partials.offcanvas._gallery-setup")
@endsection

@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/back-end/js/admin/file-manager.js') }}"></script>
@endpush
