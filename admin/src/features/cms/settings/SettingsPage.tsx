import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../../components/ui/button'
import { ErrorNotice, useToast } from '../../../components/ui/feedback'
import { NumberInput, Pair, Switch, TextArea, TextInput } from '../../../components/ui/fields'
import { Card, CardTitle, Loading, PageHeader } from '../../../components/ui/layout'
import { api, ApiError } from '../../../lib/api/client'
import { WEBSITE_FEATURES, type BankAccount, type CodeDelivery, type Data, type SiteSettings, type WebsiteFeature } from '../../../lib/api/types'
import { BRAND_LOGOS_KEY, ORIGINAL_LIGHT_LOGO, useBrandLogos } from '../../../lib/brand'
import { useFormat } from '../../../lib/useFormat'
import { MediaPicker } from '../media/media'
import { HeroVideoCard } from './HeroVideoCard'

type Key = keyof SiteSettings

/** The home page video, company details, contact channels, hours and social stats. Each card saves its own key. */
export function SettingsPage() {
  const { t } = useTranslation()
  const { number } = useFormat()
  const settings = useQuery({ queryKey: ['settings'], queryFn: ({ signal }) => api.get<Data<SiteSettings> & { meta: { codes: CodeDelivery } }>('admin/settings', signal) })

  if (settings.isPending) return <Loading />
  if (settings.isError) return <ErrorNotice error={settings.error} />
  const data = settings.data.data

  return (
    <>
      <PageHeader title={t('settings.title')} subtitle={t('settings.subtitle')} />
      <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
        <HeroVideoCard />
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
              {/* The website footer's social icons (2026-10-02): an empty link shows no icon. */}
              <p className="m-0 text-12 text-app-muted">{t('settings.socialHint')}</p>
              <Pair>
                <TextInput label="Facebook" type="url" value={value.facebook} onChange={(facebook) => set({ ...value, facebook: facebook || null })} error={error('value.facebook')} placeholder="https://www.facebook.com/…" />
                <TextInput label="Instagram" type="url" value={value.instagram} onChange={(instagram) => set({ ...value, instagram: instagram || null })} error={error('value.instagram')} placeholder="https://www.instagram.com/…" />
              </Pair>
              <Pair>
                <TextInput label="TikTok" type="url" value={value.tiktok} onChange={(tiktok) => set({ ...value, tiktok: tiktok || null })} error={error('value.tiktok')} placeholder="https://www.tiktok.com/@…" />
                <TextInput label="LinkedIn" type="url" value={value.linkedin} onChange={(linkedin) => set({ ...value, linkedin: linkedin || null })} error={error('value.linkedin')} placeholder="https://www.linkedin.com/company/…" />
              </Pair>
              <TextInput label="YouTube" type="url" value={value.youtube} onChange={(youtube) => set({ ...value, youtube: youtube || null })} error={error('value.youtube')} placeholder="https://www.youtube.com/@…" />
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

        {/* A code to the customer's mobile before a website booking is saved (docs/booking-phone-verification.md). Left off
            until SMS reaches customers: switched on without it, nobody could book on the website. */}
        <SettingCard settingKey="booking" title="Website booking" initial={data.booking ?? { verifyPhone: false }}>
          {(value, set) => (
            <>
              <Switch label={t('settings.verifyPhone')} checked={value.verifyPhone} onChange={(verifyPhone) => set({ ...value, verifyPhone })} hint={t('settings.verifyPhoneHint')} />
              <CodeDeliveryNote codes={settings.data.meta.codes} />
            </>
          )}
        </SettingCard>
        {/* docs/brand-logo.md: the logos and browser icon everywhere — website, portal, admin and PDFs. */}
        <SettingCard settingKey="brand" title={t('settings.brand.title')} initial={{ logo: null, logoLight: null, icon: null, ...data.brand }}>
          {(value, set, error) => <BrandSlots value={value} set={set} error={error} />}
        </SettingCard>
        {/* docs/website-features.md: what the public website shows. Off hides a feature there and nothing else. */}
        <SettingCard settingKey="features" title={t('settings.features.title')} initial={{ ...allFeaturesOn(), ...data.features }}>
          {(value, set) => (
            <>
              <p className="m-0 text-12 text-app-muted">{t('settings.features.hint')}</p>
              <div className="flex flex-col gap-3" data-testid="website-features">
                {WEBSITE_FEATURES.map((feature) => (
                  <Switch key={feature} label={t(`settings.features.${feature}`)} checked={value[feature]} onChange={(on) => set({ ...value, [feature]: on })} />
                ))}
              </div>
            </>
          )}
        </SettingCard>
      </div>
    </>
  )
}

