<?php
require __DIR__.'/app/bootstrap.php';
use Divigamana\Support\ApiRoutes;
use Divigamana\Support\Http;

$action=$_GET['action']??'';
$route=ApiRoutes::all()[$action]??null;
if (!$route) Http::json(['ok'=>false,'error'=>'Not found'],404);
Http::requireMethod($route['method']);
try {
    call_user_func($route['handler']);
} catch (Throwable $error) {
    error_log('Unhandled API exception action='.$action.' type='.get_class($error).' message='.$error->getMessage().' at '.$error->getFile().':'.$error->getLine());
    Http::json(['ok'=>false,'error'=>'The request could not be completed. Please try again.'],500);
}
