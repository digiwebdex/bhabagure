import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useConfirm, useToast } from '../../components/ui/feedback'
import { Pair, TextInput } from '../../components/ui/fields'
import { Badge, Card, CardTitle, Loading, PageHeader } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import { daysOf, holidayActions, useHolidayChange, useHolidayYear, type HolidayRange, type HolidayYear, type MyLeave } from './api'

/** The office week as it hangs on the wall: Saturday first, the Friday off last. JS weekday numbers (0 Sunday). */
const WEEK = [6, 0, 1, 2, 3, 4, 5] as const

/**
 * HR → Holiday calendar (docs/holiday-calendar.md), for every staff member: the year's holidays on twelve small months
 * and in a list, the weekly days off, and their own leave. Whoever holds holidays.manage — the super admin, unless
 * granted to others in Roles — adds holidays as date ranges, changes, removes and copies them from the year before.
 */
export function HolidayCalendarPage() {
  const { t } = useTranslation()
  const { number } = useFormat()
  const [params, setParams] = useSearchParams()
  const thisYear = Number(todayInDhaka().slice(0, 4))
  const year = /^\d{4}$/.test(params.get('year') ?? '') ? Number(params.get('year')) : thisYear
  const calendar = useHolidayYear(year)
  const data = calendar.data?.data
  const [editing, setEditing] = useState<HolidayRange | 'new' | null>(null)
  const [copying, setCopying] = useState(false)
  const setYear = (value: number) => setParams({ year: String(value) }, { replace: true })

  return (
    <>
      <PageHeader
        title={t('holidays.title')}
        subtitle={t('holidays.subtitle')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {data?.can_manage ? (
              <>
                <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setCopying(true)}>
                  {t('holidays.copy', { year: year - 1 })}
                </button>
                <button type="button" className={buttonClass('cta', 'sm')} onClick={() => setEditing('new')}>
                  {t('holidays.add')}
                </button>
              </>
            ) : null}
            <div className="flex items-center gap-2">
              <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setYear(year - 1)} aria-label={t('holidays.previousYear')} disabled={year <= 2020}>
                ←
              </button>
              <span className="min-w-14 text-center font-display text-15 font-semibold" data-testid="holiday-year">
                {year}
              </span>
              <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setYear(year + 1)} aria-label={t('holidays.nextYear')} disabled={year >= 2100}>
                →
              </button>
            </div>
          </div>
        }
      />
      {calendar.isError ? (
        <ErrorNotice error={calendar.error} />
      ) : !data ? (
        <Loading />
      ) : (
        <>
          <Summary data={data} />
          <div className="grid items-start gap-4.5 lg:grid-cols-[minmax(0,1.7fr)_minmax(300px,1fr)]">
            <Card className="order-2 lg:order-none">
              <CardTitle title={t('holidays.calendarTitle', { year })} aside={<span className="text-12 text-app-muted">{t('holidays.daysOff', { count: data.holiday_days, n: number(data.holiday_days) })}</span>} />
              <YearGrid data={data} />
              <Legend />
            </Card>
            <HolidayList data={data} onEdit={(holiday) => setEditing(holiday)} />
          </div>
        </>
      )}
      {editing ? <HolidayDialog holiday={editing === 'new' ? null : editing} year={year} onClose={() => setEditing(null)} /> : null}
      {copying ? <CopyDialog toYear={year} onClose={() => setCopying(false)} /> : null}
    </>
  )
}

/** The next holiday from today (this year's page and later ones), and how many the year has. */
function Summary({ data }: { data: HolidayYear }) {
  const { t } = useTranslation()
  const { number, dateRange, date } = useFormat()
  const today = todayInDhaka()
  const next = data.holidays.find((holiday) => holiday.ends_on >= today)

  return (
    <div className="flex flex-wrap gap-3" data-testid="holiday-summary">
      <Card className="min-w-60 flex-1">
        <span className="text-12 text-app-muted">{t('holidays.next')}</span>
        {next ? (
          <span className="flex flex-col gap-0.5">
            <strong className="text-16">{next.name_en}</strong>
            <span className="text-13 text-app-muted">
              {next.days > 1 ? dateRange(next.starts_on, next.ends_on) : date(next.starts_on)} · {t('holidays.dayCount', { count: next.days, n: number(next.days) })}
            </span>
          </span>
        ) : (
          <span className="text-13 text-app-muted">{t('holidays.nextNone', { year: data.year })}</span>
        )}
      </Card>
      <Card className="min-w-60 flex-1">
        <span className="text-12 text-app-muted">{t('holidays.inYear', { year: data.year })}</span>
        <strong className="text-16">{t('holidays.count', { count: data.holidays.length, n: number(data.holidays.length) })}</strong>
        <span className="text-13 text-app-muted">{t('holidays.daysOff', { count: data.holiday_days, n: number(data.holiday_days) })}</span>
      </Card>
    </div>
  )
}

