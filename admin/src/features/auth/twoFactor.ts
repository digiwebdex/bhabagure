import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'

/** api/app/Http/Controllers/Api/V1/Auth/TwoFactorController.php (docs/two-step-sign-in.md). */
export type TwoFactorStatus = { enabled: boolean; confirmed_at: string | null; recovery_codes_left: number; required: boolean }
/** The secret and its otpauth:// address, shown while setting up only. */
export type TwoFactorSetup = { secret: string; uri: string }
/** Recovery codes come back once, when made; never again. */
export type WithRecoveryCodes = TwoFactorStatus & { recovery_codes: string[] }

const KEY = ['two-factor'] as const

export const useTwoFactor = () => useQuery({ queryKey: KEY, queryFn: ({ signal }) => api.get<Data<TwoFactorStatus>>('staff/auth/two-factor', signal) })

function useTwoFactorAction<TVariables, TResult>(send: (variables: TVariables) => Promise<Data<TResult>>) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: send,
    onSuccess: (response) => {
      const status = response.data as Partial<TwoFactorStatus>
      if (typeof status.enabled === 'boolean') void client.invalidateQueries({ queryKey: KEY })
    },
  })
}

export const useStartTwoFactor = () => useTwoFactorAction(() => api.post<Data<TwoFactorSetup>>('staff/auth/two-factor/setup'))
export const useConfirmTwoFactor = () => useTwoFactorAction((code: string) => api.post<Data<WithRecoveryCodes>>('staff/auth/two-factor/confirm', { code }))
export const useNewRecoveryCodes = () => useTwoFactorAction((code: string) => api.post<Data<WithRecoveryCodes>>('staff/auth/two-factor/recovery-codes', { code }))
export const useDisableTwoFactor = () =>
  useTwoFactorAction((body: { password: string; code: string }) => api.post<Data<TwoFactorStatus>>('staff/auth/two-factor/disable', body))

/** Site settings → Security (super admin): the rule, and how many with a login have it on. */
export type SecurityState = { two_factor_required: boolean; with_login: number; two_factor_on: number }
export const useSecurity = (enabled: boolean) =>
  useQuery({ queryKey: ['security'], queryFn: ({ signal }) => api.get<Data<SecurityState>>('admin/security', signal), enabled })
export function useSetTwoFactorRequired() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: (required: boolean) => api.put<Data<SecurityState>>('admin/security', { two_factor_required: required }),
    onSuccess: (response) => client.setQueryData(['security'], response),
  })
}
