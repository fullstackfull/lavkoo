@if(isset($folderItem))
    <a class="flex-column align-items-center text-center p-0"
       href="{{ route('admin.system-setup.file-manager.index', ['storage' => $storageType ?? 'public', 'targetFolder' => $folderItem['encodePath']]) }}">
        @if(isset($folderItem['totalFiles']) && ($folderItem['totalFolders'] + $folderItem['totalFiles']) > 0)
            <img class="mb-2" alt="" src="{{ dynamicAsset(path: 'public/assets/back-end/img/folder.png') }}">
        @else
            <img class="mb-2" alt="" src="{{ dynamicAsset(path: 'public/assets/back-end/img/folder-empty.png') }}">
        @endif
        <h4 class="text-capitalize mb-0 text-truncate px-2">
            {{ Str::limit($folderItem['name'], 30) }}
        </h4>
        <p class="fs-12 mb-0 text-body-secondary">
            @if(isset($folderItem['totalFiles']) && ($folderItem['totalFolders'] + $folderItem['totalFiles']) < 10)
                {{ ($folderItem['totalFolders'] + $folderItem['totalFiles']) }} {{ translate('Item') }}
            @elseif(isset($folderItem['totalFiles']))
                {{ ($folderItem['totalFolders'] + $folderItem['totalFiles']) }} {{ translate('Items') }}
            @endif
        </p>
    </a>
@endif
