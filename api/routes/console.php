<?php

use App\Enums\BookingStatus;
use App\Http\Controllers\Api\V1\Admin\AttendanceDeviceController;
use App\Models\AttendanceDevice;
use App\Models\AttendanceRule;
use App\Models\Holiday;
use App\Enums\NotificationStatus;
use App\Enums\PaymentAttemptStatus;
use App\Jobs\DeliverNotification;
use App\Models\Booking;
use App\Models\NotificationMessage;
use App\Models\PaymentAttempt;
use App\Models\Quotation;
use App\Models\StaffDocument;
use App\Models\Transaction;
use App\Services\Booking\BookingStateMachine;
use App\Services\Ledger\LedgerService;
use App\Services\Notifications\AdminAlerts;
use App\Services\Notifications\NotificationPlanner;
use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\WhatsApp\WhatsAppGateway;
use App\Services\Passports\PassportScanner;
use App\Services\Payments\PaymentService;
use App\Services\Payments\PaymentsNotConfigured;
use App\Support\Queue\QueuePreflight;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

// SSLCommerz attempts nobody came back from: settle them if money was taken, otherwise expire and free the seats.
Artisan::command('payments:reconcile', function () {
    try {
        $payments = app(PaymentService::class);
    } catch (PaymentsNotConfigured $e) {
        // No store credentials: no payment can have started, so there is nothing to settle and nothing to report every
        // ten minutes. An attempt left open from before the credentials were removed is different — only SSLCommerz
        // can say whether that customer's money was taken.
        $open = PaymentAttempt::query()->whereIn('status', [PaymentAttemptStatus::Initiated, PaymentAttemptStatus::Redirected])->count();
        if ($open === 0) {
            $this->line('SSLCommerz is not configured; no payment attempts to reconcile.');

            return 0;
        }
        $this->error("{$open} open payment attempt(s) cannot be reconciled: {$e->getMessage()}");

        return 1;
    }

    $this->info("Checked {$payments->reconcile()} payment attempt(s).");

    return 0;
})->purpose('Settle or expire SSLCommerz payments that never called back');

Schedule::command('payments:reconcile')->everyTenMinutes()->withoutOverlapping()->onOneServer();

// A confirmed booking whose trip has ended becomes completed (docs/phase-3-booking.md §3).
Artisan::command('bookings:complete-travelled', function (BookingStateMachine $machine) {
    $count = 0;
    Booking::query()->where('status', BookingStatus::Confirmed)->whereNotNull('travel_end')
        ->whereDate('travel_end', '<', now('Asia/Dhaka')->toDateString())
        ->orderBy('id')->each(function (Booking $booking) use ($machine, &$count) {
            $machine->complete($booking);
            $count++;
        });
    $this->info("Completed {$count} booking(s).");
})->purpose('Mark confirmed bookings completed after their travel end date');

Schedule::command('bookings:complete-travelled')->dailyAt('02:30')->timezone('Asia/Dhaka')->onOneServer();

// docs/phase-3-booking.md §3: paid_amount is a cache of the cash book. If it ever differs from the ledger, the people
// who see the company balance are told (once a day) — nothing is corrected silently.
Artisan::command('bookings:check-paid', function () {
    $ledger = Transaction::query()->where('category', LedgerService::CATEGORY_PAYMENT)->groupBy('booking_id')
        ->selectRaw("booking_id, SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) AS paid");
    $drifted = DB::table('bookings')->leftJoinSub($ledger, 'ledger', 'ledger.booking_id', '=', 'bookings.id')
        ->whereRaw('bookings.paid_amount <> COALESCE(ledger.paid, 0)')
        ->orderBy('bookings.id')->limit(50)->pluck('bookings.reference');

    if ($drifted->isEmpty()) {
        $this->info('Every booking\'s paid amount matches the cash book.');

        return 0;
    }
    AdminAlerts::once('paid-drift:'.now('Asia/Dhaka')->toDateString(),
        'Paid amount differs from the cash book for: '.$drifted->implode(', ').'. Check before recording further payments on them.',
        'ledger.view_company_balance');
    $this->error("Paid amount differs from the cash book: {$drifted->implode(', ')}");

    return 1;
})->purpose('Compare every booking\'s paid amount with the cash book and alert on any difference');

