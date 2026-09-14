import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useSearchParams } from 'react-router'

import { useAuth } from '../../app/auth'
import { contactActions } from '../../components/table/contactActions'
import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { Dialog, ErrorNotice, useConfirm, useToast } from '../../components/ui/feedback'
import { SelectInput, TextInput } from '../../components/ui/fields'
import { Badge, Card, Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { customerActions, useBoard, useCustomerAction, useCustomers, useDeleteCustomer, type CustomerFilters, type CustomerRow, type LeadState, type PassportStatus } from './api'

const STAGES = ['all', 'lead', 'customer'] as const
const STATES = ['all', 'new', 'contacted', 'quoted', 'converted', 'lost'] as const
const OWNERS = ['all', 'mine', 'pool'] as const
const PASSPORTS = ['all', 'missing', 'expiring'] as const
const SOURCES_FOR_STAFF = ['walk_in', 'phone_call', 'facebook', 'whatsapp', 'referral', 'website_form'] as const

const sourceTone: Record<string, string> = {
  facebook: 'bg-facebook',
  whatsapp: 'bg-whatsapp',
  phone_call: 'bg-app-muted',
  website_form: 'bg-blue',
  walk_in: 'bg-orange',
  referral: 'bg-purple',
}

/** The source pill from the prototype's lead cards: white text on the source's colour. */
export function SourcePill({ source }: { source: string }) {
  const { t } = useTranslation()
  return <span className={`shrink-0 rounded-6 px-1.75 py-0.5 text-11 text-white ${sourceTone[source] ?? 'bg-app-muted'}`}>{t(`sources.${source}`)}</span>
}

export function PassportChip({ status }: { status: PassportStatus }) {
  const { t } = useTranslation()
  return <Badge tone={status === 'on_file' ? 'green' : status === 'expiring' ? 'orange' : 'red'}>{t(`customers.passport.${status}`)}</Badge>
}

function readFilters(params: URLSearchParams): CustomerFilters {
  const pick = <T extends string>(value: string | null, allowed: readonly T[], fallback: T): T => (allowed.includes(value as T) ? (value as T) : fallback)
  return {
    stage: pick(params.get('stage'), STAGES, 'all'),
    state: pick(params.get('state'), STATES, 'all') as LeadState | 'all',
    owner: pick(params.get('owner'), OWNERS, 'all'),
    passport: pick(params.get('passport'), PASSPORTS, 'all'),
    search: params.get('search') ?? '',
    page: Math.max(1, Number(params.get('page')) || 1),
  }
}

export function CustomersPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { number, digits, date } = useFormat()
  const navigate = useNavigate()
  const toast = useToast()
  const { confirm, element: confirmDialog } = useConfirm()
  const [params, setParams] = useSearchParams()
  const filters = readFilters(params)
  const board = useBoard(filters.owner)
  const list = useCustomers(filters)
  const remove = useDeleteCustomer()
  const [creating, setCreating] = useState(false)
  const seesAll = can('bookings.view_all')

  const set = (patch: Partial<CustomerFilters>) => {
    const next = { ...filters, page: 1, ...patch }
    const query = new URLSearchParams()
    for (const key of ['stage', 'state', 'owner', 'passport'] as const) if (next[key] !== 'all') query.set(key, next[key])
    if (next.search) query.set('search', next.search)
    if (next.page > 1) query.set('page', String(next.page))
    setParams(query, { replace: true })
  }

  const actionsFor = (customer: CustomerRow): RowAction[] => {
    const claimFirst = !seesAll && customer.assigned_staff === null ? t('customers.claimFirst') : undefined
    return [
      ...contactActions(t, { phone: customer.phone, email: customer.email }).map((action) => ({ ...action, disabledReason: claimFirst ?? action.disabledReason })),
      { key: 'pdf', icon: '⎙', label: t('table.pdf'), tone: 'amber', disabledReason: t('customers.noDocument') },
      { key: 'view', icon: '◉', label: t('table.view'), tone: 'muted', to: `/customers/${customer.id}` },
      { key: 'edit', icon: '✎', label: t('table.edit'), tone: 'muted', to: `/customers/${customer.id}`, disabledReason: customer.actions.edit ? undefined : claimFirst ?? t('customers.noEdit') },
      {
        key: 'delete',
        icon: '✕',
        label: t('table.delete'),
        tone: 'red',
        onSelect: () => void deleteCustomer(customer),
        disabledReason: !customer.actions.edit
          ? (claimFirst ?? t('customers.noEdit'))
          : customer.has_bookings
            ? t('customers.deleteHasBookings')
            : customer.has_quotations
              ? t('customers.deleteHasQuotations')
              : undefined,
      },
    ]
  }

  const deleteCustomer = async (customer: CustomerRow) => {
    if (!(await confirm(t('customers.deleteConfirm', { name: customer.name })))) return
    remove.mutate(customer.id, { onSuccess: () => toast(t('customers.deleted', { name: customer.name })), onError: (error) => toast(error.message) })
  }

  const columns: Column<CustomerRow>[] = [
    {
      key: 'name',
      header: t('customers.name'),
      cell: (customer) => (
        <div className="flex flex-col gap-0.5">
          <Link to={`/customers/${customer.id}`} className="font-medium">
            {customer.name}
          </Link>
          <span className="flex items-center gap-1.5 text-12 text-app-muted">
            <SourcePill source={customer.source} />
            {customer.stage === 'lead' ? t(`customers.state.${customer.lead_state}`) : t('customers.stage.customer')}
          </span>
        </div>
      ),
    },
    { key: 'phone', header: t('customers.phone'), className: 'font-display text-app-muted whitespace-nowrap', cell: (customer) => digits(customer.phone.replace(/^88/, '')) },
    { key: 'passport', header: t('customers.passportColumn'), cell: (customer) => <PassportChip status={customer.passport_status} /> },
    {
      key: 'trips',
      header: t('customers.trips'),
      align: 'right',
      cell: (customer) => (
        <div className="flex flex-col items-end">
          <span>{t('customers.tripCount', { count: customer.trips_completed, n: number(customer.trips_completed) })}</span>
          {customer.next_trip ? (
            <Link to={`/bookings/${customer.next_trip.booking_id}`} className="text-12">
              {t('customers.nextTrip', { date: date(customer.next_trip.travel_start) })}
            </Link>
          ) : null}
        </div>
      ),
    },
    {
      key: 'owner',
      header: t('ownership.owner'),
      cell: (customer) =>
        customer.assigned_staff ? <span className="text-app-muted">{customer.assigned_staff.name}</span> : <span className="rounded-pill bg-purple-tint px-2 py-0.25 text-11 font-semibold text-purple">{t('ownership.pool')}</span>,
    },
  ]

  return (
    <>
      <PageHeader
        title={t('customers.title')}
        subtitle={t('customers.subtitle')}
        actions={
          can('customers.manage') ? (
            <button type="button" className={buttonClass('primary')} onClick={() => setCreating(true)}>
              {t('customers.newLead')}
            </button>
          ) : null
        }
      />

      <Chips label={t('ownership.owner')} value={filters.owner} onChange={(owner) => set({ owner })} options={OWNERS.map((value) => ({ value, label: t(`ownership.${value}`) }))} />

      {board.isPending ? (
        <Loading />
      ) : board.isError ? (
        <ErrorNotice error={board.error} />
      ) : (
        <div className="grid-auto-fit-240 grid gap-3.5" data-testid="lead-board">
          {(['new', 'contacted', 'quoted', 'converted'] as const).map((state) => (
            <section key={state} className="flex flex-col gap-2.5 rounded-16 border border-app-line bg-app-surface p-3.5" data-testid={`lead-column-${state}`}>
              <h2 className="m-0 flex justify-between text-14 font-semibold">
                <Link to={`/customers?state=${state}${state === 'converted' ? '' : '&stage=lead'}`} className="text-app-text no-underline hover:underline">
                  {t(`customers.column.${state}`)}
                </Link>
                <span className="font-display text-app-muted">{number(board.data[state].count)}</span>
              </h2>
              {board.data[state].cards.map((lead) => (
                <Link key={lead.id} to={`/customers/${lead.id}`} className="flex flex-col gap-1 rounded-10 bg-app-surface-2 p-3 text-app-text no-underline hover:outline-1 hover:outline-app-line" data-testid="lead-card">
                  <span className="flex justify-between gap-2 text-14 font-medium">
                    <span className="truncate">{lead.name}</span>
                    <SourcePill source={lead.source} />
                  </span>
                  {lead.interest ? <span className="text-13 text-app-muted">{lead.interest}</span> : null}
                  <LeadCardFooter lead={lead} state={state} />
                </Link>
              ))}
            </section>
          ))}
        </div>
      )}

      <Card padded={false} className="overflow-hidden">
        <div className="flex flex-col gap-2.5 border-b border-app-line p-3.5">
          <h2 className="m-0 text-16 font-semibold">{t('customers.listTitle')}</h2>
          <div className="flex flex-wrap gap-x-5 gap-y-2.5">
            <Chips label={t('customers.stageLabel')} value={filters.stage} onChange={(stage) => set({ stage })} options={STAGES.map((value) => ({ value, label: value === 'all' ? t('common.all') : t(`customers.stage.${value}`) }))} />
            <Chips label={t('customers.passportColumn')} value={filters.passport} onChange={(passport) => set({ passport })} options={PASSPORTS.map((value) => ({ value, label: value === 'all' ? t('customers.anyPassport') : t(`customers.passport.${value}`) }))} />
            {filters.state !== 'all' ? (
              <button type="button" className={buttonClass('outline', 'sm')} onClick={() => set({ state: 'all' })}>
                {t('customers.clearState', { state: t(`customers.state.${filters.state}`) })} ×
              </button>
            ) : (
              <button type="button" className={buttonClass('ghost', 'sm')} onClick={() => set({ state: 'lost' })}>
                {t('customers.showLost')}
              </button>
            )}
          </div>
          <input type="search" value={filters.search} onChange={(event) => set({ search: event.target.value })} placeholder={t('customers.search')} aria-label={t('customers.search')} className={controlClass()} />
        </div>
        {list.isPending ? (
          <Loading />
        ) : list.isError ? (
          <div className="p-4">
            <ErrorNotice error={list.error} />
          </div>
        ) : list.data.data.length === 0 ? (
          <EmptyState title={t('customers.empty')} />
        ) : (
          <DataTable
            label={t('customers.listTitle')}
            testId="customers-table"
            columns={columns}
            rows={list.data.data}
            rowKey={(customer) => customer.id}
            rowLabel={(customer) => customer.name}
            actions={actionsFor}
            onRowClick={(customer) => navigate(`/customers/${customer.id}`)}
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

      <NewLeadDialog open={creating} onClose={() => setCreating(false)} />
      {confirmDialog}
    </>
  )
}

function LeadCardFooter({ lead, state }: { lead: CustomerRow; state: 'new' | 'contacted' | 'quoted' | 'converted' }) {
  const { t } = useTranslation()
  const { relativeAge, dateTime, date } = useFormat()
  if (state === 'new') {
    // Waiting more than a day for a first contact: amber, as the prototype marks it.
    const stale = lead.age_minutes >= 24 * 60
    return <span className={`text-12 ${stale ? 'text-amber' : 'text-app-muted'}`}>{t('customers.waiting', { age: relativeAge(lead.age_minutes) })}</span>
  }
  if (state === 'converted') return <span className="text-12 text-app-muted">{lead.next_trip ? t('customers.nextTrip', { date: date(lead.next_trip.travel_start) }) : t('customers.booked')}</span>
  if (lead.next_follow_up_at) {
    return <span className={`text-12 ${lead.follow_up_overdue ? 'text-amber' : 'text-app-muted'}`}>{t(lead.follow_up_overdue ? 'customers.followUpOverdue' : 'customers.followUp', { when: dateTime(lead.next_follow_up_at) })}</span>
  }
  return <span className="text-12 text-app-muted">{lead.last_contact_at ? t('customers.lastContact', { when: dateTime(lead.last_contact_at) }) : ''}</span>
}

function NewLeadDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [form, setForm] = useState({ name: '', phone: '', email: '', source: 'walk_in', interest: '' })
  const create = useCustomerAction(customerActions.create)
  const fieldError = (name: string) => (create.error instanceof ApiError ? create.error.field(name) : undefined)
  const existing = create.error instanceof ApiError && create.error.code === 'customer_exists'

  return (
    <Dialog open={open} onClose={onClose} title={t('customers.newLead')}>
      <form
        className="flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          create.mutate(
            { name: form.name, phone: form.phone, email: form.email || null, source: form.source, interest: form.interest || null },
            {
              onSuccess: (response) => {
                onClose()
                navigate(`/customers/${response.data.id}`)
              },
            },
          )
        }}
      >
        <TextInput label={t('newBooking.name')} value={form.name} onChange={(name) => setForm({ ...form, name })} error={fieldError('name')} required />
        <TextInput label={t('newBooking.phone')} value={form.phone} onChange={(phone) => setForm({ ...form, phone })} error={fieldError('phone')} inputMode="tel" required />
        <TextInput label={t('newBooking.email')} type="email" value={form.email} onChange={(email) => setForm({ ...form, email })} error={fieldError('email')} />
        <SelectInput label={t('newBooking.source')} value={form.source} onChange={(source) => setForm({ ...form, source })} options={SOURCES_FOR_STAFF.map((value) => ({ value, label: t(`sources.${value}`) }))} />
        <TextInput label={t('customers.interest')} value={form.interest} onChange={(interest) => setForm({ ...form, interest })} hint={t('customers.interestHint')} />
        {existing ? (
          <p role="alert" className="m-0 text-13 text-red">
            {create.error?.message}
          </p>
        ) : create.error ? (
          <ErrorNotice error={create.error} />
        ) : null}
        <div className="flex justify-end gap-2">
          <button type="button" className={buttonClass('outline')} onClick={onClose}>
            {t('common.cancel')}
          </button>
          <button type="submit" className={buttonClass('primary')} disabled={create.isPending}>
            {create.isPending ? t('common.saving') : t('customers.addLead')}
          </button>
        </div>
      </form>
    </Dialog>
  )
}
