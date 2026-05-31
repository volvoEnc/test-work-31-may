<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:60,1')->group(function (): void {
    Route::get('/health', fn () => response()->json(['status' => 'ok']));
});
