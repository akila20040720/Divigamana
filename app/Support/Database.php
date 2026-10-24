<?php
namespace Divigamana\Support;
use PDO;
final class Database {
    private static ?PDO $pdo = null;
    public static function pdo(): PDO {
        if (self::$pdo) return self::$pdo;
        $c = APP_CONFIG['db'];
        $host=trim((string)($c['host']??''));
        $name=trim((string)($c['name']??''));
        $user=trim((string)($c['user']??''));
        $pass=rtrim((string)($c['pass']??''));
        return self::$pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    }
}
