<?php
namespace Divigamana\Controllers;

use Divigamana\Services\QwenProvider;
use Divigamana\Services\IdeamartClient;
use Divigamana\Services\MspaceClient;
use Divigamana\Services\RahuKalaya;
use Divigamana\Services\ServiceCatalog;
use Divigamana\Support\Database;
use Divigamana\Support\Csrf;
use Divigamana\Support\Http;
use Divigamana\Support\Session;

final class ApiController {
    public static function csrf(): void {
        Http::json(['ok'=>true,'csrf'=>Csrf::token()]);
    }

    private static function mobile(string $value): ?string {
        $number=preg_replace('/\D/','',$value);
        if (substr($number,0,1)==='0') $number='94'.substr($number,1);
        return substr($number,0,2)==='94'&&strlen($number)===11 ? '+'.$number : null;
    }
    private static function isMobitel(string $mobile): bool {
        return preg_match('/^\+947[01]\d{7}$/',$mobile)===1;
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
    private static function uuid4(): string {
        $bytes=random_bytes(16);
        $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);
        $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        $hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
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
    private static function subscriptionState(string $providerStatus,string $provider=''): string {
        $providerStatus=strtoupper(trim($providerStatus));
        if ($providerStatus==='REGISTERED'||($provider==='mspace'&&in_array($providerStatus,['ACTIVE','SUBSCRIBED'],true))) return 'active';
        if (in_array($providerStatus,['PENDING','PENDING CHARGE','CHARGE'],true)) return 'pending';
        if ($providerStatus==='UNREGISTERED') return 'cancelled';
        return 'inactive';
    }
    private static function mspaceSubscriptionExistsSql(): string {
        return "EXISTS(
            SELECT 1
            FROM user_subscriptions s
            WHERE s.user_id=users.id
              AND s.provider='mspace'
              AND s.status='active'
              AND (s.ends_at>NOW() OR UPPER(COALESCE(s.provider_status,'')) IN ('REGISTERED','ACTIVE','SUBSCRIBED'))
              AND NOT EXISTS(
                  SELECT 1
                  FROM user_subscriptions newer
                  WHERE newer.user_id=s.user_id
                    AND newer.provider=s.provider
                    AND newer.id>s.id
              )
        )";
    }
    private static function subscriptionEndSql(string $frequency): string {
        $frequency=strtolower(trim($frequency));
        if (in_array($frequency,['weekly','week'],true)) return 'DATE_ADD(NOW(),INTERVAL 7 DAY)';
        if (in_array($frequency,['monthly','month'],true)) return 'DATE_ADD(NOW(),INTERVAL 1 MONTH)';
        if (in_array($frequency,['yearly','year','annual'],true)) return 'DATE_ADD(NOW(),INTERVAL 1 YEAR)';
        return 'DATE_ADD(NOW(),INTERVAL 1 DAY)';
    }
    private static function saveProviderSubscription($pdo,int $userId,string $provider,string $providerStatus,string $frequency='',?string $notifiedAt=null): void {
        $status=self::subscriptionState($providerStatus,$provider);
        // Providers do not send a notification ID. Update only the latest row when
        // a callback repeats the current state; a state change gets a new history row.
        $normalizedProviderStatus=strtoupper(trim($providerStatus));
        $existing=$pdo->prepare("SELECT id,frequency,provider_status FROM user_subscriptions WHERE user_id=? AND provider=? ORDER BY id DESC LIMIT 1");
        $existing->execute([$userId,$provider]);
        $row=$existing->fetch(\PDO::FETCH_ASSOC);
        if ($row&&strtoupper((string)$row['provider_status'])===$normalizedProviderStatus) {
            $effectiveFrequency=$frequency!==''?$frequency:(string)($row['frequency']??'');
            $endsAt=$status==='active'?self::subscriptionEndSql($effectiveFrequency):'NOW()';
            $pdo->prepare("UPDATE user_subscriptions SET status=?,is_simulated=0,frequency=?,notified_at=COALESCE(?,notified_at,NOW()),starts_at=IF(?='active',NOW(),starts_at),ends_at=$endsAt WHERE id=?")->execute([$status,$effectiveFrequency,$notifiedAt,$status,$row['id']]);
            return;
        }
        $endsAt=$status==='active'?self::subscriptionEndSql($frequency):'NOW()';
        $pdo->prepare("INSERT INTO user_subscriptions (user_id,status,is_simulated,provider,provider_status,frequency,accepted_at,starts_at,ends_at,notified_at) VALUES (?,?,0,?,?,?,NOW(),NOW(),$endsAt,COALESCE(?,NOW()))")->execute([$userId,$status,$provider,$normalizedProviderStatus,$frequency,$notifiedAt]);
    }
    private static function saveIdeamartSubscription($pdo,int $userId,string $providerStatus,string $frequency='',?string $notifiedAt=null): void {
        self::saveProviderSubscription($pdo,$userId,'ideamart',$providerStatus,$frequency,$notifiedAt);
    }
    private static function acknowledgeIdeamartCallback(): void {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo '{"statusCode":"S1000","statusDetail":"Success"}';
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        else {
            while (ob_get_level()>0) @ob_end_flush();
            flush();
        }
    }
    private static function normalizedMoney(string $amount): ?string {
        if (!preg_match('/^(0|[1-9][0-9]{0,7})(?:\\.([0-9]{1,2}))?$/',trim($amount),$match)) return null;
        $whole=ltrim($match[1],'0');
        if ($whole==='') $whole='0';
        return $whole.'.'.str_pad($match[2]??'',2,'0');
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
        if (($input['provider']??'')==='mspace'&&!self::isMobitel($mobile)) {
            Http::json(['ok'=>false,'error'=>'Mobitel access is available only for 070 and 071 numbers.'],422);
        }
        $rateKey='otp:'.hash('sha256',$mobile.'|'.(string)($_SERVER['REMOTE_ADDR']??''));
        if (!self::development() && !self::rate($rateKey,60)) Http::json(['ok'=>false,'error'=>'Please wait before requesting another code.'],429);
        if (self::isMobitel($mobile)) {
            try {
                $response=(new MspaceClient())->otpRequest('tel:'.substr($mobile,1),[
                    'client'=>'WEBAPP',
                    'device'=>'Browser',
                    'os'=>'Web',
                    'appCode'=>(string)(APP_CONFIG['app_url']??''),
                ]);
                if (!MspaceClient::successful($response)) {
                    unset($_SESSION['rate'][$rateKey]);
                    $statusCode=(string)($response['statusCode']??'');
                    if ($statusCode==='E1303') {
                        $error='mSpace has not allow-listed this server IP. Ask mSpace to allow-list the production hosting IP for this application.';
                    } elseif ($statusCode==='E1951') {
                        $error='mSpace rejected this number. Confirm it is an active Mobitel 070 or 071 number and use the tel:947XXXXXXXX format.';
                    } else {
                        $error='Could not send the verification code. Please try again.';
                    }
                    error_log('mSpace OTP request rejected: '.MspaceClient::diagnostic($response));
                    Http::json(['ok'=>false,'error'=>$error],502);
                }
                $_SESSION['otp_mobile']=$mobile;
                $_SESSION['otp_provider']='mspace';
                $_SESSION['otp_reference']=MspaceClient::reference($response);
                if ($_SESSION['otp_reference']==='') {
                    unset($_SESSION['rate'][$rateKey],$_SESSION['otp_mobile'],$_SESSION['otp_provider'],$_SESSION['otp_reference']);
                    error_log('mSpace OTP request returned no reference.');
                    Http::json(['ok'=>false,'error'=>'OTP service returned an invalid response. Please try again.'],502);
                }
                $_SESSION['otp_attempts']=0;
                Http::json(['ok'=>true,'message'=>'A verification code was sent to your mobile number.']);
            } catch (\Throwable $error) {
                unset($_SESSION['rate'][$rateKey]);
                error_log('mSpace OTP request transport failure: '.$error->getMessage());
                Http::json(['ok'=>false,'error'=>'OTP service is temporarily unavailable.'],503);
            }
        }
        $ideamart=new IdeamartClient();
        if ($ideamart->enabled()) {
            try {
                $response=$ideamart->otpRequest('tel:'.substr($mobile,1), [
                    'client'=>'MOBILEAPP',
                    'device'=>'Postman',
                    'os'=>'Windows',
                    'appCode'=>'http://localhost:3000/',
                ],self::uuid4());
                if (($response['statusCode']??'')==='E1351') {
                    unset($_SESSION['rate'][$rateKey]);
                    unset($_SESSION['otp_mobile'],$_SESSION['otp_reference']);
                    Http::json(['ok'=>false,'error'=>'This mobile number is already registered with IdeaMart. The registration OTP cannot authenticate an existing account. Contact support to restore account access.'],409);
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
        if (!preg_match('/^\d{6}$/',$code)||!$mobile) {
            error_log('OTP verification rejected locally: invalid code or missing OTP session.');
            Http::json(['ok'=>false,'error'=>'The verification session has expired. Request a new OTP and try again.'],422);
        }
        $_SESSION['otp_attempts']=(int)($_SESSION['otp_attempts']??0)+1;
        if ($_SESSION['otp_attempts']>3) Http::json(['ok'=>false,'error'=>'Too many verification attempts. Request a new code.'],429);
        $mspaceSubscriberId=null;
        if (($_SESSION['otp_provider']??'ideamart')==='mspace') {
            try {
                $response=(new MspaceClient())->otpVerify((string)($_SESSION['otp_reference']??''),$code);
                if (!MspaceClient::successful($response)) {
                    error_log('mSpace OTP verification rejected: '.MspaceClient::diagnostic($response));
                    Http::json(['ok'=>false,'error'=>'The verification code is invalid or expired. Request a new OTP and try again.'],422);
                }
                $subscriberId=(string)($response['subscriberId']??'');
                if (!preg_match('/^tel:[A-Za-z0-9._~-]+$/',$subscriberId)) {
                    error_log('mSpace OTP verification returned no masked subscriber identity.');
                    Http::json(['ok'=>false,'error'=>'Mobile verification succeeded, but mSpace returned no subscriber identity. Please try again.'],502);
                }
                $mspaceSubscriberId=$subscriberId;
                $subscriberId=null; // The mSpace identity must not be stored as an IdeaMart identity.
                $subscriptionStatus=strtoupper(trim((string)($response['subscriptionStatus']??'REGISTERED')));
            } catch (\Throwable $error) {
                error_log('mSpace OTP verification failed: '.$error->getMessage());
                Http::json(['ok'=>false,'error'=>'OTP service is temporarily unavailable.'],503);
            }
        } else {
        $ideamart=new IdeamartClient();
        $subscriberId=null;
        $subscriptionStatus='';
        if ($ideamart->enabled()) {
            try {
                $response=$ideamart->otpVerify((string)($_SESSION['otp_reference']??''),$code);
                if (!IdeamartClient::successful($response)) {
                    error_log('Ideamart OTP verification rejected: '.IdeamartClient::diagnostic($response));
                    Http::json(['ok'=>false,'error'=>'The verification code is invalid or expired. Request a new OTP and try again.'],422);
                }
                $subscriberId=(string)($response['subscriberId']??'');
                if ($subscriberId==='') Http::json(['ok'=>false,'error'=>'OTP verification returned no subscriber identity.'],502);
                // OTP verification returns the masked subscriber ID and its subscription state.
                // Do not submit a second opt-in request for the same registration.
                $subscriptionStatus=strtoupper(trim((string)($response['subscriptionStatus']??'')));
                if ($subscriptionStatus==='') {
                    $statusResponse=$ideamart->subscriptionStatus($subscriberId);
                    if (!IdeamartClient::successful($statusResponse)) {
                        error_log('Ideamart subscription reconciliation after OTP failed: '.IdeamartClient::diagnostic($statusResponse));
                        Http::json(['ok'=>false,'error'=>'Mobile verification succeeded, but subscription status could not be confirmed. Please try again later.'],503);
                    }
                    $subscriptionStatus=strtoupper(trim((string)($statusResponse['subscriptionStatus']??'')));
                }
                if ($subscriptionStatus==='') Http::json(['ok'=>false,'error'=>'IdeaMart returned no subscription status after mobile verification.'],502);
            } catch (\Throwable $error) {
                error_log('Ideamart OTP verification failed: '.$error->getMessage());
                Http::json(['ok'=>false,'error'=>'OTP service is temporarily unavailable.'],503);
            }
        } else Http::json(['ok'=>false,'error'=>'IdeaMart OTP verification is not configured.'],503);
        }
        $pdo=null;
        try {
            $pdo=Database::pdo();
            $pdo->beginTransaction();
            $statement=$pdo->prepare('SELECT * FROM users WHERE mobile_e164=? LIMIT 1');
            $statement->execute([$mobile]);
            $user=$statement->fetch();
            if (!$user) {
                $pdo->prepare("INSERT INTO users (mobile_e164,ideamart_subscriber_id,mspace_subscriber_id,otp_verified_demo,phone_verified_real,role) VALUES (?,?,?,0,1,'user')")->execute([$mobile,$subscriberId??null,$mspaceSubscriberId??null]);
                $id=(int)$pdo->lastInsertId();
                self::saveProviderSubscription($pdo,$id,$mspaceSubscriberId!==null?'mspace':'ideamart',$subscriptionStatus);
                $user=['id'=>$id,'mobile_e164'=>$mobile,'role'=>'user'];
            } else {
                if (($user['account_status'] ?? 'active') === 'blocked') {
                    $pdo->rollBack();
                    Http::json(['ok'=>false,'error'=>'This account has been blocked. Contact support for assistance.'],403);
                }
                $pdo->prepare('UPDATE users SET otp_verified_demo=0,phone_verified_real=1,ideamart_subscriber_id=COALESCE(?,ideamart_subscriber_id),mspace_subscriber_id=COALESCE(?,mspace_subscriber_id) WHERE id=?')->execute([$subscriberId??null,$mspaceSubscriberId??null,$user['id']]);
                self::saveProviderSubscription($pdo,(int)$user['id'],$mspaceSubscriberId!==null?'mspace':'ideamart',$subscriptionStatus);
            }
            $pdo->commit();
            self::touchLogin($pdo, (int)$user['id']);
            Session::login($user);
            unset($_SESSION['otp_mobile'],$_SESSION['otp_reference'],$_SESSION['otp_provider'],$_SESSION['otp_code_hash'],$_SESSION['otp_expires_at'],$_SESSION['otp_attempts']);
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
        if (empty($record['mspace_subscriber_id'])) Http::json(['ok'=>false,'error'=>'Complete mSpace mobile verification before charging.'],409);
        return $record;
    }
    public static function subscribe(): void {
        $input=Http::input(); Http::requirePostCsrf($input); $user=self::ideamartUser();
        try {
            $response=(new IdeamartClient())->subscribe($user['ideamart_subscriber_id']);
            if (!IdeamartClient::successful($response,['E1351'])) {
                Http::json(['ok'=>false,'error'=>'Subscription could not be activated.'],502);
            }
            $status=(string)($response['subscriptionStatus']??'REGISTERED');
            self::saveIdeamartSubscription(Database::pdo(),(int)$user['id'],$status);
            Http::json(['ok'=>true,'subscription'=>$response]);
        } catch (\Throwable $error) {
            error_log('Ideamart subscribe failed: '.$error->getMessage());
            Http::json(['ok'=>false,'error'=>'Subscription service is temporarily unavailable.'],503);
        }
    }
    public static function unsubscribe(): void {
        $input=Http::input(); Http::requirePostCsrf($input); $user=self::ideamartUser();
        try {
            $response=(new IdeamartClient())->unsubscribe($user['ideamart_subscriber_id']);
            if (!IdeamartClient::successful($response,['E1356'])) Http::json(['ok'=>false,'error'=>'Unsubscription could not be completed.'],502);
            self::saveIdeamartSubscription(Database::pdo(),(int)$user['id'],(string)($response['subscriptionStatus']??'UNREGISTERED'));
            Http::json(['ok'=>true,'subscription'=>$response]);
        } catch (\Throwable $error) { error_log('Ideamart unsubscribe failed: '.$error->getMessage()); Http::json(['ok'=>false,'error'=>'Subscription service is temporarily unavailable.'],503); }
    }
    public static function ideamartSubscriptionStatus(): void {
        $input=Http::input(); Http::requirePostCsrf($input);
        $user=self::ideamartUser();
        try {
            $response=(new IdeamartClient())->subscriptionStatus($user['ideamart_subscriber_id']);
            $ok=IdeamartClient::successful($response);
            $providerStatus=strtoupper(trim((string)($response['subscriptionStatus']??'')));
            if ($ok&&$providerStatus!=='') self::saveIdeamartSubscription(Database::pdo(),(int)$user['id'],$providerStatus);
            Http::json(['ok'=>$ok,'subscription'=>$response]);
        }
        catch (\Throwable $error) { error_log('Ideamart status failed: '.$error->getMessage()); Http::json(['ok'=>false,'error'=>'Subscription service is temporarily unavailable.'],503); }
    }
    public static function subscriptionBase(): void {
        $input=Http::input(); Http::requirePostCsrf($input);
        $admin=APP_CONFIG['admin']??[];
        $username=(string)(getenv('ADMIN_USERNAME') ?: ($admin['username']??''));
        $password=(string)(getenv('ADMIN_PASSWORD') ?: ($admin['password']??''));
        if ($username===''||$password==='') Http::json(['ok'=>false,'error'=>'Administrator access is not configured.'],503);
        $fingerprint=hash('sha256',$username."\0".$password);
        if (!isset($_SESSION['admin_fingerprint'])||!hash_equals($fingerprint,(string)$_SESSION['admin_fingerprint'])) Http::json(['ok'=>false,'error'=>'Administrator access required.'],403);
        try { $response=(new IdeamartClient())->subscriptionBase(); Http::json(['ok'=>IdeamartClient::successful($response),'base'=>$response]); }
        catch (\Throwable $error) { error_log('Ideamart base query failed: '.$error->getMessage()); Http::json(['ok'=>false,'error'=>'Subscription service is temporarily unavailable.'],503); }
    }
    public static function ideamartSubscriptionNotification(): void {
        // Carrier callbacks cannot carry a browser CSRF token. Validate the application
        // ID, acknowledge immediately, then mirror the provider's authoritative state.
        $input=Http::input();
        $expected=(string)(APP_CONFIG['ideamart']['application_id']??'');
        $applicationId=(string)($input['applicationId']??'');
        $subscriberId=trim((string)($input['subscriberId']??''));
        $providerStatus=strtoupper(trim((string)($input['status']??'')));
        if ($expected===''||!hash_equals($expected,$applicationId)||$subscriberId===''||!in_array($providerStatus,['REGISTERED','UNREGISTERED','PENDING','PENDING CHARGE','CHARGE'],true)) {
            error_log('IdeaMart subscription callback rejected.');
            self::acknowledgeIdeamartCallback();
            return;
        }
        self::acknowledgeIdeamartCallback();
        try {
            $pdo=Database::pdo();
            $find=$pdo->prepare('SELECT id FROM users WHERE ideamart_subscriber_id=? LIMIT 1');
            $find->execute([$subscriberId]);
            $userId=(int)$find->fetchColumn();
            // Subscription callbacks may contain the plain MSISDN shown in IdeaMart's
            // sample payload, while OTP verification stores a masked subscriber ID.
            if ($userId===0&&str_starts_with($subscriberId,'tel:')) {
                $callbackMobile=self::mobile(substr($subscriberId,4));
                if ($callbackMobile!==null) {
                    $find=$pdo->prepare('SELECT id FROM users WHERE mobile_e164=? AND phone_verified_real=1 LIMIT 1');
                    $find->execute([$callbackMobile]);
                    $userId=(int)$find->fetchColumn();
                }
            }
            if ($userId>0) {
                self::saveIdeamartSubscription($pdo,$userId,$providerStatus,substr((string)($input['frequency']??''),0,60),substr((string)($input['timeStamp']??''),0,40));
            } else {
                error_log('IdeaMart subscription callback has no matching verified user.');
            }
        } catch (\Throwable $error) {
            error_log('IdeaMart subscription callback database failure: '.$error->getMessage());
        }
    }
    public static function ideamartChargeNotification(): void {
        // Callbacks have no documented signature. Require a private portal URL token and
        // match callback identity/amount to the stored debit before changing its state.
        $input=Http::input();
        $external=trim((string)($input['externalTrxId']??''));
        $statusCode=trim((string)($input['statusCode']??''));
        $applicationId=trim((string)($input['applicationId']??''));
        $expected=(string)(APP_CONFIG['ideamart']['application_id']??'');
        $callbackToken=(string)(APP_CONFIG['ideamart']['caas_callback_token']??'');
        $providedToken=(string)($_GET['token']??($_SERVER['HTTP_X_IDEAMART_CALLBACK_TOKEN']??''));
        $subscriberId=trim((string)($input['subscriberId']??''));
        $amount=self::normalizedMoney((string)($input['amount']??''));
        $currency=strtoupper(trim((string)($input['currency']??'')));
        if ($callbackToken===''||!hash_equals($callbackToken,$providedToken)||!preg_match('/^[A-Za-z0-9._-]{1,32}$/',$external)||!preg_match('/^[A-Z][0-9]{4}$/',$statusCode)||$subscriberId===''||$amount===null||$currency!=='LKR'||($applicationId!==''&&($expected===''||!hash_equals($expected,$applicationId)))) {
            error_log('IdeaMart charging callback rejected.');
            self::acknowledgeIdeamartCallback();
            return;
        }
        self::acknowledgeIdeamartCallback();
        try {
            $pdo=Database::pdo();
            $find=$pdo->prepare("SELECT p.id,p.amount,p.currency,u.ideamart_subscriber_id,u.mobile_e164 FROM payment_transactions p JOIN users u ON u.id=p.user_id WHERE p.provider='ideamart' AND p.external_reference=? LIMIT 1");
            $find->execute([$external]);
            $transaction=$find->fetch(\PDO::FETCH_ASSOC);
            $callbackMobile=str_starts_with($subscriberId,'tel:')?self::mobile(substr($subscriberId,4)):null;
            if (!$transaction
                ||(!hash_equals((string)$transaction['ideamart_subscriber_id'],$subscriberId)&&($callbackMobile===null||!hash_equals((string)$transaction['mobile_e164'],$callbackMobile)))
                ||self::normalizedMoney((string)$transaction['amount'])!==$amount
                ||strtoupper((string)$transaction['currency'])!==$currency) {
                error_log('IdeaMart charging callback did not match a stored transaction.');
                return;
            }
            $reference=substr(trim((string)($input['internalTrxId']??'')),0,120);
            if (in_array($statusCode,['S1000','E1379'],true)) {
                $pdo->prepare("UPDATE payment_transactions SET status='paid',provider_reference=COALESCE(NULLIF(?,''),provider_reference) WHERE id=? AND status<>'refunded'")->execute([$reference,$transaction['id']]);
            } elseif (IdeamartClient::transientFailure(['statusCode'=>$statusCode])) {
                $pdo->prepare("UPDATE payment_transactions SET provider_reference=? WHERE id=? AND status='authorized'")->execute(['retryable:'.$statusCode,$transaction['id']]);
            } else {
                // A delayed failure notification must never overwrite a confirmed debit.
                $pdo->prepare("UPDATE payment_transactions SET status='failed',provider_reference=COALESCE(NULLIF(?,''),provider_reference) WHERE id=? AND status='authorized'")->execute([$reference,$transaction['id']]);
                error_log('IdeaMart charging callback reported: statusCode='.$statusCode);
            }
        } catch (\Throwable $error) {
            error_log('IdeaMart charging callback database failure: '.$error->getMessage());
        }
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
        if (empty($input['charge_consent'])) Http::json(['ok'=>false,'error'=>'Confirm the disclosed one-time Rs. 20.00 charge before continuing.'],422);
        $key=(string)($input['idempotency_key']??'');
        if (!preg_match('/^[a-zA-Z0-9-]{16,100}$/',$key)) Http::json(['ok'=>false,'error'=>'Invalid request identifier.'],422);
        $external='divigamana-'.substr(hash('sha256',(string)$user['id'].'|'.$key),0,20);
        $pdo=Database::pdo();
        try {
            $existing=$pdo->prepare("SELECT status,provider_reference FROM payment_transactions WHERE user_id=? AND provider='ideamart' AND external_reference=? LIMIT 1");
            $existing->execute([$user['id'],$external]);
            $row=$existing->fetch(\PDO::FETCH_ASSOC) ?: [];
            if (($row['status']??'')==='paid') {
                $_SESSION['caas_paid_reference']=$external;
                $_SESSION['caas_paid_service']=$service['slug'];
                Http::json(['ok'=>true,'already_paid'=>true,'external_reference'=>$external]);
            }
            $pdo->prepare("INSERT IGNORE INTO payment_transactions (user_id,amount,currency,status,is_simulated,provider,external_reference,description) VALUES (?,?,'LKR','authorized',0,'ideamart',?,?)")->execute([$user['id'],$amount,$external,'IdeaMart CAAS one-time '.$service['slug'].' charge']);
            $consent='I authorize a one-time LKR '.$amount.' mobile-account charge for the '.$service['slug'].' reading.';
            $pdo->prepare("INSERT INTO consent_history (user_id,consent_type,consent_text,locale,ip_hash) VALUES (?,'ideamart_caas_charge',?,?,?)")->execute([$user['id'],$consent,(string)($input['locale']??'si-LK'),hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''))]);
            $response=(new IdeamartClient())->charge($user['ideamart_subscriber_id'],$external,$amount);
            $success=IdeamartClient::successful($response,['E1379']);
            if (!$success) {
                $pdo->prepare("UPDATE payment_transactions SET status='failed',provider_reference=? WHERE user_id=? AND provider='ideamart' AND external_reference=? AND status='authorized'")->execute([(string)($response['internalTrxId']??''),$user['id'],$external]);
                error_log('IdeaMart CAAS debit rejected: '.IdeamartClient::diagnostic($response));
                Http::json(['ok'=>false,'error'=>'The mobile carrier charge was not completed.'],502);
            }
            $update=$pdo->prepare("UPDATE payment_transactions SET status='paid',provider_reference=? WHERE user_id=? AND provider='ideamart' AND external_reference=? AND status='authorized'");
            $update->execute([(string)($response['internalTrxId']??''),$user['id'],$external]);
            if ($update->rowCount()!==1) Http::json(['ok'=>false,'error'=>'The payment record could not be confirmed. Contact support before retrying.'],409);
            $_SESSION['caas_paid_reference']=$external;
            $_SESSION['caas_paid_service']=$service['slug'];
            Http::json(['ok'=>true,'status_code'=>(string)($response['statusCode']??'UNKNOWN'),'external_reference'=>$external]);
        } catch (\Throwable $error) {
            $pdo->prepare("UPDATE payment_transactions SET status='failed' WHERE user_id=? AND provider='ideamart' AND external_reference=? AND status='authorized'")->execute([$user['id'],$external]);
            error_log('IdeaMart CAAS debit transport failure: '.$error->getMessage());
            Http::json(['ok'=>false,'error'=>'The carrier charging service is temporarily unavailable.'],503);
        }
    }
    public static function mspaceChargeOtp(): void {
        $input=Http::input(); Http::requirePostCsrf($input); $user=self::mspaceUser();
        $service=ServiceCatalog::find((string)($input['service']??''));
        if (!$service||!$service['paid']) Http::json(['ok'=>false,'error'=>'A paid service is required for carrier billing.'],422);
        $amount=(string)$service['price_lkr'];
        if (isset($input['amount'])&&(string)$input['amount']!==$amount) Http::json(['ok'=>false,'error'=>'The submitted charge amount does not match this service.'],422);
        if (empty($input['charge_consent'])) Http::json(['ok'=>false,'error'=>'Confirm the disclosed one-time Rs. 20.00 charge before continuing.'],422);
        try {
            $key=(string)($input['idempotency_key']??'');
            if (!preg_match('/^[a-zA-Z0-9-]{16,100}$/',$key)) Http::json(['ok'=>false,'error'=>'Invalid request identifier.'],422);
            $external='divigamana-'.substr(hash('sha256',(string)$user['id'].'|'.$key),0,20);
            $subscriber=(string)$user['mspace_subscriber_id'];
            $pdo=Database::pdo();
            $existing=$pdo->prepare("SELECT status,provider_reference FROM payment_transactions WHERE user_id=? AND provider='mspace' AND external_reference=? LIMIT 1");
            $existing->execute([$user['id'],$external]);
            $existingRow=$existing->fetch(\PDO::FETCH_ASSOC) ?: [];
            $existingStatus=(string)($existingRow['status']??'');
            if ($existingStatus==='paid') Http::json(['ok'=>false,'error'=>'This payment has already been used. Start a new one-time payment.'],409);
            if ($existingStatus==='authorized') Http::json(['ok'=>false,'error'=>'This charge is already awaiting OTP verification. Verify it or request support.'],409);
            $pdo->prepare("INSERT INTO payment_transactions (user_id,amount,currency,status,is_simulated,provider,external_reference,description) VALUES (?,?,'LKR','authorized',0,'mspace',?,?)")->execute([$user['id'],$amount,$external,'mSpace CAAS one-time '.$service['slug'].' charge']);
            $consent='I authorize a one-time LKR '.$amount.' mobile-account charge for the '.$service['slug'].' reading.';
            $ipHash=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''));
            $pdo->prepare("INSERT INTO consent_history (user_id,consent_type,consent_text,locale,ip_hash) VALUES (?,'mspace_caas_charge',?,?,?)")->execute([$user['id'],$consent,(string)($input['locale']??'si-LK'),$ipHash]);
            $response=(new MspaceClient())->generateChargeOtp($subscriber,$external,$amount);
            $reference=MspaceClient::reference($response);
            if (!MspaceClient::successful($response)||$reference==='') {
                $pdo->prepare("UPDATE payment_transactions SET status='failed' WHERE user_id=? AND provider='mspace' AND external_reference=?")->execute([$user['id'],$external]);
                error_log('mSpace CAAS OTP rejected: '.MspaceClient::diagnostic($response));
                Http::json(['ok'=>false,'error'=>'mSpace could not send the charging OTP.'],502);
            }
            $_SESSION['mspace_charge']=['externalTrxId'=>$external,'referenceNo'=>$reference,'amount'=>$amount,'subscriberId'=>$subscriber,'userId'=>(int)$user['id'],'service'=>$service['slug'],'expiresAt'=>time()+600];
            Http::json(['ok'=>true,'message'=>'A charging OTP was sent to your mobile number.']);
        } catch (\Throwable $error) {
            if (isset($pdo,$external)) $pdo->prepare("UPDATE payment_transactions SET status='failed' WHERE user_id=? AND provider='mspace' AND external_reference=? AND status='authorized'")->execute([$user['id'],$external]);
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
            $providerReference=substr(trim((string)($response['internalTrxId']??$charge['referenceNo'])),0,120);
            $update=Database::pdo()->prepare("UPDATE payment_transactions SET status='paid',provider_reference=? WHERE user_id=? AND provider='mspace' AND external_reference=? AND amount=? AND status='authorized'");
            $update->execute([$providerReference,$user['id'],(string)$charge['externalTrxId'],(string)$charge['amount']]);
            if ($update->rowCount()!==1) Http::json(['ok'=>false,'error'=>'The payment record could not be confirmed. Contact support before retrying.'],409);
            $_SESSION['caas_paid_reference']=(string)$charge['externalTrxId'];
            $_SESSION['caas_paid_service']=(string)$charge['service'];
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
            $statement=$pdo->prepare("SELECT phone_verified_real,mobile_e164,mspace_subscriber_id,".self::mspaceSubscriptionExistsSql()." AS mspace_subscription_active FROM users WHERE id=? LIMIT 1");
            $statement->execute([$user['id']]);
            $account=$statement->fetch(\PDO::FETCH_ASSOC);
            $verified=(bool)($account['phone_verified_real']??false);
            self::touchLogin($pdo,(int)$user['id']);
            $mspaceCaasFree=self::isMobitel((string)($account['mobile_e164']??''))
                && !empty($account['mspace_subscriber_id'])
                && !empty($account['mspace_subscription_active']);
            Http::json(['ok'=>true,'authenticated'=>true,'user'=>$user,'subscribed'=>$verified,'mspace_caas_free'=>$mspaceCaasFree,'subscription'=>null]);
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
        $submittedAnswers=is_array($input['answers']??null)?$input['answers']:[];
        $answers=[];
        foreach ($service['fields'] as $field) {
            $value=trim((string)($submittedAnswers[$field['name']]??''));
            if ($field['required']&&$value==='') Http::json(['ok'=>false,'error'=>'Please complete: '.str_replace('_',' ',$field['name'])],422);
            if ($value!==''&&$field['type']==='select') {
                $allowed=array_column($field['options']??[],'value');
                if (!in_array($value,$allowed,true)) Http::json(['ok'=>false,'error'=>'Choose a valid option for '.str_replace('_',' ',$field['name']).'.'],422);
            }
            if ($field['type']==='checkbox'&&$value!==''&&$value!=='yes') Http::json(['ok'=>false,'error'=>'Please confirm the consent question.'],422);
            if ($value!==''&&$field['type']==='date') {
                $parts=[];
                if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$value,$parts)||!checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1])) Http::json(['ok'=>false,'error'=>'Enter a valid date for '.str_replace('_',' ',$field['name']).'.'],422);
            }
            if ($value!==''&&$field['type']==='month'&&!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$value)) Http::json(['ok'=>false,'error'=>'Choose a valid month.'],422);
            if ($value!==''&&$field['type']==='time'&&!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$value)) Http::json(['ok'=>false,'error'=>'Enter a valid time.'],422);
            if ($value!==''&&$field['type']==='number') {
                if (!preg_match('/^\d+$/',$value)) Http::json(['ok'=>false,'error'=>'Enter a whole number for '.str_replace('_',' ',$field['name']).'.'],422);
                $number=(int)$value;
                if ($field['name']==='count'&&($number<1||$number>15)) Http::json(['ok'=>false,'error'=>'Choose between 1 and 15 names.'],422);
                if ($field['name']==='year'&&($number<1900||$number>2200)) Http::json(['ok'=>false,'error'=>'Choose a year between 1900 and 2200.'],422);
                $value=(string)$number;
            }
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
                $verified=$pdo->prepare("SELECT phone_verified_real,mobile_e164,mspace_subscriber_id,".self::mspaceSubscriptionExistsSql()." AS mspace_subscription_active FROM users WHERE id=? LIMIT 1");
                $verified->execute([$user['id']]);
                $accountRecord=$verified->fetch(\PDO::FETCH_ASSOC);
                if (!$accountRecord||!$accountRecord['phone_verified_real']) Http::json(['ok'=>false,'error'=>'Verify your mobile number by OTP before using this service.'],403);
                $paymentProvider=self::isMobitel((string)$accountRecord['mobile_e164'])?'mspace':'ideamart';
                $mspaceCaasFree=$paymentProvider==='mspace'
                    && !empty($accountRecord['mspace_subscriber_id'])
                    && !empty($accountRecord['mspace_subscription_active']);
                if ($service['paid']&&!$mspaceCaasFree
                    && (!preg_match('/^divigamana-[A-Za-z0-9._-]{1,100}$/',$chargeReference)||$chargedService!==$service['slug'])) {
                    Http::json(['ok'=>false,'error'=>'A successful mobile carrier charge is required before this paid reading can start.'],402);
                }
                $chargingAvailable=$paymentProvider==='mspace'
                    ? (new MspaceClient())->chargingEnabled()
                    : (new IdeamartClient())->chargingEnabled();
                if ($service['paid']&&!$mspaceCaasFree&&!$chargingAvailable) Http::json(['ok'=>false,'error'=>'Carrier billing is temporarily unavailable.'],503);
                $paidChargeId=0;
                if ($service['paid']&&!$mspaceCaasFree) {
                    $paidCharge=$pdo->prepare("SELECT id FROM payment_transactions WHERE user_id=? AND provider=? AND external_reference=? AND amount=? AND status='paid' AND (request_id IS NULL OR request_id=(SELECT id FROM horoscope_requests WHERE user_id=? AND idempotency_key=? LIMIT 1)) LIMIT 1");
                    $paidCharge->execute([$user['id'],$paymentProvider,$chargeReference,$service['price_lkr'],$user['id'],$idempotency]);
                    $paidChargeId=(int)$paidCharge->fetchColumn();
                    if ($paidChargeId===0) Http::json(['ok'=>false,'error'=>'The mobile carrier charge was not confirmed. Please try the payment again before starting this reading.'],402);
                }
                $existing=$pdo->prepare("SELECT q.id,q.status,r.result_json,p.status payment_status FROM horoscope_requests q LEFT JOIN horoscope_results r ON r.request_id=q.id LEFT JOIN payment_transactions p ON p.request_id=q.id AND p.provider=? WHERE q.user_id=? AND q.idempotency_key=? LIMIT 1");
                $existing->execute([$paymentProvider,$user['id'],$idempotency]);
                $existingRow=$existing->fetch();
                if ($existingRow&&!empty($existingRow['result_json'])) Http::json(['ok'=>true,'result'=>json_decode($existingRow['result_json'],true),'cached'=>true]);
                $context=self::readingContext($pdo,(int)$user['id'],(int)$service['id']);
                $externalTrxId=null;
                if ($existingRow) {
                    $requestId=(int)$existingRow['id'];
                    if ($service['paid']&&!$mspaceCaasFree&&$existingRow['payment_status']!=='paid') {
                        $message=$existingRow['payment_status']==='authorized'?'The carrier charge is awaiting reconciliation. Do not submit another payment.':'The previous carrier charge was not completed.';
                        Http::json(['ok'=>false,'error'=>$message],$existingRow['payment_status']==='authorized'?409:402);
                    }
                    $pdo->prepare("UPDATE horoscope_requests SET status='processing' WHERE id=?")->execute([$requestId]);
                } else {
                    $pdo->beginTransaction();
                    $pdo->prepare("INSERT INTO horoscope_requests (user_id,service_id,status,locale,questionnaire_json,idempotency_key) VALUES (?,?, 'processing',?,?,?)")->execute([$user['id'],$service['id'],$input['locale']??'si-LK',json_encode($answers,JSON_UNESCAPED_UNICODE),$idempotency]);
                    $requestId=(int)$pdo->lastInsertId();
                    if ($service['paid']&&!$mspaceCaasFree) {
                        $claim=$pdo->prepare("UPDATE payment_transactions SET request_id=?,description=? WHERE id=? AND provider=? AND status='paid' AND request_id IS NULL");
                        $claim->execute([$requestId,ucfirst($paymentProvider).' CAAS '.$service['slug'].' one-time charge',$paidChargeId,$paymentProvider]);
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
            // Qwen may take longer than MySQL's wait_timeout. Drop the idle
            // connection and reconnect only when the generated result is ready.
            if ($requestId) {
                $pdo=null;
                Database::disconnect();
            }
            $provider=new QwenProvider();
            $result=$provider->generate($service,$answers,$input['locale']??'si-LK',$context);
            
            if ($requestId) {
                if (in_array(hash('sha256',json_encode($result['result'],JSON_UNESCAPED_UNICODE)),$context['previous_hashes']??[],true)) {
                    $context['reading_reference']='retry-'.$requestId.'-'.bin2hex(random_bytes(12));
                    $pdo=null;
                    Database::disconnect();
                    $result=$provider->generate($service,$answers,$input['locale']??'si-LK',$context);
                }
                $pdo=Database::pdo();
                $pdo->prepare("INSERT INTO horoscope_results (request_id,result_json,model_name) VALUES (?,?,?)")->execute([$requestId,json_encode($result['result'],JSON_UNESCAPED_UNICODE),$result['model']]);
                $pdo->prepare("UPDATE horoscope_requests SET status='completed',completed_at=NOW() WHERE id=?")->execute([$requestId]);
                $pdo->prepare('INSERT INTO ai_usage_logs (request_id,model_name,total_tokens,duration_ms) VALUES (?,?,?,?)')->execute([$requestId,$result['model'],$result['tokens'],$result['duration_ms']]);
            }
            
            Http::json(['ok'=>true,'result'=>$result['result'],'request_id'=>$requestId,'simulated_charge'=>$service['paid']]);
        } catch (\Throwable $error) {
            try {
                if ($pdo&&$pdo->inTransaction()) $pdo->rollBack();
                if ($requestId) {
                    $pdo=Database::pdo();
                    $pdo->prepare("UPDATE horoscope_requests SET status='failed' WHERE id=? AND status<>'completed'")->execute([$requestId]);
                }
            } catch (\Throwable $databaseError) {
                error_log('Divigamana: Could not update reading failure status: '.$databaseError->getMessage());
            }
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
