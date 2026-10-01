import { defaultHotelCategory, gridCategories, invoiceTotals, quoteBooking, type HotelCategory, type PriceGrid, type Quote, type RoomType } from '@bhabaghure/pricing'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { NumberInput, SelectInput, TextArea, TextInput } from '../../components/ui/fields'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import { priceOn } from '../bookings/api'
import type { QuotationBody, QuotationInputs, QuotationOptions } from './api'

/** The package list's "Custom package" choice (2026-10-02): no package slug is ever this. */
const CUSTOM = '__custom__'

/** One line of a custom quotation: its name and its price per person. */
type CustomItem = { title: string; price: number | null }

const emptyItem = (): CustomItem => ({ title: '', price: null })

export type QuotationForm = Omit<QuotationInputs, 'package_slug' | 'travel_date' | 'notes' | 'internal_note' | 'custom'> & {
  package_slug: string
  travel_date: string
  notes: string
  internal_note: string
  custom_title: string
  custom_details: string
  custom_items: CustomItem[]
}

/** What the totals box shows: a package's quote, or a custom quotation's own lines priced the same way. */
export type QuotePreview = Pick<Quote, 'perPerson' | 'subtotal' | 'singleSupplement' | 'addons' | 'discount' | 'chargePercent' | 'serviceCharge' | 'total' | 'hotelCategory'>

/** The category a grid package is quoted in: the one picked if the package offers it, else its default; null without a grid. */
const gridCategory = (grid: PriceGrid | null | undefined, picked: HotelCategory | null): HotelCategory | null => {
  const offered = gridCategories(grid)
  return offered.length === 0 ? null : picked && offered.includes(picked) ? picked : defaultHotelCategory(grid)
}

const blank = (options: QuotationOptions, locale: 'bn' | 'en'): QuotationForm => ({
  package_slug: '',
  travel_date: '',
  pax: 2,
  room: 'twin',
  hotel_category: null,
  addons: [],
  discount: 0,
  vat_rate: options.config.serviceChargePercent,
  validity_days: options.validity_days.includes(7) ? 7 : options.validity_days[0],
  locale,
  notes: '',
  internal_note: '',
  custom_title: '',
  custom_details: '',
  custom_items: [emptyItem()],
})

const fromInputs = (initial: QuotationInputs): QuotationForm => ({
  package_slug: initial.custom ? CUSTOM : (initial.package_slug ?? ''),
  travel_date: initial.travel_date ?? '',
  pax: initial.pax,
  room: initial.room,
  hotel_category: initial.hotel_category,
  addons: initial.addons,
  discount: initial.discount,
  vat_rate: initial.vat_rate,
  validity_days: initial.validity_days,
  locale: initial.locale,
  notes: initial.notes ?? '',
  internal_note: initial.internal_note ?? '',
  custom_title: initial.custom?.title ?? '',
  custom_details: initial.custom?.details ?? '',
  custom_items: initial.custom ? initial.custom.items.map((item) => ({ title: item.title, price: item.unit_price })) : [emptyItem()],
})

/**
 * The quotation editor's state and its live price. Prices with @bhabaghure/pricing from the options the API sends —
 * the same package, add-on and pricing inputs the API prices the save with, so the total shown is the total saved
 * (the API refuses any other with price_changed). A custom quotation's lines are priced as the API's customQuote: each
 * for every traveller, then the discount, service charge and VAT.
 */
