import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../../components/ui/button'
import { ErrorNotice, useConfirm, useToast } from '../../../components/ui/feedback'
import { TextInput } from '../../../components/ui/fields'
import { Card, CardTitle, Loading } from '../../../components/ui/layout'
import { ApiError } from '../../../lib/api/client'
import { useFormat } from '../../../lib/useFormat'
import { fileSize } from '../../vouchers/api'
import { capturePoster, HERO_VIDEO_TYPES, heroVideoApi, uploadHeroVideo, type HeroVideo } from './heroVideo'

const SITE_URL = (import.meta.env.VITE_SITE_URL ?? '').replace(/\/$/, '')

/** The video the website ships with (web/public/media), shown here while nothing else is set. */
const ORIGINAL = { videoUrl: `${SITE_URL}/media/hero.mp4`, posterUrl: `${SITE_URL}/media/hero-poster.jpg` }

/**
 * Site settings → Home page video (client, 2026-10-01; docs/hero-video.md): upload an MP4 or WebM, or give a direct
 * link to one. The website plays it muted and looping behind the headline, as it did the original, which "Use the
 * original video" brings back. Only the video in use is kept on the server.
 */
export function HeroVideoCard() {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { confirm, element: confirmDialog } = useConfirm()
  const hero = useQuery({ queryKey: ['hero-video'], queryFn: ({ signal }) => heroVideoApi.get(signal) })
  const [mode, setMode] = useState<'upload' | 'link'>('upload')
  const [restoring, setRestoring] = useState(false)
  const [restoreError, setRestoreError] = useState<unknown>(null)

  const updated = (next: HeroVideo | null, message: string) => {
    queryClient.setQueryData(['hero-video'], (old: { data: HeroVideo | null; meta: unknown } | undefined) => (old ? { ...old, data: next } : old))
    void queryClient.invalidateQueries({ queryKey: ['hero-video'] })
    toast(message)
  }

  const restore = async () => {
    if (!(await confirm(t('settings.hero.restoreConfirm')))) return
    setRestoring(true)
    setRestoreError(null)
    try {
      await heroVideoApi.restore()
      updated(null, t('settings.hero.restored'))
    } catch (error) {
      setRestoreError(error)
    } finally {
      setRestoring(false)
    }
  }

  if (hero.isPending) return <Card><Loading /></Card>
  if (hero.isError) return <Card><ErrorNotice error={hero.error} /></Card>
  const current = hero.data.data
  const limits = hero.data.meta
  const playing = current ?? ORIGINAL

  return (
    <Card>
      <CardTitle title={t('settings.hero.title')} />
      <div className="flex flex-col gap-2" data-testid="hero-current">
        <span className="font-display text-11 tracking-eyebrow text-app-muted uppercase">{t('settings.hero.nowPlaying')}</span>
        <video key={playing.videoUrl} src={playing.videoUrl} poster={playing.posterUrl ?? undefined} muted loop autoPlay playsInline className="aspect-video w-full rounded-12 bg-blue-ink object-cover" />
        <span className="text-13 text-app-muted">
          {current === null
            ? t('settings.hero.original')
            : current.source === 'upload'
              ? `${current.name ?? t('settings.hero.uploaded')}${current.bytes ? ` · ${fileSize(current.bytes)}` : ''}`
              : t('settings.hero.linked', { url: current.videoUrl })}
        </span>
        {current !== null ? (
          <button type="button" className={buttonClass('outline', 'sm', 'self-start')} aria-disabled={restoring} onClick={() => !restoring && void restore()}>
            {restoring ? t('common.working') : t('settings.hero.restore')}
          </button>
        ) : null}
        <ErrorNotice error={restoreError} />
      </div>

      <div className="flex gap-1 rounded-10 bg-app-surface-2 p-1" role="tablist" aria-label={t('settings.hero.replace')}>
        {(['upload', 'link'] as const).map((option) => (
          <button
            key={option}
            type="button"
            role="tab"
            aria-selected={mode === option}
            onClick={() => setMode(option)}
            className={`flex-1 cursor-pointer rounded-8 border-0 px-3 py-1.5 text-13 font-semibold ${mode === option ? 'bg-app-surface text-app-text shadow-sm' : 'bg-transparent text-app-muted'}`}
          >
            {t(`settings.hero.${option}Tab`)}
          </button>
        ))}
      </div>

      {mode === 'upload' ? (
        <UploadForm maxBytes={limits.maxBytes} onDone={(next) => updated(next, t('settings.hero.saved'))} upload={(file, poster, onProgress) => uploadHeroVideo(file, poster, limits, onProgress)} />
      ) : (
        <LinkForm onDone={(next) => updated(next, t('settings.hero.saved'))} />
      )}
      {confirmDialog}
    </Card>
  )
}

