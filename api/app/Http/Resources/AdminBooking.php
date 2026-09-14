<?php

namespace App\Http\Resources;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingLine;
use App\Models\BookingTraveller;
use App\Models\Invoice;
use App\Models\NotificationMessage;
use App\Models\PaymentAttempt;
use App\Models\Staff;
use App\Models\Transaction;
use App\Services\Booking\BookingQuoteEditor;
use App\Services\Documents\TravellerDocuments;
use App\Services\Ledger\LedgerService;
use App\Services\Notifications\MessageRenderer;
use App\Services\Notifications\NotificationSettings;
use App\Support\Money;
use App\Support\Pricing\PricingConfig;

/** Bookings for the admin (snake_case, like the rest of the staff API). Amounts are JSON numbers. */
final class AdminBooking
{
    /** @return array<string, mixed> */
    public static function summary(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'payment_status' => $booking->payment_status,
            'customer' => $booking->relationLoaded('customer') && $booking->customer
                ? ['id' => $booking->customer->id, 'name' => $booking->customer->name, 'phone' => $booking->customer->phone, 'email' => $booking->customer->email] : null,
            'package_title_en' => $booking->package_title_en,
            'package_title_bn' => $booking->package_title_bn,
            'travel_start' => $booking->travel_start?->toDateString(),
            'pax_count' => $booking->pax_count,
            'total_amount' => Money::toNumber($booking->total_amount),
            'paid_amount' => Money::toNumber($booking->paid_amount),
            'due_amount' => Money::toNumber($booking->due_amount),
            'source' => $booking->source,
            'assigned_staff' => $booking->relationLoaded('assignedStaff') && $booking->assignedStaff
                ? ['id' => $booking->assignedStaff->id, 'name' => $booking->assignedStaff->name] : null,
            // In the shared pool: unowned and still an inquiry (Booking::scopeClaimable).
            'claimable' => $booking->assigned_staff_id === null && $booking->status === BookingStatus::Inquiry,
            // For the list's row actions: PDF needs an issued invoice; delete needs neither an invoice nor money.
            'has_invoice' => (bool) ($booking->has_invoice ?? Invoice::query()->where('booking_id', $booking->id)->where('status', Invoice::ISSUED)->exists()),
            'has_payments' => (bool) ($booking->has_payments ?? Transaction::query()->where('booking_id', $booking->id)->exists()),
            'created_at' => $booking->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(Booking $booking, Staff $viewer): array
    {
        $booking->loadMissing(['customer', 'lines', 'travellers.documents', 'assignedStaff', 'package']);
        $invoices = Invoice::query()->where('booking_id', $booking->id)->latest('id')->get();
        $current = $invoices->firstWhere('status', Invoice::ISSUED);
        $open = ! $booking->status->isFinal();
        // Staff who don't see every booking work only what they own; a pool booking is claimed first (BookingController::find).
        $works = Booking::seesAll($viewer) || $booking->assigned_staff_id === $viewer->id;
        $can = fn (string $permission) => $works && $viewer->can($permission);

        // array_replace, not `+`: the detail's fuller `customer` must win over the summary's.
        return array_replace(self::summary($booking), [
            'room_type' => $booking->room_type,
            'travel_end' => $booking->travel_end?->toDateString(),
            'list_price' => Money::toNumber($booking->list_price),
            'unit_price' => Money::toNumber($booking->unit_price),
            'subtotal_amount' => Money::toNumber($booking->subtotal_amount),
            'single_supplement_amount' => Money::toNumber($booking->single_supplement_amount),
            'addons_amount' => Money::toNumber($booking->addons_amount),
            'discount_amount' => Money::toNumber($booking->discount_amount),
            'vat_rate' => Money::toNumber($booking->vat_rate),
            'vat_amount' => Money::toNumber($booking->vat_amount),
            'locale' => $booking->locale,
            'package_slug' => $booking->package?->slug,
            'assigned_staff' => $booking->assignedStaff ? ['id' => $booking->assignedStaff->id, 'name' => $booking->assignedStaff->name] : null,
            'customer' => $booking->customer ? [
                'id' => $booking->customer->id, 'name' => $booking->customer->name, 'phone' => $booking->customer->phone,
                'email' => $booking->customer->email, 'address' => $booking->customer->address,
                'whatsapp_opted_out' => $booking->customer->whatsapp_opted_out_at !== null,
            ] : null,
            // Messages about this booking, one entry per message with WhatsApp, email and SMS reported independently.
            'notification_groups' => AdminNotification::groups(NotificationMessage::query()->with('recipient')
                ->where('related_type', $booking->getMorphClass())->where('related_id', $booking->id)->latest('id')->limit(90)->get()),
            'notifications_number_published' => NotificationSettings::notificationsNumber() !== null,
            'notifications_sender_line' => MessageRenderer::senderLine(),
            'confirmed_at' => $booking->confirmed_at?->toIso8601String(),
            'cancelled_at' => $booking->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $booking->cancellation_reason,
            'terms_accepted_at' => $booking->terms_accepted_at?->toIso8601String(),
            'terms_version' => $booking->terms_version,
            'lines' => $booking->lines->map(fn (BookingLine $line) => [
                'kind' => $line->kind, 'code' => $line->code, 'title_en' => $line->title_en, 'title_bn' => $line->title_bn,
                'quantity' => $line->quantity, 'unit_price' => Money::toNumber($line->unit_price), 'amount' => Money::toNumber($line->amount),
            ])->values(),
            'travellers' => $booking->travellers->map(fn (BookingTraveller $t) => [
                'id' => $t->id, 'is_lead' => $t->is_lead, 'full_name' => $t->full_name, 'date_of_birth' => $t->date_of_birth?->toDateString(),
                'nationality' => $t->nationality, 'passport_number' => $t->passport_number, 'passport_expiry' => $t->passport_expiry?->toDateString(),
                'phone' => $t->phone, 'email' => $t->email, 'has_scan' => $t->passport_scan_path !== null, 'ocr_filled' => $t->ocr_filled_at !== null,
                // Portal documents: the slot shape the portal shows, plus the row id staff review by.
                'documents' => collect(TravellerDocuments::slots($t))->map(fn (array $slot) => $slot + ['id' => $t->documents->firstWhere('kind', $slot['kind'])?->id])->all(),
            ])->values(),
            'invoices' => $invoices->map(fn (Invoice $invoice) => [
                'id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'status' => $invoice->status,
                'issued_on' => $invoice->issued_on?->toDateString(), 'total_amount' => Money::toNumber($invoice->total_amount),
                'paid_amount' => Money::toNumber($invoice->paid_amount), 'balance_due' => Money::toNumber($invoice->balance_due),
                'payment_status' => $invoice->payment_status, 'void_reason' => $invoice->void_reason,
                'share_url' => $invoice->status === Invoice::ISSUED ? url("/api/v1/public/invoices/{$invoice->share_token}") : null,
            ])->values(),
            'transactions' => Transaction::query()->where('booking_id', $booking->id)->orderBy('occurred_at')->orderBy('id')->get()
                ->map(fn (Transaction $t) => [
                    'id' => $t->id, 'direction' => $t->direction->value, 'amount' => Money::toNumber($t->amount), 'category' => $t->category,
                    'method' => $t->method, 'external_ref' => $t->external_ref, 'reference_label' => $t->reference_label,
                    'description' => $t->description, 'occurred_at' => $t->occurred_at?->toIso8601String(),
                    'reverses_transaction_id' => $t->reverses_transaction_id,
                    // Opened through GET /admin/cash-book/{id}/evidence; the path never leaves the API.
                    'has_evidence' => $t->evidence_path !== null,
                ])->values(),
            'payment_attempts' => PaymentAttempt::query()->where('booking_id', $booking->id)->latest('id')->get()
                ->map(fn (PaymentAttempt $a) => [
                    'id' => $a->id, 'tran_id' => $a->tran_id, 'status' => $a->status->value, 'amount' => Money::toNumber($a->amount),
                    'method_hint' => $a->method_hint, 'card_type' => $a->card_type, 'bank_tran_id' => $a->bank_tran_id,
                    'online_charge' => Money::toNumber($a->online_charge), 'gateway_fee' => Money::toNumber($a->gateway_fee),
                    'gateway_surcharge' => Money::toNumber($a->gateway_surcharge), 'failure_reason' => $a->failure_reason,
                    'risk_level' => $a->risk_level, 'created_at' => $a->created_at?->toIso8601String(), 'settled_at' => $a->settled_at?->toIso8601String(),
                ])->values(),
            // What this staff member may do now. The server checks again on every action.
            'actions' => [
                'edit_quote' => $open && $current === null && $can('bookings.update'),
                'issue_invoice' => $open && $current === null && $booking->status !== BookingStatus::Cancelled && $can('invoices.manage'),
                'void_invoice' => $current !== null && $can('invoices.manage'),
                'record_payment' => $open && $current !== null && (float) $booking->due_amount > 0 && $can('transactions.create_manual'),
                'confirm' => $booking->status === BookingStatus::Inquiry && (float) $booking->paid_amount > 0 && $can('bookings.update'),
                'complete' => $booking->status === BookingStatus::Confirmed && $can('bookings.update'),
                'cancel' => $open && $can('bookings.update'),
                'reverse_payment' => $can('transactions.create_manual'),
                'send_whatsapp' => $can('notifications.send') && $booking->customer?->whatsapp_opted_out_at === null,
                'toggle_whatsapp_opt_out' => $viewer->can('customers.manage'),
                'claim' => $booking->assigned_staff_id === null && $booking->status === BookingStatus::Inquiry && $viewer->can('bookings.update'),
                'assign' => $viewer->can('records.assign'),
            ],
            // Inputs for @bhabaghure/pricing on the draft-invoice controls — the same the server recomputes with.
            'quote_inputs' => [
                'list_price' => Money::toNumber($booking->list_price),
                'addons' => app(BookingQuoteEditor::class)->addonInputs($booking),
                'config' => (fn (PricingConfig $c) => [
                    'slabs' => $c->slabs, 'singleRoomSupplementPercent' => $c->singleRoomSupplementPercent,
                    'serviceChargePercent' => $c->serviceChargePercent, 'maxTravellers' => $c->maxTravellers,
                ])(PricingConfig::current()),
            ],
            'payment_methods' => LedgerService::STAFF_METHODS,
            'vat_rates' => BookingQuoteEditor::VAT_RATES,
        ]);
    }
}
