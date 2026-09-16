import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { NumberInput, TextArea, TextInput } from '../../components/ui/fields'
import { Badge, Loading } from '../../components/ui/layout'
import { api, ApiError } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'
import { useFormat } from '../../lib/useFormat'
import { invoiceActions, invoiceTotals, lineTotals, useInvoice, useInvoiceAction, type InvoiceDetail, type InvoiceInput } from './api'

type Line = InvoiceInput['lines'][number]
type CustomerHit = { id: number; name: string; phone: string }

const blankLine = (): Line => ({ title: '', detail: null, quantity: 1, unit_price: 0, discount_amount: 0, vat_rate: 0 })

/**
 * Writing an invoice (docs/phase-9-accounts.md §5): who it is for, its lines with their own discount and VAT, a
 * discount on the whole invoice, when it is due, and the words printed under it. A draft can be changed; once issued
 * the figures are frozen, and the screen only shows them with the share link and the PDF.
 */
export function InvoiceEditor({ id, onClose }: { id: number | null; onClose: () => void }) {
  const { t } = useTranslation()
  const invoice = useInvoice(id)

  if (id !== null && invoice.isPending) {
    return (
      <Dialog open onClose={onClose} title={t('invoices.loadingTitle')} wide>
        <Loading />
      </Dialog>
    )
  }
  if (id !== null && invoice.isError) {
    return (
      <Dialog open onClose={onClose} title={t('invoices.loadingTitle')} wide>
        <ErrorNotice error={invoice.error} />
      </Dialog>
    )
  }

  return <Editor detail={invoice.data?.data ?? null} onClose={onClose} />
}

