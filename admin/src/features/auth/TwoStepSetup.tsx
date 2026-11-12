import qrcode from 'qrcode-generator'
import { useMemo, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { ErrorNotice } from '../../components/ui/feedback'
import { TextInput } from '../../components/ui/fields'
import { ApiError } from '../../lib/api/client'
import { useConfirmTwoFactor, useStartTwoFactor, type TwoFactorSetup } from './twoFactor'

/**
 * Setting up two-step sign-in (docs/two-step-sign-in.md): start, scan the QR code (or type the key) in an authenticator
 * app, enter the code it shows. Then the recovery codes, once. Used on the profile and on the setup page the super
 * admin's rule sends people to.
 */
export function TwoStepSetup({ onDone, onCancel }: { onDone: () => void; onCancel?: () => void }) {
  const { t } = useTranslation()
  const start = useStartTwoFactor()
  const [setup, setSetup] = useState<TwoFactorSetup | null>(null)
  const [codes, setCodes] = useState<string[] | null>(null)

  if (codes) return <RecoveryCodes codes={codes} onDone={onDone} />
  if (setup) return <ScanAndConfirm setup={setup} onConfirmed={setCodes} onCancel={onCancel} />

  return (
    <div className="flex flex-col gap-3">
      <p className="m-0 text-13.5 leading-1.55 text-app-muted">{t('twoStep.intro')}</p>
      <div className="flex flex-wrap gap-2">
        <button type="button" className={buttonClass('primary', 'md')} disabled={start.isPending} onClick={() => start.mutate(undefined, { onSuccess: (response) => setSetup(response.data) })}>
          {start.isPending ? t('common.working') : t('twoStep.start')}
        </button>
        {onCancel ? (
          <button type="button" className={buttonClass('ghost', 'md')} onClick={onCancel}>
            {t('common.cancel')}
          </button>
        ) : null}
      </div>
      {start.error ? <ErrorNotice error={start.error} /> : null}
    </div>
  )
}

function ScanAndConfirm({ setup, onConfirmed, onCancel }: { setup: TwoFactorSetup; onConfirmed: (codes: string[]) => void; onCancel?: () => void }) {
  const { t } = useTranslation()
  const { reloadStaff } = useAuth()
  const confirm = useConfirmTwoFactor()
  const [code, setCode] = useState('')
  const [copied, setCopied] = useState(false)
  const qr = useMemo(() => {
    const matrix = qrcode(0, 'M')
    matrix.addData(setup.uri)
    matrix.make()
    return matrix.createDataURL(5, 2)
  }, [setup.uri])
  // Grouped in fours, as authenticator apps accept it typed.
  const key = setup.secret.replace(/(.{4})/g, '$1 ').trim()
  const fieldError = confirm.error instanceof ApiError ? (confirm.error.field('code') ?? (confirm.error.status === 422 ? confirm.error.message : undefined)) : undefined

  const submit = (event: FormEvent) => {
    event.preventDefault()
    if (confirm.isPending || code.trim() === '') return
    confirm.mutate(code.trim(), {
      onSuccess: async (response) => {
        await reloadStaff()
        onConfirmed(response.data.recovery_codes)
      },
    })
  }

  return (
    <form className="flex flex-col gap-3.5" onSubmit={submit} noValidate data-testid="two-step-scan">
      <ol className="m-0 flex flex-col gap-2 pl-5 text-13.5 leading-1.55">
        <li>{t('twoStep.step1')}</li>
        <li>{t('twoStep.step2')}</li>
        <li>{t('twoStep.step3')}</li>
      </ol>
      <div className="flex flex-wrap items-center gap-4">
        <img src={qr} alt={t('twoStep.qrAlt')} width={180} height={180} className="rounded-10 border border-app-line bg-white p-1.5" />
        <div className="flex min-w-48 flex-1 flex-col gap-1.5">
          <span className="text-12 text-app-muted">{t('twoStep.keyLabel')}</span>
          <code className="rounded-8 bg-app-surface-2 px-2.5 py-2 font-mono text-13.5 tracking-wide break-all" data-testid="two-step-key">
            {key}
          </code>
          <button type="button" className={buttonClass('ghost', 'sm', 'self-start')} onClick={() => void navigator.clipboard?.writeText(setup.secret).then(() => setCopied(true))}>
            {copied ? t('twoStep.copied') : t('twoStep.copyKey')}
          </button>
        </div>
      </div>
      <TextInput
        label={t('twoStep.codeLabel')}
        value={code}
        onChange={setCode}
        inputMode="numeric"
        autoComplete="one-time-code"
        maxLength={7}
        error={fieldError}
        className="max-w-48"
      />
      {confirm.error && !fieldError ? <ErrorNotice error={confirm.error} /> : null}
      <div className="flex flex-wrap gap-2">
        <button type="submit" className={buttonClass('primary', 'md')} aria-disabled={confirm.isPending || code.trim() === ''}>
          {confirm.isPending ? t('common.working') : t('twoStep.confirm')}
        </button>
        {onCancel ? (
          <button type="button" className={buttonClass('ghost', 'md')} onClick={onCancel}>
            {t('common.cancel')}
          </button>
        ) : null}
      </div>
    </form>
  )
}

/** Shown once: copied, downloaded or printed before going on. */
export function RecoveryCodes({ codes, onDone }: { codes: string[]; onDone: () => void }) {
  const { t } = useTranslation()
  const [saved, setSaved] = useState(false)
  const text = `${t('twoStep.codesFileTitle')}\n\n${codes.join('\n')}\n`

  const download = () => {
    const url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }))
    const link = document.createElement('a')
    link.href = url
    link.download = 'bhabaghure-recovery-codes.txt'
    link.click()
    URL.revokeObjectURL(url)
  }

  return (
    <div className="flex flex-col gap-3" data-testid="recovery-codes">
      <p className="m-0 text-13.5 leading-1.55">{t('twoStep.codesIntro')}</p>
      <ul className="m-0 grid list-none grid-cols-2 gap-x-6 gap-y-1.5 rounded-10 bg-app-surface-2 p-3.5 font-mono text-14 tracking-wide">
        {codes.map((code) => (
          <li key={code}>{code}</li>
        ))}
      </ul>
      <div className="flex flex-wrap gap-2">
        <button type="button" className={buttonClass('outline', 'sm')} onClick={() => void navigator.clipboard?.writeText(codes.join('\n'))}>
          {t('twoStep.copyCodes')}
        </button>
        <button type="button" className={buttonClass('outline', 'sm')} onClick={download}>
          {t('twoStep.downloadCodes')}
        </button>
      </div>
      <label className="flex items-center gap-2 text-13.5">
        <input type="checkbox" checked={saved} onChange={(event) => setSaved(event.target.checked)} />
        {t('twoStep.codesSaved')}
      </label>
      <button type="button" className={buttonClass('primary', 'md', 'self-start')} disabled={!saved} onClick={onDone}>
        {t('twoStep.done')}
      </button>
    </div>
  )
}
