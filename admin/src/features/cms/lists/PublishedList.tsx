import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../../components/ui/button'
import { Dialog, ErrorNotice, useConfirm, useToast } from '../../../components/ui/feedback'
import { Card, EmptyState, Loading, PageHeader, ReorderButtons, StatusBadge } from '../../../components/ui/layout'
import { move } from '../../../lib/move'
import { api, ApiError } from '../../../lib/api/client'
import type { ContentStatus, Data } from '../../../lib/api/types'

type Item = { id: number; status: ContentStatus; sort_order: number }

export type FormRenderProps<TForm> = {
  form: TForm
  set: <K extends keyof TForm>(key: K, value: TForm[K]) => void
  error: (field: string) => string | undefined
}

type Props<TItem extends Item, TForm> = {
  /** API collection under /admin, e.g. "reviews". */
  resource: string
  title: string
  subtitle: string
  newLabel: string
  emptyTitle: string
  emptyNote: string
  /** Shown above the list: why the website section is hidden, etc. */
  note?: ReactNode
  row: (item: TItem) => { label: string; content: ReactNode }
  toForm: (item: TItem | null) => TForm
  renderForm: (props: FormRenderProps<TForm>) => ReactNode
}

/**
 * Team, reviews and gallery: a sortable list where every item starts as a draft and is published explicitly.
 * The order here is the order on the website.
 */
export function PublishedList<TItem extends Item, TForm>({ resource, title, subtitle, newLabel, emptyTitle, emptyNote, note, row, toForm, renderForm }: Props<TItem, TForm>) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<TItem | 'new' | null>(null)
  const list = useQuery({ queryKey: [resource], queryFn: ({ signal }) => api.get<Data<TItem[]>>(`admin/${resource}`, signal) })
  const refresh = () => queryClient.invalidateQueries({ queryKey: [resource] })

  const reorder = useMutation({ mutationFn: (ids: number[]) => api.put(`admin/${resource}/order`, { ids }), onSuccess: refresh })
  const toggle = useMutation({
    mutationFn: (item: TItem) => api.post(`admin/${resource}/${item.id}/${item.status === 'published' ? 'unpublish' : 'publish'}`),
    onSuccess: (_, item) => {
      void refresh()
      toast(item.status === 'published' ? t('packages.unpublished') : t('packages.published'))
    },
  })

  return (
    <>
      <PageHeader title={title} subtitle={subtitle} actions={<button type="button" className={buttonClass('cta')} onClick={() => setEditing('new')}>{newLabel}</button>} />
      {note}
      <ErrorNotice error={toggle.error ?? reorder.error} />
      <Card padded={false}>
        {list.isPending ? <Loading /> : list.isError ? <div className="p-4"><ErrorNotice error={list.error} /></div> : list.data.data.length === 0 ? (
          <EmptyState title={emptyTitle} note={emptyNote} action={<button type="button" className={buttonClass('primary', 'sm')} onClick={() => setEditing('new')}>{newLabel}</button>} />
        ) : (
          <ul className="m-0 list-none p-0">
            {list.data.data.map((item, index, items) => {
              const { label, content } = row(item)
              return (
                <li key={item.id} className="flex flex-wrap items-center gap-3 border-b border-app-line px-4 py-3 last:border-b-0">
                  <ReorderButtons index={index} count={items.length} label={label} onMove={(from, to) => reorder.mutate(move(items, from, to).map((i) => i.id))} />
                  <button type="button" onClick={() => setEditing(item)} className="flex min-w-0 flex-1 cursor-pointer items-center gap-3 border-0 bg-transparent p-0 text-left text-app-text">
                    {content}
                  </button>
                  <span className="flex items-center gap-2">
                    <StatusBadge status={item.status} />
                    <button type="button" className={buttonClass(item.status === 'published' ? 'outline' : 'success', 'sm')} onClick={() => toggle.mutate(item)} aria-disabled={toggle.isPending}>
                      {item.status === 'published' ? t('packages.unpublish') : t('packages.publish')}
                    </button>
                  </span>
                </li>
              )
            })}
          </ul>
        )}
      </Card>
      {editing ? (
        <ItemDialog<TItem, TForm> resource={resource} item={editing === 'new' ? null : editing} title={editing === 'new' ? newLabel : title} toForm={toForm} renderForm={renderForm} onClose={() => setEditing(null)} onSaved={refresh} />
      ) : null}
    </>
  )
}

function ItemDialog<TItem extends Item, TForm>({ resource, item, title, toForm, renderForm, onClose, onSaved }: { resource: string; item: TItem | null; title: string; toForm: (item: TItem | null) => TForm; renderForm: (props: FormRenderProps<TForm>) => ReactNode; onClose: () => void; onSaved: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const { confirm, element: confirmDialog } = useConfirm()
  const [form, setForm] = useState<TForm>(() => toForm(item))
  const done = (message: string) => {
    onSaved()
    toast(message)
    onClose()
  }
  const save = useMutation({ mutationFn: () => (item ? api.put(`admin/${resource}/${item.id}`, form) : api.post(`admin/${resource}`, form)), onSuccess: () => done(t('common.saved')) })
  const remove = useMutation({ mutationFn: () => api.delete(`admin/${resource}/${item!.id}`), onSuccess: () => done(t('common.deleted')) })

  return (
    <Dialog open onClose={onClose} title={title} wide>
      {renderForm({
        form,
        set: (key, value) => setForm((current) => ({ ...current, [key]: value })),
        error: (field) => (save.error instanceof ApiError ? save.error.field(field) : undefined),
      })}
      <ErrorNotice error={remove.error ?? (save.error instanceof ApiError && save.error.status === 422 ? null : save.error)} />
      {!item ? <p className="m-0 text-12 text-app-muted">{t('lists.startsAsDraft')}</p> : null}
      <div className="flex flex-wrap justify-between gap-2">
        {item ? <button type="button" className={buttonClass('danger')} onClick={async () => (await confirm(t('lists.confirmDelete'))) && remove.mutate()}>{t('common.delete')}</button> : <span />}
        <span className="flex gap-2">
          <button type="button" className={buttonClass('outline')} onClick={onClose}>{t('common.cancel')}</button>
          <button type="button" className={buttonClass('primary')} onClick={() => save.mutate()} aria-disabled={save.isPending}>{save.isPending ? t('common.saving') : t('common.save')}</button>
        </span>
      </div>
      {confirmDialog}
    </Dialog>
  )
}
