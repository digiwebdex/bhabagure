<?php

namespace App\Http\Controllers\Api\V1\Public;

/** site_settings keys, and which of them the public website may read. */
final class SiteSettingKeys
{
    /** Shape: web/src/lib/content/types.ts SiteSettings. */
    public const PUBLIC = ['company', 'contact', 'address', 'civilAviationNo', 'hours', 'stats'];

    /** Edited on the Pricing screen, served by GET /public/pricing. */
    public const PRICING = 'pricing';

    /** Staff-only: alert recipient lists and the last known WhatsApp session status. Never public. */
    public const NOTIFICATIONS = 'notifications';
}
