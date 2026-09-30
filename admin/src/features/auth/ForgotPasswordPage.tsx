import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { buttonClass } from '../../components/ui/button'
import { ErrorNotice } from '../../components/ui/feedback'
import { TextInput } from '../../components/ui/fields'
import { api, ApiError } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'
import { useFormat } from '../../lib/useFormat'
import { AuthLayout } from './AuthLayout'

/**
 * "Forgot password?" (client, 2026-10-01; docs/phase-7-hr-attendance-bonus-wallet.md §4.1): a reset link to the email
 * on file and, by WhatsApp, to the staff member's own phone. The page says the same thing whoever's address is typed.
 */
export function ForgotPasswordPage() {
  const { t } = useTranslation()
  const { number } = useFormat()
  const [email, setEmail] = useState('')
  const [sending, setSending] = useState(false)
  const [sent, setSent] = useState<number | null>(null)
  const [error, setError] = useState<unknown>(null)

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    if (sending) return
    setSending(true)
    setError(null)
    try {
      const response = await api.post<Data<{ status: 'sent'; minutes: number }>>('staff/auth/forgot-password', { email: email.trim() })
      setSent(response.data.minutes)
    } catch (caught) {
      setError(caught)
    } finally {
      setSending(false)
    }
  }

  if (sent !== null) {
    return (
      <AuthLayout title={t('auth.forgotSentTitle')} subtitle={t('auth.forgotSent', { email: email.trim(), minutes: number(sent) })}>
        <Link to="/login" className={buttonClass('cta', 'md', 'w-full py-3 text-15')}>
          {t('auth.toSignIn')}
        </Link>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout title={t('auth.forgotTitle')} subtitle={t('auth.forgotSubtitle')}>
      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3.5">
        <TextInput
          label={t('auth.email')}
          type="email"
          autoComplete="username"
          required
          value={email}
          onChange={setEmail}
          error={error instanceof ApiError ? error.field('email') : undefined}
        />
        {error && !(error instanceof ApiError && error.status === 422) ? <ErrorNotice error={error} /> : null}
        <button type="submit" aria-disabled={sending} className={buttonClass('cta', 'md', 'w-full py-3 text-15')}>
          {sending ? t('common.working') : t('auth.forgotSend')}
        </button>
        <Link to="/login" className="self-center text-13 font-semibold text-blue">
          {t('auth.backToSignIn')}
        </Link>
      </form>
    </AuthLayout>
  )
}
