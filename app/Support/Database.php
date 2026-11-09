<?php
namespace Divigamana\Support;
use PDO;
final class Database {
    private static ?PDO $pdo = null;
    public static function disconnect(): void {
        self::$pdo=null;
    }
    public static function pdo(): PDO {
        if (self::$pdo) return self::$pdo;
        $c = APP_CONFIG['db'];
        $host=trim((string)($c['host']??''));
        $port=(int)($c['port']??3306);
        $name=trim((string)($c['name']??''));
        $user=trim((string)($c['user']??''));
        $pass=rtrim((string)($c['pass']??''));
        $dsn="mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        $pdo=new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
        // Keep MySQL date functions and DATETIME defaults aligned with the app's
        // Asia/Colombo reporting and OTP/subscription timestamps.
        $pdo->exec("SET time_zone = '+05:30'");
        return self::$pdo=$pdo;
    }
}
