import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useBlocker, useNavigate, useParams } from 'react-router'

import { buttonClass } from '../../../components/ui/button'
import { ErrorNotice, UnsavedChangesPrompt, useConfirm, useToast } from '../../../components/ui/feedback'
import { NumberInput, Pair, SelectInput, TextArea, TextInput } from '../../../components/ui/fields'
import { Card, CardTitle, EmptyState, Loading, PageHeader, StatusBadge } from '../../../components/ui/layout'
import { ApiError } from '../../../lib/api/client'
import type { BlogPost, Media } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'
import { MediaPicker, MediaThumb } from '../media/media'
import { slugify } from '../packages/api'
import { SeoFields } from '../packages/ContentLists'
import { emptyPost, postActions, postChecklist, toPostForm, useCategories, usePost, usePostMutation, type PostForm } from './api'
import { RichTextEditor } from './RichTextEditor'

const SITE_URL = (import.meta.env.VITE_SITE_URL ?? '').replace(/\/$/, '')

export function PostEditorPage() {
  const { id } = useParams()
  const postId = id ? Number(id) : null
  const query = usePost(postId)
  const { t } = useTranslation()

  if (postId !== null && Number.isNaN(postId)) return <EmptyState title={t('errors.notFoundTitle')} />
  if (postId !== null && query.isPending) return <Loading />
  if (postId !== null && query.isError) return <ErrorNotice error={query.error} />

  const post = query.data?.data ?? null
  return <Editor key={post ? `${post.id}-${post.updated_at}` : 'new'} post={post} />
}

/** "2026-10-01T09:00" in the browser's zone → ISO string with offset, for the API. */
const toIso = (local: string) => (local ? new Date(local).toISOString() : null)

