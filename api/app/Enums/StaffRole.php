<?php

namespace App\Enums;

/** The System administration role list (docs/phase-1-schema.md §8.1). Slugs are stored in roles.name. */
enum StaffRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case SalesAgent = 'sales_agent';
    case Accountant = 'accountant';
    case TourOperator = 'tour_operator';
}
