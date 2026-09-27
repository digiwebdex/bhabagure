import { useEffect, useRef, useState, type KeyboardEvent } from 'react'
import { useTranslation } from 'react-i18next'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { ErrorNotice } from '../../components/ui/feedback'
import { Loading } from '../../components/ui/layout'
import { ApiError, fetchDocument } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { olderMessages, useAssign, useAttachmentUrl, useCannedReplies, useConversation, useMarkRead, useReply, useSetStatus, type ConversationDetail, type InboxMessage } from './api'
import { CustomerPanel } from './CustomerPanel'
import { PackagePicker } from './PackagePicker'
import { Avatar, ChannelBadge } from './parts'

const ACCEPT = '.jpg,.jpeg,.png,.webp,.pdf,.mp3,.ogg,.m4a,.aac,.mp4'

/** One chat: the thread, the reply box with canned replies and attachments, and the customer beside it. */
export function ConversationPane({ id, onBack }: { id: number; onBack: () => void }) {
  const query = useConversation(id)
  const markRead = useMarkRead(id)
  const [older, setOlder] = useState<InboxMessage[]>([])
  const [hasOlder, setHasOlder] = useState<boolean | null>(null)
  const unread = query.data?.data.unread_count ?? 0

  // Opening a chat with unread messages reads it (and sends the customer blue ticks on WhatsApp).
  useEffect(() => {
    if (unread > 0 && !markRead.isPending) markRead.mutate(undefined)
    // eslint-disable-next-line react-hooks/exhaustive-deps -- only when new messages arrive
  }, [unread])

  if (query.isPending) return <Loading />
  if (query.isError) return <ErrorNotice error={query.error} />
  const conversation = query.data.data
  const messages = [...older, ...conversation.messages.filter((m) => !older.some((o) => o.id === m.id))]

  const loadOlder = async () => {
    const first = messages[0]
    if (!first) return
    const page = await olderMessages(id, first.id)
    setOlder((current) => [...page.data.messages, ...current])
    setHasOlder(page.data.has_older)
  }

  return (
    <div className="grid h-full min-h-0 gap-3 min-[1180px]:grid-cols-[minmax(0,1fr)_17rem] min-[1180px]:grid-rows-1">
      <div className="flex min-h-[70dvh] flex-col overflow-hidden rounded-14 border border-app-line bg-app-surface min-[1180px]:min-h-0">
        <ChatHeader conversation={conversation} onBack={onBack} />
        <Thread messages={messages} hasOlder={hasOlder ?? conversation.has_older} onOlder={loadOlder} />
        <Composer conversation={conversation} />
      </div>
      <CustomerPanel conversation={conversation} />
    </div>
  )
}

function ChatHeader({ conversation, onBack }: { conversation: ConversationDetail; onBack: () => void }) {
  const { t } = useTranslation()
  const { digits } = useFormat()
  const { session, can } = useAuth()
  const me = session.state === 'signed-in' ? session.staff : null
  const assign = useAssign(conversation.id)
  const setStatus = useSetStatus(conversation.id)
  const name = conversation.name ?? (conversation.phone ? digits(`+${conversation.phone}`) : t('inbox.unknown'))

  return (
    <header className="flex flex-wrap items-center gap-3 border-b border-app-line px-4 py-3">
      <button type="button" onClick={onBack} className={buttonClass('ghost', 'sm', 'lg:hidden')} aria-label={t('inbox.back')}>
        ←
      </button>
      <Avatar name={name} channel={conversation.channel} size="lg" />
      <div className="flex min-w-0 flex-1 flex-col">
        <h2 className="m-0 truncate text-16 font-bold">{name}</h2>
        <span className="flex flex-wrap items-center gap-1.5 text-12 text-app-muted">
          <ChannelBadge channel={conversation.channel} />
          {conversation.phone ? <span className="font-display">{digits(`+${conversation.phone}`)}</span> : null}
        </span>
      </div>
      <div className="flex flex-wrap items-center gap-2 max-md:basis-full">
        {conversation.assignees ? (
          <select
            aria-label={t('inbox.assign')}
            className={`${controlClass()} max-w-48 py-1.5 text-13`}
            value={conversation.assignee?.id ?? ''}
            onChange={(event) => assign.mutate(event.target.value ? Number(event.target.value) : null)}
          >
            <option value="">{t('inbox.unassigned')}</option>
            {conversation.assignees.map((staff) => (
              <option key={staff.id} value={staff.id}>
                {staff.name}
              </option>
            ))}
          </select>
        ) : can('inbox.reply') ? (
          conversation.assignee?.id === me?.id ? (
            <button type="button" className={buttonClass('outline', 'sm')} onClick={() => assign.mutate(null)}>
              {t('inbox.release')}
            </button>
          ) : (
            <button type="button" className={buttonClass('outline', 'sm')} onClick={() => assign.mutate(me?.id ?? null)}>
              {conversation.assignee ? t('inbox.takeFrom', { name: conversation.assignee.name }) : t('inbox.take')}
            </button>
          )
        ) : null}
        {can('inbox.reply') ? (
          <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setStatus.mutate(conversation.status === 'open' ? 'close' : 'reopen')}>
            {conversation.status === 'open' ? t('inbox.close') : t('inbox.reopen')}
          </button>
        ) : null}
      </div>
      {assign.error || setStatus.error ? (
        <div className="basis-full">
          <ErrorNotice error={assign.error ?? setStatus.error} />
        </div>
      ) : null}
    </header>
  )
}

