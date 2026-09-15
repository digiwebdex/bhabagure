import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { TextArea } from '../../components/ui/fields'
import { Loading } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { ChannelGroups } from '../notifications/MessageList'
import { requestActions, useRequestAction, useRequestDetail, type QuoteRequest, type RequestDetail } from './api'
import type { RequestSpec } from './QuoteRequestsPage'

/** A request's details, every message about it with its delivery status, and the reply box. */
export function RequestDialog<T extends QuoteRequest>({ spec, id, startReplying, onClose }: { spec: RequestSpec<T>; id: number; startReplying: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const detail = useRequestDetail<T>(spec.kind, id)
  const row = detail.data?.data

  return (
    <Dialog open onClose={onClose} title={row ? t(`${spec.ns}.enquiryFrom`, { name: row.name }) : t('common.loading')} wide>
      {detail.isPending ? <Loading /> : detail.isError ? <ErrorNotice error={detail.error} /> : row ? <RequestBody spec={spec} row={row} startReplying={startReplying} onClose={onClose} /> : null}
    </Dialog>
  )
}

function RequestBody<T extends QuoteRequest>({ spec, row, startReplying, onClose }: { spec: RequestSpec<T>; row: RequestDetail<T>; startReplying: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const format = useFormat()
  const { dateTime, digits } = format
  const [replying, setReplying] = useState(startReplying && row.actions.reply)
  const facts: [string, string][] = [
    [t(`${spec.ns}.passenger`), row.name],
    [t('newBooking.phone'), digits(row.phone.replace(/^88/, ''))],
    [t('newBooking.email'), row.email ?? '—'],
    ...spec.facts(row, format, t),
    [t(`${spec.ns}.received`), dateTime(row.created_at)],
    [t(`${spec.ns}.language`), row.locale === 'en' ? 'English' : 'বাংলা'],
  ]

  return (
    <>
      <dl className="m-0 grid-auto-fit-half-160 grid gap-x-4 gap-y-2.5 text-14">
        {facts.map(([label, value]) => (
          <div key={label} className="flex flex-col">
            <dt className="text-12 text-app-muted">{label}</dt>
            <dd className="m-0 font-medium whitespace-pre-line">{value}</dd>
          </div>
        ))}
      </dl>
      {row.quoted_at ? <p className="m-0 text-13 text-green">{t(`${spec.ns}.quotedBy`, { name: row.quoted_by?.name ?? '—', when: dateTime(row.quoted_at) })}</p> : null}

      {row.notification_groups.length > 0 ? (
        <section className="flex flex-col gap-1" aria-label={t('requests.messages')}>
          <h3 className="m-0 font-display text-11 tracking-eyebrow text-app-muted uppercase">{t('requests.messages')}</h3>
          <ChannelGroups groups={row.notification_groups} />
        </section>
      ) : null}

      {replying ? (
        <ReplyForm spec={spec} row={row} onDone={() => setReplying(false)} />
      ) : (
        <div className="flex justify-end gap-2">
          <button type="button" className={buttonClass('outline')} onClick={onClose}>
            {t('common.close')}
          </button>
          {row.actions.reply ? (
            <button type="button" className={buttonClass('primary')} onClick={() => setReplying(true)}>
              {t('requests.reply')}
            </button>
          ) : null}
        </div>
      )}
    </>
  )
}

function ReplyForm<T extends QuoteRequest>({ spec, row, onDone }: { spec: RequestSpec<T>; row: RequestDetail<T>; onDone: () => void }) {
  const { t } = useTranslation()
  const { digits, number } = useFormat()
  const toast = useToast()
  const [text, setText] = useState('')
  const [markQuoted, setMarkQuoted] = useState(row.status === 'new')
  const send = useRequestAction(spec.kind, requestActions(spec.kind).reply)
  const fieldError = send.error instanceof ApiError && send.error.status === 422 ? send.error : null
  const valid = text.trim().length >= 2 && text.length <= 1000
  const whatsAppHeld = !row.notifications_number_published || row.customer_opted_out

  return (
    <form
      className="flex flex-col gap-3 rounded-12 border border-app-line p-3.5"
      onSubmit={(event) => {
        event.preventDefault()
        if (!valid) return
        send.mutate({ id: row.id, text: text.trim(), markQuoted: markQuoted && row.status === 'new' }, {
          onSuccess: () => {
            toast(t('requests.sent', { name: row.name }))
            setText('')
            onDone()
          },
        })
      }}
    >
      <div className="flex flex-col gap-0.5 text-13">
        <span className="text-12 text-app-muted">{t('notifications.to')}</span>
        <strong>
          {row.name} · <span className="font-display">{digits(row.phone.replace(/^88/, ''))}</span>
          {row.email ? ` · ${row.email}` : ''}
        </strong>
        <span className="text-12 text-app-muted">{t('requests.fromForm')}</span>
      </div>
      {whatsAppHeld ? (
        <p role="note" className="m-0 rounded-10 bg-orange-tint px-3 py-2 text-13 text-amber">
          {row.customer_opted_out ? t('requests.optedOut') : t('requests.whatsAppOff')}
          {row.email ? '' : ` ${t('requests.noEmailEither')}`}
        </p>
      ) : null}
      <TextArea label={t('notifications.message')} value={text} onChange={setText} rows={5} maxLength={1000} error={fieldError?.field('text')} hint={`${number(text.length)} / ${number(1000)}`} />
      <div className="flex flex-col gap-1.5">
        <span className="font-display text-11 tracking-eyebrow text-app-muted uppercase">{t('notifications.preview')}</span>
        <p className="m-0 max-w-bubble self-start rounded-12 rounded-tl-3 border border-green-line bg-whatsapp-tint px-3 py-2.5 text-14 leading-1.55 break-words whitespace-pre-wrap" data-testid="whatsapp-preview">
          <strong>{row.notifications_sender_line}</strong>
          {'\n'}
          {/* The template around the reply, filled for this request; {{reply}} is where the typed text goes. */}
          {row.reply_template.split('{{reply}}').map((part, index) => (
            <span key={index}>
              {index > 0 ? text.trim() || <span className="text-app-muted">{t('notifications.previewEmpty')}</span> : null}
              {part}
            </span>
          ))}
        </p>
      </div>
      {row.status === 'new' ? (
        <label className="flex cursor-pointer items-center gap-2.5 text-13">
          <input type="checkbox" className="size-4 accent-blue" checked={markQuoted} onChange={(event) => setMarkQuoted(event.target.checked)} />
          {t('requests.markQuotedToo')}
        </label>
      ) : null}
      {send.error && !fieldError ? <ErrorNotice error={send.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onDone}>
          {t('common.cancel')}
        </button>
        <button type="submit" className={buttonClass('success')} disabled={!valid || send.isPending}>
          {send.isPending ? t('common.working') : t('requests.send')}
        </button>
      </div>
    </form>
  )
}
