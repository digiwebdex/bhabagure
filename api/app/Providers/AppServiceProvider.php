<?php

namespace App\Providers;

use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Events\BookingOwnerChanged;
use App\Events\CashEntryReversed;
use App\Listeners\PlanNotifications;
use App\Models\Account;
use App\Models\Addon;
use App\Models\AttendanceDevice;
use App\Models\AuditLog;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Customer;
use App\Models\Destination;
use App\Models\GalleryItem;
use App\Models\Inquiry;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Media;
use App\Models\NotificationMessage;
use App\Models\NotificationTemplate;
use App\Models\OpeningBalance;
use App\Models\PackageDeparture;
use App\Models\PassportScan;
use App\Models\PaymentAttempt;
use App\Models\Quotation;
use App\Models\ReferencePreset;
use App\Models\Review;
use App\Models\SiteSetting;
use App\Models\Staff;
use App\Models\StaffDocument;
use App\Models\SupportTicket;
use App\Models\TeamMember;
use App\Models\TourPackage;
use App\Models\Transaction;
use App\Models\TravellerDocument;
use App\Models\VisaService;
use App\Services\Bonus\BonusDesk;
use App\Services\Bonus\CommissionDesk;
use App\Services\Notifications\Sms\BulkSmsBdGateway;
use App\Services\Notifications\Sms\DisabledSmsGateway;
use App\Services\Notifications\Sms\FakeSmsGateway;
use App\Services\Notifications\Sms\SmsGateway;
use App\Services\Notifications\WhatsApp\DisabledWhatsAppGateway;
use App\Services\Notifications\WhatsApp\FakeWhatsAppGateway;
use App\Services\Notifications\WhatsApp\WaSenderGateway;
use App\Services\Notifications\WhatsApp\WhatsAppGateway;
use App\Services\Passports\PassportTextReader;
use App\Services\Passports\TextractPassportTextReader;
use App\Services\Passports\UnavailablePassportTextReader;
use App\Services\Payments\PaymentsNotConfigured;
use App\Services\Payments\SslCommerz\FakeSslCommerzGateway;
use App\Services\Payments\SslCommerz\HttpSslCommerzGateway;
use App\Services\Payments\SslCommerz\SslCommerzGateway;
use App\Services\Payroll\PayrollDesk;
use App\Support\Database\LedgerQueryGuard;
use Aws\Textract\TextractClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use PHPOpenSourceSaver\JWTAuth\Http\Parser\AuthHeaders;
use RuntimeException;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PassportTextReader::class, function ($app) {
            $config = $app['config']['bhabaghure.passport_ocr'];
            if ($config['provider'] !== 'textract' || empty($config['aws']['key']) || empty($config['aws']['secret'])) {
                return new UnavailablePassportTextReader;
            }

            return new TextractPassportTextReader(new TextractClient([
                'version' => '2018-06-27',
                'region' => $config['aws']['region'],
                'credentials' => ['key' => $config['aws']['key'], 'secret' => $config['aws']['secret']],
                'retries' => 0,
                'http' => ['timeout' => $config['timeout_seconds'], 'connect_timeout' => 3],
            ]));
        });

        $this->app->singleton(WhatsAppGateway::class, function ($app) {
            $config = $app['config']['bhabaghure.notifications.whatsapp'];

            return match ($config['mode']) {
                'live' => empty($config['api_key'])
                    ? new DisabledWhatsAppGateway('whatsapp_not_configured')
                    : new WaSenderGateway((string) $config['base_url'], (string) $config['api_key'], (int) $config['timeout_seconds']),
                'fake' => $app->isProduction()
                    ? throw new RuntimeException('WASENDER_MODE=fake is refused in production.')
                    : new FakeWhatsAppGateway,
                'off' => new DisabledWhatsAppGateway,
                default => throw new RuntimeException("Unknown WASENDER_MODE {$config['mode']}."),
            };
        });

        $this->app->singleton(SmsGateway::class, function ($app) {
            $config = $app['config']['bhabaghure.notifications.sms'];

            return match ($config['mode']) {
                // No key, or no approved sender ID: disabled with the reason, never a send from a blank sender ID.
                'live' => match (true) {
                    blank($config['api_key']) => new DisabledSmsGateway('sms_not_configured'),
                    blank($config['sender_id']) => new DisabledSmsGateway('sms_sender_id_missing'),
                    ! str_starts_with((string) $config['url'], 'https://') => new DisabledSmsGateway('sms_insecure_url'),
                    default => new BulkSmsBdGateway((string) $config['url'], (string) $config['api_key'], (string) $config['sender_id'], (int) $config['timeout_seconds'], (string) $config['unicode_type']),
                },
                'fake' => $app->isProduction()
                    ? throw new RuntimeException('BULKSMSBD_MODE=fake is refused in production.')
                    : new FakeSmsGateway,
                'off' => new DisabledSmsGateway,
                default => throw new RuntimeException("Unknown BULKSMSBD_MODE {$config['mode']}."),
            };
        });

        $this->app->singleton(SslCommerzGateway::class, function ($app) {
            $config = $app['config']['bhabaghure.sslcommerz'];

            return match ($config['mode']) {
                'fake' => $app->isProduction()
                    ? throw new RuntimeException('SSLCOMMERZ_MODE=fake is refused in production.')
                    : new FakeSslCommerzGateway,
                'sandbox', 'live' => $config['mode'] === 'live' && ! $app->isProduction()
                    ? throw new RuntimeException('SSLCOMMERZ_MODE=live needs APP_ENV=production.')
                    : new HttpSslCommerzGateway(
                        $config['hosts'][$config['mode']],
                        (string) ($config['store_id'] ?: throw new PaymentsNotConfigured('SSLCOMMERZ_STORE_ID is not set.')),
                        (string) ($config['store_password'] ?: throw new PaymentsNotConfigured('SSLCOMMERZ_STORE_PASSWORD is not set.')),
                        (int) $config['timeout_seconds'],
                    ),
                default => throw new RuntimeException("Unknown SSLCOMMERZ_MODE {$config['mode']}."),
            };
        });
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Stable names in polymorphic columns (audit_logs, notifications) instead of PHP class names.
        Relation::enforceMorphMap([
            'staff' => Staff::class,
            'customer' => Customer::class,
            'booking' => Booking::class,
            'invoice' => Invoice::class,
            'transaction' => Transaction::class,
            'tour_package' => TourPackage::class,
            'blog_post' => BlogPost::class,
            'team_member' => TeamMember::class,
            'review' => Review::class,
            'gallery_item' => GalleryItem::class,
            'visa_service' => VisaService::class,
            'media' => Media::class,
            'site_setting' => SiteSetting::class,
            'audit_log' => AuditLog::class,
            'destination' => Destination::class,
            'package_departure' => PackageDeparture::class,
            'blog_category' => BlogCategory::class,
            'addon' => Addon::class,
            'payment_attempt' => PaymentAttempt::class,
            'journal_entry' => JournalEntry::class,
            'passport_scan' => PassportScan::class,
            'inquiry' => Inquiry::class,
            'notification' => NotificationMessage::class,
            'notification_template' => NotificationTemplate::class,
            'quotation' => Quotation::class,
            'support_ticket' => SupportTicket::class,
            'traveller_document' => TravellerDocument::class,
            // Journal sources for opening balances; deal invoices billed to a company.
            'account' => Account::class,
            'client' => Client::class,
            'opening_balance' => OpeningBalance::class,
            'reference_preset' => ReferencePreset::class,
            // Audit rows about permission changes (permissions:sync and the Roles screen).
            'role' => Role::class,
            // Staff document expiry alerts (docs/phase-7-hr-attendance-bonus-wallet.md §4.2).
            'staff_document' => StaffDocument::class,
            // Attendance devices: audit rows and offline alerts (§5).
            'attendance_device' => AttendanceDevice::class,
        ]);

        // Ledger tables are append-only on every connection, whichever way SQL is sent (LedgerTables).
        foreach (DB::getConnections() as $connection) {
            LedgerQueryGuard::register($connection);
        }
        Event::listen(ConnectionEstablished::class, fn (ConnectionEstablished $event) => LedgerQueryGuard::register($event->connection));

        // Bookings, payments and website forms → WhatsApp and email notifications (docs/phase-4-whatsapp.md).
        Event::subscribe(PlanNotifications::class);
        // A reversed salary or bonus cash-out leaves that month's pay unpaid, or puts the bonus back, in the same
        // transaction (docs/phase-7-hr-attendance-bonus-wallet.md §6, §7).
        Event::listen(CashEntryReversed::class, [PayrollDesk::class, 'onCashEntryReversed']);
        Event::listen(CashEntryReversed::class, [BonusDesk::class, 'onCashEntryReversed']);
        // Commission follows a booking's status and owner, once the change is committed (§12 step 4).
        Event::listen([BookingConfirmed::class, BookingCancelled::class, BookingOwnerChanged::class], [CommissionDesk::class, 'onBookingChanged']);

        // Super admin passes every check, independent of the permission matrix (docs/phase-1-schema.md §5).
        Gate::before(fn ($user) => $user instanceof Staff && $user->isSuperAdmin() ? true : null);

        // Access tokens only from the Authorization header — never a query string (logs) or a cookie (CSRF).
        $this->app->make('tymon.jwt.parser')->setChain([new AuthHeaders]);

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('public-forms', fn (Request $request) => [
            Limit::perMinute(5)->by('forms-minute|'.$request->ip()),
            Limit::perDay(40)->by('forms-day|'.$request->ip()),
        ]);

        RateLimiter::for('newsletter-unsubscribe', fn (Request $request) => Limit::perMinute(30)->by('unsubscribe|'.$request->ip()));

        RateLimiter::for('public-bookings', fn (Request $request) => [
            Limit::perMinute(10)->by('bookings-minute|'.$request->ip()),
            Limit::perDay(100)->by('bookings-day|'.$request->ip()),
        ]);

        RateLimiter::for('passport-scans', fn (Request $request) => [
            Limit::perMinute(20)->by('scans-minute|'.$request->ip()),
            Limit::perDay(200)->by('scans-day|'.$request->ip()),
        ]);

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)->by('webhooks|'.$request->ip()));

        // Short invoice links from SMS: a customer opens one a few times; guessing codes needs millions of tries.
        RateLimiter::for('short-links', fn (Request $request) => [
            Limit::perMinute(20)->by('short-links-minute|'.$request->ip()),
            Limit::perDay(200)->by('short-links-day|'.$request->ip()),
        ]);

        RateLimiter::for('payment-callbacks', fn (Request $request) => Limit::perMinute(120)->by('payments|'.$request->ip()));

        RateLimiter::for('public-read', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));

        // Portal sign-in codes: the per-number limits live in LoginCodes; these stop one address trying many numbers.
        RateLimiter::for('customer-codes', fn (Request $request) => [
            Limit::perMinute(5)->by('codes-minute|'.$request->ip()),
            Limit::perDay(40)->by('codes-day|'.$request->ip()),
        ]);
        RateLimiter::for('customer-verify', fn (Request $request) => Limit::perMinute(20)->by('verify|'.$request->ip()));
        RateLimiter::for('portal', fn (Request $request) => Limit::perMinute(120)->by('portal|'.($request->user('customer')?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('portal-support', fn (Request $request) => [
            Limit::perMinute(6)->by('portal-support-minute|'.$request->user('customer')?->getAuthIdentifier()),
            Limit::perDay(60)->by('portal-support-day|'.$request->user('customer')?->getAuthIdentifier()),
        ]);
        RateLimiter::for('portal-uploads', fn (Request $request) => [
            Limit::perMinute(10)->by('portal-uploads-minute|'.$request->user('customer')?->getAuthIdentifier()),
            Limit::perDay(60)->by('portal-uploads-day|'.$request->user('customer')?->getAuthIdentifier()),
        ]);

        RateLimiter::for('auth-refresh', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        // Staff invitation and reset links are 256-bit tokens; this only stops one address hammering the endpoint.
        RateLimiter::for('staff-invitations', fn (Request $request) => Limit::perMinute(10)->by('staff-invitations|'.$request->ip()));
        // The office agent: a check-in a minute, a report, and batches — a full resend of a large device log is a few
        // hundred batches, which this lets through in a couple of minutes.
        RateLimiter::for('attendance-agent', fn (Request $request) => Limit::perMinute(300)->by('attendance-agent|'.($request->attributes->get('attendance_device')?->id ?? $request->ip())));

        RateLimiter::for('media-upload', fn (Request $request) => Limit::perMinute(60)->by('upload|'.($request->user('staff')?->id ?? $request->ip())));
    }
}
