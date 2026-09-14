import type { TFunction } from 'i18next'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useConfirm, useToast } from '../../components/ui/feedback'
import { SelectInput, TextInput } from '../../components/ui/fields'
import { Badge, Card, CardTitle, Loading } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import { deviceActions, useDeviceAction, useDevices, useDeviceUsers, useMapDeviceUser, type AttendanceDevice, type DeviceCommand, type DeviceState, type SyncEvent } from './api'

const STATE_TONES: Record<DeviceState, 'green' | 'orange' | 'red' | 'slate'> = { ok: 'green', waiting_for_agent: 'orange', pc_silent: 'red', device_unreachable: 'red', revoked: 'slate' }

/** Drift beyond this and the card offers to set the device clock. */
const DRIFT_SECONDS = 120

/**
 * The design's device card and sync log (docs/phase-7-hr-attendance-bonus-wallet.md §5.1). Everything shown is what the
 * office agent last reported; the buttons leave a command for its next check-in, within a minute.
 */
export function DevicesPanel() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const devices = useDevices()
  const [adding, setAdding] = useState(false)
  const [token, setToken] = useState<{ name: string; token: string } | null>(null)
  const manage = can('attendance.manage')

  if (devices.isPending) return <Loading />
  if (devices.isError) return <ErrorNotice error={devices.error} />

  return (
    <>
      {devices.data.data.length === 0 ? (
        <Card>
          <CardTitle bn="হাজিরা ডিভাইস" en="Attendance device" />
          <p className="m-0 text-13.5 leading-1.6 text-app-muted">{t('attendance.noDevice')}</p>
          {manage ? (
            <button type="button" className={buttonClass('cta', 'md', 'self-start')} onClick={() => setAdding(true)}>
              {t('attendance.addDevice')}
            </button>
          ) : null}
        </Card>
      ) : (
        devices.data.data.map((device) => <DeviceCard key={device.id} device={device} manage={manage} onToken={(value) => setToken({ name: device.name, token: value })} />)
      )}
      {adding ? <AddDeviceDialog onClose={() => setAdding(false)} onToken={(name, value) => { setAdding(false); setToken({ name, token: value }) }} /> : null}
      {token ? <TokenDialog name={token.name} token={token.token} onClose={() => setToken(null)} /> : null}
    </>
  )
}

