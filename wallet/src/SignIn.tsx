import qrcode from 'qrcode-generator'
import { useId, useMemo, useState, type FormEvent, type KeyboardEvent, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

import { ApiError } from './lib/api'
import { actions, type PasswordStep } from './lib/queries'
import { Notice, PrimaryButton, Shell, inputClass } from './ui'

/** Why the sign-in is showing again: the person signed out, or the session ran out while the wallet was open. */
export type SignInNotice = 'signed_out' | 'session_ended' | null

/**
 * The admin panel's "Forgot password?" page beside this wallet (wallet.bhabaghure.com.bd → admin.bhabaghure.com.bd): the
 * wallet signs in with the super admin's staff password, so that is where it is reset.
 */
function forgotPasswordUrl(): string {
  const { protocol, host } = window.location
  return host.startsWith('wallet.') ? `${protocol}//admin.${host.slice('wallet.'.length)}/forgot-password` : 'https://admin.bhabaghure.com.bd/forgot-password'
}

/**
 * The wallet's sign-in (design of 2026-10-02): the allow-list already let this connection in, so the page shows that lock
 * passed and this one to go. The super admin's staff email and password (with "Forgot password?", reset on the admin
 * panel), then the code from their authenticator app. The first time, the app is set up from a QR code, a key to type, or
 * on a phone a button that hands the key to the app; the first accepted code enrolls it.
 */
export function SignIn({ onSignedIn, notice = null }: { onSignedIn: () => void; notice?: SignInNotice }) {
  const { t } = useTranslation()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [step, setStep] = useState<PasswordStep | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(notice ? t(`signIn.notices.${notice}`) : null)
  const [busy, setBusy] = useState(false)

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

  const startAgain = () => {
    setStep(null)
    setCode('')
    setPassword('')
    setError(null)
  }

  const submitPassword = (event: FormEvent) => {
    event.preventDefault()
    void run(async () => {
      setStep(await actions.password({ email: email.trim(), password }))
      setMessage(null)
    })
  }

  const submitCode = (event: FormEvent) => {
    event.preventDefault()
    if (!step) return
    void run(async () => {
      try {
        await actions.code({ challenge: step.challenge, code: code.replace(/\s+/g, '') })
        onSignedIn()
      } catch (e) {
        // A spent challenge means starting again from the password; the email stays filled in.
        if (e instanceof ApiError && e.code === 'challenge_expired') startAgain()
        throw e
      }
    })
  }

  return (
    <Shell>
      <div className="mx-auto flex w-full max-w-[1080px] flex-wrap items-start justify-center gap-x-12 gap-y-5 sm:py-4">
        <Locks />
        <section className="flex w-full max-w-[460px] flex-[1_1_380px] flex-col gap-4.5 rounded-18 border border-wallet-line bg-wallet-surface p-[clamp(20px,4vw,28px)]">
          <StepBar label={!step ? t('signIn.stepPassword') : step.enrollment ? t('signIn.stepFirstTime') : t('signIn.stepCode')} done={step ? 2 : 1} />
          {message ? <Notice tone="violet">{message}</Notice> : null}
          {!step ? (
            <PasswordForm email={email} password={password} onEmail={setEmail} onPassword={setPassword} onSubmit={submitPassword} busy={busy} error={error} />
          ) : step.enrollment ? (
            <EnrollForm enrollment={step.enrollment} code={code} onCode={setCode} onSubmit={submitCode} onStartAgain={startAgain} busy={busy} error={error} />
          ) : (
            <CodeForm code={code} onCode={setCode} onSubmit={submitCode} onStartAgain={startAgain} busy={busy} error={error} />
          )}
        </section>
      </div>
    </Shell>
  )
}

/** The wallet's two locks: the allowed address (passed, or this page wouldn't show) and the sign-in. */
function Locks() {
  const { t } = useTranslation()
  const check = (
    <svg viewBox="0 0 24 24" aria-hidden="true" className="size-4 fill-none stroke-current stroke-[2.5]">
      <path d="M5 12l5 5L20 7" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  )

  return (
    <>
      {/* A phone: the locks as a line of chips above the form. */}
      <ol aria-label={t('signIn.locksEyebrow')} className="m-0 flex w-full max-w-[460px] list-none gap-1.5 p-0 sm:hidden">
        <li className="flex min-h-8 flex-1 items-center justify-center gap-1 rounded-pill border border-wallet-in-soft/35 text-11 font-semibold text-wallet-in-soft">
          {check}
          {t('signIn.lockPlace')}
        </li>
        <li className="flex min-h-8 flex-1 items-center justify-center rounded-pill border border-wallet-violet bg-wallet-purple-deep/30 text-11 font-bold text-wallet-lavender">
          2 · {t('signIn.lockYou')}
        </li>
      </ol>

      <aside className="hidden max-w-[470px] flex-[1_1_320px] flex-col gap-5.5 pt-1.5 sm:flex">
        <div className="flex flex-col gap-2.5">
          <span className="font-display text-12 font-bold tracking-[0.16em] text-wallet-violet uppercase">{t('signIn.locksEyebrow')}</span>
          <h2 className="m-0 font-display text-[32px] leading-1.15 font-bold tracking-[-0.01em]">{t('signIn.locksTitle')}</h2>
          <p className="m-0 text-15 leading-1.6 text-wallet-muted">{t('signIn.locksNote')}</p>
        </div>
        <ol className="m-0 flex list-none flex-col gap-2.5 p-0">
          <Lock
            mark={<span className="flex size-8 shrink-0 items-center justify-center rounded-full border border-wallet-in-soft/45 bg-wallet-in/12 text-wallet-in-soft">{check}</span>}
            title={t('signIn.lockPlace')}
            note={t('signIn.lockPlaceNote')}
            state={<span className="text-12 font-semibold text-wallet-in-soft">{t('signIn.passed')}</span>}
          />
          <Lock
            current
            mark={<span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-wallet-violet font-display text-14 font-extrabold text-wallet-plum-deep">2</span>}
            title={t('signIn.lockYou')}
            note={t('signIn.lockYouNote')}
            state={<span className="text-12 font-semibold text-wallet-lilac">{t('signIn.now')}</span>}
          />
        </ol>
        <p className="m-0 text-13 leading-1.6 text-wallet-muted">{t('signIn.sessionNote')}</p>
      </aside>
    </>
  )
}

function Lock({ mark, title, note, state, current = false }: { mark: ReactNode; title: string; note: string; state: ReactNode; current?: boolean }) {
  return (
    <li className={`flex items-start gap-3.5 rounded-14 border px-4 py-3.5 ${current ? 'border-wallet-violet/55 bg-wallet-purple-deep/15' : 'border-wallet-line bg-wallet-bar'}`}>
      <span aria-hidden="true">{mark}</span>
      <span className="flex min-w-0 flex-1 flex-col gap-0.5">
        <strong className="text-15">{title}</strong>
        <span className="text-13 leading-1.5 text-wallet-muted">{note}</span>
      </span>
      {state}
    </li>
  )
}

function StepBar({ label, done }: { label: string; done: 1 | 2 }) {
  return (
    <div className="flex flex-col gap-2">
      <span className="text-12 font-semibold text-wallet-muted">{label}</span>
      <div aria-hidden="true" className="flex gap-1.5">
        <span className="h-1 flex-1 rounded-4 bg-wallet-violet" />
        <span className={`h-1 flex-1 rounded-4 ${done === 2 ? 'bg-wallet-violet' : 'bg-wallet-line'}`} />
      </div>
    </div>
  )
}

const bigCodeClass = `${inputClass} text-center font-display text-[28px] tracking-[0.32em]`

function PasswordForm({
  email,
  password,
  onEmail,
  onPassword,
  onSubmit,
  busy,
  error,
}: {
  email: string
  password: string
  onEmail: (value: string) => void
  onPassword: (value: string) => void
  onSubmit: (event: FormEvent) => void
  busy: boolean
  error: string | null
}) {
  const { t } = useTranslation()
  const id = useId()
  const [shown, setShown] = useState(false)
  const [capsLock, setCapsLock] = useState(false)
  const watchCaps = (event: KeyboardEvent<HTMLInputElement>) => setCapsLock(event.getModifierState('CapsLock'))

  return (
    <form className="flex flex-col gap-4" onSubmit={onSubmit}>
      <h1 className="m-0 font-display text-24 font-bold">{t('signIn.title')}</h1>
      <div className="flex flex-col gap-1.5">
        <label htmlFor={`${id}-email`} className="text-13 font-semibold text-wallet-muted">
          {t('signIn.email')}
        </label>
        <input id={`${id}-email`} className={`${inputClass} min-h-12 text-15`} type="email" autoComplete="username" value={email} onChange={(e) => onEmail(e.target.value)} required />
      </div>
      <div className="flex flex-col gap-1.5">
        <label htmlFor={`${id}-password`} className="text-13 font-semibold text-wallet-muted">
          {t('signIn.password')}
        </label>
        <div className="flex gap-2">
          <input
            id={`${id}-password`}
            className={`${inputClass} min-h-12 text-15`}
            type={shown ? 'text' : 'password'}
            autoComplete="current-password"
            value={password}
            onChange={(e) => onPassword(e.target.value)}
            onKeyDown={watchCaps}
            onKeyUp={watchCaps}
            aria-describedby={`${id}-password-note`}
            required
          />
          <button
            type="button"
            onClick={() => setShown((value) => !value)}
            aria-label={shown ? t('signIn.hidePassword') : t('signIn.showPassword')}
            aria-pressed={shown}
            className="min-h-12 min-w-16 shrink-0 cursor-pointer rounded-10 border border-wallet-input bg-wallet-bar text-13 font-semibold text-wallet-lilac"
          >
            {shown ? t('signIn.hide') : t('signIn.show')}
          </button>
        </div>
        {capsLock ? (
          <span id={`${id}-password-note`} role="status" className="text-12 font-semibold text-wallet-due">
            {t('signIn.capsLock')}
          </span>
        ) : (
          <span id={`${id}-password-note`} className="text-12 text-wallet-muted">
            {t('signIn.passwordHint')}
          </span>
        )}
      </div>
      {error ? <Notice tone="out">{error}</Notice> : null}
      <PrimaryButton type="submit" className="min-h-12 text-15" disabled={busy || !email || !password}>
        {busy ? t('signIn.checking') : t('signIn.continue')}
      </PrimaryButton>
      <p className="m-0 text-13 leading-1.6 text-wallet-muted">
        {t('signIn.forgot')}{' '}
        <a href={forgotPasswordUrl()} target="_blank" rel="noopener noreferrer" className="font-semibold">
          {t('signIn.forgotLink')}
        </a>
      </p>
    </form>
  )
}

function EnrollForm({
  enrollment,
  code,
  onCode,
  onSubmit,
  onStartAgain,
  busy,
  error,
}: {
  enrollment: NonNullable<PasswordStep['enrollment']>
  code: string
  onCode: (value: string) => void
  onSubmit: (event: FormEvent) => void
  onStartAgain: () => void
  busy: boolean
  error: string | null
}) {
  const { t } = useTranslation()
  const id = useId()
  const [copied, setCopied] = useState(false)
  const qr = useMemo(() => {
    const matrix = qrcode(0, 'M')
    matrix.addData(enrollment.uri)
    matrix.make()
    return matrix.createDataURL(5, 2)
  }, [enrollment.uri])
  const key = enrollment.secret.replace(/(.{4})/g, '$1 ').trim()
  const copy = () => void navigator.clipboard?.writeText(enrollment.secret).then(() => setCopied(true))
  const qrImage = <img src={qr} alt={t('signIn.qrAlt')} className="rounded-12 bg-white p-2" width={200} height={200} />
  const number = (n: number) => (
    <span aria-hidden="true" className="flex size-6.5 shrink-0 items-center justify-center rounded-full bg-wallet-purple-deep/35 text-13 font-bold text-wallet-lavender">
      {n}
    </span>
  )

  return (
    <form className="flex flex-col gap-4" onSubmit={onSubmit}>
      <h1 className="m-0 font-display text-24 font-bold">{t('signIn.enrollTitle')}</h1>
      <ol className="m-0 flex list-none flex-col gap-3.5 p-0">
        <li className="flex gap-3">
          {number(1)}
          <span className="text-14 leading-1.55">{t('signIn.enrollStep1')}</span>
        </li>
        <li className="flex gap-3">
          {number(2)}
          <span className="flex min-w-0 flex-1 flex-col gap-2.5">
            <span className="text-14 leading-1.55">{t('signIn.enrollStep2')}</span>
            {/* On a phone the screen can't be scanned by the phone itself: the key goes to the app by a link instead. */}
            <a
              href={enrollment.uri}
              className="flex min-h-12 items-center justify-center rounded-12 bg-gradient-to-br from-wallet-purple-deep to-wallet-plum px-4 text-15 font-bold text-white sm:hidden"
            >
              {t('signIn.enrollAddToApp')}
            </a>
            <span className="hidden self-start sm:block">{qrImage}</span>
            <details className="rounded-12 border border-wallet-line px-3 py-2.5 sm:hidden">
              <summary className="min-h-7 cursor-pointer text-13 font-semibold text-wallet-lilac">{t('signIn.enrollShowQr')}</summary>
              <span className="flex justify-center pt-3">{qrImage}</span>
            </details>
            <span className="text-13 text-wallet-muted">{t('signIn.secretLabel')}</span>
            <span className="flex flex-wrap items-center gap-2">
              <code className="rounded-8 border border-wallet-input bg-wallet-bg px-2.5 py-2 font-display text-15 tracking-[0.06em] break-all text-wallet-lavender" data-testid="enroll-secret">
                {key}
              </code>
              <button type="button" onClick={copy} className="min-h-10 cursor-pointer rounded-8 border border-wallet-input bg-wallet-bar px-3 text-13 font-semibold text-wallet-lilac">
                {copied ? t('signIn.copied') : t('signIn.copyKey')}
              </button>
            </span>
          </span>
        </li>
        <li className="flex gap-3">
          {number(3)}
          <span className="flex min-w-0 flex-1 flex-col gap-1.5">
            <label htmlFor={`${id}-code`} className="text-14 leading-1.55">
              {t('signIn.enrollStep3')}
            </label>
            <input
              id={`${id}-code`}
              className={bigCodeClass}
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={7}
              placeholder="000 000"
              value={code}
              onChange={(e) => onCode(e.target.value)}
              required
            />
          </span>
        </li>
      </ol>
      <Notice tone="violet">{t('signIn.enrollOnce')}</Notice>
      {error ? <Notice tone="out">{error}</Notice> : null}
      <PrimaryButton type="submit" className="min-h-12 text-15" disabled={busy || code.replace(/\s+/g, '').length !== 6}>
        {busy ? t('signIn.checking') : t('signIn.open')}
      </PrimaryButton>
      <button type="button" onClick={onStartAgain} className="min-h-10 cursor-pointer self-start border-0 bg-transparent p-0 text-13 font-semibold text-wallet-link">
        {t('signIn.startAgain')}
      </button>
    </form>
  )
}

function CodeForm({
  code,
  onCode,
  onSubmit,
  onStartAgain,
  busy,
  error,
}: {
  code: string
  onCode: (value: string) => void
  onSubmit: (event: FormEvent) => void
  onStartAgain: () => void
  busy: boolean
  error: string | null
}) {
  const { t } = useTranslation()
  const id = useId()

  return (
    <form className="flex flex-col gap-4" onSubmit={onSubmit}>
      <h1 className="m-0 font-display text-24 font-bold">{t('signIn.codeTitle')}</h1>
      <p className="m-0 text-14 leading-1.6 text-wallet-muted">{t('signIn.codeNote')}</p>
      <div className="flex flex-col gap-1.5">
        <label htmlFor={`${id}-code`} className="text-13 font-semibold text-wallet-muted">
          {t('signIn.code')}
        </label>
        <input
          id={`${id}-code`}
          className={`${bigCodeClass} min-h-14`}
          inputMode="numeric"
          autoComplete="one-time-code"
          maxLength={7}
          placeholder="000 000"
          value={code}
          onChange={(e) => onCode(e.target.value)}
          autoFocus
          required
        />
      </div>
      {error ? <Notice tone="out">{error}</Notice> : null}
      <PrimaryButton type="submit" className="min-h-12 text-15" disabled={busy || code.replace(/\s+/g, '').length !== 6}>
        {busy ? t('signIn.checking') : t('signIn.open')}
      </PrimaryButton>
      <div className="flex flex-wrap items-center justify-between gap-2 text-13 text-wallet-muted">
        <span>{t('signIn.stepOpen')}</span>
        <button type="button" onClick={onStartAgain} className="min-h-10 cursor-pointer border-0 bg-transparent p-0 text-13 font-semibold text-wallet-link">
          {t('signIn.startAgain')}
        </button>
      </div>
      <p className="m-0 border-t border-wallet-line pt-3 text-13 leading-1.6 text-wallet-muted">{t('signIn.lostPhone')}</p>
    </form>
  )
}
