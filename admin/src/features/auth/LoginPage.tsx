import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate, useLocation, useNavigate } from 'react-router'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { ErrorNotice } from '../../components/ui/feedback'
import { TextInput } from '../../components/ui/fields'
import { ApiError } from '../../lib/api/client'
import { AuthLayout } from './AuthLayout'

export function LoginPage() {
  const { t } = useTranslation()
  const { session, signIn } = useAuth()
  const navigate = useNavigate()
  const from = (useLocation().state as { from?: string } | null)?.from ?? '/'

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<unknown>(null)

  if (session.state === 'signed-in') return <Navigate to={session.staff.must_change_password ? '/change-password' : from} replace />

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    if (sending) return
    setSending(true)
    setError(null)
    try {
      const staff = await signIn(email.trim(), password)
      navigate(staff.must_change_password ? '/change-password' : from, { replace: true })
    } catch (caught) {
      setError(caught)
      setSending(false)
    }
  }

  const fieldError = (name: string) => (error instanceof ApiError ? error.field(name) : undefined)

  return (
    <AuthLayout title={t('auth.signInTitle')} subtitle={session.state === 'signed-out' && session.reason === 'expired' ? t('auth.sessionExpired') : t('auth.signInSubtitle')}>
      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3.5">
        <TextInput label={t('auth.email')} type="email" autoComplete="username" required value={email} onChange={setEmail} error={fieldError('email')} />
        <TextInput label={t('auth.password')} type="password" autoComplete="current-password" required value={password} onChange={setPassword} error={fieldError('password')} />
        {error && !(error instanceof ApiError && Object.keys(error.errors).length > 0 && error.status === 422) ? <ErrorNotice error={error} /> : null}
        <button type="submit" aria-disabled={sending} className={buttonClass('cta', 'md', 'w-full py-3 text-15')}>
          {sending ? t('common.working') : t('auth.signIn')}
        </button>
      </form>
    </AuthLayout>
  )
}
