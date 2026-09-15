<?php

use App\Http\Middleware\AuthenticateAttendanceAgent;
use App\Http\Middleware\EnsurePortalAccess;
use App\Http\Middleware\EnsureStaffCanWork;
use App\Http\Middleware\SetRequestLocale;
use App\Wallet\Console\ResetWalletAuthenticator;
use App\Wallet\Http\Middleware\OnlyOnWalletHost;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The super admin wallet: its own routes, only on its own host (docs/phase-7-hr-attendance-bonus-wallet.md §8).
        then: function (): void {
            Route::prefix('api/v1/wallet')->middleware(['api', OnlyOnWalletHost::class])->group(__DIR__.'/../routes/wallet.php');
        },
    )
    ->withCommands([ResetWalletAuthenticator::class])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [SetRequestLocale::class]);
        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'staff.can-work' => EnsureStaffCanWork::class,
            'portal.access' => EnsurePortalAccess::class,
            'attendance.agent' => AuthenticateAttendanceAgent::class,
        ]);
        // Refresh tokens travel in a cookie that Laravel must not encrypt (it's an opaque random string, stored hashed).
        $middleware->encryptCookies(except: ['bh_staff_refresh', 'bh_customer_refresh', 'bh_wallet']);
        // An API: no redirect to a login page.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (AuthenticationException $e, Request $request) => response()->json(
            ['message' => __('auth.unauthenticated'), 'code' => 'unauthenticated'], 401,
        ));

        $exceptions->render(fn (UnauthorizedException|AccessDeniedHttpException $e, Request $request) => response()->json(
            ['message' => __('auth.forbidden'), 'code' => 'forbidden'], 403,
        ));
    })->create();
