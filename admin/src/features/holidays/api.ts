import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'

/** Shapes from api/app/Services/Attendance/HolidayCalendar.php (docs/holiday-calendar.md). */

/** One holiday: a single day or a date range, kept as one. */
export type HolidayRange = { id: string; starts_on: string; ends_on: string; days: number; name_en: string; name_bn: string }

export type MyLeave = { starts_on: string; ends_on: string; status: 'approved' | 'pending'; paid: boolean }

export type HolidayYear = {
  year: number
  holidays: HolidayRange[]
  /** Days off for holidays inside the year. */
  holiday_days: number
  /** ISO weekdays (1 Monday … 7 Sunday) off each month, January first. */
  weekly_off_days: number[][]
  my_leave: MyLeave[]
  can_manage: boolean
}

export type HolidayInput = { starts_on: string; ends_on: string; name_en: string; name_bn: string }

export function useHolidayYear(year: number) {
  return useQuery({ queryKey: ['holiday-calendar', year], queryFn: ({ signal }) => api.get<Data<HolidayYear>>(`admin/profile/holidays?year=${year}`, signal), placeholderData: (previous) => previous })
}

/** A holiday changes the calendar, and what attendance and salary count as working days. */
export function useHolidayChange<TVariables, TResponse>(send: (variables: TVariables) => Promise<TResponse>) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: send,
    onSuccess: () =>
      Promise.all(['holiday-calendar', 'attendance-month', 'attendance-person', 'my-attendance', 'leave-requests', 'my-leave', 'payroll'].map((key) => client.invalidateQueries({ queryKey: [key] }))),
  })
}

export const holidayActions = {
  add: (body: HolidayInput) => api.post<Data<HolidayRange>>('admin/holidays', body),
  update: ({ id, ...body }: HolidayInput & { id: string }) => api.put<Data<HolidayRange>>(`admin/holidays/${id}`, body),
  remove: (id: string) => api.delete<Data<null>>(`admin/holidays/${id}`),
  copy: (body: { from_year: number; to_year: number; holidays: string[] }) => api.post<Data<{ copied: number; skipped: string[] }>>('admin/holidays/copy', body),
}

/** Each day of a holiday, YYYY-MM-DD. */
export function daysOf(holiday: Pick<HolidayRange, 'starts_on' | 'ends_on'>): string[] {
  const days: string[] = []
  for (let day = new Date(`${holiday.starts_on}T00:00:00Z`); day <= new Date(`${holiday.ends_on}T00:00:00Z`); day.setUTCDate(day.getUTCDate() + 1)) {
    days.push(day.toISOString().slice(0, 10))
  }
  return days
}
