import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { Badge, Loading } from '../../components/ui/layout'
import { fetchDocument } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { printPath, useInvoice, type PrintSize } from './api'

/**
 * One invoice, read the way the customer's copy reads (docs/phase-9-accounts.md §5): who it is for, what it is for,
 * what was paid and when, and what is still owed — with the printed copies a click away.
 */
export function InvoiceDetails({ id, onClose }: { id: number; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt, date } = useFormat()
  const toast = useToast()
  const invoice = useInvoice(id)

  const print = async (size: PrintSize) => {
    try {
      const blob = await fetchDocument(printPath(id, size))
      window.open(URL.createObjectURL(blob), '_blank', 'noopener')
    } catch {
      toast(t('invoices.pdfFailed'), 'error')
    }
  }

  if (invoice.isPending || invoice.isError) {
    return (
      <Dialog open onClose={onClose} title={t('invoices.detailsTitle')} wide>
        {invoice.isPending ? <Loading /> : <ErrorNotice error={invoice.error} />}
      </Dialog>
    )
  }

  const data = invoice.data.data
  const paid = (data.payments ?? []).filter((payment) => !payment.reversed)

  return (
    <Dialog open onClose={onClose} title={t('invoices.detailsTitle')} wide>
      <div className="flex flex-col gap-4" data-testid="invoice-details">
        {/* Who wrote it, when, and the two copies staff print most. */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <span className="flex flex-col gap-0.5">
            <strong className="font-display text-17">{data.number ?? t('invoices.draft')}</strong>
            {data.created_by ? <span className="text-12 text-app-muted">{t('invoices.createdBy', { name: data.created_by })}</span> : null}
            {data.updated_by ? <span className="text-12 text-app-muted">{t('invoices.updatedBy', { name: data.updated_by })}</span> : null}
          </span>
          <span className="flex flex-wrap gap-2">
            <button type="button" className={buttonClass('outline', 'sm')} onClick={() => void print('a4')}>
              {t('invoices.printPdf')}
            </button>
            <button type="button" className={buttonClass('outline', 'sm')} onClick={() => void print('slip')}>
              {t('invoices.printSlip')}
            </button>
          </span>
        </div>

        <div className="flex flex-wrap items-center justify-between gap-3 rounded-10 bg-linear-135/srgb from-blue to-blue-deep px-3.5 py-2 text-13 font-semibold text-white">
          <span>{t('invoices.invoiceDate', { date: data.issued_on ? date(data.issued_on) : '—' })}</span>
          <span>{t('invoices.dueDate', { date: data.due_on ? date(data.due_on) : '—' })}</span>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <span className="flex flex-col gap-0.5 text-13">
            <span className="text-12 text-app-muted">{t('invoices.invoiceTo')}</span>
            <strong>{data.customer?.name ?? data.billed_name ?? '—'}</strong>
            {data.customer?.phone ? <span className="font-display text-app-muted">{data.customer.phone}</span> : null}
            {data.po_number ? <span className="text-app-muted">{t('invoices.poNumber')}: {data.po_number}</span> : null}
          </span>
          {data.company ? (
            <span className="flex flex-col gap-0.5 text-13 sm:text-right">
              <strong>{data.company.name}</strong>
              {data.company.address ? <span className="text-app-muted">{data.company.address}</span> : null}
              {data.company.email ? <span className="text-app-muted">{data.company.email}</span> : null}
              {data.company.phone ? <span className="font-display text-app-muted">{data.company.phone}</span> : null}
            </span>
          ) : null}
        </div>

        <table className="w-full border-collapse text-13">
          <thead>
            <tr className="border-b border-app-line text-12 text-app-muted">
              <th className="p-2 text-left font-semibold">{t('invoices.line.title')}</th>
              <th className="p-2 text-center font-semibold">{t('invoices.line.quantity')}</th>
              <th className="p-2 text-right font-semibold">{t('invoices.line.price')}</th>
              <th className="p-2 text-right font-semibold">{t('invoices.vat')}</th>
              <th className="p-2 text-right font-semibold">{t('invoices.total')}</th>
            </tr>
          </thead>
          <tbody>
            {data.lines.map((line, index) => (
              <tr key={line.id ?? index} className="border-b border-app-line">
                <td className="p-2">
                  {line.title}
                  {line.detail ? <span className="block text-12 text-app-muted">{line.detail}</span> : null}
                </td>
                <td className="p-2 text-center font-display">{line.quantity}</td>
                <td className="p-2 text-right font-display">{bdt(line.unit_price)}</td>
                <td className="p-2 text-right font-display">{bdt(line.vat_amount)}</td>
                <td className="p-2 text-right font-display">{bdt(line.line_total)}</td>
              </tr>
            ))}
          </tbody>
        </table>

        <div className="flex flex-col gap-1 self-end text-13 sm:w-72">
          <Row label={t('invoices.subtotal')} value={bdt(data.subtotal)} />
          {data.discount_amount > 0 ? <Row label={data.discount_label || t('invoices.discount')} value={`− ${bdt(data.discount_amount)}`} /> : null}
          {data.vat_amount > 0 ? <Row label={t('invoices.vat')} value={bdt(data.vat_amount)} /> : null}
          {data.delivery_charge > 0 ? <Row label={t('invoices.deliveryCharge')} value={bdt(data.delivery_charge)} /> : null}
          <div className="border-t border-app-line pt-1">
            <Row label={t('invoices.total')} value={bdt(data.total)} strong />
          </div>
          <Row label={t('invoices.paid')} value={bdt(data.paid)} />
          <Row label={t('invoices.due')} value={bdt(data.due)} strong />
        </div>

        {data.note || data.footer ? (
          <p className="m-0 border-t border-app-line pt-3 text-13 whitespace-pre-line text-app-muted">{[data.note, data.footer].filter(Boolean).join('\n')}</p>
        ) : null}

        {/* Every payment against it, so a customer's question is answered from this one screen. */}
        <div className="flex flex-col gap-2">
          <strong className="text-14">{t('invoices.paymentDetails')}</strong>
          {paid.length === 0 ? (
            <span className="text-13 text-app-muted">{t('invoices.noPayments')}</span>
          ) : (
            <table className="w-full border-collapse text-13" data-testid="invoice-payments">
              <thead>
                <tr className="border-b border-app-line text-12 text-app-muted">
                  <th className="p-2 text-left font-semibold">{t('invoices.paymentDate')}</th>
                  <th className="p-2 text-left font-semibold">{t('bookings.method')}</th>
                  <th className="p-2 text-right font-semibold">{t('invoices.paymentAmount')}</th>
                  <th className="p-2 text-left font-semibold">{t('invoices.paymentReference')}</th>
                </tr>
              </thead>
              <tbody>
                {paid.map((payment) => (
                  <tr key={payment.id} className="border-b border-app-line">
                    <td className="p-2">{payment.date ? date(payment.date) : '—'}</td>
                    <td className="p-2">{t(`bookings.methods.${payment.method}`, { defaultValue: payment.method })}</td>
                    <td className="p-2 text-right font-display">{bdt(payment.amount)}</td>
                    <td className="p-2 text-app-muted">{payment.note ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <div className="flex flex-wrap items-center justify-between gap-3">
          <Badge tone={data.status === 'void' ? 'slate' : data.status === 'draft' ? 'blue' : data.payment_status === 'paid' ? 'green' : data.overdue ? 'red' : 'orange'}>
            {data.status === 'issued' ? t(`invoices.states.${data.payment_status}`) : t(`invoices.states.${data.status}`)}
          </Badge>
          <button type="button" className={buttonClass('outline')} onClick={onClose}>
            {t('common.close')}
          </button>
        </div>
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
