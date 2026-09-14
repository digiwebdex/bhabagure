import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '../../../lib/api/client'
import type { BlogCategory, BlogPost, ContentStatus, Data } from '../../../lib/api/types'

export type PostForm = Pick<
  BlogPost,
  'slug' | 'blog_category_id' | 'title_bn' | 'title_en' | 'excerpt_bn' | 'excerpt_en' | 'author_bn' | 'author_en' | 'cover_media_id' | 'seo_title_bn' | 'seo_title_en' | 'seo_description_bn' | 'seo_description_en'
> & { body_bn: string | null; body_en: string | null; reading_minutes: number | null }

export const emptyPost = (): PostForm => ({
  slug: '',
  blog_category_id: 0,
  title_bn: '',
  title_en: '',
  excerpt_bn: '',
  excerpt_en: '',
  body_bn: '',
  body_en: '',
  author_bn: '',
  author_en: '',
  cover_media_id: null,
  reading_minutes: null,
  seo_title_bn: '',
  seo_title_en: '',
  seo_description_bn: '',
  seo_description_en: '',
})

export const toPostForm = (post: BlogPost): PostForm => ({
  slug: post.slug,
  blog_category_id: post.blog_category_id,
  title_bn: post.title_bn,
  title_en: post.title_en,
  excerpt_bn: post.excerpt_bn,
  excerpt_en: post.excerpt_en,
  body_bn: post.body_bn ?? '',
  body_en: post.body_en ?? '',
  author_bn: post.author_bn,
  author_en: post.author_en,
  cover_media_id: post.cover_media_id,
  // Only an editor-typed number is sent back; otherwise the API recomputes it from the body.
  reading_minutes: post.reading_minutes_override ? post.reading_minutes : null,
  seo_title_bn: post.seo_title_bn,
  seo_title_en: post.seo_title_en,
  seo_description_bn: post.seo_description_bn,
  seo_description_en: post.seo_description_en,
})

export const usePosts = (status: ContentStatus | 'all', category: string, search: string) =>
  useQuery({
    queryKey: ['posts', status, category, search],
    queryFn: ({ signal }) => api.get<Data<BlogPost[]>>(`admin/posts?${new URLSearchParams({ ...(status === 'all' ? {} : { status }), ...(category ? { category } : {}), ...(search ? { search } : {}) })}`, signal),
  })

export const usePost = (id: number | null) =>
  useQuery({ queryKey: ['post', id], queryFn: ({ signal }) => api.get<Data<BlogPost>>(`admin/posts/${id}`, signal), enabled: id !== null })

export const useCategories = () =>
  useQuery({ queryKey: ['blog-categories'], queryFn: ({ signal }) => api.get<Data<BlogCategory[]>>('admin/blog-categories', signal), staleTime: 5 * 60_000 })

export function usePostMutation<TVariables, TResult>(mutationFn: (variables: TVariables) => Promise<TResult>) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['posts'] })
      void queryClient.invalidateQueries({ queryKey: ['post'] })
      void queryClient.invalidateQueries({ queryKey: ['blog-categories'] })
    },
  })
}

export const postActions = {
  create: (form: PostForm) => api.post<Data<BlogPost>>('admin/posts', form),
  update: (id: number, form: PostForm) => api.put<Data<BlogPost>>(`admin/posts/${id}`, form),
  publish: (id: number, publishedAt: string | null) => api.post<Data<BlogPost>>(`admin/posts/${id}/publish`, publishedAt ? { published_at: publishedAt } : {}),
  unpublish: (id: number) => api.post<Data<BlogPost>>(`admin/posts/${id}/unpublish`),
  remove: (id: number) => api.delete(`admin/posts/${id}`),
}

/** Mirrors BlogPostController::publishProblems. */
export const postChecklist = (form: PostForm) =>
  [
    { key: 'bodyBn', done: !!form.body_bn?.trim() },
    { key: 'bodyEn', done: !!form.body_en?.trim() },
    { key: 'excerptBn', done: !!form.excerpt_bn?.trim() },
    { key: 'excerptEn', done: !!form.excerpt_en?.trim() },
  ] as const
