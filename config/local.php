<?php
// Copy this to config/local.php. Keep the real copy outside public_html where the host permits it.
// Qwen keys are region-specific. Replace {WORKSPACE_ID} with your Model Studio workspace ID.
return [
    'app_env' => 'development',
    'app_url' => 'https://divigamana.lk',
    'db' => [
        'host'=>'localhost','name'=>'rakiyacl_cpaneluser_divigamana','user'=>'
rakiyacl_divigamana','pass'=>'Vjtseg_3348'],
    'qwen' => [
        'api_key' => 'sk-ws-H.DIHRHRX.5Qy0.MEUCIQCYN39g1--N0eKUz9LhSN6ePmuu7yEX4vX9L5fc0ZtEmAIgLupbHtf2BdFw_ocZc7-x4unUJX0a2VbY2fZeVTZX3G4',
        'base_url' => 'https://ws-7g2uwrhmspz0kmxb.ap-southeast-1.maas.aliyuncs.com/',
        'model' => 'qwen-plus',
        'timeout_seconds' => 45,
        'max_output_tokens' => 1600,
        'temperature' => 0.85,
        'mock_mode' => false,
    ],
    // Rahu Kalaya uses a transparent Colombo time calculation, not an AI guess.
    'rahu' => ['location'=>'Colombo, Sri Lanka','sunrise'=>'06:05','sunset'=>'18:20'],
];
