import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'

import { contactActions } from '../../components/table/contactActions'
import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useConfirm, useToast } from '../../components/ui/feedback'
import { SelectInput, TextArea, TextInput } from '../../components/ui/fields'
import { Card, CardTitle, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { api, ApiError } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import { quotationActions, useOpenQuotationPdf, useQuotation, useQuotationMutation, useQuotationOptions, type QuotationDetail } from './api'
import { QuotationFields, QuotationTotals, useQuotationForm } from './QuotationEditor'
import { ValidUntil } from './QuotationsPage'
import { QuotationStatusBadge } from './QuotationStatusBadge'

export function QuotationDetailPage() {
  const { id } = useParams()
  const { t } = useTranslation()
  const quotation = useQuotation(Number(id))

  if (quotation.isPending) return <Loading />
  if (quotation.isError) return quotation.error instanceof ApiError && quotation.error.status === 404 ? <EmptyState title={t('errors.notFoundTitle')} /> : <ErrorNotice error={quotation.error} />

  // Keyed by id: opening a revision starts its editor afresh.
  return <Quotation key={quotation.data.data.id} q={quotation.data.data} />
}

type Transition = 'send' | 'accept' | 'decline' | 'withdraw' | 'revise'

function Quotation({ q }: { q: QuotationDetail }) {
  const { t } = useTranslation()
  const { digits } = useFormat()
  const toast = useToast()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const { confirm, element: confirmDialog } = useConfirm()
  const openPdf = useOpenQuotationPdf()
  const [reasonFor, setReasonFor] = useState<'decline' | 'withdraw' | null>(null)
  const [assignOpen, setAssignOpen] = useState(false)
  const convertOpen = params.get('convert') === '1' && q.actions.convert
  const transition = useQuotationMutation(({ action, reason }: { action: Transition; reason?: string | null }) => quotationActions.transition(q.id, action, reason === undefined ? undefined : { reason }), (r) => r.data)
  const remove = useQuotationMutation(() => quotationActions.remove(q.id), () => null)
  const title = q.package_title_en || q.package_title_bn

  const run = (action: Transition, done: string, reason?: string | null) =>
    transition.mutate(
      { action, reason },
      {
        onSuccess: (response) => {
          toast(t(done, { number: response.data.number }))
          setReasonFor(null)
          if (response.data.id !== q.id) navigate(`/quotations/${response.data.id}`)
        },
      },
    )

  return (
    <>
      <PageHeader
        title={q.number}
        subtitle={`${q.customer.name} · ${title}`}
        actions={
          <>
            <Link to="/quotations" className={buttonClass('outline', 'sm')}>
              {t('quotations.back')}
            </Link>
            <button type="button" className={buttonClass('outline', 'sm')} onClick={() => void openPdf(q.id)}>
              {t('table.pdf')}
            </button>
            <button type="button" className={buttonClass('outline', 'sm')} onClick={() => void openPdf(q.id, false)}>
              {t('quotations.pdfForPad')}
            </button>
          </>
        }
      />

      <div className="flex flex-wrap items-center gap-2.5">
        <QuotationStatusBadge status={q.display_status} />
        <span className="text-13 text-app-muted">{t('quotations.validUntil')}:</span>
        <ValidUntil q={q} />
        {q.assigned_staff ? <span className="text-13 text-app-muted">· {t('customers.ownedBy', { name: q.assigned_staff.name })}</span> : null}
        {q.revision_of ? (
          <span className="text-13 text-app-muted">
            · {t('quotations.revisesLink')} <Link to={`/quotations/${q.revision_of.id}`}>{q.revision_of.number}</Link>
          </span>
        ) : null}
        {q.converted_booking ? (
          <span className="text-13 text-app-muted">
            · {t('quotations.bookedLink')} <Link to={`/bookings/${q.converted_booking.id}`}>{q.converted_booking.reference}</Link>
          </span>
        ) : null}
      </div>

      <div className="flex flex-wrap gap-2" data-testid="quotation-actions">
        {q.actions.send ? (
          <button type="button" className={buttonClass('cta', 'sm')} disabled={transition.isPending} onClick={async () => (await confirm(t('quotations.sendConfirm', { name: q.customer.name }))) && run('send', 'quotations.sent')}>
            {t('quotations.send')}
          </button>
        ) : null}
        {q.actions.convert ? (
          <button type="button" className={buttonClass('success', 'sm')} onClick={() => setParams({ convert: '1' }, { replace: true })}>
            {t('quotations.convert')}
          </button>
        ) : null}
        {q.actions.accept ? (
          <button type="button" className={buttonClass('outline', 'sm')} disabled={transition.isPending} onClick={() => run('accept', 'quotations.accepted')}>
            {t('quotations.markAccepted')}
          </button>
        ) : null}
        {q.actions.revise ? (
          <button type="button" className={buttonClass('outline', 'sm')} disabled={transition.isPending} onClick={() => run('revise', 'quotations.revising')}>
            {t('quotations.revise')}
          </button>
        ) : null}
        {q.actions.decline ? (
          <button type="button" className={buttonClass('danger', 'sm')} onClick={() => setReasonFor('decline')}>
            {t('quotations.markDeclined')}
          </button>
        ) : null}
        {q.actions.withdraw ? (
          <button type="button" className={buttonClass('danger', 'sm')} onClick={() => setReasonFor('withdraw')}>
            {t('quotations.withdraw')}
          </button>
        ) : null}
        {q.actions.delete ? (
          <button
            type="button"
            className={buttonClass('danger', 'sm')}
            disabled={remove.isPending}
            onClick={async () => {
              if (!(await confirm(t('quotations.deleteConfirm', { number: q.number })))) return
              remove.mutate(undefined, {
                onSuccess: () => {
                  toast(t('quotations.deleted', { number: q.number }))
                  navigate('/quotations')
                },
              })
            }}
          >
            {t('table.delete')}
          </button>
        ) : null}
        {q.actions.assign && q.status !== 'converted' ? (
          <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setAssignOpen(true)}>
            {t('customers.assign')}
          </button>
        ) : null}
      </div>
      {transition.error ? <ErrorNotice error={transition.error} /> : null}
      {remove.error ? <ErrorNotice error={remove.error} /> : null}

      <div className="grid-auto-fit-360 grid items-start gap-admin-gap">
        {q.actions.edit ? <EditorCard q={q} /> : <PriceCard q={q} />}
        <div className="flex flex-col gap-admin-gap">
          <Card>
            <CardTitle title="Customer" />
            <div className="flex flex-col gap-0.5">
              <Link to={`/customers/${q.customer.id}`} className="font-medium">
                {q.customer.name}
              </Link>
              <span className="font-display text-13 text-app-muted">{digits(q.customer.phone.replace(/^88/, ''))}</span>
              {q.customer.email ? <span className="text-13 text-app-muted">{q.customer.email}</span> : null}
            </div>
            <div className="flex flex-wrap gap-2">
              {contactActions(t, {
                phone: q.customer.phone,
                email: q.customer.email,
                subject: t('quotations.contactSubject', { number: q.number }),
                message: t('quotations.contactMessage', { name: q.customer.name, package: title, number: q.number }) + (q.public_url ? `\n${q.public_url}` : ''),
                channels: ['whatsapp', 'email'],
              }).map((action) =>
                action.disabledReason ? (
                  <button key={action.key} type="button" className={buttonClass('outline', 'sm')} disabled title={action.disabledReason}>
                    {action.icon} {action.label}
                  </button>
                ) : (
                  <a key={action.key} href={action.href} target={action.newTab ? '_blank' : undefined} rel="noreferrer" className={buttonClass('outline', 'sm')}>
                    {action.icon} {action.label}
                  </a>
                ),
              )}
            </div>
            {q.public_url ? (
              <button
                type="button"
                className={buttonClass('ghost', 'sm', 'self-start')}
                onClick={() => void navigator.clipboard.writeText(q.public_url!).then(() => toast(t('bookings.linkCopied')))}
              >
                {t('bookings.copyLink')}
              </button>
            ) : null}
          </Card>
          <HistoryCard q={q} />
        </div>
      </div>

      {reasonFor ? (
        <ReasonDialog
          title={t(reasonFor === 'decline' ? 'quotations.declineTitle' : 'quotations.withdrawTitle', { number: q.number })}
          action={t(reasonFor === 'decline' ? 'quotations.markDeclined' : 'quotations.withdraw')}
          pending={transition.isPending}
          error={transition.error}
          onClose={() => setReasonFor(null)}
          onSubmit={(reason) => run(reasonFor, reasonFor === 'decline' ? 'quotations.declined' : 'quotations.withdrawn', reason || null)}
        />
      ) : null}
      {convertOpen ? <ConvertDialog q={q} onClose={() => setParams({}, { replace: true })} /> : null}
      {assignOpen ? <AssignDialog q={q} onClose={() => setAssignOpen(false)} /> : null}
      {confirmDialog}
    </>
  )
}