// eslint-disable-next-line react-refresh/only-export-components -- the editor's state belongs with its fields
export function useQuotationForm(options: QuotationOptions | undefined, initial: QuotationInputs | null, locale: 'bn' | 'en') {
  const [form, setForm] = useState<QuotationForm | null>(null)
  // Until the first change the form shows the saved inputs (or a blank quotation).
  const current = useMemo<QuotationForm | null>(() => form ?? (options ? (initial ? fromInputs(initial) : blank(options, locale)) : null), [form, options, initial, locale])

  const custom = current?.package_slug === CUSTOM
  const pkg = custom ? undefined : options?.packages.find((p) => p.slug === current?.package_slug)
  const quote = useMemo<QuotePreview | null>(() => {
    if (!options || !current) return null
    try {
      if (custom) {
        const ready = current.custom_title.trim() !== '' && current.custom_items.length > 0 && current.custom_items.every((item) => item.title.trim() !== '' && item.price !== null && item.price >= 0)
        if (!ready) return null
        const prices = current.custom_items.map((item) => Math.round(item.price ?? 0))
        const totals = invoiceTotals({ lines: prices.map((unitPrice) => ({ quantity: current.pax, unitPrice })), discount: current.discount, chargePercent: current.vat_rate })
        return { perPerson: prices.reduce((sum, price) => sum + price, 0), subtotal: totals.subtotal, singleSupplement: 0, addons: [], discount: totals.discount, chargePercent: totals.chargePercent, serviceCharge: totals.charge, total: totals.total, hotelCategory: null }
      }
      if (!pkg) return null
      const addons = options.addons.filter((addon) => current.addons.includes(addon.code))
      return quoteBooking({ listPrice: priceOn(pkg, current.travel_date || null), pax: current.pax, room: current.room, addons, config: options.config, discount: current.discount, chargePercent: current.vat_rate, grid: pkg.price_grid, hotelCategory: pkg.fixed_price ? null : gridCategory(pkg.price_grid, current.hotel_category), rooms: pkg.room_rates, fixedPrice: pkg.fixed_price })
    } catch {
      return null
    }
  }, [options, current, pkg, custom])

  const set = (patch: Partial<QuotationForm>) => current && setForm({ ...current, ...patch })
  const body = (): QuotationBody | null =>
    current && quote
      ? {
          package_slug: custom ? null : current.package_slug,
          custom: custom
            ? {
                title: current.custom_title.trim(),
                details: current.custom_details.trim() || null,
                items: current.custom_items.map((item) => ({ title: item.title.trim(), unit_price: Math.round(item.price ?? 0) })),
              }
            : null,
          travel_date: current.travel_date || null,
          pax: current.pax,
          room: current.room,
          hotel_category: custom ? null : quote.hotelCategory,
          addons: custom ? [] : current.addons,
          discount: current.discount,
          vat_rate: current.vat_rate,
          validity_days: current.validity_days,
          locale: current.locale,
          notes: current.notes.trim() || null,
          internal_note: current.internal_note.trim() || null,
          expected_total: quote.total,
        }
      : null

  return { form: current, set, quote, pkg, custom, body, reset: () => setForm(null) }
}

type Draft = ReturnType<typeof useQuotationForm>

