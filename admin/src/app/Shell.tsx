import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, NavLink, Outlet, useLocation } from 'react-router'

import { buttonClass } from '../components/ui/button'
import { HeaderToolsContext } from '../components/ui/layout'
import { useFormat } from '../lib/useFormat'
import { useTheme } from '../lib/useTheme'
import { useAuth, useStaff } from './auth'
import { HeaderSearch } from './HeaderSearch'
import { badgeHref, useNavCounts } from './navCounts'
import { allowed, NAV_GROUPS } from './navigation'

/** Admin shell from the prototype: navy sidebar (a drawer below 1024px), bilingual nav, controls at the foot. */
export function Shell() {
  const { t } = useTranslation()
  const [navOpen, setNavOpen] = useState(false)
  const location = useLocation()
  const { can } = useAuth()
  // The header search covers bookings, customers and quotations; staff who see none of them get no search box.
  const searchable = can('bookings.view_all', 'bookings.view_own', 'customers.view', 'quotations.view_all', 'quotations.view_own')

  // Close the drawer after navigating.
  const [lastPath, setLastPath] = useState(location.pathname)
  if (lastPath !== location.pathname) {
    setLastPath(location.pathname)
    setNavOpen(false)
  }

  useEffect(() => {
    if (!navOpen) return
    const onKey = (event: KeyboardEvent) => event.key === 'Escape' && setNavOpen(false)
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [navOpen])

  return (
    <div className="flex min-h-dvh bg-app-bg text-app-text">
      <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-90 focus:rounded-8 focus:bg-app-surface focus:px-3 focus:py-2">
        {t('shell.skipToContent')}
      </a>
      {navOpen ? <div aria-hidden onClick={() => setNavOpen(false)} className="fixed inset-0 z-58 bg-scrim/50 lg:hidden" /> : null}
      <aside
        aria-label={t('shell.navigation')}
        className={`fixed top-0 left-0 z-60 flex h-dvh w-sidebar shrink-0 flex-col gap-1.5 overflow-y-auto bg-app-nav px-3.5 py-5 text-app-nav-text transition-transform duration-260 lg:sticky lg:translate-x-0 ${navOpen ? 'translate-x-0 shadow-drawer' : '-translate-x-full'}`}
      >
        <SidebarContent />
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <div className="flex items-center gap-3 px-admin-x pt-admin-y lg:hidden">
          <button
            type="button"
            onClick={() => setNavOpen(true)}
            aria-label={t('shell.openMenu')}
            aria-expanded={navOpen}
            className="flex h-10 w-10.5 cursor-pointer flex-col items-center justify-center gap-1 rounded-11 border border-app-line bg-app-surface text-app-text"
          >
            <span className="block h-0.5 w-4.25 rounded-2 bg-current" />
            <span className="block h-0.5 w-4.25 rounded-2 bg-current" />
            <span className="block h-0.5 w-4.25 rounded-2 bg-current" />
          </button>
        </div>
        <main id="main" className="flex min-w-0 flex-1 flex-col gap-admin-gap px-admin-x py-admin-y">
          <HeaderToolsContext.Provider
            value={
              searchable || can('bookings.create') ? (
                <>
                  {searchable ? <HeaderSearch /> : null}
                  {can('bookings.create') && location.pathname !== '/bookings/new' ? (
                    <Link to="/bookings/new" className={buttonClass('cta')}>
                      {t('shell.newBooking')}
                    </Link>
                  ) : null}
                </>
              ) : null
            }
          >
            <Outlet />
          </HeaderToolsContext.Provider>
        </main>
      </div>
    </div>
  )
}

function SidebarContent() {
  const { t, i18n } = useTranslation()
  const { locale, number } = useFormat()
  const { theme, toggle } = useTheme()
  const { can, signOut } = useAuth()
  const staff = useStaff()
  const counts = useNavCounts(NAV_GROUPS.some((group) => group.items.some((item) => item.badge && can(...item.permissions))))
  const isBn = locale === 'bn'

  const controlClass = 'flex cursor-pointer items-center gap-2 rounded-10 border border-white/15 bg-transparent px-3 py-2 text-left text-13 text-inherit'
  const initials = staff.name
    .split(/\s+/)
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase()

  return (
    <>
      <div className="flex flex-col gap-1.75 px-2 pt-1 pb-5">
        <img src="/brand/logo-wordmark-light.png" alt={t('shell.brand')} className="block h-auto w-full max-w-sidebar-logo" />
        <span className="pl-0.5 font-display text-10 tracking-eyebrow-wide text-orange uppercase">
          {t(`roles.${staff.is_super_admin ? 'super_admin' : (staff.role ?? 'staff')}`, { defaultValue: (isBn ? staff.role_name_bn : staff.role_name_en) ?? staff.role_name_en ?? '' })}
        </span>
      </div>

      <nav className="flex flex-col gap-1.5">
        {NAV_GROUPS.map((group) => {
          const items = group.items.filter((item) => allowed(item, can))
          if (items.length === 0) return null
          return (
            <div key={group.key} className="flex flex-col gap-1">
              {group.heading === false ? null : <div className="px-3 pt-4 pb-1.5 font-display text-10 font-extrabold tracking-eyebrow text-white/40 uppercase">{t(`nav.groups.${group.key}`)}</div>}
              {items.map((item) => {
                const badge = item.badge ? counts.data?.[item.badge] : undefined
                return (
                  <div key={item.path} className="relative flex items-center">
                    <NavLink
                      to={item.path}
                      end={item.path === '/'}
                      className={({ isActive }) =>
                        `flex w-full items-center gap-2.5 rounded-10 px-3 py-2.5 text-left text-15 font-medium hover:bg-white/8 hover:text-white ${badge?.count ? 'pr-12' : ''} ${isActive ? 'bg-white/10 text-white' : 'text-app-nav-text'}`
                      }
                    >
                      {({ isActive }) => (
                        <>
                          <span className={`flex size-5.5 shrink-0 items-center justify-center rounded-6 font-display text-11 font-extrabold text-white ${isActive ? 'bg-orange' : 'bg-white/10'}`}>{item.icon}</span>
                          <span className="flex min-w-0 flex-1 flex-col leading-1.4">
                            <span>{t(`nav.items.${item.key}`, { lng: isBn ? 'bn' : 'en' })}</span>
                            {isBn ? <span className="font-display text-11 opacity-70">{t(`nav.items.${item.key}`, { lng: 'en' })}</span> : null}
                          </span>
                        </>
                      )}
                    </NavLink>
                    {badge && badge.count > 0 ? (
                      // Its own link: opens the list with exactly the filter this number counts.
                      <Link
                        to={badgeHref(item.path, badge.filter)}
                        data-testid={`nav-badge-${item.badge}`}
                        aria-label={t(`nav.badges.${item.badge}`, { count: badge.count, n: number(badge.count) })}
                        className="absolute right-2.5 shrink-0 rounded-pill bg-orange px-1.75 py-0.5 text-11 font-bold text-white no-underline hover:bg-orange-press"
                      >
                        {number(badge.count)}
                      </Link>
                    ) : null}
                  </div>
                )
              })}
            </div>
          )
        })}
      </nav>

      <div className="mt-auto flex shrink-0 flex-col gap-2 px-2 pt-6">
        <button type="button" className={controlClass} onClick={() => void i18n.changeLanguage(isBn ? 'en' : 'bn')}>
          <span className={isBn ? undefined : 'opacity-40'}>বাংলা</span>
          <span aria-hidden className="h-2.75 w-px bg-white/25" />
          <span className={isBn ? 'opacity-40' : undefined}>English</span>
        </button>
        <button type="button" className={controlClass} onClick={toggle}>
          {theme === 'light' ? t('shell.darkMode') : t('shell.lightMode')}
        </button>
        <div className="flex items-center gap-2.5 pt-1.5">
          <span aria-hidden className="flex size-8.5 shrink-0 items-center justify-center rounded-full bg-linear-135/srgb from-blue to-orange font-display font-bold text-white">
            {initials}
          </span>
          <div className="min-w-0 flex-1 leading-1.2">
            <NavLink to="/profile" className="block truncate text-14 font-semibold text-white hover:text-white hover:underline" title={t('shell.profile')}>
              {staff.name}
            </NavLink>
            <button type="button" onClick={() => void signOut()} className="cursor-pointer bg-transparent p-0 text-11 text-app-nav-text underline-offset-2 hover:text-white hover:underline">
              {t('shell.signOut')}
            </button>
          </div>
        </div>
      </div>
    </>
  )
}
