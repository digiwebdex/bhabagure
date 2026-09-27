import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { buttonClass } from '../../components/ui/button'
import { ErrorNotice, useConfirm, useToast } from '../../components/ui/feedback'
import { TextArea, TextInput } from '../../components/ui/fields'
import { Badge, Card, CardTitle, Loading, PageHeader } from '../../components/ui/layout'
import { api, ApiError } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'
import { useFormat } from '../../lib/useFormat'
import { useCannedMutation, useCannedReplies, useInboxSettings, useSettingsMutation, type CannedReply, type InboxSettings } from './api'

/**
 * Inbox → Settings (inbox.manage, docs/admin-inbox.md §4): the WhatsApp connection's state, the Facebook Page connection
 * with Meta's setup steps, and the canned replies staff insert with one click.
 */
export function InboxSettingsPage() {
  const { t } = useTranslation()
  const settings = useInboxSettings()

  return (
    <>
      <PageHeader
        title={t('inbox.settingsTitle')}
        subtitle={t('inbox.settingsSubtitle')}
        actions={
          <Link to="/inbox" className={buttonClass('outline', 'sm')}>
            {t('inbox.backToInbox')}
          </Link>
        }
      />
      {settings.isPending ? (
        <Loading />
      ) : settings.isError ? (
        <ErrorNotice error={settings.error} />
      ) : (
        <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
          <div className="flex min-w-0 flex-col gap-4.5">
            <WhatsAppCard state={settings.data.data.whatsapp} />
            <MessengerCard state={settings.data.data.messenger} />
          </div>
          <CannedRepliesCard />
        </div>
      )}
    </>
  )
}

function Copyable({ label, value }: { label: string; value: string }) {
  const { t } = useTranslation()
  const toast = useToast()
  return (
    <div className="flex flex-col gap-1">
      <span className="text-12 font-semibold text-app-muted">{label}</span>
      <span className="flex items-center gap-2">
        <code className="min-w-0 flex-1 truncate rounded-8 bg-app-surface-2 px-2.5 py-1.5 text-12">{value}</code>
        <button
          type="button"
          className={buttonClass('outline', 'sm', 'shrink-0')}
          onClick={() => void navigator.clipboard?.writeText(value).then(() => toast(t('inbox.copied')))}
        >
          {t('inbox.copy')}
        </button>
      </span>
    </div>
  )
}

function WhatsAppCard({ state }: { state: InboxSettings['whatsapp'] }) {
  const { t } = useTranslation()
  const connected = state.session_status === 'connected'
  return (
    <Card>
      <CardTitle
        title={t('inbox.whatsappTitle')}
        aside={
          !state.enabled || !state.configured ? (
            <Badge tone="slate">{t('inbox.off')}</Badge>
          ) : connected ? (
            <Badge tone="green">{t('inbox.connected')}</Badge>
          ) : (
            <Badge tone="red">{t('inbox.sessionStatus', { status: state.session_status ?? 'unknown' })}</Badge>
          )
        }
      />
      <p className="m-0 text-13 leading-1.55 text-app-muted">{state.enabled && state.configured ? t('inbox.whatsappOn') : t('inbox.whatsappOffNote')}</p>
      {!state.webhook_secret_set ? <p className="m-0 text-13 font-semibold text-red">{t('inbox.noWebhookSecret')}</p> : null}
      <Copyable label={t('inbox.webhookUrl')} value={state.webhook_url} />
      <div className="flex flex-col gap-1">
        <span className="text-12 font-semibold text-app-muted">{t('inbox.events')}</span>
        <span className="flex flex-wrap gap-1.5">
          {state.events.map((event) => (
            <code key={event} className="rounded-6 bg-app-surface-2 px-2 py-0.5 text-12">
              {event}
            </code>
          ))}
        </span>
      </div>
      <p className="m-0 text-12 leading-1.55 text-app-muted">{t('inbox.whatsappHelp')}</p>
    </Card>
  )
}

