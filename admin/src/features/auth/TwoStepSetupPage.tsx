import { useTranslation } from 'react-i18next'
import { Navigate, useNavigate } from 'react-router'

import { useAuth, useStaff } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { AuthLayout } from './AuthLayout'
import { TwoStepSetup } from './TwoStepSetup'

/** Where the super admin's rule sends someone without two-step sign-in: nothing else opens until it is set up. */
export function TwoStepSetupPage() {
  const { t } = useTranslation()
  const { signOut } = useAuth()
  const staff = useStaff()
  const navigate = useNavigate()

  if (!staff.two_factor_setup_required && !staff.two_factor_enabled) return <Navigate to="/" replace />

  return (
    <AuthLayout title={t('twoStep.requiredTitle')} subtitle={t('twoStep.requiredNote')}>
      <TwoStepSetup onDone={() => navigate('/', { replace: true })} />
      <button type="button" className={buttonClass('ghost', 'sm', 'mt-2 self-start')} onClick={() => void signOut()}>
        {t('shell.signOut')}
      </button>
    </AuthLayout>
  )
}
