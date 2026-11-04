@php($fcmCredentials = getWebConfig('fcm_credentials'))
<span id="Firebase_Configuration_Config"
      data-api-key="{{ $fcmCredentials['apiKey'] ?? '' }}"
      data-auth-domain="{{ $fcmCredentials['authDomain'] ?? '' }}"
      data-project-id="{{ $fcmCredentials['projectId'] ?? '' }}"
      data-storage-bucket="{{ $fcmCredentials['storageBucket'] ?? '' }}"
      data-messaging-sender-id="{{ $fcmCredentials['messagingSenderId'] ?? '' }}"
      data-app-id="{{ $fcmCredentials['appId'] ?? '' }}"
      data-measurement-id="{{ $fcmCredentials['measurementId'] ?? '' }}"
      data-csrf-token="{{ csrf_token() }}"
      data-save-token-route="{{ route('vendor.system.save-fcm-web-token') }}"
      data-firebase-service-worker-file="{{ dynamicAsset(path: 'firebase-messaging-sw.js') }}"
      data-firebase-service-worker-scope="{{ dynamicAsset(path: 'firebase-cloud-messaging-push-scope') }}"
></span>

@if(isset($fcmCredentials['apiKey']) && $fcmCredentials['apiKey'])
<script src="{{ dynamicAsset(path: 'public/assets/backend/libs/firebase/firebase.min.js') }}"></script>
    {{-- The three gstatic scripts that used to sit here were removed: the local firebase.min.js
         above is Firebase 8.3.2 and already provides app, auth and messaging, which the CDN copies
         then re-defined at the same version. Verified in a real browser with the CDN unreachable —
         firebase.SDK_VERSION 8.3.2, firebase.auth and firebase.messaging both present, one app
         initialised. Three fewer external round-trips, and nothing on this page depends on Google
         being reachable, which matters for a store whose customers may not have that reliably. --}}
<script src="{{ dynamicAsset(path: 'public/assets/backend/libs/firebase/firebase-init.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/libs/firebase/firebase-auth.js') }}"></script>

<script>
    'use strict';
    try {
        Notification.requestPermission().then(function (permission) {
            if (permission !== 'granted') return;
            messaging.getToken().then(function (token) {
                if (!token) return;
                var config = document.getElementById('Firebase_Configuration_Config');
                fetch(config.dataset.saveTokenRoute, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': config.dataset.csrfToken
                    },
                    body: JSON.stringify({ token: token })
                }).catch(function (e) {
                    console.warn('FCM seller token save error:', e);
                });
            }).catch(function (e) {
                console.warn('FCM getToken error:', e);
            });
        });
    } catch (e) {
        console.warn(e);
    }
</script>
@endif

@if(function_exists('getCheckAddonPublishedStatus') && getCheckAddonPublishedStatus(moduleName: 'Auction'))
    @include('auction::vendor-views.partials._auction-notification-setup')
@endif
