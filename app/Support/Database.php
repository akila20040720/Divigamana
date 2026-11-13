<?php
namespace Divigamana\Support;
use PDO;
use PDOException;
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
        $socket=trim((string)($c['socket']??''));
        $name=trim((string)($c['name']??''));
        $user=trim((string)($c['user']??''));
        $pass=rtrim((string)($c['pass']??''));
        if ($name==='' || $user==='') {
            throw new \RuntimeException('Database configuration is incomplete: DB_NAME and DB_USER are required.');
        }
        $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false];
        $hosts=$socket!=='' ? [['socket'=>$socket]] : [['host'=>$host,'port'=>$port]];
        if ($socket==='' && strtolower($host)==='localhost') {
            $hosts[]=['host'=>'127.0.0.1','port'=>$port];
        }
        $lastError=null;
        foreach ($hosts as $endpoint) {
            $parts=[];
            foreach ($endpoint as $key=>$value) $parts[]=$key.'='.$value;
            $dsn='mysql:'.implode(';',$parts).';dbname='.$name.';charset=utf8mb4';
            try {
                $pdo=new PDO($dsn, $user, $pass, $options);
                // Keep MySQL date functions and DATETIME defaults aligned with the app's
                // Asia/Colombo reporting and OTP/subscription timestamps.
                $pdo->exec("SET time_zone = '+05:30'");
                return self::$pdo=$pdo;
            } catch (PDOException $error) {
                $lastError=$error;
                if ($socket!=='' || !str_contains($error->getMessage(),'[2002]')) throw $error;
            }
        }
        throw $lastError ?? new \RuntimeException('Database connection failed.');
    }
}
