<?php
// Keep the real copy outside public_html where the host permits it.
return [
    'app_env' => 'development',
    'app_url' => 'https://divigamana.lk',
    'db' => ['host' => 'localhost', 'name' => 'divigamana', 'user' => 'db_user', 'pass' => 'change-me'],
    'qwen' => ['api_key' => '', 'base_url' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1', 'model' => 'qwen-plus', 'timeout_seconds' => 45, 'max_output_tokens' => 1600, 'temperature' => 0.75, 'mock_mode' => true],
    'rahu' => ['location' => 'Colombo, Sri Lanka', 'sunrise' => '06:05', 'sunset' => '18:20'],
];
