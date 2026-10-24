<?php
require __DIR__.'/app/bootstrap.php';
use Divigamana\Controllers\ApiController;

$action=$_GET['action']??'';
switch ($action) {
    case 'rahu': ApiController::rahu(); break;
    case 'auth': ApiController::auth(); break;
    case 'verify': ApiController::verify(); break;
    case 'status': ApiController::status(); break;
    case 'generate': ApiController::generate(); break;
    default: \Divigamana\Support\Http::json(['ok'=>false,'error'=>'Not found'],404);
}
