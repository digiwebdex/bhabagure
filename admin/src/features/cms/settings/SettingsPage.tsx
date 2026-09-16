import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../../components/ui/button'
import { ErrorNotice, useToast } from '../../../components/ui/feedback'
import { NumberInput, Pair, TextArea, TextInput } from '../../../components/ui/fields'
import { Card, CardTitle, Loading, PageHeader } from '../../../components/ui/layout'
import { api, ApiError } from '../../../lib/api/client'
import type { Data, SiteSettings } from '../../../lib/api/types'

type Key = keyof SiteSettings

/** Company details, contact channels, hours and social stats. Each card saves its own key. */
export function SettingsPage() {
  const { t } = useTranslation()
  const settings = useQuery({ queryKey: ['settings'], queryFn: ({ signal }) => api.get<Data<SiteSettings>>('admin/settings', signal) })

  if (settings.isPending) return <Loading />
  if (settings.isError) return <ErrorNotice error={settings.error} />
  const data = settings.data.data

  return (
    <>
      <PageHeader title={t('settings.title')} subtitle={t('settings.subtitle')} />
      <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
        <SettingCard settingKey="contact" title="Contact" initial={data.contact ?? { phone: '', phoneAlt: '', whatsapp: '', email: '', facebook: '', instagram: '', website: '' }}>
          {(value, set, error) => (
            <>
              <Pair>
                <TextInput label={t('settings.phone')} type="tel" value={value.phone} onChange={(phone) => set({ ...value, phone })} error={error('value.phone')} placeholder="+8801743939300" />
                <TextInput label={t('settings.phoneAlt')} type="tel" value={value.phoneAlt} onChange={(phoneAlt) => set({ ...value, phoneAlt: phoneAlt || null })} error={error('value.phoneAlt')} />
              </Pair>
              <Pair>
                <TextInput label={t('settings.whatsapp')} type="tel" value={value.whatsapp} onChange={(whatsapp) => set({ ...value, whatsapp })} error={error('value.whatsapp')} hint={t('settings.phoneHint')} />
                <TextInput label={t('settings.email')} type="email" value={value.email} onChange={(email) => set({ ...value, email })} error={error('value.email')} />
              </Pair>
              <TextInput
                label={t('settings.notificationsWhatsapp')}
                type="tel"
                value={value.notificationsWhatsapp}
                onChange={(notificationsWhatsapp) => set({ ...value, notificationsWhatsapp: notificationsWhatsapp || null })}
                error={error('value.notificationsWhatsapp')}
                hint={t('settings.notificationsWhatsappHint')}
                placeholder="+8801XXXXXXXXX"
              />
              <TextInput label="Facebook" type="url" value={value.facebook} onChange={(facebook) => set({ ...value, facebook: facebook || null })} error={error('value.facebook')} />
              <TextInput label="Instagram" type="url" value={value.instagram} onChange={(instagram) => set({ ...value, instagram: instagram || null })} error={error('value.instagram')} />
              <TextInput label={t('settings.website')} type="url" value={value.website} onChange={(website) => set({ ...value, website: website || null })} error={error('value.website')} />
            </>
          )}
        </SettingCard>

        <div className="flex min-w-0 flex-col gap-4.5">
          <SettingCard settingKey="company" title="Company" initial={data.company ?? { name: { bn: '', en: '' }, brand: { bn: '', en: '' } }}>
            {(value, set, error) => (
              <>
                <Pair>
                  <TextInput label={t('settings.nameBn')} value={value.name.bn} onChange={(bn) => set({ ...value, name: { ...value.name, bn } })} error={error('value.name.bn')} />
                  <TextInput label={t('settings.nameEn')} value={value.name.en} onChange={(en) => set({ ...value, name: { ...value.name, en } })} error={error('value.name.en')} />
                </Pair>
                <Pair>
                  <TextInput label={t('settings.brandBn')} value={value.brand.bn} onChange={(bn) => set({ ...value, brand: { ...value.brand, bn } })} error={error('value.brand.bn')} />
                  <TextInput label={t('settings.brandEn')} value={value.brand.en} onChange={(en) => set({ ...value, brand: { ...value.brand, en } })} error={error('value.brand.en')} />
                </Pair>
              </>
            )}
          </SettingCard>

          <SettingCard settingKey="address" title="Address" initial={data.address ?? ''}>
            {(value, set, error) => <TextArea label={t('settings.address')} value={value} onChange={set} error={error('value')} hint={t('settings.addressHint')} />}
          </SettingCard>

          <SettingCard settingKey="civilAviationNo" title="Licence" initial={data.civilAviationNo ?? ''}>
            {(value, set, error) => <TextInput label={t('settings.civilAviationNo')} value={value} onChange={set} error={error('value')} />}
          </SettingCard>

          <SettingCard settingKey="hours" title="Opening hours" initial={data.hours ?? { opens: 9, closes: 19 }}>
            {(value, set, error) => (
              <Pair>
                <NumberInput label={t('settings.opens')} value={value.opens} onChange={(opens) => set({ ...value, opens: opens ?? 0 })} error={error('value.opens')} hint={t('settings.hourHint')} />
                <NumberInput label={t('settings.closes')} value={value.closes} onChange={(closes) => set({ ...value, closes: closes ?? 0 })} error={error('value.closes')} />
              </Pair>
            )}
          </SettingCard>

          <SettingCard settingKey="stats" title="Website stats" initial={data.stats ?? { topReelViewsThousands: 0, banglaSupportPercent: 100 }}>
            {(value, set, error) => (
              <Pair>
                <NumberInput label={t('settings.reelViews')} value={value.topReelViewsThousands} onChange={(n) => set({ ...value, topReelViewsThousands: n ?? 0 })} error={error('value.topReelViewsThousands')} hint={t('settings.reelViewsHint')} />
                <NumberInput label={t('settings.banglaSupport')} value={value.banglaSupportPercent} onChange={(n) => set({ ...value, banglaSupportPercent: n ?? 0 })} error={error('value.banglaSupportPercent')} />
              </Pair>
            )}
          </SettingCard>
        </div>

        {/* How customers pay by hand (Phase 8 §4.F): shown on the booking page, in the portal, on invoices and in the
            booking message, each with the exact amount. A method left blank is not offered. */}
        <SettingCard settingKey="payment" title="Payment" initial={data.payment ?? { bank: null, link: null, bkash: null }}>
          {(value, set, error) => (
            <>
              <Pair>
                <TextInput label={t('settings.bankName')} value={value.bank?.bankName ?? ''} onChange={(bankName) => set({ ...value, bank: bank(value.bank, { bankName }) })} error={error('value.bank.bankName')} hint={t('settings.bankHint')} />
                <TextInput label={t('settings.accountName')} value={value.bank?.accountName ?? ''} onChange={(accountName) => set({ ...value, bank: bank(value.bank, { accountName }) })} error={error('value.bank.accountName')} />
              </Pair>
              <Pair>
                <TextInput label={t('settings.accountNumber')} value={value.bank?.accountNumber ?? ''} onChange={(accountNumber) => set({ ...value, bank: bank(value.bank, { accountNumber }) })} error={error('value.bank.accountNumber')} />
                <TextInput label={t('settings.branch')} value={value.bank?.branch ?? ''} onChange={(branch) => set({ ...value, bank: bank(value.bank, { branch }) })} error={error('value.bank.branch')} />
              </Pair>
              <Pair>
                <TextInput label={t('settings.routingNumber')} value={value.bank?.routingNumber ?? ''} onChange={(routingNumber) => set({ ...value, bank: bank(value.bank, { routingNumber }) })} error={error('value.bank.routingNumber')} hint={t('settings.routingHint')} />
                <TextInput label={t('settings.transferType')} value={value.bank?.transferType ?? ''} onChange={(transferType) => set({ ...value, bank: bank(value.bank, { transferType }) })} error={error('value.bank.transferType')} placeholder="NPSB" />
              </Pair>
              <TextInput label={t('settings.paymentLink')} type="url" value={value.link ?? ''} onChange={(link) => set({ ...value, link: link || null })} error={error('value.link')} hint={t('settings.paymentLinkHint')} />
              <Pair>
                <TextInput label={t('settings.bkashNumber')} type="tel" value={value.bkash?.number ?? ''} onChange={(number) => set({ ...value, bkash: number ? { number, chargePercent: value.bkash?.chargePercent ?? 0 } : null })} error={error('value.bkash.number')} placeholder="+8801XXXXXXXXX" />
                <NumberInput label={t('settings.bkashCharge')} value={value.bkash?.chargePercent ?? null} onChange={(chargePercent) => set({ ...value, bkash: value.bkash ? { ...value.bkash, chargePercent: chargePercent ?? 0 } : null })} error={error('value.bkash.chargePercent')} hint={t('settings.bkashChargeHint')} />
              </Pair>
            </>
          )}
        </SettingCard>
      </div>
    </>
  )
}

