
/**
 * The staff API client. The access token lives in memory only (never localStorage, where any injected script
 * could read it); the refresh token is an httpOnly cookie the browser sends to /api/v1/staff/auth/* by itself.
 * A 401 triggers one refresh and one retry; if that fails the session has ended.
 */

const API_URL = (import.meta.env.VITE_API_URL ?? '').replace(/\/$/, '')

export type ValidationErrors = Record<string, string[]>

export class ApiError extends Error {
  readonly status: number
  readonly code?: string
  readonly errors: ValidationErrors
  /** Publish checklist: what is still missing. */
  readonly problems: string[]

  constructor(status: number, body: { message?: string; code?: string; errors?: ValidationErrors; problems?: string[] } | null) {
    super(body?.message ?? `Request failed (${status})`)
    this.status = status
    this.code = body?.code
    this.errors = body?.errors ?? {}
    this.problems = body?.problems ?? []
  }

  /** First message for a field, for inline errors. Accepts dotted paths such as `itinerary.0.body_en`. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0]
  }
}

export class NetworkError extends Error {}

let accessToken: string | null = null
let refreshing: Promise<boolean> | null = null
let onSessionEnded: () => void = () => {}

export function setAccessToken(token: string | null) {
  accessToken = token
}

export function onSessionEnd(callback: () => void) {
  onSessionEnded = callback
}

type Options = { method?: string; body?: unknown; signal?: AbortSignal; retry?: boolean }

export async function request<T>(path: string, { method = 'GET', body, signal, retry = true }: Options = {}): Promise<T> {
  const isForm = body instanceof FormData
  let response: Response
  try {
    response = await fetch(`${API_URL}/api/v1/${path.replace(/^\//, '')}`, {
      method,
      signal,
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'X-Locale': 'en',
        ...(isForm || body === undefined ? {} : { 'Content-Type': 'application/json' }),
        ...(accessToken ? { Authorization: `Bearer ${accessToken}` } : {}),
      },
      body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
    })
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') throw error
    throw new NetworkError('Network unavailable')
  }

  const isAuthCall = path.startsWith('staff/auth/')
  if (response.status === 401 && retry && !isAuthCall) {
    if (await refreshSession()) return request<T>(path, { method, body, signal, retry: false })
    onSessionEnded()
  }

  const text = await response.text()
  const json = text ? (JSON.parse(text) as unknown) : null
  if (!response.ok) throw new ApiError(response.status, json as ConstructorParameters<typeof ApiError>[1])

  return json as T
}

/** One refresh at a time, however many requests hit a 401 together. */
export function refreshSession(): Promise<boolean> {
  refreshing ??= (async () => {
    try {
      const data = await request<{ access_token: string }>('staff/auth/refresh', { method: 'POST', retry: false })
      setAccessToken(data.access_token)
      return true
    } catch {
      setAccessToken(null)
      return false
    } finally {
      refreshing = null
    }
  })()
  return refreshing
}

export const api = {
  get: <T>(path: string, signal?: AbortSignal) => request<T>(path, { signal }),
  post: <T>(path: string, body?: unknown) => request<T>(path, { method: 'POST', body }),
  put: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PUT', body }),
  patch: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PATCH', body }),
  delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
}

/**
 * A non-JSON response (invoice print HTML, PDF) with the same auth and refresh rules. The token can't ride along on an
 * <iframe src> or a link, so the page fetches the document and shows it from memory.
 */
export async function fetchDocument(path: string): Promise<Blob> {
  return (await fetchNamedDocument(path)).blob
}

/** The same, with the file name the API sends it under (Content-Disposition), when it gives one. */
export async function fetchNamedDocument(path: string, retry = true): Promise<{ blob: Blob; filename: string | null }> {
  let response: Response
  try {
    response = await fetch(`${API_URL}/api/v1/${path.replace(/^\//, '')}`, {
      credentials: 'include',
      headers: { 'X-Locale': 'en', ...(accessToken ? { Authorization: `Bearer ${accessToken}` } : {}) },
    })
  } catch {
    throw new NetworkError('Network unavailable')
  }
  if (response.status === 401 && retry) {
    if (await refreshSession()) return fetchNamedDocument(path, false)
    onSessionEnded()
  }
  if (!response.ok) {
    const text = await response.text()
    throw new ApiError(response.status, text.startsWith('{') ? JSON.parse(text) : null)
  }
  return { blob: await response.blob(), filename: dispositionFilename(response.headers.get('Content-Disposition')) }
}

/**
 * Saves a document from the API under its own name — an invoice as INV-1065.pdf, as the API names it — rather than
 * opening it in a tab, where the browser can only name it after the blob's random id (client, 2026-10-01).
 * `fallback` is used if the name can't be read.
 */
export async function downloadDocument(path: string, fallback: string): Promise<void> {
  const { blob, filename } = await fetchNamedDocument(path)
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename ?? fallback
  document.body.append(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 60_000)
}

/** `attachment; filename="INV-1065.pdf"` → INV-1065.pdf; the RFC 5987 `filename*=UTF-8''…` form wins when both are sent. */
export function dispositionFilename(header: string | null): string | null {
  if (!header) return null
  const encoded = /filename\*\s*=\s*UTF-8''([^;]+)/i.exec(header)
  if (encoded) {
    try {
      return decodeURIComponent(encoded[1].trim())
    } catch {
      // Malformed: fall back to the plain name.
    }
  }
  const plain = /filename\s*=\s*(?:"([^"]*)"|([^;]+))/i.exec(header)
  const name = (plain?.[1] ?? plain?.[2] ?? '').trim()
  return name === '' ? null : name
}

/** Upload with progress (fetch can't report upload progress). Same auth and refresh rules as request(). */
export function upload<T>(path: string, form: FormData, onProgress: (fraction: number) => void, retry = true): Promise<T> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    xhr.open('POST', `${API_URL}/api/v1/${path}`)
    xhr.withCredentials = true
    xhr.setRequestHeader('Accept', 'application/json')
    xhr.setRequestHeader('X-Locale', 'en')
    if (accessToken) xhr.setRequestHeader('Authorization', `Bearer ${accessToken}`)
    xhr.upload.onprogress = (event) => event.lengthComputable && onProgress(event.loaded / event.total)
    xhr.onerror = () => reject(new NetworkError('Network unavailable'))
    xhr.onload = async () => {
      if (xhr.status === 401 && retry) {
        if (await refreshSession()) return upload<T>(path, form, onProgress, false).then(resolve, reject)
        onSessionEnded()
      }
      const json = xhr.responseText ? JSON.parse(xhr.responseText) : null
      if (xhr.status >= 200 && xhr.status < 300) resolve(json as T)
      else reject(new ApiError(xhr.status, json))
    }
    xhr.send(form)
  })
}
