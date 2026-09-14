<?php

namespace App\Services\Auth;

use App\Models\RefreshToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Opaque refresh tokens, one family per sign-in.
 *
 * Each use rotates the token. A token that was already rotated and is presented again means one of two things:
 *
 *  - the same browser refreshing twice in quick succession (a reload while a refresh is in flight, a double mount):
 *    within `auth.refresh.reuse_grace_seconds`, from the IP and user agent that performed the rotation, it gets the
 *    same successor token back and the session carries on;
 *  - someone else holding a copy: any other replay — later, or from another device or IP — revokes the whole
 *    family and both parties must sign in again.
 *
 * The successor is derived from the old token with an HMAC keyed by APP_KEY, so it can be handed out again without
 * storing any token in the clear; only its hash is stored, as for every token.
 */
final class RefreshTokens
{
    public function issue(string $guard, int $subjectId, Request $request, ?string $family = null, ?string $plain = null): string
    {
        $plain ??= Str::random(64);

        RefreshToken::query()->create([
            'guard' => $guard,
            'subject_id' => $subjectId,
            'token_hash' => $this->hash($plain),
            'family' => $family ?? (string) Str::uuid(),
            'expires_at' => now()->addDays((int) config('auth.refresh.ttl_days')),
            'created_ip' => $request->ip(),
            'user_agent' => $this->userAgent($request),
        ]);

        return $plain;
    }

    /**
     * @return array{subject_id: int, token: string}|null null when the token is unknown, expired or reused
     */
    public function rotate(string $guard, ?string $plain, Request $request): ?array
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        return DB::transaction(function () use ($guard, $plain, $request) {
            $current = RefreshToken::query()
                ->where('token_hash', $this->hash($plain))
                ->where('guard', $guard)
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                return null;
            }

            if ($current->rotated_at !== null) {
                $latest = $this->followWithinGrace($current, $plain, $request);
                if ($latest === null) {
                    $this->revokeFamily($current->family);
                }

                return $latest;
            }

            // Signed out, or revoked with the rest of its family (password change, earlier theft).
            if ($current->revoked_at !== null) {
                $this->revokeFamily($current->family);

                return null;
            }

            if ($current->expires_at->isPast()) {
                return null;
            }

            $next = $this->issue($guard, (int) $current->subject_id, $request, $current->family, $this->successorOf($plain));
            $successor = RefreshToken::query()->where('token_hash', $this->hash($next))->firstOrFail();
            $current->forceFill(['revoked_at' => now(), 'rotated_at' => now(), 'replaced_by_id' => $successor->id])->save();

            return ['subject_id' => (int) $current->subject_id, 'token' => $next];
        });
    }

    /**
     * The token that replaced $rotated (following several quick rotations if needed), when this is the same browser
     * asking again within the grace window and that successor is still live; otherwise null.
     *
     * @return array{subject_id: int, token: string}|null
     */
    private function followWithinGrace(RefreshToken $rotated, string $plain, Request $request): ?array
    {
        $graceStart = now()->subSeconds((int) config('auth.refresh.reuse_grace_seconds'));
        $token = $rotated;

        while ($token->rotated_at !== null) {
            if ($token->rotated_at->lt($graceStart) || $token->replaced_by_id === null) {
                return null;
            }

            $successor = RefreshToken::query()->whereKey($token->replaced_by_id)->lockForUpdate()->first();
            $plain = $this->successorOf($plain);
            $sameBrowser = $successor !== null
                && hash_equals($successor->token_hash, $this->hash($plain))
                && $successor->created_ip === $request->ip()
                && $successor->user_agent === $this->userAgent($request);
            if (! $sameBrowser) {
                return null;
            }

            $token = $successor;
        }

        if ($token->revoked_at !== null || $token->expires_at->isPast()) {
            return null;
        }

        return ['subject_id' => (int) $token->subject_id, 'token' => $plain];
    }

    /** The next token in a rotation, derived from the current one. Unguessable without APP_KEY. */
    private function successorOf(string $plain): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', "refresh-successor|{$plain}", (string) config('app.key'), true)), '+/', '-_'), '=');
    }

    private function userAgent(Request $request): string
    {
        return Str::limit((string) $request->userAgent(), 250, '');
    }

    public function revoke(string $guard, ?string $plain): void
    {
        if ($plain === null || $plain === '') {
            return;
        }

        $token = RefreshToken::query()->where('token_hash', $this->hash($plain))->where('guard', $guard)->first();
        if ($token !== null) {
            $this->revokeFamily($token->family);
        }
    }

    /** Signs a subject out everywhere, e.g. after a password change. */
    public function revokeAllFor(string $guard, int $subjectId): void
    {
        RefreshToken::query()
            ->where('guard', $guard)
            ->where('subject_id', $subjectId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function cookie(string $guard, string $plain): Cookie
    {
        return Cookie::create(
            name: self::cookieName($guard),
            value: $plain,
            expire: now()->addDays((int) config('auth.refresh.ttl_days')),
            path: self::cookiePath($guard),
            domain: config('auth.refresh.cookie_domain'),
            secure: (bool) config('auth.refresh.cookie_secure'),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_STRICT,
        );
    }

    public function forgetCookie(string $guard): Cookie
    {
        return Cookie::create(self::cookieName($guard), '', 1, self::cookiePath($guard), config('auth.refresh.cookie_domain'),
            (bool) config('auth.refresh.cookie_secure'), true, false, Cookie::SAMESITE_STRICT);
    }

    public static function cookieName(string $guard): string
    {
        return "bh_{$guard}_refresh";
    }

    /** The cookie is sent only to that guard's auth endpoints, never with ordinary API calls. */
    private static function cookiePath(string $guard): string
    {
        return "/api/v1/{$guard}/auth";
    }

    private function revokeFamily(string $family): void
    {
        RefreshToken::query()->where('family', $family)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
