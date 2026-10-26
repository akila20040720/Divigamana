<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Colombo');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}
define('ROOT_PATH', dirname(__DIR__));
if (is_file(ROOT_PATH . '/.env')) {
    foreach (file(ROOT_PATH . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line=trim($line);
        if ($line==='' || $line[0]==='#' || !str_contains($line,'=')) continue;
        [$name,$value]=explode('=',$line,2);
        $name=trim($name);
        $value=trim($value);
        if ($value!=='' && (($value[0]==='"' && substr($value,-1)==='"') || ($value[0]==="'" && substr($value,-1)==="'"))) {
            $value=substr($value,1,-1);
        }
        if ($name!=='' && getenv($name)===false) putenv($name.'='.$value);
    }
}
$configPath = ROOT_PATH . '/config/local.php';
$config = file_exists($configPath) ? require $configPath : require ROOT_PATH . '/config/config.example.php';
define('APP_CONFIG', $config);
$production=($config['app_env']??'production')==='production';
if ($production) {
    ini_set('display_errors','0');
    ini_set('log_errors','1');
}
if (PHP_SAPI!=='cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    if ($production) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
spl_autoload_register(function (string $class): void {
    if (substr($class, 0, 11) === 'Divigamana\\') {
        $file = ROOT_PATH . '/app/' . str_replace(['Divigamana\\', '\\'], ['', '/'], $class) . '.php';
        if (is_file($file)) require $file;
    }
});
\Divigamana\Support\Session::start($config);
