<?php
namespace Divigamana\Support;
use PDO;
final class Database {
    private static ?PDO $pdo = null;
    public static function pdo(): PDO {
        if (self::$pdo) return self::$pdo;
        $c = APP_CONFIG['db'];
        return self::$pdo = new PDO("mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4", trim((string)$c['user']), rtrim((string)$c['pass']), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    }
}
