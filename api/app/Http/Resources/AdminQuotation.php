<?php

namespace App\Http\Resources;

use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\Staff;
use App\Support\Money;

/** Quotations for the admin (docs/phase-5-admin-core.md §4.5). Money as numbers; the screen formats it. */
final class AdminQuotation
{
    public const RELATIONS = ['customer', 'assignedStaff', 'convertedBooking', 'revisionOf'];

    /** @return array<string, mixed> */
    public static function row(Quotation $quotation, Staff $viewer): array
    {
        $display = $quotation->displayStatus();
        $works = Quotation::seesAll($viewer) || $quotation->assigned_staff_id === $viewer->id;
        $manage = $works && $viewer->can('quotations.manage');
        $offered = in_array($quotation->status, [Quotation::SENT, Quotation::ACCEPTED], true);

        return [
            'id' => $quotation->id,
            'number' => $quotation->number,
            'status' => $quotation->status,
            'display_status' => $display,
            // Sent and running out within 48 hours: the badge and the "Expiring soon" KPI count these.
            'expiring' => $quotation->isExpiringSoon(),
            'customer' => [
                'id' => $quotation->customer->id,
                'name' => $quotation->customer->name,
                'phone' => $quotation->customer->phone,
                'email' => $quotation->customer->email,
            ],
            'package_title_en' => $quotation->package_title_en,
            'package_title_bn' => $quotation->package_title_bn,
            'travel_date' => $quotation->travel_date?->toDateString(),
            'duration_days' => $quotation->duration_days,
            'pax_count' => $quotation->pax_count,
            'room_type' => $quotation->room_type,
            'hotel_category' => $quotation->hotel_category,
            'total_amount' => Money::toNumber($quotation->total_amount),
            'validity_days' => $quotation->validity_days,
            'valid_until' => $quotation->valid_until->toDateString(),
            'sent_at' => $quotation->sent_at?->toIso8601String(),
            'created_at' => $quotation->created_at->toIso8601String(),
            'assigned_staff' => $quotation->assignedStaff ? ['id' => $quotation->assignedStaff->id, 'name' => $quotation->assignedStaff->name] : null,
            'revision_of' => $quotation->revisionOf ? ['id' => $quotation->revisionOf->id, 'number' => $quotation->revisionOf->number] : null,
            'converted_booking' => $quotation->convertedBooking ? ['id' => $quotation->convertedBooking->id, 'reference' => $quotation->convertedBooking->reference] : null,
            'actions' => [
                'edit' => $manage && $quotation->status === Quotation::DRAFT,
                'send' => $manage && $quotation->status === Quotation::DRAFT,
                'accept' => $manage && $display === Quotation::SENT,
                'decline' => $manage && $offered,
                'withdraw' => $manage && $offered,
                'revise' => $manage && ! in_array($quotation->status, [Quotation::DRAFT, Quotation::CONVERTED], true),
                'convert' => $works && $viewer->can('quotations.convert') && ($display === Quotation::SENT || $quotation->status === Quotation::ACCEPTED),
                'delete' => $manage && $quotation->status === Quotation::DRAFT,
                'assign' => $viewer->can('records.assign'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(Quotation $quotation, Staff $viewer): array
    {
        $quotation->loadMissing([...self::RELATIONS, 'lines', 'package', 'createdBy', 'revisions']);

        return array_replace(self::row($quotation, $viewer), [
            'package_code' => $quotation->package_code,
            'duration_nights' => $quotation->duration_nights,
            'includes_airfare' => $quotation->includes_airfare,
            'locale' => $quotation->locale,
            'notes' => $quotation->notes,
            'amounts' => [
                'list_price' => Money::toNumber($quotation->list_price),
                'unit_price' => Money::toNumber($quotation->unit_price),
                'subtotal' => Money::toNumber($quotation->subtotal_amount),
                'single_supplement' => Money::toNumber($quotation->single_supplement_amount),
                'addons' => Money::toNumber($quotation->addons_amount),
                'discount' => Money::toNumber($quotation->discount_amount),
                'vat_rate' => Money::toNumber($quotation->vat_rate),
                'vat' => Money::toNumber($quotation->vat_amount),
                'total' => Money::toNumber($quotation->total_amount),
            ],
            'lines' => $quotation->lines->map(fn (QuotationLine $line) => [
                'kind' => $line->kind,
                'code' => $line->code,
                'title_en' => $line->title_en,
                'title_bn' => $line->title_bn,
                'quantity' => $line->quantity,
                'unit_price' => Money::toNumber($line->unit_price),
                'amount' => Money::toNumber($line->amount),
            ])->values(),
            // What the editor starts from: it re-prices these live with @bhabaghure/pricing, and the save is checked.
            'inputs' => [
                'package_slug' => $quotation->package?->slug,
                'travel_date' => $quotation->travel_date?->toDateString(),
                'pax' => $quotation->pax_count,
                'room' => $quotation->room_type,
                'hotel_category' => $quotation->hotel_category,
                'addons' => $quotation->lines->where('kind', 'addon')->pluck('code')->values(),
                'discount' => Money::toNumber($quotation->discount_amount),
                'vat_rate' => Money::toNumber($quotation->vat_rate),
                'validity_days' => $quotation->validity_days,
                'locale' => $quotation->locale,
                'notes' => $quotation->notes,
            ],
            'created_by' => $quotation->createdBy ? ['id' => $quotation->createdBy->id, 'name' => $quotation->createdBy->name] : null,
            'history' => array_filter([
                'created_at' => $quotation->created_at->toIso8601String(),
                'sent_at' => $quotation->sent_at?->toIso8601String(),
                'accepted_at' => $quotation->accepted_at?->toIso8601String(),
                'declined_at' => $quotation->declined_at?->toIso8601String(),
                'withdrawn_at' => $quotation->withdrawn_at?->toIso8601String(),
                'converted_at' => $quotation->converted_at?->toIso8601String(),
            ]),
            'revisions' => $quotation->revisions->map(fn (Quotation $revision) => ['id' => $revision->id, 'number' => $revision->number, 'status' => $revision->displayStatus()])->values(),
            // The customer's link, once there is one to share.
            'public_url' => $quotation->sent_at ? url("/api/v1/public/quotations/{$quotation->share_token}") : null,
        ]);
    }
}
