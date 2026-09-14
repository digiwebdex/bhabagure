import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../../components/ui/button'
import { Dialog, ErrorNotice, useConfirm, useToast } from '../../../components/ui/feedback'
import { Pair, TextInput } from '../../../components/ui/fields'
import { controlClass } from '../../../components/ui/controls'
import { Card, EmptyState, Loading, PageHeader } from '../../../components/ui/layout'
import { api, ApiError } from '../../../lib/api/client'
import type { Data, Media } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'
import { useDeleteMedia, useMediaList } from './api'
import { ImageUploader, MediaThumb, Pager } from './media'

export function MediaLibraryPage() {
  const { t } = useTranslation()
  const { number } = useFormat()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<Media | null>(null)
  const list = useMediaList(search, page)

  return (
    <>
      <PageHeader title={t('media.title')} subtitle={t('media.subtitle')} />
      <Card>
        <ImageUploader onUploaded={() => undefined} />
      </Card>
      <Card>
        <input type="search" value={search} onChange={(event) => { setSearch(event.target.value); setPage(1) }} placeholder={t('media.search')} aria-label={t('media.search')} className={controlClass()} />
        {list.isPending ? <Loading /> : list.isError ? <ErrorNotice error={list.error} /> : list.data.data.length === 0 ? (
          <EmptyState title={t('media.empty')} />
        ) : (
          <>
            <span className="text-12 text-app-muted">{t('media.count', { count: list.data.meta.total, n: number(list.data.meta.total) })}</span>
            <div className="grid-auto-fit-160 grid gap-3">
              {list.data.data.map((media) => (
                <button key={media.id} type="button" onClick={() => setEditing(media)} className="flex cursor-pointer flex-col gap-1.5 rounded-12 border border-app-line bg-app-surface p-2 text-left hover:border-blue">
                  <MediaThumb media={media} className="aspect-4/3 w-full" />
                  <span className="truncate text-12">{media.original_filename ?? media.alt_en ?? `#${media.id}`}</span>
                  <span className="text-11 text-app-muted">
                    {media.width && media.height ? `${number(media.width)} × ${number(media.height)}` : t('media.remote')}
                    {media.is_placeholder ? ` · ${t('media.placeholder')}` : ''}
                  </span>
                </button>
              ))}
            </div>
            <Pager page={page} lastPage={list.data.meta.last_page} onPage={setPage} />
          </>
        )}
      </Card>
      {editing ? <MediaDetails media={editing} onClose={() => setEditing(null)} /> : null}
    </>
  )
}

function MediaDetails({ media, onClose }: { media: Media; onClose: () => void }) {
  const { t } = useTranslation()
  const { number } = useFormat()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { confirm, element: confirmDialog } = useConfirm()
  const remove = useDeleteMedia()
  const [values, setValues] = useState({ alt_bn: media.alt_bn, alt_en: media.alt_en, credit: media.credit, credit_url: media.credit_url })

  const save = useMutation({
    mutationFn: () => api.patch<Data<Media>>(`admin/media/${media.id}`, values),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['media'] })
      toast(t('common.saved'))
      onClose()
    },
  })
  const fieldError = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)

  const onDelete = async () => {
    if (!(await confirm(t('media.confirmDelete')))) return
    remove.mutate(media.id, {
      onSuccess: () => {
        toast(t('common.deleted'))
        onClose()
      },
    })
  }

  return (
    <Dialog open onClose={onClose} title={media.original_filename ?? t('media.image')} wide>
      <img src={media.variants.detail?.url ?? media.url ?? ''} alt="" className="max-h-80 w-full rounded-12 bg-app-surface-2 object-contain" />
      <dl className="m-0 grid-auto-fit-160 grid gap-2 text-13">
        {Object.entries(media.variants).map(([name, variant]) => (
          <div key={name} className="rounded-10 bg-app-surface-2 px-3 py-2">
            <dt className="text-11 text-app-muted uppercase">{t(`media.variants.${name}`)}</dt>
            <dd className="m-0">
              <a href={variant.url} target="_blank" rel="noreferrer">{`${number(variant.width)} × ${number(variant.height)}`}</a>
            </dd>
          </div>
        ))}
      </dl>
      <Pair>
        <TextInput label={t('media.altBn')} value={values.alt_bn} onChange={(alt_bn) => setValues({ ...values, alt_bn })} error={fieldError('alt_bn')} hint={t('media.altHint')} />
        <TextInput label={t('media.altEn')} value={values.alt_en} onChange={(alt_en) => setValues({ ...values, alt_en })} error={fieldError('alt_en')} />
      </Pair>
      <Pair>
        <TextInput label={t('media.credit')} value={values.credit} onChange={(credit) => setValues({ ...values, credit })} error={fieldError('credit')} />
        <TextInput label={t('media.creditUrl')} type="url" value={values.credit_url} onChange={(credit_url) => setValues({ ...values, credit_url })} error={fieldError('credit_url')} />
      </Pair>
      <ErrorNotice error={remove.error ?? (save.error instanceof ApiError && save.error.status === 422 ? null : save.error)} />
      <div className="flex flex-wrap justify-between gap-2">
        <button type="button" className={buttonClass('danger')} onClick={() => void onDelete()} disabled={remove.isPending}>
          {t('common.delete')}
        </button>
        <button type="button" className={buttonClass('primary')} onClick={() => save.mutate()} aria-disabled={save.isPending}>
          {save.isPending ? t('common.saving') : t('common.save')}
        </button>
      </div>
      {confirmDialog}
    </Dialog>
  )
}