/** A draft: the editor, re-priced live and checked by the API on save. */
function EditorCard({ q }: { q: QuotationDetail }) {
  const { t } = useTranslation()
  const { locale } = useFormat()
  const toast = useToast()
  const options = useQuotationOptions()
  const draft = useQuotationForm(options.data, q.inputs, locale)
  const save = useQuotationMutation(() => {
    const body = draft.body()
    if (!body) throw new Error('incomplete')
    return quotationActions.update(q.id, body)
  }, (r) => r.data)

  if (options.isPending) return <Card><Loading /></Card>
  if (options.isError) return <Card><ErrorNotice error={options.error} /></Card>
  const fieldError = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)

  return (
    <Card>
      <CardTitle title="Draft quotation" aside={<span className="text-12 text-app-muted">{t('quotations.draftNote')}</span>} />
      <form
        className="flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate(undefined, {
            onSuccess: () => {
              draft.reset()
              toast(t('common.saved'))
            },
            onError: (error) => {
              if (error instanceof ApiError && error.code === 'price_changed') void options.refetch()
            },
          })
        }}
      >
        {!q.inputs.package_slug ? <p className="m-0 text-13 text-amber">{t('quotations.packageGone')}</p> : null}
        <QuotationFields draft={draft} options={options.data} fieldError={fieldError} />
        {draft.quote && draft.form ? <QuotationTotals quote={draft.quote} pax={draft.form.pax} /> : null}
        {save.error && !(save.error instanceof ApiError && save.error.status === 422) ? <ErrorNotice error={save.error} /> : null}
        <button type="submit" className={buttonClass('primary', 'md', 'self-start')} disabled={!draft.quote || save.isPending}>
          {save.isPending ? t('common.saving') : t('quotations.saveDraft')}
        </button>
      </form>
    </Card>
  )
}

