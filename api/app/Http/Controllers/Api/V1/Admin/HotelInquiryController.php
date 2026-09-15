<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\InquiryType;
use App\Http\Controllers\Api\V1\Admin\Concerns\WorksQuoteRequests;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminHotelInquiry;
use App\Models\Inquiry;
use App\Models\Staff;
use Illuminate\Support\Carbon;

/**
 * Hotel requests (docs/phase-8-visa-quotes-pricing-downloads.md §4.B): the website's hotel quotation requests. Staff send
 * the quotation as a reply (WhatsApp and email, logged) and mark the request quoted.
 */
class HotelInquiryController extends Controller
{
    use WorksQuoteRequests;

    protected function type(): InquiryType
    {
        return InquiryType::HotelQuote;
    }

    protected function present(Inquiry $inquiry, Staff $viewer, ?Carbon $now = null): array
    {
        return AdminHotelInquiry::row($inquiry, $viewer, $now);
    }
}
