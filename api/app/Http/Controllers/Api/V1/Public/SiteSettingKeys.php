<?php

namespace App\Http\Controllers\Api\V1\Public;

/** site_settings keys, and which of them the public website may read. */
final class SiteSettingKeys
{
    /** Shape: web/src/lib/content/types.ts SiteSettings. */
    public const PUBLIC = ['company', 'contact', 'address', 'civilAviationNo', 'hours', 'stats'];

    /**
     * How customers pay by hand (docs/phase-8-visa-quotes-pricing-downloads.md §4.F), edited in Site settings. Not served
     * as a setting: the API puts the details, with each booking's amounts, where a customer needs them.
     */
    public const PAYMENT = 'payment';

    /**
     * The website booking form's checks (docs/booking-phone-verification.md): `{ verifyPhone }`. Served to the website
     * with the pricing (GET /public/pricing), not as a setting.
     */
    public const BOOKING = 'booking';

    /** The keys Admin → Site settings edits. */
    public const STAFF_EDITABLE = [...self::PUBLIC, self::PAYMENT, self::BOOKING];

    /** Edited on the Pricing screen, served by GET /public/pricing. */
    public const PRICING = 'pricing';

    /** Staff-only: alert recipient lists and the last known WhatsApp session status. Never public. */
    public const NOTIFICATIONS = 'notifications';
}
