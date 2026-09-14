import { useQuery } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation, useNavigate } from 'react-router'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { ErrorNotice, useToast } from '../../components/ui/feedback'
import { TextInput } from '../../components/ui/fields'
import { Loading } from '../../components/ui/layout'
import { api, ApiError } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'
import { useFormat } from '../../lib/useFormat'
import { AuthLayout } from './AuthLayout'

/** Staff minimum, same as the API (StaffInvitationController::accept). */
const MIN_PASSWORD = 10

type LinkInfo = { purpose: 'invite' | 'reset'; name: string; email: string; expires_at: string }

/**
 * /accept-invite and /reset-password (docs/phase-7-hr-attendance-bonus-wallet.md §4.1). The token is read once from the
 * URL fragment — which never reaches a server — and removed from the address bar, then sent in a POST body.
 */
export function SetPasswordPage() {
  const { t } = useTranslation()
  const { acceptLink } = useAuth()
  const toast = useToast()
  const navigate = useNavigate()
  const { number } = useFormat()
  const { pathname, hash } = useLocation()
  const [token] = useState(() => new URLSearchParams(hash.slice(1)).get('token') ?? '')
  // Out of the address bar and history once read; the component keeps it.
  useEffect(() => {
    if (hash) navigate({ pathname }, { replace: true })
  }, [hash, pathname, navigate])
  const link = useQuery({
    queryKey: ['staff-link', token],
    queryFn: () => api.post<Data<LinkInfo>>('staff/auth/invitation/check', { token }),
    enabled: token.length === 64,
    retry: false,
    staleTime: Infinity,
  })

  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [attempted, setAttempted] = useState(false)
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<unknown>(null)

  if (token.length !== 64 || (link.isError && link.error instanceof ApiError && [410, 422].includes(link.error.status)) || (error instanceof ApiError && error.status === 410)) {
    return (
      <AuthLayout title={t('auth.linkInvalidTitle')} subtitle={t('auth.linkInvalid')}>
        <Link to="/login" className={buttonClass('cta', 'md', 'w-full py-3 text-15')}>
          {t('auth.toSignIn')}
        </Link>
      </AuthLayout>
    )
  }
  if (link.isPending) return <Loading />
  if (link.isError) {
    return (
      <AuthLayout title={t('auth.linkInvalidTitle')} subtitle={t('auth.linkCheckFailed')}>
        <ErrorNotice error={link.error} />
      </AuthLayout>
    )
  }

  const info = link.data.data
  const local = {
    password: password.length < MIN_PASSWORD ? t('auth.passwordMin', { min: number(MIN_PASSWORD) }) : undefined,
    password_confirmation: confirmation !== password ? t('auth.passwordMismatch') : undefined,
  }
  const fieldError = (name: 'password' | 'password_confirmation') => (attempted ? local[name] : undefined) ?? (error instanceof ApiError ? error.field(name) : undefined)

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setAttempted(true)
    if (sending || local.password || local.password_confirmation) return
    setSending(true)
    setError(null)
    try {
      await acceptLink(token, password, confirmation)
      toast(info.purpose === 'invite' ? t('auth.welcome', { name: info.name }) : t('auth.passwordChanged'))
      navigate('/', { replace: true })
    } catch (caught) {
      setError(caught)
      setSending(false)
    }
  }

  return (
    <AuthLayout
      title={info.purpose === 'invite' ? t('auth.setPasswordTitle') : t('auth.resetTitle')}
      subtitle={info.purpose === 'invite' ? t('auth.inviteSubtitle', { name: info.name, email: info.email }) : t('auth.resetSubtitle', { email: info.email })}
    >
      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3.5">
        {/* Lets a password manager save the new password against the right account. */}
        <input type="email" name="username" autoComplete="username" value={info.email} readOnly hidden />
        <TextInput label={t('auth.newPassword', { min: number(MIN_PASSWORD) })} type="password" autoComplete="new-password" value={password} onChange={setPassword} error={fieldError('password')} />
        <TextInput label={t('auth.confirmPassword')} type="password" autoComplete="new-password" value={confirmation} onChange={setConfirmation} error={fieldError('password_confirmation')} />
        {error instanceof ApiError && error.status === 422 ? null : <ErrorNotice error={error} />}
        <button type="submit" aria-disabled={sending} className={buttonClass('cta', 'md', 'w-full py-3 text-15')}>
          {sending ? t('common.working') : t('auth.setPassword')}
        </button>
      </form>
    </AuthLayout>
  )
}
