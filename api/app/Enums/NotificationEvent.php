<?php

namespace App\Enums;

/**
 * The automated messages of docs/phase-4-whatsapp.md §2 (decided 2026-09-13), plus the system messages that aren't
 * templated. Every message that matters to money — booking, confirmation, payment, reminders — also goes by email, so
 * a banned or disconnected WhatsApp number degrades the service instead of stopping it.
 */
enum NotificationEvent: string
{
    case BookingCreated = 'booking_created';
    case BookingConfirmed = 'booking_confirmed';
    case PaymentReceived = 'payment_received';
    case DocumentsPending = 'documents_pending';
    case PreTripReminder = 'pre_trip_reminder';
    case DepartureToday = 'departure_today';
    case TripCompleted = 'trip_completed';
    /** A quotation sent from the admin (docs/phase-5-admin-core.md §4.5): WhatsApp with the PDF, and email. Never SMS. */
    case QuoteSent = 'quote_sent';
    /** 24 hours before a sent quotation stops being honoured (docs/phase-6-customer-portal.md §3.2). */
    case QuoteExpiring = 'quote_expiring';
    case NewBookingAlert = 'new_booking_alert';
    case NewLeadAlert = 'new_lead_alert';
    case LowSeatAlert = 'low_seat_alert';
    /** A customer accepted a quotation in the portal: its owner converts it. */
    case QuoteAcceptedAlert = 'quote_accepted_alert';
    /** A customer opened a support ticket in the portal (docs/phase-6-customer-portal.md §3.5). */
    case SupportTicketAlert = 'support_ticket_alert';
    /** A staff reply to a support ticket: WhatsApp and email, and it shows in the portal. */
    case SupportReply = 'support_reply';
    /** An NPS answer of 0–6 after a trip: its owner calls the customer (docs/phase-6-customer-portal.md §0.4). */
    case NpsFollowUpAlert = 'nps_follow_up_alert';
    /** A staff document within 30 days of its expiry, and again on the day (docs/phase-7-hr-attendance-bonus-wallet.md §4.2). */
    case StaffDocumentExpiringAlert = 'staff_document_expiring_alert';
    /** The attendance device, or the office PC that reads it, has been silent for an hour of duty time (§5.1). */
    case AttendanceDeviceOfflineAlert = 'attendance_device_offline_alert';

    /** A message a staff member sends from a booking or customer record. Not templated. */
    case StaffMessage = 'staff_message';

    /** System messages: a staff member's WhatsApp verification code, and the confirmation of a STOP reply. */
    case WhatsAppVerification = 'whatsapp_verification';
    case OptOutConfirmation = 'opt_out_confirmation';

    /** @return list<self> the events with editable templates */
    public static function templated(): array
    {
        return [
            self::BookingCreated, self::BookingConfirmed, self::PaymentReceived, self::DocumentsPending, self::PreTripReminder,
            self::DepartureToday, self::TripCompleted, self::QuoteSent, self::QuoteExpiring, self::NewBookingAlert, self::NewLeadAlert,
            self::LowSeatAlert, self::QuoteAcceptedAlert, self::SupportTicketAlert, self::SupportReply, self::NpsFollowUpAlert, self::StaffDocumentExpiringAlert,
            self::AttendanceDeviceOfflineAlert,
        ];
    }

    /** @return list<self> sales alerts with a recipient list on the Notifications settings screen */
    public static function staffAlerts(): array
    {
        return [
            self::NewBookingAlert, self::NewLeadAlert, self::LowSeatAlert, self::QuoteAcceptedAlert, self::SupportTicketAlert, self::NpsFollowUpAlert,
            self::StaffDocumentExpiringAlert, self::AttendanceDeviceOfflineAlert,
        ];
    }

    public function audience(): string
    {
        return match ($this) {
            self::NewBookingAlert, self::NewLeadAlert, self::LowSeatAlert, self::QuoteAcceptedAlert, self::SupportTicketAlert, self::NpsFollowUpAlert,
            self::StaffDocumentExpiringAlert, self::AttendanceDeviceOfflineAlert, self::WhatsAppVerification => 'staff',
            default => 'customer',
        };
    }