function Thread({ messages, hasOlder, onOlder }: { messages: InboxMessage[]; hasOlder: boolean; onOlder: () => void }) {
  const { t } = useTranslation()
  const { date } = useFormat()
  const bottom = useRef<HTMLDivElement>(null)
  const last = messages.at(-1)?.id

  // Follow the conversation as it grows.
  useEffect(() => {
    bottom.current?.scrollIntoView({ block: 'end' })
  }, [last])

  return (
    <div className="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto bg-app-surface-2 px-4 py-3" data-testid="thread" aria-live="polite">
      {hasOlder ? (
        <button type="button" className={buttonClass('ghost', 'sm', 'self-center')} onClick={onOlder}>
          {t('inbox.older')}
        </button>
      ) : null}
      {messages.length === 0 ? <p className="m-auto text-13 text-app-muted">{t('inbox.noMessages')}</p> : null}
      {messages.map((message, index) => {
        // A day line (in Dhaka time) before the first message of each day.
        const day = date(message.sent_at)
        const divider = index === 0 || date(messages[index - 1].sent_at) !== day
        return (
          <div key={message.id} className="flex flex-col gap-2">
            {divider ? <span className="self-center rounded-pill bg-app-surface px-2.5 py-0.5 text-11 text-app-muted">{day}</span> : null}
            <Bubble message={message} />
          </div>
        )
      })}
      <div ref={bottom} />
    </div>
  )
}

function Bubble({ message }: { message: InboxMessage }) {
  const { t } = useTranslation()
  const { dateTime } = useFormat()
  const out = message.direction === 'out'
  const time = dateTime(message.sent_at).split(', ').pop()
  const who = message.origin === 'staff' ? message.staff?.name : message.origin === 'phone' ? t('inbox.fromPhone') : message.origin === 'automated' ? t('inbox.automated') : null

  return (
    <div className={`flex max-w-[82%] flex-col gap-1 ${out ? 'self-end items-end' : 'self-start items-start'}`} data-testid="bubble" data-direction={message.direction}>
      <div className={`flex flex-col gap-1.5 rounded-14 px-3.5 py-2.5 text-14 leading-1.5 shadow-hairline ${out ? 'rounded-br-4 bg-green-tint text-app-text' : 'rounded-bl-4 bg-app-surface text-app-text'}`}>
        {message.attachment ? <Attachment message={message} /> : null}
        {message.body ? <p className="m-0 break-words whitespace-pre-wrap">{message.body}</p> : null}
      </div>
      <span className="flex items-center gap-1.5 text-11 text-app-muted">
        {who ? <span>{who} ·</span> : null}
        <time dateTime={message.sent_at}>{time}</time>
        {out ? <Ticks status={message.status} /> : null}
      </span>
      {message.status === 'failed' ? (
        <span role="alert" className="text-11 font-semibold text-red">
          {t('inbox.failed', { reason: t(`inbox.errors.${message.error}`, { defaultValue: message.error ?? '' }) })}
        </span>
      ) : null}
    </div>
  )
}

function Ticks({ status }: { status: InboxMessage['status'] }) {
  const { t } = useTranslation()
  const mark = status === 'pending' ? '🕓' : status === 'sent' ? '✓' : status === 'delivered' ? '✓✓' : status === 'read' ? '✓✓' : status === 'failed' ? '!' : ''
  return (
    <span className={status === 'read' ? 'font-bold text-blue' : status === 'failed' ? 'font-bold text-red' : ''} title={t(`inbox.status.${status}`)} aria-label={t(`inbox.status.${status}`)}>
      {mark}
    </span>
  )
}

function Attachment({ message }: { message: InboxMessage }) {
  const { t } = useTranslation()
  const attachment = message.attachment!
  const visual = attachment.kind === 'image' || attachment.kind === 'sticker' || attachment.kind === 'audio' || attachment.kind === 'video'
  const url = useAttachmentUrl(message, visual)
  const [opening, setOpening] = useState(false)

  if (!attachment.ready) {
    return <span className="text-13 text-app-muted italic">{attachment.unavailable ? t('inbox.fileUnavailable') : t('inbox.fileLoading')}</span>
  }
  if ((attachment.kind === 'image' || attachment.kind === 'sticker') && url.data) {
    return (
      <a href={url.data} target="_blank" rel="noreferrer">
        <img src={url.data} alt={attachment.name ?? t('inbox.photo')} className="max-h-72 max-w-full rounded-10 object-contain" />
      </a>
    )
  }
  if (attachment.kind === 'audio' && url.data) return <audio controls src={url.data} className="max-w-full" />
  if (attachment.kind === 'video' && url.data) return <video controls src={url.data} className="max-h-72 max-w-full rounded-10" />
  if (visual) return <span className="text-13 text-app-muted">{t('inbox.fileLoading')}</span>

  const open = async () => {
    setOpening(true)
    try {
      const blob = await fetchDocument(`admin/inbox/messages/${message.id}/file`)
      window.open(URL.createObjectURL(blob), '_blank', 'noopener')
    } finally {
      setOpening(false)
    }
  }
  return (
    <button type="button" onClick={() => void open()} className="flex cursor-pointer items-center gap-2 rounded-10 border border-app-line bg-app-surface px-3 py-2 text-left text-13" aria-busy={opening}>
      <span aria-hidden>📄</span>
      <span className="min-w-0 truncate font-semibold">{attachment.name ?? t('inbox.document')}</span>
    </button>
  )
}