/** A blank field clears the whole bank block: a half-filled account helps nobody. */
function bank(current: NonNullable<SiteSettings['payment']>['bank'], patch: Partial<NonNullable<NonNullable<SiteSettings['payment']>['bank']>>) {
  const next = { bankName: '', accountName: '', accountNumber: '', branch: '', routingNumber: '', transferType: 'NPSB', ...current, ...patch }
  return Object.values(next).every((field) => field.trim() === '') ? null : next
}

function SettingCard<K extends Key>({ settingKey, title, initial, children }: {
  settingKey: K
  title: string
  initial: NonNullable<SiteSettings[K]>
  children: (value: NonNullable<SiteSettings[K]>, set: (value: NonNullable<SiteSettings[K]>) => void, error: (field: string) => string | undefined) => ReactNode
}) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [value, setValue] = useState(initial)
  const dirty = JSON.stringify(value) !== JSON.stringify(initial)
  const save = useMutation({
    mutationFn: () => api.put(`admin/settings/${settingKey}`, { value }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['settings'] })
      toast(t('common.saved'))
    },
  })

  return (
    <Card>
      <CardTitle title={title} />
      {children(value, setValue, (field) => (save.error instanceof ApiError ? save.error.field(field) : undefined))}
      <ErrorNotice error={save.error instanceof ApiError && save.error.status === 422 ? null : save.error} />
      <button type="button" className={buttonClass(dirty ? 'primary' : 'outline', 'md', 'self-start')} onClick={() => dirty && !save.isPending && save.mutate()} aria-disabled={save.isPending || !dirty}>
        {save.isPending ? t('common.saving') : dirty ? t('common.save') : t('common.saved')}
      </button>
    </Card>
  )
}
