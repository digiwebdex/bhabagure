import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { EvidenceInput, evidenceReady } from '../../components/ui/EvidenceInput'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { NumberInput, SelectInput, TextArea, TextInput } from '../../components/ui/fields'
import { Card, CardTitle, Chips, EmptyState, Loading } from '../../components/ui/layout'
import { api, ApiError, fetchDocument } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'
import { useFormat } from '../../lib/useFormat'
import { paymentActions, useDeals, usePaymentOptions, usePaymentsMutation, type Deal } from './api'

type ClientHit = { id: number; name: string; type: string; contact_phone: string | null }
type CustomerHit = { id: number; name: string; phone: string }

const STATES = ['open', 'paid', 'void', 'all'] as const

/**
 * "Deals · advance & due" (§4.6, question 3): a standalone invoice for a company or a customer. The advance is a real
 * payment in the cash book; what is paid and due always comes from the ledger.
 */
export function DealsCard() {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const { can } = useAuth()
  const [state, setState] = useState<(typeof STATES)[number]>('open')
  const deals = useDeals(state)

  return (
    <Card>
      <CardTitle bn="ডিল · অগ্রিম ও বাকি" en="Deals · advance & due" aside={deals.data ? <span className="text-13 font-semibold text-amber">{t('payments.totalDue', { amount: bdt(deals.data.meta.total_due) })}</span> : null} />
      {can('invoices.manage') ? <NewDealForm /> : null}
      <Chips label={t('payments.dealState')} value={state} onChange={setState} options={STATES.map((value) => ({ value, label: t(`payments.dealStates.${value}`) }))} />
      {deals.isPending ? (
        <Loading />
      ) : deals.isError ? (
        <ErrorNotice error={deals.error} />
      ) : deals.data.data.length === 0 ? (
        <EmptyState title={t('payments.noDeals')} />
      ) : (
        <div className="flex flex-col gap-3" data-testid="deals">
          {deals.data.data.map((deal) => (
            <DealRow key={deal.id} deal={deal} />
          ))}
        </div>
      )}
    </Card>
  )
}

