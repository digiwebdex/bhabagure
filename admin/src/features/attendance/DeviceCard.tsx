import type { TFunction } from 'i18next'
import { useId, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useConfirm, useToast } from '../../components/ui/feedback'
import { SelectInput, TextInput } from '../../components/ui/fields'
import { Badge, Card, CardTitle, Loading } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { fileSize } from '../vouchers/api'
import { deviceActions, useAgentDownload, useDeviceAction, useDevices, useDeviceUsers, useMapDeviceUser, type AgentPackage, type AttendanceDevice, type DeviceCommand, type DeviceState, type SyncEvent } from './api'

const STATE_TONES: Record<DeviceState, 'green' | 'orange' | 'red' | 'slate'> = { ok: 'green', waiting_for_agent: 'orange', pc_silent: 'red', no_address: 'orange', device_unreachable: 'red', revoked: 'slate' }

/** Drift beyond this and the card offers to set the device clock. */
const DRIFT_SECONDS = 120
/** As AttendanceDevice on the API: ZKTeco's default port, and the range the card accepts. */
const DEFAULT_PORT = 4370
const PORT_MIN = 4370
const PORT_MAX = 4380

/**
 * The design's device card and sync log (docs/phase-7-hr-attendance-bonus-wallet.md §5.1). Everything shown is what the
 * office agent last reported; the buttons, and the device address kept here, reach it at its next check-in, within a
 * minute. The agent itself is downloaded from the card (client, 2026-10-03).
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
  const agent = devices.data.meta?.agent_package ?? null

  return (
    <>
      {devices.data.data.length === 0 ? (
        <Card>
          <CardTitle title="Attendance device" />
          <p className="m-0 text-13.5 leading-1.6 text-app-muted">{t('attendance.noDevice')}</p>
          {manage ? (
            <button type="button" className={buttonClass('cta', 'md', 'self-start')} onClick={() => setAdding(true)}>
              {t('attendance.addDevice')}
            </button>
          ) : null}
        </Card>
      ) : (
        devices.data.data.map((device) => <DeviceCard key={device.id} device={device} agent={agent} manage={manage} onToken={(value) => setToken({ name: device.name, token: value })} />)
      )}
      {adding ? <AddDeviceDialog onClose={() => setAdding(false)} onToken={(name, value) => { setAdding(false); setToken({ name, token: value }) }} /> : null}
      {token ? <TokenDialog name={token.name} token={token.token} agent={agent} onClose={() => setToken(null)} /> : null}
    </>
  )
}

function DeviceCard({ device, agent, manage, onToken }: { device: AttendanceDevice; agent: AgentPackage | null; manage: boolean; onToken: (token: string) => void }) {
  const { t } = useTranslation()
  const format = useFormat()
  const { dateTime, number } = format
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
    [t('attendance.device.enrolled'), device.users_count === null ? '—' : t('attendance.device.enrolledValue', { users: number(device.users_count), fingers: number(device.fingers_count ?? 0) })],
    [t('attendance.device.records'), device.records_count === null ? '—' : number(device.records_count)],
    [t('attendance.device.clock'), device.clock_offset_seconds === null ? '—' : Math.abs(drift) < 60 ? t('attendance.device.clockOk') : t(drift > 0 ? 'attendance.device.clockFast' : 'attendance.device.clockSlow', { minutes: number(Math.round(Math.abs(drift) / 60)) })],
    [t('attendance.device.lastCheckIn'), device.last_check_in_at ? dateTime(device.last_check_in_at) : t('attendance.device.never')],
    [t('attendance.device.lastSync'), device.last_pull_ok_at ? dateTime(device.last_pull_ok_at) : t('attendance.device.never')],
    [t('attendance.device.agent'), device.agent_version ?? '—'],
  ]

  return (
    <Card>
      <CardTitle
        title="Attendance device"
        aside={
          <span data-testid="device-state">
            <Badge tone={STATE_TONES[device.state]}>{t(`attendance.states.${device.state}`)}</Badge>
          </span>
        }
      />
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

      {/* Remounted when the saved address changes, so the form starts from what the API holds. */}
      <AddressForm key={`${device.device_address}|${device.device_port}`} device={device} manage={manage} />

      {/* Installing the agent on the office PC: a download and a double-click, no typing (client, 2026-10-03). */}
      {manage ? (
        <div className="flex flex-col gap-2 border-t border-app-line pt-3">
          <h3 className="m-0 text-14 font-semibold">{t('attendance.agent.title')}</h3>
          <AgentDownload agent={agent} />
          <ol className="m-0 flex list-decimal flex-col gap-1 pl-5 text-12.5 leading-1.55" data-testid="agent-install-steps">
            {(['download', 'install', 'token'] as const).map((step) => (
              <li key={step}>{t(`attendance.agent.steps.${step}`)}</li>
            ))}
          </ol>
        </div>
      ) : null}

      <div className="flex flex-col gap-1.5 border-t border-app-line pt-3">
        <h3 className="m-0 text-14 font-semibold">{t('attendance.syncLog')}</h3>
        {device.events.length === 0 ? (
          <p className="m-0 text-12.5 text-app-muted">{t('attendance.syncLogEmpty')}</p>
        ) : (
          <ul className="m-0 flex list-none flex-col gap-1 p-0" data-testid="sync-log">
            {device.events.map((event) => (
              <li key={event.id} className="flex flex-wrap justify-between gap-2 text-12.5">
                <span>{eventText(t, format, event)}</span>
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

function eventText(t: TFunction, { number, digits }: Pick<ReturnType<typeof useFormat>, 'number' | 'digits'>, event: SyncEvent): string {
  if (event.kind === 'punches') {
    return t('attendance.events.punches', { received: number(event.received), stored: number(event.stored), duplicates: number(event.duplicates), rejected: number(event.rejected) })
  }
  if (event.kind === 'command') {
    const command = typeof event.detail?.command === 'string' ? t(`attendance.commands.${event.detail.command}`) : ''
    return t(`attendance.events.command_${event.status ?? 'requested'}`, { command })
  }
  // The device address set on the card: from and to are "address:port", or null for none.
  if (event.kind === 'config' && event.status === 'address_set') {
    const from = typeof event.detail?.from === 'string' ? digits(event.detail.from) : null
    const to = typeof event.detail?.to === 'string' ? digits(event.detail.to) : null
    if (to === null) return from === null ? t('attendance.events.address_empty') : t('attendance.events.address_cleared', { from })
    return from === null ? t('attendance.events.address_set', { to }) : t('attendance.events.address_changed', { from, to })
  }
  return t(`attendance.events.${event.kind}_${event.status ?? 'ok'}`, { defaultValue: `${event.kind} · ${event.status ?? ''}` })
}

/** "192.168.10.116:4370", as the agent reports it (or a bare address), against the address and port kept on the card. */
function sameAddress(reported: string, address: string | null, port: number): boolean {
  return address !== null && (reported === `${address}:${port}` || reported === address)
}

/**
 * The device's fixed address on the office network, kept here and handed to the agent at its next check-in, so a new
 * address needs nobody at the office PC (client, 2026-10-03). The API takes only a private IPv4 address. While the
 * agent last read the device somewhere else (its own agent.ini, or before it picked up a change), the card says where.
 */
function AddressForm({ device, manage }: { device: AttendanceDevice; manage: boolean }) {
  const { t } = useTranslation()
  const { digits } = useFormat()
  const toast = useToast()
  const [address, setAddress] = useState(device.device_address ?? '')
  const [port, setPort] = useState(String(device.device_port))
  const save = useDeviceAction(deviceActions.setAddress)
  const fieldError = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)
  // As the API reads the port: empty is 4370, and 04370 is 4370. Compared as text, those were a save that changed nothing.
  const portNumber = port.trim() === '' ? DEFAULT_PORT : Number(port)
  const changed = address.trim() !== (device.device_address ?? '') || portNumber !== device.device_port
  // With no address at all the agent's last reading is stale, not where it is looking now.
  const elsewhere = device.state !== 'no_address' && device.reported_address && !sameAddress(device.reported_address, device.device_address, device.device_port) ? device.reported_address : null

  const submit = () => {
    const value = address.trim()
    save.mutate(
      { id: device.id, address: value === '' ? null : value, port: port.trim() === '' ? undefined : Number(port) },
      {
        onSuccess: () => {
          // The fields show what was saved (the form only starts afresh when the address or port really changed).
          setAddress(value)
          setPort(String(portNumber))
          toast(t(value === '' ? 'attendance.address.cleared' : 'attendance.address.saved'))
        },
      },
    )
  }

  return (
    // noValidate: the API's own messages show on the fields, not the browser's.
    <form
      noValidate
      className="m-0 flex flex-col gap-2 border-t border-app-line pt-3"
      onSubmit={(event) => {
        event.preventDefault()
        if (manage && changed && !save.isPending) submit()
      }}
    >
      <fieldset disabled={!manage} className="m-0 grid grid-cols-[minmax(0,1fr)_6.5rem] gap-2 border-0 p-0" data-testid="device-address">
        <TextInput
          label={t('attendance.device.address')}
          value={address}
          onChange={setAddress}
          onPaste={(event) => {
            // "192.168.10.116:4370", the way the card writes an address, fills both fields.
            const [, host, pastedPort] = /^\s*([\d.]+):(\d{1,5})\s*$/.exec(event.clipboardData.getData('text')) ?? []
            if (!host) return
            event.preventDefault()
            setAddress(host)
            setPort(pastedPort)
          }}
          error={fieldError('address')}
          // Tied to the field, so a screen reader reads where to find the address with it.
          hint={t('attendance.address.hint')}
          inputMode="decimal"
          autoComplete="off"
          spellCheck={false}
        />
        <TextInput label={t('attendance.device.port')} type="number" min={PORT_MIN} max={PORT_MAX} value={port} onChange={setPort} error={fieldError('port')} />
      </fieldset>
      {elsewhere ? (
        <p className="m-0 text-12.5 text-amber" data-testid="agent-address">
          {t('attendance.address.agentUses', { address: digits(elsewhere) })}
        </p>
      ) : null}
      {save.error && !(save.error instanceof ApiError && save.error.status === 422) ? <ErrorNotice error={save.error} /> : null}
      {manage ? (
        <button type="submit" className={buttonClass('primary', 'sm', 'self-start')} disabled={!changed || save.isPending}>
          {t('attendance.address.save')}
        </button>
      ) : null}
    </form>
  )
}

/** The agent's zip from the server, under its own name; disabled, with a note, until the developer has uploaded one. */
function AgentDownload({ agent }: { agent: AgentPackage | null }) {
  const { t } = useTranslation()
  const download = useAgentDownload()
  const [busy, setBusy] = useState(false)
  const noteId = useId()

  return (
    <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5" data-testid="agent-download">
      <button
        type="button"
        className={buttonClass('primary', 'sm')}
        disabled={agent === null || busy}
        // The note beside it says which version, or why there is nothing to download yet.
        aria-describedby={noteId}
        onClick={async () => {
          if (agent === null) return
          setBusy(true)
          await download(agent.filename)
          setBusy(false)
        }}
      >
        {t('attendance.agent.download')}
      </button>
      <span id={noteId} className="text-12 text-app-muted">
        {agent ? t('attendance.agent.version', { version: agent.version, size: fileSize(agent.bytes) }) : t('attendance.agent.missing')}
      </span>
    </div>
  )
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

/**
 * The token, once: install.cmd on the office PC asks for it, and it is never shown again. The agent can be downloaded
 * from here too, so nobody closes this to fetch it and loses the token.
 */
function TokenDialog({ name, token, agent, onClose }: { name: string; token: string; agent: AgentPackage | null; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()

  return (
    <Dialog open onClose={onClose} title={t('attendance.tokenTitle', { name })}>
      <p className="m-0 text-13.5 leading-1.6">{t('attendance.tokenNote')}</p>
      <input readOnly value={token} onFocus={(event) => event.target.select()} className="w-full rounded-10 border border-app-line bg-app-surface-2 px-3 py-2 font-display text-12" data-testid="device-token" aria-label={t('attendance.tokenLabel')} />
      <p className="m-0 text-12.5 leading-1.55 text-app-muted">{t('attendance.tokenInstall')}</p>
      <AgentDownload agent={agent} />
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
                {/* An ID known only from its punches has no name: it was deleted from the device, or never listed. */}
                {user.name_on_device ? <span className="text-12 text-app-muted">{user.name_on_device}</span> : user.on_device !== false ? <span className="text-12 text-app-muted">{t('attendance.noName')}</span> : null}
                {user.on_device === false ? <span className="text-12 font-semibold text-amber">{t('attendance.notOnDevice')}</span> : null}
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
                  // Someone invited who hasn't signed in yet already punches, and counts (client, 2026-10-03).
                  ...users.data.data.staff.map((person) => ({ value: String(person.id), label: `${person.name} · ${person.employee_code}${person.status === 'invited' ? ` ${t('attendance.notSignedInYet')}` : ''}` })),
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
