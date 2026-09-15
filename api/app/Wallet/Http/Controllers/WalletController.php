<?php

namespace App\Wallet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Wallet\Http\Middleware\AuthenticateWallet;
use App\Wallet\Services\WalletRefused;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Shared by the wallet's controllers: who is signed in, and refusals as JSON. */
abstract class WalletController extends Controller
{
    protected function staff(Request $request): Staff
    {
        return $request->attributes->get(AuthenticateWallet::STAFF);
    }

    /** @param Closure(): JsonResponse $action */
    protected function attempt(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (WalletRefused $e) {
            return response()->json(['message' => __("wallet.{$e->reason}"), 'code' => $e->reason], $e->status);
        }
    }
}
