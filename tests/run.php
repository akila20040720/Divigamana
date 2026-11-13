<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';

use Divigamana\Services\IdeamartClient;
use Divigamana\Services\MspaceClient;
use Divigamana\Support\ApiRoutes;

$passed=0; $failed=0;
function check(bool $condition,string $label): void {
    global $passed,$failed;
    if ($condition) { $passed++; echo "PASS  $label\n"; }
    else { $failed++; echo "FAIL  $label\n"; }
}
function apiCase(string $action,string $method,bool $csrf=false): array {
    $command=[PHP_BINARY,__DIR__.'/api_case.php',$action,$method,$csrf?'csrf':''];
    $pipes=[];
    $process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    if (!is_resource($process)) return ['status'=>0,'body'=>null];
    $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    preg_match('/__HTTP_STATUS__=(\d+)/',$stderr,$match);
    return ['status'=>(int)($match[1]??0),'body'=>json_decode($stdout,true)];
}

foreach (ApiRoutes::all() as $action=>$route) {
    $method=$route['method'];
    $result=apiCase($action,$method);
    if ($method==='GET') check($result['status']===200&&is_array($result['body'])&&($result['body']['ok']??false)===true,"$method $action responds safely");
    elseif (in_array($action,['ideamart-subscription-notification','ideamart-charge-notification'],true)) check($result['status']===200&&($result['body']['statusCode']??'')==='S1000',"$method $action safely acknowledges an invalid carrier callback");
    else check($result['status']===419&&($result['body']['ok']??true)===false,"$method $action rejects missing CSRF");
    $wrong=apiCase($action,$method==='GET'?'POST':'GET');
    check($wrong['status']===405,"$action rejects the wrong HTTP method");
}
$protectedExpectations=['auth'=>422,'verify'=>422,'subscription'=>401,'unsubscribe'=>401,'subscription-status'=>401,'subscription-base'=>403,'caas-charge'=>401,'mspace-charge-otp'=>401,'mspace-charge-verify'=>401,'generate'=>401];
foreach ($protectedExpectations as $action=>$expected) {
    $result=apiCase($action,'POST',true);
    check($result['status']===$expected&&($result['body']['ok']??true)===false,"POST $action reaches its safe validation/auth boundary");
}
$missing=apiCase('does-not-exist','GET');
check($missing['status']===404,'unknown API action returns 404');

$ideamartRequests=[];
$fakeIdeamart=static function(string $key,string $url,array $body) use (&$ideamartRequests): array {
    $ideamartRequests[$key]=['url'=>$url,'body'=>$body];
    return $key==='otp_request_url'?['statusCode'=>'S1000','referenceNo'=>'safe-test-reference']:['statusCode'=>'S1000','subscriberId'=>'tel:masked-test','internalTrxId'=>'internal-test','baseSize'=>0];
};
$ideamartConfig=['application_id'=>'APP_TEST','password'=>'test-secret','otp_request_url'=>'https://example.test/otp/request','otp_verify_url'=>'https://example.test/otp/verify','subscription_url'=>'https://example.test/subscription','subscription_status_url'=>'https://example.test/status','subscription_base_url'=>'https://example.test/base','caas_debit_url'=>'https://example.test/debit','caas_balance_url'=>'https://example.test/balance','caas_payment_instrument'=>'MobileAccount'];
$ideamart=new IdeamartClient($ideamartConfig,$fakeIdeamart,true,true);
$ideamart->otpRequest('tel:94770000000',['client'=>'WEBAPP'],'contract-test-hash');
$ideamart->otpVerify('safe-reference','123456');
$ideamart->subscribe('tel:masked-test'); $ideamart->unsubscribe('tel:masked-test');
$ideamart->subscriptionStatus('tel:masked-test'); $ideamart->subscriptionBase();
$ideamart->charge('tel:masked-test','divigamana-contract-test','20.00');
check(($ideamartRequests['caas_debit_url']['body']['amount']??'')==='20.00','IdeaMart CAAS debit sends a decimal string amount');
check(($ideamartRequests['caas_debit_url']['body']['externalTrxId']??'')==='divigamana-contract-test','IdeaMart CAAS debit carries the idempotency key');
check(($ideamartRequests['otp_request_url']['body']['applicationHash']??'')==='contract-test-hash','Ideamart OTP request carries an application hash');

