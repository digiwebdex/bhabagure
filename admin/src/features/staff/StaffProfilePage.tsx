import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'

import { useAuth, useStaff } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useConfirm, useToast } from '../../components/ui/feedback'
import { Pair, SelectInput, TextArea, TextInput } from '../../components/ui/fields'
import { Badge, Card, CardTitle, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { SalaryHistory } from '../payroll/PayrollDialogs'
import {
  documentName,
  roleLabel,
  staffActions,
  usePasswordReset,
  useStaffAction,
  useStaffMember,
  useStaffOptions,
  type Invitation,
  type PayoutMethod,
  type StaffDetail,
  type StaffDocument,
} from './api'
import { ArchiveDocumentDialog, UploadDocumentDialog } from './DocumentDialogs'
import { DocumentStatusBadge, ExpiryNote } from './documentBits'
import { InvitationDialog } from './InvitationDialog'
import { useOpenStaffDocument } from './useOpenStaffDocument'

/** One staff member's record (docs/phase-7-hr-attendance-bonus-wallet.md §4.1): account, HR record, role and access, base salary (§6), documents. */
export function StaffProfilePage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { locale } = useFormat()
  const id = Number(useParams().id)
  const member = useStaffMember(id)

  if (member.isPending) return <Loading />
  if (member.isError) return member.error instanceof ApiError && member.error.status === 404 ? <EmptyState title={t('errors.notFoundTitle')} /> : <ErrorNotice error={member.error} />
  const staff = member.data.data

  return (
    <>
      <Link to="/staff" className="self-start text-13 font-semibold text-blue">
        {t('staff.back')}
      </Link>
      <PageHeader title={staff.name} subtitle={`${roleLabel(staff.role, locale)} · ${staff.employee_code}`} actions={<Badge tone={staff.status === 'active' ? 'green' : staff.status === 'invited' ? 'blue' : 'slate'}>{t(`staff.status.${staff.status}`)}</Badge>} />
      <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
        {/* Remounted when the saved record changes, so the form always starts from what the API holds. */}
        <RecordForm key={JSON.stringify([staff.name, staff.email, staff.phone, staff.locale, staff.profile])} staff={staff} />
        <div className="flex flex-col gap-4.5">
          <AccessCard staff={staff} />
          {can('payroll.view') || can('payroll.manage') ? (
            <Card>
              <CardTitle bn="মূল বেতন" en="Base salary" />
              <SalaryHistory staffId={staff.id} />
            </Card>
          ) : null}
          {staff.documents !== null ? <DocumentsCard staff={staff} documents={staff.documents} /> : null}
        </div>
      </div>
    </>
  )
}

