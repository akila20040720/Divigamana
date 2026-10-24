<?php
// CLI-only Qwen check. This cannot be accessed as a browser endpoint.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';

use Divigamana\Services\QwenProvider;
use Divigamana\Services\ServiceCatalog;

$requestedLocale=$argv[1]??'en-US';
$locale=in_array($requestedLocale,['si-LK','en-US','ta-LK'],true)?$requestedLocale:'en-US';
$service=ServiceCatalog::find('name-reading');
if (!$service) { fwrite(STDERR,"Name-reading service was not found.\n"); exit(1); }

try {
    $reading=(new QwenProvider())->generate($service,[
        'full_name'=>'Local Qwen Test',
        'focus'=>'general',
    ],$locale,['reading_reference'=>'manual-cli-'.date('YmdHis')]);
    $result=$reading['result'];
    echo "Qwen test succeeded\n";
    echo "Model: ".$reading['model']."\n";
    echo "Title: ".$result['title']."\n";
    echo "Sections: ".count($result['sections']??[])."\n";
    echo "Tokens: ".$reading['tokens']."\n";
} catch (Throwable $error) {
    fwrite(STDERR,"Qwen test failed: ".$error->getMessage()."\n");
    exit(1);
}
