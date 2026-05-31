<?php

use App\Http\Controllers\Api\V1\ProxyCheckController;
use App\Http\Controllers\Api\V1\ProxyController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:60,1')->group(function (): void {
    Route::get('/health', fn () => response()->json(['status' => 'ok']));
    Route::post('/proxies/check', [ProxyController::class, 'checkAll'])->middleware('throttle:10,1');
    Route::apiResource('proxies', ProxyController::class);
    Route::post('/proxies/{proxy}/check', [ProxyController::class, 'check'])->middleware('throttle:10,1');
    Route::get('/proxies/{proxy}/checks', [ProxyCheckController::class, 'index']);
});
