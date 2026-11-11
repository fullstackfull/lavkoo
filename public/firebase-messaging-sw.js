importScripts('https://www.gstatic.com/firebasejs/8.3.2/firebase-app.js');
importScripts('https://www.gstatic.com/firebasejs/8.3.2/firebase-messaging.js');
importScripts('https://www.gstatic.com/firebasejs/8.3.2/firebase-auth.js');

firebase.initializeApp({
    apiKey: "AIzaSyA7a2iNx_uqBXI8HAjE0IBOq4EblYbd8t4",
    authDomain: "lavkoo-380f1.firebaseapp.com",
    projectId: "lavkoo-380f1",
    storageBucket: "lavkoo-380f1.firebasestorage.app",
    messagingSenderId: "533968466510",
    appId: "1:533968466510:web:5d6d8d6ae872e87e81891f",
    measurementId: "G-R8XT3GY36Q"
});

const messaging = firebase.messaging();
messaging.setBackgroundMessageHandler(function(payload) {
    return self.registration.showNotification(payload.data.title, {
        body: payload.data.body || '',
        icon: payload.data.icon || ''
    });
});