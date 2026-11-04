@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Local_Storage') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseGallery_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Local_Storage') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseGallery_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('local_storage_refers_to_the_main_server_where_your_website_is_hosted.') . ' ' . translate('it_is_used_to_store_product_images_and_other_media_files_directly_on_your_server,_allowing_you_to_manage_and_access_them_without_relying_on_external_storage_services.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseGallery_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('3rd-party_storage') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseGallery_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('3rd-party_storage_means_you_keep_your_product_pictures_on_a_different_online_storage_place_(like_google_cloud_or_amazon_s3)_instead_of_directly_on_servers.') }}
                        {{ translate('this_can_help_your_website_load_images_faster_for_customers.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseGallery_03" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Public_Folder') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseGallery_03">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('the_public_folder_makes_some_of_your_files_and_folders_viewable_by_anyone_online,_even_if_they_dont_have_an_account.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseGallery_04" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('add_image_option') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseGallery_04">
                <div class="card card-body">
                   <p class="fs-12">
                        {{ translate('admin_can_add_images_one_by_one,_or_use_multiple_image_uploads_to_add_many_pictures_at_the_same_time.') }}
                        {{ translate('this_is_how_you_put_photos_on_your_website_for_people_to_see.') }}
                   </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseGallery_05" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('add_zip_file_option') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseGallery_05">
                <div class="card card-body">
                     <p class="fs-12">
                            {{ translate('the_add_zip_file_option_allows_you_to_upload_multiple_files,_such_as_images_or_documents,_in_a_compressed_zip_archive.') }}
                            {{ translate('this_can_significantly_speed_up_the_process_of_adding_several_items_to_your_platform_at_once,_saving_you_time_and_effort.') }}
                     </p>
                </div>
            </div>
        </div>

    </div>
</div>
