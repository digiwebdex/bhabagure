<?php

namespace App\Enums;

enum PackageStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    /** No longer sold; kept for the bookings and invoices that reference it. */
    case Archived = 'archived';
}
