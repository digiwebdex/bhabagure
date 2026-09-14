<?php

namespace App\Enums;

/**
 * Where a customer, lead or booking came from (docs/phase-1-schema.md §3.2, §3.7). One vocabulary for all three:
 * everything that arrives through the website — a booking, a form, a sign-up — is `website_form`.
 */
enum LeadSource: string
{
    case WebsiteForm = 'website_form';
    case Facebook = 'facebook';
    case WhatsApp = 'whatsapp';
    case PhoneCall = 'phone_call';
    case Referral = 'referral';
    case WalkIn = 'walk_in';
    /** Bookings only. */
    case B2bAgent = 'b2b_agent';
    /** Bookings only. */
    case Corporate = 'corporate';

    /** @return list<self> what staff pick for a new lead or customer */
    public static function forCustomers(): array
    {
        return [self::WalkIn, self::PhoneCall, self::Facebook, self::WhatsApp, self::Referral, self::WebsiteForm];
    }
}
