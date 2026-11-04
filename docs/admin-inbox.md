# Admin inbox: WhatsApp and Facebook Messenger (2026-09-27)

**Asked:** staff read the company's WhatsApp messages and Facebook Messenger messages in the admin panel and reply from
there. The design (`_design/Bhabaghure Admin.dc.html`, Communication → Inbox) shows one list with All / WhatsApp /
Facebook / SMS filters, a conversation pane, a reply box with canned replies, and "Create lead" / "Start booking".

## 1. Decided (2026-09-27, the recommended answers)

1. **The WaSender key is the main number** customers write to. Its chats come into the admin and replies go out from
   it. WaSender is unofficial, so there is some risk of a ban: replies share the paced sending (one per 5 seconds
   plus a random pause) and a limit of 20 replies a minute per staff member. The inbox is not a bulk sender.
2. **Inbox now, automated messages later.** `INBOX_WHATSAPP=true` switches the inbox on; `WASENDER_MODE` stays `off`,
   so no booking confirmations or codes go out until they are tested on this number. Note: the automated messages were
   designed for a separate notifications number (docs/phase-4-whatsapp.md); switching them on for the main number is
   a separate decision.
3. **Messenger is built now** and connects when the Page's admin enters the Page access token and App secret
   (§4.2).
4. **Admins and sales agents** read and reply; everyone sees every chat and can take one. Admins hand chats to
   colleagues, manage quick replies and the settings. Accountants and tour operators don't see the inbox.

SMS is not in the inbox: bulksmsbd.net only sends, it cannot receive replies.

## 2. How it works

**Data:**
- `conversations`: one per customer per channel. WhatsApp chats are keyed on the number; WhatsApp's privacy id
  (`…@lid`) is kept too, so both land in one chat. Each row holds the name, linked customer, assignee, open or closed,
  the unread count, the last message, and, for Messenger, the time of the customer's last message.
- `conversation_messages`: direction and origin (the customer, staff here, the phone or Page inbox, or an automated
  message), text, an attachment (encrypted on the private disk), provider ids and delivery ticks.
- `canned_replies`: the quick replies.

**WhatsApp** (`WhatsAppInbox`, from WaSender's webhook, the same URL as the delivery ticks):
- Receiving:
  - `messages.received` / `messages.upsert` bring the customer's messages. The number is read from `cleanedSenderPn`.
  - Groups, statuses and channels are ignored.
  - A message that arrives twice is filed once.
  - Messages typed on the phone or in WhatsApp Web arrive as `fromMe` and show as "From the phone".
  - A reply sent from here comes back the same way and is matched to it, not filed twice.
- Delivery ticks: `messages.update` gives them, and `message.sent` links WaSender's id to WhatsApp's.
- Media: photos, documents, voice notes and videos are fetched through WaSender's decrypt-media link (it lasts an
  hour) and stored encrypted.
- Read receipts (changed 2026-10-15, the owner's choice): opening a chat only clears the inbox's unread count. The
  customer gets blue ticks when a staff reply from here has actually gone (`SendInboxMessage` → `MarkInboxRead`), so
  "Seen" always comes with an answer. Before, a chat left open on a screen marked every new message seen at once, and
  customers took it as being ignored. WaSender's own auto-read must be off too (§3, step 4).
- Customer records: a chat whose number matches a customer is linked to them automatically.
- History starts when the inbox is switched on. WaSender has no history API, so older chats stay on the phone.

**Messenger** (`MessengerInbox`, `/api/v1/webhooks/messenger`):
- The webhook is signed with the App secret (`X-Hub-Signature-256`). GET answers Meta's check with the verify token.
- It receives messages, attachments, the Page's own replies (echoes), deliveries and reads.
- The customer's name comes from the Graph API.
- Replies are allowed only within 24 hours of the customer's last message (Meta's rule). After that the reply box
  says so and suggests a call.

**Replies** (`SendInboxMessage`, on the notifications queue):
- Text, and optionally a photo, PDF or audio file. Up to 6 MB in practice: the rule in the code allows 16 MB, but
  php-fpm (`deploy/php-fpm/bhabaghure.conf`) stops an upload at 6 MB and nginx at 8 MB first.
