import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, Navigate, useLocation, useNavigate } from 'react-router'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { ErrorNotice, useToast } from '../../components/ui/feedback'
import { TextInput } from '../../components/ui/fields'
import { ApiError } from '../../lib/api/client'
import type { Staff } from '../../lib/api/types'
import { AuthLayout } from './AuthLayout'

/** Where to go after signing in: a password still to replace, two-step sign-in still to set up, or where they were going. */
const landing = (staff: Staff, from: string) => (staff.must_change_password ? '/change-password' : staff.two_factor_setup_required ? '/two-step-setup' : from)

export function LoginPage() {
  const { t } = useTranslation()
  const { session, signIn } = useAuth()
  const navigate = useNavigate()
  const toast = useToast()
  const from = (useLocation().state as { from?: string } | null)?.from ?? '/'

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  // Two-step sign-in (docs/two-step-sign-in.md): after the right password, the code from the authenticator app.
  const [askCode, setAskCode] = useState(false)
  const [code, setCode] = useState('')
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<unknown>(null)

  if (session.state === 'signed-in') return <Navigate to={landing(session.staff, from)} replace />

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    if (sending) return
    setSending(true)
    setError(null)
    try {
      const { staff, recoveryCodesLeft } = await signIn(email.trim(), password, askCode ? code.trim() : undefined)
      if (recoveryCodesLeft !== undefined) toast(t('twoStep.recoveryUsed', { count: recoveryCodesLeft }), recoveryCodesLeft <= 3 ? 'error' : 'success')
      navigate(landing(staff, from), { replace: true })
    } catch (caught) {
      if (caught instanceof ApiError && caught.code === 'two_factor_required') {
        setAskCode(true)
      } else {
        setError(caught)
      }
      setSending(false)
    }
  }

  const fieldError = (name: string) => (error instanceof ApiError ? error.field(name) : undefined)

  if (askCode) {
    return (
      <AuthLayout title={t('twoStep.signInTitle')} subtitle={t('twoStep.signInNote')}>
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3.5" data-testid="two-step-sign-in">
          <TextInput
            label={t('twoStep.codeLabel')}
            hint={t('twoStep.codeHint')}
            value={code}
            onChange={setCode}
            inputMode="text"
            autoComplete="one-time-code"
            autoFocus
            maxLength={20}
            required
          />
          {error ? <ErrorNotice error={error} /> : null}
          <button type="submit" aria-disabled={sending || code.trim() === ''} className={buttonClass('cta', 'md', 'w-full py-3 text-15')}>
            {sending ? t('common.working') : t('twoStep.verify')}
          </button>
          <button
            type="button"
            className={buttonClass('ghost', 'sm', 'self-start')}
            onClick={() => {
              setAskCode(false)
              setCode('')
              setPassword('')
              setError(null)
            }}
          >
            {t('twoStep.back')}
          </button>
        </form>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout title={t('auth.signInTitle')} subtitle={session.state === 'signed-out' && session.reason === 'expired' ? t('auth.sessionExpired') : t('auth.signInSubtitle')}>
      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3.5">
        <TextInput label={t('auth.email')} type="email" autoComplete="username" required value={email} onChange={setEmail} error={fieldError('email')} />
        <TextInput label={t('auth.password')} type="password" autoComplete="current-password" required value={password} onChange={setPassword} error={fieldError('password')} />
        <Link to="/forgot-password" className="-mt-1.5 self-end text-13 font-semibold text-blue">
          {t('auth.forgotLink')}
        </Link>
        {error && !(error instanceof ApiError && Object.keys(error.errors).length > 0 && error.status === 422) ? <ErrorNotice error={error} /> : null}
        <button type="submit" aria-disabled={sending} className={buttonClass('cta', 'md', 'w-full py-3 text-15')}>
          {sending ? t('common.working') : t('auth.signIn')}
        </button>
      </form>
    </AuthLayout>
  )
}
