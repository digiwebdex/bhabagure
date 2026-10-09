<?php

use App\Wallet\Http\Controllers\AuthController;
use App\Wallet\Http\Controllers\BookController;
use App\Wallet\Http\Controllers\DealController;
use App\Wallet\Http\Controllers\MemberController;
use App\Wallet\Http\Middleware\AuthenticateWallet;
use Illuminate\Support\Facades\Route;

/*
 * /api/v1/wallet — the super admin wallet (docs/phase-7-hr-attendance-bonus-wallet.md §8), loaded by bootstrap/app.php
 * behind OnlyOnWalletHost. Nothing in routes/api.php points here, and nothing here points there.
 */
Route::prefix('auth')->controller(AuthController::class)->group(function () {
    Route::post('password', 'password')->middleware('throttle:wallet-sign-in');
    Route::post('sign-out', 'signOut');
    Route::get('me', 'me')->middleware(AuthenticateWallet::class);
});

// No PUT, PATCH or DELETE anywhere under /wallet (LedgerImmutabilityTest): Edit and Delete (docs/wallet-edits.md) are
// POSTs, and neither changes a ledger row — they add a reversal, and an edit the corrected entry.
Route::middleware([AuthenticateWallet::class, 'throttle:wallet'])->controller(BookController::class)->group(function () {
    Route::get('summary', 'summary');
    Route::get('transactions', 'history');
    Route::post('transactions', 'record');
    Route::get('transactions/{id}', 'entry')->whereNumber('id');
    Route::post('transactions/{id}/edit', 'edit')->whereNumber('id');
    Route::post('transactions/{id}/delete', 'delete')->whereNumber('id');
    Route::get('transactions/{id}/evidence', 'evidence')->whereNumber('id');
    Route::get('sources', 'sources');
    Route::post('sources', 'addSource');
    Route::post('sources/{id}/rename', 'renameSource')->whereNumber('id');
    Route::post('sources/{id}/archive', 'archiveSource')->whereNumber('id');
    Route::get('presets', 'presets');
    Route::post('presets', 'addPreset');
    Route::post('presets/{id}/edit', 'editPreset')->whereNumber('id');
    Route::post('presets/{id}/remove', 'removePreset')->whereNumber('id');
});

Route::middleware([AuthenticateWallet::class, 'throttle:wallet'])->prefix('deals')->controller(DealController::class)->group(function () {
    Route::get('/', 'index');
    Route::post('/', 'create');
    Route::get('{id}', 'show')->whereNumber('id');
    Route::post('{id}/edit', 'edit')->whereNumber('id');
    Route::post('{id}/delete', 'delete')->whereNumber('id');
    Route::post('{id}/payments', 'pay')->whereNumber('id');
});

// Access (docs/personal-wallets.md): who has a personal wallet. The super admin only, checked in the controller.
Route::middleware([AuthenticateWallet::class, 'throttle:wallet'])->prefix('members')->controller(MemberController::class)->group(function () {
    Route::get('/', 'index');
    Route::post('/', 'grant');
    Route::post('{staffId}/revoke', 'revoke')->whereNumber('staffId');
});