- Or a voice note recorded in the reply box (§9).
- The provider fetches the file once through a 30-minute signed link, `/api/v1/public/inbox-files/…`.
- A reply that can't go shows "Not sent" with the reason.
- Answering reads the chat (and, once the reply has gone, sends the customer blue ticks), and an unassigned chat
  becomes the replier's.

**Admin → Communication → Inbox** (sidebar badge = chats with unread messages):
- The list:
  - filters All / WhatsApp / Messenger, and Open / Unread / Mine / Closed, with counts
  - search by name, number or text
  - refreshes every 10 s
- The chat:
  - bubbles with ticks, photos inline, voice notes and videos playable, documents opened on click, day dividers and
    older messages on demand
  - refreshes every 5 s
- The reply box: quick replies, and **Send package details** (a package's name, price for two and website link,
  in Bangla). Enter sends; Shift+Enter starts a new line.
- The customer panel shows the linked customer and their latest bookings, with **Start booking** (New booking with
  the customer picked). Or it offers **Create lead** from the chat (the WhatsApp number is filled in; on Messenger
  staff ask for it), or linking an existing customer.
- Chat actions: take or release it; admins assign it; mark it as unread (§10); close or reopen it (a new message
  reopens it).
- **Inbox → Settings** (admins): the WhatsApp connection's state, webhook URL and events; the Facebook Page connection
  with Meta's steps, the callback URL and verify token; the quick-reply editor. The Page token and App secret are
  checked with Facebook, stored encrypted and never shown again.

Permissions:
- `inbox.view`: read.
- `inbox.reply`: reply, take, close and link.
- `inbox.manage`: assign to others, settings and quick replies.

## 3. Switching WhatsApp on (done 2026-09-27)

1. On the server, `api/.env` holds these, with the key and secret only there and never in the repository:
   - `WASENDER_API_KEY` = the session key
   - `WASENDER_WEBHOOK_SECRET` = a long random string
   - `INBOX_WHATSAPP=true`
   - `WASENDER_MODE` stays `off`
   Then run `deploy.sh --reload-config`.
2. In the **WaSender dashboard**, open the number's session → Webhook:
   - URL `https://api.bhabaghure.com.bd/api/v1/webhooks/wasender`
   - the same secret
   - the events `messages.received`, `messages.upsert`, `messages.update`, `message.sent`, `session.status`
3. Send a WhatsApp message to the number from another phone: it appears in Admin → Inbox within seconds.
4. **No automatic "Seen"** (2026-10-15). In the same session's settings, the option that marks incoming messages as
   read automatically (named like "Read incoming messages" / auto-read) must be **off**, and "Always online" too if it
   is shown. With it on, WaSender itself sends blue ticks for every message the moment it arrives, whatever the inbox
   does. Check it: from another phone, send the number a message and don't open the chat anywhere — it must stay at
   two grey ticks.

## 4. Connecting the Facebook Page

Done by someone who is an **admin of the Facebook Page**:

1. At developers.facebook.com, create an app of type **Business**, and add the **Messenger** product.
2. Under Messenger → Access tokens, connect the Bhabaghure Holidays Page and **generate the Page access token**.
3. Under Messenger → Webhooks:
   - paste the **Callback URL** and **Verify token** shown in Admin → Inbox → Settings
   - subscribe the Page to `messages`, `message_echoes`, `message_deliveries` and `message_reads`
4. App settings → Basic → copy the **App secret**.
5. Paste the Page token and App secret in Admin → Inbox → Settings → Connect the Page. They are checked with Facebook
   first, and the Page is subscribed to the app; nothing is saved unless both work, and a refusal shows Facebook's own
   reason. Every Graph call carries `appsecret_proof` (HMAC-SHA256 of the token with the App secret), so an app with
   "Require app secret" switched on works too (2026-10-17).
6. Until Meta approves the app (App Review, permission `pages_messaging`, with a short screen recording of replying
   from the inbox), only people with a role on the app can message through it. After approval, every customer can.

## 5. Tests

- `api/tests/Feature/InboxTest.php` covers:
  - duplicate and privacy-id messages
  - groups ignored
  - a wrong secret refused
  - the inbox switched off
  - media fetched and encrypted
  - a reply, its echo, its ticks, and a phone message
  - a PDF reply through the signed link
  - a failed send
  - the Messenger connection (encrypted), its verify check, signed events, a reply, the echo and the 24-hour window
  - permissions, assignment, the lead, close/reopen, and quick replies
