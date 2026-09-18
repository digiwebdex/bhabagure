import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../../components/ui/button'
import { ErrorNotice, useToast } from '../../../components/ui/feedback'
import { NumberInput, Pair, TextArea, TextInput } from '../../../components/ui/fields'
import { Card, CardTitle, Loading, PageHeader } from '../../../components/ui/layout'
import { api, ApiError } from '../../../lib/api/client'
import type { BankAccount, Data, SiteSettings } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'

type Key = keyof SiteSettings

/** Company details, contact channels, hours and social stats. Each card saves its own key. */
export function SettingsPage() {
  const { t } = useTranslation()
  const { number } = useFormat()
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
        <SettingCard settingKey="payment" title="Payment" initial={data.payment ?? { banks: [], link: null, bkash: null }}>
          {(value, set, error) => (
            <>
              {/* One block per bank account (up to three); customers see each with the exact amount. */}
              {value.banks.map((account, index) => {
                const patch = (fields: Partial<BankAccount>) => set({ ...value, banks: value.banks.map((b, i) => (i === index ? { ...b, ...fields } : b)) })
                const field = (name: keyof BankAccount) => error(`value.banks.${index}.${name}`)
                return (
                  <fieldset key={index} className="m-0 flex min-w-0 flex-col gap-3 rounded-12 border border-app-line p-3.5" data-testid="bank-account">
                    <legend className="px-1 text-14 font-semibold">{t('settings.bankAccount', { n: number(index + 1) })}</legend>
                    <Pair>
                      <TextInput label={t('settings.bankName')} value={account.bankName} onChange={(bankName) => patch({ bankName })} error={field('bankName')} hint={index === 0 ? t('settings.bankHint') : undefined} />
                      <TextInput label={t('settings.accountName')} value={account.accountName} onChange={(accountName) => patch({ accountName })} error={field('accountName')} />
                    </Pair>
                    <Pair>
                      <TextInput label={t('settings.accountNumber')} value={account.accountNumber} onChange={(accountNumber) => patch({ accountNumber })} error={field('accountNumber')} />
                      <TextInput label={t('settings.branch')} value={account.branch} onChange={(branch) => patch({ branch })} error={field('branch')} />
                    </Pair>
                    <Pair>
                      <TextInput label={t('settings.routingNumber')} value={account.routingNumber} onChange={(routingNumber) => patch({ routingNumber })} error={field('routingNumber')} hint={t('settings.routingHint')} />
                      <TextInput label={t('settings.transferType')} value={account.transferType} onChange={(transferType) => patch({ transferType })} error={field('transferType')} placeholder="NPSB" />
                    </Pair>
                    <button type="button" className={buttonClass('outline', 'sm', 'self-start')} onClick={() => set({ ...value, banks: value.banks.filter((_, i) => i !== index) })}>
                      {t('settings.removeBank')}
                    </button>
                  </fieldset>
                )
              })}
              {value.banks.length < MAX_BANKS ? (
                <button type="button" className={buttonClass('outline', 'sm', 'self-start border-dashed')} onClick={() => set({ ...value, banks: [...value.banks, emptyBank()] })}>
                  + {t('settings.addBank')}
                </button>
              ) : null}
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

/** The API keeps up to three accounts (PaymentOptions::MAX_BANKS). */
const MAX_BANKS = 3

const emptyBank = (): BankAccount => ({ bankName: '', accountName: '', accountNumber: '', branch: '', routingNumber: '', transferType: 'NPSB' })

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
