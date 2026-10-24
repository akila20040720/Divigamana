<?php
// Copy this to config/local.php. Keep the real copy outside public_html where the host permits it.
// Qwen keys are region-specific. Replace {WORKSPACE_ID} with your Model Studio workspace ID.
return [
    'app_env' => 'development',
    // Temporary only: permits any six-digit OTP. Set false once a real SMS provider is enabled.
    'demo_otp_enabled' => true,
    'app_url' => 'https://divigamana.lk',
    'db' => ['host'=>'localhost','name'=>'divigamana','user'=>'db_user','pass'=>'change-me'],
    'qwen' => [
        'api_key' => '',
        'base_url' => 'https://{WORKSPACE_ID}.ap-southeast-1.maas.aliyuncs.com/compatible-mode/v1',
        'model' => 'qwen-plus',
        'timeout_seconds' => 75,
        'max_output_tokens' => 1600,
        'temperature' => 0.85,
        'mock_mode' => true,
    ],
    // Rahu Kalaya uses a transparent Colombo time calculation, not an AI guess.
    'rahu' => ['location'=>'Colombo, Sri Lanka','sunrise'=>'06:05','sunset'=>'18:20'],
];
