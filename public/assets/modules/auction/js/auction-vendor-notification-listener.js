'use strict';

(function () {

    function buildVendorNotif(data) {
        var meta = document.getElementById('auction-vendor-notif-meta');
        if (!meta) return null;

        var productTpl = meta.dataset.productUrl || '';
        var titles     = window.auctionVendorNotifTitles || {};
        var type       = data.message_key || data.type || '';

        return {
            type:        type,
            title:       titles[type] || data.notification_title || '',
            message:     data.description || data.body || '',
            time:        meta.dataset.justNowLabel || 'Just now',
            product_url: data.auction_id
                ? productTpl.replace('ROUTE_PLACEHOLDER', data.auction_id)
                : null,
        };
    }

    function initAuctionVendorNotificationListener() {
        if (typeof messaging === 'undefined' || !messaging) {
            console.warn('[Auction FCM Vendor] Firebase messaging not available');
            return;
        }

        var meta     = document.getElementById('auction-vendor-notif-meta');
        var sellerId = meta ? (meta.dataset.sellerId || '') : '';
        if (!sellerId) {
            return;
        }

        messaging.onMessage(function (payload) {
            if (!payload?.data?.type?.startsWith('auction')) {
                return;
            }

            var data = payload.data;

            console.group('[Auction FCM Vendor] Notification received');
            console.log('Title       :', data.title);
            console.log('Body        :', data.body);
            console.log('Message Key :', data.message_key);
            console.log('Auction ID  :', data.auction_id);
            console.log('Full Payload:', payload);
            console.groupEnd();

            // Positive recipient check: drop direct messages not addressed to this seller.
            if (data.recipient_user_id && data.recipient_user_id !== sellerId) {
                return;
            }

            // Role guard: drop messages addressed to customer or admin even if the numeric ID matches.
            if (data.recipient_type && data.recipient_type !== 'seller') {
                return;
            }

            var notif = buildVendorNotif(data);
            if (!notif) return;

            var ui = window.auctionVendorNotificationUI;
            if (!ui) {
                console.warn('[Auction FCM Vendor] auctionVendorNotificationUI not ready');
                return;
            }

            ui.openModal(notif);
            ui.push(notif);
        });

        console.log('[Auction FCM Vendor] Listener ready');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAuctionVendorNotificationListener);
    } else {
        initAuctionVendorNotificationListener();
    }

})();
