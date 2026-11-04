@php($fcmCredentials = getWebConfig('fcm_credentials'))
<span id="Firebase_Configuration_Config" data-api-key="{{ $fcmCredentials['apiKey'] ?? '' }}"
      data-auth-domain="{{ $fcmCredentials['authDomain'] ?? '' }}"
      data-project-id="{{ $fcmCredentials['projectId'] ?? '' }}"
      data-storage-bucket="{{ $fcmCredentials['storageBucket'] ?? '' }}"
      data-messaging-sender-id="{{ $fcmCredentials['messagingSenderId'] ?? '' }}"
      data-app-id="{{ $fcmCredentials['appId'] ?? '' }}"
      data-measurement-id="{{ $fcmCredentials['measurementId'] ?? '' }}"
      data-csrf-token="{{ csrf_token() }}"
      data-route="{{ route('system.subscribeToTopic') }}"
      data-recaptcha-store="{{ route('g-recaptcha-response-store') }}"
      data-favicon="{{ $web_config['fav_icon']['path'] }}"
      data-firebase-service-worker-file="{{ dynamicAsset(path: 'firebase-messaging-sw.js') }}"
      data-firebase-service-worker-scope="{{ dynamicAsset(path: 'firebase-cloud-messaging-push-scope') }}"
>
</span>


@if(isset($fcmCredentials['apiKey']) && !empty($fcmCredentials['apiKey']))
    <script src="{{ dynamicAsset(path: 'public/assets/backend/libs/firebase/firebase.min.js') }}" defer></script>
    {{-- The three gstatic scripts that used to sit here were removed: the local firebase.min.js
         above is Firebase 8.3.2 and already provides app, auth and messaging, which the CDN copies
         then re-defined at the same version. Verified in a real browser with the CDN unreachable —
         firebase.SDK_VERSION 8.3.2, firebase.auth and firebase.messaging both present, one app
         initialised. Three fewer external round-trips, and nothing on this page depends on Google
         being reachable, which matters for a store whose customers may not have that reliably. --}}
    <script src="{{ dynamicAsset(path: 'public/assets/backend/libs/firebase/firebase-init.js') }}" defer></script>
    <script src="{{ dynamicAsset(path: 'public/assets/backend/libs/firebase/firebase-auth.js') }}" defer></script>

    <script>
        // The Firebase scripts above are deferred, so they have not run yet at parse time.
        // Deferred scripts all execute, in order, before DOMContentLoaded — so this is the
        // earliest point at which subscribeToNotificationTopics() is guaranteed to exist.
        document.addEventListener('DOMContentLoaded', function () {
            try {
                // List of topics to subscribe to
                const topics = {!! json_encode(getFCMTopicListToSubscribe()) !!};
                subscribeToNotificationTopics(topics);
            } catch (e) {
                console.warn(e);
            }
        });
    </script>
    <script>
    // Before navigating to logout, delete the FCM token so this device stops
    // receiving push notifications addressed to the current user.
    (function () {
        var logoutHref = '{{ route('customer.auth.logout') }}';
        document.addEventListener('click', function (e) {
            var anchor = e.target.closest('a[href]');
            if (!anchor) return;
            if (anchor.href !== logoutHref && anchor.getAttribute('href') !== logoutHref) return;
            if (typeof clearFcmToken !== 'function') return;
            e.preventDefault();
            clearFcmToken().finally(function () {
                window.location.href = logoutHref;
            });
        });
    })();
    </script>
    <script src="{{ dynamicAsset(path: 'public/assets/modules/auction/js/auction-notification-listener.js') }}" defer></script>
@endif