function RecordForm({ staff }: { staff: StaffDetail }) {
  const { t } = useTranslation()
  const toast = useToast()
  const options = useStaffOptions()
  const [form, setForm] = useState({
    name: staff.name,
    email: staff.email,
    phone: staff.phone ?? '',
    locale: staff.locale,
    designation: staff.profile.designation ?? '',
    joined_on: staff.profile.joined_on ?? '',
    left_on: staff.profile.left_on ?? '',
    date_of_birth: staff.profile.date_of_birth ?? '',
    nid_number: staff.profile.nid_number ?? '',
    address: staff.profile.address ?? '',
    emergency_contact_name: staff.profile.emergency_contact_name ?? '',
    emergency_contact_phone: staff.profile.emergency_contact_phone ?? '',
    payout_method: staff.profile.payout_method ?? '',
    payout_account: staff.profile.payout_account ?? '',
  })
  const save = useStaffAction(staff.id, staffActions.update(staff.id))
  const fieldError = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)
  const editable = staff.actions.edit
  const set = <K extends keyof typeof form>(key: K) => (value: (typeof form)[K]) => setForm({ ...form, [key]: value })

  const submit = () => {
    const blankToNull = (value: string) => (value.trim() === '' ? null : value.trim())
    save.mutate(
      {
        name: form.name.trim(),
        ...(staff.actions.change_email ? { email: form.email.trim() } : {}),
        phone: blankToNull(form.phone),
        locale: form.locale,
        designation: blankToNull(form.designation),
        joined_on: blankToNull(form.joined_on),
        left_on: blankToNull(form.left_on),
        date_of_birth: blankToNull(form.date_of_birth),
        nid_number: blankToNull(form.nid_number),
        address: blankToNull(form.address),
        emergency_contact_name: blankToNull(form.emergency_contact_name),
        emergency_contact_phone: blankToNull(form.emergency_contact_phone),
        payout_method: (blankToNull(form.payout_method) as PayoutMethod | null),
        payout_account: blankToNull(form.payout_account),
      },
      { onSuccess: () => toast(t('staff.saved')) },
    )
  }

  return (
    <Card>
      <CardTitle bn="অ্যাকাউন্ট ও এইচআর রেকর্ড" en="Account & HR record" />
      <fieldset disabled={!editable} className="m-0 flex flex-col gap-3.5 border-0 p-0">
        <Pair>
          <TextInput label={t('staff.name')} value={form.name} onChange={set('name')} error={fieldError('name')} maxLength={120} />
          <TextInput label={t('staff.email')} type="email" value={form.email} onChange={set('email')} error={fieldError('email')} disabled={!staff.actions.change_email} hint={staff.actions.change_email ? undefined : t('staff.emailLocked')} maxLength={190} />
        </Pair>
        <Pair>
          <TextInput label={t('staff.phone')} type="tel" value={form.phone} onChange={set('phone')} error={fieldError('phone')} maxLength={20} />
          <SelectInput label={t('staff.language')} value={form.locale} onChange={(value) => setForm({ ...form, locale: value === 'en' ? 'en' : 'bn' })} options={[{ value: 'bn', label: 'বাংলা' }, { value: 'en', label: 'English' }]} />
        </Pair>
        <Pair>
          <TextInput label={t('staff.designation')} value={form.designation} onChange={set('designation')} error={fieldError('designation')} maxLength={80} />
          <TextInput label={t('staff.dateOfBirth')} type="date" value={form.date_of_birth} onChange={set('date_of_birth')} error={fieldError('date_of_birth')} />
        </Pair>
        <Pair>
          <TextInput label={t('staff.joinedOn')} type="date" value={form.joined_on} onChange={set('joined_on')} error={fieldError('joined_on')} />
          <TextInput label={t('staff.leftOn')} type="date" value={form.left_on} onChange={set('left_on')} error={fieldError('left_on')} hint={t('staff.leftOnHint')} />
        </Pair>
        <TextInput label={t('staff.nid')} value={form.nid_number} onChange={set('nid_number')} error={fieldError('nid_number')} hint={t('staff.nidHint')} inputMode="numeric" maxLength={17} autoComplete="off" />
        <TextArea label={t('staff.address')} value={form.address} onChange={set('address')} error={fieldError('address')} rows={2} maxLength={300} />
        <Pair>
          <TextInput label={t('staff.emergencyName')} value={form.emergency_contact_name} onChange={set('emergency_contact_name')} error={fieldError('emergency_contact_name')} maxLength={120} />
          <TextInput label={t('staff.emergencyPhone')} type="tel" value={form.emergency_contact_phone} onChange={set('emergency_contact_phone')} error={fieldError('emergency_contact_phone')} maxLength={20} />
        </Pair>
        <Pair>
          <SelectInput
            label={t('staff.payoutMethod')}
            value={form.payout_method}
            onChange={set('payout_method')}
            options={[{ value: '', label: '—' }, ...(options.data?.data.payout_methods ?? []).map((value) => ({ value, label: t(`staff.payoutMethods.${value}`) }))]}
            error={fieldError('payout_method')}
          />
          <TextInput label={t('staff.payoutAccount')} value={form.payout_account} onChange={set('payout_account')} error={fieldError('payout_account')} hint={t('staff.privateHint')} maxLength={60} autoComplete="off" />
        </Pair>
      </fieldset>
      {save.error && !(save.error instanceof ApiError && save.error.status === 422) ? <ErrorNotice error={save.error} /> : null}
      {editable ? (
        <button type="button" className={buttonClass('primary', 'md', 'self-start')} disabled={save.isPending || form.name.trim() === ''} onClick={submit}>
          {save.isPending ? t('common.working') : t('staff.save')}
        </button>
      ) : (
        <p className="m-0 text-12.5 text-app-muted">{t('staff.superAdminOnly')}</p>
      )}
    </Card>
  )
}

