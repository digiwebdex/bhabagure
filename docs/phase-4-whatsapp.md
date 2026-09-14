# Phase 4 — WhatsApp notifications (WaSenderAPI), with email alongside

**Status (2026-09-13): built as approved, tested, not deployed.** The four questions of the plan were answered the same
day (§9). Going live needs the client's dedicated notifications number and approved SMS sender ID, the rotated WaSender and
bulksmsbd keys, and SendGrid DNS — [`deployment.md`](deployment.md) §3–§6. The queue worker is installed (§5 there).
SMS through bulksmsbd.net was added the same day as a fallback channel (§10).

Sources: `_design/whatsapp-config.js` (16 events), `_design/README.md` (Communication, Notification timings,
Integrations, Resolved ambiguities), `Bhabaghure Admin.dc.html` (Notification templates, Channel connections),
`Bhabaghure Invoice.dc.html`, and the WaSenderAPI documentation (send-message, rate limits, webhooks, session status,
terms). The local `_design` copy is still the 2026-09-13 08:13 one.

---

## 1. What the design and the provider said, and what was done

| # | Finding | As built |
|---|---|---|
| 1 | Only 6 of the 16 config events have templates in the prototype. | The ten events the system can produce now (§2). The rest arrive with the modules that create them. |
| 2 | "Booking confirmation" and "Payment received" would both fire for one payment; cash/bKash recorded by staff fired neither. | One message per business event, whoever records it: the payment that confirms a booking online sends *confirmation + invoice*; a payment staff record sends *payment received*, and confirming the booking then sends the confirmation. |
| 3 | Confirmation text says "invoice sent by email" but email wasn't built. | The invoice PDF (passport numbers masked) goes as a WhatsApp document **and** as an email attachment. |
| 4 | "Seats almost gone" is a customer sales pitch in the prototype. | A sales-team alert only (once per departure at ≤ 3 free seats). No broadcasts. |
| 5 | One 160-character WhatsApp/SMS box. | Separate WhatsApp (≤ 1,000 characters), email (subject + ≤ 5,000) and SMS templates, bn + en. SMS only as a fallback and for departure day, with its real part count and cost (§10). |
| 6 | The same six variables for every template; "৳৳"; a hardcoded group leader. | Per-event variable lists; a template can't be saved with a variable its event lacks; amounts and dates through `Numerals`; the group leader from the departure. |
| 7 | "Send test" is a toast aimed at the company number. | Test sends go only to the signed-in staff member's own verified WhatsApp number or email. |
| 8 | Channel status hardcoded "Connected"; credits. | Real session status (`GET /api/status`, the `session.status` webhook, a 5-minute check) with an email to notification managers when it drops. No credits (flat subscription). |
| 9 | Row ✆ buttons open `wa.me` in the staff member's own WhatsApp. | Unchanged — personal, not logged. |
| 10 | Inbox and Campaigns. | Not in Phase 4. Incoming messages are read only for STOP / START and never stored. |
| 11 | WaSenderAPI is unofficial (ban risk, no unblocking). | Email is an always-on second channel; the provider sits behind `WhatsAppGateway`; a separate number; known risk and runbook in `deployment.md` §3.2. |
| 12 | The key in the design bundle is exposed. | `WASENDER_API_KEY` in `api/.env` only; the admin shows the last four characters; never logged or returned. Rotate before go-live. |

## 2. The ten messages