export function QuotationFields({ draft, options, fieldError }: { draft: Draft; options: QuotationOptions; fieldError: (name: string) => string | undefined }) {
  const { t } = useTranslation()
  const { bdt, number, date } = useFormat()
  const { form, set, pkg, custom } = draft
  if (!form) return null
  const setItem = (index: number, patch: Partial<CustomItem>) => set({ custom_items: form.custom_items.map((item, i) => (i === index ? { ...item, ...patch } : item)) })

  return (
    <>
      <SelectInput
        label={t('bookings.package')}
        value={form.package_slug}
        onChange={(package_slug) => set({ package_slug, travel_date: '', hotel_category: null })}
        options={[
          { value: '', label: t('common.choose') },
          { value: CUSTOM, label: t('quotations.customOption') },
          ...options.packages.map((p) => ({ value: p.slug, label: p.title_en || p.title_bn || p.slug })),
        ]}
        error={fieldError('package_slug')}
      />
      {custom ? (
        // A trip that isn't one of the packages (2026-10-02): its title and details as the customer will read them, then
        // each line at a price per person.
        <div className="flex flex-col gap-3" data-testid="custom-package">
          <TextInput label={t('quotations.customTitle')} value={form.custom_title} onChange={(custom_title) => set({ custom_title })} error={fieldError('custom.title')} placeholder={t('quotations.customTitlePlaceholder')} maxLength={160} />
          <TextArea label={t('quotations.customDetails')} value={form.custom_details} onChange={(custom_details) => set({ custom_details })} rows={4} error={fieldError('custom.details')} hint={t('quotations.customDetailsHint')} maxLength={5000} />
          {form.custom_items.map((item, index) => (
            <div key={index} className="flex flex-wrap items-end gap-2 rounded-10 bg-app-surface-2 p-2.5" data-testid="custom-item">
              <div className="min-w-0 flex-[2_1_200px]">
                <TextInput label={t('newBooking.itemName', { n: number(index + 1) })} value={item.title} onChange={(title) => setItem(index, { title })} error={fieldError(`custom.items.${index}.title`)} />
              </div>
              <div className="min-w-0 flex-[1_1_140px]">
                <NumberInput label={t('newBooking.itemPrice')} value={item.price} onChange={(price) => setItem(index, { price })} error={fieldError(`custom.items.${index}.unit_price`)} />
              </div>
              {form.custom_items.length > 1 ? (
                <button
                  type="button"
                  aria-label={t('newBooking.removeItem', { n: number(index + 1) })}
                  className={buttonClass('outline', 'sm', 'mb-0.5')}
                  onClick={() => set({ custom_items: form.custom_items.filter((_, i) => i !== index) })}
                >
                  ×
                </button>
              ) : null}
            </div>
          ))}
          {fieldError('custom.items') ? <p className="m-0 text-13 text-red">{fieldError('custom.items')}</p> : null}
          <button type="button" className={buttonClass('outline', 'sm', 'self-start border-dashed')} onClick={() => set({ custom_items: [...form.custom_items, emptyItem()] })}>
            + {t('newBooking.addItem')}
          </button>
        </div>
      ) : null}
      {/* A group tour is quoted for one of its departures or with the date left for later — never another date. */}
      {pkg && (pkg.departures.length > 0 || pkg.fixed_price) ? (
        <SelectInput
          label={t('newBooking.departure')}
          value={form.travel_date}
          onChange={(travel_date) => set({ travel_date })}
          options={[
            { value: '', label: t('quotations.dateLater') },
            // Each date with a group tour's price on it (docs/departure-prices.md) and its seats.
            ...pkg.departures.map((d) => ({
              value: d.date,
              label: [date(d.date), pkg.fixed_price ? bdt(priceOn(pkg, d.date)) : null, d.seats_left === null ? null : t('newBooking.seatsLeft', { seats: number(d.seats_left) })].filter(Boolean).join(' · '),
            })),
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
        {pkg && !pkg.fixed_price && gridCategory(pkg.price_grid, form.hotel_category) ? (
          <SelectInput
            label={t('grid.hotelCategory')}
            value={gridCategory(pkg.price_grid, form.hotel_category) ?? ''}
            onChange={(value) => set({ hotel_category: value as HotelCategory })}
            options={gridCategories(pkg.price_grid).map((value) => ({ value, label: t(`grid.categories.${value}`) }))}
            error={fieldError('hotel_category')}
          />
        ) : null}
        {/* A custom quotation has no room choice: a room is one of its lines. */}
        {custom ? null : (
          <SelectInput
            label={t('bookings.room')}
            value={form.room}
            onChange={(value) => set({ room: value as RoomType })}
            // Each room with its price per person for these travellers (docs/room-rates.md).
            options={(['twin', 'triple', 'single'] as const).map((value) => {
              const price = (() => {
                if (!pkg) return null
                try {
                  const priced = quoteBooking({ listPrice: priceOn(pkg, form.travel_date || null), pax: form.pax, room: value, addons: [], config: options.config, grid: pkg.price_grid, hotelCategory: pkg.fixed_price ? null : gridCategory(pkg.price_grid, form.hotel_category), rooms: pkg.room_rates, fixedPrice: pkg.fixed_price })
                  return Math.round((priced.subtotal + priced.singleSupplement) / form.pax)
                } catch {
                  return null
                }
              })()
              return { value, label: price === null ? t(`bookings.rooms.${value}`) : `${t(`bookings.rooms.${value}`)} · ${bdt(price)}` }
            })}
          />
        )}
        <SelectInput label={t('quotations.vatRate')} value={String(form.vat_rate)} onChange={(value) => set({ vat_rate: Number(value) })} options={options.vat_rates.map((rate) => ({ value: String(rate), label: `${number(rate)}%` }))} error={fieldError('vat_rate')} />
      </div>
      <NumberInput label={t('bookings.discount')} value={form.discount} onChange={(discount) => set({ discount: discount ?? 0 })} preview={(value) => bdt(value)} error={fieldError('discount')} />
      {!custom && options.addons.length > 0 ? (
        <fieldset className="m-0 flex flex-col gap-1.5 border-0 p-0">
          <legend className="mb-1 text-13 text-app-muted">{t('newBooking.addons')}</legend>
          {options.addons.map((addon) => (
            <label key={addon.code} className="flex items-center justify-between gap-3 text-14">
              <span className="flex items-center gap-2">
                <input type="checkbox" checked={form.addons.includes(addon.code)} onChange={(event) => set({ addons: event.target.checked ? [...form.addons, addon.code] : form.addons.filter((code) => code !== addon.code) })} />
                {addon.name_en || addon.name_bn}
              </span>
              <span className="font-display text-13 text-app-muted">{bdt(addon.price)}</span>
            </label>
          ))}
        </fieldset>
      ) : null}
      <SelectInput label={t('quotations.language')} value={form.locale} onChange={(value) => set({ locale: value as 'bn' | 'en' })} options={[{ value: 'bn', label: 'বাংলা' }, { value: 'en', label: 'English' }]} />
      {/* Two notes (2026-10-02): the customer's, printed on the quotation; and the office's, which never leaves the admin. */}
      <TextArea label={t('quotations.customerNote')} value={form.notes} onChange={(notes) => set({ notes })} rows={2} error={fieldError('notes')} hint={t('quotations.customerNoteHint')} />
      <div className="flex flex-col gap-1 rounded-10 border border-dashed border-amber/60 bg-orange-tint/40 p-2.5" data-testid="internal-note">
        <TextArea label={t('quotations.internalNote')} value={form.internal_note} onChange={(internal_note) => set({ internal_note })} rows={2} error={fieldError('internal_note')} hint={t('quotations.internalNoteHint')} />
      </div>
    </>
  )
}

/** The live price, as the prototype's grey totals box: lines, then the total in orange. */
export function QuotationTotals({ quote, pax }: { quote: QuotePreview; pax: number }) {
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