/** Twelve small months: holidays coloured, weekly days off shaded, the person's own leave outlined, today marked. */
function YearGrid({ data }: { data: HolidayYear }) {
  const holidayByDay = useMemo(() => new Map(data.holidays.flatMap((holiday) => daysOf(holiday).map((day) => [day, holiday] as const))), [data.holidays])
  const leaveByDay = useMemo(() => new Map(data.my_leave.flatMap((leave) => daysOf(leave).map((day) => [day, leave] as const))), [data.my_leave])

  return (
    <div className="grid grid-cols-2 gap-x-3 gap-y-4 sm:grid-cols-3 sm:gap-4" data-testid="holiday-grid">
      {Array.from({ length: 12 }, (_, index) => (
        <MonthGrid key={index} year={data.year} month={index + 1} offDays={data.weekly_off_days[index] ?? []} holidayByDay={holidayByDay} leaveByDay={leaveByDay} />
      ))}
    </div>
  )
}

function MonthGrid({ year, month, offDays, holidayByDay, leaveByDay }: { year: number; month: number; offDays: number[]; holidayByDay: Map<string, HolidayRange>; leaveByDay: Map<string, MyLeave> }) {
  const { t } = useTranslation()
  const { month: monthLabel } = useFormat()
  const today = todayInDhaka()
  const key = `${year}-${String(month).padStart(2, '0')}`
  const first = new Date(Date.UTC(year, month - 1, 1))
  const length = new Date(Date.UTC(year, month, 0)).getUTCDate()
  const blanks = WEEK.indexOf(first.getUTCDay() as (typeof WEEK)[number])
  const cells: (string | null)[] = [...Array<null>(blanks).fill(null), ...Array.from({ length }, (_, day) => `${key}-${String(day + 1).padStart(2, '0')}`)]

  return (
    <div className="flex flex-col gap-1.5">
      <span className="text-13 font-semibold">{monthLabel(key)}</span>
      <div className="grid grid-cols-7 gap-0.5 text-center text-11" role="grid" aria-label={monthLabel(key)}>
        {WEEK.map((weekday) => (
          <span key={weekday} className="py-0.5 font-semibold text-app-muted" aria-hidden="true">
            {t(`holidays.week.${weekday}`)}
          </span>
        ))}
        {cells.map((day, index) => {
          if (day === null) return <span key={`blank-${index}`} />
          const weekday = new Date(`${day}T00:00:00Z`).getUTCDay()
          const iso = weekday === 0 ? 7 : weekday
          const holiday = holidayByDay.get(day)
          const leave = leaveByDay.get(day)
          const off = offDays.includes(iso)
          const label = [holiday?.name_en, off && !holiday ? t('holidays.legend.weekly') : null, leave ? t(leave.status === 'approved' ? 'holidays.legend.myLeave' : 'holidays.legend.pending') : null].filter(Boolean).join(' · ')
          return (
            <span
              key={day}
              title={label || undefined}
              data-day={day}
              data-kind={holiday ? 'holiday' : off ? 'off' : 'working'}
              className={`flex aspect-square items-center justify-center rounded-6 text-12 ${
                holiday ? 'bg-orange font-semibold text-white' : off ? 'bg-app-bg text-app-muted' : ''
              } ${leave ? (leave.status === 'approved' ? 'outline-2 -outline-offset-2 outline-green' : 'outline-2 -outline-offset-2 outline-dashed outline-green') : ''} ${day === today ? 'font-bold underline decoration-2 underline-offset-2' : ''}`}
            >
              {Number(day.slice(8))}
              {label ? <span className="sr-only">: {label}</span> : null}
            </span>
          )
        })}
      </div>
    </div>
  )
}

function Legend() {
  const { t } = useTranslation()
  const swatch = 'inline-block size-3.5 shrink-0 rounded-4'

  return (
    <div className="flex flex-wrap gap-x-4 gap-y-1.5 border-t border-app-line pt-3 text-12 text-app-muted">
      <span className="flex items-center gap-1.5">
        <span className={`${swatch} bg-orange`} /> {t('holidays.legend.holiday')}
      </span>
      <span className="flex items-center gap-1.5">
        <span className={`${swatch} border border-app-line bg-app-bg`} /> {t('holidays.legend.weekly')}
      </span>
      <span className="flex items-center gap-1.5">
        <span className={`${swatch} outline-2 -outline-offset-2 outline-green`} /> {t('holidays.legend.myLeave')}
      </span>
      <span className="flex items-center gap-1.5">
        <span className={`${swatch} outline-2 -outline-offset-2 outline-dashed outline-green`} /> {t('holidays.legend.pending')}
      </span>
    </div>
  )
}

