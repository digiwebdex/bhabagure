<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\SiteSetting;
use App\Services\Inbox\MessengerSettings;
use App\Services\Notifications\NotificationDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/admin-inbox.md: customers' WhatsApp chats (WaSender webhooks) and the Facebook Page's Messenger chats are filed
 * into conversations; staff read and answer them from the admin.
 */
class InboxTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'wasender-webhook-secret-for-tests';

    private const WASENDER = 'https://www.wasenderapi.com/api';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'bhabaghure.notifications.inbox.whatsapp' => true,
            'bhabaghure.notifications.whatsapp.api_key' => 'session-key',
            'bhabaghure.notifications.whatsapp.webhook_secret' => self::SECRET,
            'bhabaghure.notifications.whatsapp.base_url' => self::WASENDER,
            'app.url' => 'https://api.bhabaghure.test',
        ]);
        Cache::forget(NotificationDelivery::LAST_SEND_KEY);
    }

    #[Test]
    public function a_customers_whatsapp_message_opens_a_conversation_once_and_finds_their_customer_record(): void
    {
        $customer = Customer::query()->create(['name' => 'Tanvir Hasan', 'phone' => '8801711000001', 'stage' => 'lead', 'source' => 'whatsapp', 'locale' => 'bn']);

        // WaSender sends the same message as "received" and as "upsert", with the number hidden behind a privacy id.
        $this->wasender('messages.received', ['messages' => $this->incoming('ABC1', 'Nepal trip-er price koto? 4 jon', lid: '5551234@lid')])->assertOk();
        $this->wasender('messages.upsert', ['messages' => [$this->incoming('ABC1', 'Nepal trip-er price koto? 4 jon', lid: '5551234@lid')]])->assertOk();

        $conversation = Conversation::query()->sole();
        $this->assertSame(['whatsapp', '8801711000001', 'Tanvir', 1, $customer->id], [$conversation->channel, $conversation->external_id, $conversation->name, $conversation->unread_count, $conversation->customer_id]);
        $this->assertSame(['in', 'customer', 'Nepal trip-er price koto? 4 jon'], [ConversationMessage::query()->sole()->direction, ConversationMessage::query()->sole()->origin, ConversationMessage::query()->sole()->body]);
        $this->assertSame('Nepal trip-er price koto? 4 jon', $conversation->last_message_preview);

        // A later message that carries only the privacy id lands in the same conversation.
        $this->wasender('messages.received', ['messages' => [
            'key' => ['id' => 'ABC2', 'fromMe' => false, 'remoteJid' => '5551234@lid', 'addressingMode' => 'lid'],
            'messageBody' => 'Date 15 Oct', 'message' => ['conversation' => 'Date 15 Oct'],
        ]])->assertOk();
        $this->assertSame([1, 2], [Conversation::query()->count(), $conversation->fresh()->unread_count]);

        // Groups and statuses are not customer chats; a wrong secret is refused.
        $this->wasender('messages.received', ['messages' => ['key' => ['id' => 'G1', 'fromMe' => false, 'remoteJid' => '1203630@g.us', 'participant' => '8801711000009@s.whatsapp.net'], 'messageBody' => 'group']])->assertOk();
        $this->postJson('/api/v1/webhooks/wasender', ['event' => 'messages.received', 'data' => ['messages' => $this->incoming('X9', 'spoof')]], ['X-Webhook-Signature' => 'wrong'])->assertUnauthorized();
        $this->assertSame(2, ConversationMessage::query()->count());
    }

    #[Test]
    public function wasenders_url_check_is_answered_and_nothing_else_gets_in_without_the_secret(): void
    {
        $this->getJson('/api/v1/webhooks/wasender')->assertOk()->assertExactJson(['status' => 'ok']);
        $this->postJson('/api/v1/webhooks/wasender', ['event' => 'messages.received', 'data' => ['messages' => $this->incoming('X1', 'Hi')]])->assertUnauthorized();
        $this->assertSame(0, Conversation::query()->count());
    }

    #[Test]
    public function nothing_is_stored_while_the_inbox_is_off(): void
    {
        config(['bhabaghure.notifications.inbox.whatsapp' => false]);
        $this->wasender('messages.received', ['messages' => $this->incoming('ABC1', 'Hello')])->assertOk();
        $this->assertSame(0, Conversation::query()->count());
    }

    #[Test]
    public function a_photo_is_fetched_and_kept_encrypted_and_staff_open_it_from_the_admin(): void
    {
        Http::fake([
            self::WASENDER.'/decrypt-media' => Http::response(['success' => true, 'publicUrl' => self::WASENDER.'/decrypted-media/IMG1']),
            self::WASENDER.'/decrypted-media/IMG1' => Http::response('JPEG-BYTES', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $this->wasender('messages.received', ['messages' => $this->incoming('IMG1', null, [
            'imageMessage' => ['url' => 'https://mmg.whatsapp.net/x', 'mimetype' => 'image/jpeg', 'caption' => 'Passport', 'mediaKey' => 'k', 'fileLength' => '10'],
        ])])->assertOk();

        $message = ConversationMessage::query()->sole();
        $this->assertSame(['image', 'Passport', 'image/jpeg', 10], [$message->attachment_kind, $message->body, $message->attachment_mime, $message->attachment_bytes]);
        $this->assertNull($message->attachment_source);
        $this->assertStringNotContainsString('JPEG-BYTES', Storage::disk('local')->get($message->attachment_path));
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::WASENDER.'/decrypt-media' && $request['data']['messages']['key']['id'] === 'IMG1'
            && $request['data']['messages']['message']['imageMessage']['mediaKey'] === 'k' && $request->hasHeader('Authorization', 'Bearer session-key'));

        $agent = $this->staff('sales_agent');
        $this->actingAsApi($agent)->get("/api/v1/admin/inbox/messages/{$message->id}/file")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame('JPEG-BYTES', $this->actingAsApi($agent)->get("/api/v1/admin/inbox/messages/{$message->id}/file")->getContent());
        $this->actingAsApi($this->staff('accountant'))->get("/api/v1/admin/inbox/messages/{$message->id}/file")->assertForbidden();
    }

    #[Test]
    public function staff_reply_by_whatsapp_and_the_echo_ticks_and_phone_messages_file_into_the_same_thread(): void
    {
        Http::fake([self::WASENDER.'/send-message' => Http::response(['success' => true, 'data' => ['msgId' => 7001, 'status' => 'in_progress']])]);
        $this->wasender('messages.received', ['messages' => $this->incoming('IN1', 'Price?')])->assertOk();
        $conversation = Conversation::query()->sole();
        $agent = $this->staff('sales_agent');

        $this->actingAsApi($agent)->postJson("/api/v1/admin/inbox/conversations/{$conversation->id}/messages", ['body' => '  4 jon 75,000 taka jonoprotI  '])
            ->assertCreated()->assertJsonPath('data.origin', 'staff')->assertJsonPath('data.staff.id', $agent->id);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::WASENDER.'/send-message' && $request['to'] === '+8801711000001' && $request['text'] === '4 jon 75,000 taka jonoprotI');
        $reply = ConversationMessage::query()->where('origin', 'staff')->sole();
        $this->assertSame(['sent', '7001'], [$reply->status, $reply->provider_message_id]);
        // Answering reads the chat, and the replier takes it.
        $this->assertSame([0, $agent->id], [$conversation->fresh()->unread_count, $conversation->fresh()->assigned_staff_id]);

        // WaSender reports the send and echoes it as the phone's own message: linked, not filed twice.
        $this->wasender('message.sent', ['msgId' => 7001, 'key' => ['id' => 'OUT1', 'fromMe' => true, 'remoteJid' => '+8801711000001'], 'success' => true])->assertOk();
        $this->wasender('messages.upsert', ['messages' => [['key' => ['id' => 'OUT1', 'fromMe' => true, 'remoteJid' => '8801711000001@s.whatsapp.net'], 'messageBody' => '4 jon 75,000 taka jonoprotI']]])->assertOk();
        $this->wasender('messages.update', ['key' => ['id' => 'OUT1', 'fromMe' => true, 'remoteJid' => '8801711000001@s.whatsapp.net'], 'update' => ['status' => 4]])->assertOk();
        $this->assertSame(['OUT1', 'read'], [$reply->fresh()->external_message_id, $reply->fresh()->status]);

        // Someone answers from the phone: it shows in the thread as sent from the phone.
        $this->wasender('messages.upsert', ['messages' => [['key' => ['id' => 'PH1', 'fromMe' => true, 'remoteJid' => '8801711000001@s.whatsapp.net'], 'messageBody' => 'Call korchi']]])->assertOk();
        $thread = $this->actingAsApi($agent)->getJson("/api/v1/admin/inbox/conversations/{$conversation->id}")->assertOk()->json('data.messages');
        $this->assertSame([['in', 'customer'], ['out', 'staff'], ['out', 'phone']], array_map(fn (array $m) => [$m['direction'], $m['origin']], $thread));
        $this->assertSame(3, ConversationMessage::query()->count());
    }

    #[Test]
    public function a_pdf_reply_is_fetched_by_whatsapp_through_a_short_signed_link_only(): void
    {
        Http::fake([self::WASENDER.'/send-message' => Http::response(['success' => true, 'data' => ['msgId' => 7002]])]);
        $this->wasender('messages.received', ['messages' => $this->incoming('IN1', 'Brochure den')])->assertOk();
        $conversation = Conversation::query()->sole();

        $this->actingAsApi($this->staff('sales_agent'))->post("/api/v1/admin/inbox/conversations/{$conversation->id}/messages", [
            'body' => 'Mustang brochure', 'file' => UploadedFile::fake()->createWithContent('Mustang tour.pdf', '%PDF-1.4 brochure'),
        ])->assertCreated()->assertJsonPath('data.attachment.kind', 'document');

        $url = null;
        Http::assertSent(function (HttpRequest $request) use (&$url) {
            $url = $request['documentUrl'] ?? null;

            return $request['fileName'] === 'Mustang tour.pdf' && $request['text'] === 'Mustang brochure';
        });
        $this->assertStringStartsWith('https://api.bhabaghure.test/api/v1/public/inbox-files/', (string) $url);
        $path = (string) parse_url((string) $url, PHP_URL_PATH).'?'.parse_url((string) $url, PHP_URL_QUERY);
        $this->assertSame('%PDF-1.4 brochure', $this->get($path)->assertOk()->getContent());
        // Without its signature, or after 30 minutes, the file isn't served.
        $this->get((string) parse_url((string) $url, PHP_URL_PATH))->assertForbidden();
        $this->travel(31)->minutes();
        $this->get($path)->assertForbidden();
    }

    #[Test]
    public function a_failed_whatsapp_send_shows_as_failed_with_the_reason(): void
    {
        Http::fake([self::WASENDER.'/send-message' => Http::response(['success' => false, 'message' => 'Invalid number JID'], 422)]);
        $this->wasender('messages.received', ['messages' => $this->incoming('IN1', 'Hi')])->assertOk();
        $conversation = Conversation::query()->sole();
        $this->actingAsApi($this->staff('sales_agent'))->postJson("/api/v1/admin/inbox/conversations/{$conversation->id}/messages", ['body' => 'Hello'])->assertCreated();

        $this->assertSame(['failed', 'not_on_whatsapp'], [ConversationMessage::query()->where('origin', 'staff')->sole()->status, ConversationMessage::query()->where('origin', 'staff')->sole()->error]);
    }

    #[Test]
    public function the_facebook_page_is_connected_with_a_checked_token_kept_encrypted_and_its_messages_come_in_signed(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/me?*' => Http::response(['id' => 'PAGE1', 'name' => 'Bhabaghure Holidays']),
            'https://graph.facebook.com/v21.0/PAGE1/subscribed_apps' => Http::response(['success' => true]),
            'https://graph.facebook.com/v21.0/PSID1?*' => Http::response(['name' => 'Mim Akter']),
            'https://graph.facebook.com/v21.0/me/messages' => Http::response(['recipient_id' => 'PSID1', 'message_id' => 'm_OUT1']),
        ]);
        $admin = $this->staff('admin');
        $this->actingAsApi($this->staff('sales_agent'))->getJson('/api/v1/admin/inbox/settings')->assertForbidden();
        $this->actingAsApi($admin)->putJson('/api/v1/admin/inbox/settings/messenger', ['page_token' => 'EAAG-page-token-1234567890', 'app_secret' => 'app-secret-1234567890'])
            ->assertOk()->assertJsonPath('data.messenger.connected', true)->assertJsonPath('data.messenger.page_name', 'Bhabaghure Holidays')
            ->assertJsonMissingPath('data.messenger.page_token')->assertJsonMissingPath('data.messenger.app_secret');
        $stored = json_encode(SiteSetting::query()->find(MessengerSettings::KEY)->value);
        $this->assertStringNotContainsString('EAAG-page-token', (string) $stored);
        $this->assertStringNotContainsString('app-secret-1234567890', (string) $stored);
        $verify = MessengerSettings::get()['verify_token'];

        // Meta's subscription check.
        $this->get('/api/v1/webhooks/messenger?hub.mode=subscribe&hub.verify_token='.$verify.'&hub.challenge=CH4LL')->assertOk()->assertSee('CH4LL');
        $this->get('/api/v1/webhooks/messenger?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=CH4LL')->assertForbidden();

        $event = ['object' => 'page', 'entry' => [['id' => 'PAGE1', 'messaging' => [[
            'sender' => ['id' => 'PSID1'], 'recipient' => ['id' => 'PAGE1'], 'timestamp' => now()->getTimestampMs(),
            'message' => ['mid' => 'm_IN1', 'text' => 'Maldives honeymoon package ache?'],
        ]]]]];
        $this->messenger($event, 'wrong-secret')->assertUnauthorized();
        $this->messenger($event, 'app-secret-1234567890')->assertOk();
        $this->messenger($event, 'app-secret-1234567890')->assertOk();
        $conversation = Conversation::query()->sole();
        $this->assertSame(['messenger', 'PSID1', 'Mim Akter', 1], [$conversation->channel, $conversation->external_id, $conversation->name, $conversation->unread_count]);

        // A reply inside the 24 hours goes by the Graph API; its echo isn't filed twice.
        $this->actingAsApi($this->staff('sales_agent'))->postJson("/api/v1/admin/inbox/conversations/{$conversation->id}/messages", ['body' => 'Ji ache!'])->assertCreated();
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://graph.facebook.com/v21.0/me/messages' && $request['recipient']['id'] === 'PSID1'
            && $request['message']['text'] === 'Ji ache!' && $request['messaging_type'] === 'RESPONSE' && $request->hasHeader('Authorization', 'Bearer EAAG-page-token-1234567890'));
        $this->messenger(['object' => 'page', 'entry' => [['id' => 'PAGE1', 'messaging' => [[
            'sender' => ['id' => 'PAGE1'], 'recipient' => ['id' => 'PSID1'], 'timestamp' => now()->getTimestampMs(), 'message' => ['mid' => 'm_OUT1', 'text' => 'Ji ache!', 'is_echo' => true],
        ]]]]], 'app-secret-1234567890')->assertOk();
        $this->assertSame(2, ConversationMessage::query()->count());
        $this->assertSame('m_OUT1', ConversationMessage::query()->where('origin', 'staff')->sole()->external_message_id);

        // After 24 hours Meta refuses replies: the inbox says so before trying.
        $this->travel(25)->hours();
        $this->actingAsApi($this->staff('sales_agent'))->postJson("/api/v1/admin/inbox/conversations/{$conversation->id}/messages", ['body' => 'Hello?'])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertFalse($this->actingAsApi($admin)->getJson("/api/v1/admin/inbox/conversations/{$conversation->id}")->json('data.can_reply'));
    }

    #[Test]
    public function who_may_read_reply_assign_and_turn_a_chat_into_a_lead(): void
    {
        $this->wasender('messages.received', ['messages' => $this->incoming('IN1', 'Hi')])->assertOk();
        $conversation = Conversation::query()->sole();
        $agent = $this->staff('sales_agent');
        $colleague = $this->staff('sales_agent');
        $admin = $this->staff('admin');
        $url = "/api/v1/admin/inbox/conversations/{$conversation->id}";

        $this->actingAsApi($this->staff('accountant'))->getJson('/api/v1/admin/inbox/conversations')->assertForbidden();
        $this->actingAsApi($this->staff('tour_operator'))->getJson($url)->assertForbidden();
        $this->actingAsApi($agent)->getJson('/api/v1/admin/inbox/conversations?view=unread')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.unread_by_channel.whatsapp', 1);

        // An agent takes a chat or lets it go; handing it to a colleague is for admins.
        $this->actingAsApi($agent)->postJson("{$url}/assign", ['staff_id' => $agent->id])->assertOk()->assertJsonPath('data.assignee.id', $agent->id);
        $this->actingAsApi($agent)->postJson("{$url}/assign", ['staff_id' => $colleague->id])->assertForbidden();
        $this->actingAsApi($admin)->postJson("{$url}/assign", ['staff_id' => $colleague->id])->assertOk()->assertJsonPath('data.assignee.id', $colleague->id);
        $this->actingAsApi($colleague)->getJson('/api/v1/admin/inbox/conversations?view=mine')->assertOk()->assertJsonPath('meta.total', 1);

        // A lead from the chat: the WhatsApp number, owned by whoever made it.
        $this->actingAsApi($agent)->postJson("{$url}/lead", ['name' => 'Tanvir Hasan', 'phone' => '01711-000001'])->assertOk()
            ->assertJsonPath('data.customer.name', 'Tanvir Hasan')->assertJsonPath('data.customer.visible', true);
        $customer = Customer::query()->sole();
        $this->assertSame(['whatsapp', $agent->id, 'lead'], [$customer->source->value ?? $customer->source, $customer->assigned_staff_id, $customer->stage->value ?? $customer->stage]);

        // Closing clears it from the open list; a new message opens it again.
        $this->actingAsApi($agent)->postJson("{$url}/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->actingAsApi($agent)->getJson('/api/v1/admin/inbox/conversations')->assertJsonPath('meta.total', 0);
        $this->wasender('messages.received', ['messages' => $this->incoming('IN2', 'Aro jante chai')])->assertOk();
        $this->assertSame(['open', 1], [$conversation->fresh()->status, $conversation->fresh()->unread_count]);

        // Canned replies: everyone reads them, admins edit them.
        $this->actingAsApi($agent)->getJson('/api/v1/admin/inbox/canned-replies')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAsApi($agent)->postJson('/api/v1/admin/inbox/canned-replies', ['title' => 'x', 'body' => 'y'])->assertForbidden();
        $this->actingAsApi($admin)->postJson('/api/v1/admin/inbox/canned-replies', ['title' => 'Office address', 'body' => 'Our office…'])->assertCreated();
    }

    /** @param array<string, mixed> $data */
    private function wasender(string $event, array $data)
    {
        return $this->postJson('/api/v1/webhooks/wasender', ['event' => $event, 'timestamp' => now()->getTimestamp(), 'data' => $data], ['X-Webhook-Signature' => self::SECRET]);
    }

    /** @param array<string, mixed> $payload */
    private function messenger(array $payload, string $secret)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/v1/webhooks/messenger', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', (string) $body, $secret),
        ], $body);
    }

    /** A customer's message as WaSender sends it (https://wasenderapi.com/api-docs/webhooks/webhook-message-received). */
    private function incoming(string $id, ?string $text, array $message = [], ?string $lid = null): array
    {
        return [
            'key' => array_filter([
                'id' => $id, 'fromMe' => false, 'remoteJid' => $lid ?? '8801711000001@s.whatsapp.net',
                'senderPn' => '8801711000001@s.whatsapp.net', 'cleanedSenderPn' => '8801711000001', 'senderLid' => $lid,
            ], fn ($v) => $v !== null),
            'pushName' => 'Tanvir',
            'messageBody' => $text,
            'message' => $message ?: ['conversation' => $text],
        ];
    }
}
