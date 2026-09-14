import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useSearchParams } from 'react-router'

import { useAuth } from '../../app/auth'
import { contactActions } from '../../components/table/contactActions'
import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { ErrorNotice, useConfirm, useToast } from '../../components/ui/feedback'
import { Card, Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { fetchDocument } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { useBookings, useClaimBooking, useDeleteBooking, type BookingFilters, type BookingStatus, type BookingSummary, type PaymentStatus } from './api'
import { BookingStatusBadge, PaymentBadge } from './badges'

const STATUSES = ['all', 'inquiry', 'confirmed', 'completed', 'cancelled'] as const
const PAYMENTS = ['all', 'unpaid', 'partial', 'paid'] as const
const OWNERS = ['all', 'mine', 'pool'] as const

/** Filters are read from and written to the URL (docs/phase-5-admin-core.md §3.1): a badge opens exactly its list. */
function readFilters(params: URLSearchParams): BookingFilters {
  const pick = <T extends string>(value: string | null, allowed: readonly T[], fallback: T): T => (allowed.includes(value as T) ? (value as T) : fallback)
  return {
    status: pick<BookingStatus | 'all'>(params.get('status'), STATUSES, 'all'),
    payment_status: pick<PaymentStatus | 'all'>(params.get('payment_status'), PAYMENTS, 'all'),
    owner: pick(params.get('owner'), OWNERS, 'all'),
    search: params.get('search') ?? '',
    page: Math.max(1, Number(params.get('page')) || 1),
  }
}

export function BookingListPage() {
  const { t } = useTranslation()
  const { bdt, number, date, digits, locale } = useFormat()
  const { can } = useAuth()
  const navigate = useNavigate()
  const toast = useToast()
  const { confirm, element: confirmDialog } = useConfirm()
  const [params, setParams] = useSearchParams()
  const filters = readFilters(params)
  const list = useBookings(filters)
  const claim = useClaimBooking()
  const remove = useDeleteBooking()
  const seesAll = can('bookings.view_all')

  const set = (patch: Partial<BookingFilters>) => {
    const next = { ...filters, page: 1, ...patch }
    const query = new URLSearchParams()
    if (next.status !== 'all') query.set('status', next.status)
    if (next.payment_status !== 'all') query.set('payment_status', next.payment_status)
    if (next.owner !== 'all') query.set('owner', next.owner)
    if (next.search) query.set('search', next.search)
    if (next.page > 1) query.set('page', String(next.page))
    setParams(query, { replace: true })
  }

  const openPdf = async (booking: BookingSummary) => {
    try {
      const blob = await fetchDocument(`admin/bookings/${booking.id}/invoice/pdf?lang=${locale}`)
      window.open(URL.createObjectURL(blob), '_blank', 'noopener')
    } catch {
      toast(t('bookings.pdfFailed'))
    }
  }

  const actionsFor = (booking: BookingSummary): RowAction[] => {
    // Staff who don't see every booking work only their own: a pool booking is claimed before anyone contacts the customer.
    const claimFirst = !seesAll && booking.assigned_staff === null ? t('bookings.claimFirst') : undefined
    const final = booking.status === 'completed' || booking.status === 'cancelled'
    const contact = contactActions(t, {
      phone: booking.customer?.phone,
      email: booking.customer?.email,
      subject: t('bookings.contactSubject', { reference: booking.reference }),
    }).map((action) => ({ ...action, disabledReason: claimFirst ?? action.disabledReason }))

    return [
      ...contact,
      {
        key: 'pdf',
        icon: '⎙',
        label: t('table.pdf'),
        tone: 'amber',
        onSelect: () => void openPdf(booking),
        disabledReason: booking.has_invoice ? undefined : t('bookings.noInvoiceYet'),
      },
      { key: 'view', icon: '◉', label: t('table.view'), tone: 'muted', to: `/bookings/${booking.id}` },
      {
        key: 'edit',
        icon: '✎',
        label: t('table.edit'),
        tone: 'muted',
        to: `/bookings/${booking.id}`,
        disabledReason: !can('bookings.update') ? t('bookings.noEditPermission') : claimFirst ?? (final ? t('bookings.closedNoEdit') : booking.has_invoice ? t('bookings.frozenNote') : undefined),
      },
      {
        key: 'delete',
        icon: '✕',
        label: t('table.delete'),
        tone: 'red',
        onSelect: () => void deleteBooking(booking),
        disabledReason: !can('bookings.delete')
          ? t('bookings.noDeletePermission')
          : booking.has_invoice
            ? t('bookings.deleteHasInvoice')
            : booking.has_payments
              ? t('bookings.deleteHasPayments')
              : undefined,
      },
    ]
  }

  const deleteBooking = async (booking: BookingSummary) => {
    if (!(await confirm(t('bookings.deleteConfirm', { reference: booking.reference })))) return
    remove.mutate(booking.id, { onSuccess: () => toast(t('bookings.deleted', { reference: booking.reference })), onError: (error) => toast(error.message) })
  }

  const counts = list.data?.meta.status_counts
  const columns: Column<BookingSummary>[] = [
    {
      key: 'reference',
      header: t('bookings.reference'),
      cell: (booking) => (
        <div className="flex flex-col gap-1">
          <Link to={`/bookings/${booking.id}`} className="font-display font-semibold">
            {booking.reference}
          </Link>
          <span className="text-12 text-app-muted">{date(booking.created_at)}</span>
          {booking.claimable ? (
            <span className="flex items-center gap-1.5">
              <span title={t('ownership.poolNote')} className="rounded-pill bg-purple-tint px-2 py-0.25 text-11 font-semibold text-purple">
                {t('ownership.pool')}
              </span>
              {can('bookings.update') ? (
                <button
                  type="button"
                  className={buttonClass('outline', 'sm', 'px-2 py-0.5 text-11')}
                  disabled={claim.isPending}
                  onClick={() => claim.mutate(booking.id, { onSuccess: () => toast(t('ownership.claimed')), onError: (error) => toast(error.message) })}
                >
                  {t('ownership.claim')}
                </button>
              ) : null}
            </span>
          ) : booking.assigned_staff && seesAll ? (
            <span className="text-12 text-app-muted">{booking.assigned_staff.name}</span>
          ) : null}
        </div>
      ),
    },
    {
      key: 'customer',
      header: t('bookings.customer'),
      cell: (booking) => (
        <div className="flex flex-col">
          <span className="font-medium">{booking.customer?.name ?? '—'}</span>
          {booking.customer ? <span className="font-display text-12 text-app-muted">{digits(booking.customer.phone.replace(/^88/, ''))}</span> : null}
        </div>
      ),
    },
    {
      key: 'package',
      header: t('bookings.package'),
      cell: (booking) => (
        <div className="flex max-w-72 flex-col">
          <span className="truncate">{(locale === 'bn' ? booking.package_title_bn : null) || booking.package_title_en}</span>
          <span className="text-12 text-app-muted">
            {booking.travel_start ? date(booking.travel_start) : '—'} · {t('bookings.paxCount', { count: booking.pax_count, n: number(booking.pax_count) })}
          </span>
        </div>
      ),
    },
    { key: 'total', header: t('bookings.total'), align: 'right', className: 'font-display font-semibold whitespace-nowrap', cell: (booking) => bdt(booking.total_amount) },
    {
      key: 'due',
      header: t('bookings.due'),
      align: 'right',
      className: 'font-display whitespace-nowrap',
      cell: (booking) => <span className={booking.due_amount > 0 ? 'text-amber' : 'text-app-muted'}>{bdt(booking.due_amount)}</span>,
    },
    {
      key: 'status',
      header: t('common.status'),
      cell: (booking) => (
        <span className="flex flex-wrap gap-1.5">
          <BookingStatusBadge status={booking.status} />
          <PaymentBadge status={booking.payment_status} />
        </span>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t('bookings.title')} subtitle={t('bookings.subtitle')} />

      <div className="flex flex-col gap-2.5">
        <Chips
          label={t('common.status')}
          value={filters.status}
          onChange={(status) => set({ status })}
          options={STATUSES.map((value) => ({
            value,
            label: value === 'all' ? t('common.all') : counts ? `${t(`bookings.status.${value}`)} · ${number(counts[value])}` : t(`bookings.status.${value}`),
          }))}
        />
        <div className="flex flex-wrap gap-x-5 gap-y-2.5">
          <Chips
            label={t('bookings.payment')}
            value={filters.payment_status}
            onChange={(payment_status) => set({ payment_status })}
            options={PAYMENTS.map((value) => ({ value, label: value === 'all' ? t('bookings.anyPayment') : t(`bookings.paymentStatus.${value}`) }))}
          />
          <Chips label={t('ownership.owner')} value={filters.owner} onChange={(owner) => set({ owner })} options={OWNERS.map((value) => ({ value, label: t(`ownership.${value}`) }))} />
        </div>
      </div>

      <Card padded={false} className="overflow-hidden">
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
          <DataTable
            label={t('bookings.title')}
            testId="bookings-table"
            columns={columns}
            rows={list.data.data}
            rowKey={(booking) => booking.id}
            rowLabel={(booking) => booking.reference}
            actions={actionsFor}
            onRowClick={(booking) => navigate(`/bookings/${booking.id}`)}
          />
        )}
        {list.data && list.data.meta.last_page > 1 ? (
          <div className="flex items-center justify-between gap-3 border-t border-app-line px-4 py-3 text-13">
            <button type="button" className={buttonClass('outline', 'sm')} disabled={filters.page <= 1} onClick={() => set({ page: filters.page - 1 })}>
              {t('common.previous')}
            </button>
            <span className="text-app-muted">{t('common.pageOf', { page: number(filters.page), last: number(list.data.meta.last_page) })}</span>
            <button type="button" className={buttonClass('outline', 'sm')} disabled={filters.page >= list.data.meta.last_page} onClick={() => set({ page: filters.page + 1 })}>
              {t('common.next')}
            </button>
          </div>
        ) : null}
      </Card>
      {confirmDialog}
    </>
  )
}
