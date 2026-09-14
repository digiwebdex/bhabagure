<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Notifications\WhatsApp\WaSenderWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** POST /api/v1/webhooks/wasender. Anything without the right secret is refused before its body is read. */
class WaSenderWebhookController extends Controller
{
    public function __invoke(Request $request, WaSenderWebhook $webhook): JsonResponse
    {
        if (! WaSenderWebhook::authentic($request->header('X-Webhook-Signature'))) {
            return response()->json(['status' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $webhook->handle($request->json()->all());

        return response()->json(['status' => 'received']);
    }
}
