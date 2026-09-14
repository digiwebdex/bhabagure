import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useSearchParams } from 'react-router'

import { useAuth } from '../../app/auth'
import { contactActions } from '../../components/table/contactActions'
import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { ErrorNotice, useConfirm, useToast } from '../../components/ui/feedback'
import { Card, CardTitle, Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { api, ApiError } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'
import { useFormat } from '../../lib/useFormat'
import { useCustomer } from '../customers/api'
import { daysLeft, QUOTATION_FILTERS, quotationActions, useOpenQuotationPdf, useQuotationMutation, useQuotationOptions, useQuotations, useQuotationSummary, type QuotationFilter, type QuotationFilters, type QuotationRow } from './api'
import { QuotationFields, QuotationTotals, useQuotationForm } from './QuotationEditor'
import { QuotationStatusBadge } from './QuotationStatusBadge'

const STATUSES = ['all', ...QUOTATION_FILTERS] as const
const OWNERS = ['all', 'mine'] as const

/** Filters live in the URL (docs/phase-5-admin-core.md §3.1): the badge opens ?status=expiring, exactly its count. */
function readFilters(params: URLSearchParams): QuotationFilters {
  const pick = <T extends string>(value: string | null, allowed: readonly T[], fallback: T): T => (allowed.includes(value as T) ? (value as T) : fallback)
  return {
    status: pick<QuotationFilter | 'all'>(params.get('status'), STATUSES, 'all'),
    owner: pick(params.get('owner'), OWNERS, 'all'),
    search: params.get('search') ?? '',
    page: Math.max(1, Number(params.get('page')) || 1),
  }
}

/** Quotations (docs/phase-5-admin-core.md §4.5): KPIs, the list on the shared row-actions table, and a new quotation. */
export function QuotationsPage() {
  const { t } = useTranslation()
  const { bdt, bdtCompact, number, percent, date, digits, locale } = useFormat()
  const { can } = useAuth()
  const navigate = useNavigate()
  const toast = useToast()
  const { confirm, element: confirmDialog } = useConfirm()
  const [params, setParams] = useSearchParams()
  const filters = readFilters(params)
  const list = useQuotations(filters)
  const summary = useQuotationSummary()
  const openPdf = useOpenQuotationPdf()
  const remove = useQuotationMutation((id: number) => quotationActions.remove(id), () => null)
  const withdraw = useQuotationMutation((id: number) => quotationActions.transition(id, 'withdraw'), (r) => r.data)
  const revise = useQuotationMutation((id: number) => quotationActions.transition(id, 'revise'), (r) => r.data)
  const manage = can('quotations.manage')

  const set = (patch: Partial<QuotationFilters>) => {
    const next = { ...filters, page: 1, ...patch }
    const query = new URLSearchParams()
    if (next.status !== 'all') query.set('status', next.status)
    if (next.owner !== 'all') query.set('owner', next.owner)
    if (next.search) query.set('search', next.search)
    if (next.page > 1) query.set('page', String(next.page))
    const customer = params.get('customer')
    if (customer) query.set('customer', customer)
    setParams(query, { replace: true })
  }

  const actionsFor = (q: QuotationRow): RowAction[] => {
    const title = (locale === 'bn' ? q.package_title_bn : null) || q.package_title_en
    const offered = q.display_status === 'sent' || q.status === 'accepted'
    const contact = contactActions(t, {
      phone: q.customer.phone,
      email: q.customer.email,
      subject: t('quotations.contactSubject', { number: q.number }),
      message: t('quotations.contactMessage', { name: q.customer.name, package: title, number: q.number }),
    })

    return [
      {
        key: 'convert',
        icon: '→',
        label: t('quotations.convert'),
        tone: 'green',
        to: `/quotations/${q.id}?convert=1`,
        disabledReason: q.actions.convert
          ? undefined
          : q.converted_booking
            ? t('quotations.bookedAs', { reference: q.converted_booking.reference })
            : !can('quotations.convert')
              ? t('quotations.noPermission')
              : q.display_status === 'draft'
                ? t('quotations.sendFirst')
                : q.display_status === 'expired'
                  ? t('quotations.expiredRevise')
                  : t('quotations.notOffered'),
      },
      ...contact,
      { key: 'pdf', icon: '⎙', label: t('table.pdf'), tone: 'amber', onSelect: () => void openPdf(q.id) },
      { key: 'view', icon: '◉', label: t('table.view'), tone: 'muted', to: `/quotations/${q.id}` },
      q.status === 'draft'
        ? { key: 'edit', icon: '✎', label: t('table.edit'), tone: 'muted', to: `/quotations/${q.id}`, disabledReason: q.actions.edit ? undefined : t('quotations.noPermission') }
        : {
            key: 'edit',
            icon: '✎',
            label: t('quotations.revise'),
            tone: 'muted',
            onSelect: () => revise.mutate(q.id, { onSuccess: (response) => navigate(`/quotations/${response.data.id}`), onError: (error) => toast(error.message, 'error') }),
            disabledReason: q.actions.revise ? undefined : q.status === 'converted' ? t('quotations.bookedNoRevise') : t('quotations.noPermission'),
          },
      q.status === 'draft'
        ? { key: 'delete', icon: '✕', label: t('table.delete'), tone: 'red', onSelect: () => void deleteDraft(q), disabledReason: q.actions.delete ? undefined : t('quotations.noPermission') }
        : {
            key: 'delete',
            icon: '✕',
            label: t('quotations.withdraw'),
            tone: 'red',
            onSelect: () => void withdrawQuote(q),
            disabledReason: q.actions.withdraw ? undefined : offered ? t('quotations.noPermission') : t('quotations.notOffered'),
          },
    ]
  }

  const deleteDraft = async (q: QuotationRow) => {
    if (!(await confirm(t('quotations.deleteConfirm', { number: q.number })))) return
    remove.mutate(q.id, { onSuccess: () => toast(t('quotations.deleted', { number: q.number })), onError: (error) => toast(error.message, 'error') })
  }
  const withdrawQuote = async (q: QuotationRow) => {
    if (!(await confirm(t('quotations.withdrawConfirm', { number: q.number })))) return
    withdraw.mutate(q.id, { onSuccess: () => toast(t('quotations.withdrawn', { number: q.number })), onError: (error) => toast(error.message, 'error') })
  }

  const counts = list.data?.meta.status_counts
  const columns: Column<QuotationRow>[] = [
    {
      key: 'number',
      header: t('quotations.number'),
      cell: (q) => (
        <div className="flex flex-col gap-0.5">
          <Link to={`/quotations/${q.id}`} className="font-display font-semibold">
            {q.number}
          </Link>
          <span className="text-12 text-app-muted">{date(q.sent_at ?? q.created_at)}</span>
          {q.revision_of ? <span className="text-12 text-app-muted">{t('quotations.revises', { number: q.revision_of.number })}</span> : null}
        </div>
      ),
    },
    {
      key: 'customer',
      header: t('bookings.customer'),
      cell: (q) => (
        <div className="flex max-w-72 flex-col">
          <span className="font-medium">{q.customer.name}</span>
          <span className="truncate text-12 text-app-muted">
            {(locale === 'bn' ? q.package_title_bn : null) || q.package_title_en} · {t('bookings.paxCount', { count: q.pax_count, n: number(q.pax_count) })}
          </span>
          {can('quotations.view_all') && q.assigned_staff ? <span className="text-12 text-app-muted">{q.assigned_staff.name}</span> : null}
        </div>
      ),
    },
    { key: 'value', header: t('quotations.value'), align: 'right', className: 'font-display font-semibold whitespace-nowrap', cell: (q) => bdt(q.total_amount) },
    { key: 'valid', header: t('quotations.validUntil'), cell: (q) => <ValidUntil q={q} /> },
    {
      key: 'status',
      header: t('common.status'),
      cell: (q) => (
        <span className="flex flex-col items-start gap-1">
          <QuotationStatusBadge status={q.display_status} />
          {q.converted_booking ? (
            <Link to={`/bookings/${q.converted_booking.id}`} className="font-display text-12">
              {q.converted_booking.reference}
            </Link>
          ) : null}
        </span>
      ),
    },
  ]

  const kpis = summary.data
  return (
    <>
      <PageHeader title={t('quotations.title')} subtitle={t('quotations.subtitle')} />

      <div className="grid-auto-fit-200 grid gap-3.5" data-testid="quotation-kpis">
        <Kpi label={t('quotations.kpi.open')} value={kpis ? number(kpis.open.count) : '—'} note={kpis ? t('quotations.kpi.openTotal', { total: bdtCompact(kpis.open.total) }) : ''} />
        <Kpi
          label={t('quotations.kpi.conversion')}
          value={kpis?.conversion.percent != null ? percent(kpis.conversion.percent) : '—'}
          note={kpis ? t('quotations.kpi.conversionNote', { converted: number(kpis.conversion.converted), sent: number(kpis.conversion.sent) }) : ''}
          tone="green"
        />
        <Kpi label={t('quotations.kpi.expiring')} value={kpis ? number(kpis.expiring) : '—'} note={t('quotations.kpi.expiringNote')} tone="orange" onClick={() => set({ status: 'expiring' })} />
        <Kpi label={t('quotations.kpi.average')} value={kpis?.average_value != null ? bdtCompact(kpis.average_value) : '—'} note={t('quotations.kpi.averageNote')} />
      </div>

      <div className="grid items-start gap-4.5 xl:grid-cols-[minmax(0,1fr)_minmax(300px,380px)]">
        <div className="flex min-w-0 flex-col gap-3">
          <Chips
            label={t('common.status')}
            value={filters.status}
            onChange={(status) => set({ status })}
            options={STATUSES.map((value) => ({
              value,
              label: value === 'all' ? t('common.all') : counts ? `${t(`quotations.status.${value}`)} · ${number(counts[value])}` : t(`quotations.status.${value}`),
            }))}
          />
          {can('quotations.view_all') ? <Chips label={t('ownership.owner')} value={filters.owner} onChange={(owner) => set({ owner })} options={OWNERS.map((value) => ({ value, label: t(`ownership.${value}`) }))} /> : null}

          <Card padded={false} className="overflow-hidden">
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5 border-b border-app-line px-4.5 py-3.5">
              <CardTitle bn="কোটেশন" en="Quotations" as="h2" />
              <span className="text-12 text-app-muted">{t('quotations.validityNote')}</span>
            </div>
            <div className="border-b border-app-line p-3.5">
              <input type="search" value={filters.search} onChange={(event) => set({ search: event.target.value })} placeholder={t('quotations.search')} aria-label={t('quotations.search')} className={controlClass()} />
            </div>
            {list.isPending ? (
              <Loading />
            ) : list.isError ? (
              <div className="p-4">
                <ErrorNotice error={list.error} />
              </div>
            ) : list.data.data.length === 0 ? (
              <EmptyState title={t('quotations.empty')} note={t('quotations.emptyNote')} />
            ) : (
              <DataTable
                label={t('quotations.title')}
                testId="quotations-table"
                columns={columns}
                rows={list.data.data}
                rowKey={(q) => q.id}
                rowLabel={(q) => q.number}
                actions={actionsFor}
                onRowClick={(q) => navigate(`/quotations/${q.id}`)}
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
        </div>

        {manage ? <NewQuotationPanel customerId={Number(params.get('customer')) || null} digits={digits} /> : null}
      </div>
      {confirmDialog}
    </>
  )
}

function Kpi({ label, value, note, tone, onClick }: { label: string; value: string; note: string; tone?: 'green' | 'orange'; onClick?: () => void }) {
  const noteClass = tone === 'green' ? 'text-green' : tone === 'orange' ? 'text-amber' : 'text-app-muted'
  const body = (
    <>
      <span className="text-13 text-app-muted">{label}</span>
      <span className="font-display text-25 font-extrabold">{value}</span>
      <span className={`text-12 ${noteClass}`}>{note}</span>
    </>
  )
  return onClick ? (
    <button type="button" onClick={onClick} className="flex cursor-pointer flex-col items-start gap-1.25 rounded-16 border border-app-line bg-app-surface px-5 py-4.5 text-left text-app-text hover:border-blue">
      {body}
    </button>
  ) : (
    <div className="flex flex-col gap-1.25 rounded-16 border border-app-line bg-app-surface px-5 py-4.5">{body}</div>
  )
}

/** The date, and how close it is: "Expires today" / "Expired" in red, two days or less in orange (as the prototype). */
export function ValidUntil({ q }: { q: Pick<QuotationRow, 'display_status' | 'status' | 'valid_until' | 'validity_days'> }) {
  const { t } = useTranslation()
  const { date, number } = useFormat()
  if (q.status === 'draft') return <span className="text-13 text-app-muted">{t('quotations.fromSending', { count: q.validity_days, n: number(q.validity_days) })}</span>
  if (q.display_status !== 'sent' && q.display_status !== 'expired') return <span className="text-13 text-app-muted">{date(q.valid_until)}</span>

  const left = daysLeft(q.valid_until)
  const tone = left <= 0 ? 'text-red' : left <= 2 ? 'text-orange' : 'text-app-muted'
  return <span className={`text-13 whitespace-nowrap ${tone}`}>{left < 0 ? t('quotations.expired') : left === 0 ? t('quotations.expiresToday') : date(q.valid_until)}</span>
}

type CustomerHit = { id: number; name: string; phone: string }

/** "New quotation" beside the list, as in the prototype. A customer record is picked, never typed (§4.5). */
function NewQuotationPanel({ customerId, digits }: { customerId: number | null; digits: (text: string) => string }) {
  const { t } = useTranslation()
  const { bdt, locale } = useFormat()
  const toast = useToast()
  const navigate = useNavigate()
  const options = useQuotationOptions()
  const draft = useQuotationForm(options.data, null, locale)
  const [picked, setPicked] = useState<CustomerHit | null>(null)
  const [lookup, setLookup] = useState('')
  const preset = useCustomer(customerId ?? 0, customerId !== null)
  const customer = picked ?? (customerId && preset.data ? { id: preset.data.data.id, name: preset.data.data.name, phone: preset.data.data.phone } : null)

  const hits = useQuery({
    queryKey: ['search', lookup.trim()],
    queryFn: ({ signal }) => api.get<Data<{ customers?: CustomerHit[] }>>(`admin/search?q=${encodeURIComponent(lookup.trim())}`, signal).then((r) => r.data.customers ?? []),
    enabled: !customer && lookup.trim().length >= 2,
  })

  const create = useQuotationMutation(({ send }: { send: boolean }) => {
    const body = draft.body()
    if (!body || !customer) throw new Error('incomplete')
    return quotationActions.create({ ...body, customer_id: customer.id }).then((created) => (send ? quotationActions.transition(created.data.id, 'send') : created))
  }, (r) => r.data)

  if (options.isPending) return <Card><Loading /></Card>
  if (options.isError) return <Card><ErrorNotice error={options.error} /></Card>

  const fieldError = (name: string) => (create.error instanceof ApiError ? create.error.field(name) : undefined)
  const submit = (send: boolean) =>
    create.mutate(
      { send },
      {
        onSuccess: (response) => {
          if (send) {
            toast(t('quotations.sent', { number: response.data.number }))
            draft.reset()
            setPicked(null)
          } else {
            navigate(`/quotations/${response.data.id}`)
          }
        },
        onError: (error) => {
          if (error instanceof ApiError && error.code === 'price_changed') void options.refetch()
        },
      },
    )
  const ready = !!customer && !!draft.quote

  return (
    <Card>
      <CardTitle bn="নতুন কোটেশন" en="New quotation" as="h2" />
      <form
        className="flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          if (ready) submit(true)
        }}
      >
        {customer ? (
          <div className="flex items-center justify-between gap-3 rounded-10 border border-app-line p-3">
            <span className="flex min-w-0 flex-col">
              <span className="text-12 text-app-muted">{t('bookings.customer')}</span>
              <span className="truncate font-medium">{customer.name}</span>
              <span className="font-display text-12 text-app-muted">{digits(customer.phone.replace(/^88/, ''))}</span>
            </span>
            <button type="button" className={buttonClass('outline', 'sm')} onClick={() => (picked ? setPicked(null) : navigate('/quotations', { replace: true }))}>
              {t('common.change')}
            </button>
          </div>
        ) : (
          <div className="flex flex-col gap-2">
            <label className="flex flex-col gap-1.25 text-13 text-app-muted">
              {t('quotations.customer')}
              <input type="search" className={controlClass(!!fieldError('customer_id'))} value={lookup} onChange={(event) => setLookup(event.target.value)} placeholder={t('newBooking.findCustomer')} />
            </label>
            {(hits.data ?? []).map((hit) => (
              <button key={hit.id} type="button" className={buttonClass('outline', 'sm', 'justify-between')} onClick={() => setPicked(hit)}>
                <span>{hit.name}</span>
                <span className="font-display text-12 text-app-muted">{digits(hit.phone.replace(/^88/, ''))}</span>
              </button>
            ))}
            <span className="text-12 text-app-muted">
              {t('quotations.noCustomerYet')} <Link to="/customers">{t('quotations.addLead')}</Link>
            </span>
          </div>
        )}

        <QuotationFields draft={draft} options={options.data} fieldError={fieldError} />
        {draft.quote && draft.form ? <QuotationTotals quote={draft.quote} pax={draft.form.pax} /> : null}
        {create.error && !(create.error instanceof ApiError && create.error.status === 422) ? <ErrorNotice error={create.error} /> : null}

        <div className="flex flex-wrap gap-2">
          <button type="submit" className={buttonClass('cta', 'md', 'min-w-30 flex-1')} disabled={!ready || create.isPending}>
            {create.isPending ? t('common.saving') : draft.quote ? t('quotations.sendFor', { total: bdt(draft.quote.total) }) : t('quotations.send')}
          </button>
          <button type="button" className={buttonClass('outline', 'md', 'min-w-25 flex-1')} disabled={!ready || create.isPending} onClick={() => submit(false)}>
            {t('quotations.saveDraft')}
          </button>
        </div>
        <span className="text-12 text-app-muted">{t('quotations.sendNote')}</span>
      </form>
    </Card>
  )
}
