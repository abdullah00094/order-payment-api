<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:api')->group(function () {
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show'])->whereNumber('id');
    Route::patch('/orders/{id}', [OrderController::class, 'update'])->whereNumber('id');
    Route::delete('/orders/{id}', [OrderController::class, 'destroy'])->whereNumber('id');
    Route::post('/orders/{id}/payments', [PaymentController::class, 'store'])->whereNumber('id');
    Route::get('/orders/{id}/payments', [PaymentController::class, 'orderPayments'])->whereNumber('id');
    Route::get('/payments', [PaymentController::class, 'index']);
});