function Composer({ conversation }: { conversation: ConversationDetail }) {
  const { t } = useTranslation()
  const { dateTime } = useFormat()
  const { can } = useAuth()
  const reply = useReply(conversation.id)
  const canned = useCannedReplies()
  const [body, setBody] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [picking, setPicking] = useState(false)
  const fileInput = useRef<HTMLInputElement>(null)
  const textarea = useRef<HTMLTextAreaElement>(null)

  if (!can('inbox.reply')) return null
  if (!conversation.can_reply) {
    return (
      <p role="status" className="m-0 border-t border-app-line bg-orange-tint px-4 py-3 text-13 text-amber">
        {t('inbox.windowClosed')}
      </p>
    )
  }

  const insert = (text: string) => {
    setBody((current) => (current.trim() === '' ? text : `${current.trimEnd()}\n${text}`))
    textarea.current?.focus()
  }
  const send = () => {
    if (reply.isPending || (body.trim() === '' && !file)) return
    reply.mutate(
      { body, file },
      {
        onSuccess: () => {
          setBody('')
          setFile(null)
          if (fileInput.current) fileInput.current.value = ''
        },
      },
    )
  }
  const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
    // Enter sends, Shift+Enter starts a new line — as in WhatsApp Web.
    if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
      event.preventDefault()
      send()
    }
  }
  const fieldError = reply.error instanceof ApiError ? (reply.error.field('body') ?? reply.error.field('file')) : undefined

  return (
    <div className="flex flex-col gap-2 border-t border-app-line px-3 py-3">
      {conversation.reply_window_ends_at ? <span className="text-11 text-app-muted">{t('inbox.windowUntil', { time: dateTime(conversation.reply_window_ends_at) })}</span> : null}
      <div className="flex gap-1.5 overflow-x-auto pb-0.5" role="list" aria-label={t('inbox.canned')}>
        <button type="button" role="listitem" className={buttonClass('outline', 'sm', 'shrink-0 whitespace-nowrap')} onClick={() => setPicking(true)}>
          {t('inbox.sendPackage')}
        </button>
        {(canned.data?.data ?? []).map((item) => (
          <button key={item.id} type="button" role="listitem" className={buttonClass('outline', 'sm', 'shrink-0 whitespace-nowrap')} onClick={() => insert(item.body)} title={item.body}>
            {item.title}
          </button>
        ))}
      </div>
      {file ? (
        <span className="flex items-center gap-2 self-start rounded-8 bg-app-surface-2 px-2.5 py-1 text-12" data-testid="attached">
          📎 {file.name}
          <button type="button" className="cursor-pointer text-app-muted" aria-label={t('inbox.removeFile')} onClick={() => setFile(null)}>
            ×
          </button>
        </span>
      ) : null}
      <div className="flex items-end gap-2">
        <input ref={fileInput} type="file" accept={ACCEPT} className="hidden" onChange={(event) => setFile(event.target.files?.[0] ?? null)} data-testid="attach-input" />
        <button type="button" className={buttonClass('ghost', 'icon', 'shrink-0')} aria-label={t('inbox.attach')} title={t('inbox.attach')} onClick={() => fileInput.current?.click()}>
          📎
        </button>
        <textarea
          ref={textarea}
          value={body}
          onChange={(event) => setBody(event.target.value)}
          onKeyDown={onKeyDown}
          rows={Math.min(6, Math.max(1, body.split('\n').length))}
          placeholder={t('inbox.typeReply')}
          aria-label={t('inbox.typeReply')}
          className={`${controlClass(!!fieldError)} min-w-0 flex-1 resize-none rounded-18 py-2.5`}
        />
        <button type="button" className={buttonClass('cta', 'md', 'shrink-0')} onClick={send} aria-disabled={reply.isPending || (body.trim() === '' && !file)}>
          {reply.isPending ? t('inbox.sending') : t('inbox.send')}
        </button>
      </div>
      {fieldError ? (
        <p role="alert" className="m-0 text-12 text-red">
          {fieldError}
        </p>
      ) : reply.error && !(reply.error instanceof ApiError && reply.error.status === 422) ? (
        <ErrorNotice error={reply.error} />
      ) : null}
      {picking ? <PackagePicker onPick={(text) => insert(text)} onClose={() => setPicking(false)} /> : null}
    </div>
  )
}
