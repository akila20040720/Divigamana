<?php
namespace Divigamana\Controllers;

use Divigamana\Services\QwenProvider;
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
    private static function rate(string $key,int $seconds): bool {
        $now=time();
        if (($_SESSION['rate'][$key]??0)>$now-$seconds) return false;
        $_SESSION['rate'][$key]=$now;
        return true;
    }
    private static function databaseFailure(\Throwable $error): void {
        error_log('Divigamana database error: '.$error->getMessage());
        Http::json(['ok'=>false,'error'=>'Database connection failed. In cPanel, confirm the MySQL user is added to this database with ALL PRIVILEGES, then check the database name, username, and password in config/local.php.'],500);
    }
    public static function rahu(): void { Http::json(['ok'=>true,'rahu'=>RahuKalaya::today()]); }
    public static function auth(): void {
        $input=Http::input();
        Http::requirePostCsrf($input);
        if ((APP_CONFIG['app_env']??'production')!=='development') Http::json(['ok'=>false,'error'=>'Demo OTP is disabled. Configure a production SMS provider.'],503);
        $mobile=self::mobile((string)($input['mobile']??''));
        if (!$mobile) Http::json(['ok'=>false,'error'=>'Enter a valid Sri Lankan mobile number.'],422);
        if (empty($input['consent'])) Http::json(['ok'=>false,'error'=>'Subscription consent is required.'],422);
        if (!self::rate('otp',30)) Http::json(['ok'=>false,'error'=>'Please wait before requesting another code.'],429);
        $_SESSION['otp_mobile']=$mobile;
        $_SESSION['otp_consent']=true;
        $_SESSION['otp_demo_expires']=time()+600;
        Http::json(['ok'=>true,'message'=>'Development mode: enter any six-digit code. No SMS was sent.']);
    }
    public static function verify(): void {
        $input=Http::input();
        Http::requirePostCsrf($input);
        if ((APP_CONFIG['app_env']??'production')!=='development') Http::json(['ok'=>false,'error'=>'Demo OTP is disabled. Configure a production SMS provider.'],503);
        $code=(string)($input['otp']??'');
        $mobile=$_SESSION['otp_mobile']??null;
        if (!preg_match('/^\d{6}$/',$code)||!$mobile||empty($_SESSION['otp_consent'])||time()>($_SESSION['otp_demo_expires']??0)) Http::json(['ok'=>false,'error'=>'Enter a valid six-digit demo code.'],422);
        $pdo=null;
        try {
            $pdo=Database::pdo();
            $pdo->beginTransaction();
            $statement=$pdo->prepare('SELECT * FROM users WHERE mobile_e164=? LIMIT 1');
            $statement->execute([$mobile]);
            $user=$statement->fetch();
            if (!$user) {
                $pdo->prepare("INSERT INTO users (mobile_e164,otp_verified_demo,phone_verified_real,role) VALUES (?,1,0,'user')")->execute([$mobile]);
                $id=(int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO user_subscriptions (user_id,status,is_simulated,accepted_at,starts_at,ends_at) VALUES (?,'active',1,NOW(),NOW(),DATE_ADD(NOW(),INTERVAL 1 DAY))")->execute([$id]);
                $pdo->prepare("INSERT INTO consent_history (user_id,consent_type,consent_text,locale) VALUES (?,'simulated_subscription','Daily Rs. 8.00 + Tax simulated development subscription',?)")->execute([$id,$input['locale']??'si-LK']);
                $user=['id'=>$id,'mobile_e164'=>$mobile,'role'=>'user'];
            } else {
                $pdo->prepare('UPDATE users SET otp_verified_demo=1 WHERE id=?')->execute([$user['id']]);
            }
            $pdo->commit();
            Session::login($user);
            unset($_SESSION['otp_mobile'],$_SESSION['otp_consent']);
            Http::json(['ok'=>true,'user'=>Session::user(),'simulated'=>true]);
        } catch (\Throwable $error) {
            if ($pdo&&$pdo->inTransaction()) $pdo->rollBack();
            self::databaseFailure($error);
        }
    }
    public static function status(): void {
        $user=Session::user();
        if (!$user) Http::json(['ok'=>true,'authenticated'=>false]);
        try {
            $statement=Database::pdo()->prepare("SELECT status,is_simulated,ends_at FROM user_subscriptions WHERE user_id=? ORDER BY id DESC LIMIT 1");
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
        $answers=is_array($input['answers']??null)?$input['answers']:[];
        foreach ($service['fields'] as $field) {
            $value=trim((string)($answers[$field['name']]??''));
            if ($field['required']&&$value==='') Http::json(['ok'=>false,'error'=>'Please complete: '.str_replace('_',' ',$field['name'])],422);
            $answers[$field['name']]=mb_substr(strip_tags($value),0,2500);
        }
        $idempotency=(string)($input['idempotency_key']??'');
        if (!preg_match('/^[a-zA-Z0-9-]{16,100}$/',$idempotency)) Http::json(['ok'=>false,'error'=>'Invalid request identifier.'],422);
        $pdo=null;
        try {
            $pdo=Database::pdo();
            $subscription=$pdo->prepare("SELECT id FROM user_subscriptions WHERE user_id=? AND status='active' AND ends_at>NOW() LIMIT 1");
            $subscription->execute([$user['id']]);
            if (!$subscription->fetch()) Http::json(['ok'=>false,'error'=>'An active subscription is required.'],403);
            $existing=$pdo->prepare('SELECT r.result_json FROM horoscope_requests q JOIN horoscope_results r ON r.request_id=q.id WHERE q.user_id=? AND q.idempotency_key=?');
            $existing->execute([$user['id'],$idempotency]);
            if ($row=$existing->fetch()) Http::json(['ok'=>true,'result'=>json_decode($row['result_json'],true),'cached'=>true]);
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO horoscope_requests (user_id,service_id,status,locale,questionnaire_json,idempotency_key) VALUES (?,?, 'processing',?,?,?)")->execute([$user['id'],$service['id'],$input['locale']??'si-LK',json_encode($answers,JSON_UNESCAPED_UNICODE),$idempotency]);
            $requestId=(int)$pdo->lastInsertId();
            if ($service['paid']) $pdo->prepare("INSERT INTO payment_transactions (user_id,request_id,amount,currency,status,is_simulated,description) VALUES (?,?,20,'LKR','simulated_confirmed',1,'Simulated per-reading charge')")->execute([$user['id'],$requestId]);
            $pdo->commit();
            $result=(new QwenProvider)->generate($service,$answers,$input['locale']??'si-LK');
            $pdo->prepare("INSERT INTO horoscope_results (request_id,result_json,model_name) VALUES (?,?,?)")->execute([$requestId,json_encode($result['result'],JSON_UNESCAPED_UNICODE),$result['model']]);
            $pdo->prepare("UPDATE horoscope_requests SET status='completed',completed_at=NOW() WHERE id=?")->execute([$requestId]);
            $pdo->prepare('INSERT INTO ai_usage_logs (request_id,model_name,total_tokens,duration_ms) VALUES (?,?,?,?)')->execute([$requestId,$result['model'],$result['tokens'],$result['duration_ms']]);
            Http::json(['ok'=>true,'result'=>$result['result'],'request_id'=>$requestId,'simulated_charge'=>$service['paid']]);
        } catch (\Throwable $error) {
            if ($pdo&&$pdo->inTransaction()) $pdo->rollBack();
            if (isset($requestId)) $pdo->prepare("UPDATE horoscope_requests SET status='failed' WHERE id=?")->execute([$requestId]);
            error_log('Divigamana reading error: '.$error->getMessage());
            Http::json(['ok'=>false,'error'=>'We could not generate this reading just now. Please try again.'],503);
        }
    }
}
