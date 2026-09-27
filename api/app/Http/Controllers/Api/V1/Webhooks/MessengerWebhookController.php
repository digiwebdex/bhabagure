<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Inbox\MessengerInbox;
use App\Services\Inbox\MessengerSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * /api/v1/webhooks/messenger (docs/admin-inbox.md §4). GET answers Meta's subscription check with the verify token
 * shown in Admin → Inbox → Settings; POST carries the Page's events, signed with the App secret — anything unsigned is
 * refused before its body is used.
 */
class MessengerWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $token = (string) $request->query('hub_verify_token', '');
        if ($request->query('hub_mode') === 'subscribe' && $token !== '' && hash_equals(MessengerSettings::get()['verify_token'], $token)) {
            return response((string) $request->query('hub_challenge', ''), Status::HTTP_OK, ['Content-Type' => 'text/plain']);
        }

        return response('Forbidden', Status::HTTP_FORBIDDEN);
    }

    public function receive(Request $request, MessengerInbox $inbox): JsonResponse
    {
        if (! MessengerInbox::authentic($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            return response()->json(['status' => 'unauthorized'], Status::HTTP_UNAUTHORIZED);
        }
        $inbox->handle($request->json()->all());

        return response()->json(['status' => 'received']);
    }
}
