import { useTranslation } from 'react-i18next'

import { useAuth } from '../../../app/auth'
import { ErrorNotice, useConfirm, useToast } from '../../../components/ui/feedback'
import { Switch } from '../../../components/ui/fields'
import { Card, CardTitle, Loading } from '../../../components/ui/layout'
import { useSecurity, useSetTwoFactorRequired } from '../../auth/twoFactor'

/**
 * Site settings → Security, for the super admin only (docs/two-step-sign-in.md): require two-step sign-in for everyone
 * with a login. Switched on, anyone without it sets it up at their next sign-in before using the admin.
 */
export function SecurityCard() {
  const { t } = useTranslation()
  const { session } = useAuth()
  const superAdmin = session.state === 'signed-in' && session.staff.is_super_admin
  const security = useSecurity(superAdmin)
  const save = useSetTwoFactorRequired()
  const toast = useToast()
  const { confirm, element } = useConfirm()

  if (!superAdmin) return null

  return (
    <Card>
      <CardTitle title={t('security.title')} />
      {security.isPending ? (
        <Loading />
      ) : security.isError ? (
        <ErrorNotice error={security.error} />
      ) : (
        <div className="flex flex-col gap-3" data-testid="security-card">
          <Switch
            label={t('security.require')}
            hint={t('security.requireHint')}
            checked={security.data.data.two_factor_required}
            onChange={async (required) => {
              if (save.isPending) return
              if (required && !(await confirm(t('security.requireConfirm')))) return
              save.mutate(required, { onSuccess: () => toast(required ? t('security.requiredOn') : t('security.requiredOff')) })
            }}
          />
          <p className="m-0 text-13 text-app-muted">{t('security.count', { on: security.data.data.two_factor_on, total: security.data.data.with_login })}</p>
          {save.error ? <ErrorNotice error={save.error} /> : null}
        </div>
      )}
      {element}
    </Card>
  )
}
