<?php

namespace App\Http\Controllers\Api\V1\Admin\Concerns;

use App\Services\Hr\HrRefused;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** An HrRefused as the API answers it: 403 for rules about who may act, 422 on a field, 409 for the record's state. */
trait AnswersHrRefusals
{
    private const HR_FORBIDDEN = ['super_admin_only', 'own_role', 'own_account', 'email_locked'];

    private const HR_FIELDS = [
        'nid_on_another_record' => 'nid_number',
        'unknown_role' => 'role',
        'role_name_taken' => 'name_en',
        'unknown_permission' => 'permission',
        'reserved_permission' => 'permission',
    ];

    protected function hrRefused(HrRefused $e): JsonResponse
    {
        $message = __("hr.{$e->reason}");

        if (isset(self::HR_FIELDS[$e->reason])) {
            return response()->json(['message' => $message, 'code' => $e->reason, 'errors' => [self::HR_FIELDS[$e->reason] => [$message]]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $status = in_array($e->reason, self::HR_FORBIDDEN, true) ? Response::HTTP_FORBIDDEN : Response::HTTP_CONFLICT;

        return response()->json(['message' => $message, 'code' => $e->reason], $status);
    }
}