- `NavCountsContractTest`: the `inbox` badge.
- `admin/e2e/inbox.spec.ts`:
  - a chat read, answered with a quick reply, made a lead, a booking started, then closed and reopened
  - settings: quick replies, and a refused Page token

## 6. Staff messages from the main number (decided 2026-09-28)

WaSender's plan has one session: the main number 01743-939300. So a message a staff member writes elsewhere — a reply
to an air-ticket or hotel request, "Send WhatsApp" on a booking or customer — goes from the main number through the
inbox's sender (paced like every reply) and shows in that customer's Inbox chat. The notification log marks it sent
(`provider_message_id` = `inbox:<message id>`); its ticks are in the chat. The email copy still goes.

`NotificationSettings::staffWhatsAppRoute()`: `notifications` when the automated messages are on and their number is
published; else `inbox` while the WhatsApp inbox is on; else nothing. The **automated** messages (booking received,
receipts, reminders, codes) stay on hold until a notifications number is published and `WASENDER_MODE=live` — the
client's choice of 2026-09-28 was "staff replies only".

The WaSender dashboard's webhook secret is WaSender's own (it generates it); `WASENDER_WEBHOOK_SECRET` on the server
must match it exactly, or every event is refused with 401.

## 7. Sign-in and booking codes from the main number (client, 2026-09-29)

With SMS and email not yet delivering, a customer asking for a portal sign-in code was told "the code can't be sent
right now". A code is something the customer has just asked for, not an automated message, so while the automated
messages are held (above) it now goes from the main number through the inbox's WaSender session, straight away
(`LoginCodes::deliver`). The same holds for the booking code. SMS and email still go too once they work.

A code is a credential: WaSender echoes each send back as the phone's own message, and that echo is kept out of the
Inbox (`WhatsAppInbox::keepOut`). It still shows in the WhatsApp Business app on the office phone.

## 8. Starting a WhatsApp chat from the inbox (client, 2026-10-01)

Agents start a chat with a customer who hasn't written first, by number. **+ New chat** in the Inbox header (anyone
with `inbox.reply`) opens a dialog: the number, an optional name and an optional first message. Searching the list for
a number that has no chat offers **Start a chat with +880…**, which opens the same dialog with the number filled in.

- **The number**: a Bangladeshi mobile in any form (01711-000000, +880 1711 000000, Bangla digits), or another
  country's with its `+` or `00` code (+977 981-2345678). A foreign number without its code is refused: it can't be told
  from a typo. `Phone::normalizeWhatsApp`; the dialog mirrors it in `admin/src/features/inbox/phone.ts`.
- **A number that has a chat** opens that chat — found by the number, also a chat first filed under WhatsApp's privacy
  id. A closed one is reopened. No second chat, nothing asked of WaSender, nothing counted.
- **A new number** is first checked with WaSender (`GET /on-whatsapp/+8801…`, answering `{"data":{"exists":…}}`,
  checked against the live API). "Not on WhatsApp" refuses it with that message. When WaSender can't answer, the chat is
  started anyway; a send that WhatsApp then refuses shows on the message as "Not sent: this number isn't on WhatsApp".
- **The new chat** belongs to whoever started it (assigned, and `started_by_staff_id`), carries the name they gave, is
  linked to the customer with that number if there is one, and sits at the top of the open list. The first message, if
  written, goes like any reply (paced, with ticks). Without one the chat opens empty with the reply box ready. When the
  customer answers, their WhatsApp profile name doesn't replace the name staff gave (`InboxRecorder`).
- **A daily limit for the office**: 30 new chats a Dhaka day (`INBOX_NEW_CHATS_PER_DAY`), whoever starts them, because
  WhatsApp restricts a number that messages many people who haven't saved it, and WaSender isn't official WhatsApp.
  Chats customers start, and replies, are not limited. The day's 31st is refused with a message saying so.
- **Messenger** chats can't be started: Facebook lets a Page message only people who wrote to it first, and gives no
  phone numbers. WaSender has no 24-hour window or message templates, so none apply to WhatsApp here.
