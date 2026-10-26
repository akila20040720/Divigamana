<?php
namespace Divigamana\Services;

use RuntimeException;

final class MspaceClient
{
    private array $config;
    private $transport;
    private bool $live;
    private bool $caasLive;

    public function __construct(?array $config=null,?callable $transport=null,?bool $live=null,?bool $caasLive=null)
    {
        $this->config=$config??(APP_CONFIG['mspace']??[]);
        $this->transport=$transport;
        $this->live=$live??!empty(APP_CONFIG['mspace_live']);
        $this->caasLive=$caasLive??!empty(APP_CONFIG['mspace_caas_live']);
    }

    public function enabled(): bool
    {
        return $this->live
            && trim((string)($this->config['application_id']??''))!==''
            && trim((string)($this->config['password']??''))!=='';
    }
    public function chargingEnabled(): bool { return $this->enabled()&&$this->caasLive; }

    public function generateChargeOtp(string $subscriberId,string $externalTrxId,string $amount): array
    {
        self::requireSubscriber($subscriberId); self::requireCharge($externalTrxId,$amount);
        return $this->call('caas_debit_url',[
            'externalTrxId'=>$externalTrxId,
            'subscriberId'=>$subscriberId,
            'paymentInstrumentName'=>'Mobile Account',
            'amount'=>$amount,
            'currency'=>'LKR',
        ]);
    }

    public function verifyChargeOtp(string $referenceNo,string $otp,string $sourceAddress): array
    {
        if ($referenceNo===''||!preg_match('/^\d{4,8}$/',$otp)) throw new RuntimeException('Invalid mSpace OTP verification request.');
        self::requireSubscriber($sourceAddress);
        return $this->call('caas_otp_verify_url',['referenceNo'=>$referenceNo,'otp'=>$otp,'sourceAddress'=>$sourceAddress]);
    }

    public static function successful(array $response): bool { return (string)($response['statusCode']??'')==='S1000'; }
    public static function diagnostic(array $response): string
    {
        $code=(string)($response['statusCode']??'UNKNOWN'); $detail=trim((string)($response['statusDetail']??''));
        return $detail===''?'statusCode='.$code:'statusCode='.$code.' statusDetail='.substr($detail,0,240);
    }
    private static function requireSubscriber(string $subscriberId): void { if ($subscriberId===''||strlen($subscriberId)>255) throw new RuntimeException('Invalid subscriber identifier.'); }
    private static function requireCharge(string $externalTrxId,string $amount): void
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/',$externalTrxId)) throw new RuntimeException('Invalid external transaction identifier.');
        if (!preg_match('/^(?:0|[1-9]\d{0,7})\.\d{2}$/',$amount)||$amount==='0.00') throw new RuntimeException('Invalid charge amount.');
    }

    private function call(string $urlKey,array $payload): array
    {
        if (!$this->enabled()) throw new RuntimeException('mSpace is disabled or credentials are not configured.');
        if (!$this->caasLive) throw new RuntimeException('mSpace charging is disabled.');
        $url=trim((string)($this->config[$urlKey]??''));
        if ($url===''||!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https') throw new RuntimeException('mSpace HTTPS endpoint is not configured: '.$urlKey);
        $body=array_merge(['applicationId'=>(string)$this->config['application_id'],'password'=>(string)$this->config['password']],$payload);
        if (is_callable($this->transport)) {
            $response=($this->transport)($urlKey,$url,$body);
            if (!is_array($response)) throw new RuntimeException('mSpace transport returned an invalid response.');
            return $response;
        }
        if (!function_exists('curl_init')) throw new RuntimeException('The PHP cURL extension is required.');
        $handle=curl_init($url);
        if ($handle===false) throw new RuntimeException('Unable to initialize mSpace request.');
        $options=[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json;charset=utf-8','Accept: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2];
        if (defined('CURLOPT_PROTOCOLS')&&defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
        curl_setopt_array($handle,$options);
        $raw=curl_exec($handle); $http=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE); $failed=$raw===false||curl_errno($handle)!==0;
        if ($failed||$http<200||$http>=300) throw new RuntimeException('mSpace request failed.');
        if (strlen((string)$raw)>1048576) throw new RuntimeException('mSpace response was too large.');
        $response=json_decode((string)$raw,true);
        if (!is_array($response)||!isset($response['statusCode'])) throw new RuntimeException('mSpace returned an invalid response.');
        return $response;
    }
}
