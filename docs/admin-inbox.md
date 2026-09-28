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
- Read receipts: opening a chat marks it read and sends blue ticks.
- Customer records: a chat whose number matches a customer is linked to them automatically.
- History starts when the inbox is switched on. WaSender has no history API, so older chats stay on the phone.

**Messenger** (`MessengerInbox`, `/api/v1/webhooks/messenger`):
- The webhook is signed with the App secret (`X-Hub-Signature-256`). GET answers Meta's check with the verify token.
- It receives messages, attachments, the Page's own replies (echoes), deliveries and reads.
- The customer's name comes from the Graph API.
- Replies are allowed only within 24 hours of the customer's last message (Meta's rule). After that the reply box
  says so and suggests a call.

**Replies** (`SendInboxMessage`, on the notifications queue):
- Text, and optionally a photo, PDF or voice note of up to 16 MB.
- The provider fetches the file once through a 30-minute signed link, `/api/v1/public/inbox-files/…`.
- A reply that can't go shows "Not sent" with the reason.
- Answering reads the chat, and an unassigned chat becomes the replier's.

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
- Chat actions: take or release it; admins assign it; close or reopen it (a new message reopens it).
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

## 4. Connecting the Facebook Page

Done by someone who is an **admin of the Facebook Page**:

1. At developers.facebook.com, create an app of type **Business**, and add the **Messenger** product.
2. Under Messenger → Access tokens, connect the Bhabaghure Holidays Page and **generate the Page access token**.
3. Under Messenger → Webhooks:
   - paste the **Callback URL** and **Verify token** shown in Admin → Inbox → Settings
   - subscribe the Page to `messages`, `message_echoes`, `message_deliveries` and `message_reads`
4. App settings → Basic → copy the **App secret**.
5. Paste the Page token and App secret in Admin → Inbox → Settings → Connect the Page. They are checked with Facebook
   first.
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
