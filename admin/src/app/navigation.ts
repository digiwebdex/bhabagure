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
      { key: 'notifications', path: '/notifications', icon: 'N', permissions: ['notifications.manage'] },
    ],
  },
  {
    key: 'services',
    items: [
      { key: 'air_ticketing', path: '/air-ticketing', icon: 'A', permissions: ['air_inquiries.view'], badge: 'air_inquiries' },
      { key: 'documents', path: '/documents', icon: 'V', permissions: ['bookings.view_all', 'bookings.view_own'], badge: 'documents' },
    ],
  },
  // Portal support tickets (docs/phase-6-customer-portal.md §3.5); the design's Communication group.
  {
    key: 'communication',
    items: [{ key: 'support', path: '/support', icon: 'M', permissions: ['support.manage'], badge: 'support' }],
  },
  {
    key: 'finance',
    items: [{ key: 'payments', path: '/payments', icon: '৳', permissions: ['payments.view'] }],
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
      { key: 'media', path: '/media', icon: 'M', permissions: ['packages.manage', 'cms.manage'] },
      { key: 'settings', path: '/settings', icon: 'S', permissions: ['cms.manage'] },
    ],
  },
  // docs/phase-7-hr-attendance-bonus-wallet.md §4: the design's HR and System groups, as far as they are built.
  {
    key: 'hr',
    items: [
      { key: 'attendance', path: '/attendance', icon: '◷', permissions: ['attendance.view_all', 'attendance.manage'], badge: 'leave_requests' },
      { key: 'staff', path: '/staff', icon: 'S', permissions: ['staff.manage'] },
      // Everyone's own days and leave (§5.1).
      { key: 'my_attendance', path: '/my-attendance', icon: '◴', permissions: [] },
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
