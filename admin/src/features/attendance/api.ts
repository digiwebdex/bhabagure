import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'

import { useToast } from '../../components/ui/feedback'
import { api, ApiError, downloadDocument } from '../../lib/api/client'
import type { Data, Paginated } from '../../lib/api/types'
import type { StaffRole, StaffStatus } from '../staff/api'

/** Shapes from api/app/Http/Controllers/Api/V1/Admin/{Attendance,AttendanceDevice,LeaveRequest,MyAttendance}Controller.php (docs/phase-7-hr-attendance-bonus-wallet.md §5). */

/** no_address: the agent's latest report says it has no device address, neither from here nor in its agent.ini. */
export type DeviceState = 'revoked' | 'waiting_for_agent' | 'pc_silent' | 'no_address' | 'device_unreachable' | 'ok'
export type DeviceCommand = 'pull' | 'test' | 'set_clock'

export type SyncEvent = {
  id: number
  /** config: the device address set on the card (status address_set, detail from and to as "address:port" or null). */
  kind: 'report' | 'punches' | 'command' | 'token' | 'config'
  status: string | null
  received: number
  stored: number
  duplicates: number
  rejected: number
  detail: Record<string, string | number | null | Record<string, number>> | null
  created_at: string | null
}

export type AttendanceDevice = {
  id: number
  name: string
  state: DeviceState
  serial_number: string | null
  model: string | null
  firmware: string | null
  /** The device's address on the office network as set on the card; the agent picks it up at its next check-in. */
  device_address: string | null
  device_port: number
  /** What the agent last read the device at, "address:port". */
  reported_address: string | null
  agent_version: string | null
  last_check_in_at: string | null
  last_report_at: string | null
  last_pull_ok_at: string | null
  last_status: string | null
  last_error: string | null
  /** Device clock minus the office PC's, in seconds. */
  clock_offset_seconds: number | null
  users_count: number | null
  fingers_count: number | null
  records_count: number | null
  pending_command: DeviceCommand | null
  command_requested_at: string | null
  command_sent_at: string | null
  command_not_picked_up: boolean
  replacement_allowed_at: string | null
  token_rotated_at: string | null
  revoked_at: string | null
  created_at: string | null
  device_users: { mapped: number; unmapped: number; ignored: number }
  events: SyncEvent[]
}

/** The office agent's zip on the server (storage/app/private/attendance-agent/latest.json), or null when none is there. */
export type AgentPackage = { version: string; filename: string; bytes: number; sha256: string; uploaded_at: string }
export type DeviceList = Data<AttendanceDevice[]> & { meta: { agent_package: AgentPackage | null } }

/**
 * on_device: the ID was in the device's user list at its latest report with one. An ID known only from punches (deleted
 * from the device, say) has no name and isn't on the device.
 */
export type DeviceUser = { id: number; device_user_id: string; name_on_device: string | null; on_device: boolean; staff: { id: number; name: string; employee_code: string } | null; ignored: boolean; last_listed_at: string | null }
/** Who an ID can be matched to: invited staff too, who haven't signed in yet but already punch. */
export type DeviceUsers = { users: DeviceUser[]; staff: { id: number; name: string; employee_code: string; status: StaffStatus }[] }

export type DayStatus = 'full' | 'late' | 'early' | 'late_early' | 'single_punch' | 'leave' | 'absent' | 'off' | 'holiday' | 'worked_off' | 'not_employed' | 'in_progress' | 'upcoming'

export type Day = {
  date: string
  weekday: number
  kind: 'working' | 'off' | 'holiday'
  holiday: { en: string; bn: string } | null
  status: DayStatus
  in: string | null
  out: string | null
  punches: string[]
  corrected: boolean
  /** id null and manual true for leave set by hand from the attendance page, not a leave request. */
  leave: { id: number | null; paid: boolean; manual: boolean } | null
  minutes: number
}

export type Totals = {
  working_days: number
  full: number
  late: number
  early: number
  late_early: number
  single_punch: number
  leave_paid: number
  leave_unpaid: number
  absent: number
  off: number
  holiday: number
  worked_off: number
  upcoming: number
  not_employed_working_days: number
  reduced_days: number
  absent_days: number
  hours: number
}

export type SinglePunch = 'late_early' | 'absent' | 'full'

export type Rules = {
  effective_month: string
  duty_start: string
  duty_end: string
  grace_minutes: number
  late_early_pay_percent: number
  working_days_per_month: number
  weekly_off_days: number[]
  single_punch_counts_as: SinglePunch
}

