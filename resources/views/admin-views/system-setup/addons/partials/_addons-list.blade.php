@if(count($addons) > 0)
    <div class="card">
        <div class="card-body">
            <div class="mb-3 mb-sm-20 d-flex flex-wrap gap-3 justify-content-between align-items-center">
                <div>
                    <h2>{{ translate('Available_Addons') }}</h2>
                    <p class="mb-0 fs-12">
                        {{ translate('Active_the_addon_you_want_to_use_for_your_system') }}
                    </p>
                </div>
            </div>

            <div class="row g-3">

                @foreach($addons as $key => $addon)
                    @php($addonData = include(base_path($addon.'/Addon/info.php')))
                    <div class="col-sm-6 col-xl-4">
                        <div
                            class="card border shadow-none h-100 overflow-hidden {{ $addonData['is_published'] == 1 ? 'theme-active' : '' }}">
                            <div class="bg-section p-12 p-sm-20 d-flex justify-content-between gap-3 align-items-start">
                                <div>
                                    <div class="d-flex gap-2 align-items-center">
                                        <h3 class="fw-bold mb-0">
                                            {{ ucwords(str_replace('_', ' ', $addonData['name'])) }}
                                        </h3>
                                        @if($addonData['is_published'] == 1)
                                            <div class="text-white px-2 py-1 fs-10 lh-1 fw-semibold rounded bg-success">
                                                {{ translate('Active') }}
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                @if(config('app.mode') != 'demo')
                                <div class="d-flex gap-2 gap-sm-3 align-items-center">
                                    @if ($addonData['is_published'] == 0)
                                        <button class="btn btn-outline-danger bg-danger bg-opacity-10 icon-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#deleteThemeModal_{{ $key }}">
                                            <i class="fi fi-sr-trash"></i>
                                        </button>
                                    @endif

                                    <label class="switcher mb-0" for="addon-publish-{{ $key }}">
                                        <input type="checkbox" id="addon-publish-{{ $key }}"
                                               class="switcher_input addon-publish-status addon-publish-status-{{ $key }}"
                                               {{ $addonData['is_published'] == 1 ? 'checked' : '' }}
                                               data-bs-toggle="modal" data-bs-target="#shiftThemeModal_{{ $key }}">
                                        <span class="switcher_control"></span>
                                    </label>
                                </div>
                                @else
                                    <div class="d-flex gap-2 gap-sm-3 align-items-center">
                                        <label class="switcher mb-0 {{ getDemoModeFormButton(type: 'class') }}">
                                            <input type="checkbox" disabled
                                                   class="switcher_input"
                                                   checked>
                                            <span class="switcher_control"></span>
                                        </label>
                                    </div>
                                @endif
                            </div>
                            <div class="card-body rounded-0 p-0">
                                <div class="ratio-3-2 border">
                                    @if(isset($addonData['module_name']))
                                        <img class="img-fit" alt=""
                                             src="{{ config(strtolower($addonData['module_name']).'.thumbnail') }}">
                                    @else
                                        <img class="img-fit" alt=""
                                             src="{{ dynamicAsset(path: 'public/assets/backend/media/modules-thumb/gateways-thumbnail.webp') }}">
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    @if ($addonData['is_published'] == 0)
                        <div class="modal fade" id="deleteThemeModal_{{ $key }}" tabindex="-1"
                             aria-labelledby="deleteThemeModal_{{ $key }}" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                                        <button type="button"
                                                class="btn-close border-0 btn-circle bg-section2 shadow-none"
                                                data-bs-dismiss="modal" aria-label="Close">
                                        </button>
                                    </div>
                                    <div class="modal-body px-4 px-sm-5 text-center">
                                        <form action="{{ route('admin.system-setup.addon.delete') }}" method="post"
                                              class="addon-delete-form">
                                            @csrf
                                            <input type="hidden" name="path" value="{{ $addon }}">
                                            <div class="mb-3 text-center">
                                                <img width="75"
                                                     src="{{ dynamicAsset(path: 'public/assets/back-end/img/delete.png') }}"
                                                     alt="">
                                            </div>
                                            <h3>
                                                {{ translate('are_you_sure_you_want_to_delete_the').' '.$addonData['name'] }}
                                                ?
                                            </h3>
                                            <p class="mb-5">
                                                {{ translate('once_you_delete') .','. translate('you_will_lost_the_this') .' '. $addonData['name'] }}
                                            </p>

                                            <div class="d-flex justify-content-center gap-3 mb-3">
                                                <button type="button" class="fs-16 btn btn-secondary px-sm-5"
                                                        data-bs-dismiss="modal">
                                                    {{ translate('cancel') }}
                                                </button>
                                                <button type="submit" class="fs-16 btn btn-danger px-sm-5">
                                                    {{ translate('delete') }}
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="modal fade" id="shiftThemeModal_{{ $key }}" tabindex="-1"
                         aria-labelledby="shiftThemeModalLabel_{{ $key }}" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div
                                    class="modal-header border-0 pb-0 d-flex justify-content-end">
                                    <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                                            data-bs-dismiss="modal" aria-label="Close">
                                    </button>
                                </div>
                                <div class="modal-body px-4 px-sm-5 text-center">
                                    <form action="{{ route('admin.system-setup.addon.publish') }}" method="post"
                                          class="addon-publish-form">
                                        @csrf
                                        <input type="hidden" value="{{ $addon }}" name="path">
                                        <div class="mb-3 text-center">
                                            <img width="75"
                                                 src="{{dynamicAsset(path: 'public/assets/back-end/img/shift.png') }}"
                                                 alt="">
                                        </div>

                                        <h3>{{ translate('are_you_sure').' ?' }}</h3>
                                        <p class="mb-5">
                                            @if ($addonData['is_published'])
                                                {{ translate('want_to_inactive_this') .' '. $addonData['name'] }}
                                            @else
                                                {{ translate('want_to_activate_this') .' '. $addonData['name'] }}
                                            @endif
                                        </p>

                                        <div class="d-flex justify-content-center gap-3 mb-3">
                                            <button type="button" data-bs-dismiss="modal"
                                                    class="fs-16 btn btn-secondary px-sm-5">
                                                {{ translate('no') }}
                                            </button>
                                            <button type="submit" data-bs-dismiss="modal"
                                                    class="fs-16 btn btn-primary px-sm-5">
                                                {{ translate('yes') }}
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