/** Sent and after: the frozen lines and amounts, exactly as the customer received them. */
function PriceCard({ q }: { q: QuotationDetail }) {
  const { t } = useTranslation()
  const { bdt, number, date } = useFormat()
  const rows: [string, string][] = [
    [t('bookings.line.package'), bdt(q.amounts.subtotal)],
    ...(q.amounts.single_supplement > 0 ? [[t('bookings.line.single'), bdt(q.amounts.single_supplement)] as [string, string]] : []),
    ...(q.amounts.addons > 0 ? [[t('newBooking.addons'), bdt(q.amounts.addons)] as [string, string]] : []),
    ...(q.amounts.discount > 0 ? [[t('quotations.discount'), `− ${bdt(q.amounts.discount)}`] as [string, string]] : []),
    [t('bookings.vatLine', { rate: `${number(q.amounts.vat_rate)}%` }), bdt(q.amounts.vat)],
  ]

  return (
    <Card>
      <CardTitle title="Price" aside={<span className="text-12 text-app-muted">{t('quotations.frozenNote')}</span>} />
      <dl className="m-0 grid-auto-fit-half-160 grid gap-x-4 gap-y-2 text-13">
        <Fact label={t('bookings.package')} value={q.package_title_en || q.package_title_bn || '—'} />
        <Fact label={t('newBooking.travelDate')} value={q.travel_date ? date(q.travel_date) : t('quotations.dateLater')} />
        <Fact label={t('quotations.travellers')} value={number(q.pax_count)} />
        <Fact label={t('bookings.room')} value={t(`bookings.rooms.${q.room_type}`)} />
      </dl>
      <table className="w-full border-collapse text-13">
        <tbody>
          {q.lines.map((line, index) => (
            <tr key={index} className="border-b border-app-line">
              <td className="py-1.5 pr-2">{line.title_en || line.title_bn}</td>
              <td className="py-1.5 pr-2 text-right font-display whitespace-nowrap text-app-muted">
                {number(line.quantity)} × {bdt(line.unit_price)}
              </td>
              <td className="py-1.5 text-right font-display font-semibold whitespace-nowrap">{bdt(line.amount)}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <div className="flex flex-col gap-1.5 rounded-12 bg-app-surface-2 p-3.5">
        {rows.map(([label, amount]) => (
          <div key={label} className="flex justify-between gap-2.5 text-13">
            <span className="text-app-muted">{label}</span>
            <span className="font-display font-semibold">{amount}</span>
          </div>
        ))}
        <div className="my-0.75 h-px bg-app-line" />
        <div className="flex items-baseline justify-between gap-2.5">
          <span className="text-14 font-semibold">{t('bookings.total')}</span>
          <span className="font-display text-22 font-extrabold text-orange-deep" data-testid="quotation-total">
            {bdt(q.total_amount)}
          </span>
        </div>
      </div>
      {q.notes ? <p className="m-0 text-13 whitespace-pre-line text-app-muted">{q.notes}</p> : null}
    </Card>
  )
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col">
      <dt className="text-12 text-app-muted">{label}</dt>
      <dd className="m-0 font-medium">{value}</dd>
    </div>
  )
}

function HistoryCard({ q }: { q: QuotationDetail }) {
  const { t } = useTranslation()
  const { dateTime } = useFormat()
  const steps = (['created_at', 'sent_at', 'accepted_at', 'declined_at', 'withdrawn_at', 'converted_at'] as const).filter((key) => q.history[key])

  return (
    <Card>
      <CardTitle title="History" />
      <ol className="m-0 flex list-none flex-col gap-2 p-0">
        {steps.map((key) => (
          <li key={key} className="flex flex-wrap justify-between gap-2 text-13">
            <span className="font-medium">{t(`quotations.history.${key}`)}</span>
            <span className="text-app-muted">{dateTime(q.history[key]!)}</span>
          </li>
        ))}
      </ol>
      {q.created_by ? <span className="text-12 text-app-muted">{t('quotations.createdBy', { name: q.created_by.name })}</span> : null}
      {q.revisions.length > 0 ? (
        <div className="flex flex-col gap-1.5 border-t border-app-line pt-2.5">
          <span className="text-12 text-app-muted">{t('quotations.revisions')}</span>
          {q.revisions.map((revision) => (
            <Link key={revision.id} to={`/quotations/${revision.id}`} className="flex items-center justify-between gap-2 text-13">
              <span className="font-display font-semibold">{revision.number}</span>
              <QuotationStatusBadge status={revision.status} />
            </Link>
          ))}
        </div>
      ) : null}
    </Card>
  )
}

function ReasonDialog({ title, action, pending, error, onClose, onSubmit }: { title: string; action: string; pending: boolean; error: unknown; onClose: () => void; onSubmit: (reason: string) => void }) {
  const { t } = useTranslation()
  const [reason, setReason] = useState('')

  return (
    <Dialog open onClose={onClose} title={title}>
      <TextArea label={t('quotations.reason')} value={reason} onChange={setReason} rows={2} hint={t('quotations.reasonHint')} />
      {error ? <ErrorNotice error={error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button type="button" className={buttonClass('danger')} disabled={pending} onClick={() => onSubmit(reason.trim())}>
          {action}
        </button>
      </div>
    </Dialog>
  )
}

/** Into a booking at the frozen price: the travellers' names (passports can follow) and the travel date. */
function ConvertDialog({ q, onClose }: { q: QuotationDetail; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt, number, date } = useFormat()
  const toast = useToast()
  const navigate = useNavigate()
  const options = useQuotationOptions()
  // A package with scheduled departures is booked onto one of them, as on the staff booking form.
  const departures = options.data?.packages.find((p) => p.slug === q.inputs.package_slug)?.departures ?? []
  const [travelDate, setTravelDate] = useState(q.travel_date ?? '')
  const [names, setNames] = useState<string[]>(Array.from({ length: q.pax_count }, (_, i) => (i === 0 ? q.customer.name : '')))
  const convert = useQuotationMutation(
    () => quotationActions.convert(q.id, { travel_date: travelDate || null, travellers: names.map((name) => ({ name: name.trim(), passport_number: null, phone: null })) }),
    (r) => r.data.quotation,
  )
  const fieldError = (name: string) => (convert.error instanceof ApiError ? convert.error.field(name) : undefined)

  return (
    <Dialog open onClose={onClose} title={t('quotations.convertTitle', { number: q.number })}>
      <p className="m-0 text-13 text-app-muted">{t('quotations.convertNote', { total: bdt(q.total_amount) })}</p>
      {departures.length > 0 ? (
        <SelectInput
          label={t('newBooking.departure')}
          value={travelDate}
          onChange={setTravelDate}
          options={[
            { value: '', label: t('common.choose') },
            ...(q.travel_date && !departures.some((d) => d.date === q.travel_date) ? [{ value: q.travel_date, label: date(q.travel_date) }] : []),
            ...departures.map((d) => ({ value: d.date, label: d.seats_left === null ? date(d.date) : t('newBooking.departureSeats', { date: date(d.date), seats: number(d.seats_left) }) })),
          ]}
          error={fieldError('travel_date')}
        />
      ) : (
        <TextInput label={t('newBooking.travelDate')} type="date" min={todayInDhaka()} value={travelDate} onChange={setTravelDate} error={fieldError('travel_date')} required />
      )}
      {names.map((name, index) => (
        <TextInput
          key={index}
          label={index === 0 ? t('newBooking.leadTraveller') : t('newBooking.traveller', { n: number(index + 1) })}
          value={name}
          onChange={(value) => setNames((all) => all.map((n, i) => (i === index ? value : n)))}
          error={fieldError(`travellers.${index}.name`)}
          required
        />
      ))}
      <span className="text-12 text-app-muted">{t('newBooking.passportsLater')}</span>
      {convert.error && !(convert.error instanceof ApiError && convert.error.status === 422) ? <ErrorNotice error={convert.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('success')}
          disabled={convert.isPending || !travelDate || names.some((name) => !name.trim())}
          onClick={() =>
            convert.mutate(undefined, {
              onSuccess: (response) => {
                toast(t('quotations.converted', { reference: response.data.booking.reference }))
                navigate(`/bookings/${response.data.booking.id}`)
              },
            })
          }
        >
          {convert.isPending ? t('common.saving') : t('quotations.convertSubmit')}
        </button>
      </div>
    </Dialog>
  )
}

function AssignDialog({ q, onClose }: { q: QuotationDetail; onClose: () => void }) {
  const { t } = useTranslation()
  const staff = useQuery({ queryKey: ['assignable-staff'], queryFn: ({ signal }) => api.get<Data<{ id: number; name: string; role: string | null }[]>>('admin/assignable-staff', signal).then((r) => r.data) })
  const [staffId, setStaffId] = useState(q.assigned_staff ? String(q.assigned_staff.id) : '')
  const [reason, setReason] = useState('')
  const assign = useQuotationMutation(() => quotationActions.assign(q.id, { staff_id: Number(staffId), reason }), (r) => r.data)

  return (
    <Dialog open onClose={onClose} title={t('quotations.assignTitle', { number: q.number })}>
      {staff.isPending ? (
        <Loading />
      ) : (
        <SelectInput label={t('ownership.owner')} value={staffId} onChange={setStaffId} options={[{ value: '', label: t('common.choose') }, ...(staff.data ?? []).map((person) => ({ value: String(person.id), label: person.name }))]} />
      )}
      <TextArea label={t('customers.assignReason')} value={reason} onChange={setReason} rows={2} hint={t('quotations.assignHint')} />
      {assign.error ? <ErrorNotice error={assign.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button type="button" className={buttonClass('primary')} disabled={!staffId || reason.trim().length < 3 || assign.isPending} onClick={() => assign.mutate(undefined, { onSuccess: onClose })}>
          {t('customers.assign')}
        </button>
      </div>
    </Dialog>
  )
}
