<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';

use Divigamana\Services\IdeamartClient;
use Divigamana\Services\ServiceCatalog;
use Divigamana\Support\Database;

$failures=0; $warnings=0;
function result(string $level,string $label,string $detail=''): void {
    global $failures,$warnings;
    if ($level==='FAIL') $failures++; elseif ($level==='WARN') $warnings++;
    echo str_pad($level,6).$label.($detail!==''?' — '.$detail:'')."\n";
}
function present(mixed $value): bool { return trim((string)$value)!==''; }
function httpsUrl(mixed $value): bool { return filter_var($value,FILTER_VALIDATE_URL)!==false&&strtolower((string)parse_url((string)$value,PHP_URL_SCHEME))==='https'; }

result(version_compare(PHP_VERSION,'8.1.0','>=')?'PASS':'FAIL','PHP 8.1 or newer',PHP_VERSION);
foreach (['pdo_mysql','curl','mbstring','openssl','json'] as $extension) result(extension_loaded($extension)?'PASS':'FAIL','PHP extension '.$extension);
result((APP_CONFIG['app_env']??'')==='production'?'PASS':'WARN','APP_ENV is production',(string)(APP_CONFIG['app_env']??''));
result(empty(APP_CONFIG['demo_otp_enabled'])?'PASS':'FAIL','Demo OTP is disabled');
result(empty(APP_CONFIG['skip_database_check'])?'PASS':'FAIL','Database bypass is disabled');
result(httpsUrl(APP_CONFIG['app_url']??'')?'PASS':'FAIL','APP_URL uses HTTPS',(string)(APP_CONFIG['app_url']??''));
$admin=APP_CONFIG['admin']??[];
result(present($admin['username']??'')?'PASS':'FAIL','Admin username is configured');
$adminPassword=(string)($admin['password']??'');
result(strlen($adminPassword)>=12&&$adminPassword!=='1234'?'PASS':'FAIL','Admin password is production strength');
$qwen=APP_CONFIG['qwen']??[];
result(present($qwen['api_key']??'')?'PASS':'FAIL','Qwen API key is configured');
result(httpsUrl($qwen['base_url']??'')?'PASS':'FAIL','Qwen base URL uses HTTPS');
result(empty($qwen['mock_mode'])?'PASS':'FAIL','Qwen mock mode is disabled');

foreach (['ideamart','mspace'] as $provider) {
    $live=!empty(APP_CONFIG[$provider.'_live']); $caas=!empty(APP_CONFIG[$provider.'_caas_live']); $config=APP_CONFIG[$provider]??[];
    result($live?'PASS':'WARN',ucfirst($provider).' provider live switch',$live?'enabled':'disabled as expected before production approval');
    if ($live) {
        result(present($config['application_id']??'')?'PASS':'FAIL',ucfirst($provider).' application ID configured');
        result(present($config['password']??'')?'PASS':'FAIL',ucfirst($provider).' password configured');
    }
    result(!$caas||$live?'PASS':'FAIL',ucfirst($provider).' CAAS gate is consistent',$caas?'real charging enabled':'real charging disabled');
    if ($caas) foreach (array_filter($config,static fn($v,$k)=>str_ends_with((string)$k,'_url'),ARRAY_FILTER_USE_BOTH) as $key=>$url) result(httpsUrl($url)?'PASS':'FAIL',ucfirst($provider).' '.$key.' uses HTTPS');
}

try {
    $pdo=Database::pdo();
    $pdo->query('SELECT 1');
    result('PASS','Database connection');
    $database=(string)(APP_CONFIG['db']['name']??'');
    $required=['users'=>['account_status','last_login_at','ideamart_subscriber_id'],'user_subscriptions'=>['provider','provider_status','frequency','notified_at'],'payment_transactions'=>['provider','external_reference','provider_reference']];
    foreach ($required as $table=>$columns) foreach ($columns as $column) {
        $st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
        $st->execute([$database,$table,$column]);
        result((int)$st->fetchColumn()===1?'PASS':'FAIL',"Database column $table.$column");
    }
    $dbPrices=$pdo->query('SELECT slug,price_lkr FROM service_catalog')->fetchAll(\PDO::FETCH_KEY_PAIR);
    foreach (ServiceCatalog::all() as $service) result(isset($dbPrices[$service['slug']])&&number_format((float)$dbPrices[$service['slug']],2,'.','')===$service['price_lkr']?'PASS':'FAIL','Service price '.$service['slug'],$service['price_lkr'].' LKR');
} catch (Throwable $error) {
    result('FAIL','Database readiness','connection or required schema check failed');
}

if (in_array('--live-ideamart-readonly',$argv,true)) {
    try {
        $response=(new IdeamartClient())->subscriptionBase();
        result(IdeamartClient::successful($response)?'PASS':'FAIL','Live Ideamart read-only query-base',IdeamartClient::diagnostic($response));
    } catch (Throwable $error) { result('FAIL','Live Ideamart read-only query-base','request failed'); }
} else {
    result('WARN','Live carrier request not executed','use --live-ideamart-readonly only after credentials and IP allow-listing; it never debits');
}

echo "\nPreflight completed: $failures failure(s), $warnings warning(s). No CAAS debit was sent.\n";
exit($failures===0?0:1);
