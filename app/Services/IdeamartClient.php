<?php
namespace Divigamana\Services;

use RuntimeException;

final class IdeamartClient
{
    private array $config;
    private $transport;
    private bool $live;
    private bool $caasLive;

    public function __construct(?array $config=null, ?callable $transport=null, ?bool $live=null, ?bool $caasLive=null)
    {
        $this->config=$config??(APP_CONFIG['ideamart']??[]);
        $this->transport=$transport;
        $this->live=$live??!empty(APP_CONFIG['ideamart_live']);
        $this->caasLive=$caasLive??!empty(APP_CONFIG['ideamart_caas_live']);
    }

    public function enabled(): bool
    {
        return $this->live
            && trim((string)($this->config['application_id']??''))!==''
            && trim((string)($this->config['password']??''))!=='';
    }

    public function chargingEnabled(): bool { return $this->enabled() && $this->caasLive; }

    public function otpRequest(string $subscriberId,array $metadata=[],?string $applicationHash=null): array
    {
        self::requireSubscriber($subscriberId);
        return $this->call('otp_request_url',[
            'subscriberId'=>$subscriberId,
            'applicationHash'=>$applicationHash??bin2hex(random_bytes(16)),
            'applicationMetaData'=>$metadata,
        ]);
    }

    public function otpVerify(string $referenceNo,string $otp): array
    {
        if ($referenceNo===''||!preg_match('/^\d{4,8}$/',$otp)) throw new RuntimeException('Invalid Ideamart OTP verification request.');
        return $this->call('otp_verify_url',['referenceNo'=>$referenceNo,'otp'=>$otp]);
    }

    public function subscribe(string $subscriberId): array { self::requireSubscriber($subscriberId); return $this->call('subscription_url',['version'=>'1.0','subscriberId'=>$subscriberId,'action'=>'1']); }
    public function unsubscribe(string $subscriberId): array { self::requireSubscriber($subscriberId); return $this->call('subscription_url',['version'=>'1.0','subscriberId'=>$subscriberId,'action'=>'0']); }
    public function subscriptionStatus(string $subscriberId): array { self::requireSubscriber($subscriberId); return $this->call('subscription_status_url',['version'=>'1.0','subscriberId'=>$subscriberId]); }
    public function subscriptionBase(): array { return $this->call('subscription_base_url',['version'=>'1.0']); }

    public function charge(string $subscriberId,string $externalTrxId,string $amount): array
    {
        self::requireSubscriber($subscriberId); self::requireCharge($externalTrxId,$amount);
        $payload=[
            'externalTrxId'=>$externalTrxId,
            'subscriberId'=>$subscriberId,
            'paymentInstrument'=>trim((string)($this->config['caas_payment_instrument']??'MobileAccount')),
            'amount'=>$amount,
            'currency'=>'LKR',
        ];
        $accountId=trim((string)($this->config['caas_account_id']??''));
        if ($accountId!=='') $payload['accountId']=$accountId;
        return $this->call('caas_debit_url',$payload,true);
    }

    public function balance(string $subscriberId): array {
        self::requireSubscriber($subscriberId);
        $payload=['subscriberId'=>$subscriberId,'currency'=>'LKR'];
        $accountId=trim((string)($this->config['caas_account_id']??''));
        if ($accountId!=='') $payload['accountId']=$accountId;
        return $this->call('caas_balance_url',$payload);
    }

    public static function successful(array $response,array $benign=[]): bool { return in_array((string)($response['statusCode']??''),array_merge(['S1000'],$benign),true); }
    public static function diagnostic(array $response): string
    {
        $code=(string)($response['statusCode']??'UNKNOWN'); $detail=trim((string)($response['statusDetail']??''));
        $detail=function_exists('mb_substr')?mb_substr($detail,0,240):substr($detail,0,240);
        return $detail===''?'statusCode='.$code:'statusCode='.$code.' statusDetail='.$detail;
    }

    private static function requireSubscriber(string $subscriberId): void { if ($subscriberId===''||strlen($subscriberId)>255) throw new RuntimeException('Invalid subscriber identifier.'); }
    private static function requireCharge(string $externalTrxId,string $amount): void
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/',$externalTrxId)) throw new RuntimeException('Invalid external transaction identifier.');
        if (!preg_match('/^(?:0|[1-9]\d{0,7})\.\d{2}$/',$amount)||$amount==='0.00') throw new RuntimeException('Invalid charge amount.');
    }

    private function call(string $urlKey,array $payload,bool $charging=false): array
    {
        if (!$this->enabled()) throw new RuntimeException('Ideamart is disabled or credentials are not configured.');
        if ($charging&&!$this->caasLive) throw new RuntimeException('Ideamart charging is disabled.');
        $url=trim((string)($this->config[$urlKey]??''));
        if ($url===''||!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https') throw new RuntimeException('Ideamart HTTPS endpoint is not configured: '.$urlKey);
        $body=array_merge(['applicationId'=>(string)$this->config['application_id'],'password'=>(string)$this->config['password']],$payload);
        if (is_callable($this->transport)) {
            $response=($this->transport)($urlKey,$url,$body);
            if (!is_array($response)) throw new RuntimeException('Ideamart transport returned an invalid response.');
            return $response;
        }
        if (!function_exists('curl_init')) throw new RuntimeException('The PHP cURL extension is required.');
        $handle=curl_init($url);
        if ($handle===false) throw new RuntimeException('Unable to initialize Ideamart request.');
        $options=[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2];
        if (defined('CURLOPT_PROTOCOLS')&&defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
        curl_setopt_array($handle,$options);
        $raw=curl_exec($handle); $http=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE); $failed=$raw===false||curl_errno($handle)!==0;
        if ($failed||$http<200||$http>=300) throw new RuntimeException('Ideamart request failed.');
        if (strlen((string)$raw)>1048576) throw new RuntimeException('Ideamart response was too large.');
        $response=json_decode((string)$raw,true);
        if (!is_array($response)||!isset($response['statusCode'])) throw new RuntimeException('Ideamart returned an invalid response.');
        return $response;
    }
}
