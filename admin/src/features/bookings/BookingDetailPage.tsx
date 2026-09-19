import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'

import { invoiceTotals, paymentStatus, quoteBooking, type RoomType } from '@bhabaghure/pricing'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { EvidenceInput, evidenceReady } from '../../components/ui/EvidenceInput'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { NumberInput, SelectInput, Switch, TextArea, TextInput } from '../../components/ui/fields'
import { Badge, Card, CardTitle, Loading, PageHeader } from '../../components/ui/layout'
import { ApiError, fetchDocument } from '../../lib/api/client'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import { sendBookingWhatsApp, setCustomerOptOut } from '../notifications/api'
import { useReviewDocument, useSetIssuedStatus, type DocumentSlot, type IssuedKind, type IssuedStatus } from '../documents/api'
import { RejectDocumentDialog } from '../documents/DocumentReview'
import { useOpenDocument } from '../documents/useOpenDocument'
import { ChannelGroups } from '../notifications/MessageList'
import { bookingActions, useBooking, useBookingAction, type BookingDetail } from './api'
import { BookingStatusBadge, PaymentBadge } from './badges'
import { TicketsCard } from './TicketsCard'
import { TravellerEditDialog } from './TravellerEditDialog'

export function BookingDetailPage() {
  const id = Number(useParams().id)
  const booking = useBooking(id)

  if (booking.isPending) return <Loading />
  if (booking.isError) return <ErrorNotice error={booking.error} />
  return <BookingView key={id} booking={booking.data.data} />
}

function BookingView({ booking }: { booking: BookingDetail }) {
  const { t } = useTranslation()
  const { date } = useFormat()
  const toast = useToast()
  const transition = useBookingAction(booking.id, bookingActions.transition(booking.id))
  const [cancelOpen, setCancelOpen] = useState(false)

  const run = (action: 'confirm' | 'complete') =>
    transition.mutate({ action }, { onSuccess: () => toast(t(`bookings.done.${action}`)) })

  return (
    <>
      <PageHeader
        title={booking.reference}
        subtitle={`${booking.package_title_en || booking.package_title_bn}${booking.travel_start ? ` · ${date(booking.travel_start)}` : ''}`}
        actions={
          <>
            <BookingStatusBadge status={booking.status} />
            <PaymentBadge status={booking.payment_status} />
            {booking.actions.confirm ? (
              <button type="button" className={buttonClass('success')} disabled={transition.isPending} onClick={() => run('confirm')}>
                {t('bookings.confirm')}
              </button>
            ) : null}
            {booking.actions.complete ? (
              <button type="button" className={buttonClass('outline')} disabled={transition.isPending} onClick={() => run('complete')}>
                {t('bookings.complete')}
              </button>
            ) : null}
            {booking.actions.cancel ? (
              <button type="button" className={buttonClass('danger')} onClick={() => setCancelOpen(true)}>
                {t('bookings.cancel')}
              </button>
            ) : null}
          </>
        }
      />
      <Link to="/bookings" className="-mt-2 text-13">
        ← {t('bookings.back')}
      </Link>
      {transition.error ? <ErrorNotice error={transition.error} /> : null}
      {booking.status === 'cancelled' && booking.cancellation_reason ? (
        <p role="note" className="m-0 rounded-10 bg-app-surface-2 px-3 py-2.5 text-13 text-app-muted">
          {t('bookings.cancelledBecause', { reason: booking.cancellation_reason })}
        </p>
      ) : null}

      <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
        <div className="flex min-w-0 flex-col gap-4.5">
          <QuoteCard booking={booking} />
          <InvoiceCard booking={booking} />
        </div>
        <div className="flex min-w-0 flex-col gap-4.5">
          <PaymentsCard booking={booking} />
          <TravellersCard booking={booking} />
          <TicketsCard booking={booking} />
          <MessagesCard booking={booking} />
          <AttemptsCard booking={booking} />
        </div>
      </div>

      <ReasonDialog
        open={cancelOpen}
        title={t('bookings.cancelTitle')}
        label={t('bookings.reason')}
        confirmLabel={t('bookings.cancel')}
        pending={transition.isPending}
        error={transition.error}
        onClose={() => setCancelOpen(false)}
        onSubmit={(reason) => transition.mutate({ action: 'cancel', reason }, { onSuccess: () => { setCancelOpen(false); toast(t('bookings.done.cancel')) } })}
      />
    </>
  )
}

/**
 * Draft-invoice controls from the prototype (travellers, VAT, discount). Totals come from @bhabaghure/pricing with the
 * inputs the API sent, and the save is checked by the API's PHP twin — the two can't show different numbers.
 */
