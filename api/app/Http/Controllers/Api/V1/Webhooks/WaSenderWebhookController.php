<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Inbox\WhatsAppInbox;
use App\Services\Notifications\WhatsApp\WaSenderWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/v1/webhooks/wasender. Anything without the right secret is refused before its body is read. The same events
 * feed the automated messages (ticks, STOP/START) and, when it is switched on, the admin inbox (docs/admin-inbox.md).
 */
class WaSenderWebhookController extends Controller
{
    public function __invoke(Request $request, WaSenderWebhook $webhook, WhatsAppInbox $inbox): JsonResponse
    {
        if (! WaSenderWebhook::authentic($request->header('X-Webhook-Signature'))) {
            return response()->json(['status' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = $request->json()->all();
        $webhook->handle($payload);
        $inbox->handle($payload);

        return response()->json(['status' => 'received']);
    }
}
