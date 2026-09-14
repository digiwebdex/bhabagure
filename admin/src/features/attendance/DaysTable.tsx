import { useTranslation } from 'react-i18next'

import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { Badge } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import type { Day, DayStatus } from './api'

const TONES: Record<DayStatus, 'green' | 'orange' | 'blue' | 'red' | 'slate'> = {
  full: 'green',
  in_progress: 'green',
  late: 'orange',
  early: 'orange',
  late_early: 'orange',
  single_punch: 'orange',
  leave: 'blue',
  worked_off: 'blue',
  absent: 'red',
  off: 'slate',
  holiday: 'slate',
  not_employed: 'slate',
  upcoming: 'slate',
}

export function DayStatusBadge({ day }: { day: Pick<Day, 'status' | 'leave' | 'holiday'> }) {
  const { t } = useTranslation()
  const { locale } = useFormat()
  const label =
    day.status === 'leave' && day.leave
      ? t(day.leave.paid ? 'attendance.status.leave_paid' : 'attendance.status.leave_unpaid')
      : day.status === 'holiday' && day.holiday
        ? `${t('attendance.status.holiday')} · ${locale === 'en' ? day.holiday.en : day.holiday.bn}`
        : t(`attendance.status.${day.status}`)
  return <Badge tone={TONES[day.status]}>{label}</Badge>
}

/**
 * A person's month, day by day: in, out, hours and status, with a mark on days set by hand. The punches the device
 * recorded stay visible beside a correction.
 */
export function DaysTable({ days, actions, testId }: { days: Day[]; actions?: (day: Day) => RowAction[]; testId: string }) {
  const { t } = useTranslation()
  const { date, digits, number } = useFormat()

  const columns: Column<Day>[] = [
    {
      key: 'date',
      header: t('attendance.columns.date'),
      cell: (day) => (
        <span className="flex flex-col">
          <span className="font-display">{date(day.date)}</span>
          <span className="text-12 text-app-muted">{t(`attendance.weekdays.${day.weekday}`)}</span>
        </span>
      ),
    },
    { key: 'in', header: t('attendance.columns.in'), cell: (day) => <span className="font-display">{day.in ? digits(day.in) : '—'}</span> },
    { key: 'out', header: t('attendance.columns.out'), cell: (day) => <span className="font-display">{day.out ? digits(day.out) : '—'}</span> },
    { key: 'hours', header: t('attendance.columns.hours'), align: 'right', cell: (day) => <span className="font-display">{day.minutes ? t('attendance.hoursShort', { n: number(Math.round(day.minutes / 6) / 10) }) : '—'}</span> },
    {
      key: 'status',
      header: t('common.status'),
      cell: (day) => (
        <span className="flex flex-col items-start gap-0.5">
          <DayStatusBadge day={day} />
          {day.corrected ? <span className="text-12 text-app-muted">{t('attendance.corrected', { punches: day.punches.length ? digits(day.punches.join(', ')) : t('attendance.noPunches') })}</span> : null}
        </span>
      ),
    },
  ]

  return <DataTable label={t('attendance.daysTitle')} testId={testId} columns={columns} rows={days} rowKey={(day) => day.date} rowLabel={(day) => date(day.date)} actions={actions} />
}
