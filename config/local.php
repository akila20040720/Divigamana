<?php
/**
 * Divigamana - Production Configuration
 * File: config/local.php
 *
 * All configuration values are hardcoded.
 * No .env file or getenv() required.
 *
 * SECURITY:
 * - Never commit this file to Git.
 * - Keep it outside public_html where possible.
 * - Rotate exposed credentials before production use.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | APPLICATION CONFIGURATION
    |--------------------------------------------------------------------------
    */

    'app_env' => 'production',

    'app_url' => 'https://divigamana.lk',

    'demo_otp_enabled' => false,

    'skip_database_check' => false,


    /*
    |--------------------------------------------------------------------------
    | ADMIN AUTHENTICATION
    |--------------------------------------------------------------------------
    */

    'admin' => [
        'username' => 'admin',
        'password' => 'dc4cad4858b9fdebf7b508bd1d7c48bc8f73f0b6f6fb5d48',
    ],


    /*
    |--------------------------------------------------------------------------
    | MYSQL DATABASE CONFIGURATION
    |--------------------------------------------------------------------------
    */

    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'rakiyacl_divigamana',
        'user' => 'rakiyacl_divigamana_dash',
        'pass' => 'Vjtseg_3348',
    ],


    /*
    |--------------------------------------------------------------------------
    | QWEN AI - ALIBABA CLOUD
    |--------------------------------------------------------------------------
    */

    'qwen' => [

        'api_key' => 'sk-ws-H.DIHRHRX.5Qy0.MEUCIQCYN39g1--N0eKUz9LhSN6ePmuu7yEX4vX9L5fc0ZtEmAIgLupbHtf2BdFw_ocZc7-x4unUJX0a2VbY2fZeVTZX3G4',

        'base_url' => 'https://ws-7g2uwrhmspz0kmxb.ap-southeast-1.maas.aliyuncs.com/',

        'fallback_base_url' => '',

        'model' => 'qwen-plus',

        'timeout_seconds' => 75,

        'max_output_tokens' => 4000,

        'temperature' => 0.65,

        'mock_mode' => false,
    ],


    /*
    |--------------------------------------------------------------------------
    | IDEAMART - DIALOG CONFIGURATION
    |--------------------------------------------------------------------------
    */

    'ideamart_live' => true,

    'ideamart_caas_live' => true,

    'ideamart' => [

        'application_id' => 'APP_068524',

        'password' => '5f5e14571230f6b8c701e57076e7c4c7',

        'otp_request_url' =>
            'https://api.ideamart.io/subscription/otp/request',

        'otp_verify_url' =>
            'https://api.ideamart.io/subscription/otp/verify',

        'subscription_url' =>
            'https://api.ideamart.io/subscription/send',

        'subscription_status_url' =>
            'https://api.ideamart.io/subscription/getStatus',

        'subscription_base_url' =>
            'https://api.ideamart.io/subscription/query-base',

        'caas_debit_url' =>
            'https://api.ideamart.io/caas/direct/debit',

        'caas_balance_url' =>
            'https://api.ideamart.io/caas/balance/query',

        'caas_account_id' => '',

        'caas_payment_instrument' => 'MobileAccount',

        'caas_callback_token' => '',
    ],


    /*
    |--------------------------------------------------------------------------
    | MSPACE - MOBITEL CONFIGURATION
    |--------------------------------------------------------------------------
    */

    'mspace_live' => true,

    'mspace_caas_live' => true,

    'mspace' => [

        'application_id' => 'APP_010616',

        'password' => '79622018288754ef5f38521718b4d8cc',

        'otp_request_url' =>
            'https://api.mspace.lk/otp/request',

        'otp_verify_url' =>
            'https://api.mspace.lk/otp/verify',

        'sms_url' =>
            'https://api.mspace.lk/sms/send',

        'sms_source_address' => '77010',

        'caas_debit_url' =>
            'https://api.mspace.lk/caas/direct/debit',

        'caas_otp_verify_url' =>
            'https://api.mspace.lk/caas/otp/verify',
    ],


    /*
    |--------------------------------------------------------------------------
    | RAHU KALAYA CONFIGURATION
    |--------------------------------------------------------------------------
    */

    'rahu' => [

        'location' => 'Colombo, Sri Lanka',

        'sunrise' => '06:05',

        'sunset' => '18:20',
    ],

];