export type Holiday = { id: number; date: string; name_en: string; name_bn: string }
export type Person = { id: number; name: string; employee_code: string; status: string; role: StaffRole | null; designation: string | null }

export type MonthData = {
  month: string
  rules: Rules
  holidays: Holiday[]
  kpis: { attendance_percent: number | null; full_days: number; reduced_days: number; absent_days: number; pending_leave: number }
  rows: { staff: Person; totals: Totals; today: Day | null }[]
}

/** A time fixed by hand (in, out), or the whole day's status (docs/staff-without-access.md §3): worked is Present. */
export const DAY_STATUS_KINDS = ['worked', 'absent', 'leave_paid', 'leave_unpaid'] as const
export type CorrectionKind = 'in' | 'out' | (typeof DAY_STATUS_KINDS)[number]

export type Correction = { id: number; work_date: string; kind: CorrectionKind | 'reversal'; time: string | null; reason: string; reverses_id: number | null; by?: string | null; created_at: string | null }

export type LeaveStatus = 'pending' | 'approved' | 'rejected' | 'cancelled' | 'revoked'
export type LeaveRow = {
  id: number
  staff: { id: number; name?: string; employee_code?: string }
  starts_on: string
  ends_on: string
  calendar_days: number
  working_days: number
  reason: string
  status: LeaveStatus
  paid: boolean
  filed_by: string | null
  filed_for_someone: boolean
  decided_by: string | null
  decided_at: string | null
  decision_note: string | null
  created_at: string | null
  events?: { action: string; by: string | null; note: string | null; at: string | null }[]
}

/** attendance_tracked false: their staff record says they don't use the fingerprint device, so these days count for nothing. */
export type PersonMonth = { staff: Person; month: string; attendance_tracked: boolean; rules: Rules; days: Day[]; totals: Totals; corrections: Correction[]; leave: LeaveRow[] }

export const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7] as const

