import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useConfirm, useToast } from '../../components/ui/feedback'
import { Pair, SelectInput, TextInput } from '../../components/ui/fields'
import { Card, CardTitle, Loading } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { attendanceActions, useAttendanceChange, useHolidays, WEEKDAYS, type Rules, type SinglePunch } from './api'

/**
 * The design's rules strip (duty start and end, late-or-early pay, grace, working days), plus the weekly off days and what
 * a single punch counts as (docs/phase-7-hr-attendance-bonus-wallet.md §6). Saved for a month, they hold from that month
 * until a later change; earlier months keep their own.
 */
export function RulesCard({ month, rules, manage }: { month: string; rules: Rules; manage: boolean }) {
  // Remounted when the month's rules change, so the form starts from what the API holds.
  return <RulesForm key={`${month}|${JSON.stringify(rules)}`} month={month} rules={rules} manage={manage} />
}

function RulesForm({ month, rules, manage }: { month: string; rules: Rules; manage: boolean }) {
  const { t } = useTranslation()
  const { number } = useFormat()
  const toast = useToast()
  const [form, setForm] = useState({ ...rules })
  const save = useAttendanceChange(attendanceActions.saveRules)
  const fieldError = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)
  const changed = JSON.stringify(form) !== JSON.stringify(rules)

  return (
    <Card>
      <CardTitle bn="নিয়ম" en="Rules" aside={<span className="text-12 text-app-muted">{t('attendance.rulesFrom', { month: rules.effective_month })}</span>} />
      <fieldset disabled={!manage} className="m-0 flex flex-col gap-3 border-0 p-0" data-testid="attendance-rules">
        <Pair>
          <TextInput label={t('attendance.rules.dutyStart')} type="time" value={form.duty_start} onChange={(duty_start) => setForm({ ...form, duty_start })} error={fieldError('duty_start')} />
          <TextInput label={t('attendance.rules.dutyEnd')} type="time" value={form.duty_end} onChange={(duty_end) => setForm({ ...form, duty_end })} error={fieldError('duty_end')} />
        </Pair>
        <Pair>
          <SelectInput
            label={t('attendance.rules.latePay')}
            value={String(form.late_early_pay_percent)}
            onChange={(value) => setForm({ ...form, late_early_pay_percent: Number(value) })}
            options={[...new Set([0, 25, 50, 75, 100, form.late_early_pay_percent])].sort((a, b) => a - b).map((value) => ({ value: String(value), label: `${number(value)}%` }))}
          />
          <SelectInput
            label={t('attendance.rules.grace')}
            value={String(form.grace_minutes)}
            onChange={(value) => setForm({ ...form, grace_minutes: Number(value) })}
            options={[...new Set([0, 10, 15, 30, form.grace_minutes])].sort((a, b) => a - b).map((value) => ({ value: String(value), label: t('attendance.rules.minutes', { n: number(value) }) }))}
          />
        </Pair>
        <Pair>
          <TextInput label={t('attendance.rules.workingDays')} type="number" min={20} max={31} value={String(form.working_days_per_month)} onChange={(value) => setForm({ ...form, working_days_per_month: Number(value) })} error={fieldError('working_days_per_month')} />
          <SelectInput
            label={t('attendance.rules.singlePunch')}
            value={form.single_punch_counts_as}
            onChange={(value) => setForm({ ...form, single_punch_counts_as: value as SinglePunch })}
            options={(['late_early', 'absent', 'full'] as const).map((value) => ({ value, label: t(`attendance.rules.singlePunchAs.${value}`) }))}
          />
        </Pair>
        <fieldset className="m-0 flex flex-col gap-1.5 border-0 p-0">
          <legend className="mb-1 text-13 text-app-muted">{t('attendance.rules.weeklyOff')}</legend>
          <div className="flex flex-wrap gap-3">
            {WEEKDAYS.map((day) => (
              <label key={day} className="flex cursor-pointer items-center gap-1.5 text-13">
                <input
                  type="checkbox"
                  checked={form.weekly_off_days.includes(day)}
                  onChange={(event) => setForm({ ...form, weekly_off_days: event.target.checked ? [...form.weekly_off_days, day].sort() : form.weekly_off_days.filter((value) => value !== day) })}
                />
                {t(`attendance.weekdays.${day}`)}
              </label>
            ))}
          </div>
          {fieldError('weekly_off_days') ? <span className="text-12 font-semibold text-red">{fieldError('weekly_off_days')}</span> : null}
        </fieldset>
      </fieldset>
      <p className="m-0 text-12.5 leading-1.55 text-app-muted" data-testid="rule-note">
        {t('attendance.rules.note', { start: form.duty_start, end: form.duty_end, grace: number(form.grace_minutes), percent: number(form.late_early_pay_percent) })}
      </p>
      {save.error && !(save.error instanceof ApiError && save.error.status === 422) ? <ErrorNotice error={save.error} /> : null}
      {manage ? (
        <button
          type="button"
          className={buttonClass('primary', 'sm', 'self-start')}
          disabled={!changed || save.isPending}
          onClick={() =>
            save.mutate(
              {
                month,
                duty_start: form.duty_start,
                duty_end: form.duty_end,
                grace_minutes: form.grace_minutes,
                late_early_pay_percent: form.late_early_pay_percent,
                working_days_per_month: form.working_days_per_month,
                weekly_off_days: form.weekly_off_days,
                single_punch_counts_as: form.single_punch_counts_as,
              },
              { onSuccess: () => toast(t('attendance.rules.saved', { month })) },
            )
          }
        >
          {t('attendance.rules.saveFrom', { month })}
        </button>
      ) : null}
      <HolidaysList year={Number(month.slice(0, 4))} manage={manage} />
    </Card>
  )
}

