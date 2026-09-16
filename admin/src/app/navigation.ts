/**
 * Sidebar groups in the design's order, built screens only (docs/phase-5-admin-core.md §4.1).
 * `permissions`: any one of them grants access (api/routes/api.php uses the same rules).
 * `badge`: a key of GET /admin/nav-counts — the count is derived from data, never written here.
 * No permissions: every signed-in staff member (the Dashboard, whose widgets the API filters).
 */
export type NavItem = { key: string; path: string; icon: string; permissions: string[]; badge?: string }

export const NAV_GROUPS: { key: string; heading?: false; items: NavItem[] }[] = [
  // Dashboard, with no group heading (§4.1).
  { key: 'home', heading: false, items: [{ key: 'dashboard', path: '/', icon: 'D', permissions: [] }] },
  {
    key: 'sales',
    items: [
      { key: 'bookings', path: '/bookings', icon: 'B', permissions: ['bookings.view_all', 'bookings.view_own'], badge: 'bookings' },
      { key: 'quotations', path: '/quotations', icon: 'Q', permissions: ['quotations.view_all', 'quotations.view_own'], badge: 'quotations' },
      { key: 'customers', path: '/customers', icon: 'C', permissions: ['customers.view'] },
      // Brochure and visa PDFs downloaded from the website, to follow up (Phase 8 §4.E).
      { key: 'downloads', path: '/downloads', icon: '↓', permissions: ['downloads.view'] },
      { key: 'notifications', path: '/notifications', icon: 'N', permissions: ['notifications.manage'] },
    ],
  },
  {
    key: 'services',
    items: [
      { key: 'air_ticketing', path: '/air-ticketing', icon: 'A', permissions: ['air_inquiries.view'], badge: 'air_inquiries' },
      { key: 'hotel_requests', path: '/hotel-requests', icon: 'H', permissions: ['hotel_inquiries.view'], badge: 'hotel_inquiries' },
      { key: 'documents', path: '/documents', icon: 'V', permissions: ['bookings.view_all', 'bookings.view_own'], badge: 'documents' },
    ],
  },
  // Portal support tickets (docs/phase-6-customer-portal.md §3.5); the design's Communication group.
  {
    key: 'communication',
    items: [{ key: 'support', path: '/support', icon: 'M', permissions: ['support.manage'], badge: 'support' }],
  },
  // Money: the cash book with its invoices and deals, then the books behind them (docs/phase-9-accounts.md).
  {
    key: 'finance',
    items: [
      { key: 'payments', path: '/payments', icon: '$', permissions: ['payments.view'] },
      { key: 'chart_of_accounts', path: '/accounts', icon: 'A', permissions: ['accounts.view'] },
      { key: 'journal', path: '/journal', icon: 'J', permissions: ['accounts.view'] },
      { key: 'account_transactions', path: '/reports/account-transactions', icon: 'T', permissions: ['accounts.view'] },
      { key: 'general_ledger', path: '/reports/general-ledger', icon: 'L', permissions: ['accounts.view'] },
    ],
  },
  {
    key: 'catalogue',
    items: [
      { key: 'packages', path: '/packages', icon: 'P', permissions: ['packages.manage'] },
      { key: 'pricing', path: '/pricing', icon: '%', permissions: ['pricing.manage'] },
    ],
  },
  {
    key: 'website',
    items: [
      { key: 'posts', path: '/posts', icon: 'B', permissions: ['cms.manage'] },
      { key: 'team', path: '/team', icon: 'T', permissions: ['cms.manage'] },
      { key: 'reviews', path: '/reviews', icon: 'R', permissions: ['cms.manage'] },
      { key: 'gallery', path: '/gallery', icon: 'G', permissions: ['cms.manage'] },
      { key: 'visas', path: '/visas', icon: 'V', permissions: ['cms.manage'] },
      { key: 'media', path: '/media', icon: 'M', permissions: ['packages.manage', 'cms.manage'] },
      { key: 'settings', path: '/settings', icon: 'S', permissions: ['cms.manage'] },
    ],
  },
  // docs/phase-7-hr-attendance-bonus-wallet.md §4: the design's HR and System groups, as far as they are built.
  {
    key: 'hr',
    items: [
      { key: 'attendance', path: '/attendance', icon: '◷', permissions: ['attendance.view_all', 'attendance.manage'], badge: 'leave_requests' },
      // Salary from attendance (§6).
      { key: 'payroll', path: '/payroll', icon: 'W', permissions: ['payroll.view', 'payroll.manage'] },
      // Staff & bonus: the badge counts bonus withdrawals waiting for a decision or a payment (§7).
      { key: 'staff', path: '/staff', icon: 'S', permissions: ['staff.manage'], badge: 'bonus_withdrawals' },
      // Everyone's own days and leave (§5.1).
      { key: 'my_attendance', path: '/my-attendance', icon: '◴', permissions: [] },
      // Their own sales, bonus account and withdrawals (Phase 5 §4.8, §7).
      { key: 'my_commission', path: '/my-commission', icon: '◈', permissions: ['commission.view_own', 'commission.view_all'] },
    ],
  },
  {
    key: 'system',
    items: [
      { key: 'vault', path: '/vault', icon: 'U', permissions: ['staff_documents.view'], badge: 'staff_documents' },
      // system.roles_manage can't be granted to a role, so this is the super admin's.
      { key: 'roles', path: '/roles', icon: 'Y', permissions: ['system.roles_manage'] },
    ],
  },
]

export const allowed = (item: NavItem, can: (...permissions: string[]) => boolean): boolean => item.permissions.length === 0 || can(...item.permissions)
