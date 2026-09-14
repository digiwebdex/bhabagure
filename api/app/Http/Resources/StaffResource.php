<?php

namespace App\Http\Resources;

use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Staff */
class StaffResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_code' => $this->employee_code,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status->value,
            'locale' => $this->locale,
            'must_change_password' => $this->must_change_password,
            'role' => $this->getRoleNames()->first(),
            'is_super_admin' => $this->isSuperAdmin(),
            // The admin hides what the user can't do; the API enforces it regardless.
            'permissions' => $this->getAllPermissions()->pluck('name')->sort()->values(),
        ];
    }
}
