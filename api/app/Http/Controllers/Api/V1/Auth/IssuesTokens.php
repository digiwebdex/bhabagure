<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Services\Auth\RefreshTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

trait IssuesTokens
{
    /** Five failed attempts per minute per identifier + IP (docs/phase-1-schema.md §6). */
    private const MAX_ATTEMPTS = 5;

    abstract protected function guardName(): string;

    protected function guard(): JWTGuard
    {
        /** @var JWTGuard */
        return auth($this->guardName());
    }

    /**
     * @param  array<string, mixed>  $body  merged into the response (e.g. the signed-in profile)
     */
    protected function tokenResponse(JWTSubject $subject, Request $request, array $body, int $status = 200, ?string $refreshToken = null): JsonResponse
    {
        $refresh = app(RefreshTokens::class);
        $refreshToken ??= $refresh->issue($this->guardName(), (int) $subject->getJWTIdentifier(), $request);

        return response()
            ->json([
                'access_token' => $this->guard()->login($subject),
                'token_type' => 'Bearer',
                'expires_in' => $this->guard()->factory()->getTTL() * 60,
                ...$body,
            ], $status)
            ->withCookie($refresh->cookie($this->guardName(), $refreshToken));
    }

    protected function throttleKey(string $identifier, Request $request): string
    {
        return $this->guardName().'-login|'.mb_strtolower($identifier).'|'.$request->ip();
    }

    protected function ensureNotRateLimited(string $key): void
    {
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages(['identifier' => [__('auth.throttle', ['seconds' => $seconds])]])
                ->status(Response::HTTP_TOO_MANY_REQUESTS);
        }
    }

    protected function invalidCredentials(): JsonResponse
    {
        return response()->json(['message' => __('auth.failed'), 'code' => 'invalid_credentials'], Response::HTTP_UNAUTHORIZED);
    }

    /** Ends the session: revokes the refresh family and blacklists the access token if one was sent. */
    protected function endSession(Request $request): JsonResponse
    {
        app(RefreshTokens::class)->revoke($this->guardName(), $request->cookie(RefreshTokens::cookieName($this->guardName())));

        try {
            if ($this->guard()->parser()->setRequest($request)->hasToken()) {
                $this->guard()->invalidate();
            }
        } catch (Throwable) {
            // An expired or invalid access token needs no blacklisting.
        }

        return response()->json(null, Response::HTTP_NO_CONTENT)
            ->withCookie(app(RefreshTokens::class)->forgetCookie($this->guardName()));
    }

    protected function refreshFailed(): JsonResponse
    {
        return response()->json(['message' => __('auth.session_expired'), 'code' => 'session_expired'], Response::HTTP_UNAUTHORIZED)
            ->withCookie(app(RefreshTokens::class)->forgetCookie($this->guardName()));
    }
}