/** Choose a file: it is checked, previewed and its poster taken here, before anything is sent. */
function UploadForm({ maxBytes, upload, onDone }: {
  maxBytes: number
  upload: (file: File, poster: Blob | null, onProgress: (fraction: number) => void) => Promise<HeroVideo>
  onDone: (next: HeroVideo) => void
}) {
  const { t } = useTranslation()
  const { number } = useFormat()
  const [file, setFile] = useState<File | null>(null)
  const [preview, setPreview] = useState<string | null>(null)
  const [poster, setPoster] = useState<{ blob: Blob | null; url: string | null } | null>(null)
  const [problem, setProblem] = useState<string | null>(null)
  const [progress, setProgress] = useState<number | null>(null)
  const [error, setError] = useState<unknown>(null)

  useEffect(() => () => {
    if (preview) URL.revokeObjectURL(preview)
  }, [preview])
  useEffect(() => () => {
    if (poster?.url) URL.revokeObjectURL(poster.url)
  }, [poster])

  const choose = (chosen: File | null) => {
    setError(null)
    setPoster(null)
    setPreview(null)
    setFile(null)
    if (!chosen) return
    if (!HERO_VIDEO_TYPES.includes(chosen.type)) return setProblem(t('settings.hero.wrongType'))
    if (chosen.size > maxBytes) return setProblem(t('settings.hero.tooBig', { size: fileSize(chosen.size), max: fileSize(maxBytes) }))
    setProblem(null)
    setFile(chosen)
    setPreview(URL.createObjectURL(chosen))
    void capturePoster(chosen).then((blob) => setPoster({ blob, url: blob ? URL.createObjectURL(blob) : null }))
  }

  const send = async () => {
    if (!file || progress !== null || poster === null) return
    setError(null)
    setProgress(0)
    try {
      onDone(await upload(file, poster.blob, setProgress))
      choose(null)
    } catch (caught) {
      setError(caught)
    } finally {
      setProgress(null)
    }
  }

  return (
    <div className="flex flex-col gap-3" data-testid="hero-upload">
      <label className="flex flex-col gap-1.25 text-13">
        <span className="text-app-muted">{t('settings.hero.file')}</span>
        <input type="file" accept={HERO_VIDEO_TYPES.join(',')} disabled={progress !== null} onChange={(event) => choose(event.target.files?.[0] ?? null)} />
        <span className="text-12 text-app-muted">{t('settings.hero.fileHint', { max: fileSize(maxBytes) })}</span>
      </label>
      {problem ? <p role="alert" className="m-0 text-13 font-semibold text-red">{problem}</p> : null}

      {file && preview ? (
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="flex flex-col gap-1">
            <span className="text-12 text-app-muted">{`${file.name} · ${fileSize(file.size)}`}</span>
            <video src={preview} muted loop autoPlay playsInline className="aspect-video w-full rounded-10 bg-blue-ink object-cover" />
          </div>
          <div className="flex flex-col gap-1">
            <span className="text-12 text-app-muted">{t('settings.hero.poster')}</span>
            {poster === null ? (
              <span className="text-12 text-app-muted">{t('settings.hero.takingPoster')}</span>
            ) : poster.url ? (
              <img src={poster.url} alt={t('settings.hero.poster')} className="aspect-video w-full rounded-10 object-cover" />
            ) : (
              <span className="text-12 text-app-muted">{t('settings.hero.noPoster')}</span>
            )}
          </div>
        </div>
      ) : null}

      {progress !== null ? (
        <div className="flex flex-col gap-1 text-13" role="status">
          <span>{t('settings.hero.uploading', { percent: number(Math.round(progress * 100)) })}</span>
          <span className="h-1.5 overflow-hidden rounded-3 bg-app-line">
            <span className="block h-full bg-blue transition-all" style={{ width: `${Math.round(progress * 100)}%` }} />
          </span>
        </div>
      ) : null}
      <ErrorNotice error={error} />
      {file ? (
        <button type="button" className={buttonClass('primary', 'md', 'self-start')} aria-disabled={progress !== null || poster === null} onClick={() => void send()}>
          {progress !== null ? t('common.working') : t('settings.hero.useUpload')}
        </button>
      ) : null}
    </div>
  )
}

/** A direct https link to the video file. The preview says whether it plays here; the API refuses video pages. */
function LinkForm({ onDone }: { onDone: (next: HeroVideo) => void }) {
  const { t } = useTranslation()
  const [url, setUrl] = useState('')
  const [plays, setPlays] = useState<'checking' | 'yes' | 'no'>('checking')
  const [poster, setPoster] = useState<File | null>(null)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<unknown>(null)
  const link = url.trim()
  const valid = /^https:\/\/\S+$/.test(link)

  const save = async () => {
    if (!valid || saving) return
    setSaving(true)
    setError(null)
    try {
      onDone((await heroVideoApi.link(link, poster)).data)
      setUrl('')
      setPoster(null)
    } catch (caught) {
      setError(caught)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="flex flex-col gap-3" data-testid="hero-link">
      <TextInput
        label={t('settings.hero.url')}
        value={url}
        onChange={(value) => {
          setUrl(value)
          setPlays('checking')
        }}
        placeholder="https://…/video.mp4"
        hint={t('settings.hero.urlHint')}
        error={error instanceof ApiError ? error.field('url') : undefined}
      />
      {valid ? (
        <div className="flex flex-col gap-1">
          <video key={link} src={link} muted playsInline preload="metadata" onLoadedData={() => setPlays('yes')} onError={() => setPlays('no')} className="aspect-video w-full rounded-10 bg-blue-ink object-cover" />
          <span className={`text-12 ${plays === 'no' ? 'font-semibold text-red' : 'text-app-muted'}`} role="status">
            {plays === 'yes' ? t('settings.hero.plays') : plays === 'no' ? t('settings.hero.doesNotPlay') : t('settings.hero.checking')}
          </span>
        </div>
      ) : null}
      <label className="flex flex-col gap-1.25 text-13">
        <span className="text-app-muted">{t('settings.hero.linkPoster')}</span>
        <input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => setPoster(event.target.files?.[0] ?? null)} />
      </label>
      <ErrorNotice error={error instanceof ApiError && error.status === 422 && error.field('url') ? null : error} />
      <button type="button" className={buttonClass('primary', 'md', 'self-start')} aria-disabled={!valid || saving} onClick={() => void save()}>
        {saving ? t('common.working') : t('settings.hero.useLink')}
      </button>
    </div>
  )
}
