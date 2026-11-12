import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { ErrorNotice, useToast } from '../../components/ui/feedback'
import { TextInput } from '../../components/ui/fields'
import { Badge, Card, CardTitle, Loading } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { RecoveryCodes, TwoStepSetup } from '../auth/TwoStepSetup'
import { useDisableTwoFactor, useNewRecoveryCodes, useTwoFactor } from '../auth/twoFactor'

/** Profile → Two-step sign-in (docs/two-step-sign-in.md): each person's own authenticator app. */
export function TwoStepCard() {
  const { t } = useTranslation()
  const { date } = useFormat()
  const status = useTwoFactor()
  const [mode, setMode] = useState<'idle' | 'setup' | 'codes' | 'off'>('idle')
  const [codes, setCodes] = useState<string[] | null>(null)
  const state = status.data?.data

  return (
    <Card>
      <CardTitle
        title={t('twoStep.title')}
        aside={state ? <Badge tone={state.enabled ? 'green' : 'slate'}>{state.enabled ? t('twoStep.on') : t('twoStep.off')}</Badge> : null}
      />
      {status.isPending ? (
        <Loading />
      ) : status.isError ? (
        <ErrorNotice error={status.error} />
      ) : codes ? (
        <RecoveryCodes
          codes={codes}
          onDone={() => {
            setCodes(null)
            setMode('idle')
          }}
        />
      ) : mode === 'setup' ? (
        // Stays until Done, after it is on too: the recovery codes come at the end of the setup, shown once.
        <TwoStepSetup onDone={() => setMode('idle')} onCancel={() => setMode('idle')} />
      ) : !state!.enabled ? (
        <div className="flex flex-col gap-3" data-testid="two-step-off">
          <p className="m-0 text-13.5 leading-1.55 text-app-muted">{t('twoStep.offNote')}</p>
          <button type="button" className={buttonClass('primary', 'md', 'self-start')} onClick={() => setMode('setup')}>
            {t('twoStep.setUp')}
          </button>
        </div>
      ) : (
        <div className="flex flex-col gap-3" data-testid="two-step-on">
          <p className="m-0 text-13.5 leading-1.55">
            {t('twoStep.onNote', { date: state!.confirmed_at ? date(state!.confirmed_at) : '' })}{' '}
            <span className={state!.recovery_codes_left <= 3 ? 'font-semibold text-red' : 'text-app-muted'}>{t('twoStep.codesLeft', { count: state!.recovery_codes_left })}</span>
          </p>
          {mode === 'codes' ? (
            <CodeForm action="codes" onCancel={() => setMode('idle')} onCodes={setCodes} />
          ) : mode === 'off' ? (
            <CodeForm action="off" onCancel={() => setMode('idle')} onCodes={() => setMode('idle')} />
          ) : (
            <div className="flex flex-wrap gap-2">
              <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setMode('codes')}>
                {t('twoStep.newCodes')}
              </button>
              {state!.required ? (
                <span className="self-center text-12 text-app-muted">{t('twoStep.requiredCantTurnOff')}</span>
              ) : (
                <button type="button" className={buttonClass('danger', 'sm')} onClick={() => setMode('off')}>
                  {t('twoStep.turnOff')}
                </button>
              )}
            </div>
          )}
        </div>
      )}
    </Card>
  )
}

/** A code from the app for new recovery codes; the password and a code to turn it off. */
function CodeForm({ action, onCancel, onCodes }: { action: 'codes' | 'off'; onCancel: () => void; onCodes: (codes: string[]) => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const { reloadStaff } = useAuth()
  const renew = useNewRecoveryCodes()
  const disable = useDisableTwoFactor()
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const busy = renew.isPending || disable.isPending
  const error = action === 'codes' ? renew.error : disable.error
  const fieldError = (name: string) => (error instanceof ApiError ? error.field(name) : undefined)

  const submit = (event: FormEvent) => {
    event.preventDefault()
    if (busy || code.trim() === '' || (action === 'off' && password === '')) return
    if (action === 'codes') {
      renew.mutate(code.trim(), { onSuccess: (response) => onCodes(response.data.recovery_codes) })
    } else {
      disable.mutate(
        { password, code: code.trim() },
        {
          onSuccess: async () => {
            await reloadStaff()
            toast(t('twoStep.turnedOff'))
            onCodes([])
          },
        },
      )
    }
  }

  return (
    <form className="flex flex-col gap-3" onSubmit={submit} noValidate>
      <p className="m-0 text-13 text-app-muted">{action === 'codes' ? t('twoStep.newCodesNote') : t('twoStep.turnOffNote')}</p>
      {action === 'off' ? (
        <TextInput label={t('auth.password')} type="password" autoComplete="current-password" value={password} onChange={setPassword} error={fieldError('password')} className="max-w-72" />
      ) : null}
      <TextInput label={t('twoStep.codeLabel')} value={code} onChange={setCode} autoComplete="one-time-code" maxLength={20} error={fieldError('code')} className="max-w-48" />
      {error && !fieldError('code') && !fieldError('password') ? <ErrorNotice error={error} /> : null}
      <div className="flex flex-wrap gap-2">
        <button type="submit" className={buttonClass(action === 'off' ? 'danger' : 'primary', 'sm')} aria-disabled={busy}>
          {busy ? t('common.working') : action === 'codes' ? t('twoStep.makeCodes') : t('twoStep.turnOff')}
        </button>
        <button type="button" className={buttonClass('ghost', 'sm')} onClick={onCancel}>
          {t('common.cancel')}
        </button>
      </div>
    </form>
  )
}
