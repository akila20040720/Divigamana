<?php
namespace Divigamana\Support;
final class Http {
    public static function input(): array { $v=json_decode(file_get_contents('php://input'),true); return is_array($v)?$v:$_POST; }
    public static function json(array $d,int $s=200): void { http_response_code($s);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit; }
    public static function requireMethod(string $method): void { if (strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!==$method) { header('Allow: '.$method); self::json(['ok'=>false,'error'=>'Method not allowed'],405); } }
    public static function requirePostCsrf(array $i): void { if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!Csrf::verify($i['csrf']??null))self::json(['ok'=>false,'error'=>'Invalid request token. Please refresh and try again.'],419); }
}
