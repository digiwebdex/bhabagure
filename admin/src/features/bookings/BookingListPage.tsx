import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { ErrorNotice } from '../../components/ui/feedback'
import { Card, Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import { useBookings, type BookingFilters } from './api'
import { BookingStatusBadge, PaymentBadge } from './badges'

export function BookingListPage() {
  const { t } = useTranslation()
  const { bdt, number, date, digits, locale } = useFormat()
  const [filters, setFilters] = useState<BookingFilters>({ status: 'all', payment: 'all', search: '', page: 1 })
  const list = useBookings(filters)
  const set = (patch: Partial<BookingFilters>) => setFilters((current) => ({ ...current, page: 1, ...patch }))

  return (
    <>
      <PageHeader title={t('bookings.title')} subtitle={t('bookings.subtitle')} />

      <div className="flex flex-col gap-2.5">
        <Chips
          label={t('common.status')}
          value={filters.status}
          onChange={(status) => set({ status })}
          options={(['all', 'inquiry', 'confirmed', 'completed', 'cancelled'] as const).map((value) => ({ value, label: value === 'all' ? t('common.all') : t(`bookings.status.${value}`) }))}
        />
        <Chips
          label={t('bookings.payment')}
          value={filters.payment}
          onChange={(payment) => set({ payment })}
          options={(['all', 'unpaid', 'partial', 'paid'] as const).map((value) => ({ value, label: value === 'all' ? t('bookings.anyPayment') : t(`bookings.paymentStatus.${value}`) }))}
        />
      </div>

      <Card padded={false} className="overflow-x-auto">
        <div className="border-b border-app-line p-3.5">
          <input type="search" value={filters.search} onChange={(event) => set({ search: event.target.value })} placeholder={t('bookings.search')} aria-label={t('bookings.search')} className={controlClass()} />
        </div>
        {list.isPending ? (
          <Loading />
        ) : list.isError ? (
          <div className="p-4">
            <ErrorNotice error={list.error} />
          </div>
        ) : list.data.data.length === 0 ? (
          <EmptyState title={t('bookings.empty')} note={t('bookings.emptyNote')} />
        ) : (
          <table className="w-full min-w-180 border-collapse text-13">
            <thead>
              <tr className="border-b border-app-line text-left text-12 text-app-muted">
                <th className="px-4 py-2.5 font-semibold">{t('bookings.reference')}</th>
                <th className="px-4 py-2.5 font-semibold">{t('bookings.customer')}</th>
                <th className="px-4 py-2.5 font-semibold">{t('bookings.package')}</th>
                <th className="px-4 py-2.5 text-right font-semibold">{t('bookings.total')}</th>
                <th className="px-4 py-2.5 text-right font-semibold">{t('bookings.due')}</th>
                <th className="px-4 py-2.5 font-semibold">{t('common.status')}</th>
              </tr>
            </thead>
            <tbody>
              {list.data.data.map((booking) => (
                <tr key={booking.id} className="border-b border-app-line last:border-b-0 hover:bg-app-surface-2">
                  <td className="px-4 py-3">
                    <Link to={`/bookings/${booking.id}`} className="font-display font-semibold">
                      {booking.reference}
                    </Link>
                    <div className="text-12 text-app-muted">{date(booking.created_at)}</div>
                  </td>
                  <td className="px-4 py-3">
                    <div className="font-medium">{booking.customer?.name ?? '—'}</div>
                    {booking.customer ? <div className="font-display text-12 text-app-muted">{digits(booking.customer.phone.replace(/^88/, ''))}</div> : null}
                  </td>
                  <td className="max-w-72 px-4 py-3">
                    <div className="truncate">{(locale === 'bn' ? booking.package_title_bn : null) || booking.package_title_en}</div>
                    <div className="text-12 text-app-muted">
                      {booking.travel_start ? date(booking.travel_start) : '—'} · {t('bookings.paxCount', { count: booking.pax_count, n: number(booking.pax_count) })}
                    </div>
                  </td>
                  <td className="px-4 py-3 text-right font-display font-semibold whitespace-nowrap">{bdt(booking.total_amount)}</td>
                  <td className={`px-4 py-3 text-right font-display whitespace-nowrap ${booking.due_amount > 0 ? 'text-amber' : 'text-app-muted'}`}>{bdt(booking.due_amount)}</td>
                  <td className="px-4 py-3">
                    <span className="flex flex-wrap gap-1.5">
                      <BookingStatusBadge status={booking.status} />
                      <PaymentBadge status={booking.payment_status} />
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {list.data && list.data.meta.last_page > 1 ? (
          <div className="flex items-center justify-between gap-3 border-t border-app-line px-4 py-3 text-13">
            <button type="button" className={buttonClass('outline', 'sm')} disabled={filters.page <= 1} onClick={() => setFilters((f) => ({ ...f, page: f.page - 1 }))}>
              {t('common.previous')}
            </button>
            <span className="text-app-muted">{t('common.pageOf', { page: number(filters.page), last: number(list.data.meta.last_page) })}</span>
            <button type="button" className={buttonClass('outline', 'sm')} disabled={filters.page >= list.data.meta.last_page} onClick={() => setFilters((f) => ({ ...f, page: f.page + 1 }))}>
              {t('common.next')}
            </button>
          </div>
        ) : null}
      </Card>
    </>
  )
}
