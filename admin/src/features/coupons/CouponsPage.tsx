import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'

import { useAuth } from '../../app/auth'
import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { ErrorNotice, useConfirm, useToast } from '../../components/ui/feedback'
import { Badge, Card, Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import { COUPON_STATUSES, useCouponAction, useCoupons, useDeleteCoupon, type Coupon, type CouponFilters, type CouponStatus } from './api'
import { CouponDialog } from './CouponDialog'
import { useDescribeDiscount } from './useDescribeDiscount'

const STATUS_TONES: Record<CouponStatus, 'green' | 'blue' | 'slate' | 'orange' | 'red'> = {
  active: 'green',
  scheduled: 'blue',
  expired: 'slate',
  used_up: 'orange',
  inactive: 'red',
  archived: 'slate',
}

/** Filters live in the URL, so a filtered list can be shared or reopened. */
function readFilters(params: URLSearchParams): CouponFilters {
  const status = params.get('status')
  return {
    status: COUPON_STATUSES.includes(status as CouponStatus) ? (status as CouponStatus) : 'all',
    search: params.get('search') ?? '',
    page: Math.max(1, Number(params.get('page')) || 1),
  }
}

/**
 * Admin → Marketing → Coupons (docs/coupons.md §2.7): every coupon with its terms, window, uses and status. Reading needs
 * coupons.view; creating, editing, switching off and archiving coupons.manage.
 */
export function CouponsPage() {
  const { t } = useTranslation()
  const { number, bdt, dateTime } = useFormat()
  const { can } = useAuth()
  const toast = useToast()
  const [params, setParams] = useSearchParams()
  const filters = readFilters(params)
  const list = useCoupons(filters)
  const action = useCouponAction()
  const remove = useDeleteCoupon()
  const { confirm, element: confirmDialog } = useConfirm()
  const describe = useDescribeDiscount()
  const manage = can('coupons.manage')
  // The coupon being edited; 'new' for the create form.
  const [editing, setEditing] = useState<Coupon | 'new' | null>(null)
  const counts = list.data?.meta.status_counts

  const set = (patch: Partial<CouponFilters>) => {
    const next = { ...filters, page: 1, ...patch }
    const query = new URLSearchParams()
    if (next.status !== 'all') query.set('status', next.status)
    if (next.search) query.set('search', next.search)
    if (next.page > 1) query.set('page', String(next.page))
    setParams(query, { replace: true })
  }

  const archiveOrDelete = async (coupon: Coupon) => {
    if (!(await confirm(t(coupon.ever_used ? 'coupons.archiveConfirm' : 'coupons.deleteConfirm', { code: coupon.code })))) return
    remove.mutate(coupon.id, { onSuccess: (response) => toast(t(response.data.result === 'archived' ? 'coupons.archived' : 'coupons.deleted', { code: coupon.code })) })
  }

  const actionsFor = (coupon: Coupon): RowAction[] => {
    const denied = manage ? undefined : t('coupons.noManagePermission')
    const actions: RowAction[] = [
      { key: 'usage', icon: '▤', label: t('coupons.usage'), tone: 'blue', to: `/coupons/report?coupon=${coupon.id}` },
    ]
    if (coupon.status === 'archived') {
      return [...actions, { key: 'restore', icon: '↺', label: t('coupons.restore'), tone: 'green', disabledReason: denied, onSelect: () => action.mutate({ id: coupon.id, action: 'restore' }, { onSuccess: () => toast(t('coupons.restored', { code: coupon.code })) }) }]
    }
    return [
      { key: 'edit', icon: '✎', label: t('coupons.edit'), tone: 'blue', disabledReason: denied, onSelect: () => setEditing(coupon) },
      coupon.is_active
        ? { key: 'deactivate', icon: '⏸', label: t('coupons.deactivate'), tone: 'amber', disabledReason: denied, onSelect: () => action.mutate({ id: coupon.id, action: 'deactivate' }, { onSuccess: () => toast(t('coupons.deactivated', { code: coupon.code })) }) }
        : { key: 'activate', icon: '▶', label: t('coupons.activate'), tone: 'green', disabledReason: denied, onSelect: () => action.mutate({ id: coupon.id, action: 'activate' }, { onSuccess: () => toast(t('coupons.activated', { code: coupon.code })) }) },
      ...actions,
      { key: 'delete', icon: '✕', label: coupon.ever_used ? t('coupons.archive') : t('coupons.delete'), tone: 'red', disabledReason: denied, onSelect: () => void archiveOrDelete(coupon) },
    ]
  }

  const columns: Column<Coupon>[] = [
    {
      key: 'code',
      header: t('coupons.columns.code'),
      cell: (coupon) => (
        <div className="flex flex-col gap-0.5">
          <span className="font-display font-bold tracking-chip">{coupon.code}</span>
          <span className="text-12 text-app-muted">
            {coupon.name}
            {coupon.channel ? ` · ${t(`coupons.channels.${coupon.channel}`)}` : ''}
          </span>
        </div>
      ),
    },
    {
      key: 'type',
      header: t('coupons.columns.type'),
      cell: (coupon) => (
        <div className="flex flex-col items-start gap-0.5">
          <Badge tone={coupon.kind === 'passport' ? 'orange' : 'blue'}>{t(`coupons.kinds.${coupon.kind}`)}</Badge>
          {coupon.kind === 'passport' ? (
            <span className="text-12 text-app-muted">{[coupon.holder_name, coupon.passport_masked].filter(Boolean).join(' · ')}</span>
          ) : null}
        </div>
      ),
    },
    {
      key: 'discount',
      header: t('coupons.columns.discount'),
      cell: (coupon) => {
        const [off, minimum] = describe(coupon)
        return (
          <div className="flex flex-col gap-0.5 text-13">
            <span className="font-semibold whitespace-nowrap">{off}</span>
            {minimum ? <span className="text-12 text-app-muted">{minimum}</span> : null}
            {coupon.applies_to === 'packages' ? <span className="text-12 text-app-muted">{t('coupons.onPackages', { count: coupon.packages.length, n: number(coupon.packages.length) })}</span> : null}
          </div>
        )
      },
    },
    {
      key: 'validity',
      header: t('coupons.columns.validity'),
      cell: (coupon) => (
        <div className="flex flex-col gap-0.5 text-12 whitespace-nowrap">
          {coupon.starts_at || coupon.ends_at ? (
            <>
              <span>{coupon.starts_at ? t('coupons.from', { at: dateTime(coupon.starts_at) }) : t('coupons.fromNow')}</span>
              <span className="text-app-muted">{coupon.ends_at ? t('coupons.until', { at: dateTime(coupon.ends_at) }) : t('coupons.noEnd')}</span>
            </>
          ) : (
            <span className="text-app-muted">{t('coupons.always')}</span>
          )}
        </div>
      ),
    },
    {
      key: 'usage',
      header: t('coupons.columns.usage'),
      cell: (coupon) => (
        <div className="flex flex-col gap-0.5 text-13 whitespace-nowrap">
          <span>
            <strong>{number(coupon.usage.used)}</strong> {t('coupons.used')}
            {coupon.usage.pending ? ` · ${number(coupon.usage.pending)} ${t('coupons.pending')}` : ''}
            {coupon.usage_limit ? <span className="text-app-muted"> / {number(coupon.usage_limit)}</span> : null}
          </span>
          {coupon.usage.discount_given ? <span className="text-12 text-app-muted">{t('coupons.given', { amount: bdt(coupon.usage.discount_given) })}</span> : null}
        </div>
      ),
    },
    { key: 'status', header: t('coupons.columns.status'), cell: (coupon) => <Badge tone={STATUS_TONES[coupon.status]}>{t(`coupons.statuses.${coupon.status}`)}</Badge> },
  ]

  return (
    <>
      <PageHeader
        title={t('coupons.title')}
        subtitle={t('coupons.subtitle')}
        actions={
          <>
            <Link to="/coupons/report" className={buttonClass('outline')}>
              {t('coupons.report')}
            </Link>
            {manage ? (
              <button type="button" className={buttonClass('cta')} onClick={() => setEditing('new')}>
                {t('coupons.new')}
              </button>
            ) : null}
          </>
        }
      />

      <Chips
        label={t('coupons.columns.status')}
        value={filters.status}
        onChange={(status) => set({ status })}
        options={(['all', ...COUPON_STATUSES] as const).map((value) => ({
          value,
          label: `${t(`coupons.statuses.${value}`)}${counts ? ` · ${number(counts[value])}` : ''}`,
        }))}
      />

      <Card padded={false} className="overflow-hidden">
        <div className="border-b border-app-line p-3.5">
          <input type="search" value={filters.search} onChange={(event) => set({ search: event.target.value })} placeholder={t('coupons.search')} aria-label={t('coupons.search')} className={controlClass()} />
        </div>
        {action.error || remove.error ? (
          <div className="p-3.5">
            <ErrorNotice error={action.error ?? remove.error} />
          </div>
        ) : null}
        {list.isPending ? (
          <Loading />
        ) : list.isError ? (
          <div className="p-4">
            <ErrorNotice error={list.error} />
          </div>
        ) : list.data.data.length === 0 ? (
          <EmptyState
            title={filters.status === 'all' && !filters.search ? t('coupons.empty') : t('coupons.emptyFiltered')}
            note={t('coupons.emptyNote')}
            action={manage && filters.status === 'all' && !filters.search ? <button type="button" className={buttonClass('cta')} onClick={() => setEditing('new')}>{t('coupons.new')}</button> : undefined}
          />
        ) : (
          <DataTable label={t('coupons.title')} testId="coupons-table" columns={columns} rows={list.data.data} rowKey={(coupon) => coupon.id} rowLabel={(coupon) => coupon.code} actions={actionsFor} />
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

      {editing ? <CouponDialog key={editing === 'new' ? 'new' : editing.id} coupon={editing === 'new' ? null : editing} open onClose={() => setEditing(null)} /> : null}
      {confirmDialog}
    </>
  )
}
