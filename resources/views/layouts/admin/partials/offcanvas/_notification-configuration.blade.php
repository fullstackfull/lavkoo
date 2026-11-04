@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('push_notification') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapsePushNotification_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Customer') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapsePushNotification_01">
                <div class="card card-body">
                    <h5>{{ translate('push_notification') }}</h5>
                    <p class="fs-12">
                        {{ translate('set_up_the_push_notification_messages_for_all_order_statuses_on_this_page.') }} {{ translate('following_configuration,_if_a_status_is_active,_the_system_will_send_the_corresponding_message_to_the_customer_upon_an_update.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapsePushNotification_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Vendor') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapsePushNotification_02">
                <div class="card card-body">
                    <h5>{{ translate('push_notification') }}</h5>
                    <p class="fs-12">
                        {{ translate('set_up_the_push_notification_messages_for_vendors_on_this_page.') }} {{ translate('following_configuration,_if_a_status_is_active,_the_system_will_send_the_corresponding_message_to_the_vendor_upon_an_update.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapsePushNotification_03" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('delivery_man') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapsePushNotification_03">
                <div class="card card-body">
                    <h5>{{ translate('push_notification') }}</h5>
                    <p class="fs-12">
                         {{ translate('set_up_the_push_notification_messages_for_deliveryman_on_this_page.') }} {{ translate('following_configuration,_if_a_status_is_active,_the_system_will_send_the_corresponding_message_to_the_deliveryman_upon_an_update.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