Schedule::command('bookings:check-paid')->dailyAt('03:15')->timezone('Asia/Dhaka')->onOneServer();

// docs/phase-6-customer-portal.md §3.2: a sent quotation's customer is reminded once, within the last 24 hours before
// it stops being honoured (the end of valid_until in Dhaka). One sent less than 12 hours ago gets no reminder on top.
Artisan::command('quotations:remind-expiring', function (NotificationPlanner $planner) {
    // Honoured to the end of valid_until, so its last 24 hours are that day in Dhaka.
    $count = 0;
    Quotation::query()->where('status', Quotation::SENT)->whereNull('expiry_reminded_at')
        ->whereDate('valid_until', now('Asia/Dhaka')->toDateString())
        ->where('sent_at', '<=', now()->subHours(12))
        ->orderBy('id')->each(function (Quotation $quotation) use ($planner, &$count) {
            DB::transaction(function () use ($quotation, $planner, &$count) {
                if (Quotation::query()->whereKey($quotation->id)->whereNull('expiry_reminded_at')->update(['expiry_reminded_at' => now()]) === 1) {
                    $planner->quotationExpiring($quotation);
                    $count++;
                }
            });
        });
    $this->info("Reminded {$count} quotation(s).");
})->purpose('Remind customers 24 hours before a sent quotation expires');

Schedule::command('quotations:remind-expiring')->hourly()->onOneServer();

// docs/phase-7-hr-attendance-bonus-wallet.md §4.2: a staff document is alerted once when it comes within 30 days of its
// expiry and once on the day. Ranges rather than exact dates, so a day the scheduler missed is caught up; the
// notifications' dedupe keys keep each alert to once. Suspended staff (leavers) are left out, as on the Vault badge.
Artisan::command('staff-documents:remind-expiring', function (NotificationPlanner $planner) {
    $today = StaffDocument::today();
    $count = 0;
    StaffDocument::query()->current()->withStatus(StaffDocument::ATTENTION, $today)->with('staff')->orderBy('id')
        ->each(function (StaffDocument $document) use ($planner, $today, &$count) {
            $planner->staffDocumentExpiring($document, $document->expires_on->toDateString() <= $today->toDateString() ? 'due' : 'soon');
            $count++;
        });
    $this->info("Checked {$count} staff document(s) needing attention.");
})->purpose('Alert staff 30 days before a staff document expires, and on the day');

Schedule::command('staff-documents:remind-expiring')->dailyAt('09:00')->timezone('Asia/Dhaka')->onOneServer();

// docs/phase-7-hr-attendance-bonus-wallet.md §5.1: an attendance device with no good pull for an hour, during duty hours on
// a working day, alerts once per outage — saying whether the office PC went quiet or can't reach the device. A good pull
// clears the flag (AgentGateway::report), so the next outage alerts again. Outside duty hours a silent PC is normal.
Artisan::command('attendance:watch-devices', function (NotificationPlanner $planner) {
    $now = now('Asia/Dhaka');
    $rules = AttendanceRule::forMonth($now->format('Y-m'));
    $working = ! in_array($now->dayOfWeekIso, $rules->weekly_off_days, true) && ! Holiday::query()->whereDate('date', $now->toDateString())->exists();
    // From an hour into the duty day to its end: a PC switched on at 11:00 isn't an outage at 11:05.
    $time = $now->format('H:i:s');
    $watchFrom = $now->copy()->setTimeFromTimeString((string) $rules->duty_start)->addHour()->format('H:i:s');
    if (! $working || $time < $watchFrom || $time > (string) $rules->duty_end) {
        return;
    }

    $count = 0;
    AttendanceDevice::query()->active()->whereNotNull('serial_number')->whereNull('offline_alerted_at')
        ->where(fn ($query) => $query->whereNull('last_pull_ok_at')->orWhere('last_pull_ok_at', '<', now()->subHour()))
        ->each(function (AttendanceDevice $device) use ($planner, &$count) {
            $silent = $device->last_check_in_at === null || $device->last_check_in_at->lt(now()->subMinutes(AttendanceDeviceController::SILENT_MINUTES));
            $device->forceFill(['offline_alerted_at' => now()])->save();
            $planner->attendanceDeviceOffline($device, $silent ? 'pc_silent' : 'device_unreachable');
            $count++;
        });
    $this->info("Alerted about {$count} silent attendance device(s).");
})->purpose('Alert when an attendance device has been silent for an hour of duty time');

