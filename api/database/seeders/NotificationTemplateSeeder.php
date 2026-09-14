<?php

namespace Database\Seeders;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * Default message templates (docs/phase-4-whatsapp.md §2). Create-only: once staff edit a template on the Notification
 * templates screen, re-seeding never overwrites it.
 *
 * Bodies don't include the sender line — MessageRenderer adds "ভবঘুরে হলিডেজ · Bhabaghure Holidays" to every WhatsApp
 * message and to every email header. The first WhatsApp message to a customer carries no link (WaSenderAPI's
 * anti-ban guidance); the email carries the links.
 */
class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::defaults() as [$event, $channel, $bodyBn, $bodyEn, $subjectBn, $subjectEn]) {
            NotificationTemplate::query()->firstOrCreate(
                ['event' => $event->value, 'channel' => $channel->value],
                ['body_bn' => $bodyBn, 'body_en' => $bodyEn, 'subject_bn' => $subjectBn, 'subject_en' => $subjectEn, 'is_enabled' => true],
            );
        }
    }

    /** @return list<array{0: NotificationEvent, 1: NotificationChannel, 2: string, 3: string, 4: ?string, 5: ?string}> */
    public static function defaults(): array
    {
        $wa = NotificationChannel::WhatsApp;
        $mail = NotificationChannel::Email;
        $sms = NotificationChannel::Sms;

        return [
            [NotificationEvent::BookingCreated, $wa,
                "প্রিয় {{name}},\nআপনার {{package}} বুকিং আমরা পেয়েছি। রেফারেন্স {{ref}}, যাত্রা {{date}}, মোট {{total}}।\nপেমেন্ট সম্পন্ন হলে বুকিং নিশ্চিত হবে।",
                "Dear {{name}},\nwe have received your {{package}} booking. Reference {{ref}}, travelling {{date}}, total {{total}}.\nYour booking is confirmed once payment is complete.",
                null, null],
            [NotificationEvent::BookingCreated, $mail,
                "প্রিয় {{name}},\n\nআপনার {{package}} বুকিং আমরা পেয়েছি।\nরেফারেন্স: {{ref}}\nযাত্রা: {{date}}\nমোট: {{total}}\n\nবুকিং দেখতে ও পেমেন্ট করতে এই ব্যক্তিগত লিংকটি ব্যবহার করুন (কারো সাথে শেয়ার করবেন না):\n{{link}}\n\nপেমেন্ট সম্পন্ন হলে বুকিং নিশ্চিত হবে।",
                "Dear {{name}},\n\nwe have received your {{package}} booking.\nReference: {{ref}}\nTravelling: {{date}}\nTotal: {{total}}\n\nUse this private link to view your booking and pay (please don't share it):\n{{link}}\n\nYour booking is confirmed once payment is complete.",
                'বুকিং গ্রহণ করা হয়েছে · {{ref}}', 'We received your booking · {{ref}}'],

            [NotificationEvent::BookingConfirmed, $wa,
                "প্রিয় {{name}},\nআপনার {{package}} বুকিং নিশ্চিত হয়েছে। রেফারেন্স {{ref}}, যাত্রা {{date}}।\nপরিশোধিত {{paid}}, বকেয়া {{due}}। ইনভয়েস {{invoice}} সংযুক্ত করা হলো।",
                "Dear {{name}},\nyour {{package}} booking is confirmed. Reference {{ref}}, travelling {{date}}.\nPaid {{paid}}, balance due {{due}}. Invoice {{invoice}} is attached.",
                null, null],
            [NotificationEvent::BookingConfirmed, $mail,
                "প্রিয় {{name}},\n\nআপনার {{package}} বুকিং নিশ্চিত হয়েছে।\nরেফারেন্স: {{ref}}\nযাত্রা: {{date}}\nপরিশোধিত: {{paid}}\nবকেয়া: {{due}}\n\nইনভয়েস {{invoice}} এই ইমেইলে সংযুক্ত। অনলাইনে দেখতে: {{link}}",
                "Dear {{name}},\n\nyour {{package}} booking is confirmed.\nReference: {{ref}}\nTravelling: {{date}}\nPaid: {{paid}}\nBalance due: {{due}}\n\nInvoice {{invoice}} is attached to this email. View it online: {{link}}",
                'বুকিং নিশ্চিত · {{ref}}', 'Booking confirmed · {{ref}}'],

            [NotificationEvent::PaymentReceived, $wa,
                "প্রিয় {{name}},\n{{ref}} ({{package}}) বুকিংয়ের জন্য {{amount}} পেমেন্ট পাওয়া গেছে। মোট পরিশোধিত {{paid}}, বকেয়া {{due}}। ধন্যবাদ।",
                "Dear {{name}},\nwe received {{amount}} for booking {{ref}} ({{package}}). Paid so far {{paid}}, balance due {{due}}. Thank you.",
                null, null],
            [NotificationEvent::PaymentReceived, $mail,
                "প্রিয় {{name}},\n\n{{ref}} ({{package}}) বুকিংয়ের জন্য {{amount}} পেমেন্ট পাওয়া গেছে।\nমোট পরিশোধিত: {{paid}}\nবকেয়া: {{due}}\n\nইনভয়েস: {{link}}\n\nধন্যবাদ।",
                "Dear {{name}},\n\nwe received {{amount}} for booking {{ref}} ({{package}}).\nPaid so far: {{paid}}\nBalance due: {{due}}\n\nInvoice: {{link}}\n\nThank you.",
                'পেমেন্ট পাওয়া গেছে · {{ref}}', 'Payment received · {{ref}}'],

            [NotificationEvent::DocumentsPending, $wa,
                "প্রিয় {{name}},\n{{date}} তারিখে আপনার {{package}} যাত্রা ({{ref}})। এখনো পাসপোর্ট নম্বর পাওয়া যায়নি: {{travellers}}।\nটিকেট ও ভিসার কাজ শেষ করতে আজই আমাদের মূল নম্বরে যোগাযোগ করুন: {{office}}",
                "Dear {{name}},\nyour {{package}} trip ({{ref}}) starts on {{date}}, and we still don't have a passport number for: {{travellers}}.\nPlease contact our main line today so we can finish tickets and visas: {{office}}",
                null, null],
            [NotificationEvent::DocumentsPending, $mail,
                "প্রিয় {{name}},\n\n{{date}} তারিখে আপনার {{package}} যাত্রা ({{ref}})। এখনো পাসপোর্ট নম্বর পাওয়া যায়নি:\n{{travellers}}\n\nপাসপোর্টের তথ্য ছাড়া টিকেট ও ভিসার কাজ শেষ করা যাবে না। আজই আমাদের মূল নম্বরে যোগাযোগ করুন: {{office}}",
                "Dear {{name}},\n\nyour {{package}} trip ({{ref}}) starts on {{date}}, and we still don't have a passport number for:\n{{travellers}}\n\nWithout the passport details we can't finish tickets and visas. Please contact our main line today: {{office}}",
                'পাসপোর্টের তথ্য বাকি · {{ref}}', 'Passport details missing · {{ref}}'],

            [NotificationEvent::PreTripReminder, $wa,
                "প্রিয় {{name}},\n{{date}} তারিখে আপনার {{package}} যাত্রা ({{ref}})। পাসপোর্ট, টিকেট ও প্রয়োজনীয় কাগজ গুছিয়ে রাখুন।\nগ্রুপ লিডার: {{leader}}। যেকোনো প্রয়োজনে: {{office}}",
                "Dear {{name}},\nyour {{package}} trip ({{ref}}) starts on {{date}}. Please keep your passport, tickets and documents ready.\nGroup leader: {{leader}}. For anything you need: {{office}}",
                null, null],
            [NotificationEvent::PreTripReminder, $mail,
                "প্রিয় {{name}},\n\n{{date}} তারিখে আপনার {{package}} যাত্রা ({{ref}})।\nপাসপোর্ট, টিকেট ও প্রয়োজনীয় কাগজ গুছিয়ে রাখুন।\n\nগ্রুপ লিডার: {{leader}}\nযেকোনো প্রয়োজনে: {{office}}",
                "Dear {{name}},\n\nyour {{package}} trip ({{ref}}) starts on {{date}}.\nPlease keep your passport, tickets and documents ready.\n\nGroup leader: {{leader}}\nFor anything you need: {{office}}",
                'যাত্রার রিমাইন্ডার · {{ref}}', 'Trip reminder · {{ref}}'],

            [NotificationEvent::DepartureToday, $wa,
                "প্রিয় {{name}},\nআজ আপনার {{package}} যাত্রা। শুভ যাত্রা! যেকোনো প্রয়োজনে: {{office}}",
                "Dear {{name}},\nyour {{package}} trip starts today. Have a wonderful journey! For anything you need: {{office}}",
                null, null],

            // SMS: short on purpose. Bangla goes as unicode (70 characters a part, 67 once split), so no package titles;
            // English avoids characters outside the GSM alphabet (·, —, ৳) that would switch it to unicode too.
            [NotificationEvent::BookingConfirmed, $sms,
                'বুকিং {{ref}} নিশ্চিত। যাত্রা {{date}}, বকেয়া {{due}}। ইনভয়েস: {{short_link}}',
                'Booking {{ref}} confirmed. Travel {{date}}, balance due {{due}}. Invoice: {{short_link}}',
                null, null],
            [NotificationEvent::PaymentReceived, $sms,
                '{{ref}}: {{amount}} পেমেন্ট পাওয়া গেছে। বকেয়া {{due}}। ইনভয়েস: {{short_link}}',
                '{{ref}}: payment of {{amount}} received. Balance due {{due}}. Invoice: {{short_link}}',
                null, null],
            [NotificationEvent::DocumentsPending, $sms,
                '{{ref}}: {{travellers}}-এর পাসপোর্ট নম্বর এখনো পাইনি। আজই কল করুন: {{office}}',
                '{{ref}}: passport number still missing for {{travellers}}. Please call us today: {{office}}',
                null, null],
            [NotificationEvent::PreTripReminder, $sms,
                '{{ref}}: {{date}} আপনার যাত্রা। পাসপোর্ট ও টিকেট সঙ্গে রাখুন। প্রয়োজনে: {{office}}',
                '{{ref}}: your trip starts {{date}}. Keep your passport and tickets ready. Help: {{office}}',
                null, null],
            [NotificationEvent::DepartureToday, $sms,
                'আজ আপনার যাত্রা ({{ref}})। শুভ যাত্রা! প্রয়োজনে: {{office}}',
                'Your trip {{ref}} starts today. Have a great journey! Help: {{office}}',
                null, null],

            [NotificationEvent::TripCompleted, $wa,
                "প্রিয় {{name}},\n{{package}} ভ্রমণে আমাদের সাথে থাকার জন্য ধন্যবাদ। আপনার অভিজ্ঞতা জানালে আমরা খুব খুশি হব: {{review}}",
                "Dear {{name}},\nthank you for travelling {{package}} with us. A short review would mean a lot: {{review}}",
                null, null],

            [NotificationEvent::NewBookingAlert, $wa,
                "নতুন বুকিং {{ref}}\n{{package}} · {{date}} · {{pax}} জন\nমোট {{total}} · {{payment}}\nগ্রাহক: {{customer}} {{phone}}",
                "New booking {{ref}}\n{{package}} · {{date}} · {{pax}} travellers\nTotal {{total}} · {{payment}}\nCustomer: {{customer}} {{phone}}",
                null, null],
            [NotificationEvent::NewBookingAlert, $mail,
                "নতুন বুকিং {{ref}}\n\n{{package}} · {{date}} · {{pax}} জন\nমোট {{total}} · {{payment}}\nগ্রাহক: {{customer}} {{phone}}",
                "New booking {{ref}}\n\n{{package}} · {{date}} · {{pax}} travellers\nTotal {{total}} · {{payment}}\nCustomer: {{customer}} {{phone}}",
                'নতুন বুকিং · {{ref}}', 'New booking · {{ref}}'],

            [NotificationEvent::NewLeadAlert, $wa,
                "নতুন লিড: {{name}} {{phone}}\n{{kind}}: {{details}}",
                "New lead: {{name}} {{phone}}\n{{kind}}: {{details}}",
                null, null],
            [NotificationEvent::NewLeadAlert, $mail,
                "নতুন লিড: {{name}} {{phone}}\n\n{{kind}}:\n{{details}}",
                "New lead: {{name}} {{phone}}\n\n{{kind}}:\n{{details}}",
                'নতুন লিড · {{name}}', 'New lead · {{name}}'],

            [NotificationEvent::LowSeatAlert, $wa,
                'সিট প্রায় শেষ: {{package}} · {{date}} — আর মাত্র {{seats}}টি সিট খালি।',
                'Seats almost gone: {{package}} · {{date}} — only {{seats}} left.',
                null, null],
            [NotificationEvent::LowSeatAlert, $mail,
                "সিট প্রায় শেষ: {{package}} · {{date}}\nআর মাত্র {{seats}}টি সিট খালি।",
                "Seats almost gone: {{package}} · {{date}}\nOnly {{seats}} seats left.",
                'সিট প্রায় শেষ · {{package}}', 'Seats almost gone · {{package}}'],
        ];
    }
}
