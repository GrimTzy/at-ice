<?php

// Firebase Cloud Messaging: the push notifications of the Android app.
// The service account key is a secret: storage/app/firebase-service-account.json (never committed).
// Without the file, no push is sent and nothing else changes.
return [
    'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/firebase-service-account.json')),

    // The notification sound and channel the app creates on the phone (android/app/src/main/res/raw).
    'channel_id' => env('FIREBASE_CHANNEL_ID', 'atice_alerts'),
    'sound' => env('FIREBASE_SOUND', 'at_ice_notification'),
];
