import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useBlocker, useParams } from 'react-router'

import { buttonClass } from '../../../components/ui/button'
import { Dialog, ErrorNotice, UnsavedChangesPrompt, useConfirm, useToast } from '../../../components/ui/feedback'
import { TextInput } from '../../../components/ui/fields'
import { Card, CardTitle, EmptyState, Loading, PageHeader } from '../../../components/ui/layout'
import { api, ApiError } from '../../../lib/api/client'
import type { Data } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'
import { RichTextEditor } from '../blog/RichTextEditor'

/**
 * Website → Legal pages (docs/legal-pages.md): the booking terms, refund/cancellation policy and privacy policy. Each
 * publish is a new version; the website shows the latest, and a booking keeps the terms version its customer accepted.
 */

const SITE_URL = (import.meta.env.VITE_SITE_URL ?? '').replace(/\/$/, '')
const SLUGS = ['terms', 'refund-policy', 'privacy'] as const
type Slug = (typeof SLUGS)[number]

type VersionSummary = { version: number; note: string | null; published_at: string; published_by: string | null }
type Text = { title_bn: string; title_en: string | null; body_bn: string; body_en: string | null }
type PageDetail = { slug: Slug; published: boolean; text: Text | null; versions: VersionSummary[] }
type PageRow = { slug: Slug; title_bn: string; current: VersionSummary | null }

export function LegalPagesPage() {
  const { t } = useTranslation()
  const { dateTime } = useFormat()
  const pages = useQuery({ queryKey: ['legal-pages'], queryFn: ({ signal }) => api.get<Data<PageRow[]>>('admin/legal-pages', signal) })

  return (
    <>
      <PageHeader title={t('legal.title')} subtitle={t('legal.subtitle')} />
      {pages.isPending ? <Loading /> : pages.isError ? <ErrorNotice error={pages.error} /> : (
        <div className="grid gap-4 md:grid-cols-3" data-testid="legal-pages">
          {pages.data.data.map((page) => (
            <Card key={page.slug}>
              <CardTitle title={t(`legal.pages.${page.slug}`)} />
              <p className="m-0 text-13 text-app-muted">
                {page.current
                  ? t('legal.currentVersion', { version: page.current.version, when: dateTime(page.current.published_at) })
                  : t('legal.draftOnly')}
              </p>
              <Link to={`/legal-pages/${page.slug}`} className={buttonClass('primary', 'md', 'self-start')}>
                {t('legal.edit')}
              </Link>
            </Card>
          ))}
        </div>
      )}
    </>
  )
}

export function LegalPageEditorPage() {
  const { slug } = useParams()
  const { t } = useTranslation()
  const known = SLUGS.includes(slug as Slug)
  const page = useQuery({
    queryKey: ['legal-page', slug],
    queryFn: ({ signal }) => api.get<Data<PageDetail>>(`admin/legal-pages/${slug}`, signal),
    enabled: known,
  })

  if (!known) return <EmptyState title={t('errors.notFoundTitle')} />
  if (page.isPending) return <Loading />
  if (page.isError) return <ErrorNotice error={page.error} />
  const detail = page.data.data
  return <Editor key={detail.versions[0]?.version ?? 0} detail={detail} />
}

