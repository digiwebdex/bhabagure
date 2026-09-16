import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { ErrorNotice, useToast } from '../../components/ui/feedback'
import { Field, Switch, TextInput } from '../../components/ui/fields'
import { controlClass } from '../../components/ui/controls'
import { Badge, Card, CardTitle, Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import {
  ALERT_EVENTS,
  CHANNELS,
  UNDELIVERED_MAILERS,
  useAlertSettings,
  useCheckConnection,
  useNotificationLog,
  useOverview,
  usePreview,
  useSaveAlertSettings,
  useSaveTemplate,
  useTemplates,
  useTestTemplate,
  type AlertEvent,
  type LogFilters,
  type NotificationChannel,
  type NotificationOverview,
  type NotificationTemplate,
} from './api'
import { ChannelChip, MessageList } from './MessageList'

type Tab = 'templates' | 'alerts' | 'log'

/** WhatsApp and email notifications: the connection, the ten templates, sales alert lists and every message sent. */
export function NotificationsPage() {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()
  const tab: Tab = params.get('tab') === 'alerts' ? 'alerts' : params.get('tab') === 'log' ? 'log' : 'templates'
  const overview = useOverview()

  return (
    <>
      <PageHeader title={t('notifications.title')} subtitle={t('notifications.subtitle')} />
      {overview.isPending ? <Loading /> : overview.isError ? <ErrorNotice error={overview.error} /> : <ConnectionCard overview={overview.data.data} />}
      <Chips
        label={t('notifications.sections')}
        value={tab}
        onChange={(value) => setParams(value === 'templates' ? {} : { tab: value }, { replace: true })}
        options={(['templates', 'alerts', 'log'] as const).map((value) => ({ value, label: t(`notifications.tabs.${value}`) }))}
      />
      {tab === 'templates' ? <TemplatesPanel overview={overview.data?.data ?? null} /> : tab === 'alerts' ? <AlertsPanel /> : <LogPanel costs={overview.data?.data.costs ?? null} />}
    </>
  )
}

function ConnectionCard({ overview }: { overview: NotificationOverview }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { bdt, dateTime, digits, number } = useFormat()
  const check = useCheckConnection()
  const { whatsapp, email, numbers, counts, sms } = overview
  const status = whatsapp.mode === 'off' || whatsapp.provider === 'off' ? 'off' : whatsapp.session.status
  const tone = status === 'connected' ? 'green' : status === 'off' ? 'slate' : 'red'

  return (
    <Card>
      <CardTitle
        title="WhatsApp, email & SMS connection"
        aside={<Badge tone={tone}>{t(`notifications.session.${status}`, { defaultValue: status })}</Badge>}
      />
      <div className="grid-auto-fit-260 grid gap-3">
        <dl className="m-0 flex flex-col gap-1.5 rounded-12 bg-app-surface-2 px-3.5 py-3 text-13">
          <Fact label={t('notifications.provider')} value={t(`notifications.modes.${whatsapp.provider === 'off' && whatsapp.mode === 'live' ? 'unconfigured' : whatsapp.mode}`)} />
          {whatsapp.session.checkedAt ? <Fact label={t('notifications.checkedAt')} value={dateTime(whatsapp.session.checkedAt)} /> : null}
          <Fact label={t('notifications.apiKey')} value={whatsapp.key_hint ? t('notifications.keyHint', { hint: whatsapp.key_hint }) : t('notifications.notSet')} />
          <Fact label={t('notifications.webhook')} value={whatsapp.webhook_configured ? t('notifications.configured') : t('notifications.notSet')} />
          <Fact label={t('notifications.pace')} value={t('notifications.paceValue', { seconds: number(whatsapp.seconds_between_sends), cap: number(whatsapp.daily_cap) })} />
          {whatsapp.mode === 'live' ? (
            <button type="button" className={buttonClass('outline', 'sm', 'mt-1 self-start')} disabled={check.isPending} onClick={() => check.mutate()}>
              {check.isPending ? t('common.working') : t('notifications.checkNow')}
            </button>
          ) : null}
        </dl>
        <dl className="m-0 flex flex-col gap-1.5 rounded-12 bg-app-surface-2 px-3.5 py-3 text-13">
          <Fact label={t('notifications.mainNumber')} value={numbers.main ? digits(numbers.main) : '—'} />
          <Fact label={t('notifications.notificationsNumber')} value={numbers.notifications ? digits(numbers.notifications) : t('notifications.notSet')} />
          <Fact
            label={t('notifications.email')}
            value={
              !email.enabled
                ? t('notifications.emailOff')
                : UNDELIVERED_MAILERS.includes(email.mailer)
                  ? t('notifications.emailNotSetUp')
                  : `${email.mailer}${email.from ? ` · ${email.from}` : ''}`
            }
          />
          <Fact label={t('notifications.counts')} value={t('notifications.countsValue', { pending: number(counts.pending), sent: number(counts.sent_today), failed: number(counts.failed_24h) })} />
        </dl>
        <dl className="m-0 flex flex-col gap-1.5 rounded-12 bg-app-surface-2 px-3.5 py-3 text-13" data-testid="sms-connection">
          <Fact label={t('notifications.smsProvider')} value={sms.disabled_reason ? t(`notifications.reasons.${sms.disabled_reason}`, { defaultValue: sms.disabled_reason }) : t(`notifications.smsModes.${sms.mode}`)} />
          <Fact label={t('notifications.senderId')} value={sms.sender_id ?? t('notifications.senderIdMissing')} />
          <Fact label={t('notifications.apiKey')} value={sms.key_hint ? t('notifications.keyHint', { hint: sms.key_hint }) : t('notifications.notSet')} />
          <Fact label={t('notifications.transport')} value={sms.https ? t('notifications.httpsPost') : t('notifications.insecure')} />
          <Fact label={t('notifications.smsRate')} value={t('notifications.smsRateValue', { rate: bdt(sms.cost_per_part), warn: number(sms.warn_parts), max: number(sms.max_parts) })} />
        </dl>
      </div>
      {check.error ? <ErrorNotice error={check.error} /> : null}
      {!numbers.notifications ? (
        <p role="note" className="m-0 rounded-12 border border-orange-line bg-orange-tint px-3.5 py-3 text-13 leading-1.6 text-orange-ink">
          {t('notifications.numberMissing')} {can('cms.manage') ? <Link to="/settings">{t('notifications.openSettings')}</Link> : null}
        </p>
      ) : null}
      {!sms.sender_id && sms.mode === 'live' ? (
        <p role="note" className="m-0 rounded-12 border border-orange-line bg-orange-tint px-3.5 py-3 text-13 leading-1.6 text-orange-ink">{t('notifications.senderIdNote')}</p>
      ) : null}
      <p className="m-0 text-12 leading-1.6 text-app-muted">{t('notifications.riskNote')}</p>
    </Card>
  )
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-wrap justify-between gap-x-3 gap-y-0.5">
      <dt className="text-app-muted">{label}</dt>
      <dd className="m-0 text-right font-medium break-all">{value}</dd>
    </div>
  )
}

