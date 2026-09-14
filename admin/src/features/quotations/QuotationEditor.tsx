import { quoteBooking, type Quote, type RoomType } from '@bhabaghure/pricing'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { NumberInput, SelectInput, TextArea, TextInput } from '../../components/ui/fields'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import type { QuotationBody, QuotationInputs, QuotationOptions } from './api'

export type QuotationForm = Omit<QuotationInputs, 'package_slug' | 'travel_date' | 'notes'> & { package_slug: string; travel_date: string; notes: string }

const blank = (options: QuotationOptions, locale: 'bn' | 'en'): QuotationForm => ({
  package_slug: '',
  travel_date: '',
  pax: 2,
  room: 'twin',
  addons: [],
  discount: 0,
  vat_rate: options.config.serviceChargePercent,
  validity_days: options.validity_days.includes(7) ? 7 : options.validity_days[0],
  locale,
  notes: '',
})

/**
 * The quotation editor's state and its live price. Prices with @bhabaghure/pricing from the options the API sends —
 * the same package, add-on and pricing inputs the API prices the save with, so the total shown is the total saved
 * (the API refuses any other with price_changed).
 */
// eslint-disable-next-line react-refresh/only-export-components -- the editor's state belongs with its fields
export function useQuotationForm(options: QuotationOptions | undefined, initial: QuotationInputs | null, locale: 'bn' | 'en') {
  const [form, setForm] = useState<QuotationForm | null>(null)
  // Until the first change the form shows the saved inputs (or a blank quotation).
  const current = useMemo<QuotationForm | null>(
    () => form ?? (options ? (initial ? { ...initial, package_slug: initial.package_slug ?? '', travel_date: initial.travel_date ?? '', notes: initial.notes ?? '' } : blank(options, locale)) : null),
    [form, options, initial, locale],
  )

  const pkg = options?.packages.find((p) => p.slug === current?.package_slug)
  const quote = useMemo<Quote | null>(() => {
    if (!options || !current || !pkg) return null
    try {
      const addons = options.addons.filter((addon) => current.addons.includes(addon.code))
      return quoteBooking({ listPrice: pkg.list_price, pax: current.pax, room: current.room, addons, config: options.config, discount: current.discount, chargePercent: current.vat_rate })
    } catch {
      return null
    }
  }, [options, current, pkg])

  const set = (patch: Partial<QuotationForm>) => current && setForm({ ...current, ...patch })
  const body = (): QuotationBody | null =>
    current && quote
      ? { ...current, travel_date: current.travel_date || null, notes: current.notes.trim() || null, expected_total: quote.total }
      : null

  return { form: current, set, quote, pkg, body, reset: () => setForm(null) }
}

type Draft = ReturnType<typeof useQuotationForm>

