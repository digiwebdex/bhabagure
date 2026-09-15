<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/phase-8-visa-quotes-pricing-downloads.md §4.F: the "booking received" WhatsApp and email list how to pay
 * ({{how_to_pay}}). Only a template still worded as shipped is changed; one staff edited keeps their wording, and they
 * can insert the variable themselves on the Notifications screen.
 */
return new class extends Migration
{
    /** [channel, field, as shipped, with the payment options] */
    private const CHANGES = [
        ['whatsapp', 'body_bn',
            "প্রিয় {{name}},\nআপনার {{package}} বুকিং আমরা পেয়েছি। রেফারেন্স {{ref}}, যাত্রা {{date}}, মোট {{total}}।\nপেমেন্ট সম্পন্ন হলে বুকিং নিশ্চিত হবে।",
            "প্রিয় {{name}},\nআপনার {{package}} বুকিং আমরা পেয়েছি। রেফারেন্স {{ref}}, যাত্রা {{date}}, মোট {{total}}।\nপেমেন্ট সম্পন্ন হলে বুকিং নিশ্চিত হবে।\n\n{{how_to_pay}}"],
        ['whatsapp', 'body_en',
            "Dear {{name}},\nwe have received your {{package}} booking. Reference {{ref}}, travelling {{date}}, total {{total}}.\nYour booking is confirmed once payment is complete.",
            "Dear {{name}},\nwe have received your {{package}} booking. Reference {{ref}}, travelling {{date}}, total {{total}}.\nYour booking is confirmed once payment is complete.\n\n{{how_to_pay}}"],
        ['email', 'body_bn',
            "প্রিয় {{name}},\n\nআপনার {{package}} বুকিং আমরা পেয়েছি।\nরেফারেন্স: {{ref}}\nযাত্রা: {{date}}\nমোট: {{total}}\n\nবুকিং দেখতে ও পেমেন্ট করতে এই ব্যক্তিগত লিংকটি ব্যবহার করুন (কারো সাথে শেয়ার করবেন না):\n{{link}}\n\nপেমেন্ট সম্পন্ন হলে বুকিং নিশ্চিত হবে।",
            "প্রিয় {{name}},\n\nআপনার {{package}} বুকিং আমরা পেয়েছি।\nরেফারেন্স: {{ref}}\nযাত্রা: {{date}}\nমোট: {{total}}\n\nবুকিং দেখতে ও পেমেন্ট করতে এই ব্যক্তিগত লিংকটি ব্যবহার করুন (কারো সাথে শেয়ার করবেন না):\n{{link}}\n\n{{how_to_pay}}\n\nপেমেন্ট সম্পন্ন হলে বুকিং নিশ্চিত হবে।"],
        ['email', 'body_en',
            "Dear {{name}},\n\nwe have received your {{package}} booking.\nReference: {{ref}}\nTravelling: {{date}}\nTotal: {{total}}\n\nUse this private link to view your booking and pay (please don't share it):\n{{link}}\n\nYour booking is confirmed once payment is complete.",
            "Dear {{name}},\n\nwe have received your {{package}} booking.\nReference: {{ref}}\nTravelling: {{date}}\nTotal: {{total}}\n\nUse this private link to view your booking and pay (please don't share it):\n{{link}}\n\n{{how_to_pay}}\n\nYour booking is confirmed once payment is complete."],
    ];

    public function up(): void
    {
        foreach (self::CHANGES as [$channel, $field, $shipped, $updated]) {
            DB::table('notification_templates')->where('event', 'booking_created')->where('channel', $channel)->where($field, $shipped)
                ->update([$field => $updated, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::CHANGES as [$channel, $field, $shipped, $updated]) {
            DB::table('notification_templates')->where('event', 'booking_created')->where('channel', $channel)->where($field, $updated)
                ->update([$field => $shipped, 'updated_at' => now()]);
        }
    }
};