/** The prototype's templates screen: list on the left, editor with variables and a live preview on the right. */
function TemplatesPanel({ overview }: { overview: NotificationOverview | null }) {
  const { t } = useTranslation()
  const templates = useTemplates()
  const [event, setEvent] = useState<string | null>(null)
  const [channel, setChannel] = useState<NotificationChannel>('whatsapp')

  if (templates.isPending) return <Loading />
  if (templates.isError) return <ErrorNotice error={templates.error} />
  const all = templates.data.data
  const events = [...new Set(all.map((template) => template.event))]
  const selectedEvent = event ?? events[0]
  const forEvent = all.filter((template) => template.event === selectedEvent)
  const selected = forEvent.find((template) => template.channel === channel) ?? forEvent[0]

  return (
    <div className="grid-auto-fit-half-320 grid items-start gap-4.5 xl:grid-cols-[minmax(240px,0.8fr)_minmax(0,1.6fr)]">
      <Card>
        <CardTitle title="Templates" />
        <ul className="m-0 flex list-none flex-col gap-2 p-0">
          {events.map((value) => {
            const rows = all.filter((template) => template.event === value)
            const active = value === selectedEvent
            return (
              <li key={value}>
                <button
                  type="button"
                  aria-current={active}
                  onClick={() => setEvent(value)}
                  className={`flex w-full cursor-pointer flex-col gap-1 rounded-10 border px-3 py-2.5 text-left ${active ? 'border-blue bg-app-surface-2' : 'border-app-line bg-transparent'}`}
                >
                  <span className="text-14 font-medium">{t(`notifications.events.${value}`)}</span>
                  <span className="flex flex-wrap items-center gap-1.5">
                    {rows.map((row) => (
                      <span key={row.id} className={row.is_enabled ? '' : 'opacity-40'}>
                        <ChannelChip channel={row.channel} />
                      </span>
                    ))}
                    <span className="text-11 text-app-muted">{t(`notifications.audience.${rows[0].audience}`)}</span>
                  </span>
                </button>
              </li>
            )
          })}
        </ul>
      </Card>
      {selected ? (
        <TemplateEditor
          key={selected.id}
          template={selected}
          channels={forEvent.map((template) => template.channel)}
          onChannel={setChannel}
          notificationsNumber={overview?.numbers.notifications ?? null}
          sms={overview?.sms ?? null}
        />
      ) : null}
    </div>
  )
}