export function QuotationFields({ draft, options, fieldError }: { draft: Draft; options: QuotationOptions; fieldError: (name: string) => string | undefined }) {
  const { t } = useTranslation()
  const { bdt, number, date, locale } = useFormat()
  const { form, set, pkg } = draft
  if (!form) return null

  return (
    <>
      <SelectInput
        label={t('bookings.package')}
        value={form.package_slug}
        onChange={(package_slug) => set({ package_slug, travel_date: '' })}
        options={[{ value: '', label: t('common.choose') }, ...options.packages.map((p) => ({ value: p.slug, label: (locale === 'bn' ? p.title_bn : null) || p.title_en }))]}
        error={fieldError('package_slug')}
      />
      {pkg && pkg.departures.length > 0 ? (
        <SelectInput
          label={t('newBooking.departure')}
          value={form.travel_date}
          onChange={(travel_date) => set({ travel_date })}
          options={[
            { value: '', label: t('quotations.dateLater') },
            ...pkg.departures.map((d) => ({ value: d.date, label: d.seats_left === null ? date(d.date) : t('newBooking.departureSeats', { date: date(d.date), seats: number(d.seats_left) }) })),
          ]}
          error={fieldError('travel_date')}
        />
      ) : (
        <TextInput label={t('newBooking.travelDate')} type="date" min={todayInDhaka()} value={form.travel_date} onChange={(travel_date) => set({ travel_date })} error={fieldError('travel_date')} hint={t('quotations.dateOptional')} />
      )}
      <div className="grid-auto-fit-half-140 grid gap-3">
        <SelectInput
          label={t('quotations.travellers')}
          value={String(form.pax)}
          onChange={(value) => set({ pax: Number(value) })}
          options={Array.from({ length: options.config.maxTravellers }, (_, i) => ({ value: String(i + 1), label: number(i + 1) }))}
          error={fieldError('pax')}
        />
        <SelectInput
          label={t('quotations.validity')}
          value={String(form.validity_days)}
          onChange={(value) => set({ validity_days: Number(value) })}
          options={options.validity_days.map((days) => ({ value: String(days), label: t('quotations.days', { count: days, n: number(days) }) }))}
          error={fieldError('validity_days')}
        />
        <SelectInput label={t('bookings.room')} value={form.room} onChange={(value) => set({ room: value as RoomType })} options={(['twin', 'triple', 'single'] as const).map((value) => ({ value, label: t(`bookings.rooms.${value}`) }))} />
        <SelectInput label={t('quotations.vatRate')} value={String(form.vat_rate)} onChange={(value) => set({ vat_rate: Number(value) })} options={options.vat_rates.map((rate) => ({ value: String(rate), label: `${number(rate)}%` }))} error={fieldError('vat_rate')} />
      </div>
      <NumberInput label={t('bookings.discount')} value={form.discount} onChange={(discount) => set({ discount: discount ?? 0 })} preview={(value) => bdt(value)} error={fieldError('discount')} />
      {options.addons.length > 0 ? (
        <fieldset className="m-0 flex flex-col gap-1.5 border-0 p-0">
          <legend className="mb-1 text-13 text-app-muted">{t('newBooking.addons')}</legend>
          {options.addons.map((addon) => (
            <label key={addon.code} className="flex items-center justify-between gap-3 text-14">
              <span className="flex items-center gap-2">
                <input type="checkbox" checked={form.addons.includes(addon.code)} onChange={(event) => set({ addons: event.target.checked ? [...form.addons, addon.code] : form.addons.filter((code) => code !== addon.code) })} />
                {(locale === 'bn' ? addon.name_bn : null) || addon.name_en}
              </span>
              <span className="font-display text-13 text-app-muted">{bdt(addon.price)}</span>
            </label>
          ))}
        </fieldset>
      ) : null}
      <SelectInput label={t('quotations.language')} value={form.locale} onChange={(value) => set({ locale: value as 'bn' | 'en' })} options={[{ value: 'bn', label: 'বাংলা' }, { value: 'en', label: 'English' }]} />
      <TextArea label={t('quotations.notes')} value={form.notes} onChange={(notes) => set({ notes })} rows={2} hint={t('quotations.notesHint')} />
    </>
  )
}

/** The live price, as the prototype's grey totals box: lines, then the total in orange. */
export function QuotationTotals({ quote, pax }: { quote: Quote; pax: number }) {
  const { t } = useTranslation()
  const { bdt, number } = useFormat()
  const addons = quote.addons.reduce((sum, addon) => sum + addon.amount, 0)
  const rows: [string, string][] = [
    [t('quotations.perPersonTimes', { price: bdt(quote.perPerson), n: number(pax) }), bdt(quote.subtotal)],
    ...(quote.singleSupplement > 0 ? [[t('bookings.line.single'), bdt(quote.singleSupplement)] as [string, string]] : []),
    ...(addons > 0 ? [[t('newBooking.addons'), bdt(addons)] as [string, string]] : []),
    ...(quote.discount > 0 ? [[t('quotations.discount'), `− ${bdt(quote.discount)}`] as [string, string]] : []),
    [t('bookings.vatLine', { rate: `${number(quote.chargePercent)}%` }), bdt(quote.serviceCharge)],
  ]

  return (
    <div className="flex flex-col gap-1.5 rounded-12 bg-app-surface-2 p-3.5" data-testid="quotation-quote">
      {rows.map(([label, amount]) => (
        <div key={label} className="flex justify-between gap-2.5 text-13">
          <span className="min-w-0 text-app-muted">{label}</span>
          <span className="font-display font-semibold whitespace-nowrap">{amount}</span>
        </div>
      ))}
      <div className="my-0.75 h-px bg-app-line" />
      <div className="flex items-baseline justify-between gap-2.5">
        <span className="text-14 font-semibold">{t('bookings.total')}</span>
        <span className="font-display text-22 font-extrabold text-orange-deep" data-testid="quotation-total">
          {bdt(quote.total)}
        </span>
      </div>
    </div>
  )
}