function AccessCard({ staff }: { staff: StaffDetail }) {
  const { t } = useTranslation()
  const { locale } = useFormat()
  const me = useStaff()
  const toast = useToast()
  const { confirm, element } = useConfirm()
  const options = useStaffOptions()
  const [role, setRole] = useState(staff.role?.name ?? '')
  const [suspending, setSuspending] = useState(false)
  const [invitation, setInvitation] = useState<Invitation | null>(null)
  const changeRole = useStaffAction(staff.id, staffActions.role(staff.id))
  const reactivate = useStaffAction(staff.id, staffActions.reactivate(staff.id))
  const reinvite = useStaffAction(staff.id, staffActions.reinvite(staff.id))
  const reset = usePasswordReset(staff.id)
  const roles = options.data?.data.roles ?? []
  const actionError = changeRole.error ?? reactivate.error ?? reinvite.error ?? reset.error

  return (
    <Card>
      <CardTitle bn="রোল ও অ্যাকসেস" en="Role & access" />
      {staff.actions.change_role ? (
        <div className="flex flex-wrap items-end gap-2">
          <SelectInput label={t('staff.role')} value={role} onChange={setRole} options={roles.map((option) => ({ value: option.name, label: roleLabel(option, locale) }))} className="min-w-56 flex-1" />
          <button
            type="button"
            className={buttonClass('outline')}
            disabled={role === (staff.role?.name ?? '') || changeRole.isPending}
            onClick={() => changeRole.mutate(role, { onSuccess: (response) => toast(t('staff.roleChanged', { role: roleLabel(response.data.role, locale) })) })}
          >
            {t('staff.changeRole')}
          </button>
        </div>
      ) : (
        <p className="m-0 text-13.5">
          {roleLabel(staff.role, locale)} <span className="text-12 text-app-muted">· {staff.id === me.id ? t('staff.ownRole') : t('staff.superAdminOnly')}</span>
        </p>
      )}

      <div className="flex flex-wrap gap-2">
        {staff.actions.reinvite ? (
          <button type="button" className={buttonClass('outline', 'sm')} disabled={reinvite.isPending} onClick={() => reinvite.mutate(undefined, { onSuccess: (response) => setInvitation(response.invitation) })}>
            {t('staff.resendInvite')}
          </button>
        ) : null}
        {staff.actions.password_reset ? (
          <button
            type="button"
            className={buttonClass('outline', 'sm')}
            disabled={reset.isPending}
            onClick={async () => {
              if (!(await confirm(t('staff.resetConfirm', { name: staff.name, email: staff.email })))) return
              reset.mutate(undefined, { onSuccess: (response) => toast(t(`staff.resetSent.${response.data.email}`, { email: staff.email }), response.data.email === 'sent' ? 'success' : 'error') })
            }}
          >
            {t('staff.sendReset')}
          </button>
        ) : null}
        {staff.actions.suspend ? (
          <button type="button" className={buttonClass('danger', 'sm')} onClick={() => setSuspending(true)}>
            {t('staff.suspend')}
          </button>
        ) : null}
        {staff.actions.reactivate ? (
          <button type="button" className={buttonClass('success', 'sm')} disabled={reactivate.isPending} onClick={() => reactivate.mutate(undefined, { onSuccess: () => toast(t('staff.reactivated', { name: staff.name })) })}>
            {t('staff.reactivate')}
          </button>
        ) : null}
      </div>
      {actionError ? <ErrorNotice error={actionError} /> : null}
      {suspending ? <SuspendDialog staff={staff} onClose={() => setSuspending(false)} /> : null}
      {invitation ? <InvitationDialog name={staff.name} email={staff.email} phone={staff.phone} invitation={invitation} onClose={() => setInvitation(null)} /> : null}
      {element}
    </Card>
  )
}