function useDebounced<T>(value: T, delay: number): T {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(value), delay)
    return () => window.clearTimeout(timer)
  }, [value, delay])
  return debounced
}

const CHANNEL_LABEL: Record<NotificationChannel, string> = { whatsapp: 'WhatsApp', email: 'Email', sms: 'SMS' }

function TemplateEditor({ template, channels, onChannel, notificationsNumber, sms }: { template: NotificationTemplate; channels: NotificationChannel[]; onChannel: (channel: NotificationChannel) => void; notificationsNumber: string | null; sms: NotificationOverview['sms'] | null }) {
  const { t, i18n } = useTranslation()
  const { bdt, digits, number } = useFormat()
  const toast = useToast()
  const [lang, setLang] = useState<'bn' | 'en'>(i18n.resolvedLanguage === 'en' ? 'en' : 'bn')
  const [draft, setDraft] = useState({ body_bn: template.body_bn, body_en: template.body_en, subject_bn: template.subject_bn, subject_en: template.subject_en, is_enabled: template.is_enabled })
  const body = useRef<HTMLTextAreaElement>(null)
  const save = useSaveTemplate(template.id)
  const test = useTestTemplate(template.id)
  const isEmail = template.channel === 'email'
  const isSms = template.channel === 'sms'
  const max = isEmail ? 5000 : 1000
  const bodyKey = lang === 'bn' ? 'body_bn' : 'body_en'
  const subjectKey = lang === 'bn' ? 'subject_bn' : 'subject_en'
  const dirty = JSON.stringify(draft) !== JSON.stringify({ body_bn: template.body_bn, body_en: template.body_en, subject_bn: template.subject_bn, subject_en: template.subject_en, is_enabled: template.is_enabled })
  const previewInput = useDebounced({ body: draft[bodyKey], subject: isEmail ? (draft[subjectKey] ?? '') : null }, 400)
  const preview = usePreview(template.id, lang, previewInput.body, previewInput.subject)
  const fieldError = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)

  const insert = (variable: string) => {
    const token = `{{${variable}}}`
    const element = body.current
    const current = draft[bodyKey]
    const start = element?.selectionStart ?? current.length
    const end = element?.selectionEnd ?? current.length
    setDraft({ ...draft, [bodyKey]: current.slice(0, start) + token + current.slice(end) })
    requestAnimationFrame(() => {
      element?.focus()
      element?.setSelectionRange(start + token.length, start + token.length)
    })
  }

  return (
    <Card>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h2 className="m-0 text-17 font-semibold">{t(`notifications.events.${template.event}`)}</h2>
          <p className="m-0 text-13 text-app-muted">{t(`notifications.timing.${template.event}`, { defaultValue: template.timing })}</p>
        </div>
        <Switch label={draft.is_enabled ? t('notifications.enabled') : t('notifications.disabled')} checked={draft.is_enabled} onChange={(is_enabled) => setDraft({ ...draft, is_enabled })} />
      </div>

      <div className="flex flex-wrap items-center justify-between gap-2">
        {channels.length > 1 ? (
          <Chips label={t('notifications.channel')} value={template.channel} onChange={onChannel} options={channels.map((value) => ({ value, label: value === 'email' ? t('notifications.emailChannel') : CHANNEL_LABEL[value] }))} />
        ) : (
          <ChannelChip channel={template.channel} />
        )}
        <Chips label={t('notifications.language')} value={lang} onChange={setLang} options={[{ value: 'bn', label: 'Bangla' }, { value: 'en', label: 'English' }]} />
      </div>

      <div className={`flex flex-wrap items-center justify-between gap-2.5 rounded-12 border px-3.5 py-3 ${isEmail ? 'border-blue-wash-line bg-blue-wash' : isSms ? 'border-app-line bg-purple-tint' : 'border-green-line bg-whatsapp-tint'}`}>
        <span className={`flex min-w-0 flex-col text-12 leading-1.5 ${isEmail ? 'text-blue-deep' : isSms ? 'text-purple' : 'text-green-deep'}`}>
          <strong className="text-13">{isEmail ? t('notifications.emailBannerTitle') : isSms ? t('notifications.smsBannerTitle') : t('notifications.waBannerTitle')}</strong>
          <span>
            {isEmail
              ? t('notifications.emailBannerNote')
              : isSms
                ? template.event === 'departure_today'
                  ? t('notifications.smsBannerAlways', { sender: sms?.sender_id ?? t('notifications.senderIdMissing') })
                  : t('notifications.smsBannerFallback', { sender: sms?.sender_id ?? t('notifications.senderIdMissing') })
                : notificationsNumber
                  ? t('notifications.waBannerNote', { number: digits(notificationsNumber) })
                  : t('notifications.waBannerNoNumber')}
          </span>
        </span>
        <button type="button" className={buttonClass('outline', 'sm', 'bg-app-surface')} disabled={test.isPending} onClick={() => test.mutate(lang, { onSuccess: () => toast(t(isEmail ? 'notifications.testSentEmail' : isSms ? 'notifications.testSentSms' : 'notifications.testSent')) })}>
          {isEmail ? t('notifications.testToMyEmail') : isSms ? t('notifications.testToMyPhone') : t('notifications.testToMyWhatsApp')}
        </button>
      </div>
      {test.error instanceof ApiError && test.error.code === 'no_verified_number' ? (
        <p role="alert" className="m-0 text-13 font-semibold text-red">
          {test.error.message} <Link to="/profile">{t('notifications.verifyMyNumber')}</Link>
        </p>
      ) : test.error ? (
        <ErrorNotice error={test.error} />
      ) : null}

      {isEmail ? (
        <TextInput key={`subject-${lang}`} label={t('notifications.subject')} value={draft[subjectKey]} onChange={(value) => setDraft({ ...draft, [subjectKey]: value })} error={fieldError(subjectKey)} maxLength={190} />
      ) : null}
      <Field
        label={isEmail ? t('notifications.emailBody') : isSms ? t('notifications.smsBody') : t('notifications.whatsAppBody')}
        error={fieldError(bodyKey)}
        hint={
          <span className={draft[bodyKey].length > max ? 'text-red' : ''}>
            {number(draft[bodyKey].length)} / {number(max)}
            {isEmail ? '' : isSms ? ` · ${t('notifications.smsNoSenderLine')}` : ` · ${t('notifications.senderLineAdded')}`}
          </span>
        }
      >
        {(id, describedBy) => (
          <textarea
            id={id}
            ref={body}
            rows={isEmail ? 8 : 5}
            aria-describedby={describedBy}
            aria-invalid={!!fieldError(bodyKey)}
            value={draft[bodyKey]}
            onChange={(e) => setDraft({ ...draft, [bodyKey]: e.target.value })}
            className={`${controlClass(!!fieldError(bodyKey))} resize-y leading-1.55`}
          />
        )}
      </Field>

      <div className="flex flex-col gap-2">
        <span className="text-13 text-app-muted">{t('notifications.variables')}</span>
        <div className="flex flex-wrap gap-1.5">
          {template.variables.map((variable) => (
            <button key={variable} type="button" onClick={() => insert(variable)} title={t(`notifications.variableHelp.${variable}`, { defaultValue: '' })} className="cursor-pointer rounded-8 border border-dashed border-app-line bg-transparent px-2.5 py-1 font-display text-12 text-blue hover:border-blue">
              {`{{${variable}}}`}
            </button>
          ))}
        </div>
      </div>

      <div className="flex flex-col gap-1.5 rounded-12 bg-app-surface-2 px-3.5 py-3" aria-live="polite">
        <span className="font-display text-11 tracking-eyebrow text-app-muted uppercase">
          {t('notifications.preview')}
          {preview.data?.data.sample ? ` · ${digits(preview.data.data.sample)}` : ''}
        </span>
        {preview.data?.data.unknown_variables.length ? (
          <span role="alert" className="text-12 font-semibold text-red">
            {t('notifications.unknownVariables', { variables: preview.data.data.unknown_variables.map((v) => `{{${v}}}`).join(', ') })}
          </span>
        ) : null}
        {preview.data?.data.subject ? <strong className="text-14">{preview.data.data.subject}</strong> : null}
        {preview.data && preview.data.data.body === null ? (
          <span className="text-13 text-app-muted">{t('notifications.noSample')}</span>
        ) : (
          <p className="m-0 text-14 leading-1.55 break-words whitespace-pre-wrap" data-testid="template-preview">
            {preview.data?.data.body ?? ''}
          </p>
        )}
        {preview.data?.data.sms ? (
          <div
            className={`flex flex-col gap-0.5 border-t border-app-line pt-2 text-12 ${preview.data.data.sms.too_long ? 'text-red' : preview.data.data.sms.warn ? 'text-amber' : 'text-app-muted'}`}
            data-testid="sms-estimate"
          >
            <strong className="font-display text-13">
              {t('notifications.smsEstimate', {
                parts: t('notifications.parts', { count: preview.data.data.sms.parts, n: number(preview.data.data.sms.parts) }),
                encoding: t(`notifications.encoding.${preview.data.data.sms.encoding}`),
                cost: bdt(preview.data.data.sms.cost),
              })}
            </strong>
            <span>{t('notifications.smsPerPart', { units: number(preview.data.data.sms.units), perPart: number(preview.data.data.sms.per_part) })}</span>
            {preview.data.data.sms.too_long ? (
              <span role="alert" className="font-semibold">{t('notifications.smsTooLong', { max: number(sms?.max_parts ?? 6) })}</span>
            ) : preview.data.data.sms.warn ? (
              <span role="alert" className="font-semibold">{t('notifications.smsWarn', { warn: number(sms?.warn_parts ?? 3) })}</span>
            ) : null}
          </div>
        ) : null}
      </div>

      {save.error && !(save.error instanceof ApiError && save.error.status === 422) ? <ErrorNotice error={save.error} /> : null}
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="text-12 text-app-muted">{t('notifications.bothLanguages')}</span>
        <button type="button" className={buttonClass(dirty ? 'primary' : 'outline')} aria-disabled={!dirty || save.isPending} onClick={() => dirty && !save.isPending && save.mutate(draft, { onSuccess: () => toast(t('common.saved')) })}>
          {save.isPending ? t('common.saving') : dirty ? t('common.save') : t('common.saved')}
        </button>
      </div>
    </Card>
  )
}

