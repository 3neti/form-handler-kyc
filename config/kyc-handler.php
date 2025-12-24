<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Fake Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, skips actual HyperVerge API calls and returns mock data.
    | Useful for testing the KYC flow without real verification.
    |
    */
    'use_fake' => env('KYC_USE_FAKE', false),

    /*
    |--------------------------------------------------------------------------
    | HyperVerge Configuration
    |--------------------------------------------------------------------------
    |
    | These settings are used to connect to the HyperVerge API.
    | The package relies on the 3neti/hyperverge package for actual API calls.
    |
    */
    'hyperverge' => [
        'base_url' => env('HYPERVERGE_BASE_URL', 'https://ind.idv.hyperverge.co/v1'),
        'app_id' => env('HYPERVERGE_APP_ID'),
        'app_key' => env('HYPERVERGE_APP_KEY'),
        'workflow' => env('HYPERVERGE_URL_WORKFLOW', 'onboarding'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Polling Interval
    |--------------------------------------------------------------------------
    |
    | How often to poll for KYC status updates (in seconds).
    |
    */
    'polling_interval' => env('KYC_POLLING_INTERVAL', 5),

    /*
    |--------------------------------------------------------------------------
    | Auto Redirect Delay
    |--------------------------------------------------------------------------
    |
    | Delay before auto-redirecting after KYC approval (in seconds).
    |
    */
    'auto_redirect_delay' => env('KYC_AUTO_REDIRECT_DELAY', 2),
];
