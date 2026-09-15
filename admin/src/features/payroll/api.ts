import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'

import { useToast } from '../../components/ui/feedback'
import { api, fetchDocument } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'
import type { Person, Rules, Totals } from '../attendance/api'

/** Shapes from api/app/Http/Controllers/Api/V1/Admin/PayrollController.php (docs/phase-7-hr-attendance-bonus-wallet.md §6). */

export type Figures = { day_rate: number; deduction_days: number; deductions: number; adjustments: number; payable: number }
export type Adjustment = { id: number; amount: number; reason: string; by: string | null }

export type PayrollRow = {
  staff: Person
  totals: Totals
  /** null: no base salary for the month, so not on its payroll. */
  base: number | null
  figures: Figures | null
  adjustments: Adjustment[]
  /** Set once the month is finalised. */
  item_id: number | null
  paid_at: string | null
  paid_by: string | null
  transaction_id: number | null
  method: string | null
  reference: string | null
}

export type PayrollSheet = {
  month: string
  status: 'draft' | 'finalised'
  month_over: boolean
  finalised_at: string | null
  finalised_by: string | null
  methods: string[]
  rules: Rules
  rows: PayrollRow[]
  totals: { base: number; deductions: number; adjustments: number; payable: number; paid: number; missing_salary: number }
  actions: { adjust: boolean; finalise: boolean; reopen: boolean; pay: boolean }
}

export type SalaryChange = { id: number; effective_month: string; amount: number; reason: string; by: string | null; created_at: string | null }
export type MyPayslip = { id: number; month: string; payable: number; paid_at: string | null }

export function usePayrollSheet(month: string, enabled = true) {
  return useQuery({
    queryKey: ['payroll', month],
    queryFn: ({ signal }) => api.get<Data<PayrollSheet>>(`admin/payroll?month=${month}`, signal),
    placeholderData: (previous) => previous,
    enabled,
  })
}

export function useSalaries(staffId: number) {
  return useQuery({ queryKey: ['salaries', staffId], queryFn: ({ signal }) => api.get<Data<SalaryChange[]>>(`admin/staff/${staffId}/salaries`, signal) })
}

export function useMyPayslips() {
  return useQuery({ queryKey: ['my-payslips'], queryFn: ({ signal }) => api.get<Data<MyPayslip[]>>('admin/profile/payslips', signal) })
}

/** Every payroll change moves the sheet, the attendance table's salary columns, payslips and (paying) the cash book. */
export function usePayrollChange<TVariables, TResponse>(send: (variables: TVariables) => Promise<TResponse>) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: send,
    onSuccess: () => {
      for (const key of ['payroll', 'salaries', 'my-payslips', 'payments', 'attendance-month']) void client.invalidateQueries({ queryKey: [key] })
    },
  })
}

export const payrollActions = {
  adjust: ({ month, ...body }: { month: string; staff_id: number; amount: number; reason: string }) => api.post<Data<PayrollSheet>>(`admin/payroll/${month}/adjustments`, body),
  removeAdjustment: (id: number) => api.delete<Data<PayrollSheet>>(`admin/payroll-adjustments/${id}`),
  finalise: (month: string) => api.post<Data<PayrollSheet>>(`admin/payroll/${month}/finalise`),
  reopen: ({ month, reason }: { month: string; reason: string }) => api.post<Data<PayrollSheet>>(`admin/payroll/${month}/reopen`, { reason }),
  /** Multipart: the receipt goes with it. */
  pay: ({ id, form }: { id: number; form: FormData }) => api.post<Data<PayrollSheet>>(`admin/payroll-items/${id}/pay`, form),
  setSalary: ({ staffId, ...body }: { staffId: number; month: string; amount: number; reason: string }) => api.post<Data<SalaryChange[]>>(`admin/staff/${staffId}/salaries`, body),
}

/** Opens a payslip PDF from memory (the token can't ride on a link); never cached, as it states a salary. */
export function useOpenPayslip() {
  const { t, i18n } = useTranslation()
  const toast = useToast()
  return async (path: string) => {
    // Opened before the request so the browser treats it as the click, not a pop-up.
    const tab = window.open('', '_blank')
    try {
      const url = URL.createObjectURL(await fetchDocument(`${path}?locale=${i18n.resolvedLanguage === 'en' ? 'en' : 'bn'}`))
      if (tab) tab.location.href = url
      else window.open(url, '_blank', 'noopener')
      setTimeout(() => URL.revokeObjectURL(url), 60_000)
    } catch {
      tab?.close()
      toast(t('payroll.payslipFailed'), 'error')
    }
  }
}

export const payslipPath = (itemId: number) => `admin/payroll-items/${itemId}/payslip`
export const myPayslipPath = (itemId: number) => `admin/profile/payslips/${itemId}`
