<?php
require __DIR__.'/app/bootstrap.php';
use Divigamana\Controllers\ApiController;
$action=$_GET['action']??'';
match($action){'rahu'=>ApiController::rahu(),'auth'=>ApiController::auth(),'verify'=>ApiController::verify(),'status'=>ApiController::status(),'generate'=>ApiController::generate(),default=>\Divigamana\Support\Http::json(['ok'=>false,'error'=>'Not found'],404)};