function NewDealForm() {
  const { t } = useTranslation()
  const { bdt, digits } = useFormat()
  const toast = useToast()
  const options = usePaymentOptions()
  const [partyKind, setPartyKind] = useState<'company' | 'customer'>('company')
  const [lookup, setLookup] = useState('')
  const [client, setClient] = useState<ClientHit | null>(null)
  const [customer, setCustomer] = useState<CustomerHit | null>(null)
  const blank = { companyType: 'corporate', phone: '', title: '', note: '', total: null as number | null, advance: null as number | null, method: 'bank_transfer', reference: '' }
  const [form, setForm] = useState(blank)
  const set = (patch: Partial<typeof form>) => setForm({ ...form, ...patch })

  const clients = useQuery({
    queryKey: ['payments', 'clients', lookup.trim()],
    queryFn: ({ signal }) => api.get<Data<ClientHit[]>>(`admin/deals/clients?search=${encodeURIComponent(lookup.trim())}`, signal).then((r) => r.data),
    enabled: partyKind === 'company' && !client && lookup.trim().length >= 2,
  })
  const customers = useQuery({
    queryKey: ['search', lookup.trim()],
    queryFn: ({ signal }) => api.get<Data<{ customers?: CustomerHit[] }>>(`admin/search?q=${encodeURIComponent(lookup.trim())}`, signal).then((r) => r.data.customers ?? []),
    enabled: partyKind === 'customer' && !customer && lookup.trim().length >= 2,
  })

  const [advanceEvidence, setAdvanceEvidence] = useState<File | null>(null)
  const create = usePaymentsMutation(() => {
    const body = new FormData()
    const put = (key: string, value: string | number | null | undefined) => value !== null && value !== undefined && value !== '' && body.append(key, String(value))
    if (partyKind === 'customer' && customer) put('customer_id', customer.id)
    else if (client) put('client_id', client.id)
    else {
      put('company[name]', lookup.trim())
      put('company[type]', form.companyType)
      put('company[contact_phone]', form.phone)
    }
    put('title', form.title.trim())
    put('note', form.note.trim())
    put('total', form.total)
    put('advance', form.advance ?? 0)
    if ((form.advance ?? 0) > 0) {
      put('advance_method', form.method)
      put('advance_reference', form.reference.trim())
      if (advanceEvidence) body.append('advance_evidence', advanceEvidence)
    }
    return paymentActions.createDeal(body)
  })
  const fieldError = (name: string) => (create.error instanceof ApiError ? create.error.field(name) : undefined)
  const total = form.total ?? 0
  const advance = form.advance ?? 0
  const hasParty = partyKind === 'customer' ? !!customer : !!client || lookup.trim().length >= 2
  // An advance is money received: its receipt comes with it.
  const ready = hasParty && form.title.trim().length >= 3 && total >= 1 && advance <= total && (advance === 0 || (!!advanceEvidence && evidenceReady(advanceEvidence)))

  return (
    <form
      className="flex flex-col gap-2.5 rounded-13 border border-app-line bg-app-surface-2 p-3.75"
      data-testid="new-deal"
      onSubmit={(event) => {
        event.preventDefault()
        if (!ready) return
        create.mutate(undefined, {
          onSuccess: (response) => {
            toast(t('payments.dealCreated', { number: response.data.number }))
            setForm(blank)
            setAdvanceEvidence(null)
            setLookup('')
            setClient(null)
            setCustomer(null)
          },
        })
      }}
    >
      <Chips
        label={t('payments.billTo')}
        value={partyKind}
        onChange={(kind) => {
          setPartyKind(kind)
          setClient(null)
          setCustomer(null)
        }}
        options={[
          { value: 'company', label: t('payments.company') },
          { value: 'customer', label: t('bookings.customer') },
        ]}
      />
      {client || customer ? (
        <div className="flex items-center justify-between gap-2 rounded-10 border border-app-line bg-app-surface px-3 py-2">
          <span className="truncate text-14 font-medium">{(client ?? customer)!.name}</span>
          <button
            type="button"
            className={buttonClass('outline', 'sm')}
            onClick={() => {
              setClient(null)
              setCustomer(null)
            }}
          >
            {t('common.change')}
          </button>
        </div>
      ) : (
        <>
          <TextInput label={partyKind === 'company' ? t('payments.companyName') : t('newBooking.findCustomer')} value={lookup} onChange={setLookup} error={fieldError('company.name') ?? fieldError('customer_id')} />
          {(partyKind === 'company' ? (clients.data ?? []) : (customers.data ?? [])).map((hit) => (
            <button
              key={hit.id}
              type="button"
              className={buttonClass('outline', 'sm', 'justify-between')}
              onClick={() => (partyKind === 'company' ? setClient(hit as ClientHit) : setCustomer(hit as CustomerHit))}
            >
              <span className="truncate">{hit.name}</span>
              {'phone' in hit ? <span className="font-display text-12 text-app-muted">{digits(hit.phone.replace(/^88/, ''))}</span> : null}
            </button>
          ))}
          {partyKind === 'company' && lookup.trim().length >= 2 ? (
            <div className="grid-auto-fit-half-140 grid gap-2.5">
              <SelectInput label={t('payments.companyType')} value={form.companyType} onChange={(companyType) => set({ companyType })} options={['corporate', 'b2b_agent'].map((value) => ({ value, label: t(`payments.companyTypes.${value}`) }))} />
              <TextInput label={t('newBooking.phone')} value={form.phone} onChange={(phone) => set({ phone })} inputMode="tel" error={fieldError('company.contact_phone')} />
            </div>
          ) : null}
        </>
      )}
      <TextInput label={t('payments.dealTitle')} value={form.title} onChange={(title) => set({ title })} hint={t('payments.dealTitleHint')} error={fieldError('title')} />
      <div className="grid-auto-fit-half-124 grid gap-2.5">
        <NumberInput label={t('payments.dealTotal')} value={form.total} onChange={(value) => set({ total: value })} error={fieldError('total')} />
        <NumberInput label={t('payments.advance')} value={form.advance} onChange={(value) => set({ advance: value })} error={fieldError('advance')} />
      </div>
      {advance > 0 && options.data ? (
        <>
          <div className="grid-auto-fit-half-124 grid gap-2.5">
            <SelectInput label={t('bookings.method')} value={form.method} onChange={(method) => set({ method })} options={options.data.methods.map((value) => ({ value, label: t(`bookings.methods.${value}`) }))} />
            <TextInput label={t('bookings.paymentReference')} value={form.reference} onChange={(reference) => set({ reference })} />
          </div>
          <EvidenceInput file={advanceEvidence} onChange={setAdvanceEvidence} error={fieldError('advance_evidence')} />
        </>
      ) : null}
      <TextInput label={t('payments.dealNote')} value={form.note} onChange={(note) => set({ note })} />
      {total > 0 ? (
        <div className={`rounded-10 px-3 py-2.5 text-12.5 leading-1.5 ${advance > total ? 'bg-red-tint text-red' : 'bg-blue-tint text-blue-deep'}`}>
          {advance > total ? t('payments.advanceTooHigh') : t('payments.dealPreview', { total: bdt(total), advance: bdt(advance), due: bdt(total - advance) })}
        </div>
      ) : null}
      {create.error && !(create.error instanceof ApiError && create.error.status === 422) ? <ErrorNotice error={create.error} /> : null}
      <button type="submit" className={buttonClass('primary', 'md')} disabled={!ready || create.isPending}>
        {create.isPending ? t('common.saving') : t('payments.addDeal')}
      </button>
    </form>
  )
}

