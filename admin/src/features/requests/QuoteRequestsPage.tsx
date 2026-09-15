import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'

import { useAuth } from '../../app/auth'
import { contactActions } from '../../components/table/contactActions'
import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { ErrorNotice, useToast } from '../../components/ui/feedback'
import { Badge, Card, CardTitle, Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import { requestActions, useRequestAction, useRequests, type QuoteRequest, type RequestFilters, type RequestKind } from './api'
import { RequestDialog } from './RequestDialog'

const STATES = ['open', 'quoted', 'all'] as const
const OWNERS = ['all', 'mine', 'pool'] as const

/** What differs between the queues: the words (an i18n namespace) and how a request is summarised. */
export type RequestSpec<T extends QuoteRequest> = {
  kind: RequestKind
  /** i18n namespace: air.*, hotel.* */
  ns: string
  cardTitle: { bn: string; en: string }
  testId: string
  /** The request in a few words: "Dhaka → Bangkok", "Cox's Bazar". */
  summary: (row: T) => string
  /** The line under it: dates, people, class or category. */
  details: (row: T, format: ReturnType<typeof useFormat>, t: ReturnType<typeof useTranslation>['t']) => ReactNode
  /** The facts in the detail dialog, after name, phone and email. */
  facts: (row: T, format: ReturnType<typeof useFormat>, t: ReturnType<typeof useTranslation>['t']) => [string, string][]
  /** Opening words for a message from the staff member's own WhatsApp or email. */
  contact: (row: T, t: ReturnType<typeof useTranslation>['t']) => { subject: string; message: string }
}

/** Filters live in the URL: the sidebar badge opens ?state=open&stale=1, exactly the rows it counts. */
function readFilters(params: URLSearchParams): RequestFilters {
  const pick = <V extends string>(value: string | null, allowed: readonly V[], fallback: V): V => (allowed.includes(value as V) ? (value as V) : fallback)
  return {
    state: pick(params.get('state'), STATES, 'open'),
    stale: params.get('stale') === '1',
    owner: pick(params.get('owner'), OWNERS, 'all'),
    search: params.get('search') ?? '',
    page: Math.max(1, Number(params.get('page')) || 1),
  }
}

/**
 * A quotation-request queue (Air ticketing, docs/phase-5-admin-core.md §4.7; Hotel requests, Phase 8 §4.B): the website's
 * requests, oldest open ones first, flagged after 24 hours. Staff claim one from the pool, send the customer a reply
 * (WhatsApp and email, logged) or write from their own WhatsApp or email, and mark it quoted.
 */
export function QuoteRequestsPage<T extends QuoteRequest>({ spec }: { spec: RequestSpec<T> }) {
  const { t } = useTranslation()
  const format = useFormat()
  const { number, relativeAge, digits } = format
  const { can } = useAuth()
  const toast = useToast()
  const [params, setParams] = useSearchParams()
  const filters = readFilters(params)
  const list = useRequests<T>(spec.kind, filters)
  const actions = requestActions(spec.kind)
  const [viewing, setViewing] = useState<{ id: number; reply: boolean } | null>(null)
  const claim = useRequestAction(spec.kind, actions.claim)
  const quote = useRequestAction(spec.kind, actions.markQuoted)
  const undo = useRequestAction(spec.kind, actions.undoQuoted)
  const seesAll = can('bookings.view_all')
  const ns = spec.ns

  const set = (patch: Partial<RequestFilters>) => {
    const next = { ...filters, page: 1, ...patch }
    const query = new URLSearchParams({ state: next.state })
    if (next.stale) query.set('stale', '1')
    if (next.owner !== 'all') query.set('owner', next.owner)
    if (next.search) query.set('search', next.search)
    if (next.page > 1) query.set('page', String(next.page))
    setParams(query, { replace: true })
  }

  const actionsFor = (row: T): RowAction[] => {
    // As on Bookings: someone who doesn't see every request claims a pool one before contacting the customer.
    const claimFirst = !seesAll && row.assigned_staff === null ? t(`${ns}.claimFirst`) : undefined
    const contact = spec.contact(row, t)
    return [
      { key: 'view', icon: '◉', label: t('table.view'), tone: 'muted', onSelect: () => setViewing({ id: row.id, reply: false }) },
      {
        key: 'reply',
        icon: '↩',
        label: t('requests.reply'),
        tone: 'blue',
        onSelect: () => setViewing({ id: row.id, reply: true }),
        disabledReason: row.actions.reply ? undefined : t('requests.noReplyPermission'),
      },
      ...contactActions(t, { phone: row.phone, email: row.email, channels: ['whatsapp', 'email'], ...contact }).map((action) => ({
        ...action,
        disabledReason: claimFirst ?? action.disabledReason,
      })),
      row.status === 'new'
        ? {
            key: 'quoted',
            icon: '✓',
            label: t(`${ns}.markQuoted`),
            tone: 'green',
            onSelect: () => quote.mutate(row.id, { onSuccess: () => toast(t(`${ns}.quoted`, { name: row.name })), onError: (error) => toast(error.message, 'error') }),
            disabledReason: row.actions.mark_quoted ? undefined : t(`${ns}.noPermission`),
          }
        : {
            key: 'quoted',
            icon: '↺',
            label: t(`${ns}.undoQuoted`),
            tone: 'amber',
            onSelect: () => undo.mutate(row.id, { onSuccess: () => toast(t(`${ns}.reopened`, { name: row.name })), onError: (error) => toast(error.message, 'error') }),
            disabledReason: row.actions.undo_quoted ? undefined : t(`${ns}.undoOnlyBy`),
          },
    ]
  }

  const columns: Column<T>[] = [
    {
      key: 'passenger',
      header: t(`${ns}.passenger`),
      cell: (row) => (
        <div className="flex flex-col gap-0.5">
          <span className="font-medium">{row.name}</span>
          <span className="font-display text-12 text-app-muted">{digits(row.phone.replace(/^88/, ''))}</span>
          {row.stale ? (
            <span className="self-start rounded-pill bg-red-tint px-2 py-0.25 text-11 font-semibold text-red" data-testid="stale-flag">
              {t(`${ns}.waiting`, { age: relativeAge(row.age_hours * 60) })}
            </span>
          ) : (
            <span className="text-12 text-app-muted">{relativeAge(Math.floor((Date.now() - Date.parse(row.created_at)) / 60_000))}</span>
          )}
        </div>
      ),
    },
    {
      key: 'route',
      header: t(`${ns}.route`),
      cell: (row) => (
        <div className="flex max-w-72 flex-col">
          <span className="truncate font-medium">{spec.summary(row)}</span>
          <span className="text-12 text-app-muted">{spec.details(row, format, t)}</span>
          {row.replies_count > 0 ? (
            <span className="text-12 text-blue" data-testid="replies-count">
              {t('requests.repliesCount', { count: row.replies_count, n: number(row.replies_count) })}
            </span>
          ) : null}
        </div>
      ),
    },
    {
      key: 'owner',
      header: t('ownership.owner'),
      cell: (row) =>
        row.assigned_staff ? (
          <span className="text-13">{row.assigned_staff.name}</span>
        ) : (
          <span className="flex items-center gap-1.5">
            <span title={t('ownership.poolNote')} className="rounded-pill bg-purple-tint px-2 py-0.25 text-11 font-semibold text-purple">
              {t('ownership.pool')}
            </span>
            {row.actions.claim ? (
              <button
                type="button"
                className={buttonClass('outline', 'sm', 'px-2 py-0.5 text-11')}
                disabled={claim.isPending}
                onClick={() => claim.mutate(row.id, { onSuccess: () => toast(t('ownership.claimed')), onError: (error) => toast(error.message, 'error') })}
              >
                {t('ownership.claim')}
              </button>
            ) : null}
          </span>
        ),
    },
    {
      key: 'status',
      header: t('common.status'),
      cell: (row) => (
        <span className="flex flex-col items-start gap-0.5">
          <Badge tone={row.status === 'quoted' ? 'green' : row.stale ? 'red' : 'orange'}>{t(`${ns}.status.${row.status}`)}</Badge>
          {row.quoted_by ? <span className="text-12 text-app-muted">{row.quoted_by.name}</span> : null}
        </span>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t(`${ns}.title`)} subtitle={t(`${ns}.subtitle`)} />

      <div className="flex flex-wrap gap-x-5 gap-y-2.5">
        <Chips label={t('common.status')} value={filters.state} onChange={(state) => set({ state, stale: state === 'open' ? filters.stale : false })} options={STATES.map((value) => ({ value, label: t(`${ns}.states.${value}`) }))} />
        {filters.state === 'open' ? (
          <Chips label={t(`${ns}.waitingFilter`)} value={filters.stale ? 'stale' : 'any'} onChange={(value) => set({ stale: value === 'stale' })} options={[{ value: 'any', label: t(`${ns}.anyAge`) }, { value: 'stale', label: t(`${ns}.over24h`) }]} />
        ) : null}
        <Chips label={t('ownership.owner')} value={filters.owner} onChange={(owner) => set({ owner })} options={OWNERS.map((value) => ({ value, label: t(`ownership.${value}`) }))} />
      </div>

      <Card padded={false} className="overflow-hidden">
        <div className="flex flex-col gap-3 border-b border-app-line p-3.5">
          <CardTitle bn={spec.cardTitle.bn} en={spec.cardTitle.en} aside={<span className="text-12 text-app-muted">{t(`${ns}.queueNote`)}</span>} />
          <input type="search" value={filters.search} onChange={(event) => set({ search: event.target.value })} placeholder={t(`${ns}.search`)} aria-label={t(`${ns}.search`)} className={controlClass()} />
        </div>
        {list.isPending ? (
          <Loading />
        ) : list.isError ? (
          <div className="p-4">
            <ErrorNotice error={list.error} />
          </div>
        ) : list.data.data.length === 0 ? (
          <EmptyState title={t(`${ns}.empty`)} note={t(`${ns}.emptyNote`)} />
        ) : (
          <DataTable
            label={t(`${ns}.title`)}
            testId={spec.testId}
            columns={columns}
            rows={list.data.data}
            rowKey={(row) => row.id}
            rowLabel={(row) => row.name}
            actions={actionsFor}
            onRowClick={(row) => setViewing({ id: row.id, reply: false })}
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
      {viewing ? <RequestDialog key={`${viewing.id}-${viewing.reply}`} spec={spec} id={viewing.id} startReplying={viewing.reply} onClose={() => setViewing(null)} /> : null}
    </>
  )
}
