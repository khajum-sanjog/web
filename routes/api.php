<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\AuthorizeController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\PaymentGatewayController;
use App\Http\Controllers\StripeTerminalController;
use App\Http\Middleware\AuthenticateWithTempToken;
use App\Http\Controllers\AuthorizeWebhookController;
use App\Http\Controllers\StripeController;
use App\Http\Controllers\UserPaymentGatewayController;

Route::group([
    'middleware' => 'api',
], function () {
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login');
});

// Authenticated Routes (Requires API Token)
Route::group([
    'middleware' => 'auth:api',
], function () {
    Route::get('/payment-gateways', [PaymentGatewayController::class, 'index']);
    Route::get('/user/payment-gateways', [PaymentGatewayController::class, 'getUserPaymentGatewayDetails']);
    Route::post('/user-payment-gateways', [UserPaymentGatewayController::class, 'store'])->name('userPaymentGateway.store');
    Route::post('/refresh', [AuthController::class, 'refresh'])->name('refresh');

    // Payment routes
    Route::prefix('payments')->group(function () {
        Route::post('/process', [PaymentController::class, 'processPayment']);
        Route::post('/refund', [PaymentController::class, 'processRefund']);
        Route::post('/void', [PaymentController::class, 'processVoid']);
        Route::post('/transactionDetails', [PaymentController::class, 'getTransactionDetails']);
        Route::post('/template', [PaymentController::class, 'loadPaymentUI']);
    });

    // Apple Pay merchant validation
    Route::post('/validate-merchant', [AuthorizeController::class, 'validateMerchant']);
    Route::post('/validate-merchant', [StripeController::class, 'validateMerchant']);
    // Route::post('/create-apple-pay-session', [StripeController::class, 'createSession']);

    // Route::post('/webhook/create/{userId}', [StripeWebhookController::class, 'createUserWebhook']);
    // Route::post('/webhook/create/{userId}', [AuthorizeWebhookController::class, 'createUserWebhook']);

});

Route::prefix('terminal')->group(function () {
    Route::get('/connection-token', [StripeTerminalController::class, 'createConnectionToken'])
        ->middleware([AuthenticateWithTempToken::class]);
    Route::post('/create-payment-intent', [StripeTerminalController::class, 'createPaymentIntent'])
        ->middleware([AuthenticateWithTempToken::class]);
});

Route::post('/webhook/stripe/user/{userId}', [StripeWebhookController::class, 'handleWebhook'])->name('webhook.stripe');
Route::post('/webhook/authorize/user/{userId}', [AuthorizeWebhookController::class, 'handleWebhook'])->name('webhook.authorize');

Route::post('/webhook/authorize', function() {
    return response()->json(['status' => 'ok'], 200);
});