function Editor({ detail }: { detail: PageDetail }) {
  const { t } = useTranslation()
  const { dateTime } = useFormat()
  const toast = useToast()
  const client = useQueryClient()
  const { confirm, element: confirmDialog } = useConfirm()
  const initial = { title_bn: detail.text?.title_bn ?? '', title_en: detail.text?.title_en ?? '', body_bn: detail.text?.body_bn ?? '', body_en: detail.text?.body_en ?? '' }
  const [form, setForm] = useState(initial)
  const [note, setNote] = useState('')
  const [viewing, setViewing] = useState<number | null>(null)
  const dirty = JSON.stringify(form) !== JSON.stringify(initial)

  const publish = useMutation({
    mutationFn: () => api.post<Data<VersionSummary>>(`admin/legal-pages/${detail.slug}`, { ...form, note }),
    // Finished only once the page has reloaded with the new version: the editor restarts from it then, so nothing typed
    // after the toast can be lost to that restart.
    onSuccess: async (response) => {
      await Promise.all([
        client.invalidateQueries({ queryKey: ['legal-pages'] }),
        client.invalidateQueries({ queryKey: ['legal-page', detail.slug] }),
      ])
      toast(t('legal.published', { version: response.data.version }))
    },
  })
  const blocker = useBlocker(({ currentLocation, nextLocation }) => dirty && !publish.isPending && currentLocation.pathname !== nextLocation.pathname)
  const error = (name: string) => (publish.error instanceof ApiError ? publish.error.field(name) : undefined)
  const set = (key: keyof typeof form, value: string) => setForm((current) => ({ ...current, [key]: value }))

  const onPublish = async () => {
    if (await confirm(t('legal.confirmPublish', { version: (detail.versions[0]?.version ?? 0) + 1 }))) publish.mutate()
  }

  return (
    <>
      <PageHeader
        title={t(`legal.pages.${detail.slug}`)}
        subtitle={detail.published ? t('legal.editorSubtitle') : t('legal.editorDraftSubtitle')}
        actions={
          <a href={`${SITE_URL}/${detail.slug}`} target="_blank" rel="noopener noreferrer" className={buttonClass('outline')}>
            {t('legal.viewOnSite')}
          </a>
        }
      />
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
        <Card>
          <TextInput label={t('legal.titleBn')} value={form.title_bn} onChange={(value) => set('title_bn', value)} error={error('title_bn')} maxLength={160} />
          <RichTextEditor label={t('legal.bodyBn')} value={form.body_bn} onChange={(html) => set('body_bn', html)} error={error('body_bn')} />
          <TextInput label={t('legal.titleEn')} value={form.title_en} onChange={(value) => set('title_en', value)} error={error('title_en')} maxLength={160} hint={t('legal.englishHint')} />
          <RichTextEditor label={t('legal.bodyEn')} value={form.body_en} onChange={(html) => set('body_en', html)} error={error('body_en')} />
          <TextInput label={t('legal.note')} value={note} onChange={setNote} error={error('note')} maxLength={300} hint={t('legal.noteHint')} />
          {publish.error && !(publish.error instanceof ApiError && publish.error.status === 422) ? <ErrorNotice error={publish.error} /> : null}
          <button type="button" className={buttonClass('primary', 'md', 'self-start')} disabled={publish.isPending || form.title_bn.trim() === '' || form.body_bn.trim() === ''} onClick={() => void onPublish()}>
            {publish.isPending ? t('common.working') : t('legal.publish')}
          </button>
        </Card>
        <Card>
          <CardTitle title={t('legal.versions')} />
          {detail.versions.length === 0 ? <p className="m-0 text-13 text-app-muted">{t('legal.noVersions')}</p> : (
            <ol className="m-0 flex list-none flex-col gap-2.5 p-0" data-testid="legal-versions">
              {detail.versions.map((version, index) => (
                <li key={version.version} className="flex flex-col gap-0.5 text-13">
                  <span className="font-semibold">
                    {t('legal.versionLabel', { version: version.version })}
                    {index === 0 ? <span className="ml-1.5 text-12 font-normal text-green">{t('legal.live')}</span> : null}
                  </span>
                  <span className="text-12 text-app-muted">{dateTime(version.published_at)}{version.published_by ? ` · ${version.published_by}` : ''}</span>
                  {version.note ? <span className="text-12">{version.note}</span> : null}
                  <button type="button" className="cursor-pointer self-start text-12 font-semibold text-blue hover:underline" onClick={() => setViewing(version.version)}>
                    {t('legal.read')}
                  </button>
                </li>
              ))}
            </ol>
          )}
        </Card>
      </div>
      {viewing !== null ? <VersionDialog slug={detail.slug} version={viewing} onClose={() => setViewing(null)} /> : null}
      {blocker.state === 'blocked' ? <UnsavedChangesPrompt onStay={() => blocker.reset()} onLeave={() => blocker.proceed()} /> : null}
      {confirmDialog}
    </>
  )
}

/** An earlier version as it was published, read-only. */
function VersionDialog({ slug, version, onClose }: { slug: Slug; version: number; onClose: () => void }) {
  const { t } = useTranslation()
  const text = useQuery({
    queryKey: ['legal-page', slug, version],
    queryFn: ({ signal }) => api.get<Data<Text>>(`admin/legal-pages/${slug}/versions/${version}`, signal),
  })

  return (
    <Dialog open onClose={onClose} title={t('legal.versionLabel', { version })}>
      {text.isPending ? <Loading /> : text.isError ? <ErrorNotice error={text.error} /> : (
        <div className="flex max-h-[70vh] flex-col gap-3 overflow-y-auto">
          <h3 className="m-0 text-16 font-semibold">{text.data.data.title_bn}</h3>
          {/* Sanitised by the API when it was published. */}
          <div className="rich-text text-14 leading-1.6" dangerouslySetInnerHTML={{ __html: text.data.data.body_bn }} />
          {text.data.data.body_en ? (
            <>
              <h3 className="m-0 text-16 font-semibold">{text.data.data.title_en}</h3>
              <div className="rich-text text-14 leading-1.6" dangerouslySetInnerHTML={{ __html: text.data.data.body_en }} />
            </>
          ) : null}
        </div>
      )}
      <button type="button" className={buttonClass('outline', 'md', 'self-end')} onClick={onClose}>
        {t('common.close')}
      </button>
    </Dialog>
  )
}
