<?php
declare(strict_types=1);

// Exercise each reading format without sending personal data or making paid API calls.
namespace Divigamana\Services {
    function curl_init(string $url): object { $GLOBALS['qwen_test_url']=$url; return new \stdClass(); }
    function curl_setopt_array(object $handle,array $options): bool { $GLOBALS['qwen_test_request']=json_decode($options[CURLOPT_POSTFIELDS],true); return true; }
    function curl_exec(object $handle): string {
        if (isset($GLOBALS['qwen_test_error_response'])) return $GLOBALS['qwen_test_error_response'];
        $content=$GLOBALS['qwen_test_response'];
        return json_encode(['choices'=>[['finish_reason'=>'stop','message'=>['content'=>json_encode($content)]]],'usage'=>['total_tokens'=>100]]);
    }
    function curl_getinfo(object $handle,int $option): mixed { return match ($option) { CURLINFO_HTTP_CODE=>($GLOBALS['qwen_test_status']??200), CURLINFO_TOTAL_TIME=>0.01, CURLINFO_PRIMARY_IP=>'127.0.0.1', default=>null }; }
    function curl_error(object $handle): string { return ''; }
}
namespace {
    if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
    define('APP_CONFIG',['qwen'=>['api_key'=>'test-key','base_url'=>'https://token-plan.ap-southeast-1.maas.aliyuncs.com','model'=>'qwen4-max-preview','timeout_seconds'=>45,'max_output_tokens'=>2200,'temperature'=>0.65,'mock_mode'=>false]]);
    require dirname(__DIR__).'/app/Services/ServiceCatalog.php';
    require dirname(__DIR__).'/app/Services/QwenProvider.php';
    $common=['title'=>'Reading','disclaimer'=>'For reflection','guidance'=>'Consider a small step.'];
    $sections=[['heading'=>'One','body'=>'Reflection one.'],['heading'=>'Two','body'=>'Reflection two.'],['heading'=>'Three','body'=>'Reflection three.']];
    $fixtures=[
        'default'=>$common+['sections'=>$sections],
        'baby-names'=>$common+['names'=>[['name'=>'A','meaning'=>'First','pronunciation'=>'ay'],['name'=>'B','meaning'=>'Second','pronunciation'=>'bee'],['name'=>'C','meaning'=>'Third','pronunciation'=>'see']]],
        'porondam'=>$common+['sections'=>$sections,'compatibility_summary'=>'Reflect together.'],
        'daily'=>$common+['intention'=>'Take time.','reflection'=>'Reflect.','caution'=>'Avoid haste.'],
        'monthly'=>$common+['early_month'=>['heading'=>'Early','body'=>'Plan.'],'mid_month'=>['heading'=>'Middle','body'=>'Review.'],'late_month'=>['heading'=>'Late','body'=>'Reflect.']],
        'yearly'=>$common+['year_theme'=>'Growth','quarters'=>[['label'=>'Q1','body'=>'Plan.'],['label'=>'Q2','body'=>'Review.'],['label'=>'Q3','body'=>'Adjust.'],['label'=>'Q4','body'=>'Reflect.']]],
    ];
    $passed=0; $failed=0;
    foreach (\Divigamana\Services\ServiceCatalog::all() as $service) {
        $slug=$service['slug'];
        foreach (['si-LK','en-US','ta-LK'] as $locale) {
            $answers=[];
            foreach ($service['fields'] as $field) {
                $answers[$field['name']]=match($field['type']) {
                    'date'=>'2000-01-01','month'=>'2026-11','time'=>'10:00','number'=>$field['name']==='year'?'2026':'3','checkbox'=>'yes',
                    'select'=>$field['options'][0]['value']??'',default=>'Example visitor detail'
                };
            }
            $answers['unexpected_private_field']='SHOULD_NOT_BE_SENT';
            $GLOBALS['qwen_test_response']=$fixtures[$slug]??$fixtures['default'];
            try {
                $result=(new \Divigamana\Services\QwenProvider())->generate($service,$answers,$locale,['reading_reference'=>'test-reference']);
                $request=$GLOBALS['qwen_test_request']??[];
                $body=$result['result']??[];
                $valid=($request['model']??'')==='qwen4-max-preview'&&($request['enable_thinking']??null)===false&&($request['response_format']['type']??'')==='json_object'
                    &&$GLOBALS['qwen_test_url']==='https://token-plan.ap-southeast-1.maas.aliyuncs.com/compatible-mode/v1/chat/completions'
                    &&($body['title']??'')!==''&&($body['disclaimer']??'')!==''&&($body['guidance']??'')!==''
                    &&($result['tokens']??0)===100
                    &&str_contains($request['messages'][1]['content']??'', 'Example visitor detail')
                    &&!str_contains($request['messages'][1]['content']??'', 'SHOULD_NOT_BE_SENT');
                $valid=$valid&&match($slug) {
                    'baby-names'=>count($body['names']??[])===3,
                    'porondam'=>count($body['sections']??[])===3&&!empty($body['compatibility_summary']),
                    'daily'=>!empty($body['intention'])&&!empty($body['reflection'])&&!empty($body['caution']),
                    'monthly'=>!empty($body['early_month']['body'])&&!empty($body['mid_month']['body'])&&!empty($body['late_month']['body']),
                    'yearly'=>!empty($body['year_theme'])&&count($body['quarters']??[])===4,
                    default=>count($body['sections']??[])>=2,
                };
                if (!$valid) throw new \RuntimeException('Unexpected request or result shape.');
                echo "PASS  $slug $locale\n"; $passed++;
            } catch (\Throwable $error) {
                echo "FAIL  $slug $locale: ".$error->getMessage()."\n"; $failed++;
            }
        }
    }
    $fallback=new \ReflectionMethod(\Divigamana\Services\QwenProvider::class,'fallbackBaseUrl');
    $provider=new \Divigamana\Services\QwenProvider();
    $tokenFallback=$fallback->invoke($provider,'https://token-plan.ap-southeast-1.maas.aliyuncs.com/compatible-mode/v1',[]);
    $workspaceFallback=$fallback->invoke($provider,'https://ws-example.ap-southeast-1.maas.aliyuncs.com/compatible-mode/v1',[]);
    $valid=$tokenFallback===''&&$workspaceFallback==='https://dashscope-intl.aliyuncs.com/compatible-mode/v1';
    echo ($valid?'PASS':'FAIL')."  Token Plan never automatically falls back to the pay-as-you-go endpoint\n";
    $valid?$passed++:$failed++;
    // Invalid provider data must be rejected before it can be shown to visitors.
    foreach (['name-reading','baby-names','porondam','daily','monthly','yearly'] as $slug) {
        $service=\Divigamana\Services\ServiceCatalog::find($slug);
        $fixture=$fixtures[$slug]??$fixtures['default'];
        switch ($slug) {
            case 'baby-names': array_pop($fixture['names']); break;
            case 'porondam': unset($fixture['compatibility_summary']); break;
            case 'daily': unset($fixture['caution']); break;
            case 'monthly': unset($fixture['mid_month']); break;
            case 'yearly': array_pop($fixture['quarters']); break;
            default: $fixture['sections']=[];
        }
        $GLOBALS['qwen_test_response']=$fixture;
        try {
            (new \Divigamana\Services\QwenProvider())->generate($service,['count'=>'3'],'en-US',['reading_reference'=>'test-reference']);
            echo "FAIL  $slug rejects incomplete output\n"; $failed++;
        } catch (\RuntimeException $error) {
            echo "PASS  $slug rejects incomplete output\n"; $passed++;
        }
    }
    $oldErrorLog=ini_get('error_log');
    $testErrorLog=tempnam(sys_get_temp_dir(),'qwen-error-test-');
    ini_set('error_log',$testErrorLog);
    foreach (['Arrearage'=>'billing is in arrears','AccessDenied.Unpurchased'=>'model access is not enabled','UnknownError'=>'UnknownError'] as $code=>$expected) {
        $GLOBALS['qwen_test_status']=400;
        $GLOBALS['qwen_test_error_response']=json_encode(['error'=>['code'=>$code,'message'=>'sensitive-provider-message']]);
        try {
            (new \Divigamana\Services\QwenProvider())->generate(\Divigamana\Services\ServiceCatalog::find('name-reading'),['full_name'=>'Test'],'en-US');
            echo "FAIL  $code is rejected safely\n"; $failed++;
        } catch (\RuntimeException $error) {
            $message=$error->getMessage();
            $valid=str_contains($message,$expected)&&!str_contains($message,'sensitive-provider-message');
            echo ($valid?'PASS':'FAIL')."  $code is rejected safely\n"; $valid?$passed++:$failed++;
        }
    }
    $valid=!str_contains((string)file_get_contents($testErrorLog),'sensitive-provider-message');
    echo ($valid?'PASS':'FAIL')."  provider response message is omitted from logs\n"; $valid?$passed++:$failed++;
    ini_set('error_log',(string)$oldErrorLog);
    unlink($testErrorLog);
    echo "\n$passed passed, $failed failed (mocked Qwen transport)\n";
    exit($failed===0?0:1);
}