| Event | To | Channels | When (Dhaka time) |
|---|---|---|---|
| `booking_created` | customer | WhatsApp + email | a website booking is saved (the email carries the private booking link; WhatsApp doesn't) |
| `booking_confirmed` | customer | WhatsApp + email, invoice PDF attached | the booking is confirmed (online payment, or staff confirm) |
| `payment_received` | customer | WhatsApp + email | a payment that didn't itself confirm the booking |
| `documents_pending` | customer | WhatsApp + email | 7 days before departure, 10:00 — only while a traveller has no passport number (checked when planned and again at send time) |
| `pre_trip_reminder` | customer | WhatsApp + email | 2 days before departure, 10:00 |
| `departure_today` | customer | WhatsApp + always SMS | the travel day, 06:00 |
| `trip_completed` | customer | WhatsApp | 2 days after return, 11:00 (review link) |
| `new_booking_alert` | sales list + the booking's assigned agent | WhatsApp (verified numbers) + email | immediately |
| `new_lead_alert` | sales list | WhatsApp + email | the inquiry or air-ticket form is sent |
| `low_seat_alert` | sales list | WhatsApp + email | once, when a departure reaches 3 or fewer free seats |

Trip messages are planned when the booking is confirmed; a reminder whose time has already passed is sent at once if
the trip hasn't started. Cancelling a booking cancels its waiting trip messages. Every row has a unique `dedupe_key`,
so re-running anything can't send twice. Also sent, not templated: staff messages (§4), a staff member's WhatsApp
verification code, and the confirmation of a STOP reply.

## 3. Trust: a number the customer has never seen

Required by the decision on question 2, all built:

- **Every automated WhatsApp message opens with "ভবঘুরে হলিডেজ · Bhabaghure Holidays" on its own first line**, added by
  `MessageRenderer` — not part of the editable template, so it can't be deleted by mistake.
- **The notifications number is published wherever the main number appears**, as "নোটিফিকেশন নম্বর · Notifications"
  (English: "Notifications"): the website contact section and footer, the booking page, and the invoice footer.
- **Customer emails** (from our own domain) state: "Automated WhatsApp messages about your booking come only from our
  notifications number …", with the main line for talking to us. The booking review step says updates go to the lead
  traveller's WhatsApp and email, and names the number.
- **Both numbers are site settings** (Admin → Site settings → Contact: main phone, WhatsApp, notifications number), so
  either can change without a deploy. The notifications number must differ from the main lines.
- **Nothing goes to customers from an unpublished number**: until the notifications number is set, customer WhatsApp
  messages are recorded as held and emails go alone. Staff alerts aren't affected.

## 4. Staff sending

`POST /api/v1/admin/notifications/whatsapp` — `booking_id` or `customer_id`, `text` (2–1,000), `attach_invoice`.

- Permission `notifications.send` (admins, sales agents; not tour operators or accountants).
- **The number is always looked up on the server** from the booking or customer; `to`, `phone` and `number` are
  rejected for every role, admins included. A stranger needs a lead record first.
- Only records the staff member can already see (sales agents: bookings assigned to or created by them).
- Refused for opted-out customers, and while the notifications number is unpublished. 10 per minute and 100 per day per
  staff member, on top of the provider pacing. Every send is a logged message and an audit entry.

## 5. Architecture

```
domain events (after commit)                          bhabaghure-scheduler (every minute)
BookingCreated · BookingConfirmed · BookingCancelled   notifications:dispatch — due rows, stuck sends
PaymentRecorded · InquiryReceived                      notifications:check-whatsapp (5 min)
        │                                                        │
        ▼                                                        ▼
PlanNotifications → NotificationPlanner ── one row per recipient × channel (unique dedupe_key)
                                  │
                                  ▼
             DeliverNotification job (queue "notifications", one worker)
               claims the row → still wanted? (cancelled, passport added, template off, opted out,
               number unpublished) → WhatsApp: lock, pace (5 s + jitter), daily cap → WhatsAppGateway
               email: NotificationMail (+ masked invoice PDF)
               rate limit / disconnected → rescheduled on the row; bad number → failed
                                  │
                                  ▼
             webhook POST /api/v1/webhooks/wasender → delivered / read ticks, session status, STOP / START
```

- `WhatsAppGateway`: `WaSenderGateway` (HTTP), `FakeWhatsAppGateway` (writes to `storage/logs/whatsapp-fake.log`;
  refused in production), `DisabledWhatsAppGateway` (`WASENDER_MODE=off` or no key: skipped, email continues).
- WaSender send: `POST /send-message`, `Authorization: Bearer <session key>`, `{to: "+8801…", text}`; documents add
  `documentUrl` (the invoice share PDF link) and `fileName`. `retry_after` / 429 → rescheduled; "not connected" → retry in
  5 minutes and alert; key or subscription problems → retry in 15 minutes and alert.
- Webhook: the `X-Webhook-Signature` header must equal `WASENDER_WEBHOOK_SECRET` (`hash_equals`). Statuses only move
  forward (a late "sent" never undoes "read"). `msgId` ↔ WhatsApp `key.id` is linked from `message.sent` or
  `GET /messages/{msgId}/info` — still to be confirmed on the live account; until then "sent" is guaranteed and
  delivered/read are best effort.
- STOP / unsubscribe / বন্ধ opts the customer out (one confirmation message); START / চালু opts back in. Staff with
  `customers.manage` can toggle it on the booking page. Audited either way.
- Private booking tokens are masked in the admin log; verification codes are removed from the stored message once sent.

## 6. Admin

- **Notifications** (`notifications.manage`): connection card (provider, live session status with "Check connection
  now", key hint, webhook, pace, both numbers, email, counts, the unofficial-API note); **Templates** — the prototype's
  list + editor, per channel and language, variables to click in, a preview rendered by the server from the latest
  booking (sender line included), on/off, test to my WhatsApp / email; **Sales alerts** — recipients per alert from
  staff with verified numbers; **Message log** with filters and delivery ticks.
- **Booking page → WhatsApp & email**: every message about the booking with ✓ / ✓✓ / read ticks and reasons for
  anything waiting or not sent; **Send WhatsApp** (no number field; preview with the sender line; attach the issued
  invoice); the customer's WhatsApp on/off switch.
- **My profile**: a staff member's own WhatsApp number, confirmed with a six-digit code (hashed, 10 minutes, 5 tries,
  3 codes per 10 minutes).
- **Site settings → Contact**: the notifications number.

## 7. Endpoints

| Method | Path | Who |
|---|---|---|
| POST | `/api/v1/webhooks/wasender` | WaSender (secret header), 600/min |
| POST | `/api/v1/admin/notifications/whatsapp` | `notifications.send` |
| POST | `/api/v1/admin/customers/{id}/whatsapp-opt-out` | `customers.manage` |
| GET/POST/DELETE | `/api/v1/admin/profile/whatsapp`, POST `…/verify` | any signed-in staff, for themselves |
| GET | `/api/v1/admin/notifications/overview`, `/api/v1/admin/notifications` | `notifications.manage` |
| GET, PUT | `/api/v1/admin/notification-templates`, `…/{id}` | `notifications.manage` |
| POST | `/api/v1/admin/notification-templates/{id}/preview`, `…/test` | `notifications.manage` (test: 5/min, own number only) |
| GET, PUT | `/api/v1/admin/notification-settings` | `notifications.manage` (recipients must be verified) |

## 8. Tests

- API: `NotificationPlanningTest` (what each event sends and when, sender line, unpublished number, invoice attachment,
  trip schedule in Dhaka time, documents pending sent then skipped, cancellation, lead and seat alerts),
  `WhatsAppDeliveryTest` (WaSender request/response shapes, pacing, retries, opt-out and disabled templates at send
  time, webhook secret and forward-only statuses, session alerts once an hour, STOP / START), `NotificationAdminTest`
  (no typed numbers for any role, visibility, opt-out, 429, verification, verified-only alert lists, template variables,
  server preview, test sends, key never shown, the notifications number must differ from the main lines),
  `InvoicePdfTest` (still one A4 page with the notifications line in the footer).
- Admin e2e `notifications.spec.ts`: publish the number, verify a staff number with the code the fake gateway sent,
  edit a template with the preview, choose alert recipients, send a WhatsApp from a booking, turn a customer's WhatsApp
  off, permissions. Website e2e: the number beside the main one on the contact section, footer and booking page, and the
  customer's WhatsApp opening with the sender line.
- Before go-live: one manual run on the WaSender account with the rotated key (delivery ticks, a STOP reply, a
  disconnect alert).

## 9. Decisions (2026-09-13)

1. **Ten messages**, including documents pending 7 days before departure ("booking confirmed, departure in 7 days, a
   traveller has no passport number" — no visa module needed).
2. **A dedicated, warmed-up number** with Account Protection on, plus the trust requirements of §3.
3. **Staff send only to records they can already see**; server-side number lookup only, for every role.
4. **Per-alert recipient lists** of staff with verified numbers, plus the booking's assigned agent.

And: WaSenderAPI's unofficial status is a documented known risk with a named fallback (`deployment.md` §3.2); the
service is behind an interface with email always on, and every message that matters to money also goes by email.

## 10. SMS through bulksmsbd.net (added 2026-09-13)

SMS is **not a third parallel channel**. At roughly ৳0.35 a part it is the most expensive per send and the least rich
(no PDF, and Bangla goes as unicode: 70 characters, or 67 a part once split). It is used only where it beats WhatsApp.

| Case | What happens |
|---|---|
| **Fallback** — booking confirmation, payment received, 48-hour reminder, documents pending | When WhatsApp can't deliver to the customer, a short SMS with a short invoice link goes out instead of the message silently going email-only. Triggers: the WhatsApp send fails (any permanent failure, `not_on_whatsapp`, or retries exhausted); WhatsApp is unavailable (`WASENDER_MODE=off`, not configured, notifications number not published); the session needs a person (disconnected, key or subscription problem) — then the SMS goes **at once** and WhatsApp keeps retrying; WaSender reports the message failed later (the `message.sent` webhook with `success:false`, `messages.update` status 0 in either payload shape, or the message-status check 20 s after sending). |
| **Always SMS** — the departure-day 06:00 message | Planned with WhatsApp every time: at 6 a.m. on travel day the customer may have no data connection, and SMS reaches any handset. |
| **Never SMS** | The review request, invoice PDFs, booking received, staff messages and every sales alert. |
| **No fallback** | A customer who replied STOP to WhatsApp, a WhatsApp template staff switched off, a cancelled booking, a passport that has since been added. |

At most one SMS per message: its dedupe key is the WhatsApp message's plus `:sms`, so repeated webhooks, retries and
the status check can't send a second one. The SMS joins the message's group and points at the WhatsApp row it stands
in for.

**Numbers with no WhatsApp.** WaSender accepts a message for such a number and fails it afterwards ("Invalid number JID")
— there is no synchronous error to rely on, and its number-check endpoint is flagged high-risk for bans, so it isn't
used. A failure webhook names only the number: every WhatsApp message sent to that number in the last 15 minutes and
still marked sent is failed (none of them arrived), which also makes a repeated webhook harmless.

**The provider's API, handled rather than wrapped** (probed with a fake key, 2026-09-13):

- Their docs publish an `http://` URL with the key in the query string, and their code samples turn certificate checks
  off. The client uses **HTTPS with the certificate verified and POST with the key in the form body** — never GET, so the
  key never lands in a URL, access log, proxy or error report. bulksmsbd.net's certificate is valid (Let's Encrypt,
  TLS 1.3) and POST form fields are parsed, so no plain-http fallback is needed. Their server answers plain http too
  without redirecting, so there is **no runtime downgrade**: a TLS failure is retried and alerted (a downgrade would
  hand the key to whoever broke the connection), and an `http://` URL disables SMS.
- Every answer is HTTP 200 JSON; the outcome is `response_code`. 202 = submitted to the operator (shown as "Submitted",
  never "Delivered" — there is no delivery-report endpoint). On a wrong key the provider **echoes the key back** in
  `error_message`, so only codes are stored or logged. 1005 and 5xx: retry. 1011/1018/1031 (key or account),
  1002 (sender ID), 1006/1007 (validity, balance), 1013–1021 (account setup), 1032 (IP whitelist): retry in 15 minutes
  and email notification managers once an hour. 1001, 1003, 1012 and unknown codes: failed for that message. A timeout
  after the request went out is **not** retried — the SMS may already have been accepted and charged.
- **No approved sender ID → SMS disabled** (`sms_sender_id_missing`): operators silently drop messages from an unapproved
  ID, so nothing is sent from a blank one.

**Counting and cost.** `App\Support\Sms\SmsParts` counts on the exact text sent (GSM-7 160/153 septets, extension
characters 2; UCS-2 70/67 units, emoji 2), packing so escapes, surrogate pairs and — conservatively — Bangla conjuncts
are never split; ambiguous GSM 0x09 counts as unicode. 32 fixtures computed independently hold it
(`tests/Fixtures/sms-parts.json`). Each SMS stores its parts, encoding and estimated cost (parts ×
`BULKSMSBD_COST_PER_PART`); a message over `BULKSMSBD_MAX_PARTS` (6) isn't sent. The template editor shows parts,
encoding and cost from the server's own count of the filled preview, and warns above 3 parts.

**Short links.** An SMS carries `APP_URL/i/{10 characters}` instead of the 93-character share link (which alone would
take most of a Bangla part); the route redirects to the invoice share page, is rate limited, and sets no cookies.

**Admin.** SMS templates for the five SMS messages (bn + en, English kept inside the GSM alphabet); an SMS block on the
connection card (mode, sender ID or why SMS is off, key hint, HTTPS, rate); a cost column and this month's / last month's
cost per channel on the message log; the booking card shows **WhatsApp, email and SMS side by side for each message**,
each with its own status and reason, never one combined state.

**Open with the client** (none blocks the build; SMS stays off until they're answered):

1. **The approved sender ID.** Masking (brand name, ≤ 11 characters: e.g. "Bhabaghure") needs approval (bulksmsbd says
   3–5 working days, a Tk 500 fee, a 5,500-SMS minimum, ~৳0.45–0.55 an SMS) and bulksmsbd accepts only Bangla from it
   (code 1012) — set `BULKSMSBD_LOCALE=bn`. Non-masking (a numeric sender ID) needs no approval, costs ~৳0.25–0.35 and
   accepts English. BTRC's 2025 guideline allows English for transactional SMS either way.
2. **Bangla delivery.** Their docs show only `type=text`; if the first real Bangla SMS arrives garbled, set
   `BULKSMSBD_UNICODE_TYPE=unicode`.
3. **Billing.** Compare the balance drop of the first Bangla and English sends with the computed parts (some routes use
   152/66 per part); adjust `BULKSMSBD_COST_PER_PART` to the account's real price.
4. **Transactional flag.** BTRC requires aggregators to flag transactional SMS so Do-Not-Disturb doesn't block it; the
   API has no parameter for it — ask bulksmsbd how our traffic is classified.