/** Whether one-time codes reach customers — what the booking check depends on. */
function CodeDeliveryNote({ codes }: { codes: CodeDelivery }) {
  const { t } = useTranslation()
  const { dateTime } = useFormat()
  const failing = codes.lastSentAt === null || (codes.lastFailedAt !== null && codes.lastFailedAt > codes.lastSentAt)

  return failing ? (
    <p role="note" className="m-0 rounded-10 bg-orange-tint px-3 py-2.5 text-13 leading-1.5 text-amber" data-testid="code-delivery">
      {codes.lastSentAt === null ? t('settings.codesNeverSent') : t('settings.codesLastFailed', { failed: dateTime(codes.lastFailedAt!), sent: dateTime(codes.lastSentAt) })}
    </p>
  ) : (
    <p className="m-0 text-12 text-app-muted" data-testid="code-delivery">
      {t('settings.codesOk', { sent: dateTime(codes.lastSentAt!) })}
    </p>
  )
}

/** The API keeps up to three accounts (PaymentOptions::MAX_BANKS). */
type BrandValue = NonNullable<SiteSettings['brand']>
type BrandSlot = keyof BrandValue

/** Each slot, the background it is shown on, and the file that ships with the site. */
const BRAND_SLOTS: { slot: BrandSlot; background: string; original: string; previewClass: string }[] = [
  { slot: 'logo', background: 'bg-white', original: '/brand/logo-wordmark.png', previewClass: 'h-12 w-auto max-w-full' },
  { slot: 'logoLight', background: 'bg-app-nav', original: ORIGINAL_LIGHT_LOGO, previewClass: 'h-12 w-auto max-w-full' },
  { slot: 'icon', background: 'bg-white', original: '/icon.png', previewClass: 'size-12' },
]

/** Brand card body: a preview of each logo on its own background, a media-library picker, and "Use the original". */
function BrandSlots({ value, set, error }: { value: BrandValue; set: (value: BrandValue) => void; error: (field: string) => string | undefined }) {
  const { t } = useTranslation()
  const current = useBrandLogos()
  // Images picked here before saving; afterwards the saved URLs come from the public settings.
  const [picked, setPicked] = useState<Partial<Record<BrandSlot, string>>>({})
  const [picking, setPicking] = useState<BrandSlot | null>(null)

  return (
    <>
      <p className="m-0 text-12 text-app-muted">{t('settings.brand.hint')}</p>
      <div className="flex flex-col gap-4" data-testid="brand-slots">
        {BRAND_SLOTS.map(({ slot, background, original, previewClass }) => {
          const src = value[slot] === null ? original : (picked[slot] ?? current?.[slot] ?? original)
          return (
            <div key={slot} className="flex flex-col gap-2" data-testid={`brand-${slot}`}>
              <span className="text-13 font-semibold">{t(`settings.brand.${slot}`)}</span>
              <span className="text-12 text-app-muted">{t(`settings.brand.${slot}Hint`)}</span>
              <div className={`flex min-h-16 items-center rounded-12 border border-app-line px-4 py-3 ${background}`}>
                <img src={src} alt={t(`settings.brand.${slot}`)} className={`block ${previewClass}`} />
              </div>
              <span className="text-12 text-app-muted">{value[slot] === null ? t('settings.brand.usingOriginal') : t('settings.brand.usingChosen')}</span>
              {error(`value.${slot}`) ? <span className="text-12 font-semibold text-red">{error(`value.${slot}`)}</span> : null}
              <div className="flex flex-wrap gap-2">
                <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setPicking(slot)}>
                  {t('settings.brand.choose')}
                </button>
                {value[slot] !== null ? (
                  <button type="button" className={buttonClass('outline', 'sm')} onClick={() => set({ ...value, [slot]: null })}>
                    {t('settings.brand.useOriginal')}
                  </button>
                ) : null}
              </div>
            </div>
          )
        })}
      </div>
      <MediaPicker
        open={picking !== null}
        onClose={() => setPicking(null)}
        title={picking ? t(`settings.brand.${picking}`) : undefined}
        onPick={(media) => {
          if (!picking) return
          setPicked((previous) => ({ ...previous, [picking]: media.variants.card?.url ?? media.url ?? undefined }))
          set({ ...value, [picking]: media.id })
        }}
      />
    </>
  )
}

const MAX_BANKS = 3

/** Every website feature on: what the website shows before the switches are first saved. */
const allFeaturesOn = () => Object.fromEntries(WEBSITE_FEATURES.map((feature) => [feature, true])) as Record<WebsiteFeature, boolean>

const emptyBank = (): BankAccount => ({ bankName: '', accountName: '', accountNumber: '', branch: '', routingNumber: '', transferType: 'NPSB' })

/**
 * A setting as text with its fields in name order: the API sends a saved value back in its own field order, and a field
 * added on this screen (a social link the saved value didn't have yet) comes last, so the same value must still match.
 */
function canonical(value: unknown): string {
  return JSON.stringify(value, (_field, part: unknown) =>
    part && typeof part === 'object' && !Array.isArray(part) ? Object.fromEntries(Object.entries(part).sort(([a], [b]) => a.localeCompare(b))) : part,
  )
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
  const dirty = canonical(value) !== canonical(initial)
  const save = useMutation({
    mutationFn: () => api.put(`admin/settings/${settingKey}`, { value }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['settings'] })
      // The sidebar, the sign-in page and the tab icon read the logos from the public settings.
      if (settingKey === 'brand') void queryClient.invalidateQueries({ queryKey: BRAND_LOGOS_KEY })
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
