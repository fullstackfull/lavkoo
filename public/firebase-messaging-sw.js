importScripts('https://www.gstatic.com/firebasejs/8.3.2/firebase-app.js');
importScripts('https://www.gstatic.com/firebasejs/8.3.2/firebase-messaging.js');
importScripts('https://www.gstatic.com/firebasejs/8.3.2/firebase-auth.js');

firebase.initializeApp({
    apiKey: "AIzaSyB-c19Gbou-m0Q5jA-bbPNLwE_URMf1VCQ",
    authDomain: "ksa-cosmetics.firebaseapp.com",
    projectId: "ksa-cosmetics",
    storageBucket: "ksa-cosmetics.firebasestorage.app",
    messagingSenderId: "486850863620",
    appId: "1:486850863620:android:ab24ddac27304d92bb0b51",
    measurementId: ""
});

const messaging = firebase.messaging();
messaging.setBackgroundMessageHandler(function(payload) {
    return self.registration.showNotification(payload.data.title, {
        body: payload.data.body || '',
        icon: payload.data.icon || ''
    });
});