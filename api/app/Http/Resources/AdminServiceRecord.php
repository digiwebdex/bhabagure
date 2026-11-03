<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use App\Models\ServiceRecord;
use App\Models\SiteSetting;
use App\Models\Staff;
use App\Services\Notifications\NotificationVariables;
use App\Support\Numerals;

/** A sold air ticket, hotel booking or tour package, as the invoice and the service lists show it (docs/invoice-services.md). */
final class AdminServiceRecord
{
    /**
     * What the invoice form edits.
     *
     * @return array<string, mixed>
     */
    public static function fields(ServiceRecord $record): array
    {
        return [
            'id' => $record->id,
            'line_key' => $record->line_key,
            'type' => $record->type,
            'passenger_name' => $record->passenger_name,
            'reference' => $record->reference,
            'trip_type' => $record->trip_type,
            'route_from' => $record->route_from,
            'route_to' => $record->route_to,
            'starts_on' => $record->starts_on?->toDateString(),
            'ends_on' => $record->ends_on?->toDateString(),
            'hotel_name' => $record->hotel_name,
            'room_type' => $record->room_type,
            'package_name' => $record->package_name,
            'tour_package_id' => $record->tour_package_id,
            'status' => $record->status,
            'schedule_note' => $record->schedule_note,
        ];
    }

    /**
     * A row of Air ticketing or Hotel requests: never a price — the invoice has those.
     *
     * @return array<string, mixed>
     */
    public static function row(ServiceRecord $record, Staff $viewer, ?string $today = null): array
    {
        $invoice = $record->invoice;
        $days = $record->daysLeft($today);
        $air = $record->type === ServiceRecord::AIR;
        $sold = $invoice->status === Invoice::ISSUED;

        return self::fields($record) + [
            'customer_name' => $invoice->billed_name,
            'phone' => $invoice->billed_phone,
            'email' => $invoice->billed_email,
            'days_left' => $days,
            // A flight still to leave, on an invoice that stands.
            'departure_soon' => $air && $sold && $days !== null && $days >= 0 && $days <= ServiceRecord::SOON_DAYS,
            'departure_urgent' => $air && $sold && $days !== null && $days >= 0 && $days <= ServiceRecord::URGENT_DAYS,
            'schedule_changed_at' => $record->schedule_changed_at?->toIso8601String(),
            'updated_by' => $record->updatedBy?->name,
            'updated_at' => $record->updated_at?->toIso8601String(),
            'cancelled' => $invoice->status === Invoice::VOID,
            'invoice' => ['id' => $invoice->id, 'number' => $invoice->invoice_number, 'status' => $invoice->status],
            'whatsapp_text' => self::whatsAppText($record),
            'can_update' => $sold && $viewer->canAny(["{$record->queuePermission()}.manage", 'invoices.manage']),
        ];
    }

    /**
     * The message the WhatsApp button opens ready to send, in the customer's language (Bangla when not known): the new
     * schedule once a flight has changed, otherwise what the customer needs to know about the ticket or the booking.
     */
    public static function whatsAppText(ServiceRecord $record): string
    {
        $invoice = $record->invoice;
        $locale = $invoice->customer?->locale === 'en' ? 'en' : 'bn';
        $say = fn (string $key, array $values = []) => __("services.whatsapp.{$key}", $values, $locale);
        $date = fn ($value) => Numerals::date($value->toDateString(), $locale);
        $changed = $record->status === ServiceRecord::SCHEDULE_CHANGED;
        $air = $record->type === ServiceRecord::AIR;
        $name = trim((string) $invoice->billed_name);
        $passenger = trim((string) $record->passenger_name);

        $facts = $air
            ? [
                $passenger !== '' && $passenger !== $name ? $say('passenger', ['value' => $passenger]) : null,
                $record->reference ? $say('pnr', ['value' => $record->reference]) : null,
                $say('route', ['from' => $record->route_from, 'to' => $record->route_to]),
                $changed ? $say('new_schedule', ['value' => trim((string) $record->schedule_note)]) : null,
                $record->starts_on ? $say('departure', ['date' => $date($record->starts_on)]) : null,
                $record->ends_on ? $say('return', ['date' => $date($record->ends_on)]) : null,
                $changed ? null : $say('status', ['value' => $say("statuses.{$record->status}")]),
            ]
            : [
                $say('hotel', ['value' => $record->hotel_name]),
                $record->room_type ? $say('room', ['value' => $record->room_type]) : null,
                $record->reference ? $say('reservation', ['value' => $record->reference]) : null,
                $record->starts_on ? $say('check_in', ['date' => $date($record->starts_on)]) : null,
                $record->ends_on ? $say('check_out', ['date' => $date($record->ends_on)]) : null,
                $say('status', ['value' => $say("statuses.{$record->status}")]),
            ];

        $office = NotificationVariables::displayPhone(SiteSetting::get('contact', [])['phone'] ?? '');
        $company = SiteSetting::get('company', [])['name'] ?? [];
        $brand = $company[$locale] ?? $company['en'] ?? 'Bhabaghure Holidays';

        return implode("\n", array_filter([
            $say('greeting', ['name' => $name]),
            '',
            $say($air ? ($changed ? 'air_changed' : 'air_details') : 'hotel_details'),
            ...array_filter($facts, fn (?string $fact) => $fact !== null),
            '',
            $air && ! $changed ? $say('be_ready') : null,
            $office !== '' ? $say('help', ['phone' => $office]) : null,
            '',
            $say('thanks', ['brand' => $brand]),
        ], fn (?string $line) => $line !== null));
    }

    /** Printed under the invoice line: the customer's copy, in English like the rest of the invoice. Never the status. */
    public static function printedLine(ServiceRecord $record): string
    {
        $date = fn ($value) => Numerals::date($value->toDateString(), 'en');

        return implode(' · ', array_filter(match ($record->type) {
            ServiceRecord::AIR => [
                $record->passenger_name ? "Passenger {$record->passenger_name}" : null,
                $record->reference ? "PNR {$record->reference}" : null,
                "{$record->route_from} → {$record->route_to}",
                $record->trip_type === ServiceRecord::ROUND_TRIP ? 'Round trip' : 'One way',
                $record->starts_on ? 'Departure '.$date($record->starts_on) : null,
                $record->ends_on ? 'Return '.$date($record->ends_on) : null,
            ],
            ServiceRecord::HOTEL => [
                $record->passenger_name ? "Guest {$record->passenger_name}" : null,
                $record->hotel_name,
                $record->room_type,
                $record->reference ? "Reservation {$record->reference}" : null,
                $record->starts_on ? 'Check-in '.$date($record->starts_on) : null,
                $record->ends_on ? 'Check-out '.$date($record->ends_on) : null,
            ],
            default => [
                $record->passenger_name ? "Traveller {$record->passenger_name}" : null,
                $record->package_name,
                $record->starts_on ? 'Departure '.$date($record->starts_on) : null,
                $record->ends_on ? 'Return '.$date($record->ends_on) : null,
            ],
        }));
    }
}
