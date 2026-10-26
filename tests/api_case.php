<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }

$action=(string)($argv[1]??'');
$method=strtoupper((string)($argv[2]??'GET'));
$_GET=['action'=>$action];
$_POST=[];
$_SERVER['REQUEST_METHOD']=$method;
$_SERVER['REMOTE_ADDR']='127.0.0.1';
if (($argv[3]??'')==='csrf') {
    session_name('DIVIGAMANA_SESSION');
    session_start();
    $_SESSION['_csrf']='contract-test-csrf';
    $_POST['csrf']='contract-test-csrf';
}
http_response_code(200);
register_shutdown_function(static function (): void {
    fwrite(STDERR,"__HTTP_STATUS__=".http_response_code()."\n");
});
require dirname(__DIR__).'/api.php';
