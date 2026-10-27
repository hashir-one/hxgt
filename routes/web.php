<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminTestLabController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\Device\PairingRecoveryController;
use App\Http\Controllers\LivePaymentTestController;
use App\Http\Controllers\MerchantController;
use App\Http\Controllers\MerchantPreviewController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\SecurityController;
use App\Http\Middleware\MerchantPanelAccess;
use App\Http\Middleware\RequireSecondFactor;
use App\Http\Middleware\SandboxOnly;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login/request', [AuthController::class, 'requestCode'])->name('login.request')->middleware('throttle:5,1');
Route::post('/login/verify', [AuthController::class, 'verifyCode'])->name('login.verify')->middleware('throttle:10,1');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/checkout/{token}', [CheckoutController::class, 'show'])->name('checkout.show');
Route::get('/checkout/{token}/events', [CheckoutController::class, 'events'])->name('checkout.events')->middleware('throttle:20,1');
Route::get('/checkout/{token}/logo', [CheckoutController::class, 'logo'])->name('checkout.logo');
Route::get('/checkout/{token}/status', [CheckoutController::class, 'status'])->name('checkout.status')->middleware('throttle:60,1');
Route::post('/checkout/{token}/claims', [CheckoutController::class, 'claim'])->name('checkout.claim')->middleware('throttle:10,1');

Route::middleware(['auth', RequireSecondFactor::class, MerchantPanelAccess::class])->prefix('panel')->name('panel.')->group(function (): void {
    Route::get('/preview', [MerchantPreviewController::class, 'merchant'])->name('preview');
    Route::post('/preview/live-payment', [LivePaymentTestController::class, 'merchant'])->middleware('throttle:5,1')->name('preview.live');
    Route::post('/preview/transfer-qr', [LivePaymentTestController::class, 'merchantTransfer'])->middleware('throttle:10,1')->name('preview.transfer');
    Route::get('/reports', [OperationsController::class, 'reports'])->name('reports');
    Route::post('/reports/import', [OperationsController::class, 'import'])->name('reports.import');
    Route::get('/security', [OperationsController::class, 'team'])->name('security');
    Route::post('/staff', [OperationsController::class, 'addStaff'])->name('staff.create');
    Route::delete('/staff/{user}', [OperationsController::class, 'disableStaff'])->name('staff.disable');
    Route::post('/deliveries/{delivery}/resend', [OperationsController::class, 'resend'])->middleware('throttle:10,1')->name('deliveries.resend');
    Route::post('/payments/{order}/refunds', [OperationsController::class, 'refund'])->name('refunds.record');
    Route::get('/', [PanelController::class, 'dashboard'])->name('dashboard');
    Route::get('/payments', [PanelController::class, 'payments'])->name('payments');
    Route::get('/payments/{order}/detail', [PanelController::class, 'payment'])->name('payments.show');
    Route::get('/settings', [PanelController::class, 'settings'])->name('settings');
    Route::put('/settings', [PanelController::class, 'updateSettings'])->name('settings.update');
    Route::get('/payments/export', [PanelController::class, 'exportPayments'])->name('payments.export');
    Route::get('/devices', [PanelController::class, 'devices'])->name('devices');
    Route::get('/devices/pairings/{pairing}/status', [PanelController::class, 'pairingStatus'])->middleware('throttle:30,1')->name('devices.pairing-status');
    Route::post('/devices/otp', [PanelController::class, 'pairingOtp'])->middleware('throttle:3,5')->name('devices.otp');
    Route::post('/devices/recovery/otp', [PairingRecoveryController::class, 'otp'])->middleware('throttle:3,5')->name('devices.recovery-otp');
    Route::post('/devices/recovery/challenges', [PairingRecoveryController::class, 'issue'])->middleware('throttle:5,1')->name('devices.recovery-challenges');
    Route::post('/devices/approval', [PanelController::class, 'requestDeviceApproval'])->name('devices.approval');
    Route::post('/devices/pair', [PanelController::class, 'createPairing'])->name('devices.pair');
    Route::delete('/devices/{device}', [PanelController::class, 'revokeDevice'])->name('devices.revoke');
    Route::get('/developer', [PanelController::class, 'developer'])->name('developer');
    Route::post('/developer/keys', [PanelController::class, 'issueKey'])->name('keys.issue');
    Route::delete('/developer/keys/{credential}', [PanelController::class, 'revokeKey'])->name('keys.revoke');
    Route::post('/developer/webhook', [PanelController::class, 'updateWebhook'])->name('webhook.update');
    Route::get('/disputes', [PanelController::class, 'disputes'])->name('disputes');
    Route::post('/disputes/otp', [PanelController::class, 'requestOtp'])->name('disputes.otp');
    Route::post('/disputes/{dispute}/reject', [PanelController::class, 'rejectDispute'])->name('disputes.reject');
    Route::post('/disputes/{dispute}/confirm', [PanelController::class, 'confirmDispute'])->name('disputes.confirm');
});

Route::middleware(['auth', 'admin', RequireSecondFactor::class])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/preview', [MerchantPreviewController::class, 'demo'])->name('preview');
    Route::get('/merchants/{merchant}/preview', [MerchantPreviewController::class, 'admin'])->name('merchants.preview');
    Route::post('/merchants/{merchant}/preview/live-payment', [LivePaymentTestController::class, 'admin'])->middleware('throttle:5,1')->name('merchants.preview.live');
    Route::post('/merchants/{merchant}/preview/transfer-qr', [LivePaymentTestController::class, 'adminTransfer'])->middleware('throttle:10,1')->name('merchants.preview.transfer');
    Route::get('/operations', [OperationsController::class, 'admin'])->name('operations');
    Route::post('/approvals/{approval}/vote', [OperationsController::class, 'vote'])->name('approvals.vote');
    Route::post('/parser-rules', [OperationsController::class, 'parser'])->name('parser-rules.store');
    Route::post('/alerts/{alert}/resolve', [OperationsController::class, 'resolveAlert'])->name('alerts.resolve');
    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::resource('merchants', MerchantController::class)->except('destroy');
    if (app()->environment('local', 'testing')) {
        Route::get('/test-lab', [AdminTestLabController::class, 'index'])->name('test-lab')->middleware(SandboxOnly::class);
        Route::post('/test-lab/orders', [AdminTestLabController::class, 'create'])->name('test-lab.create')->middleware(SandboxOnly::class);
        Route::post('/test-lab/orders/{order}/signals', [AdminTestLabController::class, 'signal'])->name('test-lab.signal')->middleware(SandboxOnly::class);
        Route::post('/test-lab/orders/{order}/simulate', [AdminTestLabController::class, 'simulate'])->name('test-lab.simulate')->middleware(SandboxOnly::class);
    }
    Route::post('/devices/{device}/approve', [AdminController::class, 'approveDevice'])->name('devices.approve');
    Route::post('/pairings/{pairing}/approve', [AdminController::class, 'approvePairing'])->name('pairings.approve');
    Route::post('/merchants/{merchant}/suspend', [AdminController::class, 'suspend'])->name('merchants.suspend');
    Route::post('/merchants/{merchant}/activate', [AdminController::class, 'activate'])->name('merchants.activate');
    Route::post('/parser-rules/{rule}/activate', [AdminController::class, 'activateParserRule'])->name('parser-rules.activate');
    Route::post('/breakers/{breaker}/reopen', [AdminController::class, 'reopenBreaker'])->name('breakers.reopen');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/security/totp', [SecurityController::class, 'show'])->name('security.totp');
    Route::post('/security/totp', [SecurityController::class, 'verify'])->middleware('throttle:5,1')->name('security.totp.verify');
});
