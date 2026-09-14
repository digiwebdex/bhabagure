<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A CMS item can't be (or stay) published: the editor gets the list of what is missing. Rendered as 422. */
class NotReadyToPublish extends RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('Not ready to publish');
    }

    /** @param list<string> $problems */
    public static function throwIfAny(array $problems): void
    {
        if ($problems !== []) {
            throw new self($problems);
        }
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => __('cms.not_ready_to_publish'),
            'code' => 'not_ready_to_publish',
            'problems' => $this->problems,
        ], 422);
    }
}