/** The year's holidays in order; past ones muted. Edit and Remove for whoever keeps the calendar. */
function HolidayList({ data, onEdit }: { data: HolidayYear; onEdit: (holiday: HolidayRange) => void }) {
  const { t } = useTranslation()
  const { number, dateRange, weekdayDate } = useFormat()
  const toast = useToast()
  const { confirm, element } = useConfirm()
  const remove = useHolidayChange(holidayActions.remove)
  const today = todayInDhaka()
  const when = (holiday: HolidayRange) => (holiday.days > 1 ? dateRange(holiday.starts_on, holiday.ends_on) : weekdayDate(holiday.starts_on))

  return (
    <Card className="order-1 lg:order-none">
      <CardTitle title={t('holidays.listTitle', { year: data.year })} />
      {data.holidays.length === 0 ? (
        <p className="m-0 text-13 leading-1.55 text-app-muted">{data.can_manage ? t('holidays.emptyManage', { year: data.year, last: data.year - 1 }) : t('holidays.empty', { year: data.year })}</p>
      ) : (
        <ul className="m-0 flex list-none flex-col p-0" data-testid="holiday-list">
          {data.holidays.map((holiday) => (
            <li key={holiday.id} className={`flex flex-col gap-1 border-b border-app-line py-2.5 last:border-b-0 ${holiday.ends_on < today ? 'opacity-60' : ''}`}>
              <span className="flex items-start justify-between gap-3">
                <span className="flex min-w-0 flex-col">
                  <strong className="text-14">{holiday.name_en}</strong>
                  <span className="text-12 text-app-muted">{holiday.name_bn}</span>
                </span>
                <Badge tone="orange">{t('holidays.dayCount', { count: holiday.days, n: number(holiday.days) })}</Badge>
              </span>
              <span className="text-13 text-app-muted">{when(holiday)}</span>
              {data.can_manage ? (
                <span className="flex gap-3">
                  <button type="button" className="cursor-pointer text-12 font-semibold text-blue" onClick={() => onEdit(holiday)} aria-label={t('holidays.editNamed', { name: holiday.name_en })}>
                    {t('holidays.edit')}
                  </button>
                  <button
                    type="button"
                    className="cursor-pointer text-12 font-semibold text-red"
                    aria-label={t('holidays.removeNamed', { name: holiday.name_en })}
                    onClick={async () => {
                      if (await confirm(t('holidays.removeConfirm', { name: holiday.name_en, when: when(holiday) }))) remove.mutate(holiday.id, { onSuccess: () => toast(t('holidays.removed', { name: holiday.name_en })) })
                    }}
                  >
                    {t('holidays.remove')}
                  </button>
                </span>
              ) : null}
            </li>
          ))}
        </ul>
      )}
      {remove.error ? <ErrorNotice error={remove.error} /> : null}
      {element}
    </Card>
  )
}

/** Add a holiday, or change one: a single day, or From and To for a holiday of several days. */
function HolidayDialog({ holiday, year, onClose }: { holiday: HolidayRange | null; year: number; onClose: () => void }) {
  const { t } = useTranslation()
  const { number } = useFormat()
  const toast = useToast()
  const [form, setForm] = useState(holiday ? { starts_on: holiday.starts_on, ends_on: holiday.ends_on, name_en: holiday.name_en, name_bn: holiday.name_bn } : { starts_on: '', ends_on: '', name_en: '', name_bn: '' })
  const save = useHolidayChange((body: typeof form) => (holiday ? holidayActions.update({ id: holiday.id, ...body }) : holidayActions.add(body)))
  const fieldError = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)
  const ends = form.ends_on || form.starts_on
  const days = form.starts_on && ends >= form.starts_on ? daysOf({ starts_on: form.starts_on, ends_on: ends }).length : 0
  const ready = form.starts_on !== '' && days >= 1 && days <= 15 && form.name_en.trim() !== '' && form.name_bn.trim() !== ''

  return (
    <Dialog open onClose={onClose} title={holiday ? t('holidays.editTitle', { name: holiday.name_en }) : t('holidays.addTitle')}>
      <Pair>
        <TextInput
          label={t('holidays.from')}
          type="date"
          min={`${year - 1}-01-01`}
          value={form.starts_on}
          onChange={(starts_on) => setForm({ ...form, starts_on, ends_on: form.ends_on && form.ends_on >= starts_on ? form.ends_on : starts_on })}
          error={fieldError('starts_on')}
        />
        <TextInput label={t('holidays.to')} type="date" min={form.starts_on || undefined} value={ends} onChange={(ends_on) => setForm({ ...form, ends_on })} error={fieldError('ends_on')} hint={t('holidays.toHint')} />
      </Pair>
      <Pair>
        <TextInput label={t('holidays.nameEn')} value={form.name_en} onChange={(name_en) => setForm({ ...form, name_en })} error={fieldError('name_en')} maxLength={120} placeholder="Eid-ul-Fitr" />
        <TextInput label={t('holidays.nameBn')} value={form.name_bn} onChange={(name_bn) => setForm({ ...form, name_bn })} error={fieldError('name_bn')} maxLength={120} placeholder="ঈদুল ফিতর" />
      </Pair>
      <p className="m-0 text-13 text-app-muted" data-testid="holiday-days">
        {days > 15 ? t('holidays.tooLong') : days >= 1 ? t('holidays.dayCount', { count: days, n: number(days) }) : t('holidays.pickDates')}
      </p>
      {save.error && !(save.error instanceof ApiError && save.error.status === 422) ? <ErrorNotice error={save.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('primary')}
          disabled={!ready || save.isPending}
          onClick={() =>
            save.mutate(
              { ...form, ends_on: ends, name_en: form.name_en.trim(), name_bn: form.name_bn.trim() },
              {
                onSuccess: () => {
                  toast(holiday ? t('holidays.saved', { name: form.name_en.trim() }) : t('holidays.added', { name: form.name_en.trim() }))
                  onClose()
                },
              },
            )
          }
        >
          {t('common.save')}
        </button>
      </div>
    </Dialog>
  )
}