/** Who gets the sales-team alerts. Only staff who confirmed their WhatsApp number can be chosen. */
function AlertsPanel() {
  const { t } = useTranslation()
  const settings = useAlertSettings()

  if (settings.isPending) return <Loading />
  if (settings.isError) return <ErrorNotice error={settings.error} />
  return <AlertsEditor key={JSON.stringify(settings.data.data.alert_recipients)} settings={settings.data.data} note={t('notifications.alertsNote')} />
}

function AlertsEditor({ settings, note }: { settings: NonNullable<ReturnType<typeof useAlertSettings>['data']>['data']; note: string }) {
  const { t } = useTranslation()
  const { digits, locale } = useFormat()
  const toast = useToast()
  const save = useSaveAlertSettings()
  const [recipients, setRecipients] = useState<Record<AlertEvent, number[]>>(settings.alert_recipients)
  const dirty = JSON.stringify(recipients) !== JSON.stringify(settings.alert_recipients)

  const toggle = (event: AlertEvent, id: number) =>
    setRecipients({ ...recipients, [event]: recipients[event].includes(id) ? recipients[event].filter((value) => value !== id) : [...recipients[event], id] })

  return (
    <Card>
      <CardTitle title="Sales team alerts" />
      <p className="m-0 text-13 leading-1.6 text-app-muted">
        {note} <Link to="/profile">{t('notifications.verifyMyNumber')}</Link>
      </p>
      {settings.eligible_staff.length === 0 ? (
        <EmptyState title={t('notifications.noVerifiedStaff')} note={t('notifications.noVerifiedStaffNote')} />
      ) : (
        <div className="grid-auto-fit-260 grid gap-3">
          {ALERT_EVENTS.map((event) => (
            <fieldset key={event} className="m-0 flex min-w-0 flex-col gap-2 rounded-12 border border-app-line px-3.5 py-3">
              <legend className="px-1 text-14 font-semibold">{t(`notifications.events.${event}`)}</legend>
              <span className="text-12 text-app-muted">{t(`notifications.timing.${event}`)}</span>
              {settings.eligible_staff.map((staff) => (
                <label key={staff.id} className="flex cursor-pointer items-center gap-2.5 text-13">
                  <input type="checkbox" className="size-4 accent-blue" checked={recipients[event].includes(staff.id)} onChange={() => toggle(event, staff.id)} />
                  <span className="flex min-w-0 flex-col leading-1.3">
                    <span className="font-medium">{staff.name}</span>
                    <span className="font-display text-11 text-app-muted">
                      {t(`roles.${staff.role ?? 'staff'}`, { defaultValue: (locale === 'en' ? staff.role_name_en : staff.role_name_bn) ?? staff.role_name_en ?? '' })} · {digits(staff.whatsapp.replace(/^88/, ''))}
                    </span>
                  </span>
                </label>
              ))}
            </fieldset>
          ))}
        </div>
      )}
      <ErrorNotice error={save.error} />
      <button type="button" className={buttonClass(dirty ? 'primary' : 'outline', 'md', 'self-start')} aria-disabled={!dirty || save.isPending} onClick={() => dirty && !save.isPending && save.mutate(recipients, { onSuccess: () => toast(t('common.saved')) })}>
        {save.isPending ? t('common.saving') : dirty ? t('common.save') : t('common.saved')}
      </button>
    </Card>
  )
}

