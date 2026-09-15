<?php

use App\Wallet\Http\Controllers\AuthController;
use App\Wallet\Http\Controllers\BookController;
use App\Wallet\Http\Middleware\AuthenticateWallet;
use Illuminate\Support\Facades\Route;

/*
 * /api/v1/wallet — the super admin wallet (docs/phase-7-hr-attendance-bonus-wallet.md §8), loaded by bootstrap/app.php
 * behind OnlyOnWalletHost. Nothing in routes/api.php points here, and nothing here points there.
 */
Route::prefix('auth')->controller(AuthController::class)->group(function () {
    Route::post('password', 'password')->middleware('throttle:wallet-sign-in');
    Route::post('code', 'code')->middleware('throttle:wallet-sign-in');
    Route::post('sign-out', 'signOut');
    Route::get('me', 'me')->middleware(AuthenticateWallet::class);
});

Route::middleware([AuthenticateWallet::class, 'throttle:wallet'])->controller(BookController::class)->group(function () {
    Route::get('summary', 'summary');
    Route::get('transactions', 'history');
    Route::post('transactions', 'record');
    Route::post('transactions/{id}/reverse', 'reverse')->whereNumber('id');
    Route::get('transactions/{id}/evidence', 'evidence')->whereNumber('id');
    Route::get('deals', 'deals');
    Route::post('deals', 'createDeal');
    Route::post('deals/{id}/payments', 'recordPayment')->whereNumber('id');
    Route::get('sources', 'sources');
    Route::post('sources', 'addSource');
    Route::post('sources/{id}/archive', 'archiveSource')->whereNumber('id');
    Route::get('presets', 'presets');
    Route::post('presets', 'addPreset');
    // No PUT, PATCH or DELETE anywhere under /wallet (LedgerImmutabilityTest), even for a saved reference.
    Route::post('presets/{id}/remove', 'removePreset')->whereNumber('id');
});