function DeviceCard({ device, manage, onToken }: { device: AttendanceDevice; manage: boolean; onToken: (token: string) => void }) {
  const { t } = useTranslation()
  const { dateTime, digits, number } = useFormat()
  const toast = useToast()
  const { confirm, element } = useConfirm()
  const [mapping, setMapping] = useState(false)
  const command = useDeviceAction(deviceActions.command)
  const rotate = useDeviceAction(deviceActions.rotate)
  const revoke = useDeviceAction(deviceActions.revoke)
  const replace = useDeviceAction(deviceActions.allowReplacement)
  const drift = device.clock_offset_seconds ?? 0
  const changed = device.events.find((event) => event.kind === 'report')?.status === 'device_changed'

  const send = (value: DeviceCommand) =>
    command.mutate({ id: device.id, command: value }, { onSuccess: () => toast(t('attendance.commandSent', { command: t(`attendance.commands.${value}`) })), onError: (error) => toast(error.message, 'error') })

  const rows: [string, string][] = [
    [t('attendance.device.model'), [device.name, device.model].filter(Boolean).join(' · ')],
    [t('attendance.device.serial'), device.serial_number ?? '—'],
    [t('attendance.device.address'), device.reported_address ? digits(device.reported_address) : '—'],
    [t('attendance.device.enrolled'), device.users_count === null ? '—' : t('attendance.device.enrolledValue', { users: number(device.users_count), fingers: number(device.fingers_count ?? 0) })],
    [t('attendance.device.records'), device.records_count === null ? '—' : number(device.records_count)],
    [t('attendance.device.clock'), device.clock_offset_seconds === null ? '—' : Math.abs(drift) < 60 ? t('attendance.device.clockOk') : t(drift > 0 ? 'attendance.device.clockFast' : 'attendance.device.clockSlow', { minutes: number(Math.round(Math.abs(drift) / 60)) })],
    [t('attendance.device.lastCheckIn'), device.last_check_in_at ? dateTime(device.last_check_in_at) : t('attendance.device.never')],
    [t('attendance.device.lastSync'), device.last_pull_ok_at ? dateTime(device.last_pull_ok_at) : t('attendance.device.never')],
    [t('attendance.device.agent'), device.agent_version ?? '—'],
  ]

  return (
    <Card>
      <CardTitle bn="হাজিরা ডিভাইস" en="Attendance device" aside={<Badge tone={STATE_TONES[device.state]}>{t(`attendance.states.${device.state}`)}</Badge>} />
      <dl className="m-0 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-13" data-testid="device-card">
        {rows.map(([label, value]) => (
          <div key={label} className="contents">
            <dt className="text-app-muted">{label}</dt>
            <dd className="m-0 min-w-0 font-display break-words">{value}</dd>
          </div>
        ))}
      </dl>
      {device.state === 'device_unreachable' && device.last_error ? <p className="m-0 rounded-10 bg-red-tint px-3 py-2 text-12.5 text-red">{device.last_error}</p> : null}
      {changed && manage ? (
        <p className="m-0 flex flex-wrap items-center gap-2 rounded-10 bg-orange-tint px-3 py-2 text-12.5 text-amber">
          {t('attendance.deviceChanged')}
          <button type="button" className={buttonClass('outline', 'sm')} disabled={replace.isPending} onClick={async () => { if (await confirm(t('attendance.replaceConfirm'))) replace.mutate(device.id, { onSuccess: () => toast(t('attendance.replaceAllowed')) }) }}>
            {t('attendance.confirmReplacement')}
          </button>
        </p>
      ) : null}
      {device.pending_command ? (
        <p className="m-0 text-12.5 text-app-muted" role="status">
          {device.command_not_picked_up ? t('attendance.commandNotPickedUp', { command: t(`attendance.commands.${device.pending_command}`) }) : t('attendance.commandWaiting', { command: t(`attendance.commands.${device.pending_command}`) })}
        </p>
      ) : null}
      {manage && device.state !== 'revoked' ? (
        <div className="flex flex-wrap gap-2">
          <button type="button" className={buttonClass('primary', 'sm')} disabled={command.isPending} onClick={() => send('pull')}>
            {t('attendance.commands.pull')}
          </button>
          <button type="button" className={buttonClass('outline', 'sm')} disabled={command.isPending} onClick={() => send('test')}>
            {t('attendance.commands.test')}
          </button>
          {Math.abs(drift) >= DRIFT_SECONDS ? (
            <button type="button" className={buttonClass('outline', 'sm')} disabled={command.isPending} onClick={() => send('set_clock')}>
              {t('attendance.commands.set_clock')}
            </button>
          ) : null}
          <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setMapping(true)}>
            {t('attendance.deviceUsers', { count: device.device_users.unmapped, n: number(device.device_users.unmapped) })}
          </button>
          <button
            type="button"
            className={buttonClass('ghost', 'sm')}
            disabled={rotate.isPending}
            onClick={async () => {
              if (await confirm(t('attendance.rotateConfirm'))) rotate.mutate(device.id, { onSuccess: (response) => onToken(response.token) })
            }}
          >
            {t('attendance.rotateToken')}
          </button>
          <button
            type="button"
            className={buttonClass('ghost', 'sm', 'text-red')}
            disabled={revoke.isPending}
            onClick={async () => {
              if (await confirm(t('attendance.revokeConfirm'))) revoke.mutate(device.id, { onSuccess: () => toast(t('attendance.revoked')) })
            }}
          >
            {t('attendance.revoke')}
          </button>
        </div>
      ) : manage && device.state === 'revoked' ? (
        <button type="button" className={buttonClass('outline', 'sm', 'self-start')} onClick={() => rotate.mutate(device.id, { onSuccess: (response) => onToken(response.token) })}>
          {t('attendance.newToken')}
        </button>
      ) : null}
      <p className="m-0 text-12 leading-1.55 text-app-muted">{t('attendance.agentNote')}</p>

      <div className="flex flex-col gap-1.5 border-t border-app-line pt-3">
        <h3 className="m-0 text-14 font-semibold">{t('attendance.syncLog')}</h3>
        {device.events.length === 0 ? (
          <p className="m-0 text-12.5 text-app-muted">{t('attendance.syncLogEmpty')}</p>
        ) : (
          <ul className="m-0 flex list-none flex-col gap-1 p-0" data-testid="sync-log">
            {device.events.map((event) => (
              <li key={event.id} className="flex flex-wrap justify-between gap-2 text-12.5">
                <span>{eventText(t, number, event)}</span>
                <span className="text-app-muted">{event.created_at ? dateTime(event.created_at) : ''}</span>
              </li>
            ))}
          </ul>
        )}
      </div>
      {mapping ? <DeviceUsersDialog device={device} onClose={() => setMapping(false)} /> : null}
      {element}
    </Card>
  )
}

function eventText(t: TFunction, number: (value: number) => string, event: SyncEvent): string {
  if (event.kind === 'punches') {
    return t('attendance.events.punches', { received: number(event.received), stored: number(event.stored), duplicates: number(event.duplicates), rejected: number(event.rejected) })
  }
  if (event.kind === 'command') {
    const command = typeof event.detail?.command === 'string' ? t(`attendance.commands.${event.detail.command}`) : ''
    return t(`attendance.events.command_${event.status ?? 'requested'}`, { command })
  }
  return t(`attendance.events.${event.kind}_${event.status ?? 'ok'}`, { defaultValue: `${event.kind} · ${event.status ?? ''}` })
}

