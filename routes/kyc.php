<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use LBHurtado\FormHandlerKYC\Http\Controllers\KYCFlowController;

/*
|--------------------------------------------------------------------------
| KYC Handler Routes
|--------------------------------------------------------------------------
|
| Routes for KYC verification flow within form-flow system.
| These routes handle initiation, callback, and status polling.
|
*/

Route::prefix('form-flow/{flow_id}/kyc')->name('form-flow.kyc.')->group(function () {
    Route::post('/initiate', [KYCFlowController::class, 'initiate'])->name('initiate');
    Route::get('/status', [KYCFlowController::class, 'status'])->name('status');
});

// Global callback endpoint (no flow_id in path - uses transactionId from query)
Route::get('form-flow/kyc/callback', [KYCFlowController::class, 'callback'])->name('form-flow.kyc.callback');
