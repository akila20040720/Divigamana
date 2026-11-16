<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR,"pdo_sqlite is required for paid retry tests.\n"); exit(1); }
$cases=[
    'paid'=>[200,'cached'],
    'unpaid'=>[402,'error'],
    'wrong-amount'=>[402,'error'],
    'other-service'=>[409,'error'],
    'provider-failure'=>[503,'error'],
];
$failed=0;
foreach ($cases as $case=>[$status,$field]) {
    $process=proc_open([PHP_BINARY,__DIR__.'/paid_retry_case.php',$case],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    if (!is_resource($process)) { $failed++; continue; }
    $output=stream_get_contents($pipes[1]); $diagnostics=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
    preg_match('/__HTTP_STATUS__=(\d+)/',$diagnostics,$match);
    $body=json_decode($output,true);
    $ok=$exit===0&&(int)($match[1]??0)===$status&&is_array($body)&&isset($body[$field]);
    if ($case==='provider-failure') $ok=$ok&&str_contains($diagnostics,'Qwen API key is not configured');
    echo ($ok?'PASS ':'FAIL ').$case."\n";
    if (!$ok) $failed++;
}
echo count($cases)-$failed.' passed, '.$failed." failed\n";
exit($failed?1:0);
