<?php

return [

    // Most of one product (bags, small sacks or big sacks) on a single delivery,
    // loading count or confirmation. One value for New Delivery, Ice Loading and
    // the Salesman's confirmation; the web app reads it from GET /api/limits.
    'max_product_quantity' => (int) env('MAX_PRODUCT_QUANTITY', 500),

    // Most of one inventory item (parts, supplies) on one stock movement or
    // stock-out request, when the item's own stock does not set a lower limit.
    // The web app uses the same number (src/lib/quantity.js QTY_CEILING).
    'max_stock_quantity' => (int) env('MAX_STOCK_QUANTITY', 9999),

    // Times typed into forms without a zone (e.g. Ice Loading "Loaded At") are
    // in this zone; "today" for the Ice Loading delivery list follows it too.
    'timezone' => env('ATICE_TIMEZONE', 'Asia/Manila'),

    // Sign-in lockout (App\Services\LoginLockout). Every `threshold` wrong
    // passwords in a row lock one email from one IP for the next step of `steps`
    // (seconds): 3, 5, 10, 15, 30 then 60 minutes, and 60 stays the cap. The step
    // count is forgotten after `memory` seconds without a failure, or on a
    // successful sign-in. Two wider nets catch guessing that spreads out: the same
    // email from many IPs, and one IP trying many emails. They have higher limits
    // and short locks, so nobody can lock an employee out for long.
    'login_lockout' => [
        'threshold' => (int) env('LOGIN_LOCKOUT_THRESHOLD', 5),
        'steps' => [180, 300, 600, 900, 1800, 3600],
        'memory' => 86400,
        'account' => ['failures' => 20, 'window' => 3600, 'lock' => 900],
        'ip' => ['failures' => 40, 'window' => 900, 'lock' => 900],
    ],

    // Sign-out after inactivity (App\Support\SessionIdle, middleware idle). Minutes
    // without any action before the session ends, whether the page stays open,
    // the tab is closed or the computer is switched off. Only the desktop
    // portals have it: Admin, HR and Customer. null = no idle limit: the
    // employees (Checker, Salesman, Maintenance, Driver) work on phones in the
    // field, with the phone in a pocket between jobs, so they are never signed
    // out for being idle; their sessions still end by the token expiry in
    // config/sanctum.php. Employees are told apart by their sub role. The web
    // app shows a countdown and signs out by itself at the same time; `grace`
    // only absorbs the delay between the two so a busy person is never cut off
    // by a race.
    'session_idle' => [
        'minutes' => [
            'admin' => (int) env('IDLE_MINUTES_ADMIN', 30),
            'hr' => (int) env('IDLE_MINUTES_HR', 30),
            'customer' => (int) env('IDLE_MINUTES_CUSTOMER', 30),
            'checker' => null,
            'maintenance' => null,
            'salesman' => null,
            'driver' => null,
        ],
        'grace' => 60,
    ],

];
