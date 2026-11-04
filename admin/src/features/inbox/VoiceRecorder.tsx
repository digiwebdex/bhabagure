import { useEffect, useRef, useState, type SyntheticEvent } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { ErrorNotice } from '../../components/ui/feedback'
import { ApiError } from '../../lib/api/client'
import { useSendAudio } from './api'

/** The longest voice note, as voice_note_max_seconds in api/config/bhabaghure.php: recording stops by itself there. */
const MAX_SECONDS = 300
/** Chrome and Edge record WebM, Firefox Ogg or WebM, Safari MP4. The API converts whichever comes for the chat's channel. */
const FORMATS = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4']
const BARS = 14

const clock = (seconds: number) => `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`

/** Which words to show for what getUserMedia refused. */
function micProblem(error: unknown) {
  const name = error instanceof DOMException ? error.name : ''
  if (name === 'NotAllowedError' || name === 'SecurityError') return 'micBlocked'
  if (name === 'NotFoundError' || name === 'OverconstrainedError') return 'micMissing'
  if (name === 'NotReadableError') return 'micBusy'
  return 'micFailed'
}

/**
 * A voice note in the reply box (docs/admin-inbox.md §9): record, stop, listen, then send. It goes from the company's
 * main number, so there is no sending without the chance to listen first. The microphone is released as soon as
 * recording ends, and also when staff cancel or leave the chat; a recording that wasn't sent is gone with it.
 */
