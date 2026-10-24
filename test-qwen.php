<?php
require __DIR__.'/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$config = APP_CONFIG['qwen'] ?? null;

if (!$config) {
    echo json_encode(['error' => 'Qwen config not found in config/local.php'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$base = rtrim($config['base_url'] ?? '', '/');
if (substr($base, -19) !== '/compatible-mode/v1') {
    $base .= '/compatible-mode/v1';
}
$endpoint = $base . '/chat/completions';

echo json_encode([
    'test' => 'Qwen API Direct Test',
    'config' => [
        'base_url' => $config['base_url'] ?? 'NOT SET',
        'final_endpoint' => $endpoint,
        'model' => $config['model'] ?? 'NOT SET',
        'api_key_prefix' => substr($config['api_key'] ?? '', 0, 20) . '...',
        'mock_mode' => $config['mock_mode'] ?? false,
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

echo "\n\n--- MAKING API CALL ---\n\n";

if (!functio<?php
require __DIR__.'/app/bootstrap.php';

header('Content-Type: application/json; charin PHP']
header('Content-Type: application/japi
$config = APP_CONFIG['qwen'] ?? null;

if (!$config) {') 
if (!$config) {
    echo json_encodAPI key is empty'],    exit;
}

$base = rtrim($config['base_url'] ?? '', '/');
if (substr($base, -19) !== '/compatible-mode/v1') {
    $base m', 'content' =if (substr($base, -19) !== '/compatible-mode/ro    $base .= '/compatible-mode/v1';
}
$endpoint = amana API test" in one sentence.']
    ]
echo json_encode([
    'test' => 'Qwenken    'test' => 'Qwh     'config' => [
        'base_url'ar        'base_urUR        'final_endpoint' => $endpoint,
        'model' 'Authorization: Bearer ' . $apiKey,
            'api_key_prefix' => substr($config['api_ C        'mock_mode' => $config['mock_mode'] ?? false,
    ]
], JSON_PRETTY_,
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)T ], J0,
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
]);

$response = curl_exec($ch);
$httpCode = require __DIR__., 
header('Content-Type: application/jrl_header('Content-Type: application/japi
$config = APtt$config = APP_CONFIG['qwen'] ?? null;lError ?: 'None') . "\n";
echo "Response if (!$config) {
 n(    echo json_ bytes\n\n";

if ($response === false) {
    echo jsoif (substr($base, -19) !== '/compatible-mode/ =    $base m', 'content' =if (substr($base, -19) !=ec}
$endpoint = amana API test" in one sentence.']
    ]
echo json_encode([
    'test' => 'Qwenken    'test'n";
    ]
echo json_encode([
    'test' => 'Qwenke'echoon    'test' => 'Qwte        'base_url'ar        'base_urUR        'final_endpho        'model' 'Authorization: Bearer ' . $apiKey,
            'api_key_prl'            'api_key_prefix' => substr($config['ap      ]
], JSON_PRETTY_,
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)T ], J0,
    CURLOPT_IPRESOLVE ec], J?   ]
], JSON_Pn"], J      CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
]);

$res  ]);

$response = curl_exec($ch);
$httpCode=>
$dec$httpCode = require __DIR_
 header('Content-Type: applicapo$config = APtt$config = APP_CONFIG['qwen'] ?? _UNICODE);
}