- Every new chat is in the audit log (`inbox.chat_started`). `POST /admin/inbox/conversations` (`phone`, `name`, `body`)
  answers 201 for a new chat and 200 for one that was there, with `existing`; it shares the replies' rate limit.

Tests: `InboxTest` (a new number, the list, the customer's answer and the name; an existing and a privacy-id chat; a
wrong number, "not on WhatsApp", another country's number, the daily limit and the next day, permissions, the inbox off)
and `admin/e2e/inbox.spec.ts` (from the search, the same number again, a number without its code).

## 9. Voice notes from the reply box (client, 2026-11-04)

Staff with `inbox.reply` record a voice note in the browser and send it to the customer. Decided with the owner: Stop,
listen, then Send, with no sending straight from the recording, because it goes from the company's main number; and
5 minutes at most, when recording stops by itself.

**In the reply box:** 🎤 beside 📎. While recording: Cancel, the time, the microphone's level, Stop. After Stop: Delete, a
player, **Send voice note**. What was being typed stays in the box. The microphone is released when recording stops, and
when staff cancel or leave the chat; a recording not sent is gone with it. A browser that can't record shows no 🎤. A
blocked or missing microphone gets a message saying what to do.

**`POST /admin/inbox/conversations/{id}/send-audio`** (field `audio`, shares the replies' rate limit). Browsers record
different formats and WaSender doesn't list WebM, so `VoiceNotes` converts every recording with ffmpeg, in the request:

| Channel | Sent as | How it goes |
|---|---|---|
| WhatsApp | one channel of Opus in Ogg, 32 kbit/s (`.ogg`, `audio/ogg`) | WaSender's `audioUrl`, which its documentation says is "sent as voice note" |
| Messenger | one channel of AAC in M4A, 64 kbit/s (`.m4a`, `audio/mp4`) | an `audio` attachment, inside the 24-hour window. Meta's public pages don't list the audio formats it accepts, so this needs a real send to confirm |

- The input is read as what its first bytes say it is (WebM, Ogg or MP4; never the name or type the browser gave), and
  ffmpeg is limited to that one file. Anything else is refused without running ffmpeg.
- A recording that can't be converted, or ffmpeg missing from the server, is refused with a message saying to record
  again, and logged as `Inbox voice note not converted`. Nothing is kept or sent.
- After that it is a reply like any other: encrypted on the private disk, paced with the other replies, fetched once
  through the signed link, with ticks, "Not sent" and a player bubble. Answering reads and takes the chat.

**On the server:** `/usr/bin/ffmpeg` comes from the shared box's own packages, and this project didn't install it. If it
is ever removed, voice notes stop with the message above and everything else carries on. `INBOX_FFMPEG` overrides the
path. A real 4-second Chrome recording took about 0.1 s and 50 MB to convert (checked on the server, 2026-11-04).

**Older Safari:** it may not play Ogg Opus, which is also the format of every customer voice note here. A note recorded
in Safari still goes to the customer; staff on such a Safari may just not be able to play the Ogg bubbles.

Tests: `InboxTest` (WhatsApp and Messenger formats, the signed link, the echo, Firefox and Safari containers, what is
refused, the window, permissions) and `admin/e2e/inbox-voice.spec.ts` (Chrome's fake microphone: cancel, record, delete,
send, and the microphone let go). On Windows the e2e API uses `scripts/e2e-fake-ffmpeg.cmd`, which copies the file,
because there is no ffmpeg there.

## 10. Mark as unread (client, 2026-11-04)

**Mark as unread** in the chat's header (`inbox.reply`; `POST /admin/inbox/conversations/{id}/mark-unread`) flags a chat
to come back to later, and returns to the list as WhatsApp does.

- **For the whole office** (the owner's choice), on the same `unread_count` as new messages: the list, the Unread filter
  and the sidebar badge all count it. Whoever opens it next clears it. A count above zero stays as it is. The person who
  marks it sees the badge change at once; colleagues within a minute, as with new messages.
- **A closed chat reopens**, or it wouldn't show under Unread.
- **Nothing reaches the customer.** Blue ticks they already have can't be taken back.
- It is in the audit log (`inbox.marked_unread`).

A chat now clears its count only when it is opened, or when a customer's new message arrives while it is open. Before,
it cleared whenever the count was above zero, so a chat left open on a colleague's screen would have undone this at once.