function Editor({ post }: { post: BlogPost | null }) {
  const { t } = useTranslation()
  const { date, locale } = useFormat()
  const toast = useToast()
  const navigate = useNavigate()
  const categories = useCategories()
  const { confirm, element: confirmDialog } = useConfirm()

  const initial = post ? toPostForm(post) : emptyPost()
  const [form, setForm] = useState<PostForm>(initial)
  const [cover, setCover] = useState<Media | null>(post?.cover ?? null)
  const [picking, setPicking] = useState(false)
  const [schedule, setSchedule] = useState('')
  const dirty = JSON.stringify(form) !== JSON.stringify(initial)

  const save = usePostMutation((values: PostForm) => (post ? postActions.update(post.id, values) : postActions.create(values)))
  const publish = usePostMutation((at: string | null) => postActions.publish(post!.id, at))
  const unpublish = usePostMutation(() => postActions.unpublish(post!.id))
  const remove = usePostMutation(() => postActions.remove(post!.id))
  const blocker = useBlocker(({ currentLocation, nextLocation }) => dirty && !save.isPending && currentLocation.pathname !== nextLocation.pathname)

  const set = <K extends keyof PostForm>(key: K, value: PostForm[K]) => setForm((current) => ({ ...current, [key]: value }))
  const error = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)

  const onSave = () =>
    save.mutate(form, {
      onSuccess: (result) => {
        toast(t('common.saved'))
        if (!post) navigate(`/posts/${result.data.id}`, { replace: true })
      },
    })

  const onPublish = () => {
    if (dirty) return toast(t('packages.saveFirst'), 'error')
    publish.mutate(toIso(schedule), { onSuccess: (result) => toast(result.data.is_scheduled ? t('blog.scheduled') : t('packages.published')) })
  }

  const onDelete = async () => {
    if (!(await confirm(t('blog.confirmDelete')))) return
    remove.mutate(undefined, { onSuccess: () => { toast(t('common.deleted')); navigate('/posts', { replace: true }) } })
  }

  const websiteUrl = `${SITE_URL}${locale === 'en' ? '/en' : ''}/blog/${form.slug || '…'}`
  const title = form.title_en || form.title_bn || t('blog.untitled')
  const saveError = save.error instanceof ApiError && save.error.status === 422 ? new ApiError(422, { message: t('errors.fixFields') }) : save.error

  return (
    <>
      <PageHeader
        title={post ? title : t('blog.new')}
        subtitle={post?.published_at ? t('blog.publishedOn', { date: date(post.published_at) }) : t('blog.notPublished')}
        actions={
          <>
            <Link to="/posts" className={buttonClass('outline')}>{t('common.back')}</Link>
            <button type="button" className={buttonClass('cta')} onClick={onSave} aria-disabled={save.isPending}>
              {save.isPending ? t('common.saving') : dirty || !post ? t('common.save') : t('common.saved')}
            </button>
          </>
        }
      />
      <ErrorNotice error={saveError} />

      <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
        <div className="flex min-w-0 flex-col gap-4.5">
          <Card>
            <CardTitle title="Title and excerpt" />
            <Pair>
              <TextInput label={t('fields.titleBn')} value={form.title_bn} onChange={(value) => set('title_bn', value)} error={error('title_bn')} />
              <TextInput label={t('fields.titleEn')} value={form.title_en} onChange={(value) => setForm((current) => ({ ...current, title_en: value, slug: post || current.slug !== slugify(current.title_en) ? current.slug : slugify(value) }))} error={error('title_en')} />
            </Pair>
            <Pair>
              <TextArea label={t('blog.excerptBn')} value={form.excerpt_bn} onChange={(value) => set('excerpt_bn', value)} error={error('excerpt_bn')} />
              <TextArea label={t('blog.excerptEn')} value={form.excerpt_en} onChange={(value) => set('excerpt_en', value)} error={error('excerpt_en')} />
            </Pair>
          </Card>
          <Card>
            <CardTitle title="Body · Bangla" />
            <RichTextEditor label={t('blog.bodyBn')} value={form.body_bn} onChange={(html) => set('body_bn', html)} error={error('body_bn')} />
          </Card>
          <Card>
            <CardTitle title="Body · English" />
            <RichTextEditor label={t('blog.bodyEn')} value={form.body_en} onChange={(html) => set('body_en', html)} error={error('body_en')} />
          </Card>
          <Card>
            <CardTitle title="SEO · search results and sharing" />
            <SeoFields values={form} onChange={(patch) => setForm((current) => ({ ...current, ...patch }))} url={websiteUrl} fallbackTitle={title} />
          </Card>
        </div>

        <aside className="flex min-w-0 flex-col gap-4.5 lg:sticky lg:top-5">
          <Card>
            <CardTitle title="Publishing" aside={<StatusBadge status={post?.is_scheduled ? 'scheduled' : (post?.status ?? 'draft')} />} />
            <ul className="m-0 flex list-none flex-col gap-1.5 p-0">
              {postChecklist(form).map((item) => (
                <li key={item.key} className="flex items-center gap-2 text-13">
                  <span aria-hidden className={`flex size-4.5 shrink-0 items-center justify-center rounded-5 text-11 text-white ${item.done ? 'bg-green' : 'bg-app-line'}`}>{item.done ? '✓' : ''}</span>
                  <span className={item.done ? '' : 'text-app-muted'}>{t(`blog.checklist.${item.key}`)}</span>
                </li>
              ))}
            </ul>
            <ErrorNotice error={publish.error ?? unpublish.error ?? remove.error} />
            {post ? (
              post.status === 'published' ? (
                <button type="button" className={buttonClass('outline', 'md', 'self-start')} onClick={() => unpublish.mutate(undefined, { onSuccess: () => toast(t('packages.unpublished')) })}>
                  {t('packages.unpublish')}
                </button>
              ) : (
                <div className="flex flex-col gap-2">
                  <TextInput label={t('blog.scheduleFor')} type="datetime-local" value={schedule} onChange={setSchedule} hint={t('blog.scheduleHint')} />
                  <button type="button" className={buttonClass('success', 'md', 'self-start')} onClick={onPublish} aria-disabled={publish.isPending}>
                    {schedule ? t('blog.schedule') : t('packages.publish')}
                  </button>
                </div>
              )
            ) : (
              <p className="m-0 text-12 text-app-muted">{t('packages.saveToContinue')}</p>
            )}
          </Card>

          <Card>
            <CardTitle title="Details" />
            <SelectInput
              label={t('blog.category')}
              value={String(form.blog_category_id || '')}
              onChange={(value) => set('blog_category_id', Number(value))}
              error={error('blog_category_id')}
              options={[{ value: '', label: t('common.choose') }, ...(categories.data?.data ?? []).map((c) => ({ value: String(c.id), label: c.name_en || c.name_bn }))]}
            />
            <TextInput label={t('fields.slug')} value={form.slug} onChange={(value) => set('slug', value)} error={error('slug')} hint={<span className="break-all">{websiteUrl}</span>} />
            <Pair>
              <TextInput label={t('blog.authorBn')} value={form.author_bn} onChange={(value) => set('author_bn', value)} />
              <TextInput label={t('blog.authorEn')} value={form.author_en} onChange={(value) => set('author_en', value)} />
            </Pair>
            <NumberInput label={t('blog.readingMinutes')} value={form.reading_minutes} onChange={(value) => set('reading_minutes', value)} error={error('reading_minutes')} hint={t('blog.readingMinutesHint', { n: post?.reading_minutes ?? '—' })} />
          </Card>

          <Card>
            <CardTitle title="Cover image" />
            {cover ? <MediaThumb media={cover} className="aspect-4/3 w-full rounded-13" /> : <p className="m-0 text-13 text-app-muted">{t('blog.noCover')}</p>}
            <div className="flex gap-2">
              <button type="button" className={buttonClass('primary', 'sm')} onClick={() => setPicking(true)}>{cover ? t('common.change') : t('common.choose')}</button>
              {cover ? <button type="button" className={buttonClass('outline', 'sm')} onClick={() => { setCover(null); set('cover_media_id', null) }}>{t('common.remove')}</button> : null}
            </div>
            <MediaPicker open={picking} onClose={() => setPicking(false)} onPick={(media) => { setCover(media); set('cover_media_id', media.id) }} />
          </Card>

          {post ? <button type="button" className={buttonClass('danger', 'md', 'self-start')} onClick={() => void onDelete()}>{t('blog.delete')}</button> : null}
        </aside>
      </div>
      {blocker.state === 'blocked' ? <UnsavedChangesPrompt onStay={() => blocker.reset()} onLeave={() => blocker.proceed()} /> : null}
      {confirmDialog}
    </>
  )
}
