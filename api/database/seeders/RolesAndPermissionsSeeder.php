<?php

namespace Database\Seeders;

use App\Enums\StaffRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * docs/phase-1-schema.md §5. Idempotent: adds missing roles and permissions and syncs the seeded matrix.
 * After the first deploy the database is authoritative (the admin Roles screen edits it), so production
 * runs this only with --class when a new permission ships.
 *
 * Super admin holds no permissions: Gate::before lets it through every check, so a matrix edit
 * can never lock the proprietor out.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    private const GUARD = 'staff';

    /** name => [module, bn, en] */
    private const PERMISSIONS = [
        'bookings.view_all' => ['bookings', 'সব বুকিং দেখা', 'View all bookings'],
        'bookings.view_own' => ['bookings', 'নিজের বুকিং দেখা', 'View own bookings'],
        'bookings.create' => ['bookings', 'বুকিং তৈরি', 'Create bookings'],
        'bookings.update' => ['bookings', 'বুকিং সম্পাদনা', 'Edit bookings'],
        'bookings.delete' => ['bookings', 'বুকিং মুছে ফেলা', 'Delete bookings'],
        'customers.view' => ['customers', 'গ্রাহক দেখা', 'View customers'],
        'customers.manage' => ['customers', 'গ্রাহক ব্যবস্থাপনা', 'Manage customers'],
        'clients.manage' => ['customers', 'কর্পোরেট ও এজেন্ট ব্যবস্থাপনা', 'Manage corporate and agent accounts'],
        'b2b_rates.manage' => ['customers', 'B2B রেট ব্যবস্থাপনা', 'Manage B2B rates'],
        'packages.manage' => ['catalogue', 'প্যাকেজ ব্যবস্থাপনা', 'Manage packages'],
        'pricing.manage' => ['catalogue', 'মূল্য ও সিট ব্যবস্থাপনা', 'Manage pricing and seats'],
        'payments.view' => ['finance', 'পেমেন্ট দেখা', 'View payments'],
        'invoices.manage' => ['finance', 'ইনভয়েস ব্যবস্থাপনা', 'Manage invoices'],
        'transactions.create_manual' => ['finance', 'ম্যানুয়াল লেনদেন এন্ট্রি', 'Record manual transactions'],
        'ledger.view_company_balance' => ['finance', 'কোম্পানির ব্যালেন্স দেখা', 'View company balance'],
        'staff.manage' => ['staff', 'স্টাফ ব্যবস্থাপনা', 'Manage staff'],
        'bonus.manage' => ['staff', 'বোনাস ব্যবস্থাপনা', 'Manage bonuses'],
        'commission.view_own' => ['staff', 'নিজের কমিশন দেখা', 'View own commission'],
        'commission.view_all' => ['staff', 'সবার কমিশন দেখা', 'View all commission'],
        'cms.manage' => ['website', 'ওয়েবসাইট কনটেন্ট ব্যবস্থাপনা', 'Manage website content'],
        'reports.view' => ['reports', 'রিপোর্ট দেখা', 'View reports'],
        'reports.export' => ['reports', 'রিপোর্ট এক্সপোর্ট', 'Export reports'],
        'reports.profit_loss' => ['reports', 'লাভ-ক্ষতি দেখা', 'View profit and loss'],
        'notifications.send' => ['communication', 'গ্রাহককে WhatsApp বার্তা পাঠানো', 'Send WhatsApp messages to customers'],
        'notifications.manage' => ['communication', 'নোটিফিকেশন ও টেমপ্লেট ব্যবস্থাপনা', 'Manage notifications and templates'],
        'system.audit_view' => ['system', 'অডিট লগ দেখা', 'View audit log'],
        'system.roles_manage' => ['system', 'রোল ও অনুমতি ব্যবস্থাপনা', 'Manage roles and permissions'],
    ];

    /** role => [bn, en, permissions] */
    private const ROLES = [
        'super_admin' => ['সুপার অ্যাডমিন', 'Super admin', []],
        'admin' => ['অ্যাডমিন', 'Admin', [
            'bookings.view_all', 'bookings.create', 'bookings.update', 'bookings.delete', 'customers.view', 'customers.manage',
            'clients.manage', 'b2b_rates.manage', 'packages.manage', 'pricing.manage', 'payments.view', 'invoices.manage',
            'transactions.create_manual', 'ledger.view_company_balance', 'staff.manage', 'bonus.manage', 'commission.view_all',
            'cms.manage', 'reports.view', 'reports.export', 'reports.profit_loss', 'system.audit_view',
            'notifications.send', 'notifications.manage',
        ]],
        'sales_agent' => ['সেলস এজেন্ট', 'Sales agent', [
            'bookings.view_own', 'bookings.create', 'bookings.update', 'customers.view', 'customers.manage', 'b2b_rates.manage',
            'commission.view_own', 'reports.view', 'reports.export', 'notifications.send',
        ]],
        'accountant' => ['হিসাবরক্ষক', 'Accountant', [
            'bookings.view_all', 'customers.view', 'payments.view', 'invoices.manage', 'transactions.create_manual',
            'ledger.view_company_balance', 'commission.view_all', 'reports.view', 'reports.export', 'reports.profit_loss',
        ]],
        'tour_operator' => ['ট্যুর অপারেটর', 'Tour operator', [
            'bookings.view_all', 'bookings.create', 'bookings.update', 'customers.view', 'packages.manage', 'pricing.manage',
            'commission.view_own', 'reports.view', 'reports.export',
        ]],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name => [$module, $bn, $en]) {
            Permission::query()->updateOrCreate(
                ['name' => $name, 'guard_name' => self::GUARD],
                ['module' => $module, 'name_bn' => $bn, 'name_en' => $en],
            );
        }

        foreach (self::ROLES as $name => [$bn, $en, $permissions]) {
            assert(StaffRole::tryFrom($name) !== null);
            $role = Role::query()->updateOrCreate(
                ['name' => $name, 'guard_name' => self::GUARD],
                ['name_bn' => $bn, 'name_en' => $en, 'is_system' => true],
            );
            if (! $role->wasRecentlyCreated && ! app()->runningUnitTests() && app()->isProduction()) {
                continue; // In production an existing role's permissions belong to the Roles screen.
            }
            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