function MessengerCard({ state }: { state: InboxSettings['messenger'] }) {
  const { t } = useTranslation()
  const { dateTime } = useFormat()
  const toast = useToast()
  const { confirm, element } = useConfirm()
  const [token, setToken] = useState('')
  const [secret, setSecret] = useState('')
  const connect = useSettingsMutation((body: { page_token: string; app_secret: string }) => api.put<Data<InboxSettings>>('admin/inbox/settings/messenger', body))
  const disconnect = useSettingsMutation(() => api.delete<Data<InboxSettings>>('admin/inbox/settings/messenger'))
  const error = (field: string) => (connect.error instanceof ApiError ? connect.error.field(field) : undefined)

  return (
    <Card>
      <CardTitle title={t('inbox.messengerTitle')} aside={state.connected ? <Badge tone="green">{t('inbox.connected')}</Badge> : <Badge tone="slate">{t('inbox.notConnected')}</Badge>} />
      {state.connected ? (
        <p className="m-0 text-13">
          {t('inbox.pageConnected', { name: state.page_name ?? state.page_id ?? '', date: state.connected_at ? dateTime(state.connected_at) : '—' })}
        </p>
      ) : null}
      <ol className="m-0 flex flex-col gap-1.5 pl-5 text-13 leading-1.55" data-testid="messenger-steps">
        {(t('inbox.metaSteps', { returnObjects: true }) as string[]).map((step) => (
          <li key={step}>{step}</li>
        ))}
      </ol>
      <Copyable label={t('inbox.callbackUrl')} value={state.webhook_url} />
      <Copyable label={t('inbox.verifyToken')} value={state.verify_token} />
      <div className="flex flex-col gap-1">
        <span className="text-12 font-semibold text-app-muted">{t('inbox.fields')}</span>
        <span className="flex flex-wrap gap-1.5">
          {state.fields.map((field) => (
            <code key={field} className="rounded-6 bg-app-surface-2 px-2 py-0.5 text-12">
              {field}
            </code>
          ))}
        </span>
      </div>
      <form
        className="flex flex-col gap-3 border-t border-app-line pt-3"
        onSubmit={(event) => {
          event.preventDefault()
          connect.mutate(
            { page_token: token, app_secret: secret },
            {
              onSuccess: () => {
                setToken('')
                setSecret('')
                toast(t('inbox.messengerSaved'))
              },
            },
          )
        }}
      >
        <TextInput label={t('inbox.pageToken')} value={token} onChange={setToken} error={error('page_token')} type="password" autoComplete="off" hint={state.connected ? t('inbox.tokenKept') : undefined} />
        <TextInput label={t('inbox.appSecret')} value={secret} onChange={setSecret} error={error('app_secret')} type="password" autoComplete="off" hint={state.app_secret_set ? t('inbox.secretKept') : undefined} />
        <div className="flex flex-wrap gap-2">
          <button type="submit" className={buttonClass('primary')} aria-disabled={connect.isPending || token.trim() === ''}>
            {connect.isPending ? t('inbox.checking') : state.connected ? t('inbox.updateConnection') : t('inbox.connect')}
          </button>
          {state.connected ? (
            <button
              type="button"
              className={buttonClass('danger')}
              onClick={async () => {
                if (await confirm(t('inbox.confirmDisconnect'))) disconnect.mutate(undefined)
              }}
            >
              {t('inbox.disconnect')}
            </button>
          ) : null}
        </div>
        {connect.error && !(connect.error instanceof ApiError && connect.error.status === 422) ? <ErrorNotice error={connect.error} /> : null}
        {disconnect.error ? <ErrorNotice error={disconnect.error} /> : null}
      </form>
      {element}
    </Card>
  )
}

function CannedRepliesCard() {
  const { t } = useTranslation()
  const replies = useCannedReplies()
  const [editing, setEditing] = useState<CannedReply | 'new' | null>(null)

  return (
    <Card>
      <CardTitle
        title={t('inbox.cannedTitle')}
        aside={
          <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setEditing('new')}>
            + {t('inbox.addCanned')}
          </button>
        }
      />
      <p className="m-0 text-12 text-app-muted">{t('inbox.cannedNote')}</p>
      {replies.isPending ? <Loading /> : replies.isError ? <ErrorNotice error={replies.error} /> : null}
      {editing === 'new' ? <CannedForm onDone={() => setEditing(null)} /> : null}
      <ul className="m-0 flex list-none flex-col gap-2 p-0">
        {(replies.data?.data ?? []).map((reply) =>
          editing !== 'new' && editing?.id === reply.id ? (
            <li key={reply.id}>
              <CannedForm reply={reply} onDone={() => setEditing(null)} />
            </li>
          ) : (
            <li key={reply.id} className="flex items-start justify-between gap-3 rounded-10 border border-app-line p-3">
              <span className="flex min-w-0 flex-col gap-0.5">
                <span className="text-14 font-semibold">{reply.title}</span>
                <span className="text-13 whitespace-pre-wrap text-app-muted">{reply.body}</span>
              </span>
              <button type="button" className={buttonClass('ghost', 'sm', 'shrink-0')} onClick={() => setEditing(reply)}>
                {t('inbox.edit')}
              </button>
            </li>
          ),
        )}
      </ul>
    </Card>
  )
}

function CannedForm({ reply, onDone }: { reply?: CannedReply; onDone: () => void }) {
  const { t } = useTranslation()
  const { confirm, element } = useConfirm()
  const [title, setTitle] = useState(reply?.title ?? '')
  const [body, setBody] = useState(reply?.body ?? '')
  const save = useCannedMutation(() => (reply ? api.put(`admin/inbox/canned-replies/${reply.id}`, { title, body }) : api.post('admin/inbox/canned-replies', { title, body })))
  const remove = useCannedMutation(() => api.delete(`admin/inbox/canned-replies/${reply!.id}`))
  const error = (field: string) => (save.error instanceof ApiError ? save.error.field(field) : undefined)

  return (
    <form
      className="flex flex-col gap-2 rounded-10 border border-blue p-3"
      onSubmit={(event) => {
        event.preventDefault()
        save.mutate(undefined, { onSuccess: onDone })
      }}
    >
      <TextInput label={t('inbox.cannedName')} value={title} onChange={setTitle} error={error('title')} />
      <TextArea label={t('inbox.cannedText')} value={body} onChange={setBody} error={error('body')} rows={4} />
      <div className="flex flex-wrap gap-2">
        <button type="submit" className={buttonClass('primary', 'sm')} aria-disabled={save.isPending}>
          {t('common.save')}
        </button>
        <button type="button" className={buttonClass('ghost', 'sm')} onClick={onDone}>
          {t('common.cancel')}
        </button>
        {reply ? (
          <button
            type="button"
            className={buttonClass('danger', 'sm', 'ml-auto')}
            onClick={async () => {
              if (await confirm(t('inbox.confirmDeleteCanned'))) remove.mutate(undefined, { onSuccess: onDone })
            }}
          >
            {t('common.delete')}
          </button>
        ) : null}
      </div>
      {element}
    </form>
  )
}
