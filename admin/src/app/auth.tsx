import { useQueryClient } from '@tanstack/react-query'
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'

import { api, onSessionEnd, refreshSession, setAccessToken } from '../lib/api/client'
import type { Staff, TokenResponse } from '../lib/api/types'

type Session =
  | { state: 'loading' }
  | { state: 'signed-out'; reason?: 'expired' }
  | { state: 'signed-in'; staff: Staff }

type AuthContextValue = {
  session: Session
  signIn: (email: string, password: string) => Promise<Staff>
  signOut: () => Promise<void>
  changePassword: (current: string, next: string, confirmation: string) => Promise<void>
  /** Sets the password from an invitation or reset link and signs in (docs/phase-7-hr-attendance-bonus-wallet.md §4.1). */
  acceptLink: (token: string, password: string, confirmation: string) => Promise<Staff>
  can: (...permissions: string[]) => boolean
}

const AuthContext = createContext<AuthContextValue | null>(null)

/** Staff session: restored from the refresh cookie on load, ended when a refresh fails. */
export function AuthProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<Session>({ state: 'loading' })
  const queryClient = useQueryClient()

  const accept = useCallback((data: TokenResponse) => {
    setAccessToken(data.access_token)
    setSession({ state: 'signed-in', staff: data.staff })
    return data.staff
  }, [])

  useEffect(() => {
    onSessionEnd(() => {
      setAccessToken(null)
      queryClient.clear()
      setSession({ state: 'signed-out', reason: 'expired' })
    })

    let cancelled = false
    void (async () => {
      const restored = await refreshSession()
      if (cancelled) return
      if (!restored) return setSession({ state: 'signed-out' })
      try {
        const staff = await api.get<Staff>('staff/auth/me')
        if (!cancelled) setSession({ state: 'signed-in', staff })
      } catch {
        if (!cancelled) setSession({ state: 'signed-out' })
      }
    })()
    return () => {
      cancelled = true
    }
  }, [queryClient])

  const value = useMemo<AuthContextValue>(() => {
    const staff = session.state === 'signed-in' ? session.staff : null

    return {
      session,
      signIn: async (email, password) => {
        // The language chosen on this device (login screen or sidebar) is kept; the profile locale doesn't override it.
        return accept(await api.post<TokenResponse>('staff/auth/login', { email, password }))
      },
      signOut: async () => {
        try {
          await api.post('staff/auth/logout')
        } finally {
          setAccessToken(null)
          queryClient.clear()
          setSession({ state: 'signed-out' })
        }
      },
      changePassword: async (current, next, confirmation) => {
        accept(await api.post<TokenResponse>('staff/auth/change-password', { current_password: current, password: next, password_confirmation: confirmation }))
      },
      acceptLink: async (token, password, confirmation) => {
        queryClient.clear()
        return accept(await api.post<TokenResponse>('staff/auth/invitation/accept', { token, password, password_confirmation: confirmation }))
      },
      // Mirrors the API: super admin passes every check. The API enforces permissions regardless.
      can: (...permissions) => !!staff && (staff.is_super_admin || permissions.some((permission) => staff.permissions.includes(permission))),
    }
  }, [session, accept, queryClient])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth outside AuthProvider')
  return context
}

// eslint-disable-next-line react-refresh/only-export-components
export function useStaff(): Staff {
  const { session } = useAuth()
  if (session.state !== 'signed-in') throw new Error('useStaff needs a signed-in session')
  return session.staff
}
