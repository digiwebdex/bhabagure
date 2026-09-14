import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate, useLocation } from 'react-router'

import { EmptyState, Loading } from '../components/ui/layout'
import { useAuth } from './auth'

/** Signed in, password already changed, and holding one of the permissions. */
export function Require({ permissions, children }: { permissions?: string[]; children: ReactNode }) {
  const { session, can } = useAuth()
  const location = useLocation()
  const { t } = useTranslation()

  if (session.state === 'loading') return <Loading />
  if (session.state === 'signed-out') return <Navigate to="/login" replace state={{ from: location.pathname }} />
  if (session.staff.must_change_password && location.pathname !== '/change-password') return <Navigate to="/change-password" replace />
  if (permissions && !can(...permissions)) return <EmptyState title={t('errors.forbiddenTitle')} note={t('errors.forbiddenNote')} />

  return children
}

export function NotFound() {
  const { t } = useTranslation()
  return <EmptyState title={t('errors.notFoundTitle')} />
}
