"use strict";

(function () {

    function buildNotifFromPayload(data) {
        var meta = document.getElementById('auction-notif-meta');
        if (!meta) return null;

        var productTpl = meta.dataset.productUrl || '';
        var titles     = window.auctionNotifTitles || {};
        var type       = data.message_key || data.type || '';
        var title      = titles[type] || data.notification_title || '';

        return {
            id:          null,
            type:        type,
            title:       title,
            message:     data.description || data.body || '',
            is_read:     false,
            time:        meta.dataset.justNowLabel || 'Just now',
            product_name:  null,
            product_image: null,
            product_url: data.auction_slug
                ? productTpl.replace('ROUTE_PLACEHOLDER', data.auction_slug)
                : null,
        };
    }

    function initAuctionNotificationListener() {
        if (typeof messaging === 'undefined' || !messaging) {
            console.warn('[Auction FCM] Firebase messaging not available');
            return;
        }

        var meta          = document.getElementById('auction-notif-meta');
        var currentUserId = meta ? (meta.dataset.userId || '') : '';
        if (!currentUserId) {
            return;
        }

        messaging.onMessage(function (payload) {
            if (!payload?.data?.type?.startsWith('auction')) {
                return;
            }

            var data = payload.data;

            console.group('[Auction FCM] Notification received');
            console.log('Title       :', data.title);
            console.log('Body        :', data.body);
            console.log('Message Key :', data.message_key);
            console.log('Auction ID  :', data.auction_id);
            console.log('Auction Slug:', data.auction_slug);
            console.log('Full Payload:', payload);
            console.groupEnd();

            if (data.excluded_user_id && data.excluded_user_id === currentUserId) {
                return;
            }

            // Positive recipient check: drop direct messages not addressed to this user.
            // Topic broadcasts (outbid, went_live) carry no recipient_user_id, so they pass through.
            if (data.recipient_user_id && data.recipient_user_id !== currentUserId) {
                return;
            }

            // Role guard: a user can simultaneously be a customer-bidder AND a customer-owner,
            // both served by this listener (recipient_type='customer' for both roles).
            // Drop messages addressed to seller or admin even if the numeric ID matches.
            if (data.recipient_type && data.recipient_type !== 'customer') {
                return;
            }

            var notif = buildNotifFromPayload(data);
            if (!notif) return;

            var ui = window.auctionNotificationUI;
            if (!ui) {
                console.warn('[Auction FCM] auctionNotificationUI not ready');
                return;
            }

            ui.openModal(notif);
            ui.push(notif);

            // Outbid → refresh the open auction details page in realtime (Main Auction
            // Card + Live Bidding Tab) without a reload. No-op if the product-details
            // refresh hook isn't loaded or the auction isn't the one on screen.
            if (data.type === 'auction_outbid' && typeof window.auctionRefreshOnOutbid === 'function') {
                window.auctionRefreshOnOutbid(data.auction_id);
            }
        });

        console.log('[Auction FCM] Listener ready');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAuctionNotificationListener);
    } else {
        initAuctionNotificationListener();
    }

})();
