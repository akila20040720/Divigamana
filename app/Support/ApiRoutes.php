<?php
namespace Divigamana\Support;

use Divigamana\Controllers\ApiController;

final class ApiRoutes
{
    /** @return array<string,array{method:string,handler:callable}> */
    public static function all(): array
    {
        return [
            'rahu' => ['method'=>'GET', 'handler'=>[ApiController::class,'rahu']],
            'status' => ['method'=>'GET', 'handler'=>[ApiController::class,'status']],
            'auth' => ['method'=>'POST', 'handler'=>[ApiController::class,'auth']],
            'verify' => ['method'=>'POST', 'handler'=>[ApiController::class,'verify']],
            'subscription' => ['method'=>'POST', 'handler'=>[ApiController::class,'subscribe']],
            'unsubscribe' => ['method'=>'POST', 'handler'=>[ApiController::class,'unsubscribe']],
            'subscription-status' => ['method'=>'POST', 'handler'=>[ApiController::class,'ideamartSubscriptionStatus']],
            'subscription-base' => ['method'=>'POST', 'handler'=>[ApiController::class,'subscriptionBase']],
            'caas-balance' => ['method'=>'POST', 'handler'=>[ApiController::class,'caasBalance']],
            'caas-charge' => ['method'=>'POST', 'handler'=>[ApiController::class,'caasCharge']],
            'mspace-charge-otp' => ['method'=>'POST', 'handler'=>[ApiController::class,'mspaceChargeOtp']],
            'mspace-charge-verify' => ['method'=>'POST', 'handler'=>[ApiController::class,'mspaceChargeVerify']],
            'generate' => ['method'=>'POST', 'handler'=>[ApiController::class,'generate']],
        ];
    }
}
