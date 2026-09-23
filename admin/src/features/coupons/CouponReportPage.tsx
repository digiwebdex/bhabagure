import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'

import { DataTable, type Column } from '../../components/table/DataTable'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { ErrorNotice } from '../../components/ui/feedback'
import { Badge, Card, CardTitle, Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import { useCouponReport, type CouponReport, type CouponUse, type ReportFilters, type UseStatus } from './api'

const USE_STATUSES = ['used', 'reserved', 'released'] as const
const DATE = /^\d{4}-\d{2}-\d{2}$/
const USE_TONES: Record<UseStatus, 'green' | 'blue' | 'slate'> = { used: 'green', reserved: 'blue', released: 'slate' }

/** Filters live in the URL: "Usage" on a coupon opens this page filtered to it. */
function readFilters(params: URLSearchParams): ReportFilters {
  const date = (value: string | null) => (value && DATE.test(value) ? value : '')
  const status = params.get('status')
  return {
    from: date(params.get('from')),
    to: date(params.get('to')),
    coupon: Number(params.get('coupon')) || null,
    status: USE_STATUSES.includes(status as UseStatus) ? (status as UseStatus) : 'all',
    search: params.get('search') ?? '',
    passport: params.get('passport') ?? '',
    page: Math.max(1, Number(params.get('page')) || 1),
  }
}

/**
 * Admin → Marketing → Coupon report (docs/coupons.md §2.7): what coupons gave and brought in — used (confirmed bookings),
 * pending (open ones) and released (given back) — by coupon, by campaign, the passport coupons' uses, and every use.
 */
export function CouponReportPage() {
  const { t } = useTranslation()
  const { number, bdt } = useFormat()
  const [params, setParams] = useSearchParams()
  const filters = readFilters(params)
  const report = useCouponReport(filters)
  const data = report.data?.data
  const totals = data?.totals

  const set = (patch: Partial<ReportFilters>) => {
    const next = { ...filters, page: 1, ...patch }
    const query = new URLSearchParams()
    if (next.from) query.set('from', next.from)
    if (next.to) query.set('to', next.to)
    if (next.coupon) query.set('coupon', String(next.coupon))
    if (next.status !== 'all') query.set('status', next.status)
    if (next.search) query.set('search', next.search)
    if (next.passport) query.set('passport', next.passport)
    if (next.page > 1) query.set('page', String(next.page))
    setParams(query, { replace: true })
  }

  const couponColumns: Column<CouponReport['coupons'][number]>[] = [
    {
      key: 'code',
      header: t('coupons.columns.code'),
      cell: (row) => (
        <div className="flex flex-col gap-0.5">
          <span className="font-display font-bold">{row.code}</span>
          <span className="text-12 text-app-muted">{row.name}</span>
        </div>
      ),
    },
    { key: 'kind', header: t('coupons.columns.type'), cell: (row) => t(`coupons.kinds.${row.kind}`) },
    { key: 'channel', header: t('coupons.fields.channel'), cell: (row) => (row.channel ? t(`coupons.channels.${row.channel}`) : '—') },
    { key: 'status', header: t('coupons.columns.status'), cell: (row) => t(`coupons.statuses.${row.status}`) },
    ...figureColumns<CouponReport['coupons'][number]>(t, number, bdt, true),
  ]

  const campaignColumns: Column<CouponReport['campaigns'][number]>[] = [
    { key: 'name', header: t('couponReport.campaign'), cell: (row) => <span className="font-semibold">{row.name}</span> },
    { key: 'channels', header: t('couponReport.channels'), cell: (row) => row.channels.map((channel) => t(`coupons.channels.${channel}`)).join(', ') || '—' },
    { key: 'coupons', header: t('couponReport.couponsCount'), align: 'right', cell: (row) => number(row.coupons) },
    ...figureColumns<CouponReport['campaigns'][number]>(t, number, bdt, false),
  ]

  const useColumns: Column<CouponUse>[] = [
    { key: 'applied', header: t('couponReport.applied'), cell: (use) => <DateCell iso={use.applied_at} /> },
    {
      key: 'code',
      header: t('coupons.columns.code'),
      cell: (use) => (
        <div className="flex flex-col gap-0.5">
          <span className="font-display font-semibold">{use.code}</span>
          <span className="text-12 text-app-muted">{t(`couponReport.sources.${use.source}`)}</span>
        </div>
      ),
    },
    {
      key: 'booking',
      header: t('couponReport.booking'),
      cell: (use) =>
        use.booking ? (
          <div className="flex flex-col gap-0.5">
            <Link to={`/bookings/${use.booking.id}`} className="font-medium" onClick={(event) => event.stopPropagation()}>
              {use.booking.reference}
            </Link>
            <span className="text-12 text-app-muted">{t(`bookings.status.${use.booking.status}`)}</span>
          </div>
        ) : (
          '—'
        ),
    },
    {
      key: 'customer',
      header: t('couponReport.customer'),
      cell: (use) =>
        use.customer ? (
          <div className="flex flex-col gap-0.5">
            <span>{use.customer.name}</span>
            <span className="font-display text-12 text-app-muted">{use.customer.phone.replace(/^88/, '')}</span>
          </div>
        ) : (
          '—'
        ),
    },
    { key: 'passport', header: t('couponReport.passport'), cell: (use) => <span className="font-display text-13">{use.passport_masked ?? '—'}</span> },
    { key: 'discount', header: t('couponReport.discount'), align: 'right', cell: (use) => <span className="font-display font-semibold whitespace-nowrap">− {bdt(use.discount_amount)}</span> },
    { key: 'total', header: t('couponReport.bookingTotal'), align: 'right', cell: (use) => <span className="font-display whitespace-nowrap">{bdt(use.final_total)}</span> },
    {
      key: 'status',
      header: t('coupons.columns.status'),
      cell: (use) => (
        <div className="flex flex-col items-start gap-0.5">
          <Badge tone={USE_TONES[use.status]}>{t(`couponReport.useStatuses.${use.status}`)}</Badge>
          {use.release_reason ? <span className="text-12 text-app-muted">{t(`couponReport.releaseReasons.${use.release_reason}`)}</span> : null}
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title={t('couponReport.title')}
        subtitle={t('couponReport.subtitle')}
        actions={
          <Link to="/coupons" className={buttonClass('outline')}>
            {t('couponReport.allCoupons')}
          </Link>
        }
      />

      <div className="grid-auto-fit-160 grid gap-3.5" data-testid="coupon-report-totals">
        <Total label={t('couponReport.totals.coupons')} value={totals ? number(totals.coupons) : '—'} />
        <Total label={t('couponReport.totals.active')} value={totals ? number(totals.active) : '—'} />
        <Total label={t('couponReport.totals.expired')} value={totals ? number(totals.expired) : '—'} />
        <Total label={t('couponReport.totals.used')} value={totals ? number(totals.used) : '—'} note={totals && totals.pending ? t('couponReport.totals.pendingNote', { n: number(totals.pending) }) : undefined} />
        <Total label={t('couponReport.totals.discount')} value={totals ? bdt(totals.discount_given) : '—'} />
        <Total label={t('couponReport.totals.revenue')} value={totals ? bdt(totals.revenue) : '—'} />
      </div>

      <div className="flex flex-wrap items-end gap-x-5 gap-y-2.5">
        <Chips
          label={t('coupons.columns.status')}
          value={filters.status}
          onChange={(status) => set({ status })}
          options={(['all', ...USE_STATUSES] as const).map((value) => ({ value, label: t(`couponReport.useStatuses.${value}`) }))}
        />
        <label className="flex flex-col gap-1 text-12 text-app-muted">
          {t('couponReport.from')}
          <input type="date" value={filters.from} max={filters.to || undefined} onChange={(event) => set({ from: event.target.value })} className={controlClass()} />
        </label>
        <label className="flex flex-col gap-1 text-12 text-app-muted">
          {t('couponReport.to')}
          <input type="date" value={filters.to} min={filters.from || undefined} onChange={(event) => set({ to: event.target.value })} className={controlClass()} />
        </label>
        <label className="flex min-w-48 flex-col gap-1 text-12 text-app-muted">
          {t('couponReport.coupon')}
          <select value={filters.coupon ?? ''} onChange={(event) => set({ coupon: Number(event.target.value) || null })} className={controlClass()}>
            <option value="">{t('couponReport.allCouponsOption')}</option>
            {/* The coupons this report knows: every live coupon, and archived ones with uses. */}
            {(data?.coupons ?? []).map((row) => (
              <option key={row.id} value={row.id}>
                {row.code}
              </option>
            ))}
          </select>
        </label>
      </div>
      <div className="grid-auto-fit-260 grid gap-3">
        <input type="search" value={filters.search} onChange={(event) => set({ search: event.target.value })} placeholder={t('couponReport.search')} aria-label={t('couponReport.search')} className={controlClass()} />
        <input type="search" value={filters.passport} onChange={(event) => set({ passport: event.target.value })} placeholder={t('couponReport.passportSearch')} aria-label={t('couponReport.passportSearch')} className={controlClass()} autoCapitalize="characters" />
      </div>

      {report.isPending ? (
        <Loading />
      ) : report.isError ? (
        <ErrorNotice error={report.error} />
      ) : (
        <>
          <Card padded={false} className="overflow-hidden">
            <div className="border-b border-app-line p-3.5">
              <CardTitle title={t('couponReport.byCoupon')} />
            </div>
            {report.data.data.coupons.length === 0 ? (
              <EmptyState title={t('couponReport.noCoupons')} />
            ) : (
              <DataTable label={t('couponReport.byCoupon')} testId="coupon-report-coupons" columns={couponColumns} rows={report.data.data.coupons} rowKey={(row) => row.id} rowLabel={(row) => row.code} />
            )}
          </Card>

          <Card padded={false} className="overflow-hidden">
            <div className="border-b border-app-line p-3.5">
              <CardTitle title={t('couponReport.byCampaign')} aside={<span className="text-12 text-app-muted">{t('couponReport.campaignNote')}</span>} />
            </div>
            {report.data.data.campaigns.length === 0 ? (
              <EmptyState title={t('couponReport.noCoupons')} />
            ) : (
              <DataTable label={t('couponReport.byCampaign')} testId="coupon-report-campaigns" columns={campaignColumns} rows={report.data.data.campaigns} rowKey={(row) => row.name} rowLabel={(row) => row.name} />
            )}
          </Card>

          <Card padded={false} className="overflow-hidden">
            <div className="border-b border-app-line p-3.5">
              <CardTitle title={t('couponReport.passportUses')} aside={<span className="text-12 text-app-muted">{t('couponReport.passportNote')}</span>} />
            </div>
            {report.data.data.passport_uses.length === 0 ? (
              <EmptyState title={t('couponReport.noUses')} />
            ) : (
              <DataTable
                label={t('couponReport.passportUses')}
                testId="coupon-report-passport-uses"
                columns={[useColumns[0], useColumns[1], { key: 'holder', header: t('coupons.fields.holder'), cell: (use) => use.holder_name ?? '—' }, ...useColumns.slice(2)]}
                rows={report.data.data.passport_uses}
                rowKey={(use) => use.id}
                rowLabel={(use) => use.code}
              />
            )}
          </Card>

          <Card padded={false} className="overflow-hidden">
            <div className="border-b border-app-line p-3.5">
              <CardTitle title={t('couponReport.uses')} aside={<span className="text-12 text-app-muted">{t('couponReport.usesCount', { count: report.data.meta.total, n: number(report.data.meta.total) })}</span>} />
            </div>
            {report.data.data.uses.length === 0 ? (
              <EmptyState title={t('couponReport.noUses')} note={t('couponReport.noUsesNote')} />
            ) : (
              <DataTable label={t('couponReport.uses')} testId="coupon-report-uses" columns={useColumns} rows={report.data.data.uses} rowKey={(use) => use.id} rowLabel={(use) => use.booking?.reference ?? use.code} />
            )}
            {report.data.meta.last_page > 1 ? (
              <div className="flex items-center justify-between gap-3 border-t border-app-line px-4 py-3 text-13">
                <button type="button" className={buttonClass('outline', 'sm')} disabled={filters.page <= 1} onClick={() => set({ page: filters.page - 1 })}>
                  {t('common.previous')}
                </button>
                <span className="text-app-muted">{t('common.pageOf', { page: number(filters.page), last: number(report.data.meta.last_page) })}</span>
                <button type="button" className={buttonClass('outline', 'sm')} disabled={filters.page >= report.data.meta.last_page} onClick={() => set({ page: filters.page + 1 })}>
                  {t('common.next')}
                </button>
              </div>
            ) : null}
          </Card>
        </>
      )}
    </>
  )
}

type Figures = { used: number; pending: number; discount_given: number; revenue: number }

/** Uses, pending, discount given and revenue — the same four columns in the coupon and campaign tables. */
function figureColumns<T extends Figures>(t: (key: string) => string, number: (value: number) => string, bdt: (value: number) => string, released: boolean): Column<T>[] {
  return [
    { key: 'used', header: t('couponReport.columns.used'), align: 'right', cell: (row) => <strong>{number(row.used)}</strong> },
    { key: 'pending', header: t('couponReport.columns.pending'), align: 'right', cell: (row) => number(row.pending) },
    ...(released ? [{ key: 'released', header: t('couponReport.columns.released'), align: 'right' as const, cell: (row: T) => number((row as T & { released: number }).released) }] : []),
    { key: 'discount', header: t('couponReport.columns.discount'), align: 'right', cell: (row) => <span className="font-display whitespace-nowrap">{bdt(row.discount_given)}</span> },
    { key: 'revenue', header: t('couponReport.columns.revenue'), align: 'right', cell: (row) => <span className="font-display whitespace-nowrap">{bdt(row.revenue)}</span> },
  ]
}

function DateCell({ iso }: { iso: string }) {
  const { dateTime } = useFormat()
  return <span className="text-13 whitespace-nowrap">{dateTime(iso)}</span>
}

function Total({ label, value, note }: { label: string; value: string; note?: string }) {
  return (
    <div className="flex flex-col gap-1.25 rounded-16 border border-app-line bg-app-surface px-5 py-4.5">
      <span className="text-13 text-app-muted">{label}</span>
      <span className="font-display text-22 font-extrabold">{value}</span>
      {note ? <span className="text-12 text-app-muted">{note}</span> : null}
    </div>
  )
}
