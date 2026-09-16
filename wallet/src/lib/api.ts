
/**
 * The wallet API, on this page's own origin (docs/phase-7-hr-attendance-bonus-wallet.md §8). The session is the
 * wallet's own HttpOnly cookie; every request carries X-Wallet-Request, without which the API answers nothing.
 */
const BASE = '/api/v1/wallet'

type ErrorBody = { message?: string; code?: string; errors?: Record<string, string[]> } | null

export class ApiError extends Error {
  readonly status: number
  readonly body: ErrorBody

  constructor(status: number, body: ErrorBody) {
    super(body?.message ?? `Request failed (${status})`)
    this.status = status
    this.body = body
  }

  field(name: string): string | undefined {
    return this.body?.errors?.[name]?.[0]
  }

  get code(): string | undefined {
    return this.body?.code
  }
}

/** Fired when the session is gone, so the page can return to sign-in. */
export const SIGNED_OUT_EVENT = 'wallet:signed-out'

async function send(path: string, init: RequestInit = {}): Promise<Response> {
  const isForm = init.body instanceof FormData
  const response = await fetch(`${BASE}/${path}`, {
    ...init,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'X-Wallet-Request': '1',
      'X-Locale': 'en',
      ...(init.body === undefined || isForm ? {} : { 'Content-Type': 'application/json' }),
    },
  })
  if (response.status === 401 && !path.startsWith('auth/')) window.dispatchEvent(new Event(SIGNED_OUT_EVENT))
  if (!response.ok) {
    const text = await response.text()
    throw new ApiError(response.status, text.startsWith('{') ? JSON.parse(text) : null)
  }

  return response
}

export const api = {
  get: async <T>(path: string, signal?: AbortSignal): Promise<T> => (await send(path, { signal })).json() as Promise<T>,
  post: async <T>(path: string, body?: unknown): Promise<T> => (await send(path, { method: 'POST', body: body instanceof FormData ? body : JSON.stringify(body ?? {}) })).json() as Promise<T>,
  blob: async (path: string): Promise<Blob> => (await send(path)).blob(),
}

export type Data<T> = { data: T }