function Editor({ detail, onClose }: { detail: InvoiceDetail | null; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt, date } = useFormat()
  const toast = useToast()
  const issued = detail !== null && detail.status !== 'draft'

  const [customerId, setCustomerId] = useState<number | null>(detail?.customer_id ?? null)
  const [customerName, setCustomerName] = useState(detail?.billed_name ?? '')
  const [customerPhone, setCustomerPhone] = useState(detail?.customer?.phone ?? '')
  const [lookup, setLookup] = useState('')
  const [form, setForm] = useState({
    title: detail?.title ?? '',
    note: detail?.note ?? '',
    footer: detail?.footer ?? '',
    due_on: detail?.due_on ?? '',
    discount_label: detail?.discount_label ?? '',
    discount_amount: detail?.discount_amount ?? 0,
    vat_rate: detail?.vat_rate ?? 0,
  })
  const [lines, setLines] = useState<Line[]>(
    detail?.lines.map((line) => ({
      title: line.title,
      detail: line.detail,
      quantity: line.quantity,
      unit_price: line.unit_price,
      discount_amount: line.discount_amount,
      vat_rate: line.vat_rate,
    })) ?? [blankLine()],
  )

  const hits = useQuery({
    queryKey: ['search', lookup.trim()],
    queryFn: ({ signal }) => api.get<Data<{ customers?: CustomerHit[] }>>(`admin/search?q=${encodeURIComponent(lookup.trim())}`, signal).then((r) => r.data.customers ?? []),
    enabled: lookup.trim().length >= 2 && !issued,
  })

  const save = useInvoiceAction(detail ? invoiceActions.update(detail.id) : invoiceActions.create)
  // Issuing takes the id the save came back with: a new invoice has none until it is saved.
  const issue = useInvoiceAction((id: number) => invoiceActions.issue(id)())
  const fieldError = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)
  const totals = invoiceTotals(lines, form.discount_amount)
  // An invoice always belongs to someone: a customer from the list, or a name and number to make the record from.
  const hasParty = customerId !== null || (customerName.trim() !== '' && customerPhone.replace(/\D/g, '').length >= 11)
  const incomplete = !hasParty || !form.title.trim() || totals.total <= 0
  const setLine = (index: number, patch: Partial<Line>) => setLines((all) => all.map((line, i) => (i === index ? { ...line, ...patch } : line)))

  const payload = (): InvoiceInput => ({
    customer_id: customerId,
    // Nobody picked: the name and number written here become the customer this invoice is billed to.
    customer: customerId === null ? { name: customerName.trim(), phone: customerPhone.replace(/\D/g, '') } : null,
    title: form.title.trim(),
    note: form.note.trim() || null,
    footer: form.footer.trim() || null,
    due_on: form.due_on || null,
    discount_label: form.discount_label.trim() || null,
    discount_amount: form.discount_amount || 0,
    vat_rate: form.vat_rate || 0,
    lines: lines.filter((line) => line.title.trim()).map((line) => ({ ...line, title: line.title.trim(), detail: line.detail?.trim() || null })),
  })

  return (
    <Dialog open onClose={onClose} title={detail ? t('invoices.editTitle', { number: detail.number ?? t('invoices.draft') }) : t('invoices.createTitle')} wide>
      {issued && detail ? (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-12 border border-app-line px-3.5 py-2.5">
          <span className="flex flex-wrap items-center gap-2 text-13">
            <Badge tone={detail.status === 'void' ? 'slate' : detail.payment_status === 'paid' ? 'green' : 'orange'}>
              {detail.status === 'void' ? t('invoices.states.void') : t(`invoices.states.${detail.payment_status}`)}
            </Badge>
            <span className="text-app-muted">{t('invoices.issuedOn', { date: detail.issued_on ? date(detail.issued_on) : '—' })}</span>
          </span>
          <span className="flex gap-2">
            {detail.pdf_url ? (
              <a href={detail.pdf_url} target="_blank" rel="noopener noreferrer" className={buttonClass('outline', 'sm')}>
                {t('invoices.pdf')}
              </a>
            ) : null}
          </span>
        </div>
      ) : null}

      {/* Who it is for: someone already on file, or a name and number that make the record as the invoice is saved. */}
      {!issued ? (
        <>
          <TextInput label={t('invoices.findCustomer')} value={lookup} onChange={setLookup} placeholder={t('invoices.findCustomerHint')} />
          {hits.data && hits.data.length > 0 ? (
            <div className="flex flex-wrap gap-2" data-testid="customer-hits">
              {hits.data.slice(0, 6).map((hit) => (
                <button
                  key={hit.id}
                  type="button"
                  className={buttonClass(customerId === hit.id ? 'primary' : 'outline', 'sm')}
                  onClick={() => {
                    setCustomerId(hit.id)
                    setCustomerName(hit.name)
                    setCustomerPhone(hit.phone)
                    setLookup('')
                  }}
                >
                  {hit.name}
                </button>
              ))}
            </div>
          ) : null}
        </>
      ) : null}

      <div className="grid-auto-fit-200 grid gap-3">
        <TextInput
          label={t('invoices.customer')}
          value={customerName}
          onChange={setCustomerName}
          disabled={issued || customerId !== null}
          hint={issued || customerId !== null ? undefined : t('invoices.customerHint')}
          error={fieldError('customer.name')}
        />
        <TextInput
          label={t('invoices.customerPhone')}
          value={customerPhone}
          onChange={setCustomerPhone}
          disabled={issued || customerId !== null}
          hint={issued || customerId !== null ? undefined : t('invoices.customerPhoneHint')}
          error={fieldError('customer.phone')}
        />
        <TextInput label={t('invoices.dueOn')} type="date" value={form.due_on} onChange={(due_on) => setForm({ ...form, due_on })} disabled={issued} error={fieldError('due_on')} />
      </div>

      {!issued && customerId !== null ? (
        <button
          type="button"
          className={buttonClass('outline', 'sm', 'self-start')}
          onClick={() => {
            setCustomerId(null)
            setCustomerName('')
            setCustomerPhone('')
          }}
        >
          {t('invoices.changeCustomer')}
        </button>
      ) : null}

      <TextInput label={t('invoices.invoiceTitle')} value={form.title} onChange={(title) => setForm({ ...form, title })} disabled={issued} error={fieldError('title')} />

      <div className="flex flex-col gap-2" data-testid="invoice-lines">
        {lines.map((line, index) => {
          const { net, vat } = lineTotals(line)
          return (
            <div key={index} className="grid gap-2 sm:grid-cols-[minmax(0,2.2fr)_minmax(0,0.6fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,0.7fr)_auto]">
              <TextInput label={t('invoices.line.title')} value={line.title} onChange={(title) => setLine(index, { title })} disabled={issued} error={fieldError(`lines.${index}.title`)} />
              <NumberInput label={t('invoices.line.quantity')} value={line.quantity} onChange={(quantity) => setLine(index, { quantity: quantity ?? 1 })} disabled={issued} error={fieldError(`lines.${index}.quantity`)} />
              <NumberInput label={t('invoices.line.price')} value={line.unit_price} onChange={(unit_price) => setLine(index, { unit_price: unit_price ?? 0 })} disabled={issued} error={fieldError(`lines.${index}.unit_price`)} />
              <NumberInput label={t('invoices.line.discount')} value={line.discount_amount} onChange={(discount_amount) => setLine(index, { discount_amount: discount_amount ?? 0 })} disabled={issued} />
              <NumberInput label={t('invoices.line.vat')} value={line.vat_rate} onChange={(vat_rate) => setLine(index, { vat_rate: vat_rate ?? 0 })} disabled={issued} />
              <span className="flex items-end gap-1.5 pb-1">
                <span className="font-display text-13 whitespace-nowrap">{bdt(net + vat)}</span>
                {!issued && lines.length > 1 ? (
                  <button type="button" className={buttonClass('outline', 'sm', 'px-2 py-1 text-12')} onClick={() => setLines((all) => all.filter((_, i) => i !== index))}>
                    ✕
                  </button>
                ) : null}
              </span>
            </div>
          )
        })}
        {!issued ? (
          <button type="button" className={buttonClass('outline', 'sm', 'self-start')} onClick={() => setLines((all) => [...all, blankLine()])}>
            {t('invoices.addLine')}
          </button>
        ) : null}
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <div className="flex flex-col gap-3">
          <TextArea label={t('invoices.note')} value={form.note} onChange={(note) => setForm({ ...form, note })} rows={2} disabled={issued} />
          <TextArea label={t('invoices.footer')} value={form.footer} onChange={(footer) => setForm({ ...form, footer })} rows={2} disabled={issued} hint={t('invoices.footerHint')} />
        </div>
        <div className="flex flex-col gap-2 rounded-12 border border-app-line p-3.5 text-13" data-testid="invoice-totals">
          <Row label={t('invoices.subtotal')} value={bdt(totals.subtotal)} />
          <div className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2">
            <TextInput label={t('invoices.discountLabel')} value={form.discount_label} onChange={(discount_label) => setForm({ ...form, discount_label })} disabled={issued} />
            <NumberInput label={t('invoices.discount')} value={form.discount_amount} onChange={(discount_amount) => setForm({ ...form, discount_amount: discount_amount ?? 0 })} disabled={issued} />
          </div>
          <Row label={t('invoices.vat')} value={bdt(totals.vat)} />
          <div className="border-t border-app-line pt-2">
            <Row label={t('invoices.total')} value={bdt(totals.total)} strong />
          </div>
          {detail && issued ? (
            <>
              <Row label={t('invoices.paid')} value={bdt(detail.paid)} />
              <Row label={t('invoices.due')} value={bdt(detail.due)} strong />
            </>
          ) : null}
        </div>
      </div>

      {save.error && !(save.error instanceof ApiError && save.error.status === 422) ? <ErrorNotice error={save.error} /> : null}
      {issue.error ? <ErrorNotice error={issue.error} /> : null}

      <div className="flex flex-wrap justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {issued ? t('common.close') : t('common.cancel')}
        </button>
        {!issued ? (
          <>
            <button
              type="button"
              className={buttonClass('outline')}
              disabled={incomplete || save.isPending}
              onClick={() => save.mutate(payload(), { onSuccess: () => { toast(t('invoices.savedDraft')); onClose() } })}
            >
              {save.isPending ? t('common.saving') : t('invoices.saveDraft')}
            </button>
            <button
              type="button"
              className={buttonClass('primary')}
              disabled={incomplete || save.isPending || issue.isPending}
              onClick={() =>
                save.mutate(payload(), {
                  onSuccess: (response) =>
                    issue.mutate((response as { data: { id: number } }).data.id, {
                      onSuccess: () => {
                        toast(t('invoices.issued'))
                        onClose()
                      },
                    }),
                })
              }
            >
              {save.isPending || issue.isPending ? t('common.saving') : t('invoices.issue')}
            </button>
          </>
        ) : null}
      </div>
    </Dialog>
  )
}

function Row({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className={`flex items-baseline justify-between gap-3 ${strong ? 'font-bold' : ''}`}>
      <span className={strong ? '' : 'text-app-muted'}>{label}</span>
      <span className="font-display">{value}</span>
    </div>
  )
}
