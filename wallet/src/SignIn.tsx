import qrcode from 'qrcode-generator'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { ApiError } from './lib/api'
import { actions, type PasswordStep } from './lib/queries'
import { Field, Notice, PrimaryButton, Shell, inputClass } from './ui'

/**
 * The super admin's staff email and password, then the code from their authenticator app. The first time, the page
 * shows a QR code (and the secret, to type in) for the app; the first accepted code enrolls it.
 */
export function SignIn({ onSignedIn }: { onSignedIn: () => void }) {
  const { t } = useTranslation()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [step, setStep] = useState<PasswordStep | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const qr = useMemo(() => {
    if (!step?.enrollment) return null
    const matrix = qrcode(0, 'M')
    matrix.addData(step.enrollment.uri)
    matrix.make()
    return matrix.createDataURL(5, 2)
  }, [step])

  const run = async (work: () => Promise<void>) => {
    setBusy(true)
    setError(null)
    try {
      await work()
    } catch (e) {
      setError(e instanceof ApiError ? e.message : t('errors.network'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Shell>
      <section className="mx-auto flex w-full max-w-md flex-col gap-4 rounded-18 border border-wallet-line bg-wallet-surface p-6">
        <h1 className="m-0 text-20 font-bold">{step ? (step.enrollment ? t('signIn.enrollTitle') : t('signIn.codeTitle')) : t('signIn.title')}</h1>
        {!step ? (
          <form
            className="flex flex-col gap-3"
            onSubmit={(event) => {
              event.preventDefault()
              void run(async () => setStep(await actions.password({ email: email.trim(), password })))
            }}
          >
            <p className="m-0 text-13 leading-1.6 text-wallet-muted">{t('signIn.note')}</p>
            <Field label={t('signIn.email')}>
              <input className={inputClass} type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} required />
            </Field>
            <Field label={t('signIn.password')}>
              <input className={inputClass} type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required />
            </Field>
            {error ? <Notice tone="out">{error}</Notice> : null}
            <PrimaryButton type="submit" disabled={busy || !email || !password}>
              {t('signIn.continue')}
            </PrimaryButton>
          </form>
        ) : (
          <form
            className="flex flex-col gap-3"
            onSubmit={(event) => {
              event.preventDefault()
              void run(async () => {
                try {
                  await actions.code({ challenge: step.challenge, code: code.replace(/\s+/g, '') })
                  onSignedIn()
                } catch (e) {
                  // A spent challenge means starting again from the password.
                  if (e instanceof ApiError && e.code === 'challenge_expired') {
                    setStep(null)
                    setCode('')
                  }
                  throw e
                }
              })
            }}
          >
            {step.enrollment && qr ? (
              <>
                <p className="m-0 text-13 leading-1.6 text-wallet-muted">{t('signIn.enrollNote')}</p>
                <img src={qr} alt={t('signIn.qrAlt')} className="self-center rounded-12 bg-white p-2" width={220} height={220} />
                <p className="m-0 text-12 text-wallet-muted">
                  {t('signIn.secretLabel')} <code className="break-all font-display text-13 text-wallet-lavender" data-testid="enroll-secret">{step.enrollment.secret.replace(/(.{4})/g, '$1 ').trim()}</code>
                </p>
              </>
            ) : (
              <p className="m-0 text-13 leading-1.6 text-wallet-muted">{t('signIn.codeNote')}</p>
            )}
            <Field label={t('signIn.code')}>
              <input className={`${inputClass} font-display text-20 tracking-[0.3em]`} inputMode="numeric" autoComplete="one-time-code" maxLength={7} value={code} onChange={(e) => setCode(e.target.value)} required />
            </Field>
            {error ? <Notice tone="out">{error}</Notice> : null}
            <PrimaryButton type="submit" disabled={busy || code.replace(/\s+/g, '').length !== 6}>
              {t('signIn.open')}
            </PrimaryButton>
          </form>
        )}
      </section>
    </Shell>
  )
}
