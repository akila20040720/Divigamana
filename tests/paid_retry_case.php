<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);

// Exercise the real generation controller against an isolated in-memory database.
define('APP_CONFIG',['app_env'=>'production','db'=>[],'qwen'=>['api_key'=>'','mock_mode'=>false,'base_url'=>'https://example.test','model'=>'test']]);
spl_autoload_register(static function (string $class): void {
    $prefix='Divigamana\\';
    if (str_starts_with($class,$prefix)) require dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
});
$case=$argv[1]??'paid';
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
@$db->sqliteCreateFunction('NOW',static fn(): string=>'2026-01-01 00:00:00');
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, account_status TEXT, phone_verified_real INTEGER, mobile_e164 TEXT, mspace_subscriber_id TEXT)');
$db->exec('CREATE TABLE user_subscriptions (id INTEGER, user_id INTEGER, provider TEXT, status TEXT, ends_at TEXT, provider_status TEXT)');
$db->exec('CREATE TABLE horoscope_requests (id INTEGER PRIMARY KEY, user_id INTEGER, service_id INTEGER, status TEXT, idempotency_key TEXT)');
$db->exec('CREATE TABLE horoscope_results (id INTEGER PRIMARY KEY, request_id INTEGER, result_json TEXT)');
$db->exec('CREATE TABLE payment_transactions (id INTEGER PRIMARY KEY, request_id INTEGER, provider TEXT, status TEXT, amount TEXT)');
$db->exec("INSERT INTO users VALUES (1,'active',1,'+94770000000',NULL)");
$db->exec("INSERT INTO horoscope_requests VALUES (10,1,3,'failed','retry-request-123456')");
$payment=$case==='unpaid'?'failed':'paid';
$amount=$case==='wrong-amount'?'10.00':'20.00';
$db->prepare('INSERT INTO payment_transactions VALUES (1,10,?,?,?)')->execute(['ideamart',$payment,$amount]);
if ($case!=='provider-failure') $db->prepare('INSERT INTO horoscope_results VALUES (1,10,?)')->execute([json_encode(['title'=>'saved reading'])]);
$property=new ReflectionProperty(Divigamana\Support\Database::class,'pdo');
$property->setValue(null,$db);
$_SERVER['REQUEST_METHOD']='POST';
$_SESSION=['_csrf'=>'test-token','user'=>['id'=>1,'role'=>'user']]; // No payment reference remains after claiming the charge.
$_POST=['csrf'=>'test-token','service'=>$case==='other-service'?'past-life':'dream','answers'=>$case==='other-service'?['name'=>'Test']:['dream'=>'Test dream'],'idempotency_key'=>'retry-request-123456'];
register_shutdown_function(static function (): void { fwrite(STDERR,'__HTTP_STATUS__='.http_response_code()); });
http_response_code(200);
Divigamana\Controllers\ApiController::generate();