/** YYYY-MM for today in Dhaka, and the months either side. */
export function shiftMonth(month: string, by: number): string {
  const [year, index] = month.split('-').map(Number)
  const date = new Date(Date.UTC(year, index - 1 + by, 1))
  return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`
}

export function useDevices() {
  return useQuery({ queryKey: ['attendance-devices'], queryFn: ({ signal }) => api.get<DeviceList>('admin/attendance/devices', signal), refetchInterval: 30_000 })
}

/**
 * Saves the office agent's zip under the name the API sends (BhabaghureAttendanceAgent-<version>.zip); attendance.manage
 * only. It comes through the API with the staff token, so it can't be a plain link. If it has gone from the server
 * since the card loaded, the card is refreshed and says so.
 */
export function useAgentDownload() {
  const { t } = useTranslation()
  const toast = useToast()
  const client = useQueryClient()
  return async (fallback: string) => {
    try {
      await downloadDocument('admin/attendance/agent-package', fallback)
    } catch (error) {
      toast(t(error instanceof ApiError && error.code === 'agent_package_missing' ? 'attendance.agent.gone' : 'attendance.agent.failed'), 'error')
      void client.invalidateQueries({ queryKey: ['attendance-devices'] })
    }
  }
}

export function useDeviceAction<TVariables, TResponse>(send: (variables: TVariables) => Promise<TResponse>) {
  const client = useQueryClient()
  return useMutation({ mutationFn: send, onSuccess: () => void client.invalidateQueries({ queryKey: ['attendance-devices'] }) })
}

export const deviceActions = {
  create: (name: string) => api.post<Data<AttendanceDevice> & { token: string }>('admin/attendance/devices', { name }),
  rotate: (id: number) => api.post<Data<AttendanceDevice> & { token: string }>(`admin/attendance/devices/${id}/rotate-token`),
  revoke: (id: number) => api.post<Data<AttendanceDevice>>(`admin/attendance/devices/${id}/revoke`),
  command: ({ id, command }: { id: number; command: DeviceCommand }) => api.post<Data<AttendanceDevice>>(`admin/attendance/devices/${id}/commands`, { command }),
  allowReplacement: (id: number) => api.post<Data<AttendanceDevice>>(`admin/attendance/devices/${id}/allow-replacement`),
  /** A private IPv4 address, or null to clear it; the port defaults to 4370 when left out. */
  setAddress: ({ id, address, port }: { id: number; address: string | null; port?: number }) => api.put<Data<AttendanceDevice>>(`admin/attendance/devices/${id}/address`, { address, port }),
}

export function useDeviceUsers(id: number) {
  return useQuery({ queryKey: ['attendance-device-users', id], queryFn: ({ signal }) => api.get<Data<DeviceUsers>>(`admin/attendance/devices/${id}/users`, signal) })
}

export function useMapDeviceUser(deviceId: number) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: ({ userId, staffId, ignored }: { userId: number; staffId: number | null; ignored: boolean }) =>
      api.put<Data<DeviceUsers>>(`admin/attendance/device-users/${userId}`, { staff_id: staffId, ignored }),
    onSuccess: (response) => {
      client.setQueryData(['attendance-device-users', deviceId], response)
      void client.invalidateQueries({ queryKey: ['attendance-devices'] })
      void client.invalidateQueries({ queryKey: ['attendance-month'] })
      void client.invalidateQueries({ queryKey: ['attendance-person'] })
    },
  })
}

export function useAttendanceMonth(month: string) {
  return useQuery({ queryKey: ['attendance-month', month], queryFn: ({ signal }) => api.get<Data<MonthData>>(`admin/attendance/month?month=${month}`, signal), placeholderData: (previous) => previous })
}

export function usePersonMonth(id: number, month: string) {
  return useQuery({ queryKey: ['attendance-person', id, month], queryFn: ({ signal }) => api.get<Data<PersonMonth>>(`admin/attendance/staff/${id}?month=${month}`, signal), placeholderData: (previous) => previous })
}

export function useMyMonth(month: string) {
  return useQuery({ queryKey: ['my-attendance', month], queryFn: ({ signal }) => api.get<Data<PersonMonth>>(`admin/profile/attendance?month=${month}`, signal), placeholderData: (previous) => previous })
}

export function useRules(month: string) {
  return useQuery({ queryKey: ['attendance-rules', month], queryFn: ({ signal }) => api.get<Data<{ month: string; rules: Rules; versions: Rules[] }>>(`admin/attendance/rules?month=${month}`, signal) })
}

/** Rules, leave and corrections change what the month shows (holidays: features/holidays). */
export function useAttendanceChange<TVariables, TResponse>(send: (variables: TVariables) => Promise<TResponse>) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: send,
    onSuccess: () => {
      for (const key of ['attendance-month', 'attendance-person', 'attendance-rules', 'holiday-calendar', 'my-attendance', 'leave-requests', 'my-leave']) void client.invalidateQueries({ queryKey: [key] })
    },
  })
}

export type RulesInput = Omit<Rules, 'effective_month'> & { month: string }

export const attendanceActions = {
  saveRules: (body: RulesInput) => api.put<Data<{ month: string; rules: Rules; versions: Rules[] }>>('admin/attendance/rules', body),
  correct: (body: { staff_id: number; work_date: string; kind: CorrectionKind; time: string | null; until: string | null; reason: string }) => api.post<Data<PersonMonth>>('admin/attendance/corrections', body),
  reverse: ({ id, reason }: { id: number; reason: string }) => api.post<Data<PersonMonth>>(`admin/attendance/corrections/${id}/reverse`, { reason }),
}

// ── Leave ───────────────────────────────────────────────────────────────────────────────────────

export type LeaveFilter = LeaveStatus | 'all'

export function useLeaveRequests(status: LeaveFilter, page: number, enabled = true) {
  return useQuery({
    queryKey: ['leave-requests', status, page],
    queryFn: ({ signal }) => api.get<Paginated<LeaveRow>>(`admin/leave-requests?status=${status}&page=${page}`, signal),
    placeholderData: (previous) => previous,
    enabled,
  })
}

export const leaveActions = {
  record: (body: { staff_id: number; starts_on: string; ends_on: string; reason: string }) => api.post<Data<LeaveRow>>('admin/leave-requests', body),
  approve: ({ id, paid, note }: { id: number; paid: boolean; note: string }) => api.post<Data<LeaveRow>>(`admin/leave-requests/${id}/approve`, { paid, note: note || null }),
  reject: ({ id, note }: { id: number; note: string }) => api.post<Data<LeaveRow>>(`admin/leave-requests/${id}/reject`, { note }),
  revoke: ({ id, note }: { id: number; note: string }) => api.post<Data<LeaveRow>>(`admin/leave-requests/${id}/revoke`, { note }),
}

export function useMyLeave() {
  return useQuery({ queryKey: ['my-leave'], queryFn: ({ signal }) => api.get<Data<LeaveRow[]>>('admin/profile/leave-requests', signal) })
}

export const myLeaveActions = {
  file: (body: { starts_on: string; ends_on: string; reason: string }) => api.post<Data<LeaveRow>>('admin/profile/leave-requests', body),
  cancel: (id: number) => api.post<Data<LeaveRow>>(`admin/profile/leave-requests/${id}/cancel`),
}
