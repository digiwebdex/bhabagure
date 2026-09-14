import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useSearchParams } from 'react-router'

import { contactActions } from '../../components/table/contactActions'
import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { Dialog, ErrorNotice } from '../../components/ui/feedback'
import { Pair, SelectInput, TextInput } from '../../components/ui/fields'
import { Badge, Card, Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import { roleLabel, useCreateStaff, useStaffList, useStaffOptions, type Invitation, type NewStaff, type StaffDetail, type StaffFilters, type StaffListStatus, type StaffRow } from './api'
import { InvitationDialog } from './InvitationDialog'

const STATUSES: StaffListStatus[] = ['current', 'active', 'invited', 'suspended', 'all']

/**
 * HR → Staff (docs/phase-7-hr-attendance-bonus-wallet.md §4.1): everyone with an admin account, their role, sales closed
 * this month and documents needing attention. Adding someone invites them to set their own password.
 */
export function StaffPage() {
  const { t } = useTranslation()
  const { locale, dateTime, number } = useFormat()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const filters: StaffFilters = {
    status: STATUSES.includes(params.get('status') as StaffListStatus) ? (params.get('status') as StaffListStatus) : 'current',
    search: params.get('search') ?? '',
    page: Math.max(1, Number(params.get('page')) || 1),
  }
  const list = useStaffList(filters)
  const [adding, setAdding] = useState(false)
  const [invited, setInvited] = useState<{ staff: StaffDetail; invitation: Invitation } | null>(null)

  const set = (patch: Partial<StaffFilters>) => {
    const next = { ...filters, page: 1, ...patch }
    const query = new URLSearchParams({ status: next.status })
    if (next.search) query.set('search', next.search)
    if (next.page > 1) query.set('page', String(next.page))
    setParams(query, { replace: true })
  }

  const actionsFor = (row: StaffRow): RowAction[] => [
    { key: 'open', icon: '◉', label: t('staff.open'), tone: 'muted', to: `/staff/${row.id}` },
    ...contactActions(t, { phone: row.phone, email: row.email, channels: ['whatsapp', 'email'], subject: t('staff.emailSubject') }),
  ]

  const statusBadge = (row: StaffRow) => (
    <span className="flex flex-col items-start gap-0.5">
      <Badge tone={row.status === 'active' ? 'green' : row.status === 'invited' ? 'blue' : 'slate'}>{t(`staff.status.${row.status}`)}</Badge>
      <span className="text-12 text-app-muted">
        {row.status === 'invited'
          ? row.invitation_expires_at
            ? t('staff.inviteExpires', { date: dateTime(row.invitation_expires_at) })
            : t('staff.inviteLapsed')
          : row.last_login_at
            ? t('staff.lastSignIn', { date: dateTime(row.last_login_at) })
            : t('staff.neverSignedIn')}
      </span>
    </span>
  )

  const columns: Column<StaffRow>[] = [
    {
      key: 'person',
      header: t('staff.columns.person'),
      cell: (row) => (
        <div className="flex flex-col gap-0.5">
          <span className="font-medium">{row.name}</span>
          <span className="text-12 text-app-muted">
            {row.email} · <span className="font-display">{row.employee_code}</span>
          </span>
        </div>
      ),
    },
    {
      key: 'role',
      header: t('staff.columns.role'),
      cell: (row) => (
        <div className="flex flex-col gap-0.5">
          <span>{roleLabel(row.role, locale)}</span>
          {row.designation ? <span className="text-12 text-app-muted">{row.designation}</span> : null}
        </div>
      ),
    },
    { key: 'status', header: t('common.status'), cell: statusBadge },
    { key: 'sales', header: t('staff.columns.sales'), align: 'right', cell: (row) => <span className="font-display">{number(row.closed_sales_month)}</span> },
    ...(list.data?.data.some((row) => row.documents_attention !== null)
      ? [
          {
            key: 'documents',
            header: t('staff.columns.documents'),
            cell: (row: StaffRow) =>
              row.documents_attention ? <Badge tone="red">{t('staff.documentsAttention', { count: row.documents_attention, n: number(row.documents_attention) })}</Badge> : <span className="text-12 text-app-muted">—</span>,
          },
        ]
      : []),
  ]

  return (
    <>
      <PageHeader
        title={t('staff.title')}
        subtitle={t('staff.subtitle')}
        actions={
          <button type="button" className={buttonClass('cta')} onClick={() => setAdding(true)}>
            {t('staff.add')}
          </button>
        }
      />
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Chips
          label={t('common.status')}
          value={filters.status}
          onChange={(status) => set({ status })}
          options={STATUSES.map((value) => ({
            value,
            label: list.data && (value === 'active' || value === 'invited' || value === 'suspended') ? `${t(`staff.states.${value}`)} · ${number(list.data.meta.status_counts[value])}` : t(`staff.states.${value}`),
          }))}
        />
        <input type="search" value={filters.search} onChange={(event) => set({ search: event.target.value })} placeholder={t('staff.search')} aria-label={t('staff.search')} className={`${controlClass()} max-w-80`} />
      </div>

      <Card padded={false} className="overflow-hidden">
        {list.isPending ? (
          <Loading />
        ) : list.isError ? (
          <div className="p-4">
            <ErrorNotice error={list.error} />
          </div>
        ) : list.data.data.length === 0 ? (
          <EmptyState title={t('staff.empty')} />
        ) : (
          <DataTable label={t('staff.title')} testId="staff-table" columns={columns} rows={list.data.data} rowKey={(row) => row.id} rowLabel={(row) => row.name} actions={actionsFor} onRowClick={(row) => navigate(`/staff/${row.id}`)} />
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
      {adding ? <AddStaffDialog onClose={() => setAdding(false)} onCreated={(staff, invitation) => { setAdding(false); setInvited({ staff, invitation }) }} /> : null}
      {invited ? <InvitationDialog name={invited.staff.name} email={invited.staff.email} phone={invited.staff.phone} invitation={invited.invitation} onClose={() => { setInvited(null); navigate(`/staff/${invited.staff.id}`) }} /> : null}
    </>
  )
}

function AddStaffDialog({ onClose, onCreated }: { onClose: () => void; onCreated: (staff: StaffDetail, invitation: Invitation) => void }) {
  const { t } = useTranslation()
  const { locale } = useFormat()
  const options = useStaffOptions()
  const create = useCreateStaff()
  const [form, setForm] = useState<NewStaff>({ name: '', email: '', phone: '', role: '', designation: '', joined_on: todayInDhaka(), locale: 'bn' })
  const fieldError = (name: keyof NewStaff) => (create.error instanceof ApiError ? create.error.field(name) : undefined)
  const roles = options.data?.data.roles ?? []
  const ready = form.name.trim() !== '' && form.email.trim() !== '' && form.role !== ''

  return (
    <Dialog open onClose={onClose} title={t('staff.addTitle')}>
      <Pair>
        <TextInput label={t('staff.name')} value={form.name} onChange={(name) => setForm({ ...form, name })} error={fieldError('name')} maxLength={120} autoComplete="off" />
        <TextInput label={t('staff.email')} type="email" value={form.email} onChange={(email) => setForm({ ...form, email })} error={fieldError('email')} hint={t('staff.emailHint')} maxLength={190} autoComplete="off" />
      </Pair>
      <Pair>
        <TextInput label={t('staff.phone')} type="tel" value={form.phone} onChange={(phone) => setForm({ ...form, phone })} error={fieldError('phone')} hint={t('staff.phoneHint')} maxLength={20} />
        <SelectInput
          label={t('staff.role')}
          value={form.role}
          onChange={(role) => setForm({ ...form, role })}
          options={[{ value: '', label: t('staff.pickRole') }, ...roles.map((role) => ({ value: role.name, label: roleLabel(role, locale) }))]}
          error={fieldError('role')}
        />
      </Pair>
      <Pair>
        <TextInput label={t('staff.designation')} value={form.designation} onChange={(designation) => setForm({ ...form, designation })} error={fieldError('designation')} maxLength={80} />
        <TextInput label={t('staff.joinedOn')} type="date" value={form.joined_on} onChange={(joined_on) => setForm({ ...form, joined_on })} error={fieldError('joined_on')} />
      </Pair>
      <SelectInput label={t('staff.language')} value={form.locale} onChange={(value) => setForm({ ...form, locale: value === 'en' ? 'en' : 'bn' })} options={[{ value: 'bn', label: 'বাংলা' }, { value: 'en', label: 'English' }]} hint={t('staff.languageHint')} />
      <p className="m-0 text-12.5 leading-1.55 text-app-muted">{t('staff.inviteExplainer')}</p>
      {create.error && !(create.error instanceof ApiError && create.error.status === 422) ? <ErrorNotice error={create.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('primary')}
          disabled={!ready || create.isPending}
          onClick={() => create.mutate({ ...form, designation: form.designation.trim(), phone: form.phone.trim() }, { onSuccess: (response) => onCreated(response.data, response.invitation) })}
        >
          {create.isPending ? t('common.working') : t('staff.create')}
        </button>
      </div>
    </Dialog>
  )
}
