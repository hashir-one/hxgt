<?php

use App\Http\Controllers\Api\EmailSignalController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Device\DeviceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/orders', [OrderController::class, 'store'])->middleware('merchant.api:orders:write');
    Route::get('/orders/{id}', [OrderController::class, 'show'])->middleware('merchant.api:orders:read');
    Route::get('/events', [EventController::class, 'index'])->middleware('merchant.api:events:read');
    Route::post('/events/ack', [EventController::class, 'acknowledge'])->middleware('merchant.api:events:ack');
});

Route::prefix('device/v1')->middleware('device.signature')->group(function (): void {
    Route::post('/heartbeat', [DeviceController::class, 'heartbeat']);
    Route::post('/signals', [DeviceController::class, 'signals']);
    Route::post('/faults', [DeviceController::class, 'faults'])->middleware('throttle:30,1');
    Route::get('/parser-rules', [DeviceController::class, 'parserRules']);
});

Route::post('/device/v1/pair', [DeviceController::class, 'pair'])->middleware('throttle:10,1');

Route::post('/email/v1/signals', [EmailSignalController::class, 'store'])->middleware('throttle:120,1');
