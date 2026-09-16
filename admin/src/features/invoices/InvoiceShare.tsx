import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { TextArea, TextInput } from '../../components/ui/fields'
import { ApiError } from '../../lib/api/client'
import { smsParts } from '../../lib/smsParts'
import { useFormat } from '../../lib/useFormat'
import { invoiceActions, shareLink, useInvoiceAction, type InvoiceRow } from './api'

type Channel = 'sms' | 'whatsapp' | 'email' | 'link'

/**
 * Sharing an invoice and chasing what is owed (docs/phase-9-accounts.md §5). Two ways out:
 *
 * · the link — the customer's own copy, copied or handed to WhatsApp on the staff member's own device;
 * · SMS and email — written here and sent by the system, so the Notifications log shows whether they arrived.
 *
 * The chooser comes first, as it does on the old system, so nothing is sent by a single stray click.
 */
export function InvoiceShare({ invoice, purpose, onClose }: { invoice: InvoiceRow; purpose: 'share' | 'remind'; onClose: () => void }) {
  const { t } = useTranslation()
  const [channel, setChannel] = useState<Channel | null>(null)

  if (channel === 'sms' || channel === 'email') {
    return <Composer invoice={invoice} channel={channel} onClose={onClose} />
  }
  if (channel === 'link' || channel === 'whatsapp') {
    return <ShareLink invoice={invoice} openWhatsApp={channel === 'whatsapp'} onClose={onClose} />
  }

  return (
    <Dialog open onClose={onClose} title={t(purpose === 'share' ? 'invoices.shareTitle' : 'invoices.remindTitle')}>
      <div className="flex flex-wrap justify-center gap-3 py-2">
        {(['sms', 'whatsapp', 'email'] as const).map((option) => (
          <button key={option} type="button" className={buttonClass('primary', 'md', 'min-w-28')} onClick={() => setChannel(option)}>
            {t(`invoices.channels.${option}`)}
          </button>
        ))}
      </div>
      <button type="button" className={buttonClass('outline', 'sm', 'self-center')} onClick={() => setChannel('link')}>
        {t('invoices.justTheLink')}
      </button>
    </Dialog>
  )
}

/** The customer's own copy. Nothing is sent from here: the link is copied, or handed to WhatsApp on this device. */
function ShareLink({ invoice, openWhatsApp, onClose }: { invoice: InvoiceRow; openWhatsApp: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const link = shareLink(invoice.number)
  const message = t('invoices.reminderMessage', { number: invoice.number ?? '', amount: bdt(invoice.due) })
  const whatsapp = `https://wa.me/${(invoice.customer?.phone ?? '').replace(/\D/g, '')}?text=${encodeURIComponent(`${message} ${link}`)}`

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(link)
      toast(t('invoices.linkCopied'))
    } catch {
      toast(link)
    }
  }

  return (
    <Dialog open onClose={onClose} title={t('invoices.shareTitle')}>
      <p className="m-0 text-13 text-app-muted">{t('invoices.shareNote')}</p>
      <TextInput label={t('invoices.shareLink')} value={link} onChange={() => undefined} readOnly />
      <div className="flex flex-wrap items-center justify-between gap-2">
        <a href={link} target="_blank" rel="noopener noreferrer" className="text-13 font-semibold">
          {t('invoices.previewLink')}
        </a>
        {openWhatsApp && invoice.customer?.phone ? (
          <a href={whatsapp} target="_blank" rel="noopener noreferrer" className={buttonClass('success', 'sm')}>
            {t('invoices.sendOnWhatsApp')}
          </a>
        ) : null}
      </div>
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.close')}
        </button>
        <button type="button" className={buttonClass('primary')} onClick={() => void copy()}>
          {t('invoices.copyLink')}
        </button>
      </div>
    </Dialog>
  )
}

/** The SMS or email the system sends, with the wording already written and the staff member free to change it. */
function Composer({ invoice, channel, onClose }: { invoice: InvoiceRow; channel: 'sms' | 'email'; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const [text, setText] = useState(t('invoices.reminderMessage', { number: invoice.number ?? '', amount: bdt(invoice.due) }))
  const [subject, setSubject] = useState(t('invoices.reminderSubject', { number: invoice.number ?? '' }))
  const [email, setEmail] = useState(invoice.customer?.email ?? '')

  const send = useInvoiceAction(invoiceActions.remind(invoice.id))
  const fieldError = (name: string) => (send.error instanceof ApiError ? send.error.field(name) : undefined)
  const parts = smsParts(text)
  const ready = text.trim().length >= 5 && (channel === 'sms' || /.+@.+\..+/.test(email))

  return (
    <Dialog open onClose={onClose} title={t(channel === 'sms' ? 'invoices.smsTitle' : 'invoices.mailTitle')}>
      {channel === 'email' ? (
        <>
          <TextInput label={t('invoices.mailTo')} type="email" value={email} onChange={setEmail} error={fieldError('email')} />
          <TextInput label={t('invoices.mailSubject')} value={subject} onChange={setSubject} error={fieldError('subject')} />
        </>
      ) : null}

      <TextArea label={t('invoices.messageLabel')} value={text} onChange={setText} rows={4} error={fieldError('text')} />
      {channel === 'sms' ? (
        <p className="m-0 font-display text-12 text-app-muted">{t('invoices.smsParts', { characters: parts.units, parts: parts.parts, perPart: parts.perPart })}</p>
      ) : null}

      {send.error && !(send.error instanceof ApiError && send.error.status === 422) ? <ErrorNotice error={send.error} /> : null}

      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.close')}
        </button>
        <button
          type="button"
          className={buttonClass('primary')}
          disabled={!ready || send.isPending}
          onClick={() =>
            send.mutate(
              channel === 'sms' ? { channels: ['sms'], text: text.trim() } : { channels: ['email'], text: text.trim(), subject: subject.trim(), email: email.trim() },
              {
                onSuccess: () => {
                  toast(t(channel === 'sms' ? 'invoices.smsSent' : 'invoices.mailSent'))
                  onClose()
                },
              },
            )
          }
        >
          {send.isPending ? t('common.saving') : t(channel === 'sms' ? 'invoices.sendSms' : 'invoices.sendMail')}
        </button>
      </div>
    </Dialog>
  )
}
