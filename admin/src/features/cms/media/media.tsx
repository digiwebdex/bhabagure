import { useQueryClient } from '@tanstack/react-query'
import { useRef, useState, type DragEvent } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../../components/ui/button'
import { Dialog, ErrorNotice } from '../../../components/ui/feedback'
import { controlClass } from '../../../components/ui/controls'
import { EmptyState, Loading } from '../../../components/ui/layout'
import { ApiError, upload } from '../../../lib/api/client'
import type { Data, Media } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'
import { ACCEPTED_TYPES, MAX_UPLOAD_BYTES, useMediaList } from './api'

export function MediaThumb({ media, className = 'size-thumb' }: { media: Media | null | undefined; className?: string }) {
  const { i18n } = useTranslation()
  const src = media?.variants.thumb?.url ?? media?.url
  const alt = (i18n.resolvedLanguage === 'en' ? media?.alt_en : media?.alt_bn) ?? media?.alt_en ?? ''

  return src ? (
    <img src={src} alt={alt} loading="lazy" className={`shrink-0 rounded-10 border border-app-line bg-app-surface-2 object-cover ${className}`} />
  ) : (
    <span aria-hidden className={`block shrink-0 rounded-10 border border-dashed border-app-line bg-app-surface-2 ${className}`} />
  )
}

type Uploading = { key: string; name: string; progress: number; error?: string }

/**
 * Drop zone + file picker. Uploads one file at a time and reports each result, so one bad photo in a batch of
 * ten doesn't hide the other nine.
 */
export function ImageUploader({ onUploaded, multiple = true }: { onUploaded: (media: Media) => void; multiple?: boolean }) {
  const { t } = useTranslation()
  const { number } = useFormat()
  const input = useRef<HTMLInputElement>(null)
  const queryClient = useQueryClient()
  const [items, setItems] = useState<Uploading[]>([])
  const [dragging, setDragging] = useState(false)

  const update = (key: string, patch: Partial<Uploading>) => setItems((current) => current.map((item) => (item.key === key ? { ...item, ...patch } : item)))

  const start = async (files: File[]) => {
    const queue = files.map((file, index) => ({ file, key: `${Date.now()}-${index}-${file.name}` }))
    setItems((current) => [...current.filter((item) => item.error === undefined && item.progress < 1), ...queue.map(({ file, key }) => ({ key, name: file.name, progress: 0 }))])

    for (const { file, key } of queue) {
      if (!ACCEPTED_TYPES.includes(file.type)) {
        update(key, { error: t('media.wrongType') })
        continue
      }
      if (file.size > MAX_UPLOAD_BYTES) {
        update(key, { error: t('media.tooLarge', { max: number(5) }) })
        continue
      }
      const form = new FormData()
      form.append('file', file)
      try {
        const result = await upload<Data<Media>>('admin/media', form, (progress) => update(key, { progress }))
        update(key, { progress: 1 })
        onUploaded(result.data)
      } catch (error) {
        update(key, { error: error instanceof ApiError ? (error.field('file') ?? error.message) : t('errors.network') })
      }
    }
    void queryClient.invalidateQueries({ queryKey: ['media'] })
  }

  const onDrop = (event: DragEvent) => {
    event.preventDefault()
    setDragging(false)
    void start(Array.from(event.dataTransfer.files).slice(0, multiple ? undefined : 1))
  }

  return (
    <div className="flex flex-col gap-2.5">
      <div
        onDragOver={(event) => {
          event.preventDefault()
          setDragging(true)
        }}
        onDragLeave={() => setDragging(false)}
        onDrop={onDrop}
        className={`flex flex-col items-center gap-2 rounded-12 border border-dashed px-4 py-6 text-center ${dragging ? 'border-blue bg-blue-tint/40' : 'border-app-line bg-app-surface-2'}`}
      >
        <span className="text-14 font-medium">{t('media.dropHere')}</span>
        <span className="text-12 text-app-muted">{t('media.limits', { max: number(5) })}</span>
        <button type="button" className={buttonClass('primary', 'sm')} onClick={() => input.current?.click()}>
          {t('media.chooseFiles')}
        </button>
        <input
          ref={input}
          type="file"
          accept={ACCEPTED_TYPES.join(',')}
          multiple={multiple}
          className="sr-only"
          tabIndex={-1}
          onChange={(event) => {
            void start(Array.from(event.target.files ?? []))
            event.target.value = ''
          }}
        />
      </div>
      {items.length > 0 ? (
        <ul className="m-0 flex list-none flex-col gap-1.5 p-0">
          {items.map((item) => (
            <li key={item.key} className="flex flex-col gap-1 rounded-10 bg-app-surface-2 px-3 py-2 text-13">
              <span className="flex justify-between gap-3">
                <span className="truncate">{item.name}</span>
                <span className={item.error ? 'text-red' : 'text-app-muted'}>{item.error ? t('media.failed') : item.progress >= 1 ? t('media.done') : `${number(Math.round(item.progress * 100))}%`}</span>
              </span>
              {item.error ? (
                <span role="alert" className="text-12 font-semibold text-red">
                  {item.error}
                </span>
              ) : (
                <span className="h-1.5 overflow-hidden rounded-3 bg-app-line">
                  <span className="block h-full bg-blue transition-all" style={{ width: `${Math.round(item.progress * 100)}%` }} />
                </span>
              )}
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  )
}

/** Choose an existing image or upload a new one. */
export function MediaPicker({ open, onClose, onPick, title }: { open: boolean; onClose: () => void; onPick: (media: Media) => void; title?: string }) {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const list = useMediaList(search, page)

  const pick = (media: Media) => {
    onPick(media)
    onClose()
  }

  return (
    <Dialog open={open} onClose={onClose} title={title ?? t('media.pickTitle')} wide>
      <ImageUploader multiple={false} onUploaded={pick} />
      <input type="search" value={search} onChange={(event) => { setSearch(event.target.value); setPage(1) }} placeholder={t('media.search')} aria-label={t('media.search')} className={controlClass()} />
      {list.isPending ? <Loading /> : list.isError ? <ErrorNotice error={list.error} /> : list.data.data.length === 0 ? (
        <EmptyState title={t('media.empty')} />
      ) : (
        <>
          <div className="grid-auto-fit-120 grid gap-2.5">
            {list.data.data.map((media) => (
              <button key={media.id} type="button" onClick={() => pick(media)} className="flex cursor-pointer flex-col gap-1 rounded-12 border border-app-line bg-app-surface p-1.5 text-left hover:border-blue">
                <MediaThumb media={media} className="aspect-4/3 w-full" />
                <span className="truncate px-0.5 text-11 text-app-muted">{media.original_filename ?? media.alt_en ?? `#${media.id}`}</span>
              </button>
            ))}
          </div>
          <Pager page={page} lastPage={list.data.meta.last_page} onPage={setPage} />
        </>
      )}
    </Dialog>
  )
}

export function Pager({ page, lastPage, onPage }: { page: number; lastPage: number; onPage: (page: number) => void }) {
  const { t } = useTranslation()
  const { number } = useFormat()
  if (lastPage <= 1) return null
  return (
    <div className="flex items-center justify-center gap-3 text-13">
      <button type="button" disabled={page <= 1} onClick={() => onPage(page - 1)} className={buttonClass('outline', 'sm')}>
        {t('common.previous')}
      </button>
      <span className="text-app-muted">{t('common.pageOf', { page: number(page), last: number(lastPage) })}</span>
      <button type="button" disabled={page >= lastPage} onClick={() => onPage(page + 1)} className={buttonClass('outline', 'sm')}>
        {t('common.next')}
      </button>
    </div>
  )
}
