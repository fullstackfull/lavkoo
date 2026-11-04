<template id="auction-notif-item-template">
    <div class="notification-item notification-active p-xxl-3 p-2 rounded d-flex align-items-center gap-10px auction-notification-item"
         style="cursor:pointer;">
        <div class="w-50px min-w-50px h-50px border rounded-circle overflow-hidden">
            <img class="js-notif-icon object-cover w-100 h-100"
                 src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/congrt-bid.png') }}"
                 alt="">
        </div>
        <div class="w-100">
            <div class="d-flex align-items-center justify-content-between">
                <h3 class="js-notif-title fw-normal fs-16 title-clr mb-2 line--limit-1"></h3>
                <span class="js-notif-time text-primary fs-14 d-block auction-notif-time"></span>
            </div>
            <div class="d-flex align-items-center gap-1">
                <p class="js-notif-message mb-0 line--limit-1 fs-14 title-semidark pe-xl-5 pe-1 me-4"></p>
            </div>
        </div>
    </div>
</template>