function DealRow({ deal }: { deal: Deal }) {
  const { t } = useTranslation()
  const { bdt, date, dateTime, locale } = useFormat()
  const { can } = useAuth()
  const toast = useToast()
  const options = usePaymentOptions()
  const [pay, setPay] = useState({ amount: null as number | null, method: 'bkash', reference: '' })
  const [evidence, setEvidence] = useState<File | null>(null)
  const [voiding, setVoiding] = useState(false)
  const record = usePaymentsMutation(() => paymentActions.payDeal(deal.id, { amount: pay.amount ?? 0, method: pay.method, reference: pay.reference.trim() || null, evidence: evidence! }))
  const paidPercent = deal.total_amount > 0 ? Math.min(100, Math.round((deal.paid_amount / deal.total_amount) * 100)) : 0
  const open = deal.status === 'issued' && deal.balance_due > 0
  const tone = deal.status === 'void' ? 'bg-slate-tint text-silver' : deal.payment_status === 'paid' ? 'bg-green-tint text-green-deep' : 'bg-orange-tint text-amber'

  const openPdf = async () => {
    try {
      const blob = await fetchDocument(`admin/deals/${deal.id}/pdf?lang=${locale}`)
      window.open(URL.createObjectURL(blob), '_blank', 'noopener')
    } catch {
      toast(t('bookings.pdfFailed'), 'error')
    }
  }

  return (
    <article className="flex flex-col gap-2.5 rounded-13 border border-app-line p-3.5" data-testid="deal" aria-label={`${deal.number} · ${deal.party.name}`}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <span className="flex min-w-0 flex-col gap-0.5">
          <span className="text-15 font-semibold">{deal.party.name}</span>
          <span className="text-12 text-app-muted">
            {deal.title} · <span className="font-display">{deal.number}</span> · {date(deal.issued_on)}
            {deal.note ? ` · ${deal.note}` : ''}
          </span>
        </span>
        <span className={`rounded-pill px-2.5 py-0.75 text-11 font-bold whitespace-nowrap ${tone}`}>{deal.status === 'void' ? t('payments.void') : t(`bookings.paymentStatus.${deal.payment_status}`)}</span>
      </div>
      <div className="grid-auto-fit-half-84 grid gap-2.5">
        {[
          [t('payments.dealTotal'), bdt(deal.total_amount), ''],
          [t('bookings.paid'), bdt(deal.paid_amount), 'text-green'],
          [t('bookings.due'), bdt(deal.balance_due), deal.balance_due > 0 ? 'text-amber' : ''],
        ].map(([label, value, color]) => (
          <span key={label} className="flex flex-col gap-px">
            <span className="text-11 text-app-muted">{label}</span>
            <span className={`font-display text-15 font-bold ${color}`}>{value}</span>
          </span>
        ))}
      </div>
      <div className="h-1.75 overflow-hidden rounded-4 bg-app-line" role="progressbar" aria-valuenow={paidPercent} aria-valuemin={0} aria-valuemax={100} aria-label={t('bookings.paid')}>
        <div className="h-full bg-linear-90 from-green to-green-soft" style={{ width: `${paidPercent}%` }} />
      </div>
      {open && can('transactions.create_manual') && options.data ? (
        <div className="flex flex-col gap-2">
          <div className="flex flex-wrap items-end gap-2">
            <NumberInput label={t('payments.receive')} value={pay.amount} onChange={(amount) => setPay({ ...pay, amount })} className="min-w-26 flex-1" />
            <SelectInput label={t('bookings.method')} value={pay.method} onChange={(method) => setPay({ ...pay, method })} options={options.data.methods.map((value) => ({ value, label: t(`bookings.methods.${value}`) }))} className="min-w-26 flex-1" />
          </div>
          <EvidenceInput file={evidence} onChange={setEvidence} error={record.error instanceof ApiError ? record.error.field('evidence') : undefined} />
          <button
            type="button"
            className={buttonClass('success', 'md', 'self-start')}
            disabled={(pay.amount ?? 0) < 1 || !evidence || !evidenceReady(evidence) || record.isPending}
            onClick={() =>
              record.mutate(undefined, {
                onSuccess: () => {
                  toast(t('payments.dealPaid', { number: deal.number }))
                  setPay({ ...pay, amount: null, reference: '' })
                  setEvidence(null)
                },
              })
            }
          >
            {t('payments.receiveSubmit')}
          </button>
        </div>
      ) : null}
      {record.error ? <ErrorNotice error={record.error} /> : null}
      {deal.payments.map((payment) => (
        <div key={payment.id} className="flex justify-between gap-2.5 border-t border-app-line pt-2 text-12 text-app-muted">
          <span className="min-w-0">
            {t(`bookings.methods.${payment.method}`)} · {dateTime(payment.occurred_at)}
            {payment.reference ? ` · ${payment.reference}` : ''}
            {payment.is_reversal ? ` · ${t('bookings.reversal')}` : ''}
          </span>
          <span className={`font-display whitespace-nowrap ${payment.direction === 'in' ? 'text-green' : 'text-amber'}`}>{payment.direction === 'in' ? bdt(payment.amount) : `− ${bdt(payment.amount)}`}</span>
        </div>
      ))}
      <div className="flex flex-wrap gap-2">
        <button type="button" className={buttonClass('outline', 'sm')} onClick={() => void openPdf()}>
          {t('table.pdf')}
        </button>
        {deal.status === 'issued' && can('invoices.manage') ? (
          <button type="button" className={buttonClass('danger', 'sm')} onClick={() => setVoiding(true)} disabled={deal.paid_amount > 0} title={deal.paid_amount > 0 ? t('payments.voidReverseFirst') : undefined}>
            {t('payments.voidDeal')}
          </button>
        ) : null}
      </div>
      {voiding ? <VoidDialog deal={deal} onClose={() => setVoiding(false)} /> : null}
    </article>
  )
}

function VoidDialog({ deal, onClose }: { deal: Deal; onClose: () => void }) {
  const { t } = useTranslation()
  const [reason, setReason] = useState('')
  const voidDeal = usePaymentsMutation(() => paymentActions.voidDeal(deal.id, reason.trim()))

  return (
    <Dialog open onClose={onClose} title={t('payments.voidTitle', { number: deal.number })}>
      <TextArea label={t('bookings.reason')} value={reason} onChange={setReason} rows={2} />
      {voidDeal.error ? <ErrorNotice error={voidDeal.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button type="button" className={buttonClass('danger')} disabled={reason.trim().length < 3 || voidDeal.isPending} onClick={() => voidDeal.mutate(undefined, { onSuccess: onClose })}>
          {t('payments.voidDeal')}
        </button>
      </div>
    </Dialog>
  )
}
