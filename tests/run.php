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
    elseif ($action==='ideamart-subscription-notification') check($result['status']===200&&($result['body']['statusCode']??'')==='S1000',"$method $action safely acknowledges an invalid carrier callback");
    else check($result['status']===419&&($result['body']['ok']??true)===false,"$method $action rejects missing CSRF");
    $wrong=apiCase($action,$method==='GET'?'POST':'GET');
    check($wrong['status']===405,"$action rejects the wrong HTTP method");
}
$protectedExpectations=['auth'=>422,'verify'=>422,'subscription'=>422,'unsubscribe'=>401,'subscription-status'=>401,'subscription-base'=>403,'caas-balance'=>401,'caas-charge'=>401,'mspace-charge-otp'=>401,'mspace-charge-verify'=>401,'generate'=>401];
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
$ideamartConfig=['application_id'=>'APP_TEST','password'=>'test-secret','otp_request_url'=>'https://example.test/otp/request','otp_verify_url'=>'https://example.test/otp/verify','subscription_url'=>'https://example.test/subscription','subscription_status_url'=>'https://example.test/status','subscription_base_url'=>'https://example.test/base','caas_debit_url'=>'https://example.test/debit','caas_balance_url'=>'https://example.test/balance'];
$ideamart=new IdeamartClient($ideamartConfig,$fakeIdeamart,true,true);
$ideamart->otpRequest('tel:94770000000',['client'=>'WEBAPP'],'contract-test-hash');
$ideamart->otpVerify('safe-reference','123456');
$ideamart->subscribe('tel:masked-test'); $ideamart->unsubscribe('tel:masked-test');
$ideamart->subscriptionStatus('tel:masked-test'); $ideamart->subscriptionBase(); $ideamart->balance('tel:masked-test');
$ideamart->charge('tel:masked-test','divigamana-contract-test','20.00');
check(($ideamartRequests['caas_debit_url']['body']['paymentInstrument']??'')==='MobileAccount','Ideamart debit payload uses MobileAccount');
check(!isset($ideamartRequests['caas_debit_url']['body']['accountId']),'Ideamart debit omits an unprovisioned account ID');
check(($ideamartRequests['caas_debit_url']['body']['externalTrxId']??'')==='divigamana-contract-test','Ideamart debit preserves its idempotency key');
check(($ideamartRequests['otp_request_url']['body']['applicationHash']??'')==='contract-test-hash','Ideamart OTP request carries an application hash');
$blocked=false; try { (new IdeamartClient($ideamartConfig,$fakeIdeamart,true,false))->charge('tel:test','safe-id','20.00'); } catch (Throwable) { $blocked=true; }
check($blocked,'Ideamart debit is blocked when its CAAS switch is off');
$ideamartWithAccount=new IdeamartClient($ideamartConfig+['caas_account_id'=>'merchant-account'],$fakeIdeamart,true,true);
$ideamartWithAccount->charge('tel:masked-test','divigamana-account-test','20.00');
check(($ideamartRequests['caas_debit_url']['body']['accountId']??'')==='merchant-account','Ideamart debit carries a provisioned account ID');

$mspaceRequests=[];
$fakeMspace=static function(string $key,string $url,array $body) use (&$mspaceRequests): array {
    $mspaceRequests[$key]=['url'=>$url,'body'=>$body];
    return $key==='caas_debit_url'?['statusCode'=>'S1000','referenceNo'=>'safe-test-reference']:['statusCode'=>'S1000','internalTrxId'=>'internal-test'];
};
$mspaceConfig=['application_id'=>'APP_TEST','password'=>'test-secret','caas_debit_url'=>'https://example.test/debit','caas_otp_verify_url'=>'https://example.test/verify'];
$mspace=new MspaceClient($mspaceConfig,$fakeMspace,true,true);
$mspace->generateChargeOtp('tel:94710000000','divigamana-contract-test','20.00');
$mspace->verifyChargeOtp('safe-reference','123456','tel:94710000000');
check(($mspaceRequests['caas_debit_url']['body']['paymentInstrumentName']??'')==='Mobile Account','mSpace debit payload uses Mobile Account');
check(($mspaceRequests['caas_otp_verify_url']['body']['sourceAddress']??'')==='tel:94710000000','mSpace OTP verification preserves the subscriber');
$blocked=false; try { (new MspaceClient($mspaceConfig,$fakeMspace,true,false))->generateChargeOtp('tel:test','safe-id','20.00'); } catch (Throwable) { $blocked=true; }
check($blocked,'mSpace debit is blocked when its CAAS switch is off');

echo "\n$passed passed, $failed failed\n";
exit($failed===0?0:1);
