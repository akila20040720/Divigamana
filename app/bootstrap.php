<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Colombo');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}
define('ROOT_PATH', dirname(__DIR__));
$configPath = ROOT_PATH . '/config/local.php';
$config = file_exists($configPath) ? require $configPath : require ROOT_PATH . '/config/config.example.php';
define('APP_CONFIG', $config);
spl_autoload_register(function (string $class): void {
    if (substr($class, 0, 11) === 'Divigamana\\') {
        $file = ROOT_PATH . '/app/' . str_replace(['Divigamana\\', '\\'], ['', '/'], $class) . '.php';
        if (is_file($file)) require $file;
    }
});
\Divigamana\Support\Session::start($config);