function QuoteCard({ booking }: { booking: BookingDetail }) {
  const { t } = useTranslation()
  const { bdt, number, percent } = useFormat()
  const toast = useToast()
  const save = useBookingAction(booking.id, bookingActions.quote(booking.id))
  const [pax, setPax] = useState(booking.pax_count)
  const [room, setRoom] = useState<RoomType>(booking.room_type)
  const [vat, setVat] = useState(booking.vat_rate)
  const [discount, setDiscount] = useState<number | null>(booking.discount_amount || null)

  const inputs = booking.quote_inputs
  const quote = useMemo(() => {
    try {
      // A custom service: its own items for every traveller, the discount, then VAT — the API's customQuote exactly.
      if (inputs.custom_items) {
        const lines = inputs.custom_items.map((item, index) => ({ kind: 'custom', code: `item-${index}`, title: item.title, quantity: pax, unitPrice: item.unitPrice, amount: pax * item.unitPrice }))
        const totals = invoiceTotals({ lines, discount: discount ?? 0, chargePercent: vat })
        return { lines, discount: totals.discount, serviceCharge: totals.charge, total: totals.total }
      }
      return quoteBooking({ listPrice: inputs.list_price, pax, room, addons: inputs.addons, config: inputs.config, discount: discount ?? 0, chargePercent: vat, grid: inputs.grid, hotelCategory: inputs.hotel_category })
    } catch {
      return null
    }
  }, [inputs, pax, room, vat, discount])

  const editable = booking.actions.edit_quote
  const changed = pax !== booking.pax_count || room !== booking.room_type || vat !== booking.vat_rate || (discount ?? 0) !== booking.discount_amount
  const total = editable && quote ? quote.total : booking.total_amount
  const status = paymentStatus(total, booking.paid_amount)
  const lineTitle = (kind: string, code: string | null) =>
    kind === 'package' ? t('bookings.line.package') : kind === 'single_supplement' ? t('bookings.line.single') : (booking.lines.find((l) => l.code === code)?.title_en ?? code ?? '')

  // A custom service's lines carry their own names.
  const lines = editable && quote
    ? quote.lines.map((line, index) => ({ key: `${line.kind}-${line.code ?? index}`, title: 'title' in line && typeof line.title === 'string' ? line.title : lineTitle(line.kind, line.code), quantity: line.quantity, unitPrice: line.unitPrice, amount: line.amount }))
    : booking.lines.map((line, index) => ({ key: `${line.kind}-${line.code ?? index}`, title: line.kind === 'custom' ? line.title_en : lineTitle(line.kind, line.code), quantity: line.quantity, unitPrice: line.unit_price, amount: line.amount }))

  const onSave = () => {
    if (!quote) return
    save.mutate(
      { pax, room, discount: discount ?? 0, vat_rate: vat, expected_total: quote.total },
      { onSuccess: () => toast(t('bookings.quoteSaved')) },
    )
  }

  return (
    <Card>
      <CardTitle title="Quote" aside={editable ? <Badge tone="blue">{t('bookings.draft')}</Badge> : <Badge tone="slate">{t('bookings.frozen')}</Badge>} />
      {booking.hotel_category ? (
        <p className="m-0 text-13" data-testid="booking-hotel-category">
          <span className="text-app-muted">{t('grid.hotelCategory')}:</span> <strong>{t(`grid.categories.${booking.hotel_category}`)}</strong>
        </p>
      ) : null}
      {editable ? (
        <div className="grid-auto-fit-140 grid gap-3">
          <div className="flex flex-col gap-1.25">
            <span className="text-13 text-app-muted">{t('bookings.travellers')}</span>
            <span className="flex items-center overflow-hidden rounded-9 border border-app-line bg-app-surface-2">
              <button type="button" aria-label={t('bookings.fewer')} className="h-10 w-9 cursor-pointer text-17 text-blue disabled:opacity-40" disabled={pax <= 1} onClick={() => setPax(pax - 1)}>
                −
              </button>
              <span className="flex-1 text-center font-display text-15 font-bold" aria-live="polite">
                {number(pax)}
              </span>
              <button type="button" aria-label={t('bookings.more')} className="h-10 w-9 cursor-pointer text-17 text-blue disabled:opacity-40" disabled={pax >= inputs.config.maxTravellers} onClick={() => setPax(pax + 1)}>
                +
              </button>
            </span>
          </div>
          {booking.is_custom ? null : (
            <SelectInput label={t('bookings.room')} value={room} onChange={(value) => setRoom(value as RoomType)} options={(['twin', 'triple', 'single'] as const).map((value) => ({ value, label: t(`bookings.rooms.${value}`) }))} />
          )}
          <SelectInput label={t('bookings.vat')} value={String(vat)} onChange={(value) => setVat(Number(value))} options={booking.vat_rates.map((rate) => ({ value: String(rate), label: percent(rate) }))} />
          <NumberInput label={t('bookings.discount')} value={discount} onChange={setDiscount} min={0} inputMode="numeric" />
        </div>
      ) : null}

      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-13">
          <tbody>
            {lines.map((line) => (
              <tr key={line.key} className="border-b border-app-line">
                <td className="py-2 pr-2">{line.title}</td>
                <td className="py-2 text-right font-display whitespace-nowrap text-app-muted">
                  {number(line.quantity)} × {bdt(line.unitPrice)}
                </td>
                <td className="py-2 pl-3 text-right font-display font-semibold whitespace-nowrap">{bdt(line.amount)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <dl className="m-0 flex flex-col gap-1.5 text-13">
        {(editable && quote ? quote.discount : booking.discount_amount) > 0 ? (
          <Row label={t('bookings.discount')} value={`− ${bdt(editable && quote ? quote.discount : booking.discount_amount)}`} />
        ) : null}
        <Row label={t('bookings.vatLine', { rate: percent(editable ? vat : booking.vat_rate) })} value={bdt(editable && quote ? quote.serviceCharge : booking.vat_amount)} />
        <Row label={t('bookings.total')} value={bdt(total)} strong />
        <Row label={t('bookings.paid')} value={bdt(booking.paid_amount)} />
        <Row label={t('bookings.due')} value={bdt(total - booking.paid_amount)} />
      </dl>
      <div className="flex flex-wrap items-center justify-between gap-2.5">
        <PaymentBadge status={status} />
        {editable ? (
          <button type="button" className={buttonClass('primary')} disabled={!changed || !quote || save.isPending} onClick={onSave}>
            {save.isPending ? t('common.saving') : t('bookings.saveQuote')}
          </button>
        ) : (
          <span className="text-12 text-app-muted">{t('bookings.frozenNote')}</span>
        )}
      </div>
      {save.error ? <ErrorNotice error={save.error} /> : null}
    </Card>
  )
}

function Row({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className={`flex justify-between gap-3 ${strong ? 'border-t border-app-line pt-1.5 text-15 font-bold' : ''}`}>
      <dt className={strong ? '' : 'text-app-muted'}>{label}</dt>
      <dd className="m-0 font-display font-semibold">{value}</dd>
    </div>
  )
}

const A4_WIDTH_PX = (210 / 25.4) * 96

/** The invoice exactly as it prints (the API's print view), scaled to the card. Header on/off is the pad setting. */
function InvoiceCard({ booking }: { booking: BookingDetail }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [header, setHeader] = useState(true)
  const [lang, setLang] = useState<'bn' | 'en'>('bn')
  const [html, setHtml] = useState<string | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [scale, setScale] = useState(1)
  const [voidOpen, setVoidOpen] = useState(false)
  const frame = useRef<HTMLIFrameElement>(null)
  const box = useRef<HTMLDivElement>(null)
  const issue = useBookingAction(booking.id, bookingActions.issue(booking.id))
  const voidInvoice = useBookingAction(booking.id, bookingActions.void())
  const current = booking.invoices.find((invoice) => invoice.status === 'issued') ?? null
  const query = `header=${header ? 1 : 0}&lang=${lang}`
  const version = `${booking.paid_amount}-${booking.total_amount}-${current?.id ?? 'draft'}-${booking.invoices.length}`

  useEffect(() => {
    let alive = true
    fetchDocument(`admin/bookings/${booking.id}/invoice/print?${query}`)
      .then((blob) => blob.text())
      .then((text) => alive && (setHtml(text), setError(null)))
      .catch((reason: unknown) => alive && setError(reason))
    return () => {
      alive = false
    }
  }, [booking.id, query, version])

  useEffect(() => {
    const element = box.current
    if (!element) return
    const observer = new ResizeObserver(([entry]) => setScale(Math.min(1, entry.contentRect.width / A4_WIDTH_PX)))
    observer.observe(element)
    return () => observer.disconnect()
  }, [])

  const openPdf = async () => {
    try {
      const blob = await fetchDocument(`admin/bookings/${booking.id}/invoice/pdf?${query}`)
      const url = URL.createObjectURL(blob)
      window.open(url, '_blank', 'noopener')
      setTimeout(() => URL.revokeObjectURL(url), 60_000)
    } catch (reason) {
      setError(reason)
    }
  }

  const copyLink = async () => {
    if (!current?.share_url) return
    await navigator.clipboard.writeText(current.share_url)
    toast(t('bookings.linkCopied'))
  }

  return (
    <Card>
      <CardTitle title="Invoice" aside={current ? <span className="font-display text-13 font-semibold">{current.invoice_number}</span> : <Badge tone="orange">{t('bookings.notIssued')}</Badge>} />

      <div className="flex flex-wrap items-center justify-between gap-2.5">
        <button type="button" role="switch" aria-checked={header} onClick={() => setHeader(!header)} className="flex cursor-pointer items-center gap-2.5 rounded-10 border border-app-line bg-app-surface-2 px-3 py-2 text-left text-13">
          <span className={`relative h-5.5 w-9.5 shrink-0 rounded-pill transition-colors ${header ? 'bg-green' : 'bg-app-line'}`}>
            <span className={`absolute top-0.75 size-4 rounded-full bg-white transition-all ${header ? 'left-4.75' : 'left-0.75'}`} />
          </span>
          <span className="flex flex-col leading-1.3">
            <strong className="font-semibold">{header ? t('bookings.headerOn') : t('bookings.headerOff')}</strong>
            <span className="text-12 text-app-muted">{header ? t('bookings.headerOnNote') : t('bookings.headerOffNote')}</span>
          </span>
        </button>
        <span className="flex flex-wrap gap-2">
          <select aria-label={t('bookings.invoiceLanguage')} value={lang} onChange={(event) => setLang(event.target.value as 'bn' | 'en')} className="rounded-9 border border-app-line bg-app-surface-2 px-2.5 py-2 text-13">
            <option value="bn">Bangla digits</option>
            <option value="en">English digits</option>
          </select>
          <button type="button" className={buttonClass('primary', 'sm')} disabled={!html} onClick={() => frame.current?.contentWindow?.print()}>
            ⎙ {t('bookings.print')}
          </button>
          <button type="button" className={buttonClass('outline', 'sm')} onClick={() => void openPdf()}>
            ↓ PDF
          </button>
        </span>
      </div>

      {error ? <ErrorNotice error={error} /> : null}
      <div ref={box} className="overflow-hidden rounded-10 border border-app-line bg-app-surface-2">
        <div className="aspect-a4 w-full">
          {html ? (
            <iframe
              ref={frame}
              title={t('bookings.invoicePreview')}
              srcDoc={html}
              sandbox="allow-same-origin allow-modals"
              className="origin-top-left border-0 bg-white"
              style={{ width: `${A4_WIDTH_PX}px`, height: `${A4_WIDTH_PX * (297 / 210)}px`, transform: `scale(${scale})` }}
            />
          ) : (
            <Loading />
          )}
        </div>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-2.5">
        {booking.actions.issue_invoice ? (
          <button type="button" className={buttonClass('cta')} disabled={issue.isPending} onClick={() => issue.mutate(undefined, { onSuccess: () => toast(t('bookings.done.issued')) })}>
            {t('bookings.issue')}
          </button>
        ) : current?.share_url ? (
          <button type="button" className={buttonClass('outline', 'sm')} onClick={() => void copyLink()}>
            {t('bookings.copyLink')}
          </button>
        ) : (
          <span />
        )}
        {booking.actions.void_invoice && current ? (
          <button type="button" className={buttonClass('danger', 'sm')} onClick={() => setVoidOpen(true)}>
            {t('bookings.void')}
          </button>
        ) : null}
      </div>
      {issue.error ? <ErrorNotice error={issue.error} /> : null}
      {booking.invoices.some((invoice) => invoice.status === 'void') ? (
        <ul className="m-0 flex list-none flex-col gap-1 p-0 text-12 text-app-muted">
          {booking.invoices.filter((invoice) => invoice.status === 'void').map((invoice) => (
            <li key={invoice.id}>
              {t('bookings.voided', { number: invoice.invoice_number, reason: invoice.void_reason })}
            </li>
          ))}
        </ul>
      ) : null}

      {current ? (
        <ReasonDialog
          open={voidOpen}
          title={t('bookings.voidTitle', { number: current.invoice_number })}
          note={t('bookings.voidNote')}
          label={t('bookings.reason')}
          confirmLabel={t('bookings.void')}
          pending={voidInvoice.isPending}
          error={voidInvoice.error}
          onClose={() => setVoidOpen(false)}
          onSubmit={(reason) => voidInvoice.mutate({ invoiceId: current.id, reason }, { onSuccess: () => { setVoidOpen(false); toast(t('bookings.done.voided')) } })}
        />
      ) : null}
    </Card>
  )
}

/** Payments come only from "Record payment" (or SSLCommerz) — the paid amount is never typed onto the invoice. */
function PaymentsCard({ booking }: { booking: BookingDetail }) {
  const { t } = useTranslation()
  const { bdt, date } = useFormat()
  const toast = useToast()
  const [recordOpen, setRecordOpen] = useState(false)
  const [reversing, setReversing] = useState<number | null>(null)
  const reverse = useBookingAction(booking.id, bookingActions.reverse())
  const reversed = new Set(booking.transactions.map((row) => row.reverses_transaction_id).filter(Boolean))
  const { can } = useAuth()
  const openEvidence = async (id: number) => {
    try {
      const blob = await fetchDocument(`admin/cash-book/${id}/evidence`)
      window.open(URL.createObjectURL(blob), '_blank', 'noopener')
    } catch {
      toast(t('evidence.openFailed'), 'error')
    }
  }

  return (
    <Card>
      <CardTitle title="Payments" aside={<PaymentBadge status={booking.payment_status} />} />
      {booking.transactions.length === 0 ? (
        <p className="m-0 text-13 text-app-muted">{t('bookings.noPayments')}</p>
      ) : (
        <ul className="m-0 flex list-none flex-col p-0">
          {booking.transactions.map((row) => (
            <li key={row.id} className="flex items-start justify-between gap-3 border-b border-app-line py-2.5 last:border-b-0">
              <span className="flex min-w-0 flex-col gap-0.5">
                <span className="text-13 font-medium">
                  {t(`bookings.methods.${row.method}`, { defaultValue: row.method })}
                  {/* A bKash payment's charge is bKash's, not a gateway's (Phase 8 §4.F). */}
                  {row.category !== 'customer_payment'
                    ? ` · ${t(`bookings.categories.${row.category === 'online_payment_charge' && row.method === 'bkash' ? 'bkash_charge' : row.category}`, { defaultValue: row.category })}`
                    : ''}
                  {row.reverses_transaction_id ? ` · ${t('bookings.reversal')}` : ''}
                </span>
                <span className="truncate text-12 text-app-muted">
                  {date(row.occurred_at)}
                  {row.external_ref ? ` · ${row.external_ref}` : ''}
                </span>
                {row.has_evidence && can('payments.view') ? (
                  <button type="button" className="cursor-pointer self-start border-0 bg-transparent p-0 text-11 font-semibold text-green hover:underline" onClick={() => void openEvidence(row.id)}>
                    ⎘ {t('evidence.open')}
                  </button>
                ) : null}
              </span>
              <span className="flex shrink-0 flex-col items-end gap-1">
                <span className={`font-display text-14 font-semibold ${row.direction === 'out' ? 'text-red' : 'text-green-deep'}`}>
                  {row.direction === 'out' ? '− ' : ''}
                  {bdt(row.amount)}
                </span>
                {booking.actions.reverse_payment && row.category === 'customer_payment' && !row.reverses_transaction_id && !reversed.has(row.id) && booking.status !== 'completed' ? (
                  <button type="button" className="cursor-pointer text-12 text-app-muted underline hover:text-red" onClick={() => setReversing(row.id)}>
                    {t('bookings.reverse')}
                  </button>
                ) : null}
              </span>
            </li>
          ))}
        </ul>
      )}
      {booking.actions.record_payment ? (
        <button type="button" className={buttonClass('success')} onClick={() => setRecordOpen(true)}>
          {t('bookings.recordPayment')}
        </button>
      ) : booking.invoices.every((invoice) => invoice.status !== 'issued') && booking.status !== 'cancelled' ? (
        <p className="m-0 text-12 text-app-muted">{t('bookings.issueFirst')}</p>
      ) : null}

      <RecordPaymentDialog booking={booking} open={recordOpen} onClose={() => setRecordOpen(false)} />
      <ReasonDialog
        open={reversing !== null}
        title={t('bookings.reverseTitle')}
        note={t('bookings.reverseNote')}
        label={t('bookings.reason')}
        confirmLabel={t('bookings.reverse')}
        pending={reverse.isPending}
        error={reverse.error}
        onClose={() => setReversing(null)}
        onSubmit={(reason) => reversing !== null && reverse.mutate({ transactionId: reversing, reason }, { onSuccess: () => { setReversing(null); toast(t('bookings.done.reversed')) } })}
      />
    </Card>
  )
}

function RecordPaymentDialog({ booking, open, onClose }: { booking: BookingDetail; open: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const pay = useBookingAction(booking.id, bookingActions.pay(booking.id))
  const [amount, setAmount] = useState<number | null>(booking.due_amount)
  const [method, setMethod] = useState('cash')
  const [reference, setReference] = useState('')
  const [occurredAt, setOccurredAt] = useState(todayInDhaka)
  const [note, setNote] = useState('')
  const [evidence, setEvidence] = useState<File | null>(null)
  const [bkashCharge, setBkashCharge] = useState(false)
  const fieldError = pay.error instanceof ApiError ? pay.error : null
  // Phase 8 §4.F: a bKash customer sends the charge on top; it is booked as a charge, not as payment for the tour.
  const chargePercent = booking.bkash_charge_percent ?? 0
  const charge = method === 'bkash' && chargePercent > 0 && amount ? Math.round((amount * chargePercent) / 100) : 0

  const presets = [
    { key: 'full', value: booking.due_amount },
    { key: 'half', value: Math.round(booking.total_amount / 2) - booking.paid_amount },
  ].filter((preset) => preset.value > 0)

  const submit = () => {
    if (!amount || !evidence || !evidenceReady(evidence)) return
    pay.mutate(
      { amount, method, reference: reference.trim(), occurred_at: occurredAt, note: note.trim(), evidence, bkash_charge: charge > 0 && bkashCharge ? '1' : '' },
      { onSuccess: () => { toast(t('bookings.done.paid')); onClose() } },
    )
  }

  return (
    <Dialog open={open} onClose={onClose} title={t('bookings.recordPayment')}>
      <p className="m-0 text-13 text-app-muted">{t('bookings.recordNote', { due: bdt(booking.due_amount) })}</p>
      <div className="flex flex-wrap gap-2">
        {presets.map((preset) => (
          <button key={preset.key} type="button" onClick={() => setAmount(preset.value)} className={`border-chip cursor-pointer rounded-pill px-3.5 py-1.5 text-12 font-semibold ${amount === preset.value ? 'border-blue bg-blue-tint text-blue-deep' : 'border-app-line bg-app-surface text-app-muted'}`}>
            {t(`bookings.preset.${preset.key}`, { amount: bdt(preset.value) })}
          </button>
        ))}
      </div>
      <NumberInput label={t('bookings.amount')} value={amount} onChange={setAmount} error={fieldError?.field('amount') ?? (fieldError?.code === 'exceeds_balance' ? fieldError.message : undefined)} preview={(value) => bdt(value)} />
      <SelectInput label={t('bookings.method')} value={method} onChange={setMethod} options={booking.payment_methods.map((value) => ({ value, label: t(`bookings.methods.${value}`, { defaultValue: value }) }))} />
      <TextInput label={t('bookings.paymentReference')} value={reference} onChange={setReference} hint={t('bookings.paymentReferenceHint')} error={fieldError?.code === 'duplicate_reference' ? fieldError.message : fieldError?.field('reference')} />
      {charge > 0 ? (
        <Switch
          label={t('bookings.bkashCharge', { amount: bdt(charge) })}
          hint={t('bookings.bkashChargeHint', { amount: bdt(amount ?? 0) })}
          checked={bkashCharge}
          onChange={setBkashCharge}
        />
      ) : null}
      {fieldError?.field('bkash_charge') ? (
        <p role="alert" className="m-0 text-12 font-semibold text-red">
          {fieldError.field('bkash_charge')}
        </p>
      ) : null}
      <TextInput label={t('bookings.paidOn')} type="date" value={occurredAt} onChange={setOccurredAt} max={todayInDhaka()} error={fieldError?.field('occurred_at')} />
      <TextArea label={t('bookings.note')} value={note} onChange={setNote} rows={2} />
      <EvidenceInput file={evidence} onChange={setEvidence} error={fieldError?.field('evidence')} />
      {pay.error && !(fieldError && (fieldError.status === 422)) ? <ErrorNotice error={pay.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button type="button" className={buttonClass('success')} disabled={!amount || amount <= 0 || !evidence || !evidenceReady(evidence) || pay.isPending} onClick={submit}>
          {pay.isPending ? t('common.saving') : t('bookings.recordPayment')}
        </button>
      </div>
    </Dialog>
  )
}

/**
 * A traveller's portal documents (docs/phase-6-customer-portal.md §3.3): uploads to open, verify or reject; visa and
 * insurance statuses to set. The same rows the Documents queue lists.
 */
function TravellerDocuments({ booking, traveller }: { booking: BookingDetail; traveller: BookingDetail['travellers'][number] }) {
  const { t } = useTranslation()
  const toast = useToast()
  const open = useOpenDocument()
  const review = useReviewDocument()
  const setIssued = useSetIssuedStatus(booking.id)
  const [rejecting, setRejecting] = useState<number | null>(null)
  const [editing, setEditing] = useState<{ kind: IssuedKind; status: IssuedStatus; note: string } | null>(null)
  const may = booking.actions.review_documents
  const tone = (status: DocumentSlot['status']) =>
    status === 'verified' || status === 'issued' ? 'green' : status === 'rejected' ? 'red' : status === 'uploaded' ? 'blue' : status === 'missing' ? 'orange' : 'slate'

  return (
    <div className="flex flex-col gap-1.5" data-testid={`traveller-documents-${traveller.id}`}>
      <div className="flex flex-wrap gap-1.5">
        {traveller.documents.map((slot) => (
          <span key={slot.kind} className="inline-flex items-center gap-1">
            <Badge tone={tone(slot.status)}>
              {t(`documents.kinds.${slot.kind}`)} · {t(`documents.status.${slot.status}`)}
            </Badge>
            {slot.hasFile && slot.id !== null ? (
              <button type="button" className="cursor-pointer text-12 font-semibold text-blue" onClick={() => void open(slot.id!)} aria-label={t('documents.openNamed', { kind: t(`documents.kinds.${slot.kind}`), name: traveller.full_name })}>
                ◉
              </button>
            ) : null}
            {may && slot.status === 'uploaded' && slot.id !== null ? (
              <>
                <button
                  type="button"
                  className="cursor-pointer text-12 font-semibold text-green"
                  disabled={review.isPending}
                  aria-label={t('documents.verifyNamed', { kind: t(`documents.kinds.${slot.kind}`), name: traveller.full_name })}
                  onClick={() => review.mutate({ id: slot.id!, decision: 'verified' }, { onSuccess: () => toast(t('documents.verified', { name: traveller.full_name })) })}
                >
                  ✓
                </button>
                <button type="button" className="cursor-pointer text-12 font-semibold text-red" onClick={() => setRejecting(slot.id)} aria-label={t('documents.rejectNamed', { kind: t(`documents.kinds.${slot.kind}`), name: traveller.full_name })}>
                  ✕
                </button>
              </>
            ) : null}
            {may && (slot.kind === 'visa' || slot.kind === 'insurance') ? (
              <button
                type="button"
                className="cursor-pointer text-12 text-app-muted"
                aria-label={t('documents.setNamed', { kind: t(`documents.kinds.${slot.kind}`), name: traveller.full_name })}
                onClick={() => setEditing({ kind: slot.kind as IssuedKind, status: slot.status === 'missing' ? 'pending' : (slot.status as IssuedStatus), note: slot.note ?? '' })}
              >
                ✎
              </button>
            ) : null}
          </span>
        ))}
      </div>
      {traveller.documents.filter((slot) => slot.status === 'rejected' && slot.note).map((slot) => (
        <span key={slot.kind} className="text-12 text-red">
          {t(`documents.kinds.${slot.kind}`)}: {slot.note}
        </span>
      ))}
      <RejectDocumentDialog id={rejecting} name={traveller.full_name} onClose={() => setRejecting(null)} />
      <Dialog open={editing !== null} onClose={() => setEditing(null)} title={editing ? t('documents.setTitle', { kind: t(`documents.kinds.${editing.kind}`), name: traveller.full_name }) : ''}>
        {editing ? (
          <>
            <SelectInput
              label={t('common.status')}
              value={editing.status}
              onChange={(status) => setEditing({ ...editing, status: status as IssuedStatus })}
              options={(['pending', 'issued', 'not_required'] as const).map((value) => ({ value, label: t(`documents.status.${value}`) }))}
            />
            <TextInput label={t('documents.note')} hint={t('documents.noteHint')} value={editing.note} onChange={(note) => setEditing({ ...editing, note })} maxLength={300} />
            {setIssued.error ? <ErrorNotice error={setIssued.error} /> : null}
            <div className="flex justify-end gap-2">
              <button type="button" className={buttonClass('outline')} onClick={() => setEditing(null)}>
                {t('common.cancel')}
              </button>
              <button
                type="button"
                className={buttonClass('primary')}
                disabled={setIssued.isPending}
                onClick={() => setIssued.mutate({ travellerId: traveller.id, ...editing }, { onSuccess: () => { setEditing(null); toast(t('documents.statusSaved')) } })}
              >
                {t('common.save')}
              </button>
            </div>
          </>
        ) : null}
      </Dialog>
    </div>
  )
}

function TravellersCard({ booking }: { booking: BookingDetail }) {
  const { t } = useTranslation()
  const { date, digits, number } = useFormat()
  const { can } = useAuth()
  const [editing, setEditing] = useState<BookingDetail['travellers'][number] | null>(null)
  const missing = (traveller: BookingDetail['travellers'][number]) =>
    [!traveller.passport_number && 'passport', !traveller.date_of_birth && 'dateOfBirth'].filter((key): key is string => !!key)

  return (
    <Card>
      <CardTitle title="Customer & travellers" />
      {booking.customer ? (
        <div className="flex flex-col gap-0.5 text-13">
          <strong className="text-14">{booking.customer.name}</strong>
          <span className="font-display text-app-muted">{digits(booking.customer.phone.replace(/^88/, ''))}</span>
          {booking.customer.email ? <span className="text-app-muted">{booking.customer.email}</span> : null}
        </div>
      ) : null}
      <ul className="m-0 flex list-none flex-col gap-2 p-0">
        {booking.travellers.map((traveller) => (
          <li key={traveller.id} className="flex flex-col gap-1.5 rounded-10 bg-app-surface-2 px-3 py-2 text-13">
            <span className="flex flex-wrap items-center gap-1.5 font-medium">
              {traveller.full_name}
              {traveller.is_lead ? <Badge tone="blue">{t('bookings.lead')}</Badge> : null}
              {traveller.ocr_filled ? <Badge tone="slate">{t('bookings.ocrFilled')}</Badge> : null}
              {can('bookings.update') ? (
                <button
                  type="button"
                  className="ml-auto cursor-pointer text-12 font-semibold text-blue"
                  aria-label={t('bookings.editTravellerNamed', { name: traveller.full_name })}
                  onClick={() => setEditing(traveller)}
                >
                  {t('bookings.editTraveller')}
                </button>
              ) : null}
            </span>
            <span className="font-display text-12 text-app-muted">
              {traveller.passport_number ?? '—'}
              {traveller.passport_expiry ? ` · ${t('bookings.expires', { date: date(traveller.passport_expiry) })}` : ''}
              {traveller.date_of_birth ? ` · ${t('bookings.born', { date: date(traveller.date_of_birth) })}` : ''}
              {traveller.phone ? ` · ${digits(traveller.phone.replace(/^88/, ''))}` : ''}
            </span>
            {missing(traveller).length > 0 ? (
              <span className="text-12 text-amber" data-testid={`traveller-missing-${traveller.id}`}>
                {t('bookings.detailsMissing', { fields: missing(traveller).map((key) => t(`bookings.missing.${key}`)).join(', ') })}
              </span>
            ) : null}
            <TravellerDocuments booking={booking} traveller={traveller} />
          </li>
        ))}
      </ul>
      {booking.nps ? (
        <p className="m-0 flex flex-wrap items-center gap-2 text-13" data-testid="booking-nps">
          <Badge tone={booking.nps.score >= 9 ? 'green' : booking.nps.score >= 7 ? 'slate' : 'red'}>{t('bookings.nps', { score: number(booking.nps.score) })}</Badge>
          {booking.nps.comment ? <span className="text-app-muted">“{booking.nps.comment}”</span> : null}
        </p>
      ) : null}
      {booking.terms_accepted_at ? <p className="m-0 text-12 text-app-muted">{t('bookings.termsAccepted', { date: date(booking.terms_accepted_at), version: booking.terms_version })}</p> : null}
      <TravellerEditDialog booking={booking} traveller={editing} onClose={() => setEditing(null)} />
    </Card>
  )
}

/** Every WhatsApp and email message about the booking, with delivery ticks; staff can write to the customer from here. */
function MessagesCard({ booking }: { booking: BookingDetail }) {
  const { t } = useTranslation()
  const toast = useToast()
  const client = useQueryClient()
  const [sendOpen, setSendOpen] = useState(false)
  const customer = booking.customer
  const optOut = useMutation({
    mutationFn: (optedOut: boolean) => setCustomerOptOut(customer!.id, optedOut),
    onSuccess: (response) => {
      void client.invalidateQueries({ queryKey: ['booking', booking.id] })
      toast(t(response.data.whatsapp_opted_out ? 'notifications.optedOutToast' : 'notifications.optedInToast'))
    },
  })

  return (
    <Card>
      <CardTitle title="WhatsApp, email & SMS" />
      {!booking.notifications_number_published ? (
        <p role="note" className="m-0 rounded-10 border border-orange-line bg-orange-tint px-3 py-2.5 text-12 leading-1.55 text-orange-ink">
          {t('notifications.onHold')}
        </p>
      ) : null}
      {customer?.whatsapp_opted_out ? (
        <p role="note" className="m-0 rounded-10 bg-app-surface-2 px-3 py-2.5 text-12 leading-1.55 text-app-muted">
          {t('notifications.customerOptedOut')}
        </p>
      ) : null}
      {booking.notification_groups.length === 0 ? <p className="m-0 text-13 text-app-muted">{t('notifications.noMessages')}</p> : <ChannelGroups groups={booking.notification_groups} />}
      {optOut.error ? <ErrorNotice error={optOut.error} /> : null}
      <div className="flex flex-wrap items-center justify-between gap-2.5">
        {booking.actions.toggle_whatsapp_opt_out && customer ? (
          <Switch label={t('notifications.customerWhatsApp')} hint={t('notifications.customerWhatsAppHint')} checked={!customer.whatsapp_opted_out} onChange={(on) => !optOut.isPending && optOut.mutate(!on)} />
        ) : (
          <span />
        )}
        {booking.actions.send_whatsapp && booking.notifications_number_published ? (
          <button type="button" className={buttonClass('success')} onClick={() => setSendOpen(true)}>
            ✆ {t('notifications.sendWhatsApp')}
          </button>
        ) : null}
      </div>
      {customer ? <SendWhatsAppDialog booking={booking} open={sendOpen} onClose={() => setSendOpen(false)} /> : null}
    </Card>
  )
}

/** The number is the one on the booking — there is no field for it. What the customer will see is previewed below. */
function SendWhatsAppDialog({ booking, open, onClose }: { booking: BookingDetail; open: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const { digits, number } = useFormat()
  const toast = useToast()
  const client = useQueryClient()
  const [text, setText] = useState('')
  const [attach, setAttach] = useState(false)
  const invoice = booking.invoices.find((row) => row.status === 'issued') ?? null
  const send = useMutation({
    mutationFn: () => sendBookingWhatsApp({ booking_id: booking.id, text: text.trim(), attach_invoice: attach && invoice !== null }),
    onSuccess: (response) => {
      void client.invalidateQueries({ queryKey: ['booking', booking.id] })
      toast(t(response.data.status === 'pending' ? 'notifications.queued' : 'notifications.sent'))
      setText('')
      setAttach(false)
      onClose()
    },
  })
  const fieldError = send.error instanceof ApiError && send.error.status === 422 ? send.error : null
  const valid = text.trim().length >= 2 && text.length <= 1000

  return (
    <Dialog open={open} onClose={onClose} title={t('notifications.sendWhatsApp')}>
      <div className="flex flex-col gap-0.5 rounded-10 bg-app-surface-2 px-3 py-2.5 text-13">
        <span className="text-12 text-app-muted">{t('notifications.to')}</span>
        <strong>
          {booking.customer?.name} · <span className="font-display">{digits((booking.customer?.phone ?? '').replace(/^88/, ''))}</span>
        </strong>
        <span className="text-12 text-app-muted">{t('notifications.numberFromRecord')}</span>
      </div>
      <TextArea
        label={t('notifications.message')}
        value={text}
        onChange={setText}
        rows={5}
        maxLength={1000}
        error={fieldError?.field('text')}
        hint={`${number(text.length)} / ${number(1000)}`}
      />
      {invoice ? (
        <label className="flex cursor-pointer items-center gap-2.5 text-13">
          <input type="checkbox" className="size-4 accent-blue" checked={attach} onChange={(event) => setAttach(event.target.checked)} />
          {t('notifications.attachInvoice', { number: invoice.invoice_number })}
        </label>
      ) : null}
      <div className="flex flex-col gap-1.5">
        <span className="font-display text-11 tracking-eyebrow text-app-muted uppercase">{t('notifications.preview')}</span>
        <p className="m-0 max-w-bubble self-start rounded-12 rounded-tl-3 border border-green-line bg-whatsapp-tint px-3 py-2.5 text-14 leading-1.55 break-words whitespace-pre-wrap" data-testid="whatsapp-preview">
          <strong>{booking.notifications_sender_line}</strong>
          {'\n'}
          {text.trim() || <span className="text-app-muted">{t('notifications.previewEmpty')}</span>}
          {attach && invoice ? `\n📎 ${invoice.invoice_number}.pdf` : ''}
        </p>
      </div>
      {send.error && !fieldError ? <ErrorNotice error={send.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button type="button" className={buttonClass('success')} disabled={!valid || send.isPending} onClick={() => send.mutate()}>
          {send.isPending ? t('common.working') : t('notifications.send')}
        </button>
      </div>
    </Dialog>
  )
}

function AttemptsCard({ booking }: { booking: BookingDetail }) {
  const { t } = useTranslation()
  const { bdt, date } = useFormat()
  if (booking.payment_attempts.length === 0) return null

  const tone = (status: string) => (status === 'settled' ? 'green' : status === 'needs_review' ? 'red' : status === 'redirected' || status === 'initiated' ? 'blue' : 'slate')
  return (
    <Card>
      <CardTitle title="Online payment attempts" />
      <ul className="m-0 flex list-none flex-col p-0">
        {booking.payment_attempts.map((attempt) => (
          <li key={attempt.id} className="flex items-start justify-between gap-3 border-b border-app-line py-2 text-12 last:border-b-0">
            <span className="flex min-w-0 flex-col gap-0.5">
              <span className="font-display">{attempt.tran_id}</span>
              <span className="text-app-muted">
                {date(attempt.created_at)}
                {attempt.card_type ? ` · ${attempt.card_type}` : ''}
                {attempt.failure_reason ? ` · ${t(`bookings.attemptReason.${attempt.failure_reason}`, { defaultValue: attempt.failure_reason })}` : ''}
              </span>
            </span>
            <span className="flex shrink-0 flex-col items-end gap-1">
              <Badge tone={tone(attempt.status)}>{t(`bookings.attempt.${attempt.status}`, { defaultValue: attempt.status })}</Badge>
              <span className="font-display">{bdt(attempt.amount)}</span>
            </span>
          </li>
        ))}
      </ul>
    </Card>
  )
}

function ReasonDialog(props: { open: boolean; title: string; note?: string; label: string; confirmLabel: string; pending: boolean; error: unknown; onClose: () => void; onSubmit: (reason: string) => void }) {
  const { t } = useTranslation()
  const [reason, setReason] = useState('')
  const valid = reason.trim().length >= 3

  return (
    <Dialog open={props.open} onClose={props.onClose} title={props.title}>
      {props.note ? <p className="m-0 text-13 leading-1.6 text-app-muted">{props.note}</p> : null}
      <TextArea label={props.label} value={reason} onChange={setReason} rows={3} />
      {props.error ? <ErrorNotice error={props.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={props.onClose}>
          {t('common.cancel')}
        </button>
        <button type="button" className={buttonClass('danger')} disabled={!valid || props.pending} onClick={() => props.onSubmit(reason.trim())}>
          {props.confirmLabel}
        </button>
      </div>
    </Dialog>
  )
}
