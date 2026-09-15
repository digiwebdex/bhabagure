import { useTranslation } from 'react-i18next'

import { ErrorNotice } from '../../components/ui/feedback'
import { Badge, Card, CardTitle, Loading } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import { myPayslipPath, useMyPayslips, useOpenPayslip } from './api'

/** My payslips (docs/phase-7-hr-attendance-bonus-wallet.md §6): each finalised month, its pay, and whether it's paid. */
export function MyPayslipsCard() {
  const { t } = useTranslation()
  const { bdt, date, month } = useFormat()
  const payslips = useMyPayslips()
  const open = useOpenPayslip()

  return (
    <Card>
      <CardTitle bn="আমার পে-স্লিপ" en="My payslips" />
      {payslips.isPending ? (
        <Loading />
      ) : payslips.isError ? (
        <ErrorNotice error={payslips.error} />
      ) : payslips.data.data.length === 0 ? (
        <p className="m-0 text-13 text-app-muted">{t('myPayslips.none')}</p>
      ) : (
        <ul className="m-0 flex list-none flex-col gap-2 p-0" data-testid="my-payslips">
          {payslips.data.data.map((payslip) => (
            <li key={payslip.id} className="flex flex-wrap items-center justify-between gap-2 rounded-10 bg-app-surface-2 px-3 py-2 text-13">
              <span className="flex min-w-0 flex-col gap-0.5">
                <span className="font-medium">{month(payslip.month)}</span>
                <span className="font-display text-12 text-app-muted">{bdt(payslip.payable)}</span>
              </span>
              <span className="flex items-center gap-3">
                {payslip.paid_at ? <Badge tone="green">{t('myPayslips.paidOn', { date: date(payslip.paid_at) })}</Badge> : <Badge tone="orange">{t('payroll.unpaid')}</Badge>}
                <button type="button" className="cursor-pointer text-12 font-semibold text-blue" onClick={() => void open(myPayslipPath(payslip.id))} aria-label={t('myPayslips.openNamed', { month: month(payslip.month) })}>
                  {t('myPayslips.open')}
                </button>
              </span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
