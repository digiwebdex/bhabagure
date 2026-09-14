import { useQuery } from '@tanstack/react-query'
import { useEffect, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation } from 'react-router'

import { controlClass } from '../components/ui/controls'
import { api } from '../lib/api/client'
import type { Data } from '../lib/api/types'
import { useFormat } from '../lib/useFormat'

type Results = {
  bookings?: { id: number; reference: string; status: string; customer: string | null }[]
  customers?: { id: number; name: string; phone: string; stage: string }[]
  quotations?: { id: number; number: string; status: string; customer: string }[]
}

/**
 * The header search (docs/phase-5-admin-core.md §4.1): grouped results from GET /admin/search, which applies the same
 * visibility as the lists — a sales agent never finds a colleague's booking here either.
 */
export function HeaderSearch() {
  const { t } = useTranslation()
  const { digits } = useFormat()
  const [text, setText] = useState('')
  const [query, setQuery] = useState('')
  const [open, setOpen] = useState(false)
  const box = useRef<HTMLDivElement>(null)
  const location = useLocation()
  const listId = useId()

  // Debounced: one request after typing pauses.
  useEffect(() => {
    const timer = window.setTimeout(() => setQuery(text.trim()), 250)
    return () => window.clearTimeout(timer)
  }, [text])

  // Close after navigating to a result.
  const [lastPath, setLastPath] = useState(location.pathname)
  if (lastPath !== location.pathname) {
    setLastPath(location.pathname)
    setOpen(false)
    setText('')
  }

  useEffect(() => {
    if (!open) return
    const onPointer = (event: PointerEvent) => !box.current?.contains(event.target as Node) && setOpen(false)
    document.addEventListener('pointerdown', onPointer)
    return () => document.removeEventListener('pointerdown', onPointer)
  }, [open])

  const results = useQuery({
    queryKey: ['search', query],
    queryFn: ({ signal }) => api.get<Data<Results>>(`admin/search?q=${encodeURIComponent(query)}`, signal).then((response) => response.data),
    enabled: query.length >= 2,
    staleTime: 10_000,
  })

  const bookings = results.data?.bookings ?? []
  const customers = results.data?.customers ?? []
  const quotations = results.data?.quotations ?? []
  const showPanel = open && query.length >= 2

  return (
    <div ref={box} className="relative w-full sm:w-header-search" onKeyDown={(event) => event.key === 'Escape' && setOpen(false)}>
      <input
        type="search"
        value={text}
        onChange={(event) => {
          setText(event.target.value)
          setOpen(true)
        }}
        onFocus={() => setOpen(true)}
        placeholder={t('search.placeholder')}
        aria-label={t('search.placeholder')}
        aria-expanded={showPanel}
        aria-controls={listId}
        className={controlClass()}
      />
      {showPanel ? (
        <div id={listId} role="region" aria-live="polite" aria-label={t('search.results')} className="absolute top-full right-0 z-40 mt-1.5 flex max-h-dialog-h w-full flex-col gap-3 overflow-y-auto rounded-12 border border-app-line bg-app-surface p-3 shadow-popover sm:w-header-search-panel">
          {results.isPending ? (
            <span className="text-13 text-app-muted">{t('common.loading')}</span>
          ) : bookings.length === 0 && customers.length === 0 && quotations.length === 0 ? (
            <span className="text-13 text-app-muted">{t('search.nothing', { query })}</span>
          ) : (
            <>
              {bookings.length > 0 ? (
                <section className="flex flex-col gap-1">
                  <h3 className="m-0 px-2 font-display text-11 font-bold tracking-eyebrow text-app-muted uppercase">{t('search.bookings')}</h3>
                  {bookings.map((booking) => (
                    <Link key={booking.id} to={`/bookings/${booking.id}`} className="flex items-center justify-between gap-3 rounded-8 px-2 py-1.5 text-14 text-app-text no-underline hover:bg-app-surface-2">
                      <span className="font-display font-semibold">{booking.reference}</span>
                      <span className="truncate text-13 text-app-muted">{booking.customer ?? '—'}</span>
                    </Link>
                  ))}
                </section>
              ) : null}
              {customers.length > 0 ? (
                <section className="flex flex-col gap-1">
                  <h3 className="m-0 px-2 font-display text-11 font-bold tracking-eyebrow text-app-muted uppercase">{t('search.customers')}</h3>
                  {customers.map((customer) => (
                    <Link key={customer.id} to={`/customers/${customer.id}`} className="flex items-center justify-between gap-3 rounded-8 px-2 py-1.5 text-14 text-app-text no-underline hover:bg-app-surface-2">
                      <span className="truncate font-medium">{customer.name}</span>
                      <span className="font-display text-13 text-app-muted">{digits(customer.phone.replace(/^88/, ''))}</span>
                    </Link>
                  ))}
                </section>
              ) : null}
              {quotations.length > 0 ? (
                <section className="flex flex-col gap-1">
                  <h3 className="m-0 px-2 font-display text-11 font-bold tracking-eyebrow text-app-muted uppercase">{t('search.quotations')}</h3>
                  {quotations.map((quotation) => (
                    <Link key={quotation.id} to={`/quotations/${quotation.id}`} className="flex items-center justify-between gap-3 rounded-8 px-2 py-1.5 text-14 text-app-text no-underline hover:bg-app-surface-2">
                      <span className="font-display font-semibold">{quotation.number}</span>
                      <span className="truncate text-13 text-app-muted">{quotation.customer}</span>
                    </Link>
                  ))}
                </section>
              ) : null}
            </>
          )}
        </div>
      ) : null}
    </div>
  )
}
