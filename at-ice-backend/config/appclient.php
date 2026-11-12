<?php

// The Android app proves it is the app with this key. It is set in the app build (APP_CLIENT_KEY in
// at-ice-frontend/.env.android) and in the backend .env, and must be the same on both. A website
// browser never has it, so a client that only claims to be the app gets a website session.
return [
    'key' => env('APP_CLIENT_KEY', ''),
];
