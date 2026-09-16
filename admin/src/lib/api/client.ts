
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
export async function fetchDocument(path: string, retry = true): Promise<Blob> {
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
    if (await refreshSession()) return fetchDocument(path, false)
    onSessionEnded()
  }
  if (!response.ok) {
    const text = await response.text()
    throw new ApiError(response.status, text.startsWith('{') ? JSON.parse(text) : null)
  }
  return response.blob()
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
