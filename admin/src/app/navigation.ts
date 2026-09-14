/**
 * Sidebar groups in the design's order, built screens only (docs/phase-5-admin-core.md §4.1).
 * `permissions`: any one of them grants access (api/routes/api.php uses the same rules).
 * `badge`: a key of GET /admin/nav-counts — the count is derived from data, never written here.
 */
export type NavItem = { key: string; path: string; icon: string; permissions: string[]; badge?: string }

export const NAV_GROUPS: { key: string; items: NavItem[] }[] = [
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
]

export const firstAllowedPath = (can: (...permissions: string[]) => boolean): string | null =>
  NAV_GROUPS.flatMap((group) => group.items).find((item) => can(...item.permissions))?.path ?? null
