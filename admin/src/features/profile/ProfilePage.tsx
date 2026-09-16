import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { useStaff } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { ErrorNotice, useConfirm, useToast } from '../../components/ui/feedback'
import { TextInput } from '../../components/ui/fields'
import { Badge, Card, CardTitle, Loading, PageHeader } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { myWhatsAppActions, useMyWhatsApp, useMyWhatsAppAction, type MyWhatsApp } from '../notifications/api'
import { useMyRecord } from '../staff/api'

export function ProfilePage() {
  const { t } = useTranslation()
  const staff = useStaff()
  const whatsapp = useMyWhatsApp()

  return (
    <>
      <PageHeader title={t('profile.title')} subtitle={`${staff.name} · ${staff.email}`} />
      <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
        {whatsapp.isPending ? <Loading /> : whatsapp.isError ? <ErrorNotice error={whatsapp.error} /> : <WhatsAppCard state={whatsapp.data.data} />}
        <Card>
          <CardTitle title="Password" />
          <Link to="/change-password" className={buttonClass('outline', 'md', 'self-start')}>
            {t('profile.changePassword')}
          </Link>
        </Card>
        <MyRecordCard />
      </div>
    </>
  )
}

/** The staff member's own HR record, read-only: HR keeps it (docs/phase-7-hr-attendance-bonus-wallet.md §4.1). */
function MyRecordCard() {
  const { t } = useTranslation()
  const { date, digits } = useFormat()
  const record = useMyRecord()

  if (record.isPending) return <Loading />
  if (record.isError) return <ErrorNotice error={record.error} />
  const data = record.data.data
  const rows: [string, string | null][] = [
    [t('staff.code'), data.employee_code],
    [t('staff.designation'), data.designation],
    [t('staff.joinedOn'), data.joined_on ? date(data.joined_on) : null],
    [t('staff.dateOfBirth'), data.date_of_birth ? date(data.date_of_birth) : null],
    [t('staff.nid'), data.nid_number ? digits(data.nid_number) : null],
    [t('staff.address'), data.address],
    [t('staff.emergencyContact'), data.emergency_contact_name ? `${data.emergency_contact_name}${data.emergency_contact_phone ? ` · ${digits(data.emergency_contact_phone)}` : ''}` : null],
    [t('staff.payoutMethod'), data.payout_method ? `${t(`staff.payoutMethods.${data.payout_method}`)}${data.payout_account ? ` · ${digits(data.payout_account)}` : ''}` : null],
  ]

  return (
    <Card>
      <CardTitle title="My HR record" />
      <dl className="m-0 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-13.5" data-testid="my-record">
        {rows.map(([label, value]) => (
          <div key={label} className="contents">
            <dt className="text-app-muted">{label}</dt>
            <dd className="m-0 min-w-0 break-words">{value ?? '—'}</dd>
          </div>
        ))}
      </dl>
      <p className="m-0 text-12.5 text-app-muted">{t('profile.recordNote')}</p>
    </Card>
  )
}

/**
 * A staff member's WhatsApp number, for sales alerts and "send test to me". It counts only once the six-digit code
 * sent to it is entered, so alerts never go to a number nobody checked.
 */
function WhatsAppCard({ state }: { state: MyWhatsApp }) {
  const { t } = useTranslation()
  const { digits } = useFormat()
  const toast = useToast()
  const { confirm, element } = useConfirm()
  const [editing, setEditing] = useState(!state.number)
  const [number, setNumber] = useState('')
  const [code, setCode] = useState('')
  const start = useMyWhatsAppAction(myWhatsAppActions.start)
  const verify = useMyWhatsAppAction(myWhatsAppActions.verify)
  const remove = useMyWhatsAppAction(myWhatsAppActions.remove)
  const shown = state.number ? digits(state.number.replace(/^88/, '')) : null
  const startError = start.error instanceof ApiError ? start.error : null
  const verifyError = verify.error instanceof ApiError ? verify.error : null

  const send = (value: string) =>
    start.mutate(value, {
      onSuccess: () => {
        setEditing(false)
        setCode('')
        toast(t('profile.codeSent'))
      },
    })

  return (
    <Card>
      <CardTitle title="My WhatsApp number" aside={state.verified ? <Badge tone="green">{t('profile.verified')}</Badge> : state.number ? <Badge tone="orange">{t('profile.notVerified')}</Badge> : null} />
      <p className="m-0 text-13 leading-1.6 text-app-muted">{t('profile.whatsAppNote')}</p>

      {state.number && !editing ? (
        <div className="flex flex-wrap items-center justify-between gap-2.5 rounded-12 bg-app-surface-2 px-3.5 py-3">
          <span className="font-display text-16 font-semibold" data-testid="my-whatsapp-number">
            {shown}
          </span>
          <span className="flex flex-wrap gap-2">
            <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setEditing(true)}>
              {t('profile.changeNumber')}
            </button>
            <button
              type="button"
              className={buttonClass('danger', 'sm')}
              onClick={async () => (await confirm(t('profile.removeConfirm'))) && remove.mutate(undefined, { onSuccess: () => { setEditing(true); toast(t('profile.removed')) } })}
            >
              {t('common.remove')}
            </button>
          </span>
        </div>
      ) : (
        <form
          className="flex flex-col gap-3"
          onSubmit={(event) => {
            event.preventDefault()
            if (number.trim()) send(number.trim())
          }}
        >
          <TextInput label={t('profile.number')} type="tel" inputMode="tel" autoComplete="tel" value={number} onChange={setNumber} placeholder="01711-223344" error={startError?.field('number')} hint={t('profile.numberHint')} />
          {start.error && !(startError?.status === 422) ? <ErrorNotice error={start.error} /> : null}
          <span className="flex flex-wrap gap-2">
            <button type="submit" className={buttonClass('primary')} disabled={!number.trim() || start.isPending}>
              {start.isPending ? t('common.working') : t('profile.sendCode')}
            </button>
            {state.number ? (
              <button type="button" className={buttonClass('outline')} onClick={() => setEditing(false)}>
                {t('common.cancel')}
              </button>
            ) : null}
          </span>
        </form>
      )}

      {state.code_pending && !editing ? (
        <form
          className="flex flex-col gap-3 rounded-12 border border-app-line px-3.5 py-3"
          onSubmit={(event) => {
            event.preventDefault()
            verify.mutate(code, { onSuccess: () => toast(t('profile.verifiedToast')) })
          }}
        >
          <TextInput
            label={t('profile.code', { number: shown })}
            value={code}
            onChange={(value) => setCode(value.replace(/[^\d০-৯]/g, '').replace(/[০-৯]/g, (d) => String('০১২৩৪৫৬৭৮৯'.indexOf(d))).slice(0, 6))}
            inputMode="numeric"
            autoComplete="one-time-code"
            error={verifyError?.field('code')}
            hint={t('profile.codeHint')}
          />
          {verify.error && !(verifyError?.status === 422) ? <ErrorNotice error={verify.error} /> : null}
          <span className="flex flex-wrap gap-2">
            <button type="submit" className={buttonClass('success')} disabled={code.length !== 6 || verify.isPending}>
              {t('profile.verify')}
            </button>
            <button type="button" className={buttonClass('outline')} disabled={start.isPending} onClick={() => state.number && send(state.number)}>
              {t('profile.resend')}
            </button>
          </span>
        </form>
      ) : null}
      {remove.error ? <ErrorNotice error={remove.error} /> : null}
      {element}
    </Card>
  )
}
