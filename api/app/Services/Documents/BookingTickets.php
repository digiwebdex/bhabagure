<?php

namespace App\Services\Documents;

use App\Models\Booking;
use App\Models\BookingTicket;
use App\Models\BookingTraveller;
use App\Models\Staff;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * E-tickets on bookings. A ticket belongs to one traveller of the booking; its PDF (optional) is encrypted on the
 * private disk and served only to the booking's customer and to staff who can see the booking. A wrong ticket is
 * voided with a reason — the row and its file stay, so what the customer was once shown is never lost.
 */
final class BookingTickets
{
    public const MAX_KB = 5120;

    public const MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{airline: string, pnr: string, ticket_number: string, route: ?string, departs_on: ?string}  $data
     */
    public function issue(Booking $booking, BookingTraveller $traveller, array $data, ?UploadedFile $file, Staff $staff): BookingTicket
    {
        $path = null;
        if ($file !== null) {
            $path = sprintf('booking-tickets/%s/%s.enc', now('Asia/Dhaka')->format('Y/m'), Str::uuid());
            Storage::disk(TravellerDocuments::DISK)->put($path, Crypt::encryptString((string) file_get_contents($file->getRealPath())));
        }

        try {
            return DB::transaction(function () use ($booking, $traveller, $data, $file, $path, $staff) {
                $ticket = BookingTicket::query()->create([
                    'booking_id' => $booking->id, 'booking_traveller_id' => $traveller->id,
                    'airline' => $data['airline'], 'pnr' => $data['pnr'], 'ticket_number' => $data['ticket_number'],
                    'route' => $data['route'] ?? null, 'departs_on' => $data['departs_on'] ?? null,
                    'disk' => $path ? TravellerDocuments::DISK : null, 'path' => $path,
                    'mime' => $file ? Str::limit((string) $file->getMimeType(), 60, '') : null, 'bytes' => $file?->getSize(),
                    'issued_by_staff_id' => $staff->id,
                ]);
                $this->audit->record('booking.ticket_issued', $staff, $booking, [
                    'ticket_id' => $ticket->id, 'traveller_id' => $traveller->id, 'airline' => $ticket->airline, 'pnr' => $ticket->pnr,
                ]);

                return $ticket;
            });
        } catch (\Throwable $e) {
            if ($path !== null) {
                Storage::disk(TravellerDocuments::DISK)->delete($path);
            }
            throw $e;
        }
    }

    /** @throws DocumentRefused */
    public function void(BookingTicket $ticket, string $reason, Staff $staff): BookingTicket
    {
        return DB::transaction(function () use ($ticket, $reason, $staff) {
            $locked = BookingTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($locked->voided_at !== null) {
                throw new DocumentRefused('ticket_voided');
            }
            $locked->forceFill(['voided_at' => now(), 'voided_by_staff_id' => $staff->id, 'void_reason' => $reason])->save();
            $this->audit->record('booking.ticket_voided', $staff, $locked->loadMissing('booking')->booking, ['ticket_id' => $locked->id, 'reason' => $reason]);

            return $locked;
        });
    }

    public function contents(BookingTicket $ticket): string
    {
        return Crypt::decryptString((string) Storage::disk($ticket->disk ?? TravellerDocuments::DISK)->get((string) $ticket->path));
    }

    /** @return array<string, mixed> the shape both the admin and the portal show */
    public static function row(BookingTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'travellerId' => $ticket->booking_traveller_id,
            'airline' => $ticket->airline,
            'pnr' => $ticket->pnr,
            'ticketNumber' => $ticket->ticket_number,
            'route' => $ticket->route,
            'departsOn' => $ticket->departs_on?->toDateString(),
            'hasFile' => $ticket->path !== null,
            'issuedAt' => $ticket->created_at->toIso8601String(),
        ];
    }
}
