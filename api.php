<?php
require __DIR__.'/app/bootstrap.php';
use Divigamana\Support\ApiRoutes;
use Divigamana\Support\Http;

$action=$_GET['action']??'';
$route=ApiRoutes::all()[$action]??null;
if (!$route) Http::json(['ok'=>false,'error'=>'Not found'],404);
Http::requireMethod($route['method']);
call_user_func($route['handler']);
