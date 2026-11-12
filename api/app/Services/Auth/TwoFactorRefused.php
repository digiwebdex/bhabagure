<?php

namespace App\Services\Auth;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** Why a two-step sign-in action was refused; the message is lang/{locale}/auth.php two_factor.<reason>. */
final class TwoFactorRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $retryAfter = null)
    {
        parent::__construct($reason);
    }

    public function status(): int
    {
        return match ($this->reason) {
            'too_many_attempts' => Response::HTTP_TOO_MANY_REQUESTS,
            'super_admin_only' => Response::HTTP_FORBIDDEN,
            default => Response::HTTP_UNPROCESSABLE_ENTITY,
        };
    }

    public function message(): string
    {
        return __("auth.two_factor.{$this->reason}", ['seconds' => $this->retryAfter ?? 0]);
    }
}