/** What each channel cost, so the client sees what SMS actually costs per month. WhatsApp is a flat subscription. */
function CostSummary({ costs }: { costs: NotificationOverview['costs'] }) {
  const { t } = useTranslation()
  const { bdt, number } = useFormat()

  return (
    <div className="overflow-x-auto">
      <table className="w-full border-collapse text-13" data-testid="cost-summary">
        <thead>
          <tr className="border-b border-app-line text-left text-12 text-app-muted">
            <th className="py-2 pr-3 font-medium">{t('notifications.channel')}</th>
            <th className="py-2 pr-3 text-right font-medium">{t('notifications.thisMonth')}</th>
            <th className="py-2 text-right font-medium">{t('notifications.lastMonth')}</th>
          </tr>
        </thead>
        <tbody>
          {CHANNELS.map((channel) => (
            <tr key={channel} className="border-b border-app-line last:border-b-0">
              <td className="py-2 pr-3">
                <ChannelChip channel={channel} />
              </td>
              {[costs.this_month, costs.last_month].map((month, i) => {
                const row = month.channels[channel]
                return (
                  <td key={i} className={`py-2 text-right font-display whitespace-nowrap ${i === 0 ? 'pr-3' : ''}`}>
                    <strong className="font-semibold">{bdt(row.cost)}</strong>
                    <span className="block text-11 text-app-muted">
                      {t('notifications.messagesCount', { count: row.messages, n: number(row.messages) })}
                      {channel === 'sms' ? ` · ${t('notifications.parts', { count: row.parts, n: number(row.parts) })}` : channel === 'whatsapp' ? ` · ${t('notifications.subscription')}` : ''}
                    </span>
                  </td>
                )
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function LogPanel({ costs }: { costs: NotificationOverview['costs'] | null }) {
  const { t } = useTranslation()
  const { number } = useFormat()
  const [filters, setFilters] = useState<LogFilters>({ status: 'all', channel: 'all', page: 1 })
  const log = useNotificationLog(filters)

  return (
    <Card>
      <CardTitle title="Message log" aside={log.data ? <span className="text-12 text-app-muted">{t('notifications.total', { count: log.data.meta.total, n: number(log.data.meta.total) })}</span> : null} />
      {costs ? <CostSummary costs={costs} /> : null}
      <p className="m-0 text-12 text-app-muted">{t('notifications.costNote')}</p>
      <div className="flex flex-wrap items-center gap-3">
        <Chips
          label={t('common.status')}
          value={filters.status}
          onChange={(status) => setFilters({ ...filters, status, page: 1 })}
          options={(['all', 'pending', 'sent', 'delivered', 'read', 'failed', 'skipped'] as const).map((value) => ({ value, label: value === 'all' ? t('common.all') : t(`notifications.status.${value}`) }))}
        />
        <Chips
          label={t('notifications.channel')}
          value={filters.channel}
          onChange={(channel) => setFilters({ ...filters, channel, page: 1 })}
          options={(['all', ...CHANNELS] as const).map((value) => ({ value, label: value === 'all' ? t('common.all') : value === 'email' ? t('notifications.emailChannel') : CHANNEL_LABEL[value] }))}
        />
      </div>
      {log.isPending ? (
        <Loading />
      ) : log.isError ? (
        <ErrorNotice error={log.error} />
      ) : log.data.data.length === 0 ? (
        <EmptyState title={t('notifications.logEmpty')} />
      ) : (
        <>
          <MessageList messages={log.data.data} showRelated />
          {log.data.meta.last_page > 1 ? (
            <div className="flex items-center justify-between gap-2">
              <button type="button" className={buttonClass('outline', 'sm')} disabled={filters.page <= 1} onClick={() => setFilters({ ...filters, page: filters.page - 1 })}>
                {t('common.previous')}
              </button>
              <span className="text-12 text-app-muted">{t('common.pageOf', { page: number(filters.page), last: number(log.data.meta.last_page) })}</span>
              <button type="button" className={buttonClass('outline', 'sm')} disabled={filters.page >= log.data.meta.last_page} onClick={() => setFilters({ ...filters, page: filters.page + 1 })}>
                {t('common.next')}
              </button>
            </div>
          ) : null}
        </>
      )}
    </Card>
  )
}