/** Copy last year's fixed-date holidays (21 February, 26 March, 16 December) onto the same days this year. */
function CopyDialog({ toYear, onClose }: { toYear: number; onClose: () => void }) {
  const { t } = useTranslation()
  const { number, dateRange, date } = useFormat()
  const toast = useToast()
  const fromYear = toYear - 1
  const source = useHolidayYear(fromYear)
  // Only holidays that start in that year: a range from the year before it belongs to that year's copy.
  const holidays = (source.data?.data.holidays ?? []).filter((holiday) => holiday.starts_on.startsWith(String(fromYear)))
  const [chosen, setChosen] = useState<string[]>([])
  const copy = useHolidayChange(holidayActions.copy)

  return (
    <Dialog open onClose={onClose} title={t('holidays.copyTitle', { from: fromYear, to: toYear })}>
      <p className="m-0 text-13 leading-1.55 text-app-muted">{t('holidays.copyNote', { to: toYear })}</p>
      {source.isPending ? (
        <Loading />
      ) : holidays.length === 0 ? (
        <p className="m-0 text-13 text-app-muted">{t('holidays.copyNone', { from: fromYear })}</p>
      ) : (
        <>
          <button type="button" className="cursor-pointer self-start text-12 font-semibold text-blue" onClick={() => setChosen(chosen.length === holidays.length ? [] : holidays.map((holiday) => holiday.id))}>
            {chosen.length === holidays.length ? t('holidays.copyNoneChosen') : t('holidays.copyAll')}
          </button>
          <ul className="m-0 flex max-h-80 list-none flex-col gap-1.5 overflow-y-auto p-0" data-testid="copy-list">
            {holidays.map((holiday) => (
              <li key={holiday.id}>
                <label className="flex cursor-pointer items-start gap-2.5 text-13">
                  <input type="checkbox" className="mt-0.5" checked={chosen.includes(holiday.id)} onChange={(event) => setChosen(event.target.checked ? [...chosen, holiday.id] : chosen.filter((id) => id !== holiday.id))} />
                  <span className="flex flex-col">
                    <span className="font-semibold">{holiday.name_en}</span>
                    <span className="text-12 text-app-muted">{holiday.days > 1 ? dateRange(holiday.starts_on, holiday.ends_on) : date(holiday.starts_on)}</span>
                  </span>
                </label>
              </li>
            ))}
          </ul>
        </>
      )}
      {copy.error ? <ErrorNotice error={copy.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('primary')}
          disabled={chosen.length === 0 || copy.isPending}
          onClick={() =>
            copy.mutate(
              { from_year: fromYear, to_year: toYear, holidays: chosen },
              {
                onSuccess: (response) => {
                  const { copied, skipped } = response.data
                  toast(skipped.length ? t('holidays.copiedSkipped', { copied: number(copied), names: skipped.join(', ') }) : t('holidays.copied', { count: copied, n: number(copied) }))
                  onClose()
                },
              },
            )
          }
        >
          {t('holidays.copyButton', { count: chosen.length, n: number(chosen.length) })}
        </button>
      </div>
    </Dialog>
  )
}
