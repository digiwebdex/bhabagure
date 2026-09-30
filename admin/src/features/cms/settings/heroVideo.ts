import { api, ApiError, NetworkError, upload } from '../../../lib/api/client'
import type { Data } from '../../../lib/api/types'

/** The home page video as Site settings shows it (docs/hero-video.md). null: the one the website ships with. */
export type HeroVideo = { source: 'upload' | 'link'; videoUrl: string; posterUrl: string | null; name: string | null; bytes: number | null }

/** HeroVideo::MAX_BYTES and CHUNK_BYTES in the API. */
export type HeroVideoLimits = { maxBytes: number; chunkBytes: number }

export const HERO_VIDEO_TYPES = ['video/mp4', 'video/webm']

export const heroVideoApi = {
  get: (signal?: AbortSignal) => api.get<{ data: HeroVideo | null; meta: HeroVideoLimits }>('admin/settings/hero-video', signal),
  link: (url: string, poster: File | null) => {
    const form = new FormData()
    form.append('url', url)
    if (poster) form.append('poster', poster)
    return upload<Data<HeroVideo>>('admin/settings/hero-video/link', form, () => undefined)
  },
  restore: () => api.delete<null>('admin/settings/hero-video'),
}

/**
 * Sends the video in pieces of `chunkBytes` — each well inside the server's request limit — then puts it on the home
 * page with its poster. A piece lost to the network is sent again (the API never adds one twice); `onProgress` runs
 * from 0 to 1 over the whole file.
 */
export async function uploadHeroVideo(file: File, poster: Blob | null, limits: HeroVideoLimits, onProgress: (fraction: number) => void): Promise<HeroVideo> {
  const id = crypto.randomUUID()
  const pieces = Math.ceil(file.size / limits.chunkBytes)
  for (let index = 0; index < pieces; index++) {
    const form = new FormData()
    form.append('upload', id)
    form.append('index', String(index))
    form.append('size', String(file.size))
    form.append('type', file.type)
    form.append('name', file.name)
    form.append('chunk', file.slice(index * limits.chunkBytes, (index + 1) * limits.chunkBytes), 'chunk')
    await retrying(() => upload<Data<{ received: number }>>('admin/settings/hero-video/chunks', form, (fraction) => onProgress((index + fraction) / pieces)))
  }
  onProgress(1)
  const form = new FormData()
  form.append('upload', id)
  if (poster) form.append('poster', poster, 'poster.jpg')
  return (await upload<Data<HeroVideo>>('admin/settings/hero-video', form, () => undefined)).data
}

/** A dropped connection or a server hiccup is tried twice more; a refusal (the API said why) is not. */
async function retrying<T>(send: () => Promise<T>): Promise<T> {
  for (let attempt = 1; ; attempt++) {
    try {
      return await send()
    } catch (error) {
      const retriable = error instanceof NetworkError || (error instanceof ApiError && error.status >= 500)
      if (!retriable || attempt >= 3) throw error
      await new Promise((resolve) => setTimeout(resolve, 1500 * attempt))
    }
  }
}

/**
 * The poster: a frame one second in (halfway through a shorter clip), as a JPEG at most 1600 px wide, taken in this
 * browser. null when the browser can't decode the video (some can't play every MP4): the site then shows its dark
 * background until the video plays.
 */
export function capturePoster(file: File): Promise<Blob | null> {
  return new Promise((resolve) => {
    const url = URL.createObjectURL(file)
    const video = document.createElement('video')
    let settled = false
    const done = (blob: Blob | null) => {
      if (settled) return
      settled = true
      clearTimeout(timer)
      URL.revokeObjectURL(url)
      resolve(blob)
    }
    const timer = setTimeout(() => done(null), 15_000)
    video.muted = true
    video.playsInline = true
    video.preload = 'auto'
    video.addEventListener('error', () => done(null), { once: true })
    video.addEventListener('loadeddata', () => {
      video.currentTime = Math.min(1, (Number.isFinite(video.duration) ? video.duration : 2) / 2)
    }, { once: true })
    video.addEventListener('seeked', () => {
      const scale = Math.min(1, 1600 / Math.max(1, video.videoWidth))
      const canvas = document.createElement('canvas')
      canvas.width = Math.round(video.videoWidth * scale)
      canvas.height = Math.round(video.videoHeight * scale)
      const context = canvas.getContext('2d')
      if (!context || canvas.width === 0 || canvas.height === 0) return done(null)
      context.drawImage(video, 0, 0, canvas.width, canvas.height)
      canvas.toBlob((blob) => done(blob), 'image/jpeg', 0.82)
    }, { once: true })
    video.src = url
  })
}