function SuspendDialog({ staff, onClose }: { staff: StaffDetail; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [reason, setReason] = useState('')
  const [leftOn, setLeftOn] = useState('')
  const suspend = useStaffAction(staff.id, staffActions.suspend(staff.id))

  return (
    <Dialog open onClose={onClose} title={t('staff.suspendTitle', { name: staff.name })}>
      <p className="m-0 text-13 leading-1.6 text-app-muted">{t('staff.suspendNote')}</p>
      <TextArea label={t('staff.suspendReason')} value={reason} onChange={setReason} rows={2} maxLength={300} />
      <TextInput label={t('staff.leftOn')} type="date" value={leftOn} onChange={setLeftOn} hint={t('staff.leftOnHint')} />
      {suspend.error ? <ErrorNotice error={suspend.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('danger')}
          disabled={reason.trim().length < 3 || suspend.isPending}
          onClick={() => suspend.mutate({ reason: reason.trim(), left_on: leftOn || null }, { onSuccess: () => { toast(t('staff.suspended', { name: staff.name })); onClose() } })}
        >
          {t('staff.suspend')}
        </button>
      </div>
    </Dialog>
  )
}

function DocumentsCard({ staff, documents }: { staff: StaffDetail; documents: StaffDocument[] }) {
  const { t } = useTranslation()
  const open = useOpenStaffDocument()
  const [uploading, setUploading] = useState(false)
  const [replacing, setReplacing] = useState<StaffDocument | null>(null)
  const [archiving, setArchiving] = useState<StaffDocument | null>(null)
  const manage = staff.actions.upload_documents

  return (
    <Card>
      <CardTitle
        bn="ডকুমেন্ট"
        en="Documents"
        aside={
          manage ? (
            <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setUploading(true)}>
              {t('vault.add')}
            </button>
          ) : null
        }
      />
      {documents.length === 0 ? (
        <p className="m-0 text-13 text-app-muted">{t('vault.noneForPerson')}</p>
      ) : (
        <ul className="m-0 flex list-none flex-col gap-2 p-0" data-testid="staff-documents">
          {documents.map((document) => (
            <li key={document.id} className={`flex flex-wrap items-start justify-between gap-2 rounded-10 bg-app-surface-2 px-3 py-2.5 text-13 ${document.archived_at ? 'opacity-60' : ''}`}>
              <span className="flex min-w-0 flex-col gap-0.5">
                <span className="font-medium">{documentName(t, document)}</span>
                <span className="text-12 text-app-muted">{document.number ? `№ ${document.number}` : t('vault.noNumber')}</span>
                {document.archived_at ? (
                  <span className="text-12 text-app-muted">{t('vault.archivedBecause', { name: document.archived_by ?? '—', reason: document.archive_reason === 'replaced' ? t('vault.replacedReason') : (document.archive_reason ?? '') })}</span>
                ) : null}
              </span>
              <span className="flex flex-col items-end gap-1">
                {document.archived_at ? <Badge tone="slate">{t('vault.archived')}</Badge> : <DocumentStatusBadge status={document.status} />}
                {document.archived_at ? null : <ExpiryNote document={document} />}
                <span className="flex gap-3 text-12">
                  <button type="button" className="cursor-pointer font-semibold text-blue" onClick={() => void open(document.id)} aria-label={t('vault.openNamed', { document: documentName(t, document) })}>
                    {t('vault.open')}
                  </button>
                  {manage && !document.archived_at ? (
                    <>
                      <button type="button" className="cursor-pointer font-semibold text-blue" onClick={() => setReplacing(document)} aria-label={t('vault.replaceNamed', { document: documentName(t, document) })}>
                        {t('vault.replace')}
                      </button>
                      <button type="button" className="cursor-pointer font-semibold text-red" onClick={() => setArchiving(document)} aria-label={t('vault.archiveNamed', { document: documentName(t, document) })}>
                        {t('vault.archive')}
                      </button>
                    </>
                  ) : null}
                </span>
              </span>
            </li>
          ))}
        </ul>
      )}
      {uploading ? <UploadDocumentDialog staffId={staff.id} onClose={() => setUploading(false)} /> : null}
      {replacing ? <UploadDocumentDialog replacing={replacing} onClose={() => setReplacing(null)} /> : null}
      {archiving ? <ArchiveDocumentDialog document={archiving} onClose={() => setArchiving(null)} /> : null}
    </Card>
  )
}
