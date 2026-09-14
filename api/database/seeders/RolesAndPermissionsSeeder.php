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

    /** name => [module, bn, en]. A permission added here reaches a live database through `php artisan permissions:sync --add-only`. */
    public const PERMISSIONS = [
        'bookings.view_all' => ['bookings', 'সব বুকিং দেখা', 'View all bookings'],
        'bookings.view_own' => ['bookings', 'নিজের বুকিং দেখা', 'View own bookings'],
        'bookings.create' => ['bookings', 'বুকিং তৈরি', 'Create bookings'],
        'bookings.update' => ['bookings', 'বুকিং সম্পাদনা', 'Edit bookings'],
        'bookings.delete' => ['bookings', 'বুকিং মুছে ফেলা', 'Delete bookings'],
        'records.assign' => ['bookings', 'বুকিং, গ্রাহক ও ইনকোয়্যারির দায়িত্ব বদল', 'Reassign bookings, customers and enquiries'],
        'quotations.view_all' => ['quotations', 'সব কোটেশন দেখা', 'View all quotations'],
        'quotations.view_own' => ['quotations', 'নিজের কোটেশন দেখা', 'View own quotations'],
        'quotations.manage' => ['quotations', 'কোটেশন তৈরি ও পাঠানো', 'Create and send quotations'],
        'quotations.convert' => ['quotations', 'কোটেশন থেকে বুকিং', 'Convert quotations to bookings'],
        'air_inquiries.view' => ['air', 'টিকেট ইনকোয়্যারি দেখা', 'View air-ticket enquiries'],
        'air_inquiries.manage' => ['air', 'টিকেট ইনকোয়্যারিতে কাজ করা', 'Work air-ticket enquiries'],
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
        'staff_documents.view' => ['staff', 'স্টাফের ডকুমেন্ট দেখা', 'View staff documents'],
        'staff_documents.manage' => ['staff', 'স্টাফের ডকুমেন্ট আপলোড ও আর্কাইভ', 'Upload and archive staff documents'],
        'attendance.view_all' => ['staff', 'সবার হাজিরা দেখা', 'View everyone’s attendance'],
        'attendance.manage' => ['staff', 'হাজিরা, ডিভাইস ও ছুটি ব্যবস্থাপনা', 'Manage attendance, devices and leave'],
        'cms.manage' => ['website', 'ওয়েবসাইট কনটেন্ট ব্যবস্থাপনা', 'Manage website content'],
        'reports.view' => ['reports', 'রিপোর্ট দেখা', 'View reports'],
        'reports.export' => ['reports', 'রিপোর্ট এক্সপোর্ট', 'Export reports'],
        'reports.profit_loss' => ['reports', 'লাভ-ক্ষতি দেখা', 'View profit and loss'],
        'notifications.send' => ['communication', 'গ্রাহককে WhatsApp বার্তা পাঠানো', 'Send WhatsApp messages to customers'],
        'notifications.manage' => ['communication', 'নোটিফিকেশন ও টেমপ্লেট ব্যবস্থাপনা', 'Manage notifications and templates'],
        'support.manage' => ['communication', 'গ্রাহকের সাপোর্ট টিকেটের উত্তর', 'Answer customer support tickets'],
        'system.audit_view' => ['system', 'অডিট লগ দেখা', 'View audit log'],
        'system.roles_manage' => ['system', 'রোল ও অনুমতি ব্যবস্থাপনা', 'Manage roles and permissions'],
    ];

    /** role => [bn, en, permissions] */
    public const ROLES = [
        'super_admin' => ['সুপার অ্যাডমিন', 'Super admin', []],
        'admin' => ['অ্যাডমিন', 'Admin', [
            'bookings.view_all', 'bookings.create', 'bookings.update', 'bookings.delete', 'records.assign',
            'quotations.view_all', 'quotations.manage', 'quotations.convert', 'air_inquiries.view', 'air_inquiries.manage',
            'customers.view', 'customers.manage',
            'clients.manage', 'b2b_rates.manage', 'packages.manage', 'pricing.manage', 'payments.view', 'invoices.manage',
            'transactions.create_manual', 'ledger.view_company_balance', 'staff.manage', 'bonus.manage', 'commission.view_all',
            'staff_documents.view', 'staff_documents.manage', 'attendance.view_all', 'attendance.manage',
            'cms.manage', 'reports.view', 'reports.export', 'reports.profit_loss', 'system.audit_view',
            'notifications.send', 'notifications.manage', 'support.manage',
        ]],
        'sales_agent' => ['সেলস এজেন্ট', 'Sales agent', [
            'bookings.view_own', 'bookings.create', 'bookings.update', 'customers.view', 'customers.manage', 'b2b_rates.manage',
            'quotations.view_own', 'quotations.manage', 'quotations.convert', 'air_inquiries.view', 'air_inquiries.manage',
            'commission.view_own', 'reports.view', 'reports.export', 'notifications.send', 'support.manage',
        ]],
        'accountant' => ['হিসাবরক্ষক', 'Accountant', [
            'bookings.view_all', 'quotations.view_all', 'customers.view', 'payments.view', 'invoices.manage', 'transactions.create_manual',
            'ledger.view_company_balance', 'commission.view_all', 'reports.view', 'reports.export', 'reports.profit_loss', 'support.manage',
            'attendance.view_all',
        ]],
        'tour_operator' => ['ট্যুর অপারেটর', 'Tour operator', [
            'bookings.view_all', 'bookings.create', 'bookings.update', 'customers.view', 'packages.manage', 'pricing.manage',
            'commission.view_own', 'reports.view', 'reports.export', 'support.manage',
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
