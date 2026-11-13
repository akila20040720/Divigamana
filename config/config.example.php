<?php
// Copy this to config/local.php. Keep the real copy outside public_html where the host permits it.
// Qwen keys are region-specific. Replace {WORKSPACE_ID} with your Model Studio workspace ID.
return [
    'app_env' => getenv('APP_ENV') ?: 'production',
    'skip_database_check' => filter_var(getenv('SKIP_DATABASE_CHECK') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'mspace_live' => filter_var(getenv('MSPACE_LIVE') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'mspace_caas_live' => filter_var(getenv('MSPACE_CAAS_LIVE') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'mspace' => [
        'application_id' => getenv('MSPACE_APP_ID') ?: '',
        'password' => getenv('MSPACE_PASSWORD') ?: '',
        'otp_request_url' => getenv('MSPACE_OTP_REQUEST_URL') ?: 'https://api.mspace.lk/otp/request',
        'otp_verify_url' => getenv('MSPACE_OTP_VERIFY_URL') ?: 'https://api.mspace.lk/otp/verify',
        'sms_url' => getenv('MSPACE_SMS_URL') ?: 'https://api.mspace.lk/sms/send',
        'sms_source_address' => getenv('MSPACE_SMS_SOURCE_ADDRESS') ?: '',
        'caas_debit_url' => getenv('MSPACE_CAAS_DEBIT_URL') ?: 'https://api.mspace.lk/caas/direct/debit',
        'caas_otp_verify_url' => getenv('MSPACE_CAAS_OTP_VERIFY_URL') ?: 'https://api.mspace.lk/caas/otp/verify',
    ],
    'ideamart_live' => filter_var(getenv('IDEAMART_LIVE') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'ideamart_caas_live' => filter_var(getenv('IDEAMART_CAAS_LIVE') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'ideamart' => [
        'application_id' => getenv('IDEAMART_APP_ID') ?: '',
        'password' => getenv('IDEAMART_PASSWORD') ?: '',
        'otp_request_url' => getenv('IDEAMART_OTP_REQUEST_URL') ?: 'https://api.ideamart.io/subscription/otp/request',
        'otp_verify_url' => getenv('IDEAMART_OTP_VERIFY_URL') ?: 'https://api.ideamart.io/subscription/otp/verify',
        'subscription_url' => getenv('IDEAMART_SUBSCRIPTION_SEND_URL') ?: 'https://api.ideamart.io/subscription/send',
        'subscription_status_url' => getenv('IDEAMART_SUBSCRIPTION_STATUS_URL') ?: 'https://api.ideamart.io/subscription/getStatus',
        'subscription_base_url' => getenv('IDEAMART_SUBSCRIPTION_QUERY_BASE_URL') ?: 'https://api.ideamart.io/subscription/query-base',
        'caas_debit_url' => getenv('IDEAMART_CAAS_DEBIT_URL') ?: 'https://api.ideamart.io/caas/direct/debit',
        'caas_balance_url' => getenv('IDEAMART_CAAS_BALANCE_URL') ?: 'https://api.ideamart.io/caas/balance/query',
        'caas_account_id' => getenv('IDEAMART_CAAS_ACCOUNT_ID') ?: '',
        'caas_payment_instrument' => getenv('IDEAMART_CAAS_PAYMENT_INSTRUMENT') ?: 'MobileAccount',
        'caas_callback_token' => getenv('IDEAMART_CAAS_CALLBACK_TOKEN') ?: '',
    ],
    'app_url' => getenv('APP_URL') ?: 'https://divigamana.lk',
    'db' => [
        'host'=>getenv('DB_HOST') ?: '127.0.0.1',
        'port'=>(int)(getenv('DB_PORT') ?: 3306),
        'socket'=>getenv('DB_SOCKET') ?: '',
        'name'=>getenv('DB_NAME') ?: 'divigamana',
        'user'=>getenv('DB_USER') ?: 'db_user',
        'pass'=>getenv('DB_PASSWORD') ?: 'change-me',
    ],
    'qwen' => [
        'api_key' => getenv('QWEN_API_KEY') ?: '',
        'base_url' => getenv('QWEN_BASE_URL') ?: 'https://{WORKSPACE_ID}.ap-southeast-1.maas.aliyuncs.com/compatible-mode/v1',
        // Optional regional fallback override. Singapore workspace endpoints also fall back automatically to DashScope on connect errors.
        'fallback_base_url' => getenv('QWEN_FALLBACK_BASE_URL') ?: '',
        'model' => getenv('QWEN_MODEL') ?: 'qwen-plus',
        // Keep this below the cPanel PHP max_execution_time. 45 seconds is a safe default.
        'timeout_seconds' => 45,
        'max_output_tokens' => 2200,
        // Lower sampling keeps the structured readings consistent and grounded.
        'temperature' => 0.65,
        'mock_mode' => filter_var(getenv('QWEN_MOCK_MODE') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    ],
    // Rahu Kalaya uses a transparent Colombo time calculation, not an AI guess.
    'rahu' => ['location'=>'Colombo, Sri Lanka','sunrise'=>'06:05','sunset'=>'18:20'],
];