export function VoiceRecorder({ conversationId, onClose }: { conversationId: number; onClose: () => void }) {
  const { t } = useTranslation()
  const send = useSendAudio(conversationId)
  const [seconds, setSeconds] = useState(0)
  const [listening, setListening] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const [recording, setRecording] = useState<{ blob: Blob; url: string } | null>(null)
  const recorder = useRef<MediaRecorder | null>(null)
  const bars = useRef<(HTMLSpanElement | null)[]>([])

  useEffect(() => {
    let left = false
    let stream: MediaStream | null = null
    let meter: AudioContext | null = null
    let frame = 0
    let timer = 0
    const chunks: Blob[] = []

    const release = () => {
      window.clearInterval(timer)
      cancelAnimationFrame(frame)
      stream?.getTracks().forEach((track) => track.stop())
      stream = null
      void meter?.close()
      meter = null
    }

    /** The level bars show the microphone is really hearing something. Only a help: recording goes on without them. */
    const startMeter = (source: MediaStream) => {
      try {
        meter = new AudioContext()
        void meter.resume()
        const analyser = meter.createAnalyser()
        analyser.fftSize = 64
        meter.createMediaStreamSource(source).connect(analyser)
        const levels = new Uint8Array(analyser.frequencyBinCount)
        const draw = () => {
          analyser.getByteFrequencyData(levels)
          bars.current.forEach((bar, index) => {
            if (bar) bar.style.transform = `scaleY(${Math.max(0.12, levels[index] / 255)})`
          })
          frame = requestAnimationFrame(draw)
        }
        draw()
      } catch {
        meter = null
      }
    }

    const start = async () => {
      try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true })
      } catch (error) {
        if (!left) setProblem(micProblem(error))
        return
      }
      // Left while the browser was asking for permission.
      if (left) return release()

      const mimeType = FORMATS.find((format) => MediaRecorder.isTypeSupported(format))
      const media = new MediaRecorder(stream, { ...(mimeType ? { mimeType } : {}), audioBitsPerSecond: 48_000 })
      recorder.current = media
      media.ondataavailable = (event) => {
        if (event.data.size > 0) chunks.push(event.data)
      }
      // Also how a recorder that failed, or a microphone pulled out, ends: staff hear what there is.
      media.onstop = () => {
        release()
        if (left) return
        if (chunks.length === 0) return setProblem('micFailed')
        const blob = new Blob(chunks, { type: media.mimeType })
        setRecording({ blob, url: URL.createObjectURL(blob) })
      }
      media.start(1000)
      setListening(true)
      startMeter(stream)
      const began = performance.now()
      timer = window.setInterval(() => {
        const elapsed = Math.floor((performance.now() - began) / 1000)
        setSeconds(Math.min(elapsed, MAX_SECONDS))
        if (elapsed >= MAX_SECONDS && media.state === 'recording') media.stop()
      }, 250)
    }
    void start()

    return () => {
      left = true
      if (recorder.current && recorder.current.state !== 'inactive') recorder.current.stop()
      else release()
    }
  }, [])

  useEffect(() => {
    return () => {
      if (recording) URL.revokeObjectURL(recording.url)
    }
  }, [recording])

  if (problem) {
    return (
      <div className="flex flex-wrap items-center gap-2" data-testid="voice-problem">
        <p role="alert" className="m-0 min-w-0 flex-1 text-13 text-red">
          {t(`inbox.voice.${problem}`)}
        </p>
        <button type="button" className={buttonClass('outline', 'sm')} onClick={onClose}>
          {t('inbox.voice.back')}
        </button>
      </div>
    )
  }

  if (!recording) {
    return (
      <div className="flex items-center gap-3" data-testid="voice-recorder">
        <button type="button" className={buttonClass('ghost', 'sm', 'shrink-0')} onClick={onClose}>
          {t('inbox.voice.cancel')}
        </button>
        {listening ? (
          <>
            <span className="flex shrink-0 items-center gap-2 text-14 font-semibold">
              <span aria-hidden className="size-2.5 rounded-pill bg-red motion-safe:animate-pulse" />
              <span role="timer" aria-label={t('inbox.voice.recording')} data-testid="voice-timer">
                {clock(seconds)}
              </span>
              <span className="text-12 font-normal text-app-muted">/ {clock(MAX_SECONDS)}</span>
            </span>
            <span aria-hidden className="flex h-7 min-w-0 flex-1 items-center gap-0.5 overflow-hidden">
              {Array.from({ length: BARS }, (_, index) => (
                <span
                  key={index}
                  ref={(bar) => {
                    bars.current[index] = bar
                  }}
                  className="h-full w-1 shrink-0 rounded-pill bg-green"
                  style={{ transform: 'scaleY(0.12)' }}
                />
              ))}
            </span>
            <button type="button" className={buttonClass('cta', 'md', 'shrink-0')} onClick={() => recorder.current?.stop()}>
              {t('inbox.voice.stop')}
            </button>
          </>
        ) : (
          <span className="text-13 text-app-muted">{t('inbox.voice.asking')}</span>
        )}
      </div>
    )
  }

  const submit = () => {
    if (send.isPending) return
    send.mutate(recording.blob, { onSuccess: onClose })
  }
  const fieldError = send.error instanceof ApiError ? (send.error.field('audio') ?? send.error.field('body')) : undefined

  return (
    <div className="flex flex-col gap-2" data-testid="voice-preview">
      <div className="flex flex-wrap items-center gap-2">
        <button type="button" className={buttonClass('ghost', 'sm', 'shrink-0')} onClick={onClose} aria-disabled={send.isPending}>
          {t('inbox.voice.discard')}
        </button>
        <audio controls src={recording.url} onLoadedMetadata={showLength} className="h-10 min-w-48 flex-1" data-testid="voice-player" />
        <button type="button" className={buttonClass('cta', 'md', 'ml-auto shrink-0')} onClick={submit} aria-disabled={send.isPending}>
          {send.isPending ? t('inbox.sending') : t('inbox.voice.send')}
        </button>
      </div>
      {fieldError ? (
        <p role="alert" className="m-0 text-12 text-red">
          {fieldError}
        </p>
      ) : send.error ? (
        <ErrorNotice error={send.error} />
      ) : null}
    </div>
  )
}

/** Chrome's recordings don't say how long they are, so its player shows no length until it has been to the end once. */
function showLength(event: SyntheticEvent<HTMLAudioElement>) {
  const audio = event.currentTarget
  if (Number.isFinite(audio.duration)) return
  audio.addEventListener(
    'timeupdate',
    () => {
      audio.currentTime = 0
    },
    { once: true },
  )
  audio.currentTime = 1e101
}