Schedule::command('attendance:watch-devices')->everyFiveMinutes()->onOneServer();

// Passport scans no booking used are deleted after their retention window (config bhabaghure.passport_ocr).
Artisan::command('passport-scans:prune', function (PassportScanner $scanner) {
    $this->info("Deleted {$scanner->prune()} unused passport scan(s).");
})->purpose('Delete passport scans that no booking used');

Schedule::command('passport-scans:prune')->hourly()->onOneServer();

// Run by bhabaghure-queue.service before the worker starts (docs/deployment.md §5). Exit code 1 stops the unit.
Artisan::command('bhabaghure:queue-preflight {--redis-db= : Redis database reserved for the queue} {--redis-cache-db= : Redis database reserved for the cache} {--job-timeout= : queue:work --timeout in seconds}', function () {
    $options = [];
    foreach (['redis-db', 'redis-cache-db', 'job-timeout'] as $name) {
        $value = (string) $this->option($name);
        if (! ctype_digit($value)) {
            $this->error("--{$name}=<number> is required.");

            return 2;
        }
        $options[$name] = (int) $value;
    }

    $problems = QueuePreflight::configProblems($options['redis-db'], $options['redis-cache-db'], $options['job-timeout']);
    if ($problems === []) {
        $problems = QueuePreflight::redisProblems($options['redis-db'], $options['redis-cache-db']);
    }
    foreach ($problems as $problem) {
        $this->error($problem);
    }
    if ($problems !== []) {
        return 1;
    }

    $this->info("Queue preflight passed: Redis databases {$options['redis-db']} (queue) and {$options['redis-cache-db']} (cache) hold only '".QueuePreflight::PREFIX."' keys.");

    return 0;
})->purpose('Refuse to start the queue worker unless it is on this project\'s own Redis databases');

// Notifications whose time has come (scheduled trip messages, paced or retried sends) go to the delivery job.
Artisan::command('notifications:dispatch', function () {
    // A send that never finished (the worker stopped mid-way) is tried again.
    NotificationMessage::query()->where('status', NotificationStatus::Sending)->where('updated_at', '<', now()->subMinutes(15))
        ->update(['status' => NotificationStatus::Pending, 'updated_at' => now()]);

    // Due for a quarter of an hour and still unclaimed: no worker is taking jobs (the unit gave up, or isn't running).
    // The alert mail is sent from here, so it goes out even with the worker down.
    if (config('queue.default') !== 'sync') {
        $stalled = NotificationMessage::query()->where('status', NotificationStatus::Pending)->where('scheduled_for', '<', now()->subMinutes(15))->count();
        if ($stalled > 0) {
            AdminAlerts::once('queue-stalled', "{$stalled} notification(s) have been due for over 15 minutes without being sent: the queue worker isn't taking jobs. On the server: systemctl status bhabaghure-queue.service");
        }
    }

    $ids = NotificationMessage::query()->where('status', NotificationStatus::Pending)->where('scheduled_for', '<=', now())
        ->orderBy('scheduled_for')->limit(200)->pluck('id');
    foreach ($ids as $id) {
        DeliverNotification::dispatch($id);
    }
    $this->info("Dispatched {$ids->count()} notification(s).");
})->purpose('Send notifications that are due');

Schedule::command('notifications:dispatch')->everyMinute()->withoutOverlapping()->onOneServer();

// The WhatsApp session can drop (the phone needs a QR re-scan). Checked so the admin shows the truth, and the people who
// manage notifications hear about it even when no message happens to be waiting.
Artisan::command('notifications:check-whatsapp', function (WhatsAppGateway $gateway) {
    $status = $gateway->sessionStatus();
    NotificationSettings::recordSessionStatus($status);
    if (! in_array($status, ['connected', 'off'], true)) {
        AdminAlerts::whatsAppSession($status);
    }
    $this->info("WhatsApp session: {$status}");
})->purpose('Record the WhatsApp session status');

Schedule::command('notifications:check-whatsapp')->everyFiveMinutes()->onOneServer();