$mspaceRequests=[];
$fakeMspace=static function(string $key,string $url,array $body) use (&$mspaceRequests): array {
    $mspaceRequests[$key]=['url'=>$url,'body'=>$body];
    return $key==='caas_debit_url'?['statusCode'=>'S1000','requestCorrelator'=>'safe-test-reference']:['statusCode'=>'S1000','status'=>true];
};
$mspaceConfig=['application_id'=>'APP_TEST','password'=>'test-secret','otp_request_url'=>'https://example.test/otp/request','otp_verify_url'=>'https://example.test/otp/verify','caas_debit_url'=>'https://example.test/debit','caas_otp_verify_url'=>'https://example.test/verify'];
$mspace=new MspaceClient($mspaceConfig,$fakeMspace,true,true);
$mspace->otpRequest('0707000000',['client'=>'WEBAPP']);
$mspace->generateChargeOtp('tel:94710000000','divigamana-contract-test','20.00');
$mspace->verifyChargeOtp('safe-reference','123456','tel:94710000000');
check(($mspaceRequests['caas_debit_url']['body']['paymentInstrumentName']??'')==='Mobile Account','mSpace debit payload uses Mobile Account');
check(($mspaceRequests['caas_otp_verify_url']['body']['sourceAddress']??'')==='tel:94710000000','mSpace OTP verification preserves the subscriber');
check(MspaceClient::subscriberAddress('0707000000')==='tel:94707000000','mSpace normalizes local 070 MSISDNs');
check(MspaceClient::subscriberAddress('+94710000000')==='tel:94710000000','mSpace normalizes international 071 MSISDNs');
check(MspaceClient::reference(['statusCode'=>'S1000','requestCorrelator'=>'safe-test-reference'])==='safe-test-reference','mSpace uses the documented request correlator');
check(MspaceClient::successful(['statusCode'=>'S1000','status'=>true]),'mSpace accepts the documented successful OTP response');
check(MspaceClient::successful(['destinationResponses'=>[['statusCode'=>'S1000']]]),'mSpace accepts the documented successful SMS response');
$smsRequests=[];
$fakeSms=static function(string $key,string $url,array $body) use (&$smsRequests): array {
    $smsRequests[$key]=$body;
    return ['destinationResponses'=>[['statusCode'=>'S1000']]];
};
$smsConfig=['application_id'=>'APP_TEST','password'=>'test-secret','sms_url'=>'https://example.test/sms','sms_source_address'=>'77000'];
(new MspaceClient($smsConfig,$fakeSms,true,false))->sendSms('tel:94707000000','Your code is 123456.');
check(($smsRequests['sms_url']['destinationAddresses'][0]??'')==='tel:94707000000','mSpace SMS preserves the destination subscriber');
check(($smsRequests['sms_url']['sourceAddress']??'')==='77000','mSpace SMS uses the configured source address');
$specificSmsRequest=[];
(new MspaceClient($smsConfig,static function(string $key,string $url,array $body) use (&$specificSmsRequest): array {
    $specificSmsRequest=$body;
    return ['destinationResponses'=>[['statusCode'=>'S1000']]];
},true,false))->sendSms('tel:94703654532','Your code is 123456.');
check(($specificSmsRequest['destinationAddresses'][0]??'')==='tel:94703654532','mSpace formats 0703654532 as tel:94703654532');
$smsFallbackRequests=[];
$fallbackMspace=new MspaceClient(['application_id'=>'APP_TEST','password'=>'test-secret','sms_source_address'=>'77000'],static function(string $key,string $url,array $body) use (&$smsFallbackRequests): array {
    $smsFallbackRequests=['key'=>$key,'url'=>$url,'body'=>$body];
    return ['destinationResponses'=>[['statusCode'=>'S1000']]];
},true,false);
$fallbackMspace->sendSms('tel:94707000000','Your code is 123456.');
check(($smsFallbackRequests['url']??'')==='https://api.mspace.lk/sms/send','mSpace SMS resolves the default endpoint when local config is stale');
$missingSourceRejected=false;
putenv('MSPACE_SMS_SOURCE_ADDRESS=');
try {
    (new MspaceClient(['application_id'=>'APP_TEST','password'=>'test-secret'],static fn(): array=>['destinationResponses'=>[['statusCode'=>'S1000']]],true,false))
        ->sendSms('tel:94707000000','Your code is 123456.');
} catch (RuntimeException $error) {
    $missingSourceRejected=$error->getMessage()==='mSpace SMS source address is not configured.';
}
check($missingSourceRejected,'mSpace SMS rejects missing source address instead of using an unapproved default');
$blocked=false; try { (new MspaceClient($mspaceConfig,$fakeMspace,true,false))->generateChargeOtp('tel:test','safe-id','20.00'); } catch (Throwable) { $blocked=true; }
check($blocked,'mSpace debit is blocked when its CAAS switch is off');

$routes=ApiRoutes::all();
check(($routes['mspace-charge-otp']['handler'][1]??'')==='mspaceChargeOtp','the CAAS payment route starts with OTP authorization');
check(($routes['mspace-charge-verify']['handler'][1]??'')==='mspaceChargeVerify','the CAAS payment verification route confirms OTP');
check(($routes['subscription']['handler'][1]??'')==='subscribe','subscription endpoint is wired to the IdeaMart subscription action');
$controllerSource=file_get_contents(dirname(__DIR__).'/app/Controllers/ApiController.php');
check(is_string($controllerSource)&&str_contains($controllerSource,"new IdeamartClient())->chargingEnabled()")&&str_contains($controllerSource,"new MspaceClient())->chargingEnabled()"),'paid readings use the selected carrier CAAS gate');
check(is_string($controllerSource)&&str_contains($controllerSource,"provider=? AND external_reference"),'paid readings reconcile the selected carrier payment');
check(is_string($controllerSource)&&str_contains($controllerSource,'mSpace rejected this number. Confirm it is an active Mobitel 070 or 071 number'),'Mobitel OTP remains on mSpace when the provider rejects the recipient');
$prices=array_values(array_unique(array_column(array_filter(Divigamana\Services\ServiceCatalog::all(),static fn(array $service): bool=>$service['paid']),'price_lkr')));
check($prices===['20.00'],'all paid readings have a fixed one-time LKR 20.00 price');

echo "\n$passed passed, $failed failed\n";
exit($failed===0?0:1);
