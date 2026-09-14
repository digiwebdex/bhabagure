<?php

namespace Tests\Concerns;

use App\Models\NotificationMessage;
use App\Models\SiteSetting;
use App\Models\Staff;
use App\Services\Invoices\InvoicePdf;
use App\Services\Invoices\InvoiceView;
use App\Services\Notifications\Sms\FakeSmsGateway;
use App\Services\Notifications\Sms\SmsGateway;
use App\Services\Notifications\WhatsApp\FakeWhatsAppGateway;
use App\Services\Notifications\WhatsApp\WhatsAppGateway;
use App\Services\Quotations\QuotationPdf;
use App\Services\Quotations\QuotationView;
use Database\Seeders\ContentSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/** Turns notifications on for a test: fake WhatsApp gateway, fake mail, seeded templates, a published notifications number. */
trait SendsNotifications
{
    public const NOTIFICATIONS_NUMBER = '+8801911000111';

    public const WEBHOOK_SECRET = 'test-webhook-secret-value';

    protected function sendNotifications(?string $notificationsNumber = self::NOTIFICATIONS_NUMBER): void
    {
        config([
            'bhabaghure.notifications.whatsapp.mode' => 'fake',
            'bhabaghure.notifications.whatsapp.seconds_between_sends' => 0,
            'bhabaghure.notifications.whatsapp.jitter_seconds' => 0,
            'bhabaghure.notifications.whatsapp.webhook_secret' => self::WEBHOOK_SECRET,
            'bhabaghure.notifications.email.enabled' => true,
            'bhabaghure.notifications.sms.mode' => 'fake',
            'bhabaghure.notifications.sms.cost_per_part' => 0.35,
            'bhabaghure.web_url' => 'https://bhabaghure.test',
            'bhabaghure.short_link_base' => 'https://api.bhabaghure.test',
        ]);
        $this->app->forgetInstance(WhatsAppGateway::class);
        $this->app->forgetInstance(SmsGateway::class);
        FakeWhatsAppGateway::$sent = [];
        FakeWhatsAppGateway::$status = 'connected';
        FakeSmsGateway::$sent = [];
        FakeSmsGateway::$next = null;
        Mail::fake();
        Cache::flush();

        $this->seed(ContentSeeder::class);
        $this->seed(NotificationTemplateSeeder::class);
        $contact = SiteSetting::get('contact', []);
        SiteSetting::query()->updateOrCreate(['key' => 'contact'], ['value' => ['notificationsWhatsapp' => $notificationsNumber ?? ''] + $contact]);

        // Invoice PDFs are measured in InvoicePdfTest; here any bytes will do.
        $this->app->instance(InvoicePdf::class, new class(app(InvoiceView::class)) extends InvoicePdf
        {
            public function pdf($invoice, bool $header, string $locale = 'bn', bool $maskPassports = false): string
            {
                return '%PDF-1.4 test invoice';
            }
        });
        $this->app->instance(QuotationPdf::class, new class(app(QuotationView::class), app(InvoicePdf::class)) extends QuotationPdf
        {
            public function pdf($quotation, bool $header, string $locale = 'bn'): string
            {
                return '%PDF-1.4 test quotation';
            }
        });
    }

    protected function verifiedStaff(string $role, string $whatsApp): Staff
    {
        $staff = $this->staff($role);
        $staff->forceFill(['whatsapp_number' => $whatsApp, 'whatsapp_verified_at' => now()])->save();

        return $staff;
    }

    /** @return list<array{0: string, 1: string, 2: string}> [event, channel, status] */
    protected function notificationRows(): array
    {
        return NotificationMessage::query()->orderBy('id')->get()
            ->map(fn ($row) => [$row->event->value, $row->channel->value, $row->status->value])->all();
    }
}
