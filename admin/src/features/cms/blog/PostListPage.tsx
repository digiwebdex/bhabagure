import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { buttonClass } from '../../../components/ui/button'
import { Dialog, ErrorNotice, useConfirm, useToast } from '../../../components/ui/feedback'
import { Pair, SelectInput, TextInput } from '../../../components/ui/fields'
import { controlClass } from '../../../components/ui/controls'
import { Card, CardTitle, Chips, EmptyState, Loading, PageHeader, StatusBadge } from '../../../components/ui/layout'
import { api, ApiError } from '../../../lib/api/client'
import type { BlogCategory, ContentStatus } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'
import { MediaThumb } from '../media/media'
import { slugify } from '../packages/api'
import { useCategories, usePosts } from './api'
import { toneClass } from './tone'

export function PostListPage() {
  const { t } = useTranslation()
  const { date } = useFormat()
  const [status, setStatus] = useState<ContentStatus | 'all'>('all')
  const [category, setCategory] = useState('')
  const [search, setSearch] = useState('')
  const posts = usePosts(status, category, search)
  const categories = useCategories()

  return (
    <>
      <PageHeader title={t('blog.title')} subtitle={t('blog.subtitle')} actions={<Link to="/posts/new" className={buttonClass('cta')}>{t('blog.new')}</Link>} />
      <div className="flex flex-col gap-2.5">
        <Chips label={t('common.status')} value={status} onChange={setStatus} options={[{ value: 'all', label: t('common.all') }, { value: 'published', label: t('status.published') }, { value: 'draft', label: t('status.draft') }]} />
        {categories.data ? (
          <Chips label={t('blog.category')} value={category} onChange={setCategory} options={[{ value: '', label: t('blog.allCategories') }, ...categories.data.data.map((c) => ({ value: c.slug, label: c.name_en || c.name_bn }))]} />
        ) : null}
      </div>
      <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
        <Card padded={false}>
          <div className="border-b border-app-line p-3.5">
            <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('blog.search')} aria-label={t('blog.search')} className={controlClass()} />
          </div>
          {posts.isPending ? <Loading /> : posts.isError ? <div className="p-4"><ErrorNotice error={posts.error} /></div> : posts.data.data.length === 0 ? (
            <EmptyState title={t('blog.empty')} action={<Link to="/posts/new" className={buttonClass('primary', 'sm')}>{t('blog.new')}</Link>} />
          ) : (
            <ul className="m-0 list-none p-0">
              {posts.data.data.map((post) => (
                <li key={post.id} className="border-b border-app-line last:border-b-0">
                  <Link to={`/posts/${post.id}`} className="flex items-center gap-3 px-4 py-3 text-app-text hover:bg-app-surface-2 hover:text-app-text">
                    <MediaThumb media={post.cover} className="size-14" />
                    <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                      <span className="flex flex-wrap items-center gap-2">
                        {post.category ? <span className={`rounded-pill px-2 py-0.5 font-display text-11 font-extrabold uppercase ${toneClass[post.category.tone]}`}>{post.category.name_en || post.category.name_bn}</span> : null}
                        {post.published_at ? <span className="text-12 text-app-muted">{date(post.published_at)}</span> : null}
                      </span>
                      <span className="truncate text-14 font-medium">{post.title_en || post.title_bn}</span>
                    </span>
                    <StatusBadge status={post.is_scheduled ? 'scheduled' : post.status} />
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Card>
        <CategoriesCard />
      </div>
    </>
  )
}

function CategoriesCard() {
  const { t } = useTranslation()
  const { number } = useFormat()
  const categories = useCategories()
  const [editing, setEditing] = useState<BlogCategory | 'new' | null>(null)

  return (
    <Card>
      <CardTitle title="Categories" aside={<button type="button" className={buttonClass('outline', 'sm')} onClick={() => setEditing('new')}>{t('common.add')}</button>} />
      {categories.isPending ? <Loading /> : categories.isError ? <ErrorNotice error={categories.error} /> : (
        <ul className="m-0 flex list-none flex-col gap-1.5 p-0">
          {categories.data.data.map((category) => (
            <li key={category.id}>
              <button type="button" onClick={() => setEditing(category)} className="flex w-full cursor-pointer items-center justify-between gap-3 rounded-10 border-0 bg-app-surface-2 px-3 py-2.5 text-left text-14 text-app-text">
                <span className={`rounded-pill px-2 py-0.5 font-display text-11 font-extrabold uppercase ${toneClass[category.tone]}`}>{category.name_en || category.name_bn}</span>
                <span className="text-12 text-app-muted">{t('blog.postCount', { count: category.posts_count ?? 0, n: number(category.posts_count ?? 0) })}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
      {editing ? <CategoryDialog category={editing === 'new' ? null : editing} onClose={() => setEditing(null)} /> : null}
    </Card>
  )
}

function CategoryDialog({ category, onClose }: { category: BlogCategory | null; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { confirm, element: confirmDialog } = useConfirm()
  const [form, setForm] = useState({ slug: category?.slug ?? '', name_bn: category?.name_bn ?? '', name_en: category?.name_en ?? '', tone: category?.tone ?? 'blue' })
  const done = (message: string) => {
    void queryClient.invalidateQueries({ queryKey: ['blog-categories'] })
    toast(message)
    onClose()
  }
  const save = useMutation({ mutationFn: () => (category ? api.put(`admin/blog-categories/${category.id}`, form) : api.post('admin/blog-categories', form)), onSuccess: () => done(t('common.saved')) })
  const remove = useMutation({ mutationFn: () => api.delete(`admin/blog-categories/${category!.id}`), onSuccess: () => done(t('common.deleted')) })
  const error = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)

  return (
    <Dialog open onClose={onClose} title={category ? t('blog.editCategory') : t('blog.newCategory')}>
      <Pair>
        <TextInput label={t('fields.nameBn')} value={form.name_bn} onChange={(name_bn) => setForm({ ...form, name_bn })} error={error('name_bn')} />
        <TextInput label={t('fields.nameEn')} value={form.name_en} onChange={(name_en) => setForm((current) => ({ ...current, name_en, slug: category ? current.slug : slugify(name_en) }))} error={error('name_en')} />
      </Pair>
      <Pair>
        <TextInput label={t('fields.slug')} value={form.slug} onChange={(slug) => setForm({ ...form, slug })} error={error('slug')} />
        <SelectInput label={t('blog.tone')} value={form.tone} onChange={(tone) => setForm({ ...form, tone: tone as BlogCategory['tone'] })} options={(['blue', 'purple', 'orange'] as const).map((value) => ({ value, label: t(`blog.tones.${value}`) }))} />
      </Pair>
      <ErrorNotice error={remove.error ?? (save.error instanceof ApiError && save.error.status === 422 ? null : save.error)} />
      <div className="flex flex-wrap justify-between gap-2">
        {category ? <button type="button" className={buttonClass('danger')} onClick={async () => (await confirm(t('blog.confirmDeleteCategory'))) && remove.mutate()}>{t('common.delete')}</button> : <span />}
        <span className="flex gap-2">
          <button type="button" className={buttonClass('outline')} onClick={onClose}>{t('common.cancel')}</button>
          <button type="button" className={buttonClass('primary')} onClick={() => save.mutate()} aria-disabled={save.isPending}>{save.isPending ? t('common.saving') : t('common.save')}</button>
        </span>
      </div>
      {confirmDialog}
    </Dialog>
  )
}
