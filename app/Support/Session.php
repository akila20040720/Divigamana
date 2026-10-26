<?php
namespace Divigamana\Support;
final class Session {
    public static function start(array $config): void { if(session_status()===PHP_SESSION_ACTIVE)return; $production=($config['app_env']??'production')==='production'; $https=$production||(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))==='https'; ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1'); session_name('DIVIGAMANA_SESSION'); session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']); session_start(); }
    public static function user(): ?array { return $_SESSION['user'] ?? null; }
    public static function login(array $u): void { session_regenerate_id(true); $_SESSION['user']=['id'=>(int)$u['id'],'role'=>$u['role'],'mobile'=>$u['mobile_e164']]; }
    public static function logout(): void { $_SESSION=[]; if(ini_get('session.use_cookies'))setcookie(session_name(),' ',time()-42000,'/'); session_destroy(); }
}
