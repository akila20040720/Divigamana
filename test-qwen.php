<?php
// This endpoint deliberately never calls Qwen in production. Use the normal
// authenticated service flow for a real reading, so API keys and quota cannot
// be exercised by a public URL.
require __DIR__.'/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if ((APP_CONFIG['app_env']??'production')!=='development') {
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'Not found.']);
    exit;
}

$q=APP_CONFIG['qwen']??[];
$base=rtrim((string)($q['base_url']??''),'/');
if (substr($base,-19)!=='/compatible-mode/v1') $base.='/compatible-mode/v1';
echo json_encode([
    'ok'=>true,
    'mode'=>'development configuration check only',
    'api_key_configured'=>trim((string)($q['api_key']??''))!=='',
    'base_url'=>$base,
    'model'=>$q['model']??'',
    'mock_mode'=>(bool)($q['mock_mode']??true),
    'curl_available'=>function_exists('curl_init'),
],JSON_UNESCAPED_UNICODE);
