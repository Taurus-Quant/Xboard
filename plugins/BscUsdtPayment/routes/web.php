<?php

use Illuminate\Support\Facades\Route;
use Plugin\BscUsdtPayment\Controllers\BscUsdtController;

// 用户 API 路由
Route::prefix('api/v1/user/bsc-usdt')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::get('/wallet-address', [BscUsdtController::class, 'getWalletAddress']);
    Route::get('/status/{trade_no}', [BscUsdtController::class, 'checkPaymentStatus']);
    Route::post('/auto-check', [BscUsdtController::class, 'autoCheckPayments']);
});