function AddDeviceDialog({ onClose, onToken }: { onClose: () => void; onToken: (name: string, token: string) => void }) {
  const { t } = useTranslation()
  const [name, setName] = useState(t('attendance.defaultDeviceName'))
  const create = useDeviceAction(deviceActions.create)

  return (
    <Dialog open onClose={onClose} title={t('attendance.addDeviceTitle')}>
      <TextInput label={t('attendance.deviceName')} value={name} onChange={setName} maxLength={80} hint={t('attendance.deviceNameHint')} />
      {create.error ? <ErrorNotice error={create.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button type="button" className={buttonClass('primary')} disabled={name.trim() === '' || create.isPending} onClick={() => create.mutate(name.trim(), { onSuccess: (response) => onToken(response.data.name, response.token) })}>
          {t('attendance.createDevice')}
        </button>
      </div>
    </Dialog>
  )
}

/** The token, once: it goes into install.ps1 on the office PC and is never shown again. */
function TokenDialog({ name, token, onClose }: { name: string; token: string; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()

  return (
    <Dialog open onClose={onClose} title={t('attendance.tokenTitle', { name })}>
      <p className="m-0 text-13.5 leading-1.6">{t('attendance.tokenNote')}</p>
      <input readOnly value={token} onFocus={(event) => event.target.select()} className="w-full rounded-10 border border-app-line bg-app-surface-2 px-3 py-2 font-display text-12" data-testid="device-token" aria-label={t('attendance.tokenLabel')} />
      <p className="m-0 text-12.5 leading-1.55 text-app-muted">{t('attendance.tokenInstall')}</p>
      <div className="flex justify-end gap-2">
        <button
          type="button"
          className={buttonClass('outline')}
          onClick={async () => {
            try {
              await navigator.clipboard.writeText(token)
              toast(t('attendance.tokenCopied'))
            } catch {
              toast(t('staff.copyFailed'), 'error')
            }
          }}
        >
          {t('staff.copyLink')}
        </button>
        <button type="button" className={buttonClass('primary')} onClick={onClose}>
          {t('staff.done')}
        </button>
      </div>
    </Dialog>
  )
}

function DeviceUsersDialog({ device, onClose }: { device: AttendanceDevice; onClose: () => void }) {
  const { t } = useTranslation()
  const { digits } = useFormat()
  const toast = useToast()
  const users = useDeviceUsers(device.id)
  const map = useMapDeviceUser(device.id)

  return (
    <Dialog open onClose={onClose} title={t('attendance.deviceUsersTitle', { name: device.name })} wide>
      <p className="m-0 text-13 leading-1.55 text-app-muted">{t('attendance.deviceUsersNote')}</p>
      {users.isPending ? (
        <Loading />
      ) : users.isError ? (
        <ErrorNotice error={users.error} />
      ) : users.data.data.users.length === 0 ? (
        <p className="m-0 text-13 text-app-muted">{t('attendance.deviceUsersEmpty')}</p>
      ) : (
        <ul className="m-0 flex list-none flex-col gap-2 p-0" data-testid="device-users">
          {users.data.data.users.map((user) => (
            <li key={user.id} className="flex flex-wrap items-end gap-3 rounded-10 bg-app-surface-2 px-3 py-2">
              <span className="flex min-w-32 flex-col text-13">
                <span className="font-display font-semibold">{t('attendance.deviceUserId', { id: digits(user.device_user_id) })}</span>
                <span className="text-12 text-app-muted">{user.name_on_device || t('attendance.noName')}</span>
              </span>
              <SelectInput
                label={t('attendance.matchTo')}
                className="min-w-56 flex-1"
                value={user.ignored ? 'ignore' : user.staff ? String(user.staff.id) : ''}
                disabled={map.isPending}
                onChange={(value) =>
                  map.mutate(
                    { userId: user.id, staffId: value === '' || value === 'ignore' ? null : Number(value), ignored: value === 'ignore' },
                    { onSuccess: () => toast(t('attendance.matched')) },
                  )
                }
                options={[
                  { value: '', label: t('attendance.notMatched') },
                  { value: 'ignore', label: t('attendance.ignoreUser') },
                  ...users.data.data.staff.map((person) => ({ value: String(person.id), label: `${person.name} · ${person.employee_code}` })),
                ]}
              />
            </li>
          ))}
        </ul>
      )}
      {map.error ? <ErrorNotice error={map.error} /> : null}
      <div className="flex justify-end">
        <button type="button" className={buttonClass('primary')} onClick={onClose}>
          {t('staff.done')}
        </button>
      </div>
    </Dialog>
  )
}
