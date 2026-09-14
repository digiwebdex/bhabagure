<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\NavBadges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /admin/nav-counts — the sidebar badges, derived from data (App\Support\Admin\NavBadges). */
class NavCountController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(['data' => NavBadges::for($request->user('staff'))])->header('Cache-Control', 'no-store');
    }
}
