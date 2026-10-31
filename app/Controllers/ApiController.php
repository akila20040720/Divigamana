<?php
namespace Divigamana\Controllers;

use Divigamana\Services\QwenProvider;
use Divigamana\Services\IdeamartClient;
use Divigamana\Services\MspaceClient;
use Divigamana\Services\RahuKalaya;
use Divigamana\Services\ServiceCatalog;
use Divigamana\Support\Database;
use Divigamana\Support\Http;
use Divigamana\Support\Session;

final class ApiController {
    private static function mobile(string $value): ?string {
        $number=preg_replace('/\D/','',$value);
        if (substr($number,0,1)==='0') $number='94'.substr($number,1);
        return substr($number,0,2)==='94'&&strlen($number)===11 ? '+'.$number : null;
    }
    private static function maskMobile(string $mobile): string {
        return substr($mobile,0,4).'******'.substr($mobile,-2);
    }
    private static function rate(string $key,int $seconds): bool {
        $now=time();
        if (($_SESSION['rate'][$key]??0)>$now-$seconds) return false;
        $_SESSION['rate'][$key]=$now;
        return true;
    }
    private static function development(): bool { return (APP_CONFIG['app_env']??'production')==='development'; }
    private static function skipDatabase(): bool { return !empty(APP_CONFIG['skip_database_check']); }
    private static function touchLogin($pdo, int $userId): void {
        $pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$userId]);
    }
    private static function configuredAmount(string $amount): bool {
        if (!preg_match('/^(?:0|[1-9]\d{0,7})\.\d{2}$/',$amount)||$amount==='0.00') return false;
        $amounts=array_values(array_unique(array_map(static fn(array $service): string=>$service['price_lkr'],array_filter(ServiceCatalog::all(),static fn(array $service): bool=>$service['paid']))));
        return in_array($amount,$amounts,true);
    }
    private static function subscriptionState(string $providerStatus): string {
        $providerStatus=strtoupper(trim($providerStatus));
        if ($providerStatus==='REGISTERED') return 'active';
        if (in_array($providerStatus,['PENDING','PENDING CHARGE','CHARGE'],true)) return 'pending';
        if ($providerStatus==='UNREGISTERED') return 'cancelled';
        return 'inactive';
    }
    private static function subscriptionEndSql(string $frequency): string {
        $frequency=strtolower(trim($frequency));
        if (in_array($frequency,['weekly','week'],true)) return 'DATE_ADD(NOW(),INTERVAL 7 DAY)';
        if (in_array($frequency,['monthly','month'],true)) return 'DATE_ADD(NOW(),INTERVAL 1 MONTH)';
        if (in_array($frequency,['yearly','year','annual'],true)) return 'DATE_ADD(NOW(),INTERVAL 1 YEAR)';
        return 'DATE_ADD(NOW(),INTERVAL 1 DAY)';
    }
    private static function saveIdeamartSubscription($pdo,int $userId,string $providerStatus,string $frequency='',?string $notifiedAt=null): void {
        $status=self::subscriptionState($providerStatus);
        $endsAt=$status==='active'?self::subscriptionEndSql($frequency):'NOW()';
        // IdeaMart does not send a notification ID. Updating the newest matching row
        // makes repeated callback deliveries idempotent while retaining prior periods.
        $existing=$pdo->prepare("SELECT id FROM user_subscriptions WHERE user_id=? AND provider='ideamart' AND provider_status=? ORDER BY id DESC LIMIT 1");
        $existing->execute([$userId,strtoupper(trim($providerStatus))]);
        $id=$existing->fetchColumn();
        if ($id) {
            $pdo->prepare("UPDATE user_subscriptions SET status=?,is_simulated=0,frequency=?,notified_at=COALESCE(?,NOW()),starts_at=IF(?='active',NOW(),starts_at),ends_at=$endsAt WHERE id=?")->execute([$status,$frequency,$notifiedAt,$status,$id]);
            return;
        }
        $pdo->prepare("INSERT INTO user_subscriptions (user_id,status,is_simulated,provider,provider_status,frequency,accepted_at,starts_at,ends_at,notified_at) VALUES (?,?,0,'ideamart',?,?,NOW(),NOW(),$endsAt,COALESCE(?,NOW()))")->execute([$userId,$status,strtoupper(trim($providerStatus)),$frequency,$notifiedAt]);
    }
    private static function cleanAnswer(string $value): string {
        $value=strip_tags($value);
        return function_exists('mb_substr') ? mb_substr($value,0,2500) : substr($value,0,2500);
    }
    private static function readingContext($pdo,int $userId,int $serviceId): array {
        $statement=$pdo->prepare("SELECT r.result_json FROM horoscope_requests q JOIN horoscope_results r ON r.request_id=q.id WHERE q.user_id=? AND q.service_id=? AND q.status='completed' ORDER BY r.id DESC LIMIT 3");
        $statement->execute([$userId,$serviceId]);
        $summaries=[]; $hashes=[];
        while ($row=$statement->fetch()) {
            $result=json_decode($row['result_json'],true);
            if (!is_array($result)) continue;
            $headings=[];
            foreach (($result['sections']??[]) as $section) if (is_array($section)&&!empty($section['heading'])) $headings[]=$section['heading'];
            $summary=trim(($result['title']??'').' '.implode(', ',$headings));
            if ($summary!=='') $summaries[]=self::cleanAnswer($summary);
            $hashes[]=hash('sha256',json_encode($result,JSON_UNESCAPED_UNICODE));
        }
        return ['previous_summaries'=>$summaries,'previous_hashes'=>$hashes];
    }
    private static function databaseFailure(\Throwable $error): void {
        error_log('Divigamana database error: '.$error->getMessage());
        Http::json(['ok'=>false,'error'=>'Database connection failed. In cPanel, confirm the MySQL user is added to this database with ALL PRIVILEGES, then check the database name, username, and password in config/local.php.'],500);
    }
    public static function rahu(): void { Http::json(['ok'=>true,'rahu'=>RahuKalaya::today()]); }
    public static function auth(): void {
        $input=Http::input();
        Http::requirePostCsrf($input);
        $mobile=self::mobile((string)($input['mobile']??''));
        if (!$mobile) Http::json(['ok'=>false,'error'=>'Enter a valid Sri Lankan mobile number.'],422);
        if (empty($input['consent'])) Http::json(['ok'=>false,'error'=>'Subscription consent is required.'],422);
        $rateKey='otp:'.hash('sha256',$mobile.'|'.(string)($_SERVER['REMOTE_ADDR']??''));
        if (!self::development() && !self::rate($rateKey,60)) Http::json(['ok'=>false,'error'=>'Please wait before requesting another code.'],429);
        $_SESSION['otp_consent']=true;
        $ideamart=new IdeamartClient();
        if ($ideamart->enabled()) {
            try {
                $response=$ideamart->otpRequest('tel:'.substr($mobile,1), [
                    'client'=>'WEBAPP',
                    'device'=>substr((string)($_SERVER['HTTP_USER_AGENT']??'Web browser'),0,120),
                    'os'=>'Web',
                    'appCode'=>(string)(APP_CONFIG['app_url']??'https://divigamana.lk'),
                ]);
                if (($response['statusCode']??'')==='E1351') {
                    try {
                        $pdo=Database::pdo();
                        $statement=$pdo->prepare('SELECT * FROM users WHERE mobile_e164=? AND phone_verified_real=1 AND ideamart_subscriber_id IS NOT NULL LIMIT 1');
                        $statement->execute([$mobile]);
                        $existingUser=$statement->fetch();
                    } catch (\PDOException $error) {
                        unset($_SESSION['rate'][$rateKey]);
                        error_log('Existing Ideamart user lookup failed: '.$error->getMessage());
                        Http::json(['ok'=>false,'error'=>'Your account is registered, but the application database is unavailable. Please try again after the database service is restored.'],503);
                    }
                    if ($existingUser) {
                        if (($existingUser['account_status'] ?? 'active') === 'blocked') {
                            Http::json(['ok'=>false,'error'=>'This account has been blocked. Contact support for assistance.'],403);
                        }
                        self::touchLogin($pdo, (int)$existingUser['id']);
                        Session::login($existingUser);
                        unset($_SESSION['rate'][$rateKey],$_SESSION['otp_mobile'],$_SESSION['otp_consent'],$_SESSION['otp_reference']);
                        Http::json(['ok'=>true,'existing'=>true,'message'=>'You are already registered. You have been logged in.']);
                    }
                    unset($_SESSION['rate'][$rateKey]);
                    error_log('Ideamart OTP request reported an existing user without a locally verified account: '.self::maskMobile($mobile));
                    Http::json(['ok'=>false,'error'=>'This mobile number is already registered. Complete verification on the original account to continue.'],409);
                }
                if (!IdeamartClient::successful($response)) {
                    unset($_SESSION['rate'][$rateKey]);
                    error_log('Ideamart OTP request rejected: '.IdeamartClient::diagnostic($response));
                    Http::json(['ok'=>false,'error'=>'Could not send the verification code. Please try again.'],502);
                }
                // Track the signup attempt after IdeaMart confirms it sent the OTP.
                // The dashboard uses this pending row until phone verification succeeds.
                try {
                    Database::pdo()->prepare("INSERT IGNORE INTO users (mobile_e164,role) VALUES (?,'user')")->execute([$mobile]);
                } catch (\Throwable $error) {
                    error_log('Pending mobile registration tracking failed: '.$error->getMessage());
                }
                $_SESSION['otp_reference']=$response['referenceNo']??null;
                $_SESSION['otp_mobile']=$mobile;
                $_SESSION['otp_attempts']=0;
                Http::json(['ok'=>true,'message'=>'A verification code was sent to your mobile number.']);
            } catch (\Throwable $error) {
                unset($_SESSION['rate'][$rateKey]);
                error_log('Ideamart OTP request transport failure: '.$error->getMessage());
                Http::json(['ok'=>false,'error'=>'OTP service is temporarily unavailable.'],503);
            }
        }
        Http::json(['ok'=>false,'error'=>'IdeaMart OTP verification is not configured.'],503);
    }
    public static function verify(): void {
        $input=Http::input();
        Http::requirePostCsrf($input);
        $code=(string)($input['otp']??'');
        $mobile=$_SESSION['otp_mobile']??null;
        if (!preg_match('/^\d{6}$/',$code)||!$mobile||empty($_SESSION['otp_consent'])) {
            error_log('OTP verification rejected locally: invalid code, missing OTP session, or missing consent.');
            Http::json(['ok'=>false,'error'=>'The verification session has expired. Request a new OTP and try again.'],422);
        }
        $_SESSION['otp_attempts']=(int)($_SESSION['otp_attempts']??0)+1;
        if ($_SESSION['otp_attempts']>3) Http::json(['ok'=>false,'error'=>'Too many verification attempts. Request a new code.'],429);
        $ideamart=new IdeamartClient();
        $subscriberId=null;
        if ($ideamart->enabled()) {
            try {
                $response=$ideamart->otpVerify((string)($_SESSION['otp_reference']??''),$code);
                if (!IdeamartClient::successful($response)) {
                    error_log('Ideamart OTP verification rejected: '.IdeamartClient::diagnostic($response));
                    Http::json(['ok'=>false,'error'=>'The verification code is invalid or expired. Request a new OTP and try again.'],422);
                }
                $subscriberId=(string)($response['subscriberId']??'');
                if ($subscriberId==='') Http::json(['ok'=>false,'error'=>'OTP verification returned no subscriber identity.'],502);
                $subscription=$ideamart->subscribe($subscriberId);
                if (!IdeamartClient::successful($subscription,['E1351'])) Http::json(['ok'=>false,'error'=>'Mobile verification succeeded, but subscription could not be activated.'],502);
            } catch (\Throwable $error) {
                error_log('Ideamart OTP verification failed: '.$error->getMessage());
                Http::json(['ok'=>false,'error'=>'OTP service is temporarily unavailable.'],503);
            }
        } else Http::json(['ok'=>false,'error'=>'IdeaMart OTP verification is not configured.'],503);
        $pdo=null;
        try {
            $pdo=Database::pdo();
            $pdo->beginTransaction();
            $statement=$pdo->prepare('SELECT * FROM users WHERE mobile_e164=? LIMIT 1');
            $statement->execute([$mobile]);
            $user=$statement->fetch();
            if (!$user) {
                $pdo->prepare("INSERT INTO users (mobile_e164,ideamart_subscriber_id,otp_verified_demo,phone_verified_real,role) VALUES (?,?,0,1,'user')")->execute([$mobile,$subscriberId]);
                $id=(int)$pdo->lastInsertId();
                self::saveIdeamartSubscription($pdo,$id,(string)($subscription['subscriptionStatus']??'PENDING'));
                $consentText='I consented to the Ideamart subscription after reviewing the applicable service terms.';
                $pdo->prepare("INSERT INTO consent_history (user_id,consent_type,consent_text,locale) VALUES (?,'subscription',?,?)")->execute([$id,$consentText,$input['locale']??'si-LK']);
                $user=['id'=>$id,'mobile_e164'=>$mobile,'role'=>'user'];
            } else {
                if (($user['account_status'] ?? 'active') === 'blocked') {
                    $pdo->rollBack();
                    Http::json(['ok'=>false,'error'=>'This account has been blocked. Contact support for assistance.'],403);
                }
                $pdo->prepare('UPDATE users SET otp_verified_demo=0,phone_verified_real=1,ideamart_subscriber_id=COALESCE(?,ideamart_subscriber_id) WHERE id=?')->execute([$subscriberId,$user['id']]);
            }
            $pdo->commit();
            self::touchLogin($pdo, (int)$user['id']);
            Session::login($user);
            unset($_SESSION['otp_mobile'],$_SESSION['otp_consent'],$_SESSION['otp_reference'],$_SESSION['otp_attempts']);
            Http::json(['ok'=>true,'user'=>Session::user(),'simulated'=>false]);
        } catch (\Throwable $error) {
            if ($pdo&&$pdo->inTransaction()) $pdo->rollBack();
            self::databaseFailure($error);
        }
    }
    private static function ideamartUser(): array {
        $user=Session::user();
        if (!$user) Http::json(['ok'=>false,'error'=>'Please sign in first.'],401);
        try {
            $statement=Database::pdo()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
            $statement->execute([$user['id']]);
            $record=$statement->fetch();
        } catch (\Throwable $error) { self::databaseFailure($error); }
        if (!$record || empty($record['ideamart_subscriber_id'])) Http::json(['ok'=>false,'error'=>'Complete mobile verification first.'],409);
        if (($record['account_status'] ?? 'active') === 'blocked') Http::json(['ok'=>false,'error'=>'This account has been blocked. Contact support for assistance.'],403);
        return $record;
    }
    private static function mspaceUser(): array {
        $user=Session::user();
        if (!$user) Http::json(['ok'=>false,'error'=>'Please sign in first.'],401);
        try { $statement=Database::pdo()->prepare('SELECT * FROM users WHERE id=? LIMIT 1'); $statement->execute([$user['id']]); $record=$statement->fetch(); }
        catch (\Throwable $error) { self::databaseFailure($error); }
        if (!$record||empty($record['phone_verified_real'])) Http::json(['ok'=>false,'error'=>'Verified mobile ownership is required.'],409);
        if (($record['account_status']??'active')==='blocked') Http::json(['ok'=>false,'error'=>'This account has been blocked. Contact support for assistance.'],403);
        if (!preg_match('/^\+947[01]\d{7}$/',(string)$record['mobile_e164'])) Http::json(['ok'=>false,'error'=>'mSpace charging is available only for supported Mobitel numbers.'],422);
        return $record;
    }
    public static function subscribe(): void {
        $input=Http::input(); Http::requirePostCsrf($input);
        if (empty($input['consent'])) Http::json(['ok'=>false,'error'=>'Subscription consent is required.'],422);
        $user=self::ideamartUser();
        try {
            $response=(new IdeamartClient())->subscribe($user['ideamart_subscriber_id']);
            if (!IdeamartClient::successful($response,['E1351'])) Http::json(['ok'=>false,'error'=>'Subscription could not be activated.'],502);
            $pdo=Database::pdo();
            $pdo->prepare("INSERT INTO consent_history (user_id,consent_type,consent_text,locale) VALUES (?,'ideamart_subscription',?,?)")->execute([$user['id'],'I consent to the disclosed subscription terms and charges.',(string)($input['locale']??'si-LK')]);
            self::saveIdeamartSubscription($pdo,(int)$user['id'],(string)($response['subscriptionStatus']??'PENDING'));
            Http::json(['ok'=>true,'subscription'=>$response]);
        } catch (\Throwable $error) { error_log('Ideamart subscribe failed: '.$error->getMessage()); Http::json(['ok'=>false,'error'=>'Subscription service is temporarily unavailable.'],503); }
    }
    public static function unsubscribe(): void {
        $input=Http::input(); Http::requirePostCsrf($input); $user=self::ideamartUser();
        try {
            $response=(new IdeamartClient())->unsubscribe($user['ideamart_subscriber_id']);
            if (!IdeamartClient::successful($response,['E1356'])) Http::json(['ok'=>false,'error'=>'Unsubscription could not be completed.'],502);
            Database::pdo()->prepare("UPDATE user_subscriptions SET status='cancelled',ends_at=NOW() WHERE user_id=? AND status='active'")->execute([$user['id']]);
            Http::json(['ok'=>true,'subscription'=>$response]);
        } catch (\Throwable $error) { error_log('Ideamart unsubscribe failed: '.$error->getMessage()); Http::json(['ok'=>false,'error'=>'Subscription service is temporarily unavailable.'],503); }
    }
    public static function ideamartSubscriptionStatus(): void {
        $input=Http::input(); Http::requirePostCsrf($input);
        $user=self::ideamartUser();
        try { $response=(new IdeamartClient())->subscriptionStatus($user['ideamart_subscriber_id']); Http::json(['ok'=>IdeamartClient::successful($response),'subscription'=>$response]); }
        catch (\Throwable $error) { error_log('Ideamart status failed: '.$error->getMessage()); Http::json(['ok'=>false,'error'=>'Subscription service is temporarily unavailable.'],503); }
    }
    public static function subscriptionBase(): void {
        $input=Http::input(); Http::requirePostCsrf($input);
        $fingerprint=hash('sha256',"admin\0"."1234");
        if (!isset($_SESSION['admin_fingerprint'])||!hash_equals($fingerprint,(string)$_SESSION['admin_fingerprint'])) Http::json(['ok'=>false,'error'=>'Administrator access required.'],403);
        try { $response=(new IdeamartClient())->subscriptionBase(); Http::json(['ok'=>IdeamartClient::successful($response),'base'=>$response]); }
        catch (\Throwable $error) { error_log('Ideamart base query failed: '.$error->getMessage()); Http::json(['ok'=>false,'error'=>'Subscription service is temporarily unavailable.'],503); }
    }
    public static function ideamartSubscriptionNotification(): void {
        // Carrier callbacks cannot carry a browser CSRF token. Authenticate by the
        // provisioned application ID and always acknowledge promptly to prevent retries.
        $input=Http::input();
        $expected=(string)(APP_CONFIG['ideamart']['application_id']??'');
        $applicationId=(string)($input['applicationId']??'');
        $subscriberId=trim((string)($input['subscriberId']??''));
        $providerStatus=strtoupper(trim((string)($input['status']??'')));
        if ($expected===''||!hash_equals($expected,$applicationId)||$subscriberId===''||!in_array($providerStatus,['REGISTERED','UNREGISTERED','PENDING','PENDING CHARGE','CHARGE'],true)) {
            error_log('IdeaMart subscription callback rejected.');
            Http::json(['statusCode'=>'S1000']);
        }
        try {
            $pdo=Database::pdo();
            $find=$pdo->prepare('SELECT id FROM users WHERE ideamart_subscriber_id=? LIMIT 1');
            $find->execute([$subscriberId]);
            $userId=(int)$find->fetchColumn();
            if ($userId>0) {
                self::saveIdeamartSubscription($pdo,$userId,$providerStatus,substr((string)($input['frequency']??''),0,60),substr((string)($input['timeStamp']??''),0,40));
            } else {
                error_log('IdeaMart subscription callback has no matching verified user.');
            }
        } catch (\Throwable $error) {
            error_log('IdeaMart subscription callback database failure: '.$error->getMessage());
        }
        Http::json(['statusCode'=>'S1000']);
    }
    public static function ideamartChargeNotification(): void {
        // IdeaMart charging callbacks use the original externalTrxId for reconciliation.
        // They cannot carry a browser CSRF token, so they never create a transaction.
        $input=Http::input();
        $external=trim((string)($input['externalTrxId']??''));
        $statusCode=trim((string)($input['statusCode']??''));
        $applicationId=trim((string)($input['applicationId']??''));
        $expected=(string)(APP_CONFIG['ideamart']['application_id']??'');
        if ($external===''||strlen($external)>120||$statusCode===''||($applicationId!==''&&($expected===''||!hash_equals($expected,$applicationId)))) {
            error_log('IdeaMart charging callback rejected.');
            Http::json(['statusCode'=>'S1000']);
        }
        try {
            $pdo=Database::pdo();
            $reference=substr(trim((string)($input['internalTrxId']??'')),0,120);
            if (in_array($statusCode,['S1000','E1379'],true)) {
                $pdo->prepare("UPDATE payment_transactions SET status='paid',provider_reference=COALESCE(NULLIF(?,''),provider_reference) WHERE provider='ideamart' AND external_reference=? AND status<>'paid'")->execute([$reference,$external]);
            } else {
                // A delayed failure notification must never overwrite a confirmed debit.
                $pdo->prepare("UPDATE payment_transactions SET status='failed',provider_reference=COALESCE(NULLIF(?,''),provider_reference) WHERE provider='ideamart' AND external_reference=? AND status='authorized'")->execute([$reference,$external]);
                error_log('IdeaMart charging callback reported: statusCode='.$statusCode);
            }
        } catch (\Throwable $error) {
            error_log('IdeaMart charging callback database failure: '.$error->getMessage());
        }
        Http::json(['statusCode'=>'S1000']);
    }
    public static function caasBalance(): void {
        $input=Http::input(); Http::requirePostCsrf($input);
        $user=self::ideamartUser();
        try { $response=(new IdeamartClient())->balance($user['ideamart_subscriber_id']); Http::json(['ok'=>IdeamartClient::successful($response),'balance'=>$response]); }
        catch (\Throwable $error) { error_log('Ideamart balance failed: '.$error->getMessage()); Http::json(['ok'=>false,'error'=>'Charging service is temporarily unavailable.'],503); }
    }
    public static function caasCharge(): void {
        $input=Http::input(); Http::requirePostCsrf($input); $user=self::ideamartUser();
        $service=ServiceCatalog::find((string)($input['service']??''));
        if (!$service||!$service['paid']) Http::json(['ok'=>false,'error'=>'A paid service is required for carrier billing.'],422);
        $amount=(string)$service['price_lkr'];
        if (isset($input['amount'])&&(string)$input['amount']!==$amount) Http::json(['ok'=>false,'error'=>'The submitted charge amount does not match this service.'],422);
        if (empty($input['charge_consent'])) Http::json(['ok'=>false,'error'=>'Confirm the disclosed mobile carrier charge before continuing.'],422);
        try {
            $key=(string)($input['idempotency_key']??'');
            if (!preg_match('/^[a-zA-Z0-9-]{16,100}$/',$key)) Http::json(['ok'=>false,'error'=>'Invalid request identifier.'],422);
            $external='divigamana-'.substr(hash('sha256',(string)$user['id'].'|'.$key),0,20);
            $pdo=Database::pdo();
            $existing=$pdo->prepare("SELECT status,provider_reference FROM payment_transactions WHERE user_id=? AND provider='ideamart' AND external_reference=? LIMIT 1");
            $existing->execute([$user['id'],$external]);
            $existingRow=$existing->fetch(\PDO::FETCH_ASSOC) ?: [];
            $existingStatus=(string)($existingRow['status']??'');
            if ($existingStatus==='paid') {
                $_SESSION['caas_paid_reference']=$external;
                $_SESSION['caas_paid_service']=$service['slug'];
                Http::json(['ok'=>true,'charge'=>['statusCode'=>'E1379','externalTrxId'=>$external],'external_reference'=>$external,'cached'=>true]);
            }
            $retryable=$existingStatus==='authorized'&&strpos((string)($existingRow['provider_reference']??''),'retryable:')===0;
            if ($existingStatus==='authorized'&&!$retryable) Http::json(['ok'=>false,'error'=>'This charge is still being processed. Contact support before trying again.'],409);
            if ($retryable) {
                $pdo->prepare("UPDATE payment_transactions SET provider_reference=NULL WHERE user_id=? AND provider='ideamart' AND external_reference=? AND status='authorized'")->execute([$user['id'],$external]);
            } else {
                $pdo->prepare("INSERT INTO payment_transactions (user_id,amount,currency,status,is_simulated,provider,external_reference,description) VALUES (?,?,'LKR','authorized',0,'ideamart',?,?)")->execute([$user['id'],$amount,$external,'IdeaMart CAAS '.$service['slug'].' charge']);
            }
            $consent='I authorize a one-time LKR '.$amount.' mobile-account charge for the '.$service['slug'].' reading.';
            $ipHash=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''));
            $pdo->prepare("INSERT INTO consent_history (user_id,consent_type,consent_text,locale,ip_hash) VALUES (?,'ideamart_caas_charge',?,?,?)")->execute([$user['id'],$consent,(string)($input['locale']??'si-LK'),$ipHash]);
            $response=(new IdeamartClient())->charge($user['ideamart_subscriber_id'],$external,$amount);
            $success=IdeamartClient::successful($response,['E1379']);
            $transient=IdeamartClient::transientFailure($response);
            $providerReference=$transient?'retryable:'.(string)$response['statusCode']:(string)($response['internalTrxId']??'');
            $pdo->prepare("UPDATE payment_transactions SET status=?,provider_reference=? WHERE user_id=? AND provider='ideamart' AND external_reference=?")->execute([$success?'paid':($transient?'authorized':'failed'),$providerReference,$user['id'],$external]);
            if (!$success) error_log('Ideamart CAAS direct debit rejected: '.IdeamartClient::diagnostic($response));
            if ($success) {
                $_SESSION['caas_paid_reference']=$external;
                $_SESSION['caas_paid_service']=$service['slug'];
            }
            $statusCode=(string)($response['statusCode']??'UNKNOWN');
            $errorMessage=$transient
                ? 'IdeaMart charging is temporarily unavailable. Please retry shortly.'
                : ($statusCode==='E1378'?'Your mobile account has insufficient balance. Please top it up and try again.':null);
            Http::json(['ok'=>$success,'status_code'=>$statusCode,'external_reference'=>$external]+($errorMessage!==null?['error'=>$errorMessage]:[]),$success?200:($transient?503:502));
        } catch (\Throwable $error) {
            $transportRetryable=strpos($error->getMessage(),'Ideamart request failed:')===0;
            if (isset($pdo,$external)) $pdo->prepare("UPDATE payment_transactions SET status=?,provider_reference=? WHERE user_id=? AND provider='ideamart' AND external_reference=? AND status='authorized'")->execute([$transportRetryable?'authorized':'failed',$transportRetryable?'retryable:transport':null,$user['id'],$external]);
            error_log('Ideamart CAAS direct debit transport failure: '.$error->getMessage());
            Http::json(['ok'=>false,'error'=>$transportRetryable?'IdeaMart charging is temporarily unavailable. Please retry shortly.':'Charging service is temporarily unavailable.'],503);
        }
    }
    public static function mspaceChargeOtp(): void {
        $input=Http::input(); Http::requirePostCsrf($input); $user=self::mspaceUser();
        $amount=(string)($input['amount']??'');
        if (!self::configuredAmount($amount)) Http::json(['ok'=>false,'error'=>'This charge amount is not configured for a service.'],422);
        try {
            $key=(string)($input['idempotency_key']??'');
            if (!preg_match('/^[a-zA-Z0-9-]{16,100}$/',$key)) Http::json(['ok'=>false,'error'=>'Invalid request identifier.'],422);
            $external='divigamana-'.substr(hash('sha256',(string)$user['id'].'|'.$key),0,20);
            $subscriber='tel:'.substr((string)$user['mobile_e164'],1);
            $pdo=Database::pdo();
            $existing=$pdo->prepare("SELECT status FROM payment_transactions WHERE user_id=? AND provider='mspace' AND external_reference=? LIMIT 1");
            $existing->execute([$user['id'],$external]);
            $existingStatus=$existing->fetchColumn();
            if ($existingStatus==='paid') Http::json(['ok'=>true,'cached'=>true,'external_reference'=>$external]);
            if ($existingStatus==='authorized') Http::json(['ok'=>false,'error'=>'This charge is already awaiting verification.'],409);
            $pdo->prepare("INSERT INTO payment_transactions (user_id,amount,currency,status,is_simulated,provider,external_reference,description) VALUES (?,?,'LKR','authorized',0,'mspace',?,'mSpace CAAS OTP charge')")->execute([$user['id'],$amount,$external]);
            $response=(new MspaceClient())->generateChargeOtp($subscriber,$external,$amount);
            if (!MspaceClient::successful($response)) {
                $pdo->prepare("UPDATE payment_transactions SET status='failed' WHERE user_id=? AND provider='mspace' AND external_reference=?")->execute([$user['id'],$external]);
                error_log('mSpace CAAS OTP rejected: '.MspaceClient::diagnostic($response));
                Http::json(['ok'=>false,'error'=>'mSpace could not send the charging OTP.'],502);
            }
            $_SESSION['mspace_charge']=[
                'externalTrxId'=>$external,
                'referenceNo'=>(string)($response['referenceNo']??''),
                'amount'=>$amount,
                'subscriberId'=>$subscriber,
                'externalTrxId'=>$external,
                'userId'=>(int)$user['id'],
                'expiresAt'=>time()+600,
            ];
            if ($_SESSION['mspace_charge']['referenceNo']==='') { $pdo->prepare("UPDATE payment_transactions SET status='failed' WHERE user_id=? AND provider='mspace' AND external_reference=?")->execute([$user['id'],$external]); unset($_SESSION['mspace_charge']); Http::json(['ok'=>false,'error'=>'mSpace returned no charging reference.'],502); }
            Http::json(['ok'=>true,'message'=>'A charging OTP was sent to your mobile number.']);
        } catch (\Throwable $error) {
            error_log('mSpace CAAS OTP transport failure: '.$error->getMessage());
            Http::json(['ok'=>false,'error'=>'mSpace charging service is temporarily unavailable.'],503);
        }
    }
    public static function mspaceChargeVerify(): void {
        $input=Http::input(); Http::requirePostCsrf($input); $user=self::mspaceUser();
        $charge=$_SESSION['mspace_charge']??null; $otp=(string)($input['otp']??'');
        if (!is_array($charge)||(int)($charge['userId']??0)!==(int)$user['id']||time()>((int)($charge['expiresAt']??0))||!preg_match('/^\d{4,8}$/',$otp)) {
            Http::json(['ok'=>false,'error'=>'The mSpace charging session has expired. Request a new charging OTP.'],422);
        }
        try {
            $response=(new MspaceClient())->verifyChargeOtp((string)$charge['referenceNo'],$otp,(string)$charge['subscriberId']);
            if (!MspaceClient::successful($response)) {
                error_log('mSpace CAAS OTP verification rejected: '.MspaceClient::diagnostic($response));
                Http::json(['ok'=>false,'error'=>'The mSpace charging OTP is invalid or expired.'],422);
            }
            Database::pdo()->prepare("UPDATE payment_transactions SET status='paid',provider_reference=? WHERE user_id=? AND provider='mspace' AND external_reference=?")->execute([(string)($response['internalTrxId']??''),$user['id'],(string)$charge['externalTrxId']]);
            unset($_SESSION['mspace_charge']);
            Http::json(['ok'=>true,'status_code'=>(string)($response['statusCode']??'UNKNOWN'),'external_reference'=>(string)$charge['externalTrxId']]);
        } catch (\Throwable $error) {
            error_log('mSpace CAAS OTP verification failure: '.$error->getMessage());
            Http::json(['ok'=>false,'error'=>'mSpace charging service is temporarily unavailable.'],503);
        }
    }
    public static function status(): void {
        $user=Session::user();
        if (!$user) Http::json(['ok'=>true,'authenticated'=>false]);
        try {
            $pdo=Database::pdo();
            $account=$pdo->prepare('SELECT account_status FROM users WHERE id=? LIMIT 1');
            $account->execute([$user['id']]);
            if ($account->fetchColumn()==='blocked') Http::json(['ok'=>false,'error'=>'This account has been blocked. Contact support for assistance.'],403);
            $statement=$pdo->prepare("SELECT status,is_simulated,ends_at FROM user_subscriptions WHERE user_id=? ORDER BY id DESC LIMIT 1");
            $statement->execute([$user['id']]);
            $subscription=$statement->fetch();
            Http::json(['ok'=>true,'authenticated'=>true,'user'=>$user,'subscribed'=>$subscription&&$subscription['status']==='active'&&$subscription['ends_at']>date('Y-m-d H:i:s'),'subscription'=>$subscription]);
        } catch (\Throwable $error) { self::databaseFailure($error); }
    }
    public static function generate(): void {
        $input=Http::input();
        Http::requirePostCsrf($input);
        $user=Session::user();
        if (!$user) Http::json(['ok'=>false,'error'=>'Please sign in first.'],401);
        $service=ServiceCatalog::find((string)($input['service']??''));
        if (!$service) Http::json(['ok'=>false,'error'=>'Service not found.'],404);
        $chargeReference=trim((string)($_SESSION['caas_paid_reference']??''));
        $chargedService=(string)($_SESSION['caas_paid_service']??'');
        if ($service['paid']&&(!preg_match('/^divigamana-[A-Za-z0-9._-]{1,100}$/',$chargeReference)||$chargedService!==$service['slug'])) Http::json(['ok'=>false,'error'=>'A successful mobile carrier charge is required before this paid reading can start.'],402);
        $answers=is_array($input['answers']??null)?$input['answers']:[];
        foreach ($service['fields'] as $field) {
            $value=trim((string)($answers[$field['name']]??''));
            if ($field['required']&&$value==='') Http::json(['ok'=>false,'error'=>'Please complete: '.str_replace('_',' ',$field['name'])],422);
            $answers[$field['name']]=self::cleanAnswer($value);
        }
        $idempotency=(string)($input['idempotency_key']??'');
        if (!preg_match('/^[a-zA-Z0-9-]{16,100}$/',$idempotency)) Http::json(['ok'=>false,'error'=>'Invalid request identifier.'],422);
        $pdo=null;
        $skipDatabase=false;
        
        if (!$skipDatabase) {
            try {
                error_log('Divigamana: Attempting database connection');
                $pdo=Database::pdo();
                error_log('Divigamana: Database connection successful');
                $account=$pdo->prepare('SELECT account_status FROM users WHERE id=? LIMIT 1');
                $account->execute([$user['id']]);
                if ($account->fetchColumn()==='blocked') Http::json(['ok'=>false,'error'=>'This account has been blocked. Contact support for assistance.'],403);
                $subscription=$pdo->prepare("SELECT id FROM user_subscriptions WHERE user_id=? AND status='active' AND ends_at>NOW() LIMIT 1");
                $subscription->execute([$user['id']]);
                if (!$subscription->fetch()) Http::json(['ok'=>false,'error'=>'An active subscription is required.'],403);
                if ($service['paid']&&!(new IdeamartClient())->chargingEnabled()) Http::json(['ok'=>false,'error'=>'Paid readings are temporarily unavailable while carrier billing is disabled.'],503);
                $paidChargeId=0;
                if ($service['paid']) {
                    $paidCharge=$pdo->prepare("SELECT id FROM payment_transactions WHERE user_id=? AND provider='ideamart' AND external_reference=? AND amount=? AND status='paid' AND (request_id IS NULL OR request_id=(SELECT id FROM horoscope_requests WHERE user_id=? AND idempotency_key=? LIMIT 1)) LIMIT 1");
                    $paidCharge->execute([$user['id'],$chargeReference,$service['price_lkr'],$user['id'],$idempotency]);
                    $paidChargeId=(int)$paidCharge->fetchColumn();
                    if ($paidChargeId===0) Http::json(['ok'=>false,'error'=>'The mobile carrier charge was not confirmed. Please try the payment again before starting this reading.'],402);
                }
                $existing=$pdo->prepare("SELECT q.id,q.status,r.result_json,p.status payment_status FROM horoscope_requests q LEFT JOIN horoscope_results r ON r.request_id=q.id LEFT JOIN payment_transactions p ON p.request_id=q.id AND p.provider='ideamart' WHERE q.user_id=? AND q.idempotency_key=? LIMIT 1");
                $existing->execute([$user['id'],$idempotency]);
                $existingRow=$existing->fetch();
                if ($existingRow&&!empty($existingRow['result_json'])) Http::json(['ok'=>true,'result'=>json_decode($existingRow['result_json'],true),'cached'=>true]);
                $context=self::readingContext($pdo,(int)$user['id'],(int)$service['id']);
                $externalTrxId=null;
                if ($existingRow) {
                    $requestId=(int)$existingRow['id'];
                    if ($service['paid']&&$existingRow['payment_status']!=='paid') {
                        $message=$existingRow['payment_status']==='authorized'?'The carrier charge is awaiting reconciliation. Do not submit another payment.':'The previous carrier charge was not completed.';
                        Http::json(['ok'=>false,'error'=>$message],$existingRow['payment_status']==='authorized'?409:402);
                    }
                    $pdo->prepare("UPDATE horoscope_requests SET status='processing' WHERE id=?")->execute([$requestId]);
                } else {
                    $pdo->beginTransaction();
                    $pdo->prepare("INSERT INTO horoscope_requests (user_id,service_id,status,locale,questionnaire_json,idempotency_key) VALUES (?,?, 'processing',?,?,?)")->execute([$user['id'],$service['id'],$input['locale']??'si-LK',json_encode($answers,JSON_UNESCAPED_UNICODE),$idempotency]);
                    $requestId=(int)$pdo->lastInsertId();
                    if ($service['paid']) {
                        $claim=$pdo->prepare("UPDATE payment_transactions SET request_id=?,description=? WHERE id=? AND provider='ideamart' AND status='paid' AND request_id IS NULL");
                        $claim->execute([$requestId,'IdeaMart CAAS '.$service['slug'].' reading charge',$paidChargeId]);
                        if ($claim->rowCount()!==1) {
                            $pdo->rollBack();
                            Http::json(['ok'=>false,'error'=>'The confirmed carrier charge is already being used. Please start the payment again.'],409);
                        }
                        unset($_SESSION['caas_paid_reference'],$_SESSION['caas_paid_service']);
                    }
                    $pdo->commit();
                }
                $context['reading_reference']='request-'.$requestId.'-'.bin2hex(random_bytes(8));
            } catch (\Throwable $error) {
                if ($pdo&&$pdo->inTransaction()) $pdo->rollBack();
                self::databaseFailure($error);
            }
        } else {
            error_log('Divigamana: Skipping database in development mode');
            $context=[];
            $requestId=null;
        }
        
        try {
            $provider=new QwenProvider();
            $result=$provider->generate($service,$answers,$input['locale']??'si-LK',$context);
            
            if ($pdo&&$requestId) {
                if (in_array(hash('sha256',json_encode($result['result'],JSON_UNESCAPED_UNICODE)),$context['previous_hashes']??[],true)) {
                    $context['reading_reference']='retry-'.$requestId.'-'.bin2hex(random_bytes(12));
                    $result=$provider->generate($service,$answers,$input['locale']??'si-LK',$context);
                }
                $pdo->prepare("INSERT INTO horoscope_results (request_id,result_json,model_name) VALUES (?,?,?)")->execute([$requestId,json_encode($result['result'],JSON_UNESCAPED_UNICODE),$result['model']]);
                $pdo->prepare("UPDATE horoscope_requests SET status='completed',completed_at=NOW() WHERE id=?")->execute([$requestId]);
                $pdo->prepare('INSERT INTO ai_usage_logs (request_id,model_name,total_tokens,duration_ms) VALUES (?,?,?,?)')->execute([$requestId,$result['model'],$result['tokens'],$result['duration_ms']]);
            }
            
            Http::json(['ok'=>true,'result'=>$result['result'],'request_id'=>$requestId,'simulated_charge'=>$service['paid']]);
        } catch (\Throwable $error) {
            if ($pdo&&$pdo->inTransaction()) $pdo->rollBack();
            if ($pdo&&$requestId) $pdo->prepare("UPDATE horoscope_requests SET status='failed' WHERE id=?")->execute([$requestId]);
            error_log('Divigamana reading error: '.$error->getMessage().' in '.$error->getFile().':'.$error->getLine());
            error_log('Divigamana error trace: '.$error->getTraceAsString());
            $errorMessage='We could not generate this reading just now. Please try again.';
            if ((APP_CONFIG['app_env']??'production')==='development') {
                $errorMessage.=' Debug: '.$error->getMessage();
            }
            Http::json(['ok'=>false,'error'=>$errorMessage],503);
        }
    }
}
