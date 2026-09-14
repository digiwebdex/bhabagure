import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'

import { useAuth, useStaff } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { ErrorNotice, useToast } from '../../components/ui/feedback'
import { TextInput } from '../../components/ui/fields'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { AuthLayout } from './AuthLayout'

/** Staff minimum, same as the API (StaffAuthController::changePassword). */
const MIN_PASSWORD = 10

export function ChangePasswordPage() {
  const { t } = useTranslation()
  const { changePassword, signOut } = useAuth()
  const staff = useStaff()
  const toast = useToast()
  const navigate = useNavigate()
  const { number } = useFormat()

  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [attempted, setAttempted] = useState(false)
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<unknown>(null)

  const local = {
    password: next.length < MIN_PASSWORD ? t('auth.passwordMin', { min: number(MIN_PASSWORD) }) : undefined,
    password_confirmation: confirmation !== next ? t('auth.passwordMismatch') : undefined,
  }
  const fieldError = (name: 'current_password' | 'password' | 'password_confirmation') =>
    (attempted ? local[name as keyof typeof local] : undefined) ?? (error instanceof ApiError ? error.field(name) : undefined)

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setAttempted(true)
    if (sending || local.password || local.password_confirmation) return
    setSending(true)
    setError(null)
    try {
      await changePassword(current, next, confirmation)
      toast(t('auth.passwordChanged'))
      navigate('/', { replace: true })
    } catch (caught) {
      setError(caught)
      setSending(false)
    }
  }

  return (
    <AuthLayout title={t('auth.changePasswordTitle')} subtitle={staff.must_change_password ? t('auth.mustChangeNote') : t('auth.changePasswordNote')}>
      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3.5">
        <TextInput label={t('auth.currentPassword')} type="password" autoComplete="current-password" value={current} onChange={setCurrent} error={fieldError('current_password')} />
        <TextInput label={t('auth.newPassword', { min: number(MIN_PASSWORD) })} type="password" autoComplete="new-password" value={next} onChange={setNext} error={fieldError('password')} />
        <TextInput label={t('auth.confirmPassword')} type="password" autoComplete="new-password" value={confirmation} onChange={setConfirmation} error={fieldError('password_confirmation')} />
        {error instanceof ApiError && error.status === 422 ? null : <ErrorNotice error={error} />}
        <button type="submit" aria-disabled={sending} className={buttonClass('cta', 'md', 'w-full py-3 text-15')}>
          {sending ? t('common.working') : t('auth.changePassword')}
        </button>
        <button type="button" onClick={() => void signOut()} className={buttonClass('outline', 'md', 'w-full')}>
          {t('shell.signOut')}
        </button>
      </form>
    </AuthLayout>
  )
}
