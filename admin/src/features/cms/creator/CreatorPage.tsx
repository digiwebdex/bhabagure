import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../../components/ui/button'
import { ErrorNotice, useToast } from '../../../components/ui/feedback'
import { NumberInput, Pair, TextArea, TextInput } from '../../../components/ui/fields'
import { Card, CardTitle, Loading } from '../../../components/ui/layout'
import { api, ApiError } from '../../../lib/api/client'
import type { CreatorProfile, CreatorVideo, Data, Media } from '../../../lib/api/types'
import { ImageField } from '../lists/ImageField'
import { PublishedList } from '../lists/PublishedList'

type VideoForm = { url: string; title_bn: string; title_en: string }

/** The same links the API accepts (CreatorVideo::URL), only to show the thumbnail while the link is typed. */
const youtubeId = (url: string) => /^https:\/\/(?:(?:www\.|m\.)?youtube\.com\/(?:watch\?(?:[^#]*&)?v=|shorts\/|live\/|embed\/)|youtu\.be\/)([A-Za-z0-9_-]{11})(?:[?&#/].*)?$/.exec(url.trim())?.[1]

/**
 * Travel host (docs/travel-host.md): the profile behind the home page's Facebook and YouTube cards, and below it the
 * videos shown under them, published and ordered like every other website list.
 */
export function CreatorPage() {
  const { t } = useTranslation()

  return (
    <PublishedList<CreatorVideo, VideoForm>
      resource="creator-videos"
      title={t('creator.title')}
      subtitle={t('creator.subtitle')}
      newLabel={t('creator.newVideo')}
      emptyTitle={t('creator.emptyVideos')}
      emptyNote={t('creator.emptyVideosNote')}
      note={
        <>
          <ProfileCard />
          <div className="flex flex-col gap-1">
            <h2 className="m-0 text-16 font-semibold">{t('creator.videosTitle')}</h2>
            <p className="m-0 text-13 text-app-muted">{t('creator.videosNote')}</p>
          </div>
        </>
      }
      row={(video) => ({
        label: video.title_en || video.title_bn || video.url,
        content: (
          <>
            <img src={video.thumbnail_url} alt="" loading="lazy" className="aspect-video w-24 shrink-0 rounded-8 bg-app-surface-2 object-cover" />
            <span className="flex min-w-0 flex-col gap-0.5">
              <span className="truncate text-14 font-medium">{video.title_en || video.title_bn}</span>
              {video.title_en && video.title_bn ? <span className="truncate text-12 text-app-muted">{video.title_bn}</span> : null}
              <a href={video.url} target="_blank" rel="noopener noreferrer" className="text-12">
                youtu.be/{video.youtube_id} ↗
              </a>
            </span>
          </>
        ),
      })}
      toForm={(video) => ({ url: video?.url ?? '', title_bn: video?.title_bn ?? '', title_en: video?.title_en ?? '' })}
      renderForm={({ form, set, error }) => {
        const id = youtubeId(form.url)
        return (
          <>
            <TextInput label={t('creator.videoUrl')} type="url" value={form.url} onChange={(value) => set('url', value)} error={error('url')} placeholder="https://youtu.be/…" hint={t('creator.videoUrlHint')} />
            {id ? <img src={`https://i.ytimg.com/vi/${id}/hqdefault.jpg`} alt="" className="aspect-video w-48 rounded-10 object-cover" data-testid="video-preview" /> : null}
            <Pair>
              <TextInput label={t('creator.titleBn')} value={form.title_bn} onChange={(value) => set('title_bn', value)} error={error('title_bn')} hint={t('creator.titleHint')} />
              <TextInput label={t('creator.titleEn')} value={form.title_en} onChange={(value) => set('title_en', value)} error={error('title_en')} />
            </Pair>
          </>
        )
      }}
    />
  )
}

function ProfileCard() {
  const profile = useQuery({ queryKey: ['creator'], queryFn: ({ signal }) => api.get<Data<CreatorProfile>>('admin/creator', signal) })

  if (profile.isPending) return <Loading />
  if (profile.isError) return <ErrorNotice error={profile.error} />
  // Remounted after each save, so the form starts again from what was stored.
  return <ProfileForm key={profile.dataUpdatedAt} initial={profile.data.data} />
}

function ProfileForm({ initial }: { initial: CreatorProfile }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [form, setForm] = useState(initial)
  const set = <K extends keyof CreatorProfile>(key: K, value: CreatorProfile[K]) => setForm((current) => ({ ...current, [key]: value }))
  const photo = (key: 'facebook_photo' | 'facebook_cover' | 'youtube_photo' | 'youtube_cover') => (media: Media | null) =>
    setForm((current) => ({ ...current, [key]: media, [`${key}_media_id`]: media?.id ?? null }))
  const dirty = JSON.stringify(form) !== JSON.stringify(initial)
  const save = useMutation({
    mutationFn: () => api.put<Data<CreatorProfile>>('admin/creator', form),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['creator'] })
      toast(t('common.saved'))
    },
  })
  const error = (field: string) => (save.error instanceof ApiError ? save.error.field(field) : undefined)

  return (
    <Card>
      <CardTitle title={t('creator.profile')} />
      <p className="m-0 text-13 text-app-muted">{t('creator.profileNote')}</p>
      <Pair>
        <TextInput label={t('creator.nameBn')} value={form.name_bn} onChange={(value) => set('name_bn', value)} error={error('name_bn')} />
        <TextInput label={t('creator.nameEn')} value={form.name_en} onChange={(value) => set('name_en', value)} error={error('name_en')} />
      </Pair>
      <Pair>
        <TextArea label={t('creator.bioBn')} value={form.bio_bn} onChange={(value) => set('bio_bn', value)} error={error('bio_bn')} hint={t('creator.bioHint')} />
        <TextArea label={t('creator.bioEn')} value={form.bio_en} onChange={(value) => set('bio_en', value)} error={error('bio_en')} />
      </Pair>

      <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
        <fieldset className="m-0 flex min-w-0 flex-col gap-3 rounded-12 border border-app-line p-3.5">
          <legend className="px-1 text-14 font-semibold">{t('creator.facebook')}</legend>
          <TextInput label={t('creator.facebookUrl')} type="url" value={form.facebook_url} onChange={(value) => set('facebook_url', value)} error={error('facebook_url')} placeholder="https://www.facebook.com/…" />
          <NumberInput label={t('creator.followers')} value={form.facebook_followers} onChange={(value) => set('facebook_followers', value)} error={error('facebook_followers')} hint={t('creator.countHint')} />
          <ImageField label={t('creator.photo')} square media={form.facebook_photo} error={error('facebook_photo_media_id')} onChange={photo('facebook_photo')} />
          <ImageField label={t('creator.cover')} media={form.facebook_cover} error={error('facebook_cover_media_id')} onChange={photo('facebook_cover')} />
        </fieldset>

        <fieldset className="m-0 flex min-w-0 flex-col gap-3 rounded-12 border border-app-line p-3.5">
          <legend className="px-1 text-14 font-semibold">{t('creator.youtube')}</legend>
          <TextInput label={t('creator.youtubeUrl')} type="url" value={form.youtube_url} onChange={(value) => set('youtube_url', value)} error={error('youtube_url')} placeholder="https://www.youtube.com/@…" />
          <Pair>
            <NumberInput label={t('creator.subscribers')} value={form.youtube_subscribers} onChange={(value) => set('youtube_subscribers', value)} error={error('youtube_subscribers')} hint={t('creator.countHint')} />
            <NumberInput label={t('creator.videoCount')} value={form.youtube_video_count} onChange={(value) => set('youtube_video_count', value)} error={error('youtube_video_count')} />
          </Pair>
          <ImageField label={t('creator.photo')} square media={form.youtube_photo} error={error('youtube_photo_media_id')} onChange={photo('youtube_photo')} />
          <ImageField label={t('creator.cover')} media={form.youtube_cover} error={error('youtube_cover_media_id')} onChange={photo('youtube_cover')} />
        </fieldset>
      </div>

      <ErrorNotice error={save.error instanceof ApiError && save.error.status === 422 ? null : save.error} />
      <button type="button" className={buttonClass(dirty ? 'primary' : 'outline', 'md', 'self-start')} onClick={() => dirty && !save.isPending && save.mutate()} aria-disabled={save.isPending || !dirty}>
        {save.isPending ? t('common.saving') : dirty ? t('common.save') : t('common.saved')}
      </button>
    </Card>
  )
}
