import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { ErrorNotice } from '../../components/ui/feedback'
import { Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import { useConversations, type ConversationRow, type InboxFilters, type InboxView } from './api'
import { ConversationPane } from './ConversationPane'
import { Avatar, ChannelBadge } from './parts'

const VIEWS: InboxView[] = ['all', 'unread', 'mine', 'closed']

/**
 * Communication → Inbox (docs/admin-inbox.md; the design's Inbox screen): customers' WhatsApp and Messenger chats in one
 * list, the open chat beside it and the customer beside that. On a phone the list and the chat take turns.
 */
export function InboxPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const navigate = useNavigate()
  const { id } = useParams()
  const openId = id ? Number(id) : null
  const [params] = useSearchParams()
  const [filters, setFilters] = useState<InboxFilters>({
    view: VIEWS.includes(params.get('view') as InboxView) ? (params.get('view') as InboxView) : 'all',
    channel: 'all',
    search: '',
    page: 1,
  })
  const set = (patch: Partial<InboxFilters>) => setFilters((current) => ({ ...current, page: 1, ...patch }))
  const list = useConversations(filters)
  const query = filters.view === 'all' ? '' : `?view=${filters.view}`

  return (
    <>
      <PageHeader
        title={t('inbox.title')}
        subtitle={t('inbox.subtitle')}
        actions={
          can('inbox.manage') ? (
            <Link to="/inbox/settings" className={buttonClass('outline', 'sm')}>
              {t('inbox.settings')}
            </Link>
          ) : null
        }
      />
      <div className="grid min-h-0 gap-3 min-[1180px]:h-[calc(100dvh-11rem)] lg:grid-cols-[minmax(280px,340px)_1fr]" data-testid="inbox">
        <section aria-label={t('inbox.conversations')} className={`flex min-h-0 flex-col gap-2.5 rounded-14 border border-app-line bg-app-surface p-3 ${openId !== null ? 'max-lg:hidden' : ''}`}>
          <Chips
            label={t('inbox.channel')}
            value={filters.channel}
            onChange={(channel) => set({ channel })}
            options={(['all', 'whatsapp', 'messenger'] as const).map((value) => ({
              value,
              label: value === 'all' ? t('inbox.channels.all') : `${t(`inbox.channels.${value}`)}${list.data?.meta.unread_by_channel[value] ? ` · ${list.data.meta.unread_by_channel[value]}` : ''}`,
            }))}
          />
          <div className="flex flex-wrap gap-1.5" role="tablist" aria-label={t('inbox.view')}>
            {VIEWS.map((view) => (
              <button
                key={view}
                type="button"
                role="tab"
                aria-selected={filters.view === view}
                onClick={() => set({ view })}
                className={`cursor-pointer rounded-8 px-2.5 py-1 text-12 font-semibold ${filters.view === view ? 'bg-blue-tint text-blue-deep' : 'text-app-muted hover:text-app-text'}`}
              >
                {t(`inbox.views.${view}`)}
                {list.data ? <span className="ml-1 opacity-75">{list.data.meta.counts[view]}</span> : null}
              </button>
            ))}
          </div>
          <input
            type="search"
            className={controlClass()}
            value={filters.search}
            onChange={(event) => set({ search: event.target.value })}
            placeholder={t('inbox.search')}
            aria-label={t('inbox.search')}
          />
          <div className="-mx-3 min-h-0 flex-1 overflow-y-auto">
            {list.isPending ? (
              <Loading />
            ) : list.isError ? (
              <div className="px-3">
                <ErrorNotice error={list.error} />
              </div>
            ) : list.data.data.length === 0 ? (
              <EmptyState title={t('inbox.empty')} note={filters.view === 'all' && filters.channel === 'all' && !filters.search ? t('inbox.emptyNote') : undefined} />
            ) : (
              <ul className="m-0 list-none p-0">
                {list.data.data.map((row) => (
                  <li key={row.id}>
                    <ConversationItem row={row} now={list.dataUpdatedAt} active={row.id === openId} onOpen={() => navigate(`/inbox/${row.id}${query}`)} />
                  </li>
                ))}
              </ul>
            )}
          </div>
          {list.data && list.data.meta.last_page > 1 ? (
            <div className="flex items-center justify-between text-12 text-app-muted">
              <button type="button" className={buttonClass('ghost', 'sm')} disabled={filters.page <= 1} onClick={() => setFilters({ ...filters, page: filters.page - 1 })}>
                ← {t('common.previous')}
              </button>
              <span>
                {list.data.meta.current_page} / {list.data.meta.last_page}
              </span>
              <button type="button" className={buttonClass('ghost', 'sm')} disabled={filters.page >= list.data.meta.last_page} onClick={() => setFilters({ ...filters, page: filters.page + 1 })}>
                {t('common.next')} →
              </button>
            </div>
          ) : null}
        </section>

        <section aria-label={t('inbox.chat')} className={`min-h-0 ${openId === null ? 'max-lg:hidden' : ''}`}>
          {openId !== null ? (
            <ConversationPane key={openId} id={openId} onBack={() => navigate(`/inbox${query}`)} />
          ) : (
            <div className="flex h-full items-center justify-center rounded-14 border border-dashed border-app-line p-8 text-center text-14 text-app-muted">{t('inbox.pick')}</div>
          )}
        </section>
      </div>
    </>
  )
}

/** `now`: when the list was fetched (it refreshes every 10 seconds), so the ages are as of that moment. */
function ConversationItem({ row, now, active, onOpen }: { row: ConversationRow; now: number; active: boolean; onOpen: () => void }) {
  const { t } = useTranslation()
  const { relativeAge, digits } = useFormat()
  const name = row.name ?? (row.phone ? digits(`+${row.phone}`) : t('inbox.unknown'))
  const unread = row.unread_count > 0 && row.status === 'open'
  const minutes = row.last_message_at ? Math.max(0, Math.round((now - new Date(row.last_message_at).getTime()) / 60_000)) : null

  return (
    <button
      type="button"
      onClick={onOpen}
      aria-current={active ? 'true' : undefined}
      className={`flex w-full cursor-pointer items-start gap-3 border-b border-app-line px-3 py-3 text-left ${active ? 'bg-blue-tint' : 'hover:bg-app-surface-2'}`}
    >
      <Avatar name={name} channel={row.channel} />
      <span className="flex min-w-0 flex-1 flex-col gap-1">
        <span className="flex items-baseline justify-between gap-2">
          <span className={`truncate text-14 ${unread ? 'font-bold' : 'font-semibold'}`}>{name}</span>
          <span className="shrink-0 text-11 text-app-muted">{minutes === null ? '' : minutes < 1 ? t('inbox.now') : relativeAge(minutes)}</span>
        </span>
        <span className={`truncate text-13 ${unread ? 'text-app-text' : 'text-app-muted'}`}>
          {row.last_message_direction === 'out' ? `${t('inbox.you')}: ` : ''}
          {row.last_message_preview ?? ''}
        </span>
        <span className="flex flex-wrap items-center gap-1.5">
          <ChannelBadge channel={row.channel} />
          {unread ? (
            <span className="rounded-pill bg-orange px-2 py-0.25 text-11 font-bold text-white" aria-label={t('inbox.unreadCount', { count: row.unread_count })}>
              {row.unread_count}
            </span>
          ) : null}
          {row.assignee ? <span className="truncate text-11 text-app-muted">→ {row.assignee.name}</span> : null}
          {row.status === 'closed' ? <span className="text-11 text-app-muted">{t('inbox.closed')}</span> : null}
        </span>
      </span>
    </button>
  )
}