function HolidaysList({ year, manage }: { year: number; manage: boolean }) {
  const { t } = useTranslation()
  const { date, locale } = useFormat()
  const toast = useToast()
  const { confirm, element } = useConfirm()
  const holidays = useHolidays(year)
  const remove = useAttendanceChange(attendanceActions.removeHoliday)
  const [adding, setAdding] = useState(false)

  return (
    <div className="flex flex-col gap-2 border-t border-app-line pt-3">
      <div className="flex items-center justify-between gap-2">
        <h3 className="m-0 text-14 font-semibold">{t('attendance.holidays', { year })}</h3>
        {manage ? (
          <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setAdding(true)}>
            {t('attendance.addHoliday')}
          </button>
        ) : null}
      </div>
      {holidays.isPending ? (
        <Loading />
      ) : holidays.data?.data.length ? (
        <ul className="m-0 flex list-none flex-col gap-1 p-0 text-13">
          {holidays.data.data.map((holiday) => (
            <li key={holiday.id} className="flex flex-wrap items-center justify-between gap-2">
              <span>
                <span className="font-display">{date(holiday.date)}</span> · {locale === 'en' ? holiday.name_en : holiday.name_bn}
              </span>
              {manage ? (
                <button
                  type="button"
                  className="cursor-pointer text-12 font-semibold text-red"
                  onClick={async () => {
                    if (await confirm(t('attendance.removeHolidayConfirm', { date: date(holiday.date) }))) remove.mutate(holiday.id, { onSuccess: () => toast(t('attendance.holidayRemoved')) })
                  }}
                >
                  {t('attendance.removeHoliday')}
                </button>
              ) : null}
            </li>
          ))}
        </ul>
      ) : (
        <p className="m-0 text-12.5 text-app-muted">{t('attendance.noHolidays')}</p>
      )}
      {adding ? <HolidayDialog onClose={() => setAdding(false)} /> : null}
      {element}
    </div>
  )
}

function HolidayDialog({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [form, setForm] = useState({ date: '', name_en: '', name_bn: '' })
  const add = useAttendanceChange(attendanceActions.addHoliday)
  const fieldError = (name: string) => (add.error instanceof ApiError ? add.error.field(name) : undefined)

  return (
    <Dialog open onClose={onClose} title={t('attendance.addHolidayTitle')}>
      <TextInput label={t('attendance.holidayDate')} type="date" value={form.date} onChange={(value) => setForm({ ...form, date: value })} error={fieldError('date')} />
      <Pair>
        <TextInput label={t('roles.nameEn')} value={form.name_en} onChange={(name_en) => setForm({ ...form, name_en })} error={fieldError('name_en')} maxLength={120} />
        <TextInput label={t('roles.nameBn')} value={form.name_bn} onChange={(name_bn) => setForm({ ...form, name_bn })} error={fieldError('name_bn')} maxLength={120} />
      </Pair>
      {add.error && !(add.error instanceof ApiError && add.error.status === 422) ? <ErrorNotice error={add.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button type="button" className={buttonClass('primary')} disabled={!form.date || !form.name_en.trim() || !form.name_bn.trim() || add.isPending} onClick={() => add.mutate(form, { onSuccess: () => { toast(t('attendance.holidayAdded')); onClose() } })}>
          {t('common.save')}
        </button>
      </div>
    </Dialog>
  )
}