    /**
     * The channels planned for every occurrence. SMS is planned only for the departure-day message — at 06:00 on travel
     * day the customer may have no data connection, and SMS reaches any handset. For the money-critical events SMS is a
     * fallback (fallsBackToSms), never a parallel copy: it costs per part and carries no PDF.
     *
     * @return list<NotificationChannel>
     */
    public function channels(): array
    {
        return match ($this) {
            self::DepartureToday => [NotificationChannel::WhatsApp, NotificationChannel::Sms],
            self::TripCompleted, self::StaffMessage, self::WhatsAppVerification, self::OptOutConfirmation => [NotificationChannel::WhatsApp],
            default => [NotificationChannel::WhatsApp, NotificationChannel::Email],
        };
    }

    /** A short SMS goes out when WhatsApp can't deliver this message (failed, no WhatsApp, or WhatsApp unavailable). */
    public function fallsBackToSms(): bool
    {
        return in_array($this, [self::BookingConfirmed, self::PaymentReceived, self::PreTripReminder, self::DocumentsPending], true);
    }

    /** @return list<NotificationChannel> channels with an editable template */
    public function templateChannels(): array
    {
        return $this->fallsBackToSms() ? [...$this->channels(), NotificationChannel::Sms] : $this->channels();
    }

    /** The {{variables}} a template for this event may use. */
    public function variables(): array
    {
        return match ($this) {
            self::BookingCreated => ['name', 'package', 'ref', 'date', 'total', 'link'],
            self::BookingConfirmed => ['name', 'package', 'ref', 'date', 'paid', 'due', 'invoice', 'link', 'short_link'],
            self::PaymentReceived => ['name', 'package', 'ref', 'amount', 'paid', 'due', 'link', 'short_link'],
            self::DocumentsPending => ['name', 'package', 'ref', 'date', 'travellers', 'office', 'short_link'],
            self::PreTripReminder => ['name', 'package', 'ref', 'date', 'leader', 'office', 'short_link'],
            self::DepartureToday => ['name', 'package', 'ref', 'office'],
            self::TripCompleted => ['name', 'package', 'review'],
            self::QuoteSent => ['name', 'package', 'number', 'date', 'pax', 'total', 'valid_until', 'link'],
            self::QuoteExpiring => ['name', 'package', 'number', 'total', 'valid_until', 'link'],
            self::NewBookingAlert => ['ref', 'package', 'date', 'pax', 'total', 'payment', 'customer', 'phone'],
            self::NewLeadAlert => ['name', 'phone', 'kind', 'details'],
            self::LowSeatAlert => ['package', 'date', 'seats'],
            self::QuoteAcceptedAlert => ['number', 'package', 'total', 'customer', 'phone'],
            self::SupportTicketAlert => ['number', 'subject', 'message', 'ref', 'customer', 'phone'],
            self::SupportReply => ['name', 'number', 'subject', 'reply', 'link'],
            self::NpsFollowUpAlert => ['ref', 'package', 'score', 'comment', 'customer', 'phone'],
            self::StaffDocumentExpiringAlert => ['staff', 'document', 'expires', 'days', 'link'],
            self::AttendanceDeviceOfflineAlert => ['device', 'since', 'reason', 'link'],
            default => [],
        };
    }

    /** When it goes out, as shown on the templates screen. Scheduling itself lives in NotificationPlanner. */
    public function timing(): string
    {
        return match ($this) {
            self::DocumentsPending => '7 days before departure, if a traveller has no passport number',
            self::PreTripReminder => '48 hours before departure',
            self::DepartureToday => '06:00 on the travel day',
            self::TripCompleted => '2 days after return',
            self::LowSeatAlert => 'when a departure has 3 or fewer seats left (once)',
            self::QuoteSent => 'when staff send a quotation',
            self::QuoteExpiring => '24 hours before a sent quotation expires',
            self::QuoteAcceptedAlert => 'when a customer accepts a quotation in the portal',
            self::SupportTicketAlert => 'when a customer opens a support ticket in the portal',
            self::SupportReply => 'when staff reply to a support ticket',
            self::NpsFollowUpAlert => 'when a customer rates a completed trip 0–6 in the portal',
            self::StaffDocumentExpiringAlert => '30 days before a staff document expires, and on the day',
            self::AttendanceDeviceOfflineAlert => 'after an hour without a good pull during duty hours on a working day, once per outage',
            default => 'immediately',
        };
    }

    /** Messages about a trip that is going ahead: cancelled if the booking is cancelled before they're sent. */
    public function needsConfirmedBooking(): bool
    {
        return in_array($this, [self::BookingConfirmed, self::DocumentsPending, self::PreTripReminder, self::DepartureToday, self::TripCompleted], true);
    }
}
