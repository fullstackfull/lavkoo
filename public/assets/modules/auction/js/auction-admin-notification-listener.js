'use strict';

(function () {
    'use strict';

    var ADMIN_TYPES = [
        'auction_claim_submitted',
        'auction_withdrawal_submitted',
        'auction_commission_submitted',
    ];

    function buildAdminNotif(data) {
        var labels  = window.auctionAdminNotifLabels  || {};
        var ctaUrls = window.auctionAdminNotifCtaUrls || {};
        var type    = data.message_key || data.type   || '';

        return {
            type:    type,
            title:   labels[type]  || type,
            message: data.description || data.body || '',
            cta_url: ctaUrls[type] || null,
        };
    }

    function initAuctionAdminNotificationListener() {
        if (typeof messaging === 'undefined' || !messaging) {
            console.warn('[Auction FCM Admin] Firebase messaging not available');
            return;
        }

        messaging.onMessage(function (payload) {
            var type = (payload && payload.data && payload.data.type) ? payload.data.type : '';

            if (!type.startsWith('auction')) return;
            if (ADMIN_TYPES.indexOf(type) === -1) return;

            var data = payload.data;

            console.group('[Auction FCM Admin] Notification received');
            console.log('Type        :', type);
            console.log('Body        :', data.body);
            console.log('Full Payload:', payload);
            console.groupEnd();

            // Positive recipient check: drop messages not addressed to admin.
            // Admin notifications carry recipient_type === 'admin'; others (customer/seller) are excluded.
            if (data.recipient_type && data.recipient_type !== 'admin') {
                return;
            }

            var notif = buildAdminNotif(data);

            var ui = window.auctionAdminNotificationUI;
            if (!ui) {
                console.warn('[Auction FCM Admin] auctionAdminNotificationUI not ready');
                return;
            }

            ui.openModal(notif);
        });

        console.log('[Auction FCM Admin] Listener ready');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAuctionAdminNotificationListener);
    } else {
        initAuctionAdminNotificationListener();
    }

})();
