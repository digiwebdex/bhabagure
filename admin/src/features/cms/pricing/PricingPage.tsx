import { quoteBooking, type PricingConfig } from '@bhabaghure/pricing'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../../components/ui/feedback'
import { NumberInput, Pair, SelectInput, Switch, TextInput } from '../../../components/ui/fields'
import { Badge, Card, CardTitle, Loading, PageHeader } from '../../../components/ui/layout'
import { api, ApiError } from '../../../lib/api/client'
import type { Addon, Data, Pricing } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'
import { slugify } from '../packages/api'

export function PricingPage() {
  const { t } = useTranslation()
  const pricing = useQuery({ queryKey: ['pricing'], queryFn: ({ signal }) => api.get<Data<Pricing>>('admin/pricing', signal) })

  return (
    <>
      <PageHeader title={t('pricing.title')} subtitle={t('pricing.subtitle')} />
      {pricing.isPending ? <Loading /> : pricing.isError ? <ErrorNotice error={pricing.error} /> : <PricingEditor key={JSON.stringify(pricing.data.data)} initial={pricing.data.data} />}
      <AddonsCard />
    </>
  )
}

function PricingEditor({ initial }: { initial: Pricing }) {
  const { t } = useTranslation()
  const { bdt, number, percent } = useFormat()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [form, setForm] = useState<Pricing>(initial)
  const [examplePrice, setExamplePrice] = useState<number | null>(75000)
  const dirty = JSON.stringify(form) !== JSON.stringify(initial)

  const save = useMutation({
    mutationFn: () => api.put<Data<Pricing>>('admin/pricing', { ...form, slabs: [...form.slabs].sort((a, b) => a.min_pax - b.min_pax) }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['pricing'] })
      toast(t('common.saved'))
    },
  })
  const error = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)
  const setSlab = (index: number, patch: Partial<Pricing['slabs'][number]>) => setForm({ ...form, slabs: form.slabs.map((slab, i) => (i === index ? { ...slab, ...patch } : slab)) })

  // The website's own calculation, so the example is exactly what a traveller will be charged.
  const config: PricingConfig = {
    slabs: [...form.slabs].sort((a, b) => a.min_pax - b.min_pax).map((slab) => ({ minPax: slab.min_pax, discountPercent: slab.discount_percent })),
    singleRoomSupplementPercent: form.single_room_supplement_percent,
    serviceChargePercent: form.service_charge_percent,
    maxTravellers: form.max_travellers,
    onlinePaymentChargePercent: form.online_payment_charge_percent,
  }
  const exampleRows = (() => {
    if (!examplePrice || config.slabs[0]?.minPax !== 1) return []
    try {
      return [1, 2, 3, 4, 6, 10].filter((pax) => pax <= form.max_travellers).map((pax) => ({
        pax,
        twin: quoteBooking({ listPrice: examplePrice, pax, room: 'twin', addons: [], config }),
        single: quoteBooking({ listPrice: examplePrice, pax, room: 'single', addons: [], config }),
      }))
    } catch {
      return []
    }
  })()

  return (
    <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
      <div className="flex min-w-0 flex-col gap-4.5">
        <Card>
          <CardTitle bn="গ্রুপ স্ল্যাব" en="Group slab · per-person discount" />
          <p className="m-0 text-13 leading-1.6 text-app-muted">{t('pricing.slabNote')}</p>
          {error('slabs') ? <span role="alert" className="text-12 font-semibold text-red">{error('slabs')}</span> : null}
          <ul className="m-0 flex list-none flex-col gap-2 p-0">
            {form.slabs.map((slab, index) => (
              <li key={index} className="flex flex-wrap items-end gap-2 rounded-10 bg-app-surface-2 p-2.5">
                <div className="grid-auto-fit-160 grid min-w-0 flex-1 gap-2">
                  <NumberInput label={t('pricing.fromTravellers')} value={slab.min_pax} onChange={(value) => setSlab(index, { min_pax: value ?? 0 })} error={error(`slabs.${index}.min_pax`)} />
                  <NumberInput label={t('pricing.discountPercent')} value={slab.discount_percent} onChange={(value) => setSlab(index, { discount_percent: value ?? 0 })} error={error(`slabs.${index}.discount_percent`)} preview={(value) => `−${percent(value)}`} />
                </div>
                <button type="button" disabled={form.slabs.length === 1} aria-label={t('common.remove')} className="mb-1 size-6.5 cursor-pointer rounded-7 border border-app-line bg-transparent text-12 text-amber hover:border-red disabled:opacity-35" onClick={() => setForm({ ...form, slabs: form.slabs.filter((_, i) => i !== index) })}>
                  ×
                </button>
              </li>
            ))}
          </ul>
          <button type="button" className={buttonClass('outline', 'sm', 'self-start border-dashed')} onClick={() => setForm({ ...form, slabs: [...form.slabs, { min_pax: (form.slabs.at(-1)?.min_pax ?? 0) + 1, discount_percent: form.slabs.at(-1)?.discount_percent ?? 0 }] })}>
            + {t('pricing.addTier')}
          </button>
        </Card>

        <Card>
          <CardTitle bn="সিঙ্গেল রুম সাপ্লিমেন্ট" en="Single-room supplement" />
          <p className="m-0 text-13 leading-1.6 text-app-muted">{t('pricing.supplementNote')}</p>
          <NumberInput label={t('pricing.supplementPercent')} value={form.single_room_supplement_percent} onChange={(value) => setForm({ ...form, single_room_supplement_percent: value ?? 0 })} error={error('single_room_supplement_percent')} preview={(value) => `+${percent(value)}`} />
        </Card>

        <Card>
          <CardTitle bn="চার্জ ও সীমা" en="Charges and limits" />
          <Pair>
            <NumberInput label={t('pricing.serviceCharge')} value={form.service_charge_percent} onChange={(value) => setForm({ ...form, service_charge_percent: value ?? 0 })} error={error('service_charge_percent')} preview={percent} />
            <NumberInput label={t('pricing.maxTravellers')} value={form.max_travellers} onChange={(value) => setForm({ ...form, max_travellers: value ?? 0 })} error={error('max_travellers')} />
          </Pair>
        </Card>

        <Card>
          <CardTitle bn="অনলাইন পেমেন্ট চার্জ" en="Online payment charge" />
          <p className="m-0 text-13 leading-1.6 text-app-muted">{t('pricing.onlineChargeNote')}</p>
          <NumberInput
            label={t('pricing.onlineCharge')}
            value={form.online_payment_charge_percent}
            onChange={(value) => setForm({ ...form, online_payment_charge_percent: value ?? 0 })}
            error={error('online_payment_charge_percent')}
            preview={(value) => (value > 0 ? `+${percent(value)}` : percent(0))}
          />
        </Card>

        <ErrorNotice error={save.error instanceof ApiError && save.error.status === 422 ? null : save.error} />
        <div className="flex gap-2">
          <button type="button" className={buttonClass('cta')} onClick={() => dirty && !save.isPending && save.mutate()} aria-disabled={save.isPending || !dirty}>
            {save.isPending ? t('common.saving') : dirty ? t('common.save') : t('common.saved')}
          </button>
          {dirty ? <button type="button" className={buttonClass('outline')} onClick={() => setForm(initial)}>{t('common.discard')}</button> : null}
        </div>
      </div>

      <Card className="lg:sticky lg:top-5">
        <CardTitle bn="উদাহরণ" en="Worked example" />
        <NumberInput label={t('pricing.examplePrice')} value={examplePrice} onChange={setExamplePrice} preview={(value) => bdt(value)} />
        <div className="overflow-x-auto">
          <table className="w-full border-collapse text-13">
            <thead>
              <tr className="font-display text-11 tracking-eyebrow text-app-muted uppercase">
                <th className="px-2 py-2 text-left font-normal">{t('pricing.travellers')}</th>
                <th className="px-2 py-2 text-right font-normal">{t('pricing.perPerson')}</th>
                <th className="px-2 py-2 text-right font-normal">{t('pricing.totalShared')}</th>
                <th className="px-2 py-2 text-right font-normal">{t('pricing.totalSingle')}</th>
              </tr>
            </thead>
            <tbody>
              {exampleRows.map(({ pax, twin, single }) => (
                <tr key={pax} className="border-t border-app-line">
                  <td className="px-2 py-2 whitespace-nowrap">
                    {number(pax)} {twin.slab.discountPercent > 0 ? <Badge tone="green">−{percent(twin.slab.discountPercent)}</Badge> : null}
                  </td>
                  <td className="px-2 py-2 text-right font-display font-semibold whitespace-nowrap">{bdt(twin.perPerson)}</td>
                  <td className="px-2 py-2 text-right font-display whitespace-nowrap">{bdt(twin.total)}</td>
                  <td className="px-2 py-2 text-right font-display whitespace-nowrap text-app-muted">{bdt(single.total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="m-0 text-12 leading-1.6 text-app-muted">{t('pricing.exampleNote', { service: percent(form.service_charge_percent), supplement: percent(form.single_room_supplement_percent) })}</p>
      </Card>
    </div>
  )
}

function AddonsCard() {
  const { t } = useTranslation()
  const { bdt, locale } = useFormat()
  const addons = useQuery({ queryKey: ['addons'], queryFn: ({ signal }) => api.get<Data<Addon[]>>('admin/addons', signal) })
  const [editing, setEditing] = useState<Addon | 'new' | null>(null)

  return (
    <Card>
      <CardTitle bn="অ্যাড-অন" en="Add-ons · offered at booking" aside={<button type="button" className={buttonClass('outline', 'sm')} onClick={() => setEditing('new')}>{t('common.add')}</button>} />
      <p className="m-0 text-13 text-app-muted">{t('pricing.addonsNote')}</p>
      {addons.isPending ? <Loading /> : addons.isError ? <ErrorNotice error={addons.error} /> : (
        <ul className="m-0 flex list-none flex-col gap-1.5 p-0">
          {addons.data.data.map((addon) => (
            <li key={addon.id}>
              <button type="button" onClick={() => setEditing(addon)} className="flex w-full cursor-pointer flex-wrap items-center justify-between gap-3 rounded-10 border-0 bg-app-surface-2 px-3 py-2.5 text-left text-14 text-app-text">
                <span className="flex min-w-0 flex-col">
                  <span className="font-medium">{locale === 'bn' ? addon.name_bn : addon.name_en}</span>
                  <span className="text-12 text-app-muted">{t(`pricing.units.${addon.unit}`)}</span>
                </span>
                <span className="flex items-center gap-2">
                  <span className="font-display font-semibold">{bdt(addon.price)}</span>
                  <Badge tone={addon.is_active ? 'green' : 'slate'}>{addon.is_active ? t('pricing.offered') : t('pricing.retired')}</Badge>
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
      {editing ? <AddonDialog addon={editing === 'new' ? null : editing} onClose={() => setEditing(null)} /> : null}
    </Card>
  )
}

function AddonDialog({ addon, onClose }: { addon: Addon | null; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [form, setForm] = useState({ code: addon?.code ?? '', name_bn: addon?.name_bn ?? '', name_en: addon?.name_en ?? '', price: addon?.price ?? 0, unit: addon?.unit ?? 'per_person', is_active: addon?.is_active ?? true })
  const save = useMutation({
    mutationFn: () => (addon ? api.put(`admin/addons/${addon.id}`, form) : api.post('admin/addons', form)),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['addons'] })
      toast(t('common.saved'))
      onClose()
    },
  })
  const error = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)

  return (
    <Dialog open onClose={onClose} title={addon ? t('pricing.editAddon') : t('pricing.newAddon')}>
      <Pair>
        <TextInput label={t('fields.nameBn')} value={form.name_bn} onChange={(name_bn) => setForm({ ...form, name_bn })} error={error('name_bn')} />
        <TextInput label={t('fields.nameEn')} value={form.name_en} onChange={(name_en) => setForm((current) => ({ ...current, name_en, code: addon ? current.code : slugify(name_en) }))} error={error('name_en')} />
      </Pair>
      <Pair>
        <NumberInput label={t('pricing.price')} value={form.price} onChange={(price) => setForm({ ...form, price: price ?? 0 })} error={error('price')} preview={bdt} />
        <SelectInput label={t('pricing.unit')} value={form.unit} onChange={(unit) => setForm({ ...form, unit: unit as Addon['unit'] })} options={(['per_person', 'per_booking'] as const).map((value) => ({ value, label: t(`pricing.units.${value}`) }))} />
      </Pair>
      <TextInput label={t('pricing.code')} value={form.code} onChange={(code) => setForm({ ...form, code })} error={error('code')} disabled={!!addon} hint={t('pricing.codeHint')} />
      <Switch label={t('pricing.offered')} hint={t('pricing.offeredHint')} checked={form.is_active} onChange={(is_active) => setForm({ ...form, is_active })} />
      <ErrorNotice error={save.error instanceof ApiError && save.error.status === 422 ? null : save.error} />
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>{t('common.cancel')}</button>
        <button type="button" className={buttonClass('primary')} onClick={() => save.mutate()} aria-disabled={save.isPending}>{save.isPending ? t('common.saving') : t('common.save')}</button>
      </div>
    </Dialog>
  )
}
